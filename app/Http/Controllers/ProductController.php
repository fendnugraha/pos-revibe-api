<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Journal;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Models\StockMovement;
use App\Models\WarehouseStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;
use App\Models\ChartOfAccount;
use App\Models\ProductCategory;

class ProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $products = Product::with('category')->when($request->search, function ($query, $search) {
            $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('code', 'like', '%' . $search . '%');
        })
            ->orderBy('name')
            ->paginate(10)->onEachSide(0);

        return new DataResource($products, true, "Successfully fetched products");
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
        $request->validate(
            [
                'name' => 'required|string|max:255|unique:products,name',
                'category_id' => 'required',  // Make sure category_id is present
                'price' => 'required|numeric',
                'cost' => 'required|numeric',
                'is_service' => 'required|boolean'
            ]
        );

        $product = Product::create([
            'name' => $request->name,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'init_cost' => $request->cost,
            'current_cost' => $request->cost,
            'is_service' => $request->is_service
        ]);

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $product
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Product $product)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Product $product)
    {
        $request->validate(
            [
                'name' => 'required|string|max:255|unique:products,name,' . $product->id,
                'category_id' => 'required|exists:product_categories,id',  // Make sure category_id is present
                'price' => 'required|numeric|min:' . $product->current_cost,
                'current_cost' => 'required|numeric',
            ]
        );

        $product->update([
            'name' => $request->name,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'current_cost' => $request->current_cost
        ]);

        return response()->json([
            'message' => 'Product updated successfully',
            'product' => $product->refresh()
        ], 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Product $product)
    {
        $transactionsExist = $product->transactions()->exists();
        if ($transactionsExist) {
            return response()->json([
                'success' => false,
                'message' => 'Product cannot be deleted because it has transactions'
            ], 400);
        }

        $product->delete();
        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ], 200);
    }

    public function getAllProducts()
    {
        $products = Product::with('category')->orderBy('name')->get();
        return new DataResource($products, true, "Successfully fetched products");
    }

    public function getAllProductsByWarehouse($warehouse, $endDate, Request $request)
    {
        $status = $request->status;

        $products = Product::withSum([
            'stock_movements' => function ($query) use ($warehouse, $endDate, $status) {
                $query->where('warehouse_id', $warehouse)
                    ->where('date_issued', '<=', Carbon::parse($endDate)->endOfDay())
                    ->when($status, function ($query) use ($status) {
                        $query->where('status', $status);
                    });
            }
        ], 'quantity')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->where('is_service', false)
            ->orderBy('name');

        $summarizedProducts = Product::selectRaw('SUM(stock_movements.cost * stock_movements.quantity) as total_cost')
            ->join('stock_movements', 'products.id', '=', 'stock_movements.product_id')
            ->where('products.is_service', false)
            ->where('stock_movements.warehouse_id', $warehouse)
            ->where('stock_movements.date_issued', '<=', Carbon::parse($endDate)->endOfDay())
            ->when($status, function ($query) use ($status) {
                $query->where('stock_movements.status', $status);
            })
            ->value('total_cost');

        $data = [
            'products' => $request->boolean('paginated')
                ? $products->paginate($request->get('per_page', 10))->onEachSide(0)
                : $products->get(),
            'summarizedProducts' => $summarizedProducts
        ];

        return new DataResource($data, true, "Successfully fetched products");
    }


    public function stockAdjustment(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'warehouse_id' => 'required|exists:warehouses,id',
            'cost' => 'required|numeric',
        ]);

        if (!$request->is_initial) {
            $request->validate([
                'date' => 'required|date',
                'account_id' => 'required|exists:chart_of_accounts,id',
                'adjustmentType' => 'required|in:in,out',
            ]);
        }

        $product = Product::findOrFail($request->product_id);

        DB::beginTransaction();
        try {
            // jika initial stock lama ada -> hapus dulu
            if ($request->is_initial) {
                $initProduct = StockMovement::where('product_id', $request->product_id)
                    ->where('is_initial', true)
                    ->where('warehouse_id', $request->warehouse_id)
                    ->first();

                if ($initProduct && $initProduct->transaction) {
                    $oldTransaction = $initProduct->transaction;

                    // hapus journal lama
                    Journal::where('invoice', $oldTransaction->invoice)->delete();

                    // hapus stock movement lama
                    $oldTransaction->stock_movements()->delete();

                    // hapus transaksi lama
                    $oldTransaction->delete();
                }
            }

            // buat transaksi baru
            $newInvoice = Journal::adjustment_journal();

            $transaction = Transaction::create([
                'date_issued' => $request->date ?? now(),
                'invoice' => $newInvoice,
                'transaction_type' => "Adjustment",
                'status' => "Active",
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            $journal = Journal::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Adjustment',
                'description' => 'Penyesuaian Stok. Note: ' . ($request->description ?? ''),
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            if ($request->is_initial) {
                // jurnal initial stock
                $journal->entries()->createMany([
                    [
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'chart_of_account_id' => ChartOfAccount::MODAL_EQUITY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ],
                ]);

                $product->update(['init_cost' => $request->cost]);

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date ?? now(),
                    'product_id' => $request->product_id,
                    'quantity' => $request->quantity,
                    'cost' => $request->cost,
                    'price' => 0,
                    'is_initial' => true,
                    'warehouse_id' => $request->warehouse_id,
                    'transaction_type' => "Adjustment",
                ]);
            } else {
                // jurnal adjustment biasa
                $journal->entries()->createMany([
                    [
                        'chart_of_account_id' => $request->adjustmentType == "in" ? ChartOfAccount::INVENTORY : $request->account_id,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'chart_of_account_id' => $request->adjustmentType == "in" ? $request->account_id : ChartOfAccount::INVENTORY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ],
                ]);

                $transaction->stock_movements()->create([
                    'date_issued' => $request->date,
                    'product_id' => $request->product_id,
                    'quantity' => $request->adjustmentType == "in" ? $request->quantity : -$request->quantity,
                    'cost' => $request->cost,
                    'price' => 0,
                    'warehouse_id' => $request->warehouse_id,
                    'transaction_type' => "Adjustment",
                ]);
            }

            $newCost = Product::updateCost($product->id);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Stock adjustment successful',
                'data' => [
                    'product_id' => $product->id,
                    'new_cost' => $newCost,
                    'warehouse_id' => $request->warehouse_id,
                ]
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 400);
        }
    }

    public function stockReversal(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|numeric',
            'warehouse_id' => 'required|exists:warehouses,id',
            'cost' => 'required|numeric',
            'account_id' => 'required|exists:chart_of_accounts,id',
        ]);

        if ($request->transaction_type == "Sales") {
            $request->validate([
                'price' => 'required|numeric',
            ]);

            $quantity = $request->quantity;
            $account_id = ChartOfAccount::INVENTORY;
        } else {
            $quantity = -$request->quantity;
            $account_id = $request->account_id;
        }

        $product = Product::findOrFail($request->product_id);

        DB::beginTransaction();
        try {
            $newInvoice = Journal::adjustment_journal();

            $transaction = Transaction::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Return',
                'status' => "Active",
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            $journal = Journal::create([
                'invoice' => $newInvoice,
                'date_issued' => $request->date ?? now(),
                'transaction_type' => 'Return',
                'status' => "Active",
                'description' => 'Retur Stok ' . $product->name . '. Note: ' . ($request->description ?? ''),
                'contact_id' => $request->contact_id ?? 1,
                'user_id' => auth()->id(),
                'warehouse_id' => $request->warehouse_id,
            ]);

            if ($request->transaction_type == "Sales") {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $request->account_id,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->price
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INCOME_FROM_SALES,
                        'debit' => $request->quantity * $request->price,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::COST_OF_GOODS_SOLD,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ]
                ]);
            } else {
                $journal->entries()->createMany([
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => $account_id,
                        'debit' => $request->quantity * $request->cost,
                        'credit' => 0
                    ],
                    [
                        'journal_id' => $journal->id,
                        'chart_of_account_id' => ChartOfAccount::INVENTORY,
                        'debit' => 0,
                        'credit' => $request->quantity * $request->cost
                    ]
                ]);
            }

            $transaction->stock_movements()->create([
                'date_issued' => $request->date,
                'product_id' => $request->product_id,
                'quantity' => $quantity,
                'cost' => $request->cost,
                'price' => $request->price,
                'warehouse_id' => $request->warehouse_id,
                'transaction_type' => "Return",
            ]);

            Product::updateCost($product->id);

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Stock reversal successful',
                'data' => [
                    'product_id' => $product->id,
                    'warehouse_id' => $request->warehouse_id,
                ]
            ]);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 400);
        }
    }

    public function import(Request $request)
    {
        $products = $request->input('data', []);

        DB::beginTransaction();
        try {
            foreach ($products as $item) {
                $category = ProductCategory::where('name', $item['category_name'])->first();

                if (!$category) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => "Category {$item['category_name']} not found"
                    ], 404);
                }

                Product::updateOrCreate(
                    ['name' => $item['name']], // key unik
                    [
                        'code'         => $item['code'],
                        'category_id'  => $category->id,
                        'is_service'   => (bool) ($item['is_service'] ?? false),
                        'price'        => $item['price'],
                        'init_cost'    => $item['init_cost'] ?? 0,
                        'current_cost' => $item['current_cost'] ?? 0,
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Products imported successfully'
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage()
            ], 400);
        }
    }

    public function productHistory(Request $request, $id)
    {
        $product = Product::find($id);
        $startDate = $request->start_date
            ? Carbon::parse($request->start_date)->startOfDay()
            : Carbon::now()->startOfDay();
        $endDate = $request->end_date
            ? Carbon::parse($request->end_date)->endOfDay()
            : Carbon::now()->endOfDay();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        $stock_movements = $product->stock_movements()
            ->with('warehouse', 'transaction')
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->orderByDesc('date_issued')
            ->paginate(10, ['*'], 'productHistory')
            ->onEachSide(0);

        $data = [
            'product' => $product,
            'stock_movements' => $stock_movements
        ];

        return response()->json([
            'success' => true,
            'message' => 'Product history',
            'data' => $data
        ], 200);
    }

    public function importTransferItem(Request $request)
    {
        $request->validate([
            'from' => 'required|exists:warehouses,id',
            'to' => 'required|exists:warehouses,id',
            'date_issued' => 'required|date',
            'cart' => 'required|array',
            'cart.*.code' => 'required|exists:products,code',
            'cart.*.quantity' => 'required|numeric|min:1',
        ]);

        DB::beginTransaction();
        try {
            $newInvoice = Journal::mutation_journal();
            $transaction = Transaction::create([
                'date_issued' => $request->date_issued,
                'invoice' => $newInvoice,
                'transaction_type' => "Mutation",
                'contact_id' => 1,
                'warehouse_id' => $request->from,
                'user_id' => auth()->id(),
                'status' => "Confirmed",
            ]);

            foreach ($request->cart as $item) {
                $product = Product::where('code', $item['code'])->first();
                $quantity = $item['quantity'];

                StockMovement::insert([
                    [
                        'date_issued' => $request->date_issued,
                        'product_id' => $product->id,
                        'transaction_id' => $transaction->id,
                        'quantity' => -$quantity,
                        'cost' => $product->current_cost,
                        'price' => $product->price,
                        'warehouse_id' => $request->from,
                        'transaction_type' => "Mutation",
                    ],
                    [
                        'date_issued' => $request->date_issued,
                        'product_id' => $product->id,
                        'transaction_id' => $transaction->id,
                        'quantity' => $quantity,
                        'cost' => $product->current_cost,
                        'price' => $product->price,
                        'warehouse_id' => $request->to,
                        'transaction_type' => "Mutation",
                    ]
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Item transfered successfully',
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 400);
        }
    }

    public function transferItem(Request $request)
    {
        $request->validate([
            'from' => 'required|exists:warehouses,id',
            'to' => 'required|exists:warehouses,id',
            'date_issued' => 'required|date',
            'cart' => 'required|array',
            'cart.*.id' => 'required|exists:products,id',
            'cart.*.quantity' => 'required|numeric|min:1',
        ]);

        DB::beginTransaction();
        try {
            $newInvoice = Journal::mutation_journal();
            $transaction = Transaction::create([
                'date_issued' => $request->date_issued,
                'invoice' => $newInvoice,
                'transaction_type' => "Mutation",
                'contact_id' => 1,
                'warehouse_id' => $request->from,
                'user_id' => auth()->id(),
                'status' => "Confirmed",
            ]);

            foreach ($request->cart as $item) {
                $product = Product::find($item['id']);
                $quantity = $item['quantity'];

                StockMovement::insert([
                    [
                        'date_issued' => $request->date_issued,
                        'product_id' => $product->id,
                        'transaction_id' => $transaction->id,
                        'quantity' => -$quantity,
                        'cost' => $product->current_cost,
                        'price' => $product->price,
                        'warehouse_id' => $request->from,
                        'transaction_type' => "Mutation",
                    ],
                    [
                        'date_issued' => $request->date_issued,
                        'product_id' => $product->id,
                        'transaction_id' => $transaction->id,
                        'quantity' => $quantity,
                        'cost' => $product->current_cost,
                        'price' => $product->price,
                        'warehouse_id' => $request->to,
                        'transaction_type' => "Mutation",
                    ]
                ]);
            }

            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Item transfered successfully',
            ], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => $th->getMessage(),
            ], 400);
        }
    }
}
