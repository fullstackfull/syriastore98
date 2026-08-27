<?php

namespace Workdo\PartialPayments\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderPartialPayments extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'order_id',
        'customer_id',
        'payment',
        'payment_type',
        'order_status',
        'payment_amount',
        'payment_status',
        'store_id',
    ];

    public static function OrderPartialPayments($order, $slug, $request)
    {
        $data = [
            [
                'order_id' => $order->product_order_id,
                'customer_id' => $order->customer_id,
                'payment' => 'Deposit',
                'payment_type' => $request['payment_type'],
                'order_status' => 'Pending',
                'payment_amount' => $order->final_price,
                'payment_status' => 'On Hold',
                'store_id' => $request['store_id'],
                'created_at' => now(),
                'updated_at' => now(),

            ],
            [
                'order_id' => $order->product_order_id,
                'customer_id' => $order->customer_id,
                'payment' => 'Second Payment',
                'payment_type' => '-',
                'order_status' => 'Pending',
                'payment_amount' => $request['order_pending_amount'],
                'payment_status' => 'Pending payment',
                'store_id' => $request['store_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ];

        OrderPartialPayments::insert($data);
    }

    public static function payment_status_change($date = [])
    {
        $payment_id = $date['payment_id'];
        $payment_status = $date['payment_status'];
        $payment = OrderPartialPayments::find($payment_id);

        if (!empty($payment)) {
            $payment->updated_at = now();
            $payment->payment_status = $payment_status;
            $payment->save();

            $return['status'] = 'success';
            $return['message'] = 'Payment status changed.';
            return $return;
        } else {
            $return['status'] = 'error';
            $return['message'] = 'Payment not found.';
            return $return;
        }
    }

    public function CustomerData()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id');
    }
}
