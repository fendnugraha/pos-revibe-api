<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateWarehouseStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stock:recalculate {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::today()->toDateString();

        $this->info("🔄 Recalculating stock for date: {$date}");

        // Ambil per warehouse_id + product_id + balance_date
        $movements = DB::table('stock_movements')
            ->select(
                'warehouse_id',
                'product_id',
                DB::raw("'{$date}' as balance_date"),
                DB::raw('SUM(quantity) as total_quantity')
            )
            ->whereDate('date_issued', '<=', $date)
            ->groupBy('warehouse_id', 'product_id')
            ->get();

        foreach ($movements as $row) {
            DB::table('warehouse_stocks')->updateOrInsert(
                [
                    'warehouse_id' => $row->warehouse_id,
                    'product_id'   => $row->product_id,
                    'balance_date' => $row->balance_date,
                ],
                [
                    'quantity'    => $row->total_quantity,
                    'updated_at'  => now(),
                    'created_at'  => now(),
                ]
            );
        }

        $this->info("✅ Stock recalculated successfully for {$date}");
    }
}
