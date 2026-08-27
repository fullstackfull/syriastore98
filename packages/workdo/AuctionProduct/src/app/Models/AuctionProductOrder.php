<?php

namespace Workdo\AuctionProduct\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Events\OrderPaymentStatus;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\OrderCouponDetail;
use App\Models\OrderTaxDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Tax;
use App\Models\Utility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AuctionProductOrder extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [];

    public static function order_detail($order_id = 0)
    {
        $order = AuctionProductOrder::find($order_id);

        if (!empty($order)) {
            $store_id = !empty($order) ? $order->store_id : getCurrentStore();

            $order_array = [];
            $products = json_decode($order->product_json, true);

            if (!empty($products)) {
                $products = [
                    "product_id" => $products['id'],
                    "image" => $products["cover_image_path"],
                    "name" => $products["name"],
                    "orignal_price" => $products["price"],
                    "total_orignal_price" => SetNumber($products["price"]),
                    "final_price" => SetNumber($products["price"]),
                    "qty" => $products["qty"] ?? 1,
                    "variant_id" => $products["variant_id"] ?? '',
                    "variant_name" => $products["variant_name"] ?? '',
                    "return" => $products["return"] ?? '',
                    ];
            }

            $order_array['id'] = $order->id;
            $order_array['order_id'] = '#' . $order->product_order_id;
            $order_array['delivery_date'] = $order->delivery_date ?? '';
            $order_array['order_reward_point'] = $order->reward_points;
            $order_array['return_price'] = $order->return_price ?? '';
            $order_array['return_status_message'] = $order->return_status_message ?? '';
            $order_array['return_date'] = $order->return_date ?? '';
            $order_array['order_status_text'] = __('Pending');

            $order_array['order_status'] = $order->delivered_status;
            $order_array['order_status_message'] = '';
            $order_array['payment_status'] = $order->payment_status;
            $order_array['deliveryboy_id'] = $order->deliveryboy_id;

            if ($order->delivered_status == 0) {
                $order_array['order_status_text'] = __('Pending');
            }
            if ($order->delivered_status == 4) {
                $order_array['order_status_text'] = __('Comfirmed');
            }
            if ($order->delivered_status == 5) {
                $order_array['order_status_text'] = __('Picked Up');
            }
            if ($order->delivered_status == 6) {
                $order_array['order_status_text'] = __('Shipped');
            }
            if ($order->delivered_status == 1) {
                $order_array['order_status_text'] = __('Delivered');
            }
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

            $order_array['product'] = $products;
            $order_array['is_review'] = 0;
            $customer = Customer::find($order->customer_id);

            if($customer)
            {
                $DeliveryAddress = DeliveryAddress::where( 'customer_id', $customer->id)->first();

                $bi_f_name = !empty($customer->first_name) ? $customer->first_name : '-';
                $bi_l_name = !empty($customer->last_name) ? $customer->last_name : '-';
                $order_array['billing_informations']['name'] = $bi_f_name . ' ' . $bi_l_name;
                $order_array['billing_informations']['address'] = !empty($DeliveryAddress->address) ? $DeliveryAddress->address : '-';
                $order_array['billing_informations']['state'] = !empty($DeliveryAddress) ? $DeliveryAddress->getStateNameAttribute() : '-';
                $order_array['billing_informations']['country'] = !empty($DeliveryAddress) ? $DeliveryAddress->getCountryNameAttribute() : '-';
                $order_array['billing_informations']['city'] = !empty($DeliveryAddress) ? $DeliveryAddress->getCityNameAttribute() : '-';
                $order_array['billing_informations']['post_code'] = !empty($DeliveryAddress->postcode) ? $DeliveryAddress->postcode : '-';
                $order_array['billing_informations']['email'] = !empty($customer->email) ? $customer->email : '-';
                $order_array['billing_informations']['phone'] = !empty($customer->mobile) ? $customer->mobile : '-';

                $order_array['delivery_informations']['name'] = $bi_f_name . ' ' . $bi_l_name;
                $order_array['delivery_informations']['address'] = !empty($DeliveryAddress->address) ? $DeliveryAddress->address : '-';
                $order_array['delivery_informations']['state'] = !empty($DeliveryAddress) ? $DeliveryAddress->getStateNameAttribute() : '-';
                $order_array['delivery_informations']['country'] = !empty($DeliveryAddress) ? $DeliveryAddress->getCountryNameAttribute() : '-';
                $order_array['delivery_informations']['city'] = !empty($DeliveryAddress) ? $DeliveryAddress->getCityNameAttribute() : '-';
                $order_array['delivery_informations']['post_code'] = !empty($DeliveryAddress->postcode) ? $DeliveryAddress->postcode : '-';
                $order_array['delivery_informations']['email'] = !empty($customer->email) ? $customer->email : '';
                $order_array['delivery_informations']['phone'] = !empty($customer->mobile) ? $customer->mobile : '';

            }
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

                $order_array['coupon_info']['discount_amount'] = SetNumber($OrderCouponDetail->coupon_discount_number);
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
            if(isset($tax))
            {
                if($tax->tax_methods()){
                    foreach(json_decode($tax->tax_methods()) as $method){
                        $tax_rate += $method->tax_rate;
                    }
                }
            }
            $order_array['sub_total'] = SetNumber($order->sub_total);
            $order_array['final_price'] = SetNumber($order->price);
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

        $order = AuctionProductOrder::find($order_id);
        if (!empty($order)) {
            if ($order_status == 'cancel') {
                if ($order->delivered_status != 0) {
                    $return['status'] = 'error';
                    $return['message'] = 'The order has been delivered. So you cannot cancel the order.';
                    return $return;
                }
                $order->cancel_date = now();
                $order->delivered_status = 2;
                $order->save();
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }
            if ($order_status == 'confirmed') {
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
                event(new OrderPaymentStatus($order));

                $return['status'] = 'success';
                $return['message'] = 'Order status changed.';
                return $return;
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = 'Order not found.';
            return $return;
        }
    }

    public static function product_return($date = [])
    {
        $product_id = $date['product_id'];
        $variant_id = $date['variant_id'];
        $order_id   = $date['order_id'];

        $Order = AuctionProductOrder::find($order_id);
        if ($Order->delivered_status != 1) {
            $return['status'] = 'error';
            $return['message'] = __('The product has not been delivered. Therefore you cannot return the product.');
            return $return;
        }


        if (!empty($Order->product_json)) {
            $product_json = json_decode($Order->product_json, true);
            foreach ($product_json as $key => $product) {
                if ($product['product_id'] == $product_id && $product['variant_id'] == $variant_id) {
                    $product_json[$key]['return'] = 1;

                    if ($variant_id == 0 || empty($variant_id)) {
                        $product = Product::find($product_id);
                        if (!empty($product)) {
                            $product->product_stock += $product['qty'];
                            $product->save();
                        }
                    } else {
                        $ProductStock = ProductVariant::where('product_id', $product_id)->where('id', $variant_id)->first();
                        if (!empty($ProductStock)) {
                            $ProductStock->stock += $product['qty'];
                            $ProductStock->save();
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
        $tax_price = 0;
        $tax_price_ind = 0;

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
        $Order->product_json = json_encode($product_json);
        $Order->return_price = SetNumber($return_price);
        $Order->tax_price = SetNumber($tax_price);
        $Order->final_price = SetNumber($final_price);
        $Order->save();

        $return['status'] = 'success';
        $return['message'] = 'Product return successfully.';
        return $return;
    }


    public static function calculateFlateShipping($shippingMethods, $amount, $city ,$state ,$country,$product) {
        $shipping_cost = 0;
        $productId = $product->id;
        $product_data = Product::find($productId);
        $store_id = !empty($product_data) ? $product_data->store_id : getCurrentStore();
        $country = !empty($country) ? $country : null;
        $state_id = !empty($state) ? $state : null;

        $Shipping_zone = ShippingZone::where('store_id', $store_id)->where('country_id', $country)->where('state_id', $state_id)->first();

        if (!empty($Shipping_zone)) {
            $methods = ShippingMethod::where('zone_id', $Shipping_zone->id)->where('method_name','Flat Rate')->where('store_id', $store_id)->first();
            $product_cost = json_decode($methods['product_cost'], true);
            $product_cost = $product_cost['product_cost'] ?? [];
            $product_cost_key = key($product_cost);
            $product_cost_value = current($product_cost);
            $shipping_cost = $methods['cost'];
            if($product_cost_key == $product_data->shipping_id)
            {
                $shipping_cost += $product_cost_value;
            }elseif(!empty($product_data->shipping_id))
            {
                $shipping_cost = $shipping_cost;
            }else{
                $shipping_cost = 0;
            }
        }

        return $shipping_cost;
    }
}
