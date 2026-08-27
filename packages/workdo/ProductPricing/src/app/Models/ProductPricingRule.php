<?php

namespace Workdo\ProductPricing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\ProductPricing\Database\factories\ProductPricingRuleFactory;

class ProductPricingRule extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'pricing_method',
        'quantity_type',
        'field_json',
        'product_json',
        'product_get_json',
        'conditions',
        'rule_status',
        'created_by',
        'store_id',
        
    ];

    protected static function newFactory(): ProductPricingRuleFactory
    {
        //return ProductPricingRuleFactory::new();
    }

    public static $allRulesConditions = [
        'none'                      => 'None',
        'apply_all_rules'           => 'Apply all applicable rules',
        'apply_first_rule'          => 'Apply first applicable rule',
        // 'apply_smaller_price_rule'  => 'Apply rule for smaller price',
        // 'apply_bigger_price_rule'   => 'Apply rule for bigger price',
        'all_rules_disabled'        => 'All rules disabled',
    ];

    public static $ruleConditions = [
        'none'                                          => 'None',
        'apply_with_other_rules'                        => 'Apply with other applicable rules',
        'apply_this_rule_and_disregard_other_rules'     => 'Apply this rule and disregard other rules',
        // 'apply_if_other_rules_are_not'                  => 'Apply if other rules are not applicable',
        'disabled'                                      => 'Disabled',
    ];

    public static $pricing_methods = [
        ''                  => 'Select Method',
        'simple_pricing'    => 'Simple Pricing',
        'bulk_pricing'      => 'Bulk Pricing',
        'buy_x_get_x'       => 'Buy x get x',
        'buy_x_get_y'       => 'Buy x get y',
    ];

    public static $quantity_types = [
        ''              => 'Select Quantity Type',
        'product'       => 'Product',
        'cart_item'     => 'Cart Item',
    ];

    public static $discount_types = [
        ''                      => 'Select Discount Type',
        'fixed_discount'        => 'Fixed Discount',
        'percentage_discount'   => 'Percentage Discount',
        'fixed_price'           => 'Fixed Price',
    ];

    public static $product_types = [
        ''              => 'Select Product Type',
        'product'       => 'Product',
    ];

    public static $inOrNotConditions = [
        ''              => 'Select Condition',
        'in_list'       => 'In List',
        'not_in_list'   => 'Not In List',
    ];

    public static $condition_types = [
        ''                              => 'Select Condition Type',
        'cart_total_quantity'           => 'Cart total quantity',
        'cart_item_count'               => 'Cart item count',
        'cart_items_products'           => 'Cart items - Products',
        'cart_item_quantity_products'   => 'Cart item quantity - Products',
        'customer'                      => 'Customer',
        'guest'                         => 'Guest',
        'is_logged_in'                  => 'Is logged in',
        'date_and_time'                 => 'Date & time',
    ];

    public static $atLeastOrMoreConditions = [
        ''                => 'Select Condition',
        'at_least'        => 'At least',
        'more_than'       => 'More than',
        'not_more_than'   => 'Not more than',
        'less_than'       => 'Less than',
    ];

    public static $productItemConditions = [
        ''              => 'Select Condition',
        'at_least_one'  => 'At least one of selected',
        'all'           => 'All of selected',
        'only'          => 'Only selected',
        'none'          => 'None of selected',
    ];

    public static function productPricingApply($Carts,$cart_value,$store,$cart_product_data,$product_list_values,$totalQtyDiscount)
    {
        $productPricingRules    = ProductPricingRule::where('store_id',$store->id)->get();
        $setting                = getAdminAllSetting($store->created_by,$store->id);
        $cartTotalQty = 0;
        $apply_conditions = [];
        $cartTotalItem = count((array) $Carts);
        foreach ($Carts as $item) {
            $cartTotalQty += (int)$item->qty;
        }
        $hasBuyXGetY = false;
        $check_applied = false;
        if ($productPricingRules->isNotEmpty()) {
            foreach ($productPricingRules as $key => $rule) {
                if (!empty($rule->rule_status) && $rule->rule_status != 'none' && $rule->rule_status != 'disabled' && $rule->pricing_method == 'buy_x_get_y') {
                    $hasBuyXGetY = true;
                    break 1; // Exit both loops
                }
            }
            $productListUpdatedValue = [];
            foreach ($productPricingRules as $key => $rule) {
                switch ($setting['all_rules_condition'] ?? 'none') {
                    case 'apply_first_rule':
                        // if ($key == 0) {
                            if (!empty($rule->rule_status) && $rule->rule_status != 'none' && $rule->rule_status != 'disabled') {
                                if ($rule->pricing_method == 'simple_pricing') {
                                    $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                    if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                        if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                    foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $check_applied = true;
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    foreach ($field_json as $range => $json){
                                                        if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                            $product_json_check = true;
                                                            $condition_json_check = true;

                                                            if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                $product_discount = $product_list_values['product_discount_price'];
                                                                $product_final_price = $product_list_values['final_price'];
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_json_check = false;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_json_check = false;
                                                                    }
                                                                }
                                                                if ($product_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                        $cartProductIds = array_map(function($item) {
                                                                            return $item->product_id;
                                                                        }, (array)$Carts);

                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                        switch ($condition_value->type) {
                                                                            case 'at_least_one':
                                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            case 'all':
                                                                                // Ensure all selected items are in the cart
                                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'only':
                                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'none':
                                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            default:
                                                                                $condition_json_check = true;
                                                                                break;
                                                                        }

                                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                        $selectedProductQty = 0;
                                                                        foreach ($Carts as $item) {
                                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                                $selectedProductQty += (int)$item->qty;
                                                                            }
                                                                        }
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'customer') {
                                                                        if(auth('customers')->user()){
                                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                                $condition_json_check = false;
                                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                                $condition_json_check = false;
                                                                            }
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'guest') {
                                                                        if(auth('customers')->user()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                        if(auth('customers')->guest()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                        $currentDateTime = new \DateTime();

                                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                            $condition_json_check = true;
                                                                        } else {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                    if ($condition_json_check == false) {
                                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                                $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                                $product_list_values['final_price'] = $product_final_price;
                                                                            }
                                                                        } else {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'];
                                                                                $product_list_values['final_price'];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    if($product_json_check == true){
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    if($cart_value->qty > $field_json[0]->buy_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                            }
                                                            if($field_json[0]->get_quantity != $cart_value->qty){
                                                                $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    if($product_json_check == true){
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    $product_id = 0;
                                                    $product_qty = 0;
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break; // Stop after finding the first item
                                                            }
                                                        }
                                                    }
                                                    if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                        $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                        if($QtyDiscount >= $field_json[0]->get_quantity){
                                                            $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                        }
                                                        else if($QtyDiscount != $cart_value->qty){
                                                            $QtyDiscount = $cart_value->qty;
                                                        }
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                            }
                                                            if($QtyDiscount != $cart_value->qty){
                                                                $qty = $cart_value->qty - $QtyDiscount;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                        $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                        $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                        $qty_json[]                = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        if($product_json_check == true){
                                                            $check_applied = true;
                                                        }
                                                    }else{
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = false;
                                                        $productListUpdatedValue['condition_json_check']    = false;
                                                    }

                                                }
                                            }
                                        }
                                    }
                                    if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                        $productListUpdatedValue['product_json_check']      = true;
                                        $productListUpdatedValue['condition_json_check']    = true;
                                    } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                    } else if ($check_applied != true){  // apply_with_other_rules
                                        if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                            $field_json = json_decode($rule->field_json);
                                            $product_json_check = true;
                                            $condition_json_check = true;
                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                }
                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                }
                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                $product_discount = $product_list_values['product_discount_price'];
                                                $product_final_price = $product_list_values['final_price'];
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                }
                                            } else {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'];
                                                    $product_list_values['final_price'];
                                                }
                                            }
                                            if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                        $product_json_check = false;
                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                        $product_json_check = false;
                                                    }
                                                }
                                                if ($product_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                            if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                        $cartProductIds = array_map(function($item) {
                                                            return $item->product_id;
                                                        }, (array)$Carts);

                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                        switch ($condition_value->type) {
                                                            case 'at_least_one':
                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            case 'all':
                                                                // Ensure all selected items are in the cart
                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'only':
                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'none':
                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            default:
                                                                $condition_json_check = true;
                                                                break;
                                                        }

                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                        $selectedProductQty = 0;
                                                        foreach ($Carts as $item) {
                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                $selectedProductQty += (int)$item->qty;
                                                            }
                                                        }
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'customer') {
                                                        if(auth('customers')->user()){
                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    } else if ($condition_value->condition_type == 'guest') {
                                                        if(auth('customers')->user()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                        if(auth('customers')->guest()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                        $currentDateTime = new \DateTime();

                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                            $condition_json_check = true;
                                                        } else {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    if ($condition_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }

                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if ($product_json_check == true){
                                                break 2;
                                            }
                                        }
                                    }
                                } else if ($rule->pricing_method == 'bulk_pricing') {
                                    $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                    if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                        if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                    foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $check_applied = true;
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    foreach ($field_json as $range => $json){
                                                        if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                            $product_json_check = true;
                                                            $condition_json_check = true;

                                                            if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                $product_discount = $product_list_values['product_discount_price'];
                                                                $product_final_price = $product_list_values['final_price'];
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_json_check = false;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_json_check = false;
                                                                    }
                                                                }
                                                                if ($product_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                        $cartProductIds = array_map(function($item) {
                                                                            return $item->product_id;
                                                                        }, (array)$Carts);

                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                        switch ($condition_value->type) {
                                                                            case 'at_least_one':
                                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            case 'all':
                                                                                // Ensure all selected items are in the cart
                                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'only':
                                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'none':
                                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            default:
                                                                                $condition_json_check = true;
                                                                                break;
                                                                        }

                                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                        $selectedProductQty = 0;
                                                                        foreach ($Carts as $item) {
                                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                                $selectedProductQty += (int)$item->qty;
                                                                            }
                                                                        }
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'customer') {
                                                                        if(auth('customers')->user()){
                                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                                $condition_json_check = false;
                                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                                $condition_json_check = false;
                                                                            }
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'guest') {
                                                                        if(auth('customers')->user()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                        if(auth('customers')->guest()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                        $currentDateTime = new \DateTime();

                                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                            $condition_json_check = true;
                                                                        } else {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                    if ($condition_json_check == false) {
                                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                                $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                                $product_list_values['final_price'] = $product_final_price;
                                                                            }
                                                                        } else {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'];
                                                                                $product_list_values['final_price'];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    if($product_json_check == true){
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    if($cart_value->qty > $field_json[0]->buy_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                            }
                                                            if($field_json[0]->get_quantity != $cart_value->qty){
                                                                $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    if($product_json_check == true){
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    $product_id = 0;
                                                    $product_qty = 0;
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break; // Stop after finding the first item
                                                            }
                                                        }
                                                    }
                                                    if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                        $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                        if($QtyDiscount >= $field_json[0]->get_quantity){
                                                            $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                        }
                                                        else if($QtyDiscount != $cart_value->qty){
                                                            $QtyDiscount = $cart_value->qty;
                                                        }
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                            }
                                                            if($QtyDiscount != $cart_value->qty){
                                                                $qty = $cart_value->qty - $QtyDiscount;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                        $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                        $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                        $qty_json[]                = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        if($product_json_check == true){
                                                            $check_applied = true;
                                                        }
                                                    }else{
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = false;
                                                        $productListUpdatedValue['condition_json_check']    = false;
                                                    }

                                                }
                                            }
                                        }
                                    }
                                    if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                        $productListUpdatedValue['product_json_check']      = true;
                                        $productListUpdatedValue['condition_json_check']    = true;
                                    } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                    } else if ($check_applied != true){  // apply_with_other_rules
                                        if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                                $field_json = json_decode($rule->field_json);

                                                foreach ($field_json as $range => $json){
                                                    if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;

                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                            foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                            foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if ($product_json_check == true){
                                                    break 2;
                                                }
                                            }
                                        }
                                    }
                                } else if ($rule->pricing_method == 'buy_x_get_x') {
                                    $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                    if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                        if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                    foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $check_applied = true;
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    foreach ($field_json as $range => $json){
                                                        if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                            $product_json_check = true;
                                                            $condition_json_check = true;

                                                            if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                $product_discount = $product_list_values['product_discount_price'];
                                                                $product_final_price = $product_list_values['final_price'];
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_json_check = false;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_json_check = false;
                                                                    }
                                                                }
                                                                if ($product_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                        $cartProductIds = array_map(function($item) {
                                                                            return $item->product_id;
                                                                        }, (array)$Carts);

                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                        switch ($condition_value->type) {
                                                                            case 'at_least_one':
                                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            case 'all':
                                                                                // Ensure all selected items are in the cart
                                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'only':
                                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'none':
                                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            default:
                                                                                $condition_json_check = true;
                                                                                break;
                                                                        }

                                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                        $selectedProductQty = 0;
                                                                        foreach ($Carts as $item) {
                                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                                $selectedProductQty += (int)$item->qty;
                                                                            }
                                                                        }
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'customer') {
                                                                        if(auth('customers')->user()){
                                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                                $condition_json_check = false;
                                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                                $condition_json_check = false;
                                                                            }
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'guest') {
                                                                        if(auth('customers')->user()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                        if(auth('customers')->guest()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                        $currentDateTime = new \DateTime();

                                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                            $condition_json_check = true;
                                                                        } else {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                    if ($condition_json_check == false) {
                                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                                $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                                $product_list_values['final_price'] = $product_final_price;
                                                                            }
                                                                        } else {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'];
                                                                                $product_list_values['final_price'];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    if($product_json_check == true){
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    if($cart_value->qty > $field_json[0]->buy_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                            }
                                                            if($field_json[0]->get_quantity != $cart_value->qty){
                                                                $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    if($product_json_check == true){
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    $product_id = 0;
                                                    $product_qty = 0;
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break; // Stop after finding the first item
                                                            }
                                                        }
                                                    }
                                                    if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                        $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                        if($QtyDiscount >= $field_json[0]->get_quantity){
                                                            $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                        }
                                                        else if($QtyDiscount != $cart_value->qty){
                                                            $QtyDiscount = $cart_value->qty;
                                                        }
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                            }
                                                            if($QtyDiscount != $cart_value->qty){
                                                                $qty = $cart_value->qty - $QtyDiscount;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                        $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                        $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                        $qty_json[]                = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        if($product_json_check == true){
                                                            $check_applied = true;
                                                        }
                                                    }else{
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = false;
                                                        $productListUpdatedValue['condition_json_check']    = false;
                                                    }

                                                }
                                            }
                                        }
                                    }
                                    if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                        $productListUpdatedValue['product_json_check']      = true;
                                        $productListUpdatedValue['condition_json_check']    = true;
                                    } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                    } else if ($check_applied != true){  // apply_with_other_rules
                                        if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                                $field_json = json_decode($rule->field_json);
                                                if($cart_value->qty > $field_json[0]->buy_quantity){
                                                    // $data = self::simplePricing($field_json, $cart_value, $product_list_values, $cart_product_data, $rule, $cartTotalQty, $cartTotalItem, $Carts);
                                                    // $productListUpdatedValue['product_discount_price']  = isset($data) ? $data['product_discount_price'] : max(0, $product_list_values['product_discount_price']);
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                        }
                                                        if($field_json[0]->get_quantity != $cart_value->qty){
                                                            $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                        foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                        foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                $qty_json[]    = ["rule_id"=>$rule->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                if ($product_json_check == true){
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    break 2;
                                                }
                                            }
                                        }
                                    }
                                } else if ($rule->pricing_method == 'buy_x_get_y') {
                                    $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                    if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                        if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                    foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                }

                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $check_applied = true;
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    foreach ($field_json as $range => $json){
                                                        if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                            $product_json_check = true;
                                                            $condition_json_check = true;

                                                            if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                $product_discount = $product_list_values['product_discount_price'];
                                                                $product_final_price = $product_list_values['final_price'];
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_json_check = false;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_json_check = false;
                                                                    }
                                                                }
                                                                if ($product_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                        $cartProductIds = array_map(function($item) {
                                                                            return $item->product_id;
                                                                        }, (array)$Carts);

                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                        switch ($condition_value->type) {
                                                                            case 'at_least_one':
                                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            case 'all':
                                                                                // Ensure all selected items are in the cart
                                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'only':
                                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                                break;

                                                                            case 'none':
                                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                                break;

                                                                            default:
                                                                                $condition_json_check = true;
                                                                                break;
                                                                        }

                                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                        $selectedProductQty = 0;
                                                                        foreach ($Carts as $item) {
                                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                                $selectedProductQty += (int)$item->qty;
                                                                            }
                                                                        }
                                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'customer') {
                                                                        if(auth('customers')->user()){
                                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                                $condition_json_check = false;
                                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                                $condition_json_check = false;
                                                                            }
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'guest') {
                                                                        if(auth('customers')->user()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                        if(auth('customers')->guest()){
                                                                            $condition_json_check = false;
                                                                        }
                                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                        $currentDateTime = new \DateTime();

                                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                            $condition_json_check = true;
                                                                        } else {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                    if ($condition_json_check == false) {
                                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                                $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            }
                                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                                $product_list_values['final_price'] = $product_final_price;
                                                                            }
                                                                        } else {
                                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                                $product_list_values['product_discount_price'];
                                                                                $product_list_values['final_price'];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    if($product_json_check == true){
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    if($cart_value->qty > $field_json[0]->buy_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                            }
                                                            if($field_json[0]->get_quantity != $cart_value->qty){
                                                                $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    if($product_json_check == true){
                                                        $check_applied = true;
                                                    }
                                                }
                                            }
                                        } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                            if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                                if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                    $field_json = json_decode($disregard_other_rules->field_json);
                                                    $product_id = 0;
                                                    $product_qty = 0;
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                        $product_id = $item->product_id;
                                                                        $product_qty = $item->qty;
                                                                        break 2;
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        foreach ($Carts as $key => $item) {
                                                            if ($item->qty >= $field_json[0]->buy_quantity) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break; // Stop after finding the first item
                                                            }
                                                        }
                                                    }
                                                    if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                        $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                        if($QtyDiscount >= $field_json[0]->get_quantity){
                                                            $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                        }
                                                        else if($QtyDiscount != $cart_value->qty){
                                                            $QtyDiscount = $cart_value->qty;
                                                        }
                                                        $product_json_check = true;
                                                        $condition_json_check = true;
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                            }
                                                            if($QtyDiscount != $cart_value->qty){
                                                                $qty = $cart_value->qty - $QtyDiscount;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        } else {
                                                            if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                            }else{
                                                                $product_json_check = false;
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                        $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                        $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                        $qty_json[]                = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        if($product_json_check == true){
                                                            $check_applied = true;
                                                        }
                                                    }else{
                                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                        $productListUpdatedValue['product_json_check']      = false;
                                                        $productListUpdatedValue['condition_json_check']    = false;
                                                    }

                                                }
                                            }
                                        }
                                    }
                                    if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                        $productListUpdatedValue['product_json_check']      = true;
                                        $productListUpdatedValue['condition_json_check']    = true;
                                    } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                    } else if ($check_applied != true){  // apply_with_other_rules
                                        if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                                $field_json = json_decode($rule->field_json);
                                                $product_id = 0;
                                                $product_qty = 0;
                                                if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            $product_id = $item->product_id;
                                                            $product_qty = $item->qty;
                                                            break; // Stop after finding the first item
                                                        }
                                                    }
                                                }
                                
                                                if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                    $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                    // if($totalQtyDiscount > 0 ){
                                                    // dump($QtyDiscount , $totalQtyDiscount);
                                                    // }
                                                    if($QtyDiscount >= $field_json[0]->get_quantity){
                                                        $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                    }
                                                    else if($QtyDiscount != $cart_value->qty){
                                                        $QtyDiscount = $cart_value->qty;
                                                    }
                                                   
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                        }
                                                        if($QtyDiscount != $cart_value->qty){
                                                            $qty = $cart_value->qty - $QtyDiscount;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($rule->product_get_json) && (is_array(json_decode($rule->product_get_json)) || is_object(json_decode($rule->product_get_json)))) {
                                                        foreach (json_decode($rule->product_get_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                        foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                    $qty_json[]                = ["rule_id"=>$rule->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    if ($product_json_check == true){
                                                        break 2;
                                                    }
                                                }else{
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = false;
                                                    $productListUpdatedValue['condition_json_check']    = false;
                                                }

                                            }
                                        }
                                    }
                                }
                            } else {
                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                $productListUpdatedValue['product_json_check']      = true;
                                $productListUpdatedValue['condition_json_check']    = true;
                            }
                        // }
                        break;

                    case 'apply_all_rules':
                        if (!empty($rule->rule_status) && $rule->rule_status != 'none' && $rule->rule_status != 'disabled') {
                            if ($rule->pricing_method == 'simple_pricing') {
                                $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                    if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                        if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                            $field_json = json_decode($disregard_other_rules->field_json);
                                            $product_json_check = true;
                                            $condition_json_check = true;
                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                }
                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                }
                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                $product_discount = $product_list_values['product_discount_price'];
                                                $product_final_price = $product_list_values['final_price'];
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                }
                                            } else {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'];
                                                    $product_list_values['final_price'];
                                                }
                                            }
                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                        $product_json_check = false;
                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                        $product_json_check = false;
                                                    }
                                                }
                                                if ($product_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                        $cartProductIds = array_map(function($item) {
                                                            return $item->product_id;
                                                        }, (array)$Carts);

                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                        switch ($condition_value->type) {
                                                            case 'at_least_one':
                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            case 'all':
                                                                // Ensure all selected items are in the cart
                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'only':
                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'none':
                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            default:
                                                                $condition_json_check = true;
                                                                break;
                                                        }

                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                        $selectedProductQty = 0;
                                                        foreach ($Carts as $item) {
                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                $selectedProductQty += (int)$item->qty;
                                                            }
                                                        }
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'customer') {
                                                        if(auth('customers')->user()){
                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    } else if ($condition_value->condition_type == 'guest') {
                                                        if(auth('customers')->user()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                        if(auth('customers')->guest()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                        $currentDateTime = new \DateTime();

                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                            $condition_json_check = true;
                                                        } else {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    if ($condition_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if($product_json_check == true){
                                                $apply_conditions[] = $disregard_other_rules->id;
                                                $productListUpdatedValue['apply_conditions']    = $apply_conditions;
                                                $check_applied = true;
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);

                                                foreach ($field_json as $range => $json){
                                                    if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;

                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $apply_conditions[] = $disregard_other_rules->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                    $check_applied = true;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                if($cart_value->qty > $field_json[0]->buy_quantity){
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                        }
                                                        if($field_json[0]->get_quantity != $cart_value->qty){
                                                            $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_x","get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_id = 0;
                                                $product_qty = 0;
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            $product_id = $item->product_id;
                                                            $product_qty = $item->qty;
                                                            break; // Stop after finding the first item
                                                        }
                                                    }
                                                }
                                                if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                    $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                    if($QtyDiscount >= $field_json[0]->get_quantity){
                                                        $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                    }
                                                    else if($QtyDiscount != $cart_value->qty){
                                                        $QtyDiscount = $cart_value->qty;
                                                    }
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                        }
                                                        if($QtyDiscount != $cart_value->qty){
                                                            $qty = $cart_value->qty - $QtyDiscount;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                // dump($cart_value , $cart_product_data , $product_json_value->get_selected_products);
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    = $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                }else{
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = true;
                                                    $productListUpdatedValue['condition_json_check']    = true;
                                                }

                                            }
                                        }
                                    }
                                }
                                if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                    $productListUpdatedValue['product_json_check']      = true;
                                    $productListUpdatedValue['condition_json_check']    = true;
                                } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                } else if ($check_applied != true){  // apply_with_other_rules
                                    if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                        $field_json = json_decode($rule->field_json);
                                        $product_json_check = true;
                                        $condition_json_check = true;
                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                            }
                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                            }
                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                            $product_discount = $product_list_values['product_discount_price'];
                                            $product_final_price = $product_list_values['final_price'];
                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                            }
                                        } else {
                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                $product_list_values['product_discount_price'];
                                                $product_list_values['final_price'];
                                            }
                                        }
                                        if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                            foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                    $product_json_check = false;
                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                    $product_json_check = false;
                                                }
                                            }
                                            if ($product_json_check == false) {
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                        $product_list_values['final_price'] = $product_final_price;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                            }
                                        }
                                        if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                            foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                        $condition_json_check = false;
                                                    }
                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                        $condition_json_check = false;
                                                    }
                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                    $cartProductIds = array_map(function($item) {
                                                        return $item->product_id;
                                                    }, (array)$Carts);

                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                    switch ($condition_value->type) {
                                                        case 'at_least_one':
                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                            break;

                                                        case 'all':
                                                            // Ensure all selected items are in the cart
                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                            break;

                                                        case 'only':
                                                            // Ensure all cart items are in selected items and no extra items are present
                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                            break;

                                                        case 'none':
                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                            break;

                                                        default:
                                                            $condition_json_check = true;
                                                            break;
                                                    }

                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                    $selectedProductQty = 0;
                                                    foreach ($Carts as $item) {
                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                            $selectedProductQty += (int)$item->qty;
                                                        }
                                                    }
                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                        $condition_json_check = false;
                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                        $condition_json_check = false;
                                                    }
                                                } else if ($condition_value->condition_type == 'customer') {
                                                    if(auth('customers')->user()){
                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                } else if ($condition_value->condition_type == 'guest') {
                                                    if(auth('customers')->user()){
                                                        $condition_json_check = false;
                                                    }
                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                    if(auth('customers')->guest()){
                                                        $condition_json_check = false;
                                                    }
                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                    $currentDateTime = new \DateTime();

                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                        $condition_json_check = true;
                                                    } else {
                                                        $condition_json_check = false;
                                                    }
                                                }
                                                if ($condition_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        // dump($product_json_check , $product_discount , $product_final_price , $product_list_values);
                                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                        $productListUpdatedValue['product_json_check']      = $product_json_check;
                                        $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                        if($product_json_check == true){
                                            // dump($productListUpdatedValue ,$cart_product_data , $rule);
                                            $apply_conditions[] = $rule->id;
                                            $productListUpdatedValue['apply_conditions']    = $apply_conditions;
                                        }
                                    }
                                }

                            } else if ($rule->pricing_method == 'bulk_pricing') {
                                $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                    if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                        if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                            $field_json = json_decode($disregard_other_rules->field_json);
                                            $product_json_check = true;
                                            $condition_json_check = true;
                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                }
                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                }
                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                $product_discount = $product_list_values['product_discount_price'];
                                                $product_final_price = $product_list_values['final_price'];
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                }
                                            } else {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'];
                                                    $product_list_values['final_price'];
                                                }
                                            }
                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                        $product_json_check = false;
                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                        $product_json_check = false;
                                                    }
                                                }
                                                if ($product_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                        $cartProductIds = array_map(function($item) {
                                                            return $item->product_id;
                                                        }, (array)$Carts);

                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                        switch ($condition_value->type) {
                                                            case 'at_least_one':
                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            case 'all':
                                                                // Ensure all selected items are in the cart
                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'only':
                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'none':
                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            default:
                                                                $condition_json_check = true;
                                                                break;
                                                        }

                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                        $selectedProductQty = 0;
                                                        foreach ($Carts as $item) {
                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                $selectedProductQty += (int)$item->qty;
                                                            }
                                                        }
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'customer') {
                                                        if(auth('customers')->user()){
                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    } else if ($condition_value->condition_type == 'guest') {
                                                        if(auth('customers')->user()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                        if(auth('customers')->guest()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                        $currentDateTime = new \DateTime();

                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                            $condition_json_check = true;
                                                        } else {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    if ($condition_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if($product_json_check == true){
                                                $apply_conditions[] = $disregard_other_rules->id;
                                                $productListUpdatedValue['apply_conditions']    = $apply_conditions;
                                                $check_applied = true;
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);

                                                foreach ($field_json as $range => $json){
                                                    if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;

                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $apply_conditions[] = $disregard_other_rules->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                    $check_applied = true;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                if($cart_value->qty > $field_json[0]->buy_quantity){
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                        }
                                                        if($field_json[0]->get_quantity != $cart_value->qty){
                                                            $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_x","get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_id = 0;
                                                $product_qty = 0;
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            $product_id = $item->product_id;
                                                            $product_qty = $item->qty;
                                                            break; // Stop after finding the first item
                                                        }
                                                    }
                                                }
                                                if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                    $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                    if($QtyDiscount >= $field_json[0]->get_quantity){
                                                        $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                    }
                                                    else if($QtyDiscount != $cart_value->qty){
                                                        $QtyDiscount = $cart_value->qty;
                                                    }
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                        }
                                                        if($QtyDiscount != $cart_value->qty){
                                                            $qty = $cart_value->qty - $QtyDiscount;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                // dump($cart_value , $cart_product_data , $product_json_value->get_selected_products);
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    = $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                }else{
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = true;
                                                    $productListUpdatedValue['condition_json_check']    = true;
                                                }

                                            }
                                        }
                                    }
                                }
                                if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                    $productListUpdatedValue['product_json_check']      = true;
                                    $productListUpdatedValue['condition_json_check']    = true;
                                } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                } else if ($check_applied != true){  // apply_with_other_rules
                                    if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                        if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                            $field_json = json_decode($rule->field_json);
                                            $product_json_check = false;
                                            $condition_json_check = false;
                                            foreach ($field_json as $range => $json){
                                                if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                    $product_json_check = true;
                                                    $condition_json_check = true;

                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                        }
                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                        foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                        foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                }
                                            }
                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if($product_json_check == true){
                                                $apply_conditions[] = $rule->id;
                                                $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                            }
                                        }
                                    }
                                }
                            } else if ($rule->pricing_method == 'buy_x_get_x' && $hasBuyXGetY == false) {
                                $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                    if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                        if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                            $field_json = json_decode($disregard_other_rules->field_json);
                                            $product_json_check = true;
                                            $condition_json_check = true;
                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                }
                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                }
                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                $product_discount = $product_list_values['product_discount_price'];
                                                $product_final_price = $product_list_values['final_price'];
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                }
                                            } else {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'];
                                                    $product_list_values['final_price'];
                                                }
                                            }
                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                        $product_json_check = false;
                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                        $product_json_check = false;
                                                    }
                                                }
                                                if ($product_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                        $cartProductIds = array_map(function($item) {
                                                            return $item->product_id;
                                                        }, (array)$Carts);

                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                        switch ($condition_value->type) {
                                                            case 'at_least_one':
                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            case 'all':
                                                                // Ensure all selected items are in the cart
                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'only':
                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'none':
                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            default:
                                                                $condition_json_check = true;
                                                                break;
                                                        }

                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                        $selectedProductQty = 0;
                                                        foreach ($Carts as $item) {
                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                $selectedProductQty += (int)$item->qty;
                                                            }
                                                        }
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'customer') {
                                                        if(auth('customers')->user()){
                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    } else if ($condition_value->condition_type == 'guest') {
                                                        if(auth('customers')->user()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                        if(auth('customers')->guest()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                        $currentDateTime = new \DateTime();

                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                            $condition_json_check = true;
                                                        } else {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    if ($condition_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if($product_json_check == true){
                                                $apply_conditions[] = $disregard_other_rules->id;
                                                $productListUpdatedValue['apply_conditions']    = $apply_conditions;
                                                $check_applied = true;
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);

                                                foreach ($field_json as $range => $json){
                                                    if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;

                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $apply_conditions[] = $disregard_other_rules->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                    $check_applied = true;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                if($cart_value->qty > $field_json[0]->buy_quantity){
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                        }
                                                        if($field_json[0]->get_quantity != $cart_value->qty){
                                                            $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_x","get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    if($product_json_check == true){
                                                        $productListUpdatedValue['qty_json']    =  $qty_json;
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_id = 0;
                                                $product_qty = 0;
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            $product_id = $item->product_id;
                                                            $product_qty = $item->qty;
                                                            break; // Stop after finding the first item
                                                        }
                                                    }
                                                }
                                                if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                    $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                    if($QtyDiscount >= $field_json[0]->get_quantity){
                                                        $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                    }
                                                    else if($QtyDiscount != $cart_value->qty){
                                                        $QtyDiscount = $cart_value->qty;
                                                    }
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                        }
                                                        if($QtyDiscount != $cart_value->qty){
                                                            $qty = $cart_value->qty - $QtyDiscount;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                // dump($cart_value , $cart_product_data , $product_json_value->get_selected_products);
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    = $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                }else{
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = true;
                                                    $productListUpdatedValue['condition_json_check']    = true;
                                                }

                                            }
                                        }
                                    }
                                }
                                if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                    $productListUpdatedValue['product_json_check']      = true;
                                    $productListUpdatedValue['condition_json_check']    = true;
                                } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                } else if ($check_applied != true){  // apply_with_other_rules
                                    if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                        if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                            $field_json = json_decode($rule->field_json);
                                            if($cart_value->qty > $field_json[0]->buy_quantity){
                                                // $data = self::simplePricing($field_json, $cart_value, $product_list_values, $cart_product_data, $rule, $cartTotalQty, $cartTotalItem, $Carts);
                                                // $productListUpdatedValue['product_discount_price']  = isset($data) ? $data['product_discount_price'] : max(0, $product_list_values['product_discount_price']);
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                    }
                                                    if($field_json[0]->get_quantity != $cart_value->qty){
                                                        $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                    foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                    foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_x","get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                $qty_json[]    = ["rule_id"=>$rule->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                if($product_json_check == true){
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    $apply_conditions[] = $rule->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                }
                                            } else {
                                                if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                }else{
                                                    $product_json_check = false;
                                                    $condition_json_check = false;
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            }
                                        }
                                    }
                                }
                            } else if ($rule->pricing_method == 'buy_x_get_y') {
                                $disregard_other_rules    = ProductPricingRule::where('rule_status','apply_this_rule_and_disregard_other_rules')->where('store_id',$store->id)->first();
                                if (isset($disregard_other_rules) && !empty($disregard_other_rules) && $check_applied != true) {
                                    if ($disregard_other_rules->pricing_method == 'simple_pricing') {
                                        if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                            $field_json = json_decode($disregard_other_rules->field_json);
                                            $product_json_check = true;
                                            $condition_json_check = true;
                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                }
                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                }
                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                $product_discount = $product_list_values['product_discount_price'];
                                                $product_final_price = $product_list_values['final_price'];
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                    $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
                                                }
                                            } else {
                                                for ($i = 0; $i < $cart_value->qty; $i++) {
                                                    $product_list_values['product_discount_price'];
                                                    $product_list_values['final_price'];
                                                }
                                            }
                                            if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                    if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                        $product_json_check = false;
                                                    } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                        $product_json_check = false;
                                                    }
                                                }
                                                if ($product_json_check == false) {
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                            $product_list_values['final_price'] = $product_final_price;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                }
                                            }
                                            if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                    if ($condition_value->condition_type == 'cart_total_quantity') {
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_item_count') {
                                                        if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'cart_items_products') {
                                                        $cartProductIds = array_map(function($item) {
                                                            return $item->product_id;
                                                        }, (array)$Carts);

                                                        $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                        switch ($condition_value->type) {
                                                            case 'at_least_one':
                                                                $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            case 'all':
                                                                // Ensure all selected items are in the cart
                                                                $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'only':
                                                                // Ensure all cart items are in selected items and no extra items are present
                                                                $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                break;

                                                            case 'none':
                                                                $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                break;

                                                            default:
                                                                $condition_json_check = true;
                                                                break;
                                                        }

                                                    } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                        $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                        $selectedProductQty = 0;
                                                        foreach ($Carts as $item) {
                                                            if (in_array($item->product_id, $selectedProductIds)) {
                                                                $selectedProductQty += (int)$item->qty;
                                                            }
                                                        }
                                                        if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'customer') {
                                                        if(auth('customers')->user()){
                                                            if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                    } else if ($condition_value->condition_type == 'guest') {
                                                        if(auth('customers')->user()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'is_logged_in') {
                                                        if(auth('customers')->guest()){
                                                            $condition_json_check = false;
                                                        }
                                                    } else if ($condition_value->condition_type == 'date_and_time') {
                                                        $startDateTime = new \DateTime($condition_value->start_date_time);
                                                        $endDateTime = new \DateTime($condition_value->end_date_time);
                                                        $currentDateTime = new \DateTime();

                                                        if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                            $condition_json_check = true;
                                                        } else {
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    if ($condition_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                            $productListUpdatedValue['product_json_check']      = $product_json_check;
                                            $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                            if($product_json_check == true){
                                                $apply_conditions[] = $disregard_other_rules->id;
                                                $productListUpdatedValue['apply_conditions']    = $apply_conditions;
                                                $check_applied = true;
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'bulk_pricing'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);

                                                foreach ($field_json as $range => $json){
                                                    if($cart_value->qty >= $field_json[$range]->min_quantity && $cart_value->qty <= $field_json[$range]->max_quantity){
                                                        $product_json_check = true;
                                                        $condition_json_check = true;

                                                        if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] -= $field_json[$range]->discount_value;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                            $product_discount = $product_list_values['product_discount_price'];
                                                            $product_final_price = $product_list_values['final_price'];
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[$range]->discount_value;
                                                                $product_list_values['final_price'] = $field_json[$range]->discount_value * $cart_value->qty;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                        if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_json_check = false;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_json_check = false;
                                                                }
                                                            }
                                                            if ($product_json_check == false) {
                                                                if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                        if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                            foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                                if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                    $cartProductIds = array_map(function($item) {
                                                                        return $item->product_id;
                                                                    }, (array)$Carts);

                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                    switch ($condition_value->type) {
                                                                        case 'at_least_one':
                                                                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        case 'all':
                                                                            // Ensure all selected items are in the cart
                                                                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'only':
                                                                            // Ensure all cart items are in selected items and no extra items are present
                                                                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                            break;

                                                                        case 'none':
                                                                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                            break;

                                                                        default:
                                                                            $condition_json_check = true;
                                                                            break;
                                                                    }

                                                                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                    $selectedProductQty = 0;
                                                                    foreach ($Carts as $item) {
                                                                        if (in_array($item->product_id, $selectedProductIds)) {
                                                                            $selectedProductQty += (int)$item->qty;
                                                                        }
                                                                    }
                                                                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'customer') {
                                                                    if(auth('customers')->user()){
                                                                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                            $condition_json_check = false;
                                                                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                            $condition_json_check = false;
                                                                        }
                                                                    }
                                                                } else if ($condition_value->condition_type == 'guest') {
                                                                    if(auth('customers')->user()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                    if(auth('customers')->guest()){
                                                                        $condition_json_check = false;
                                                                    }
                                                                } else if ($condition_value->condition_type == 'date_and_time') {
                                                                    $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                    $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                    $currentDateTime = new \DateTime();

                                                                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                        $condition_json_check = true;
                                                                    } else {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                                if ($condition_json_check == false) {
                                                                    if ($field_json[$range]->discount_type == 'fixed_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $field_json[$range]->discount_value;
                                                                            $product_list_values['final_price'] += $field_json[$range]->discount_value;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'percentage_discount') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[$range]->discount_value / 100;
                                                                        }
                                                                    } else if ($field_json[$range]->discount_type == 'fixed_price') {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'] = $product_discount;
                                                                            $product_list_values['final_price'] = $product_final_price;
                                                                        }
                                                                    } else {
                                                                        for ($i = 0; $i < $cart_value->qty; $i++) {
                                                                            $product_list_values['product_discount_price'];
                                                                            $product_list_values['final_price'];
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                if($product_json_check == true){
                                                    $apply_conditions[] = $disregard_other_rules->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                    $check_applied = true;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_x'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                if($cart_value->qty > $field_json[0]->buy_quantity){
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $field_json[0]->get_quantity;
                                                        }
                                                        if($field_json[0]->get_quantity != $cart_value->qty){
                                                            $qty = $cart_value->qty - $field_json[0]->get_quantity;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $field_json[0]->get_quantity; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_x","get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    =  $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                }
                                            }
                                        }
                                    } else if ($disregard_other_rules->pricing_method == 'buy_x_get_y'){
                                        if ($disregard_other_rules->quantity_type == 'product' || $disregard_other_rules->quantity_type == 'cart_item') {
                                            if (is_array(json_decode($disregard_other_rules->field_json)) || is_object(json_decode($disregard_other_rules->field_json))) {
                                                $field_json = json_decode($disregard_other_rules->field_json);
                                                $product_id = 0;
                                                $product_qty = 0;
                                                if (!empty($disregard_other_rules->product_json) && (is_array(json_decode($disregard_other_rules->product_json)) || is_object(json_decode($disregard_other_rules->product_json)))) {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            foreach (json_decode($disregard_other_rules->product_json) as $key => $product_json_value) {
                                                                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                    $product_id = $item->product_id;
                                                                    $product_qty = $item->qty;
                                                                    break 2;
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    foreach ($Carts as $key => $item) {
                                                        if ($item->qty >= $field_json[0]->buy_quantity) {
                                                            $product_id = $item->product_id;
                                                            $product_qty = $item->qty;
                                                            break; // Stop after finding the first item
                                                        }
                                                    }
                                                }
                                                if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                    $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                    if($QtyDiscount >= $field_json[0]->get_quantity){
                                                        $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                    }
                                                    else if($QtyDiscount != $cart_value->qty){
                                                        $QtyDiscount = $cart_value->qty;
                                                    }
                                                    $product_json_check = true;
                                                    $condition_json_check = true;
                                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        }
                                                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                        $product_discount = $product_list_values['product_discount_price'];
                                                        $product_final_price = $product_list_values['final_price'];
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                            $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                        }
                                                        if($QtyDiscount != $cart_value->qty){
                                                            $qty = $cart_value->qty - $QtyDiscount;
                                                            $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                        }
                                                    } else {
                                                        for ($i = 0; $i < $QtyDiscount; $i++) {
                                                            $product_list_values['product_discount_price'];
                                                            $product_list_values['final_price'];
                                                        }
                                                    }
                                                    if (!empty($disregard_other_rules->product_get_json) && (is_array(json_decode($disregard_other_rules->product_get_json)) || is_object(json_decode($disregard_other_rules->product_get_json)))) {
                                                        foreach (json_decode($disregard_other_rules->product_get_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                                // dump($cart_value , $cart_product_data , $product_json_value->get_selected_products);
                                                                $product_json_check = false;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                                $product_json_check = false;
                                                            }
                                                        }
                                                        if ($product_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                    if(!empty($disregard_other_rules->conditions) && (is_array(json_decode($disregard_other_rules->conditions)) || is_object(json_decode($disregard_other_rules->conditions)))){
                                                        foreach (json_decode($disregard_other_rules->conditions) as $key => $condition_value) {
                                                            if ($condition_value->condition_type == 'cart_total_quantity') {
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_item_count') {
                                                                if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'cart_items_products') {
                                                                $cartProductIds = array_map(function($item) {
                                                                    return $item->product_id;
                                                                }, (array)$Carts);

                                                                $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                                switch ($condition_value->type) {
                                                                    case 'at_least_one':
                                                                        $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    case 'all':
                                                                        // Ensure all selected items are in the cart
                                                                        $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'only':
                                                                        // Ensure all cart items are in selected items and no extra items are present
                                                                        $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                        break;

                                                                    case 'none':
                                                                        $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                        break;

                                                                    default:
                                                                        $condition_json_check = true;
                                                                        break;
                                                                }

                                                            } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                                $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                                $selectedProductQty = 0;
                                                                foreach ($Carts as $item) {
                                                                    if (in_array($item->product_id, $selectedProductIds)) {
                                                                        $selectedProductQty += (int)$item->qty;
                                                                    }
                                                                }
                                                                if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'customer') {
                                                                if(auth('customers')->user()){
                                                                    if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                        $condition_json_check = false;
                                                                    } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                        $condition_json_check = false;
                                                                    }
                                                                }
                                                            } else if ($condition_value->condition_type == 'guest') {
                                                                if(auth('customers')->user()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'is_logged_in') {
                                                                if(auth('customers')->guest()){
                                                                    $condition_json_check = false;
                                                                }
                                                            } else if ($condition_value->condition_type == 'date_and_time') {
                                                                $startDateTime = new \DateTime($condition_value->start_date_time);
                                                                $endDateTime = new \DateTime($condition_value->end_date_time);
                                                                $currentDateTime = new \DateTime();

                                                                if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                    $condition_json_check = true;
                                                                } else {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                            if ($condition_json_check == false) {
                                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    }
                                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'] = $product_discount;
                                                                        $product_list_values['final_price'] = $product_final_price;
                                                                    }
                                                                } else {
                                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                        $product_list_values['product_discount_price'];
                                                                        $product_list_values['final_price'];
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    } else {
                                                        if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                        }else{
                                                            $product_json_check = false;
                                                            $condition_json_check = false;
                                                        }
                                                    }
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                    $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                    $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                    $qty_json[]    = ["rule_id"=>$disregard_other_rules->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                    $productListUpdatedValue['qty_json']    = $qty_json;
                                                    if($product_json_check == true){
                                                        $apply_conditions[] = $disregard_other_rules->id;
                                                        $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                        $check_applied = true;
                                                    }
                                                }else{
                                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                    $productListUpdatedValue['product_json_check']      = true;
                                                    $productListUpdatedValue['condition_json_check']    = true;
                                                }

                                            }
                                        }
                                    }
                                }
                                if ($rule->rule_status == 'apply_this_rule_and_disregard_other_rules') {
                                    $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                    $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                    $productListUpdatedValue['product_json_check']      = true;
                                    $productListUpdatedValue['condition_json_check']    = true;
                                } else if ($rule->rule_status == 'apply_if_other_rules_are_not') {
                                } else if ($check_applied != true){  // apply_with_other_rules
                                    if ($rule->quantity_type == 'product' || $rule->quantity_type == 'cart_item') {
                                        if (is_array(json_decode($rule->field_json)) || is_object(json_decode($rule->field_json))) {
                                            $field_json = json_decode($rule->field_json);
                                            $product_id = 0;
                                            $product_qty = 0;
                                            if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
                                                foreach ($Carts as $key => $item) {
                                                    if ($item->qty >= $field_json[0]->buy_quantity) {
                                                        foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                                                            if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == true) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break 2;
                                                            } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($item->product_id,explode(",", $product_json_value->selected_products)) == false) {
                                                                $product_id = $item->product_id;
                                                                $product_qty = $item->qty;
                                                                break 2;
                                                            }
                                                        }
                                                    }
                                                }
                                            } else {
                                                foreach ($Carts as $key => $item) {
                                                    if ($item->qty >= $field_json[0]->buy_quantity) {
                                                        $product_id = $item->product_id;
                                                        $product_qty = $item->qty;
                                                        break; // Stop after finding the first item
                                                    }
                                                }
                                            }
                                            if($product_qty > $field_json[0]->buy_quantity && $product_id != $cart_product_data->id && $totalQtyDiscount < $field_json[0]->get_quantity){
                                                $QtyDiscount = $totalQtyDiscount + $cart_value->qty;
                                                // if($totalQtyDiscount > 0 ){
                                                // dump($QtyDiscount , $totalQtyDiscount);
                                                // }
                                                if($QtyDiscount >= $field_json[0]->get_quantity){
                                                    $QtyDiscount = $field_json[0]->get_quantity - $totalQtyDiscount;
                                                }
                                                else if($QtyDiscount != $cart_value->qty){
                                                    $QtyDiscount = $cart_value->qty;
                                                }
                                                $product_json_check = true;
                                                $condition_json_check = true;
                                                if ($field_json[0]->discount_type == 'fixed_discount') {
                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                        $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] -= $field_json[0]->discount_value;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                        $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                        $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                    }
                                                } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                    $product_discount = $product_list_values['product_discount_price'];
                                                    $product_final_price = $product_list_values['final_price'];
                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                        $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                                                        $product_list_values['final_price'] = $field_json[0]->discount_value * $QtyDiscount;
                                                    }
                                                    if($QtyDiscount != $cart_value->qty){
                                                        $qty = $cart_value->qty - $QtyDiscount;
                                                        $product_list_values['final_price'] += $cart_product_data->sale_price * $qty;
                                                    }
                                                } else {
                                                    for ($i = 0; $i < $QtyDiscount; $i++) {
                                                        $product_list_values['product_discount_price'];
                                                        $product_list_values['final_price'];
                                                    }
                                                }
                                                if (!empty($rule->product_get_json) && (is_array(json_decode($rule->product_get_json)) || is_object(json_decode($rule->product_get_json)))) {
                                                    foreach (json_decode($rule->product_get_json) as $key => $product_json_value) {
                                                        if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == false) {
                                                            // dump($cart_value , $cart_product_data , $product_json_value->get_selected_products);
                                                            $product_json_check = false;
                                                        } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->get_selected_products)) == true) {
                                                            $product_json_check = false;
                                                        }
                                                    }
                                                    if ($product_json_check == false) {
                                                        if ($field_json[0]->discount_type == 'fixed_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                            }
                                                        } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'] = $product_discount;
                                                                $product_list_values['final_price'] = $product_final_price;
                                                            }
                                                        } else {
                                                            for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                $product_list_values['product_discount_price'];
                                                                $product_list_values['final_price'];
                                                            }
                                                        }
                                                    }
                                                }
                                                if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
                                                    foreach (json_decode($rule->conditions) as $key => $condition_value) {
                                                        if ($condition_value->condition_type == 'cart_total_quantity') {
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_item_count') {
                                                            if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'cart_items_products') {
                                                            $cartProductIds = array_map(function($item) {
                                                                return $item->product_id;
                                                            }, (array)$Carts);

                                                            $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                                                            switch ($condition_value->type) {
                                                                case 'at_least_one':
                                                                    $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                case 'all':
                                                                    // Ensure all selected items are in the cart
                                                                    $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'only':
                                                                    // Ensure all cart items are in selected items and no extra items are present
                                                                    $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                                                                    break;

                                                                case 'none':
                                                                    $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                                                                    break;

                                                                default:
                                                                    $condition_json_check = true;
                                                                    break;
                                                            }

                                                        } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                                                            $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                                                            $selectedProductQty = 0;
                                                            foreach ($Carts as $item) {
                                                                if (in_array($item->product_id, $selectedProductIds)) {
                                                                    $selectedProductQty += (int)$item->qty;
                                                                }
                                                            }
                                                            if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'customer') {
                                                            if(auth('customers')->user()){
                                                                if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                                                                    $condition_json_check = false;
                                                                } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                                                                    $condition_json_check = false;
                                                                }
                                                            }
                                                        } else if ($condition_value->condition_type == 'guest') {
                                                            if(auth('customers')->user()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'is_logged_in') {
                                                            if(auth('customers')->guest()){
                                                                $condition_json_check = false;
                                                            }
                                                        } else if ($condition_value->condition_type == 'date_and_time') {
                                                            $startDateTime = new \DateTime($condition_value->start_date_time);
                                                            $endDateTime = new \DateTime($condition_value->end_date_time);
                                                            $currentDateTime = new \DateTime();

                                                            if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                                                                $condition_json_check = true;
                                                            } else {
                                                                $condition_json_check = false;
                                                            }
                                                        }
                                                        if ($condition_json_check == false) {
                                                            if ($field_json[0]->discount_type == 'fixed_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                                                                    $product_list_values['final_price'] += $field_json[0]->discount_value;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'percentage_discount') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                    $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                                                                }
                                                            } else if ($field_json[0]->discount_type == 'fixed_price') {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'] = $product_discount;
                                                                    $product_list_values['final_price'] = $product_final_price;
                                                                }
                                                            } else {
                                                                for ($i = 0; $i < $QtyDiscount; $i++) {
                                                                    $product_list_values['product_discount_price'];
                                                                    $product_list_values['final_price'];
                                                                }
                                                            }
                                                        }
                                                    }
                                                } else {
                                                    if (isset($product_json_check) && isset($condition_json_check) && $product_json_check == true && $condition_json_check == true){
                                                    }else{
                                                        $product_json_check = false;
                                                        $condition_json_check = false;
                                                    }
                                                }
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = $product_json_check;
                                                $productListUpdatedValue['condition_json_check']    = $condition_json_check;
                                                $productListUpdatedValue['totalQtyDiscount']        = $QtyDiscount;
                                                // $productListUpdatedValue['qty_json']    = ["method_type"=>"buy_x_get_y","totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                $qty_json[]    = ["rule_id"=>$rule->id,"totalQtyDiscount"=>$QtyDiscount,"get_quantity"=>$field_json[0]->get_quantity,"discount_type"=>$field_json[0]->discount_type,"discount_value"=>$field_json[0]->discount_value];
                                                $productListUpdatedValue['qty_json']    = $qty_json;
                                                if($product_json_check == true){
                                                    $apply_conditions[] = $rule->id;
                                                    $productListUpdatedValue['apply_conditions']        = $apply_conditions;
                                                }
                                            }else{
                                                $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                                                $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                                                $productListUpdatedValue['product_json_check']      = true;
                                                $productListUpdatedValue['condition_json_check']    = true;
                                            }

                                        }
                                    }
                                }
                            }
                        } else {
                            $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                            $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                            $productListUpdatedValue['product_json_check']      = true;
                            $productListUpdatedValue['condition_json_check']    = true;
                        }
                        break;

                    default:
                        $productListUpdatedValue['product_discount_price']  = max(0, $product_list_values['product_discount_price']);
                        $productListUpdatedValue['final_price']             = max(0, $product_list_values['final_price']);
                        $productListUpdatedValue['product_json_check']      = true;
                        $productListUpdatedValue['condition_json_check']    = true;
                        break;
                }
            }
        }
        return $productListUpdatedValue;
    }

    public static function priceDesplay($item,$store)
    {
        $admin  = User::find($store->created_by ?? null) ?? null;
        $plan   = Plan::find($admin->plan_id ?? null) ?? null;
        $priceHTML = '';
        if (isset($plan->modules) && strpos($plan->modules, 'ProductPricing') !== false && isset($item->sale_price)) {
            // $insPrice = currency_format_with_sym($item->sale_price/$item->qty, $store->id) ?? SetNumberFormat($item->sale_price/$item->qty);
            // $insFinalPrice = currency_format_with_sym($item->sale_price, $store->id) ?? SetNumberFormat($item->sale_price);
            // $delPrice = currency_format_with_sym($item->final_price/$item->qty, $store->id) ?? SetNumberFormat($item->final_price/$item->qty);
            // $finalPrice = currency_format_with_sym($item->final_price, $store->id) ?? SetNumberFormat($item->final_price);

            // if (isset($delPrice) && isset($insPrice)){
            //     $priceHTML  .=  '<td data-label="Price">
            //                         <del>' . $delPrice . '</del>
            //                         <ins>' . $insPrice . '</ins>
            //                     </td>
            //                     <td data-label="Total">
            //                         ' . $insFinalPrice . '
            //                     </td>';
            // }else{
            //     $priceHTML  .=  '<td data-label="Price">
            //                         ' . $delPrice . '
            //                     </td>
            //                     <td data-label="Total">
            //                         ' . $finalPrice . '
            //                     </td>';
            // }

            // return $priceHTML;
            return [
                'insPrice' => $item->sale_price,
                'delPrice' => $item->final_price,
            ];
        } else {
            return $item->final_price;
        }

    }

    public static function simplePricing($field_json, $cart_value, $product_list_values, $cart_product_data, $rule, $cartTotalQty, $cartTotalItem, $Carts)
    {
        $product_json_check = true;
        $condition_json_check = true;
        if ($field_json[0]->discount_type == 'fixed_discount') {
            for ($i = 0; $i < $cart_value->qty; $i++) {
                $product_list_values['product_discount_price'] += $field_json[0]->discount_value;
                $product_list_values['final_price'] -= $field_json[0]->discount_value;
            }
        } else if ($field_json[0]->discount_type == 'percentage_discount') {
            for ($i = 0; $i < $cart_value->qty; $i++) {
                $product_list_values['product_discount_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                $product_list_values['final_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
            }
        } else if ($field_json[0]->discount_type == 'fixed_price') {
            $product_discount = $product_list_values['product_discount_price'];
            $product_final_price = $product_list_values['final_price'];
            for ($i = 0; $i < $cart_value->qty; $i++) {
                $product_list_values['product_discount_price'] = $cart_product_data->sale_price - $field_json[0]->discount_value;
                $product_list_values['final_price'] = $field_json[0]->discount_value * $cart_value->qty;
            }
        } else {
            for ($i = 0; $i < $cart_value->qty; $i++) {
                $product_list_values['product_discount_price'];
                $product_list_values['final_price'];
            }
        }
        if (!empty($rule->product_json) && (is_array(json_decode($rule->product_json)) || is_object(json_decode($rule->product_json)))) {
            foreach (json_decode($rule->product_json) as $key => $product_json_value) {
                if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == false) {
                    $product_json_check = false;
                } else if ($product_json_value->condition_type == 'product' && $product_json_value->type == 'not_in_list' && in_array($cart_product_data->id,explode(",", $product_json_value->selected_products)) == true) {
                    $product_json_check = false;
                }
            }
            if ($product_json_check == false) {
                if ($field_json[0]->discount_type == 'fixed_discount') {
                    for ($i = 0; $i < $cart_value->qty; $i++) {
                        $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                        $product_list_values['final_price'] += $field_json[0]->discount_value;
                    }
                } else if ($field_json[0]->discount_type == 'percentage_discount') {
                    for ($i = 0; $i < $cart_value->qty; $i++) {
                        $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                        $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                    }
                } else if ($field_json[0]->discount_type == 'fixed_price') {
                    for ($i = 0; $i < $cart_value->qty; $i++) {
                        $product_list_values['product_discount_price'] = $product_discount;
                        $product_list_values['final_price'] = $product_final_price;
                    }
                } else {
                    for ($i = 0; $i < $cart_value->qty; $i++) {
                        $product_list_values['product_discount_price'];
                        $product_list_values['final_price'];
                    }
                }
            }
        }
        if(!empty($rule->conditions) && (is_array(json_decode($rule->conditions)) || is_object(json_decode($rule->conditions)))){
            foreach (json_decode($rule->conditions) as $key => $condition_value) {
                if ($condition_value->condition_type == 'cart_total_quantity') {
                    if($condition_value->type == 'at_least' && $condition_value->quantity > $cartTotalQty){
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $cartTotalQty) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $cartTotalQty) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $cartTotalQty) {
                        $condition_json_check = false;
                    }
                } else if ($condition_value->condition_type == 'cart_item_count') {
                    if($condition_value->type == 'at_least' && $condition_value->item > $cartTotalItem){
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'more_than' && $condition_value->item >= $cartTotalItem) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'not_more_than' && $condition_value->item < $cartTotalItem) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'less_than' && $condition_value->item <= $cartTotalItem) {
                        $condition_json_check = false;
                    }
                } else if ($condition_value->condition_type == 'cart_items_products') {
                    $cartProductIds = array_map(function($item) {
                        return $item->product_id;
                    }, (array)$Carts);

                    $selectedProductIds = explode(',', $condition_value->cart_items_products_selected);

                    switch ($condition_value->type) {
                        case 'at_least_one':
                            $condition_json_check = !empty(array_intersect($cartProductIds, $selectedProductIds));
                            break;

                        case 'all':
                            // Ensure all selected items are in the cart
                            $condition_json_check = empty(array_diff($selectedProductIds, $cartProductIds));
                            break;

                        case 'only':
                            // Ensure all cart items are in selected items and no extra items are present
                            $condition_json_check = empty(array_diff($cartProductIds, $selectedProductIds)) && empty(array_diff($selectedProductIds, $cartProductIds));
                            break;

                        case 'none':
                            $condition_json_check = empty(array_intersect($cartProductIds, $selectedProductIds));
                            break;

                        default:
                            $condition_json_check = true;
                            break;
                    }

                } else if ($condition_value->condition_type == 'cart_item_quantity_products') {
                    $selectedProductIds = explode(',', $condition_value->cart_items_quantity_products_selected);
                    $selectedProductQty = 0;
                    foreach ($Carts as $item) {
                        if (in_array($item->product_id, $selectedProductIds)) {
                            $selectedProductQty += (int)$item->qty;
                        }
                    }
                    if($condition_value->type == 'at_least' && $condition_value->quantity > $selectedProductQty){
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'more_than' && $condition_value->quantity >= $selectedProductQty) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'not_more_than' && $condition_value->quantity < $selectedProductQty) {
                        $condition_json_check = false;
                    } else if ($condition_value->type == 'less_than' && $condition_value->quantity <= $selectedProductQty) {
                        $condition_json_check = false;
                    }
                } else if ($condition_value->condition_type == 'customer') {
                    if(auth('customers')->user()){
                        if ($condition_value->type == 'in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == false) {
                            $condition_json_check = false;
                        } else if ($condition_value->type == 'not_in_list' && in_array(auth('customers')->user()->id,explode(",", $condition_value->condition_customers)) == true) {
                            $condition_json_check = false;
                        }
                    }
                } else if ($condition_value->condition_type == 'guest') {
                    if(auth('customers')->user()){
                        $condition_json_check = false;
                    }
                } else if ($condition_value->condition_type == 'is_logged_in') {
                    if(auth('customers')->guest()){
                        $condition_json_check = false;
                    }
                } else if ($condition_value->condition_type == 'date_and_time') {
                    $startDateTime = new \DateTime($condition_value->start_date_time);
                    $endDateTime = new \DateTime($condition_value->end_date_time);
                    $currentDateTime = new \DateTime();

                    if($currentDateTime >= $startDateTime && $currentDateTime <= $endDateTime){
                        $condition_json_check = true;
                    } else {
                        $condition_json_check = false;
                    }
                }
                if ($condition_json_check == false) {
                    if ($field_json[0]->discount_type == 'fixed_discount') {
                        for ($i = 0; $i < $cart_value->qty; $i++) {
                            $product_list_values['product_discount_price'] -= $field_json[0]->discount_value;
                            $product_list_values['final_price'] += $field_json[0]->discount_value;
                        }
                    } else if ($field_json[0]->discount_type == 'percentage_discount') {
                        for ($i = 0; $i < $cart_value->qty; $i++) {
                            $product_list_values['product_discount_price'] -= $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                            $product_list_values['final_price'] += $cart_product_data->sale_price * $field_json[0]->discount_value / 100;
                        }
                    } else if ($field_json[0]->discount_type == 'fixed_price') {
                        for ($i = 0; $i < $cart_value->qty; $i++) {
                            $product_list_values['product_discount_price'] = $product_discount;
                            $product_list_values['final_price'] = $product_final_price;
                        }
                    } else {
                        for ($i = 0; $i < $cart_value->qty; $i++) {
                            $product_list_values['product_discount_price'];
                            $product_list_values['final_price'];
                        }
                    }
                }
            }
        }

        return [
            'product_discount_price' => max(0, $product_list_values['product_discount_price']),
            'final_price'            => max(0, $product_list_values['final_price']),
            'product_json_check'     => $product_json_check,
            'condition_json_check'   => $condition_json_check,
        ];
    }

}
