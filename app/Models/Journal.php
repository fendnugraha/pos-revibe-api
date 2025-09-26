<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee_amount' => 'float',
        'debt_code' => 'integer',
        'cred_code' => 'integer',
    ];

    public function scopeFilterJournals($query, array $filters)
    {
        $query->when(!empty($filters['search']), function ($query) use ($filters) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('invoice', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('cred_code', 'like', '%' . $search . '%')
                    ->orWhere('debt_code', 'like', '%' . $search . '%')
                    ->orWhere('date_issued', 'like', '%' . $search . '%')
                    ->orWhere('trx_type', 'like', '%' . $search . '%')
                    ->orWhere('status', 'like', '%' . $search . '%')
                    ->orWhere('amount', 'like', '%' . $search . '%')
                    ->orWhere('fee_amount', 'like', '%' . $search . '%')
                    ->orWhereHas('debt', function ($query) use ($search) {
                        $query->where('acc_name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('cred', function ($query) use ($search) {
                        $query->where('acc_name', 'like', '%' . $search . '%');
                    });
            });
        });
    }

    public function scopeFilterAccounts($query, array $filters)
    {
        $query->when(!empty($filters['account']), function ($query) use ($filters) {
            $account = $filters['account'];
            $query->where('cred_code', $account)->orWhere('debt_code', $account);
        });
    }

    public function scopeFilterMutation($query, array $filters)
    {
        $query->when($filters['searchHistory'] ?? false, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('debt', function ($q) use ($search) {
                    $q->where('acc_name', 'like', '%' . $search . '%');
                })
                    ->orWhereHas('cred', function ($q) use ($search) {
                        $q->where('acc_name', 'like', '%' . $search . '%');
                    });
            });
        });
    }

    public function debt()
    {
        return $this->belongsTo(ChartOfAccount::class, 'debt_code', 'id');
    }

    public function cred()
    {
        return $this->belongsTo(ChartOfAccount::class, 'cred_code', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class, 'invoice', 'invoice');
    }

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function serviceFee()
    {
        return $this->hasOne(JournalEntry::class)->where('chart_of_account_id', ChartOfAccount::SERVICE_FEE);
    }

    public function sales_discount()
    {
        return $this->hasOne(JournalEntry::class)->where('chart_of_account_id', ChartOfAccount::SALES_DISCOUNT);
    }

    public static function generateJournalInvoice($prefix, $table, $condition = [], $whereInCondition = null)
    {
        $userId = auth()->id();
        $today = now()->toDateString();

        return DB::transaction(function () use ($prefix, $table, $condition, $whereInCondition, $userId, $today) {
            $query = DB::table($table)
                ->lockForUpdate()
                ->where($condition)
                ->where('user_id', $userId)
                ->whereDate('created_at', $today);

            // kalau ada whereInCondition, tambahkan ke query
            if ($whereInCondition && is_array($whereInCondition) && count($whereInCondition) === 2) {
                [$column, $values] = $whereInCondition;
                $query->whereIn($column, $values);
            }

            $lastInvoice = $query->max(DB::raw('CAST(SUBSTRING_INDEX(invoice, ".", -1) AS UNSIGNED)'));

            $nextNumber = $lastInvoice ? $lastInvoice + 1 : 1;

            $newInvoice = implode('.', [
                $prefix, // contoh: JRN
                now()->format('dmY'), // tanggal: 22072025
                $userId,
                str_pad($nextNumber, 7, '0', STR_PAD_LEFT) // nomor: 0000001
            ]);

            return $newInvoice;
        });
    }


    public static function sales_journal()
    {
        return self::generateJournalInvoice('SO.BK', 'transactions', [['transaction_type', '=', 'Sales']]);
    }

    public static function general_journal()
    {
        return self::generateJournalInvoice('GR.BK', 'journals', [], ['journal_type', ['Sales', 'Order']]);
    }

    public static function order_journal()
    {
        $prefix = 'RO.BK.' . now()->format('dmY') . '.' . auth()->id() . '.';

        do {
            $lastNumber = Journal::where('invoice', 'like', $prefix . '%')
                ->orderBy('id', 'desc')
                ->value('invoice');

            if ($lastNumber && preg_match('/(\d+)$/', $lastNumber, $matches)) {
                $nextNumber = (int) $matches[1] + 1;
            } else {
                $nextNumber = 1;
            }

            $newInvoice = $prefix . str_pad($nextNumber, 7, '0', STR_PAD_LEFT);
        } while (
            Journal::where('invoice', $newInvoice)->exists() ||
            ServiceOrder::where('invoice', $newInvoice)->exists()
        );

        return $newInvoice;
    }




    public static function purchase_journal()
    {
        // Untuk purchase journal, kita menambahkan kondisi agar hanya mengembalikan yang quantity > 0
        return self::generateJournalInvoice('PO.BK', 'transactions', [['transaction_type', '=', 'Purchase']]);
    }

    public static function adjustment_journal()
    {
        // Untuk purchase journal, kita menambahkan kondisi agar hanya mengembalikan yang quantity > 0
        return self::generateJournalInvoice('AJ.BK', 'transactions', [['transaction_type', '=', 'Adjustment']]);
    }

    public static function mutation_journal()
    {
        // Untuk purchase journal, kita menambahkan kondisi agar hanya mengembalikan yang quantity > 0
        return self::generateJournalInvoice('TR.BK', 'transactions', [['transaction_type', '=', 'Mutation']]);
    }

    public static function endBalanceBetweenDate($account_code, $start_date, $end_date)
    {
        $initBalance = ChartOfAccount::with('account')->where('id', $account_code)->first();

        $transactions = self::where(function ($query) use ($account_code) {
            $query
                ->where('debt_code', $account_code)
                ->orWhere('cred_code', $account_code);
        })
            ->whereBetween('date_issued', [
                $start_date,
                $end_date,
            ])
            ->get();

        $debit = $transactions->where('debt_code', $account_code)->sum('amount');
        $credit = $transactions->where('cred_code', $account_code)->sum('amount');

        if ($initBalance->account->status == "D") {
            return $initBalance->st_balance + $debit - $credit;
        } else {
            return $initBalance->st_balance + $credit - $debit;
        }
    }

    public static function equityCount($end_date, $includeEquity = true)
    {
        $coa = ChartOfAccount::all();

        foreach ($coa as $coaItem) {
            $coaItem->balance = self::endBalanceBetweenDate($coaItem->acc_code, '0000-00-00', $end_date);
        }

        $initBalance = $coa->where('acc_code', '30100-001')->first()->st_balance;
        $assets = $coa->whereIn('account_id', \range(1, 18))->sum('balance');
        $liabilities = $coa->whereIn('account_id', \range(19, 25))->sum('balance');
        $equity = $coa->where('account_id', 26)->sum('balance');

        // Use Eloquent to update a specific record
        ChartOfAccount::where('acc_code', '30100-001')->update(['st_balance' => $initBalance + $assets - $liabilities - $equity]);

        // Return the calculated equity
        return ($includeEquity ? $initBalance : 0) + $assets - $liabilities - ($includeEquity ? $equity : 0);
    }

    public static function profitLossCount($startDate, $endDate)
    {
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = Carbon::parse($endDate)->endOfDay();

        $accounts = ChartOfAccount::with(['entriesWithJournal' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('journals.date_issued', [$startDate, $endDate]);
        }, 'account'])->get();

        // Hitung balance berdasarkan status akun
        $calculateBalance = function ($acc) {
            $entries = collect($acc->entriesWithJournal);
            return $acc->account->status == "D"
                ? $entries->sum('debit') - $entries->sum('credit')
                : $entries->sum('credit') - $entries->sum('debit');
        };

        // Kategorisasi akun
        $revenue = $accounts->whereIn('account_id', range(27, 30));
        $cost = $accounts->whereIn('account_id', range(31, 32));
        $expense = $accounts->whereIn('account_id', range(33, 45));

        // Hitung total per kategori
        $totalRevenue = $revenue->sum($calculateBalance);
        $totalCost = $cost->sum($calculateBalance);
        $totalExpense = $expense->sum($calculateBalance);

        $profitOrLoss = $totalRevenue - $totalCost - $totalExpense;

        return $profitOrLoss;
    }


    public static function cashflowCount($startDate, $endDate)
    {
        // $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : now()->startOfDay();
        // $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : now()->endOfDay();
        Log::info($endDate);

        $accounts = ChartOfAccount::with(['entriesWithJournal' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('journals.date_issued', [$startDate, $endDate]);
        }, 'account'])
            ->whereIn('account_id', [1, 2])
            ->get();

        foreach ($accounts as $acc) {
            $entries = collect($acc->entriesWithJournal ?? []);
            $status = $acc->account->status ?? 'D';

            $sumDebtCredit = $status == "D"
                ? $entries->sum('debit') - $entries->sum('credit')
                : $entries->sum('credit') - $entries->sum('debit');

            $acc->balance = $acc->st_balance + $sumDebtCredit;
        }

        return $accounts->sum('balance');
    }
}
