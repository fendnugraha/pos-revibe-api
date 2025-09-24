<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Contact;
use App\Models\Finance;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;

use function Pest\Laravel\get;

class ServiceOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $startDate = $request->start_date ? Carbon::parse($request->start_date)->startOfMonth() : Carbon::now()->startOfMonth();
        $endDate = $request->end_date ? Carbon::parse($request->end_date)->endOfMonth() : Carbon::now()->endOfMonth();

        $orders = ServiceOrder::with(['contact', 'user', 'warehouse', 'technician'])
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->when($request->warehouse_id, function ($query) use ($request) {
                return $query->where('warehouse_id', $request->warehouse_id);
            })
            ->where(function ($query) use ($request) {
                $query->where('order_number', 'like', '%' . $request->search . '%')
                    ->orWhere('phone_type', 'like', '%' . $request->search . '%')
                    ->orWhereHas('contact', function ($q) use ($request) {
                        $q->where('name', 'like', '%' . $request->search . '%')
                            ->orWhere('phone_number', 'like', '%' . $request->search . '%');
                    });
            })
            ->when($request->status !== "All Orders", function ($query) use ($request) {
                return $query->where('status', $request->status);
            })
            // ->when(auth()->user()->role->role !== 'Administrator', function ($query) {
            //     return $query->where('warehouse_id', auth()->user()->role->warehouse_id);
            // })
            ->orderBy('updated_at', 'desc');

        $orderStatusCount = ServiceOrder::select('status', DB::raw('COUNT(*) as total'))
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->groupBy('status')
            // ->when(auth()->user()->role->role !== 'Administrator', function ($query) {
            //     return $query->where('warehouse_id', auth()->user()->role->warehouse_id);
            // })
            ->pluck('total', 'status') // key = status, value = total
            ->toArray();

        $data = [
            'orders' => $request->boolean('paginated') ? $orders->paginate($request->per_page ?? 10)->onEachSide(0) : $orders->get(),
            'orderStatusCount' => $orderStatusCount
        ];

        return new DataResource($data, true, "Successfully fetched service orders");
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
        $request->validate([
            'date_issued' => 'required|date',
            'name' => 'required|string|max:255',
            'description' => 'required|string|max:255',
            'phone_number' => 'required|string|max:15|min:9',
            'phone_type' => 'required|string|max:30',
            'address' => 'required|string|max:160'
        ]);
        $newInvoice = ServiceOrder::generateOrderNumber(auth()->user()->role->warehouse_id, auth()->user()->id);
        DB::beginTransaction();
        try {
            $serviceOrder = ServiceOrder::create([
                'date_issued' => $request->date_issued,
                'order_number' => $newInvoice,
                'name' => $request->name,
                'description' => $request->description,
                'phone_number' => $request->phone_number,
                'phone_type' => $request->phone_type,
                'address' => $request->address,
                'status' => 'Pending',
                'user_id' => auth()->user()->id,
                'warehouse_id' => auth()->user()->role->warehouse_id
            ]);

            Contact::firstOrCreate(
                ['phone_number' => $request->phone_number],
                [
                    'name' => $request->name,
                    'type' => 'Customer',
                    'address' => $request->address
                ]
            );


            DB::commit();
            return response()->json(['success' => true, 'message' => 'Service order created successfully', 'data' => $serviceOrder], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ServiceOrder $serviceOrder)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ServiceOrder $serviceOrder)
    {
        //
    }

    public function GetOrderByOrderNumber($order_number)
    {
        $order = ServiceOrder::with(['transaction.stock_movements.product', 'journal' => function ($query) {
            $query->with(['serviceFee', 'sales_discount', 'entries.chartOfAccount:id,acc_name,account_id']);
        }])->where('order_number', $order_number)->first();

        return new DataResource($order, true, "Successfully fetched service order");
    }

    public function updateOrderStatus(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'status' => 'required|string'
        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();

        if ($order->invoice && $order->status == 'In Progress' && $request->status == 'Canceled') {
            return response()->json(['success' => false, 'message' => 'Pembatalan gagal, sudah ada transaksi dan pergantian sparepart'], 400);
        }

        if ($order->status == 'In Progress' && $request->status == 'Take Over' && $order->technician_id != auth()->user()->id) {
            $order->technician_id = auth()->user()->id;
            $order->save();
            return response()->json(['success' => true, 'message' => 'Order ' . $order->order_number . ' diambil alih oleh ' . auth()->user()->name . '', 'data' => $order], 200);
        }

        if ($request->status == 'Take Over' && $order->technician_id === auth()->user()->id) {
            return response()->json(['success' => false, 'message' => 'Tidak boleh mengambil order sendiri'], 400);
        }

        if ($order && $order->status != $request->status) {
            $order->status = $request->status;
            $order->technician_id = auth()->user()->id;
            $order->save();
            return response()->json(['success' => true, 'message' => 'Order status updated to ' . $request->status . '', 'data' => $order], 200);
        } else {
            return response()->json(['success' => false, 'message' => 'Failed to update order status'], 400);
        }
    }

    public function makePayment(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'date_issued' => 'required|date',
            'paymentAccountID' => 'required|exists:chart_of_accounts,id',
            'paymentMethod' => 'required'

        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();
        $totalPrice = $order?->transaction?->stock_movements()->selectRaw('SUM(quantity * price) as total')->value('total');
        $totalCost = $order?->transaction?->stock_movements()->selectRaw('SUM(quantity * cost) as total')->value('total');
        $newInvoice = $order?->invoice !== null ? $order->invoice : Journal::order_journal();

        if ($order) {
            DB::beginTransaction();
            try {
                // Pastikan order punya invoice
                if ($order->invoice === null) {
                    $order->invoice = $newInvoice;
                    $order->save();
                }

                $journal = Journal::create([
                    'invoice' => $order->invoice ?? $newInvoice,  // Menggunakan metode statis untuk invoice
                    'date_issued' => $request->date_issued ?? now(),
                    'transaction_type' => 'Sales',
                    'description' => 'Pembayaran Service Order ' . $order->order_number . '. Note: ' . $request->note,
                    'journal_type' => 'Transaction',
                    'finance_type' => $request->paymentMethod == 'credit' ? 'Receivable' : null,
                    'user_id' => auth()->user()->id,
                    'warehouse_id' => $order->warehouse_id
                ]);

                if ($request->paymentMethod == "credit") {
                    Finance::create([
                        'date_issued' => $request->date_issued ?? now(),
                        'due_date' => $request->date_issued ?? now()->addDays(30),
                        'invoice' => $order->invoice ?? $newInvoice,
                        'description' => 'Pembayaran Service Order ' . $order->order_number,
                        'bill_amount' => (-$totalPrice + $request->serviceFee - $request->discount),
                        'payment_amount' => 0,
                        'payment_nth' => 0,
                        'finance_type' => 'Receivable',
                        'contact_id' => $order->contact->id,
                        'user_id' => auth()->user()->id,
                        'journal_id' => $journal->id
                    ]);
                }

                if ($order->transaction()->exists()) {
                    $journal->entries()->createMany([
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => $request->paymentAccountID,
                            'debit' => -$totalPrice,
                            'credit' => 0
                        ],
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => 16,
                            'debit' => 0,
                            'credit' => -$totalPrice
                        ],
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => 10,
                            'debit' => 0,
                            'credit' => -$totalCost
                        ],
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => 21,
                            'debit' => -$totalCost,
                            'credit' => 0
                        ]
                    ]);
                }

                if ($request->serviceFee) {
                    $journal->entries()->createMany([
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => 17,
                            'debit' => 0,
                            'credit' => $request->serviceFee
                        ],
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => $request->paymentAccountID,
                            'debit' => $request->serviceFee,
                            'credit' => 0
                        ]
                    ]);
                }

                if ($request->discount) {
                    $journal->entries()->createMany([
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => $request->paymentAccountID,
                            'debit' => 0,
                            'credit' => $request->discount
                        ],
                        [
                            'journal_id' => $journal->id,
                            'chart_of_account_id' => 44,
                            'debit' => $request->discount,
                            'credit' => 0
                        ]
                    ]);
                }

                if ($order->transaction()->exists()) {
                    $order->transaction()->update([
                        'transaction_type' => 'Sales',
                        'payment_method'   => $request->paymentMethod == "cash"
                            ? "Cash/Bank Transfer"
                            : "Credit",
                    ]);
                }

                $order->update(['status' => "Completed", 'payment_method'   => $request->paymentMethod == "cash"
                    ? "Cash/Bank Transfer"
                    : "Credit",]);

                DB::commit();
                return response()->json(['success' => true, 'message' => 'Payment made successfully', 'data' => $order], 200);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e->getMessage());
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Service order not found'], 404);
        }
    }

    public function updatePaymentOrder($order_number, Request $request)
    {
        $order = ServiceOrder::where('order_number', $order_number)->first();

        $request->validate([
            'date_issued' => 'required|date',
            'paymentAccountID' => 'exists:chart_of_accounts,id',
            'note' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $order->journal()->update([
                'date_issued' => $request->date_issued,
                'description' => 'Pembayaran Service Order ' . $order->order_number . '. Note: ' . $request->note
            ]);

            if ($request->paymentAccountID && $order->payment_method !== "Credit") {
                $order->journal->entries()->where('chart_of_account_id', $request->oldPaymentAccountID)->update([
                    'chart_of_account_id' => $request->paymentAccountID
                ]);
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Payment date updated successfully', 'data' => $order], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function addPartsToOrder(Request $request)
    {
        $request->validate([
            'order_number' => 'required|string',
            'parts' => 'required|array'
        ]);

        $order = ServiceOrder::where('order_number', $request->order_number)->first();
        Log::info($order);
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Service order not found'], 404);
        }

        $transactionExists = Transaction::where('invoice', $order->invoice)->exists();

        $newinvoice = $transactionExists ? $order->invoice : Journal::order_journal();
        $warehouseId = auth()->user()->role->warehouse_id;
        $userId = auth()->user()->id;

        DB::beginTransaction();
        try {
            if ($transactionExists) {
                $transaction = Transaction::where('invoice', $order->invoice)->first();
            } else {
                $transaction = Transaction::create([
                    'date_issued' => now(),
                    'invoice' => $newinvoice,
                    'transaction_type' => "Order",
                    'status' => "Confirmed",
                    'contact_id' => $order->contact->id ?? 1,
                    'warehouse_id' => $warehouseId,
                    'user_id' => $userId
                ]);
            }

            foreach ($request->parts as $item) {
                $cost = Product::find($item['id'])->current_cost;
                $itemExists = $transaction->stock_movements()->where('product_id', $item['id'])->exists();

                if ($itemExists) {
                    continue;
                }

                $transaction->stock_movements()->create([
                    'date_issued' => now(),
                    'product_id' => $item['id'],
                    'quantity' => -$item['quantity'],
                    'cost' => $cost,
                    'price' => $item['price'],
                    'warehouse_id' => $warehouseId,
                    'transaction_type' => "Order"
                ]);
            }

            $order->invoice = $newinvoice;
            $order->save();

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Parts added to order successfully', 'data' => $order], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function getRevenueByUser($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate   = $endDate ? Carbon::parse($endDate)->endOfDay()   : Carbon::now()->endOfDay();

        $orders = ServiceOrder::select('service_orders.technician_id')
            ->addSelect(DB::raw('COUNT(DISTINCT service_orders.id) as total_orders'))
            ->addSelect(DB::raw('SUM(CASE WHEN je.chart_of_account_id = 17 THEN (je.credit - je.debit) ELSE 0 END) as total_fee'))
            ->whereBetween('service_orders.updated_at', [$startDate, $endDate])
            ->where('service_orders.status', 'Completed')
            ->join('journals as j', 'j.invoice', '=', 'service_orders.invoice')
            ->join('journal_entries as je', 'je.journal_id', '=', 'j.id')
            ->groupBy('service_orders.technician_id')
            ->get();

        $orders->load('technician');

        return response()->json([
            'success' => true,
            'message' => 'Order retrieved successfully',
            'data'    => $orders
        ], 200);
    }

    public function trackingOrders(Request $request)
    {
        $request->validate(
            [
                'search' => 'required|string',
            ],
            [
                'search.required' => 'Masukan nomor telepon atau nomor order terlebih dahulu',
            ]
        );

        $order = ServiceOrder::with('contact')->where('order_number', $request->search)->orWhereHas('contact', function ($query) use ($request) {
            $query->where('phone_number', $request->search);
        })->latest('updated_at')->get();
        if ($order->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Hasil pencarian ' . $request->search . ' tidak ditemukan',
            ], 404);
        }

        if ($order) {
            return response()->json([
                'success' => true,
                'message' => 'Order retrieved successfully',
                'data'    => $order
            ], 200);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }
    }

    public function removePartFromOrder(Request $request)
    {
        $order = ServiceOrder::findOrFail($request->order_id);

        // asumsi relasi: ServiceOrder -> transaction -> stock_movements (hasMany)
        $order->transaction->stock_movements()
            ->where('product_id', $request->part_id)
            ->delete();

        // Cek lagi apakah stock_movements masih ada
        if ($order->transaction->stock_movements()->count() === 0) {
            $order->invoice = null;
            $order->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Part removed from order successfully',
            'data' => $order->fresh('transaction.stock_movements') // refresh supaya data terbaru
        ], 200);
    }

    public function voidOrder(Request $request)
    {
        $order = ServiceOrder::where('order_number', $request->order_number)->firstOrFail();
        $transactionExists = Transaction::where('invoice', $order->invoice)->exists();
        $journalExists = Journal::where('invoice', $order->invoice)->exists();

        DB::beginTransaction();
        try {
            if ($order->journal) {
                // hapus journal dulu
                $order->journal()->delete();
            }

            if ($transactionExists) {
                Transaction::where('invoice', $order->invoice)->delete();
            }

            // update status order
            $order->update(['status' => 'Canceled', 'payment_method' => 'Unpaid']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order voided successfully',
                'data' => $order
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }
}
