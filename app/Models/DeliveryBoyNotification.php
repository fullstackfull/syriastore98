<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryBoyNotification extends Model
{
    use HasFactory;

    protected $table = 'delivery_boy_notifications';

    protected $fillable = [
        'delivery_boy_id',
        'order_id',
        'type',
        'title',
        'message',
        'data',
        'is_read',
        'push_sent',
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'push_sent' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function deliveryBoy()
    {
        return $this->belongsTo(DeliveryBoy::class, 'delivery_boy_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopePushSent($query)
    {
        return $query->where('push_sent', true);
    }

    public function scopeForDeliveryBoy($query, $deliveryBoyId)
    {
        return $query->where('delivery_boy_id', $deliveryBoyId);
    }

    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function markAsRead()
    {
        $this->update(['is_read' => true]);
        return $this;
    }

    public function markAsUnread()
    {
        $this->update(['is_read' => false]);
        return $this;
    }

    public static function markAllAsRead($deliveryBoyId)
    {
        return self::where('delivery_boy_id', $deliveryBoyId)
                   ->where('is_read', false)
                   ->update(['is_read' => true]);
    }

    public static function getUnreadCount($deliveryBoyId)
    {
        return self::where('delivery_boy_id', $deliveryBoyId)
                   ->where('is_read', false)
                   ->count();
    }
}