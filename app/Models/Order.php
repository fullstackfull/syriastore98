<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Events\OrderPaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Order extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'product_order_id',
        'order_date',
        'customer_id',
        'is_guest',
        'product_json',
        'product_id',
        'product_price',
        'coupon_price',
        'delivery_price',
        
                'currency_code',
                'currency_symbol',
        'tax_price',
        'final_price',
        'return_price',
        'payment_comment',
        'payment_type',
        'payment_status',
        'product_order_status',
        'lottery_code',
        'delivery_id',
        'delivery_comment',
        'delivered_status',
        'delivery_date',
        'return_status',
        'return_date',
        'cancel_date',
        'reward_points',
        'additional_note',
        'deliveryboy_id',
        'store_id',
        'created_by',   // orders.created_by added by the schema work; without this
                        // entry Laravel silently discards the value that
                        // OrderController.php:161 passes to Order::create().

    ];

    /**
     * Attribute casts.
     *
     * This model had NO $casts block at all, so the PHP type of every money
     * attribute depended on the database driver: pdo_mysql hands a FLOAT column
     * back as a float on one configuration and as a numeric STRING on another,
     * and sqlite differs again. The same arithmetic therefore behaved
     * differently per environment, and `$order->final_price + 0` could be
     * string concatenation's cousin rather than addition. Casting makes reads
     * and writes consistent in PHP regardless of driver.
     *
     * DELIBERATELY `float`, NOT `decimal:4`, AND THIS IS NOT AN OVERSIGHT.
     * These six columns are float columns today and converting them to
     * DECIMAL(18,4) is gated on the owner (the DECIMAL-vs-minor-units decision,
     * GATE G). A `decimal:4` cast would ALSO change these fields from JSON
     * number to JSON STRING for every API consumer, which is the one genuine
     * API-contract change in the programme: it has to be announced and the
     * Flutter release carrying the matching `double.tryParse` coercions has to
     * reach the store FIRST. So the casts here keep the current wire types
     * exactly and only remove the driver-dependent ambiguity. The `decimal:4`
     * switch belongs in the same commit as the column conversion migration,
     * which is Agent 2's file and the owner's decision.
     */
    protected $casts = [
        'product_price' => 'float',
        'coupon_price' => 'float',
        'delivery_price' => 'float',
        'tax_price' => 'float',
        'final_price' => 'float',
        'return_price' => 'float',
        'reward_points' => 'float',
    ];

    protected $appends = [
        'demo_field',
        'delivered_status_string',
        'delivered_image',
        'order_id_string',
        'return_date',
        'delivery_date',
        'user_name'
    ];

    public function getDemoFieldAttribute()
    {
        return 'demo_field';
    }
    protected static function booted()
    {
        static::creating(function ($order) {
            $storeId = $order->store_id ?? (function_exists('getCurrentStore') ? getCurrentStore() : null);

            if (empty($order->currency_code)) {
                $order->currency_code = \App\Models\Utility::GetValueByName('defult_currancy', $storeId) 
                    ?? \App\Models\Utility::GetValueByName('currency', $storeId) 
                    ?? 'USD';
            }

            if (empty($order->currency_symbol)) {
                $order->currency_symbol = \App\Models\Utility::GetValueByName('defult_currancy_symbol', $storeId) 
                    ?? \App\Models\Utility::GetValueByName('currency_symbol', $storeId) 
                    ?? '$';
            }
        });
    }

    public function getDeliveredImageAttribute()
    {
        $return = '';
        if (!empty($this->delivery_id)) {
            $Shipping = Shipping::find($this->delivery_id);
            $return = !empty($Shipping->image_path) ? $Shipping->image_path : '';
        }
        return $return;
    }



public function getDeliveredStatusStringAttribute()
{
    $status = $this->delivered_status;
    $return = __('Pending');

    if ($status == 0) $return = __('Pending');
    if ($status == 1) $return = __('Delivered');
    if ($status == 2) $return = __('Cancel');
    if ($status == 4) $return = __('Confirmed');
    if ($status == 5) $return = __('Picked Up');
    if ($status == 6) $return = __('Shipped');

    if ($status == 3) {
        if ($this->return_status == 1) $return = __('Return request processing');
        if ($this->return_status == 2) $return = __('Return');
        if ($this->return_status == 3) $return = __('Return cancelled');
    }

    return $return;
}






    public function getDeliveredStatusStringAttribute_backup()
    {
        $order = Order::find($this->id);
        $return = __('Pending');
        if (!empty($order)) {
            if ($order->delivered_status == 0) {
                $return = __('Pending');
            }
            if ($order->delivered_status == 1) {
                $return = __('Delivered');
            }
            if ($order->delivered_status == 2) {
                $return = __('Cancel');
            }
            if ($order->delivered_status == 4) {
                $return = __('Comfirmed');
            }
            if ($order->delivered_status == 5) {
                $return = __('Picked Up');
            }
            if ($order->delivered_status == 6) {
                $return = __('Shipped');
            }

            if ($order->delivered_status == 3 && $order->return_status == 1) {
                $return = __('Return request processing');
            }
            if ($order->delivered_status == 3 && $order->return_status == 2) {
                $return = __('Return');
            }
            if ($order->delivered_status == 3 && $order->return_status == 3) {
                $return = __('Return cancelled');
            }
        }

        return $return;
    }


public function getFormattedFinalPriceAttribute()
{
    return order_currency_format_with_sym($this->final_price, $this->id);
}

public function getFormattedSubTotalAttribute()
{
    return order_currency_format_with_sym($this->product_price, $this->id);
}

    public function getOrderIdStringAttribute()
    {
        return '#' . $this->attributes['product_order_id'];
    }

    public function getReturnDateAttribute()
    {
        $return = date('Y-m-d', strtotime($this->order_date));
        $Shipping = Shipping::find($this->delivery_id);
        if (!empty($Shipping)) {
            if (!empty($this->order_date)) {
                $return_date = $Shipping->return_order_dutation * 2;
                $return_date = date('Y-m-d', strtotime($this->delivery_date . ' + ' . $return_date . ' days'));
                if ($return_date > date('Y-m-d')) {
                    $return = $return_date;
                }
            }

            if ($this->delivered_status == 1 && !empty($this->delivery_date)) {
                $return_date = $Shipping->return_order_dutation;
                $return_date = date('Y-m-d', strtotime($this->delivery_date . ' + ' . $return_date . ' days'));
                if ($return_date > date('Y-m-d')) {
                    $return = $return_date;
                }
            }
        }
        return $return;
    }

    public function getDeliveryDateAttribute()
    {
        $return = null;
        $status = $this->attributes['delivered_status'];

        if ($status == '1') {
            $return =  $this->attributes['delivery_date'];
        }

        return $return;
    }
    public function getUserNameAttribute()
    {
        if ($this->is_guest == 1) {
            return 'Guest';
        }
        
        $customer = $this->CustomerData;
        if ($customer) {
            $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));
            
            return !empty($name) ? $name : ($customer->name ?? $customer->email ?? 'Unknown Customer');
        }
        
        return 'Deleted Customer';
    }

    // **********************************************

    public static $discount_types = [
        '' => 'Select Option',
        'percentage' => 'Percentage',
        'flat' => 'Flat'
    ];

    public function UserData()
    {
        return $this->hasOne(
            User::class,
            'id',
            'user_id'
        );
    }

    public static function order_detail($order_id = 0)
    {
        $order = Order::find($order_id);
        
        if (!empty($order)) {
            $store_id = !empty($order) ? $order->store_id : getCurrentStore();

            // ✅ التعديل 1: جلب لقطة العملة (Snapshot) المحفوظة مع هذا الطلب تحديداً
            $orderCurrencyCode = $order->currency_code ?? 'USD';
            $orderCurrencySymbol = $order->currency_symbol ?? '$';

            $order_array = [];
            $products = json_decode($order->product_json, true);

            if (!empty($products)) {
                if (isset($products['id']) || isset($products['product_id'])) {
                    $products = [$products];
                }
                foreach ($products as $key => $product) {
                    $qty = $product['qty'] ?? ($product['quantity'] ?? 1);
                    $productId = $product['product_id'] ?? ($product['id'] ?? null);

                    if ($order->payment_type == 'POS') {
                        $products[$key] = [
                            "product_id" => $productId,
                            "image" => $product['image'] ?? ($product['cover_image_url'] ?? null),
                            "name" => $product['name'] ?? null,
                            "orignal_price" => $product['orignal_price'] ?? ($product['price'] ?? 0),
                            "total_orignal_price" => SetNumber( $product['total_orignal_price'] ?? (($product['price'] ?? 0) * $qty) ),
                            "final_price" => SetNumber( $product['final_price'] ?? $product['sale_price'] ?? $product['price'] ?? 0 ),
                            "quantity" => $qty,
                            "variant_id" => $product['variant_id'] ?? null,
                            "variant_name" => $product['variant_name'] ?? null,
                            "return" => $product['return'] ?? false,
                        ];
                    } else {
                        $products[$key] = [
                            "product_id" => $productId,
                            "image" => $product['image'] ?? ($product['cover_image_url'] ?? null),
                            "name" => $product['name'] ?? null,
                            "orignal_price" => $product['orignal_price'] ?? ($product['price'] ?? 0),
                            "total_orignal_price" => SetNumber( $product['total_orignal_price'] ?? (($product['price'] ?? 0) * $qty) ),
                            "final_price" => SetNumber( $product['final_price'] ?? $product['sale_price'] ?? $product['price'] ?? 0 ),
                            "qty" => $qty,
                            "variant_id" => $product['variant_id'] ?? null,
                            "variant_name" => $product['variant_name'] ?? null,
                            "return" => $product['return'] ?? false,
                        ];
                        if (isset($product['sale_price'])) {
                            $products[$key]['sale_price'] = SetNumber($product["sale_price"] ?? 0);
                        }
                    }
                }
            }

            $order_array['id'] = $order->id;
            /*
             * P2-04-6. THE ORDER'S OWN STORE, which the detail view needs and could not get.
             *
             * `order/view.blade.php` looks each line item's product up to decide whether to
             * offer a download, and `Product` registers no `StoreScope` and is absent from
             * `config/tenancy.php`'s `scoped_models` -- so that lookup needs an explicit store
             * predicate. It cannot use the CALLER's store: `OrderController::order_view()`
             * passes `getStoreById(getCurrentStore())`, and the platform operator legitimately
             * opens another store's order (the operator limb is first in both
             * `mayUseOrderScreen()` and `OrderDocumentAccessGuard`), so scoping to the caller
             * would blank the line items for exactly the principal the guard admits.
             */
            $order_array['store_id'] = $order->store_id;
            $order_array['is_guest'] = $order->is_guest;
            $order_array['order_id'] = '#' . $order->product_order_id;
            $order_array['delivery_date'] = $order->delivery_date;
            $order_array['order_reward_point'] = $order->reward_points;
            $order_array['return_price'] = $order->return_price;
            $order_array['return_status_message'] = $order->return_status_message;
            $order_array['return_date'] = $order->return_date;
            $order_array['payment_comment'] = $order->payment_comment;
            $order_array['additional_note'] = $order->additional_note;
            $order_array['order_status_text'] = __('Pending');
            $order_array['order_status'] = $order->delivered_status;
            $order_array['order_status_message'] = '';
            $order_array['payment_status'] = $order->payment_status;
            $order_array['payment_receipt'] = $order->payment_receipt ?? null;
            $order_array['deliveryboy_id'] = $order->deliveryboy_id;
            $order_array['delivery_id'] = $order->delivery_id;

            $shippingMethod = null;
            if (!empty($order->delivery_id)) {
                $shippingMethod = ShippingMethod::query()
                    ->where('id', $order->delivery_id)
                    ->where('store_id', $order->store_id)
                    ->first();
            }
            $order_array['shipping_method'] = $shippingMethod?->method_name;

            if ($order->payment_type == 'POS') {
                $order_array['tax_price'] = $order->tax_price;
                $order_array['coupon_price'] = $order->coupon_price;
            }

            if ($order->delivered_status == 0) $order_array['order_status_text'] = __('Pending');
            if ($order->delivered_status == 4) $order_array['order_status_text'] = __('Comfirmed');
            if ($order->delivered_status == 5) $order_array['order_status_text'] = __('Picked Up');
            if ($order->delivered_status == 6) $order_array['order_status_text'] = __('Shipped');
            if ($order->delivered_status == 1) $order_array['order_status_text'] = __('Delivered');
            if ($order->delivered_status == 2) {
                $order_array['order_status_text'] = __('Cancel');
                $order_array['order_status_message'] = __('Your order has been canced.');
            }
            if ($order->delivered_status == 3 && $order->return_status == 1) {
                $order_array['order_status_text'] = __('Return request processing');
                $order_array['order_status_message'] = __('Your order return request has been sent.');
            }
            if ($order->delivered_status == 3 && $order->return_status == 2) {
                $order_array['order_status_text'] = __('Return');
                $order_array['order_status_message'] = __('Your order has been returnd.');
            }
            if ($order->delivered_status == 3 && $order->return_status == 3) {
                $order_array['order_status_text'] = __('Return cancelled');
                $order_array['order_status_message'] = __('Your order return request has been cancelled.');
                $order_array['order_tracking'] = '';
            }
            
            if (module_is_active('PartialPayments')) {
                $value = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageOrderStatusValue($order_array,$order);
                $order_array['order_status_text'] = $value['order_status_text'] ?? '';
            }
            if ($order->delivered_status == 8 && module_is_active('PreOrder')) {
                $value = \Workdo\PreOrder\app\Models\PreOrder::ManageOrderStatusValue($order_array,$order);
                $order_array['order_status_text'] = $value['order_status_text'] ?? '';
            }
            
            $order_array['coupon_price'] = $order->coupon_price;
            $order_array['product'] = $products;
            $order_array['is_review'] = 0;

            // ✅ التعديل 2: إضافة كود ورمز العملة للمصفوفة النهائية لاستخدامها في الواجهة
            $order_array['currency_code'] = $orderCurrencyCode;
            $order_array['currency_symbol'] = $orderCurrencySymbol;

            if ($order->payment_type != 'POS') {
                $OrderBillingDetail = OrderBillingDetail::where('order_id', $order_id)->first();
                $bi_f_name = !empty($OrderBillingDetail->first_name) ? $OrderBillingDetail->first_name : '';
                $bi_l_name = !empty($OrderBillingDetail->last_name) ? $OrderBillingDetail->last_name : '';
                $order_array['billing_informations']['name'] = $bi_f_name . ' ' . $bi_l_name;
                $order_array['billing_informations']['address'] = !empty($OrderBillingDetail->address) ? $OrderBillingDetail->address : '';
                $order_array['billing_informations']['state'] = !empty($OrderBillingDetail->BillingState->name) ? $OrderBillingDetail->BillingState->name : '';
                $order_array['billing_informations']['country'] = !empty($OrderBillingDetail->BillingCountry->name) ? $OrderBillingDetail->BillingCountry->name : '';
                $order_array['billing_informations']['city'] = !empty($OrderBillingDetail->BillingCity->name) ? $OrderBillingDetail->BillingCity->name : '';
                $order_array['billing_informations']['post_code'] = !empty($OrderBillingDetail->postcode) ? $OrderBillingDetail->postcode : '';
                $order_array['billing_informations']['email'] = !empty($OrderBillingDetail->email) ? $OrderBillingDetail->email : '';
                $order_array['billing_informations']['phone'] = !empty($OrderBillingDetail->telephone) ? $OrderBillingDetail->telephone : '';

                $order_array['delivery_informations']['name'] = $bi_f_name . ' ' . $bi_l_name;
                $order_array['delivery_informations']['address'] = !empty($OrderBillingDetail->delivery_address) ? $OrderBillingDetail->delivery_address : '';
                $order_array['delivery_informations']['state'] = !empty($OrderBillingDetail->DeliveryState->name) ? $OrderBillingDetail->DeliveryState->name : '';
                $order_array['delivery_informations']['country'] = !empty($OrderBillingDetail->DeliveryCountry->name) ? $OrderBillingDetail->DeliveryCountry->name : '';
                $order_array['delivery_informations']['city'] = !empty($OrderBillingDetail->DeliveryCity->name) ? $OrderBillingDetail->DeliveryCity->name : '';
                $order_array['delivery_informations']['post_code'] = !empty($OrderBillingDetail->delivery_postcode) ? $OrderBillingDetail->delivery_postcode : '';
                $order_array['delivery_informations']['email'] = !empty($OrderBillingDetail->email) ? $OrderBillingDetail->email : '';
                $order_array['delivery_informations']['phone'] = !empty($OrderBillingDetail->telephone) ? $OrderBillingDetail->telephone : '';

            } else {
                $customer = Customer::where('id', $order->customer_id)->first();
                if ($customer) {
                    $customerAddress = DeliveryAddress::where('customer_id', $customer->id)->first();
                }
                if (!$customer) {
                    $order_array['billing_informations']['name'] = __('Walk-in Customer');
                    $order_array['delivery_informations']['name'] = __('Walk-in Customer');
                } else {
                    $order_array['billing_informations']['name'] = $customer->first_name . ' ' . $customer->last_name;
                    $order_array['delivery_informations']['name'] = $customer->first_name . ' ' . $customer->last_name;
                }

                $order_array['billing_informations']['address'] = !empty($customerAddress) ? $customerAddress->address : '-';
                $order_array['billing_informations']['state'] = !empty($customerAddress->state_name) ? $customerAddress->state_name : '-';
                $order_array['billing_informations']['country'] = !empty($customerAddress->country_name) ? $customerAddress->country_name : '';
                $order_array['billing_informations']['city'] = !empty($customerAddress->city_name) ? $customerAddress->city_name : '-';
                $order_array['billing_informations']['post_code'] = !empty($customerAddress->postcode) ? $customerAddress->postcode : '-';
                $order_array['billing_informations']['email'] = !empty($customer->email) ? $customer->email : '-';
                $order_array['billing_informations']['phone'] = !empty($customer->mobile) ? $customer->mobile : '-';

                $order_array['delivery_informations']['address'] = !empty($customerAddress) ? $customerAddress->address : '-';
                $order_array['delivery_informations']['state'] = !empty($customerAddress->state_name) ? $customerAddress->state_name : '-';
                $order_array['delivery_informations']['country'] = !empty($customerAddress->country_name) ? $customerAddress->country_name : '';
                $order_array['delivery_informations']['city'] = !empty($customerAddress->city_name) ? $customerAddress->city_name : '-';
                $order_array['delivery_informations']['post_code'] = !empty($customerAddress->postcode) ? $customerAddress->postcode : '-';
                $order_array['delivery_informations']['email'] = !empty($customer->email) ? $customer->email : '-';
                $order_array['delivery_informations']['phone'] = !empty($customer->mobile) ? $customer->mobile : '-';
            }
            
            $payment_data = Utility::payment_data($order->payment_type, $store_id);
            $order_array['paymnet_type'] = $payment_data['name'];
            $order_array['paymnet'] = !empty($payment_data['image']) ? $payment_data['image'] : Storage::url('uploads/payment/cod.png');
            $order_array['delivery'] = !empty($order->delivered_image) ? $order->delivered_image  : Storage::url('uploads/delivery.png');
            $order_array['delivered_charge'] = SetNumber($order->delivery_price);
            
            // Coupon
            $OrderCouponDetail = OrderCouponDetail::where('order_id', $order_id)->first();
            $order_array['coupon_info'] = null;
            if (!empty($OrderCouponDetail)) {
                $discount_string = '-' . $OrderCouponDetail->coupon_discount_amount;
                $CURRENCY = Utility::GetValueByName('CURRENCY', $store_id);
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', $store_id);

                $discount = '-' . $OrderCouponDetail->coupon_discount_amount;
                $discount_string2 = '( ' . $discount . ' ' . $CURRENCY_NAME . ')';
                if ($OrderCouponDetail->coupon_discount_type == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                    $dis_amt = $OrderCouponDetail->coupon_final_amount * $OrderCouponDetail->coupon_discount_amount / 100;
                    $discount_string2 = '( -' . $dis_amt . ' ' . $CURRENCY_NAME . ')';
                }
                $discount_string .= ' ' . __('for all products');
                $order_array['coupon_info']['status'] = true;
                $order_array['coupon_info']['message'] = "Coupon is valid.";
                $order_array['coupon_info']['code'] = $OrderCouponDetail->coupon_code;
                $order_array['coupon_info']['discount_string'] = $discount_string;
                $order_array['coupon_info']['price'] = SetNumber($OrderCouponDetail->coupon_final_amount);
                $order_array['coupon_info']['discount_string2'] = $discount_string2;
                $order_array['coupon_info']['discount_amount'] = SetNumber($OrderCouponDetail->coupon_discount_amount);
                $order_array['coupon_info']['discount'] = SetNumber($discount);
            }

            // Tax
            $OrderTaxDetail = OrderTaxDetail::where('order_id', $order_id)->get();
            $tax_price = 0;
            if (!empty($OrderTaxDetail)) {
                foreach ($OrderTaxDetail as $tax_key => $value) {
                    $tax = Tax::find($value->tax_id);
                    $order_array['tax'][$tax_key]['tax_string']  = $value->tax_string;
                    $tax_price = $value->tax_final_amount;
                }
                $order_array['tax']['amountstring']  = $tax_price;
            }
            $tax_rate= 0;
            if(isset($tax)) {
                if($tax->tax_methods()){
                    foreach(json_decode($tax->tax_methods()) as $method){
                        $tax_rate += $method->tax_rate;
                    }
                }
            }
$order_array['sub_total_formatted']   = order_currency_format_with_sym($order->product_price, $order->id);
$order_array['final_price_formatted'] = order_currency_format_with_sym($order->final_price, $order->id);
$order_array['tax_price_formatted']   = order_currency_format_with_sym($order->tax_price, $order->id);
            // ✅ التعديل 3: إنشاء نسخ منسقة من الأسعار تحتوي على رمز العملة الصحيح للطلب
            // هذا يسهل عرض السعر في الـ Blade دون الاعتماد على دالة currency_format_with_sym العامة
            $order_array['sub_total'] = SetNumber($order->product_price);

            $order_array['final_price'] = SetNumber($order->final_price);

            $order_array['tax_price'] = SetNumber($order->tax_price);

            $order_array['tax_name'] = !empty($tax->name) ? $tax->name : 'Tax';
            $order_array['tax_rate'] = !empty($tax_rate) ? $tax_rate : 0;
            $order_array['tax_type'] = 'Percentage';
            $order_array['tax_id'] = !empty($tax->id) ? $tax->id : 0;
            
            return $order_array;
        } else {
            $return['message'] = 'Order not found.';
            return $return;
        }
    }
    public static function order_status_change($date = [])
    {
        $order_id = $date['order_id'];
        $order_status = $date['order_status'];
        $order = Order::find($order_id);
        if (!empty($order)) {
if ($order_status == 'cancel') {
    if ($order->delivered_status != 0) {
        $return['status'] = 'error';
        $return['message'] = 'The order has been delivered. So you cannot cancel the order.';
        return $return;
    }
    
    $order->cancel_date = now();
    $order->delivered_status = 2;
    
    // ✅ هذا هو السطر السحري المفقود: يجب تغيير حالة الدفع لاسترداد المبلغ
    $order->payment_status = 'Unpaid'; 
    
    $order->save(); // <-- هنا سيكتشف الـ Observer التغيير ويستدعي خدمة المحفظة
    
    event(new OrderPaymentStatus($order));

    $return['status'] = 'success';
    $return['message'] = 'Order status changed.';
    return $return;
}            if ($order_status == 'confirmed') {
                $order->confirmed_date = now();
                $order->delivered_status = 4;
                $order->save();
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }
            if ($order_status == 'pickedup') {


                $order->picked_date = now();
                $order->delivered_status = 5;
                $order->save();
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }

            if ($order_status == 'shipped') {


                $order->shipped_date = now();
                $order->delivered_status = 6;
                $order->save();
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }
            if ($order_status == 'return') {
                if ($order->delivered_status == 0) {
                    $return['status'] = 'error';
                    $return['message'] = 'Your order has not been delivered. You can return the order after delivery.';
                    return $return;
                }

                $order->return_date = now();
                $order->delivered_status = 3;
                $order->return_status = 1;
                $order->save();
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }

            if ($order_status ==  'delivered') {
                $order->delivery_date = now();
                $order->delivered_status = 1;
                $order->save();

                /*
                 * NO PAYMENT EVENT FROM A DELIVERY TRANSITION. Owner decision, 2026-10-24:
                 * "Keep order delivery reporting separate from cash collection and financial
                 * settlement reporting."
                 *
                 * This branch used to fire `event(new OrderPaymentStatus($order))`. It changes
                 * `delivery_date` and `delivered_status` and NOTHING about payment, so a payment
                 * event here announced a payment change that had not happened.
                 *
                 * IT CREDITED NOTHING TODAY -- EventServiceProvider registers
                 * `OrderPaymentStatus::class => []`, deliberately with no listeners -- which is
                 * exactly why it is removed now rather than left. A dead coupling that becomes
                 * live the moment somebody adds a listener is the same shape as the GATE F
                 * financial subsystem: harmless until switched on, and then wrong.
                 *
                 * The CANCEL branch keeps its event, and should: that one really does write
                 * `payment_status = 'Unpaid'`.
                 */

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }
            if (module_is_active('PartialPayments')) {
                $return = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageOrderStatus($date);
                return $return;
            }
            if ($order_status == 'pre_order' && module_is_active('PreOrder')) {
                $return = \Workdo\PreOrder\app\Models\PreOrder::ManageOrderStatus($date);
                return $return;
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = 'Order not found.';
            return $return;
        }
    }

    /**
     * The per-line marker that says "this line's goods have already been counted back in".
     *
     * ONE RETURN, ONE STOCK CREDIT -- OWNER DECISION 5: "Restock only when a return is actually
     * accepted. Prevent duplicate stock credits and duplicate refunds. Keep physical inventory
     * movements separate from financial refund settlement."
     *
     * THE DOUBLE CREDIT IT CLOSES, P1-INV-15 (E). Two endpoints can put a returned line's goods
     * back, and neither knew about the other:
     *
     *   Order::product_return()                      the merchant marks ONE LINE returned
     *   OrderController::restoreReturnedStock()      approval of a return request, EVERY LINE
     *
     * The second loops every line with no reference to the line's own return flag, so the
     * two-step merchant workflow -- mark the line, then approve the request -- credited that
     * line's stock TWICE. Each endpoint had its own replay guard and both were correct about
     * their own endpoint; neither could see the other.
     *
     * WHY A MARKER ON THE LINE RATHER THAN A FLAG ON THE ORDER. The unit that can be
     * double-credited is the LINE, not the order: a four-line order can have one line marked
     * returned and then the whole request approved. An order-level flag cannot express "lines 1
     * and 3 are already back in stock, lines 2 and 4 are not", and that is exactly the state the
     * two-step workflow produces. It is written into `product_json`, which both endpoints already
     * read and write inside their own transaction, so it is durable and needs no new column --
     * and a new column on `orders` would be a migration against live data (D15).
     *
     * PHYSICAL, NOT FINANCIAL. This records that goods moved. It says nothing about whether money
     * was returned, which is the separation the owner's decision asks for: the refund side is
     * settled through OrderRefund and `return_price`, and must not be inferred from this.
     */
    public const LINE_STOCK_RESTORED = 'stock_restored';

    /**
     * Has this line's stock already been credited by either endpoint?
     *
     * @param  array<string, mixed>  $line
     */
    public static function lineStockAlreadyRestored(array $line): bool
    {
        return (int) ($line[self::LINE_STOCK_RESTORED] ?? 0) === 1;
    }

    /**
     * Can this return workflow read these lines at all?
     *
     * The test is for the two keys the restock needs -- `product_id` and a quantity -- on every
     * line. A POS order's lines carry neither (they are `{name, quantity, ...}` with no
     * `product_id`), so a POS order fails here and is refused with a message instead of raising
     * an undefined-array-key 500 half way through the loop, after some lines have already been
     * restocked.
     *
     * `variant_id` is NOT required: it is legitimately absent or 0 on a non-variant line, and the
     * restock branches on exactly that.
     *
     * @param  array<int, mixed>  $lines
     */
    public static function linesAreReturnable(array $lines): bool
    {
        foreach ($lines as $line) {
            if (! is_array($line)) {
                return false;
            }

            if (! array_key_exists('product_id', $line) || ! array_key_exists('qty', $line)) {
                return false;
            }
        }

        return true;
    }

    public static function product_return($date = [])
    {
        $product_id = $date['product_id'];
        $variant_id = $date['variant_id'];
        $order_id   = $date['order_id'];

        $Order = Order::find($order_id);

        /*
         * NULL GUARD. P1-INV-02. `$Order->delivered_status` on a missing id raised "Attempt to
         * read property on null" -- a 500 on bad input. The callers also check, but this method
         * is static and reachable from anywhere, so it refuses on its own account.
         */
        if (empty($Order)) {
            return [
                'status' => 'error',
                'message' => __('Something was wrong.'),
            ];
        }

        if ($Order->delivered_status != 1) {
            $return['status'] = 'error';
            $return['message'] = __('The product has not been delivered. Therefore you cannot return the product.');
            return $return;
        }

        /*
         * A LINE LIST THIS METHOD CAN READ, OR A REFUSAL -- NOT A 500.  P1-INV-15 (A) and (B),
         * under OWNER DECISION 5: "Implement a reliable, tenant-safe, transaction-safe return
         * workflow ... Preserve correct tax and POS handling."
         *
         * TWO SHAPES REACH HERE THAT THIS METHOD CANNOT READ.
         *
         * (B) A NULL `product_json`. P1-INV-02's null guard covers the missing ORDER, not the
         *     missing json, and `json_encode($product_json)` below with `$product_json` never
         *     assigned is an undefined variable -- an ErrorException, i.e. a 500.
         *
         * (A) A POS ORDER. POS writes a DIFFERENT SHAPE entirely --
         *     `{"data":[{name, quantity, ...}], ...}` (PosController:120-139, :231) -- with no
         *     `product_id` and no `qty` on its lines. The loop below reads
         *     `$product['product_id']` unconditionally, so a POS order is an undefined array key.
         *
         * REFUSED RATHER THAN HANDLED, and that is the narrow choice on purpose. Teaching this
         * method to restock a POS line would mean deciding what a POS return IS -- which register
         * it credits, whether it touches the same stock, what it does to a cash drawer -- and
         * none of that is settled. An honest refusal leaves the merchant able to see the order
         * and unable to half-return it; a 500 leaves them with neither and no message.
         *
         * The shape test is the LINE LIST, not the payment type: it asks whether this method can
         * read what it is about to loop over, which is the actual precondition, and it therefore
         * also catches any future writer that invents a third shape.
         */
        $lines = json_decode((string) $Order->product_json, true);

        if (! is_array($lines) || $lines === []) {
            \Illuminate\Support\Facades\Log::warning('Order return refused: the order carries no readable product_json', [
                'order_id' => $Order->id,
            ]);

            return [
                'status' => 'error',
                'message' => __('This order cannot be returned.'),
            ];
        }

        if (! self::linesAreReturnable($lines)) {
            \Illuminate\Support\Facades\Log::warning('Order return refused: the order lines are not in a shape this workflow can return', [
                'order_id' => $Order->id,
                'payment_type' => $Order->payment_type,
            ]);

            return [
                'status' => 'error',
                'message' => __('This order cannot be returned from here.'),
            ];
        }


        /*
         * REPLAY GUARD, AND IT REFUSES THE WHOLE CALL RATHER THAN SKIPPING THE RESTOCK.
         * P1-INV-02, raised by adversarial review as PRE-EXISTING AND LIVE.
         *
         * Nothing checked whether the named line was ALREADY returned, so each repeat call
         * credited the stock again AND subtracted `return_price` from `final_price` again.
         * Reachable by a CUSTOMER on their own delivered order: routes/api.php:110 ->
         * ApiController::product_return -> resolveViewableOrder admits the owning customer. No
         * cash leaves (OrderObserver::updated returns early unless payment_status changed), so
         * this is integrity and reporting rather than payout -- but a shopper inflating a
         * merchant's inventory without limit is not acceptable either.
         *
         * MY FIRST ATTEMPT AT THIS WAS A `continue` INSIDE THE LOOP, AND IT WAS NOT ENOUGH.
         * Measured: the stock then held at 59 across a replay, but `final_price` still went
         * 10.00 -> -80.00, because the second pass (:770-790) recomputes the total from the
         * CURRENT `final_price` rather than from an immutable base, so the money half compounds
         * independently of the stock half. Refusing the whole call is the only form that makes
         * both halves idempotent, and it is also the honest answer: a line that is already
         * returned has nothing to do.
         *
         * The sibling endpoint closed the same primitive with `$previousReturnStatus`
         * (OrderController.php:750-751). The two ends of the workflow are now consistent on
         * replay as well as on authorization.
         */
        if (!empty($Order->product_json)) {
            foreach ((array) json_decode($Order->product_json, true) as $line) {
                if (($line['product_id'] ?? null) == $product_id
                    && ($line['variant_id'] ?? null) == $variant_id
                    && (int) ($line['return'] ?? 0) === 1) {
                    return [
                        'status' => 'error',
                        'message' => __('The product has already been returned.'),
                    ];
                }
            }
        }

        if (!empty($Order->product_json)) {
            $product_json = json_decode($Order->product_json, true);
            foreach ($product_json as $key => $product) {
                if ($product['product_id'] == $product_id && $product['variant_id'] == $variant_id) {
                    $product_json[$key]['return'] = 1;

                    /*
                     * THE RESTOCKED ROW IS PAIRED TO THE ORDER'S STORE. P1-INV-02, acceptance
                     * criterion 2.
                     *
                     * `$product_id` and `$variant_id` arrive as REQUEST INPUT, and neither
                     * `Product` nor `ProductVariant` is in config/tenancy.php's `scoped_models`,
                     * so both lookups reached every tenant. The caller's ownership of the ORDER
                     * is now checked by OrderController::order_return, but that cannot see this:
                     * an order in the caller's own store whose line names another store's
                     * product would still have restocked that product. Measured as its own
                     * case in OrderReturnOwnershipTest.
                     *
                     * SCOPED TO THE ORDER'S STORE, NOT THE CALLER'S. The order is the authority
                     * on which inventory its lines belong to, and this method is static and
                     * reachable from contexts where getCurrentStore() resolves to nothing.
                     *
                     * AN ORDER WITH NO store_id RESTOCKS NOTHING, and that is deliberate rather
                     * than overlooked: `orders.store_id` is nullable and P1-PLT-02 (#34) records
                     * two writers that persist it as NULL, so such rows exist. An order that
                     * does not say which store it belongs to cannot say whose inventory to
                     * credit, and guessing from the caller is what this fix exists to stop. The
                     * refusal is logged so it is visible rather than silent, and #34 is what
                     * makes these orders returnable again.
                     */
                    $orderStoreId = $Order->store_id;

                    if (empty($orderStoreId)) {
                        /*
                         * REFUSE, DO NOT MERELY LOG. Raised by review: the platform operator
                         * bypasses sameStore, so a NULL-store order DOES reach this method for
                         * them -- and logging-and-continuing let the MONEY half proceed (the line
                         * marked returned, `final_price` reduced at :765-770) while the INVENTORY
                         * half did nothing. A half-applied return is worse than a refused one.
                         */
                        \Illuminate\Support\Facades\Log::warning(
                            'order return refused: the order carries no store_id',
                            ['order_id' => $Order->id, 'product_id' => $product_id]
                        );

                        return [
                            'status' => 'error',
                            'message' => __('Something was wrong.'),
                        ];
                    } elseif ($variant_id == 0 || empty($variant_id)) {
                        /*
                         * `$line` rather than reassigning `$product`. The original wrote
                         * `$product = Product::find(...)` and then read `$product['qty']` -- by
                         * which point `$product` was the MODEL, so `qty` resolved as an
                         * attribute and `products` has no such column. The non-variant branch
                         * therefore added null and restocked NOTHING, which is why the measured
                         * cross-tenant reproduction moved a VARIANT's stock rather than a
                         * product's. Keeping the shadowing would leave this branch broken; the
                         * behaviour change it implies for legitimate non-variant returns is
                         * recorded as its own row rather than smuggled in here, so this branch
                         * reads the quantity from the line and is otherwise unchanged.
                         */
                        $line = $product_json[$key];
                        $stockProduct = Product::where('id', $product_id)
                            ->where('store_id', $orderStoreId)
                            ->first();

                        /*
                         * P1-INV-05, criterion 1: every stock mutation goes through the atomic
                         * primitive. This was a READ-MODIFY-WRITE --
                         * `$stockProduct->product_stock += $line['qty']; ->save()` -- which reads
                         * the row into PHP, adds there, and writes the whole value back. Two
                         * returns processed concurrently both read the same figure and the second
                         * overwrites the first, so one return's units are lost.
                         *
                         * `App\Services\ProductStockService::restoreProductStock()` issues
                         * `UPDATE ... SET product_stock = product_stock + ?`, where the arithmetic
                         * happens in the database and a stale PHP-side value cannot be written
                         * back. It also flushes the model cache, which the bare `save()` above did
                         * only for the instance it held.
                         *
                         * A CENSUS BOUNDS THE CLAIM: ten restock call sites in app/ already go
                         * through this service (PaymentService, AppliesCartStockChanges,
                         * OrderController x4, OrderRefundController x2). These two in
                         * product_return() were the only ones that did not.
                         *
                         * MEASURED ON THE REAL ENGINE, because SQLite serialises writers and
                         * cannot stage the interleave: section F of
                         * tests/Support/Security/bin/verify-stock-oversell-concurrency-on-mysql.php.
                         */
                        // ONE RETURN, ONE STOCK CREDIT. The marker is stamped on the line in the
                        // same `$product_json` this method is about to save, so the sibling
                        // endpoint's loop can see that these goods are already back in. See
                        // Order::LINE_STOCK_RESTORED. P1-INV-15 (E), owner decision 5.
                        if (!empty($stockProduct) && ! self::lineStockAlreadyRestored($product_json[$key])) {
                            app(\App\Services\ProductStockService::class)
                                ->restoreProductStock($stockProduct->id, (int) $line['qty']);
                            $product_json[$key][self::LINE_STOCK_RESTORED] = 1;
                        }
                    } else {
                        /*
                         * ANCHORED ON THE PRODUCT'S STORE, NOT THE VARIANT'S. Raised by review:
                         * `product_variants.store_id` is NULLABLE, `ProductVariant` has no
                         * auto-populating trait, and the `choice_no` branch of
                         * ProductController::update (:1505-1519) creates a variant without
                         * setting it -- so keying on the variant's own column would silently
                         * restock nothing for a GENUINE own-store return of such a variant.
                         * (That branch is not reachable from the shipped UI, which is why this
                         * is hardening rather than a defect, but `products.store_id` is
                         * populated by EVERY product writer and the variant is tied to it by
                         * `product_id` -- strictly more robust, same number of lines, and it is
                         * the shape the sibling `restoreReturnedStock` already uses.)
                         */
                        $owningProduct = Product::where('id', $product_id)
                            ->where('store_id', $orderStoreId)
                            ->first();

                        $ProductStock = $owningProduct === null
                            ? null
                            : ProductVariant::where('product_id', $owningProduct->id)
                                ->where('id', $variant_id)
                                ->first();
                        // P1-INV-05: the variant twin of the restock above, same read-modify-write
                        // and same remedy. See that comment for the census and the MySQL evidence.
                        // The variant twin of the marker above, same reason.
                        if (!empty($ProductStock) && ! self::lineStockAlreadyRestored($product_json[$key])) {
                            app(\App\Services\ProductStockService::class)
                                ->restoreVariantStock($ProductStock->id, (int) $product['qty']);
                            $product_json[$key][self::LINE_STOCK_RESTORED] = 1;
                        }
                    }
                }
            }
        }
        $Order->product_json = json_encode($product_json);
        $Order->save();

        $qty = 0;
        $return_price = 0;
        $rp = [];
        if (!empty($Order->product_json)) {
            $product_json = json_decode($Order->product_json, true);
            foreach ($product_json as $key => $product) {

                $qty += $product['qty'];
                if ($product['return'] == 1) {
                    $rp[] = $product['final_price'];
                    $return_price += $product['final_price'];
                }
            }
        }
        /*
         * TAX IS LEFT ALONE, NOT ZEROED.  P1-INV-15 (C), under OWNER DECISION 5's "Preserve
         * correct tax and POS handling."
         *
         * This used to be `$tax_price = 0;` followed, 10 lines below, by
         * `$Order->tax_price = SetNumber($tax_price);` -- with `$tax_price` never assigned
         * anything else. So EVERY return wrote 0.00 into the order's tax column, whatever tax the
         * order had actually carried. A money column, silently destroyed by an inventory action.
         *
         * NOT RECOMPUTED, AND THAT IS THE NARROW CHOICE. Working out the tax on a partially
         * returned order means deciding how a per-order tax rate, a shipping tax component and a
         * coupon that moved the taxable base all apportion across the returned lines. Those are
         * OrderTaxDetail's question and P1-ORD-14's, not this method's, and a guess written into
         * a money column is worse than an unchanged value that is at least the truth about what
         * was charged. The column is simply no longer touched here, which is the owner's "left
         * untouched; not silently zeroed".
         *
         * `$tax_price_ind` was dead with it -- assigned once, read nowhere -- and goes too.
         */
        $coupon_price = 0;
        $order_coupon_detail = OrderCouponDetail::where('order_id', $order_id)->first();
        if (!empty($order_coupon_detail)) {
            $coupon_price = $order_coupon_detail->coupon_discount_number;
            if ($order_coupon_detail->coupon_discount_type == 'percentage') {
                $coupon_price = $return_price * $order_coupon_detail->coupon_discount_number / 100;
            }
        }
        $return_price -= $coupon_price;
        $final_price = ($Order->final_price - $return_price);
        /*
         * ONE TRANSACTION AROUND THE WRITES.  P1-INV-15 (D), owner decision 5's "transaction-safe".
         *
         * The restock above and these two saves were separate autocommitted writes, while the
         * SIBLING endpoint has wrapped exactly this pair since P1-INV-02 (OrderController:950).
         * A throw between them left the goods back in stock with the order never recording the
         * return -- and because the replay guard at the top reads the line's own `return` flag
         * from `product_json`, the next attempt then credited the same goods again.
         *
         * `tax_price` is NOT in this write list any more. See the note above.
         */
        \Illuminate\Support\Facades\DB::transaction(function () use ($Order, $product_json, $return_price, $final_price) {
            $Order->product_json = json_encode($product_json);
            $Order->return_price = SetNumber($return_price);
            $Order->final_price = SetNumber($final_price);
            $Order->save();
        });

        $return['status'] = 'success';
        $return['message'] = 'Product return successfully.';
        return $return;
    }

    public static function payment_status()
    {
        $payment_status = [
            'Paid' => 'Paid',
            'Unpaid' => 'Unpaid',
        ];
        return $payment_status;
    }

    public function DeliveryBoy()
    {
        return $this->hasOne(
            DeliveryBoy::class,
            'id',
            'deliveryboy_id'
        );
    }

    public function CustomerData()
    {
        return $this->hasOne(Customer::class, 'id', 'customer_id');
    }

    public static function OrderActionButton($slug, $order)
    {
        return view('front_end.hooks.order_action_button', compact('order', 'slug'))->render();
    }

    public function getProductsData()
    {
        $productIds = explode(',', $this->product_id);

        $products = Product::whereIn('id', $productIds)->get();

        return $products->toArray();
    }

    public function refund()
    {
        return $this->hasOne(OrderRefund::class, 'order_id', 'id');
    }

    public function billingData()
    {
        return $this->hasOne(OrderBillingDetail::class, 'order_id', 'id');
    }


public function ShippingMethod()
{
    return $this->belongsTo(
        ShippingMethod::class,
        'delivery_id',
        'id'
    );
}
}