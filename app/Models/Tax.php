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

    /**
     * THE METHODS OF THIS TAX GROUP, SCOPED TO THE GROUP'S OWN STORE.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * F263 FOLLOW-UP — THIS SELECTED BY `tax_id` ALONE, AND IT IS A MONEY READ
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * `TaxMethod` is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no
     * predicate for any principal, and this method added none either. Its result is summed as
     * `tax_rate * price / 100` by `Product::ProductPrice()` (`:429`) and by this class's own
     * `TaxCount()` (`:77`, `:84`, `:99`), and read again by `Order.php:605` and
     * `Api/ApiController.php:7390` — so a `tax_methods` row belonging to ANOTHER store changed what
     * this store's shoppers were charged.
     *
     * The injection door was `TaxMethodController::store()`, which took `tax_id` raw from the
     * request and saved the row under the CALLER's `store_id`; it is closed in that method. **This
     * is the other half, and it is the half that matters for rows already written** — closing only
     * the writer would leave any injected row charging the victim's shoppers for ever.
     *
     * **IT IS ALSO WHY `priority` MADE IT WORSE.** The column is a STRING with default `'0'`
     * (`2023_12_25_040519_create_tax_methods_table.php:22`), so `orderBy('priority', 'ASC')` is a
     * lexicographic sort: `''` sorts before `'0'`. A row posted with an empty priority therefore
     * became the top priority, and the second query — which matches that priority exactly —
     * returned the foreign row and EXCLUDED the store's own methods entirely. Both queries are
     * scoped here, so the tie-break is now decided among this store's rows only.
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     * THE PREDICATE IS THE GROUP'S STORE, NOT `getCurrentStore()`, and that is deliberate
     * ═════════════════════════════════════════════════════════════════════════════════════════════
     *
     * `taxes` carries its own `store_id` (`$fillable` above), and this is a MODEL method reached
     * from the storefront, from the API and from the merchant UI alike — `getCurrentStore()` means
     * something different in each, and in the storefront it means nothing at all. The group's own
     * store is the one fact that is true in every caller, and a method whose store differs from its
     * group's is wrong whoever is asking.
     *
     * **A NULL `store_id` IS STILL ACCEPTED**, the same allowance `CartController::get_shipping_method()`
     * records for shipping methods: the column is nullable, legacy rows may not carry it, and
     * refusing those would stop a merchant's OWN tax being charged — a silent revenue change, which
     * is worse than the defect. What is refused is a method belonging to a DIFFERENT store.
     *
     * **WHAT THIS CHANGES ABOUT COMPUTED PRICES, stated plainly rather than buried:** a price moves
     * only where it was being computed from another store's row, which is the defect itself. No tax
     * rule, rounding mode, inclusive/exclusive setting or stored amount is touched, and no
     * legitimate row is dropped.
     */
    public function tax_methods() {
        $storeId = $this->store_id;

        $scoped = fn ($query) => $query
            ->where('tax_id', $this->id)
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            });

        $topPrority = $scoped(TaxMethod::query())->orderBy('priority', 'ASC')->first();

        if ($topPrority) {
            return $scoped(TaxMethod::query())->where('priority', $topPrority->priority)->get();
        } else {
            return [];
        }
    }
}
