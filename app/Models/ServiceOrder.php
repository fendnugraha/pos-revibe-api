<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $with = ['contact', 'user', 'warehouse', 'technician'];

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'invoice', 'invoice');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class, 'invoice', 'invoice');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'phone_number', 'phone_number');
    }

    public static function generateOrderNumber($warehouse_id, $user_id)
    {
        $warehouse_code = Warehouse::where('id', $warehouse_id)->value('code');

        $lastOrder = ServiceOrder::where('warehouse_id', $warehouse_id)
            ->whereDate('created_at', now()->startOfDay())
            ->where('user_id', $user_id)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastOrder && preg_match('/(\d+)$/', $lastOrder->order_number, $matches)) {
            $lastNumber = (int) $matches[1];
        } else {
            $lastNumber = 0;
        }

        $newNumber = $lastNumber + 1;

        $prefix = 'ORDER-' . $warehouse_code . '-' . date('dmY') . '-' . $user_id . '-';
        $formatted = $prefix . str_pad($newNumber, 5, '0', STR_PAD_LEFT);

        return $formatted;
    }
}
