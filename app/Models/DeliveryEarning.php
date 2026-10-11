<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryEarning extends Model
{
    protected $table = 'delivery_earnings';

    protected $fillable = [
        'delivery_boy_id',
        'order_id',
        'shipping_method_id',
        'shipping_cost',
        'commission_percentage',
        'commission_amount',
        'status',
        'paid_at',
    ];

    protected $casts = [
        'shipping_cost' => 'decimal:2',
        'commission_percentage' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function deliveryBoy()
    {
        return $this->belongsTo(DeliveryBoy::class, 'delivery_boy_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function shippingMethod()
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }
}