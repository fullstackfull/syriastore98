<?php

namespace Workdo\PartialPayments\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PartialPayments extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_id',
        'manage_store_wise',
        'enable_deposit',
        'force_deposit',
        'deposit_amount',
        'deposit_type',
        'store_id',
    ];

    public static function saveData($product, $data)
    {
        if ($product || $data) {
            $productData = [
                'enable_deposit' => $data['enable_deposit'] ?? '',
                'manage_store_wise' => $data['manage_store_setting_wise'] ?? '',
                'force_deposit' => $data['force_deposit'] ?? '',
                'deposit_amount' => $data['deposit_amount'] ?? 0,
                'deposit_type' => $data['deposit_type'],
                'store_id' => $data['store_id'] ?? getCurrentStore(),
            ];
        
            if(isset($data['deposit_amount']))
            {
                PartialPayments::updateOrCreate(
                    ['product_id' => $product->id],
                    $productData
                );
            }
        }
    }

    public static function ManagePartialPayment($request_data , $slug)
    {
        $partial_payment = OrderPartialPayments::find($request_data['partial_payment_id']);
        $partial_payment->payment_status = 'On Hold';
        $partial_payment->payment_type = $request_data['payment_type'];
        $partial_payment->save();

        $return['status'] = true;
        $return['message'] = 'Transaction has been successfully';

        return $return;
    }
}
