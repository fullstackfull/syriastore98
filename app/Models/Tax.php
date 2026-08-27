<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Tax extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'name',  'store_id'
    ];

    public static function  Taxstatus(){
        $taxstatus =[
            'taxable' =>'Taxable',
            'shipping_only' =>'Shipping Only',
            'none' =>'None',

        ];

        return $taxstatus;
    }

    protected $appends = ["demo_field", "tax_string"];

    public function getDemoFieldAttribute()
    {
        return 'demo_field';
    }

    public function getTaxStringAttribute()
    {
        $type_percentage = ($this->tax_type == 'percentage') ? '%' : '';
        $type_flat = ($this->tax_type == 'flat') ? '-' : '';
        return $this->tax_name.' ('.$type_flat.$this->tax_amount.$type_percentage.')';
    }

    public static function TaxCount($data = [])
    {
        $store_id = !empty($data['store_id']) ? $data['store_id'] : 1;
        $product_tax_id = !empty($data['product_tax_id']) ? $data['product_tax_id'] : 0;
        $sub_total = !empty($data['sub_total']) ? $data['sub_total'] : 0;
        // $Tax = Tax::where('store_id',$store_id)->first();
        $Tax = Tax::find($product_tax_id);
        $tax_option = TaxOption::where('store_id', $store_id)->pluck('value', 'name')->toArray();
        $tax_price = 0;
        $tax_name = 0;
        $original_price = $sub_total;
        $cart_array = [];
        $cart_array['original_price'] = SetNumber(floatval($original_price));
        $cart_array['tax_info'] = [];
        // if($Tax && count($Tax->tax_methods()) > 0) {
        //     foreach ($Tax->tax_methods() as $key1 => $value1) {

        //         $amount = $sub_total * $value1->tax_rate / 100;
        //         $cart_array['tax_info'][$key1]["tax_name"] = $value1->name;
        //         $cart_array['tax_info'][$key1]["tax_rate"] = $value1->tax_rate;
        //         $cart_array['tax_info'][$key1]["tax_amount"] = $amount;
        //         $cart_array['tax_info'][$key1]["id"] = $value1->id;
        //         $cart_array['tax_info'][$key1]["tax_price"] = SetNumber($amount);
        //         $tax_price += $amount;
        //         $tax_name = $value1->name;
        //         $tax_rate = $value1->tax_rate;
        //     }
        // }
        if($Tax && count($Tax->tax_methods()) > 0)
        {
            if(isset($tax_option['price_type']) && isset($tax_option['shop_price']) && $tax_option['price_type'] == 'inclusive' && $tax_option['shop_price'] == 'including')
            {
                // For inclusive tax, calculate the tax amount that's already included in the price
                $total_tax_rate = 0;
                foreach ($Tax->tax_methods() as $mkey => $method) {
                    $total_tax_rate += $method->tax_rate;
                }
                
                // Calculate tax amount from inclusive price: tax = (price * tax_rate) / (100 + tax_rate)
                $tax_amount = ($sub_total * $total_tax_rate) / (100 + $total_tax_rate);
                
                foreach ($Tax->tax_methods() as $mkey => $method) {
                    // Proportional tax amount for each method
                    $amount = ($tax_amount * $method->tax_rate) / $total_tax_rate;
                    $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                    $cart_array['tax_info'][$mkey]["tax_rate"] = $method->tax_rate;
                    $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                    $cart_array['tax_info'][$mkey]["id"] = $method->id;
                    $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                    $tax_price += $amount;
                    $tax_name = $method->name;
                    $tax_rate = $method->tax_rate;
                }
            }
            else {
                // For exclusive tax, calculate normally
                foreach ($Tax->tax_methods() as $mkey => $method) {
                    $amount = $sub_total * $method->tax_rate / 100;
                    $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                    $cart_array['tax_info'][$mkey]["tax_rate"] = $method->tax_rate;
                    $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                    $cart_array['tax_info'][$mkey]["id"] = $method->id;
                    $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                    $tax_price += $amount;
                    $tax_name = $method->name;
                    $tax_rate = $method->tax_rate;
                }
            }
        }

        $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME', $store_id);
        $CURRENCY= Utility::GetValueByName('CURRENCY', $store_id);
        $cart_array['total_tax_price'] = SetNumber($tax_price);
        $total = $tax_price + $sub_total;
        $cart_array['final_price'] = SetNumber($total);
        $cart_array['currency_name'] = $CURRENCY_NAME ?? '';
        $cart_array['currency'] = $CURRENCY ?? '';
        $cart_array['tax_name'] = $tax_name ?? '';
        $cart_array['tax_rate'] = $tax_rate ?? '';
        $cart_array['tax_type'] = 'percentage';
        return $cart_array;


    }

    public function tax_methods() {
        $taxMethodQuery = TaxMethod::query();
        $topPrority = (clone $taxMethodQuery)->where('tax_id', $this->id)->orderBy('priority', 'ASC')->first();
        if ($topPrority) {
            return (clone $taxMethodQuery)->where('tax_id', $this->id)->where('priority', $topPrority->priority)->get();
        } else {
            return [];
        }
    }
}
