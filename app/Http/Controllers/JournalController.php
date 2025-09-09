<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Journal;
use App\Models\Warehouse;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;
use App\Models\ServiceOrder;

class JournalController extends Controller
{
    public $startDate;
    public $endDate;
    /**
     * Display a listing of the resource.
     */

    public function __construct()
    {
        $this->startDate = Carbon::now()->startOfDay();
        $this->endDate = Carbon::now()->endOfDay();
    }

    public function index()
    {
        $journals = Journal::with(['debt', 'cred'])->orderBy('created_at', 'desc')->paginate(10, ['*'], 'journalPage')->onEachSide(0)->withQueryString();
        return new DataResource($journals, true, "Successfully fetched journals");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $journal = Journal::with(['debt', 'cred'])->find($id);
        return new DataResource($journal, true, "Successfully fetched journal");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $request->validate([
            'cred_code' => 'required|exists:chart_of_accounts,id',
            'debt_code' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric|min:0',
            'fee_amount' => 'required|numeric|min:0',
            'description' => 'max:255',
        ]);

        $journal = Journal::findOrFail($id); // Better to fail gracefully
        // $log = new LogActivity();
        $isAmountChanged = $journal->amount != $request->amount;
        $isFeeAmountChanged = $journal->fee_amount != $request->fee_amount;

        DB::beginTransaction();
        try {
            $oldAmount = $journal->amount;
            $oldFeeAmount = $journal->fee_amount;

            $journal->update($request->all());

            $descriptionParts = [];
            if ($isAmountChanged) {
                $oldAmountFormatted = number_format($oldAmount, 0, ',', '.');
                $newAmountFormatted = number_format($request->amount, 0, ',', '.');
                $descriptionParts[] = "Amount changed from Rp $oldAmountFormatted to Rp $newAmountFormatted.";
            }
            if ($isFeeAmountChanged) {
                $oldFeeFormatted = number_format($oldFeeAmount, 0, ',', '.');
                $newFeeFormatted = number_format($request->fee_amount, 0, ',', '.');
                $descriptionParts[] = "Fee amount changed from Rp $oldFeeFormatted to Rp $newFeeFormatted.";
            }


            // if ($isAmountChanged || $isFeeAmountChanged) {
            //     $log->create([
            //         'user_id' => auth()->id,
            //         'warehouse_id' => $journal->warehouse_id,
            //         'activity' => 'Updated Journal',
            //         'description' => 'Updated Journal with ID: ' . $journal->id . '. ' . implode(' ', $descriptionParts),
            //     ]);
            // }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to update journal',
            ]);
        }

        return new DataResource($journal, true, "Successfully updated journal");
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Journal $journal)
    {
        DB::beginTransaction();
        try {
            $journal->delete();

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Journal deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            // Flash an error message
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete journal'
            ]);
        }
    }

    public function createTransfer(Request $request)
    {
        $journal = new Journal();
        $request->validate([
            'debt_code' => 'required|exists:chart_of_accounts,id',
            'cred_code' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric|min:0',
            'trx_type' => 'required',
            'fee_amount' => 'required|numeric|min:0',
            'custName' => 'required|regex:/^[a-zA-Z0-9\s]+$/|min:3|max:255',
        ], [
            'debt_code.required' => 'Akun debet harus diisi.',
            'cred_code.required' => 'Akun kredit harus diisi.',
            'custName.required' => 'Customer name harus diisi.',
            'custName.regex' => 'Customer name tidak valid.',
        ]);
        $description = $request->description ? $request->description . ' - ' . strtoupper($request->custName) : $request->trx_type . ' - ' . strtoupper($request->custName);

        DB::beginTransaction();
        try {
            $journal->create([
                'invoice' => $journal->invoice_journal(),  // Menggunakan metode statis untuk invoice
                'date_issued' => now(),
                'debt_code' => $request->debt_code,
                'cred_code' => $request->cred_code,
                'amount' => $request->amount,
                'fee_amount' => $request->fee_amount,
                'trx_type' => $request->trx_type,
                'description' => $description,
                'user_id' => auth()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Journal created successfully',
                'journal' => $journal
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal'
            ], 500);
        }
    }

    public function createMutation(Request $request)
    {
        $request->validate([
            'date_issued' => 'required|date',
            'debt_code' => 'required|exists:chart_of_accounts,id',
            'cred_code' => 'required|exists:chart_of_accounts,id',
            'amount' => 'required|numeric',
            'admin_fee' => 'numeric|min:0',
        ], [
            'admin_fee.numeric' => 'Biaya admin harus berupa angka.',
            'debt_code.required' => 'Akun debet harus diisi.',
            'cred_code.required' => 'Akun kredit harus diisi.',
        ]);
        Log::info($request->all());

        $description = $request->description ?? 'Mutasi Kas';
        $newInvoice = Journal::general_journal();
        DB::beginTransaction();
        try {
            $journal = Journal::create([
                'invoice' => $newInvoice,  // Menggunakan metode statis untuk invoice
                'date_issued' => $request->date_issued ?? now(),
                'description' => $description,
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            $journal->entries()->createMany([
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => $request->debt_code,
                    'debit' => $request->amount,
                    'credit' => 0
                ],
                [
                    'journal_id' => $journal->id,
                    'chart_of_account_id' => $request->cred_code,
                    'debit' => 0,
                    'credit' => $request->amount
                ]
            ]);

            if ($request->admin_fee > 0) {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::BANK_FEE,
                        'debit' => $request->admin_fee,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $request->cred_code,
                        'debit' => 0,
                        'credit' => $request->admin_fee
                    ]
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Mutasi Kas berhasil',
                'journal' => $journal->load('entries')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create journal'
            ], 500);
        }
    }

    public function getJournalByWarehouse($warehouse, $startDate, $endDate)
    {
        $chartOfAccounts = ChartOfAccount::where('warehouse_id', $warehouse)->pluck('id')->toArray();
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journals = JournalEntry::with(['journal', 'chartOfAccount'])
            ->whereHas('journal', function ($query) use ($startDate, $endDate, $warehouse) {
                $query->whereBetween('date_issued', [$startDate, $endDate])
                    ->where('warehouse_id', $warehouse);
            })
            ->whereIn('chart_of_account_id', $chartOfAccounts)
            ->orderBy('journal_id', 'desc')
            ->get();

        return new DataResource($journals, true, "Successfully fetched journals");
    }

    public function getExpenses($warehouse, $startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $expenses = Journal::with('warehouse', 'debt')
            ->where(function ($query) use ($warehouse) {
                if ($warehouse === "all") {
                    $query;
                } else {
                    $query->where('warehouse_id', $warehouse);
                }
            })
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('trx_type', 'Pengeluaran')
            ->orderBy('id', 'desc')
            ->get();
        return new DataResource($expenses, true, "Successfully fetched chart of accounts");
    }

    public function getWarehouseBalance($endDate)
    {
        $entries = new JournalEntry();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $chartOfAccounts = ChartOfAccount::with('account')->whereIn('account_id', [1, 2])->get();

        $journals = $entries->with('journal', 'chartOfAccount')
            ->whereHas('journal', function ($query) use ($endDate) {
                $query->where('date_issued', '<=', $endDate);
            })->get();

        foreach ($chartOfAccounts as $acc) {
            $debet = $journals->where('chart_of_account_id', $acc->id)->sum('debit');
            $credit = $journals->where('chart_of_account_id', $acc->id)->sum('credit');

            if ($acc->account->status === 'D') {
                $acc->balance = $acc->st_balance + $debet - $credit;
            } else {
                $acc->balance = $acc->st_balance + $credit - $debet;
            }
        }

        $warehouse = Warehouse::where('is_active', true)->orderBy('name', 'asc')->get();

        $data = [
            'warehouses' => $warehouse->map(function ($warehouse) use ($chartOfAccounts) {
                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                    'cash' => $chartOfAccounts->whereIn('account_id', ['1'])->where('warehouse_id', $warehouse->id)->sum('balance'),
                    'bank' => $chartOfAccounts->whereIn('account_id', ['2'])->where('warehouse_id', $warehouse->id)->sum('balance')
                ];
            }),
            'totalCash' => $chartOfAccounts->whereIn('account_id', ['1'])->sum('balance'),
            'totalBank' => $chartOfAccounts->whereIn('account_id', ['2'])->sum('balance')
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function mutationHistory($account, $startDate, $endDate, Request $request)
    {
        $journal = new Journal();
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journal = new Journal();
        $journals = $journal->with('debt.account', 'cred.account', 'warehouse', 'user')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where(function ($query) use ($request) {
                $query->where('invoice', 'like', '%' . $request->search . '%')
                    ->orWhere('description', 'like', '%' . $request->search . '%')
                    ->orWhere('amount', 'like', '%' . $request->search . '%');
            })
            ->where(function ($query) use ($account) {
                $query->where('debt_code', $account)
                    ->orWhere('cred_code', $account);
            })
            ->orderBy('date_issued', 'asc')
            ->paginate(10, ['*'], 'mutationHistory');

        $total = $journal->with('debt.account', 'cred.account', 'warehouse', 'user')->where('debt_code', $account)
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->orWhere('cred_code', $account)
            ->WhereBetween('date_issued', [$startDate, $endDate])
            ->orderBy('date_issued', 'asc')
            ->get();

        $initBalanceDate = Carbon::parse($startDate)->subDay(1)->endOfDay();

        $debt_total = $total->where('debt_code', $account)->sum('amount');
        $cred_total = $total->where('cred_code', $account)->sum('amount');

        $data = [
            'journals' => $journals,
            'initBalance' => $journal->endBalanceBetweenDate($account, '0000-00-00', $initBalanceDate),
            'endBalance' => $journal->endBalanceBetweenDate($account, '0000-00-00', $endDate),
            'debt_total' => $debt_total,
            'cred_total' => $cred_total,
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }

    public function getRankByProfit()
    {
        $journal = new Journal();
        $startDate = Carbon::now()->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $revenue = $journal->with('warehouse')->selectRaw('SUM(fee_amount) as total, warehouse_id')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->where('warehouse_id', '!=', 1)
            ->groupBy('warehouse_id')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $revenue
        ], 200);
    }

    public function getRevenueReport($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate   = $endDate ? Carbon::parse($endDate)->endOfDay()   : Carbon::now()->endOfDay();

        $revenueIds = ChartOfAccount::whereIn('account_id', range(27, 30))->pluck('id')->toArray(); // revenue
        $costIds    = ChartOfAccount::whereIn('account_id', range(31, 32))->pluck('id')->toArray(); // cost
        $expenseIds = ChartOfAccount::whereIn('account_id', range(33, 45))->pluck('id')->toArray(); // expense

        $report = JournalEntry::join('journals', 'journal_entries.journal_id', '=', 'journals.id')
            ->join('warehouses', 'journals.warehouse_id', '=', 'warehouses.id')
            ->whereBetween('journals.date_issued', [$startDate, $endDate])
            ->select(
                'warehouses.id as warehouse_id',
                'warehouses.name as warehouse_name',
                'warehouses.code as warehouse_code',
                DB::raw("
                SUM(
                    CASE WHEN chart_of_account_id IN (" . implode(',', $revenueIds) . ")
                    THEN credit - debit ELSE 0 END
                ) as total_revenue
            "),
                DB::raw("
                SUM(
                    CASE WHEN chart_of_account_id IN (" . implode(',', $costIds) . ")
                    THEN debit - credit ELSE 0 END
                ) as total_cost
            "),
                DB::raw("
                SUM(
                    CASE WHEN chart_of_account_id IN (" . implode(',', $expenseIds) . ")
                    THEN debit - credit ELSE 0 END
                ) as total_expense
            ")
            )
            ->groupBy('warehouses.id', 'warehouses.name', 'warehouses.code')
            ->get()
            ->map(function ($row) use ($startDate, $endDate) {
                $total_order = ServiceOrder::where('warehouse_id', $row->warehouse_id)->whereBetween('updated_at', [$startDate, $endDate])->count();
                return [
                    'warehouse_id'   => $row->warehouse_id,
                    'warehouse_name' => $row->warehouse_name,
                    'warehouse_code' => $row->warehouse_code,
                    'total_order'    => $total_order,
                    'total_revenue'  => (float) $row->total_revenue,
                    'total_cost'     => (float) $row->total_cost,
                    'total_expense'  => (float) $row->total_expense,
                ];
            });

        return response()->json([
            'success' => true,
            'data'    => $report
        ], 200);
    }


    public function getRevenueByWarehouse($warehouse, $startDate, $endDate)
    {
        $entries = new JournalEntry();
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $serviceOrder = ServiceOrder::where('warehouse_id', $warehouse)->whereBetween('date_issued', [$startDate, $endDate])->count();

        $chartOfAccounts = ChartOfAccount::with('account')->get();

        $journals = $entries->with(['journal', 'chartOfAccount'])
            ->where(function ($q) use ($startDate, $endDate, $warehouse, $chartOfAccounts) {
                $q->whereHas('journal', function ($query) use ($startDate, $endDate, $warehouse) {
                    $query->whereBetween('date_issued', [$startDate, $endDate])
                        ->where('warehouse_id', $warehouse);
                })
                    ->orWhereIn('chart_of_account_id', $chartOfAccounts->where('warehouse_id', $warehouse)->pluck('id')->toArray());
            })
            ->get();


        foreach ($chartOfAccounts as $acc) {
            $debet = $journals->where('chart_of_account_id', $acc->id)->sum('debit');
            $credit = $journals->where('chart_of_account_id', $acc->id)->sum('credit');

            if ($acc->account->status === 'D') {
                $acc->balance = $acc->st_balance + $debet - $credit;
            } else {
                $acc->balance = $acc->st_balance + $credit - $debet;
            }
        }

        $revenue = $chartOfAccounts
            ->whereIn('account_id', range(27, 30))
            ->sum('balance');
        $cost = $chartOfAccounts
            ->whereIn('account_id', range(31, 32))
            ->sum('balance');

        $expense = $chartOfAccounts
            ->whereIn('account_id', range(33, 45))
            ->sum('balance');
        $cash = $chartOfAccounts
            ->where('warehouse_id', $warehouse)
            ->where('is_primary_cash', true)
            ->sum('balance');

        $data = [
            'cash' => $cash,
            'revenue' => $revenue,
            'cost' => $cost,
            'expense' => $expense,
            'net_profit' => $revenue - $cost - $expense,
            'service_order' => $serviceOrder
        ];

        return response()->json([
            'success' => true,
            'data' => $data
        ], 200);
    }
}
