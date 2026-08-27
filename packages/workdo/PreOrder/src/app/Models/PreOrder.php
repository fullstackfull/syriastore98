<?php

namespace Workdo\PreOrder\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Events\OrderPaymentStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PreOrder extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'enable_pre_order',
        'name',
        'message',
        'created_by',
        
        'store_id',
    ];


    public static function ManageOrderStatus($data)
    {
        $user = auth()->user();
        if (module_is_active('PreOrder')) {
            if ($user) {
                $order_id = $data['order_id'];
                $order_status = $data['order_status'];
                $order = Order::find($order_id);
                if ($order_status == 'pre_order') {
                    $order->delivered_status = 8;
                    $order->save();
                    event(new OrderPaymentStatus($order));

                    $return['status'] = 'success';
                    $return['message'] = __('Order status changed.');
                    return $return;
                }
            }
        }
    }

    public static function ManageOrderStatusValue($order_array, $order)
    {
        if (module_is_active('PreOrder')) {
            if ($order->delivered_status == 8) {
                $order_array['order_status_text'] = __('Pre Order');
                return $order_array;
            }
        }
        return $order_array;
    }

}
