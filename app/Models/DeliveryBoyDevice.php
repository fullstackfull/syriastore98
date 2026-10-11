<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryBoyDevice extends Model
{
    use HasFactory;

    protected $table = 'delivery_boy_devices';

    protected $fillable = [
        'delivery_boy_id',
        'fcm_token',
        'device_id',
        'platform',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function deliveryBoy()
    {
        return $this->belongsTo(DeliveryBoy::class, 'delivery_boy_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDeliveryBoy($query, $deliveryBoyId)
    {
        return $query->where('delivery_boy_id', $deliveryBoyId);
    }

    public function scopeWithValidToken($query)
    {
        return $query->whereNotNull('fcm_token')
                     ->where('fcm_token', '!=', '')
                     ->where('fcm_token', '!=', 'YOUR_FIREBASE_TOKEN');
    }
}