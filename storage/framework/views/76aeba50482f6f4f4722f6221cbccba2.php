<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductPricing')): ?>
    <?php
        $priceHTML = \Workdo\ProductPricing\app\Models\ProductPricingRule::priceDesplay($item, $store);
        $without_discount_price = $item->final_price;
    ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($item->qty_json)): ?>
        <?php
            $discountPrice = 0;
            $discountCheck = 0;
            $other_discountPrice = 0;
            foreach ($item->qty_json as $key => $item->qty_jsons) {
                if (isset($item->qty_jsons->rule_id)) {
                    $productPricingRules = \Workdo\ProductPricing\app\Models\ProductPricingRule::where(
                        'store_id',
                        $store->id,
                    )
                        ->get();
                    if ($productPricingRules->isNotEmpty()) {
                        foreach ($productPricingRules as $key => $rule) {
                            if ($rule->id == $item->qty_jsons->rule_id && $rule->pricing_method == 'buy_x_get_x') {
                                if (
                                    is_array(json_decode($rule->field_json)) ||
                                    is_object(json_decode($rule->field_json))
                                ) {
                                    $field_json = json_decode($rule->field_json);
                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                        $discountPrice += $field_json[0]->discount_value;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->get_quantity;
                                        $discount_qty = $item->qty_jsons->get_quantity;
                                    } elseif ($field_json[0]->discount_type == 'percentage_discount') {
                                        $discountPrice +=
                                            (($item->final_price / $item->qty) * $field_json[0]->discount_value) / 100;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->get_quantity;
                                        $discount_qty = $item->qty_jsons->get_quantity;
                                    } elseif ($field_json[0]->discount_type == 'fixed_price') {
                                        $discountPrice =
                                            $priceHTML['delPrice'] / $item->qty - $field_json[0]->discount_value;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->get_quantity;
                                        $discount_qty = $item->qty_jsons->get_quantity;
                                    }
                                }
                                if (isset($item->apply_conditions) && $discountCheck == 0) {
                                    $modifiedApplyConditions = array_diff($item->apply_conditions, [
                                        $item->qty_jsons->rule_id,
                                    ]);
                                    if (isset($modifiedApplyConditions)) {
                                        foreach ($modifiedApplyConditions as $condition) {
                                            $other_rule = \Workdo\ProductPricing\app\Models\ProductPricingRule::find(
                                                $condition,
                                            );
                                            if (isset($other_rule) && $other_rule->pricing_method != 'buy_x_get_x') {
                                                if (
                                                    is_array(json_decode($other_rule->field_json)) ||
                                                    is_object(json_decode($other_rule->field_json))
                                                ) {
                                                    $other_rule_field_json = json_decode($other_rule->field_json);
                                                    if ($other_rule_field_json[0]->discount_type == 'fixed_discount') {
                                                        $discountPrice += $other_rule_field_json[0]->discount_value;
                                                        $discountCheck = 1;
                                                        $other_discountPrice +=
                                                            $other_rule_field_json[0]->discount_value;
                                                    } elseif (
                                                        $other_rule_field_json[0]->discount_type ==
                                                        'percentage_discount'
                                                    ) {
                                                        $discountPrice +=
                                                            (($item->final_price / $item->qty) *
                                                                $field_json[0]->discount_value) /
                                                            100;
                                                        $discountCheck = 1;
                                                        $other_discountPrice +=
                                                            (($without_discount_price / $item->qty) *
                                                                $other_rule_field_json[0]->discount_value) /
                                                            100;
                                                    } elseif (
                                                        $other_rule_field_json[0]->discount_type == 'fixed_price'
                                                    ) {
                                                        $discountPrice =
                                                            $priceHTML['delPrice'] / $item->qty -
                                                            $field_json[0]->discount_value;
                                                        $discountCheck = 1;
                                                        $other_discountPrice =
                                                            $without_discount_price / $item->qty -
                                                            $other_rule_field_json[0]->discount_value;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } elseif (
                                $rule->id == $item->qty_jsons->rule_id &&
                                $rule->pricing_method == 'buy_x_get_y'
                            ) {
                                if (
                                    is_array(json_decode($rule->field_json)) ||
                                    is_object(json_decode($rule->field_json))
                                ) {
                                    $field_json = json_decode($rule->field_json);
                                    if ($field_json[0]->discount_type == 'fixed_discount') {
                                        $discountPrice += $field_json[0]->discount_value;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->totalQtyDiscount;
                                        $discount_qty = $item->qty_jsons->totalQtyDiscount;
                                    } elseif ($field_json[0]->discount_type == 'percentage_discount') {
                                        $discountPrice +=
                                            (($item->final_price / $item->qty) * $field_json[0]->discount_value) / 100;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->totalQtyDiscount;
                                        $discount_qty = $item->qty_jsons->totalQtyDiscount;
                                    } elseif ($field_json[0]->discount_type == 'fixed_price') {
                                        $discountPrice =
                                            $priceHTML['delPrice'] / $item->qty - $field_json[0]->discount_value;
                                        $buy_x_qty = $item->qty - $item->qty_jsons->totalQtyDiscount;
                                        $discount_qty = $item->qty_jsons->totalQtyDiscount;
                                    }
                                }
                                if (isset($item->apply_conditions) && $discountCheck == 0) {
                                    $modifiedApplyConditions = array_diff($item->apply_conditions, [
                                        $item->qty_jsons->rule_id,
                                    ]);
                                    if (isset($modifiedApplyConditions)) {
                                        foreach ($modifiedApplyConditions as $condition) {
                                            $other_rule = \Workdo\ProductPricing\app\Models\ProductPricingRule::find(
                                                $condition,
                                            );
                                            if (isset($other_rule) && $other_rule->pricing_method != 'buy_x_get_y') {
                                                if (
                                                    is_array(json_decode($other_rule->field_json)) ||
                                                    is_object(json_decode($other_rule->field_json))
                                                ) {
                                                    $other_rule_field_json = json_decode($other_rule->field_json);
                                                    if ($other_rule_field_json[0]->discount_type == 'fixed_discount') {
                                                        $discountPrice += $other_rule_field_json[0]->discount_value;
                                                        $discountCheck = 1;
                                                        $other_discountPrice +=
                                                            $other_rule_field_json[0]->discount_value;
                                                    } elseif (
                                                        $other_rule_field_json[0]->discount_type ==
                                                        'percentage_discount'
                                                    ) {
                                                        $discountPrice +=
                                                            (($item->final_price / $item->qty) *
                                                                $field_json[0]->discount_value) /
                                                            100;
                                                        $discountCheck = 1;
                                                        $other_discountPrice +=
                                                            (($without_discount_price / $item->qty) *
                                                                $other_rule_field_json[0]->discount_value) /
                                                            100;
                                                    } elseif (
                                                        $other_rule_field_json[0]->discount_type == 'fixed_price'
                                                    ) {
                                                        $discountPrice =
                                                            $priceHTML['delPrice'] / $item->qty -
                                                            $field_json[0]->discount_value;
                                                        $discountCheck = 1;
                                                        $other_discountPrice =
                                                            $without_discount_price / $item->qty -
                                                            $other_rule_field_json[0]->discount_value;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $buy_x_price = $priceHTML['delPrice'] / $item->qty - $discountPrice;
            $other_discount_price = $without_discount_price / $item->qty - $other_discountPrice;
        ?>
        <td class="py-4 px-4 text-center" data-label="Price">
            <span class="font-semibold">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($buy_x_qty) && $buy_x_qty != 0): ?>
                <del><?php echo e(currency_format_with_sym($priceHTML['delPrice'] / $item->qty, $store->id) ?? SetNumberFormat($priceHTML['delPrice'] / $item->qty)); ?></del>
                <ins><?php echo e(currency_format_with_sym(isset($other_discount_price) ? $other_discount_price : $without_discount_price / $item->qty, $store->id) ?? SetNumberFormat($priceHTML['insPrice'] / $item->qty)); ?></ins>
                <br>
                <ins> X <?php echo e($buy_x_qty); ?></ins>
                <br>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <del><?php echo e(currency_format_with_sym($priceHTML['delPrice'] / $item->qty, $store->id) ?? SetNumberFormat($priceHTML['delPrice'] / $item->qty)); ?></del>
            <ins><?php echo e(currency_format_with_sym($buy_x_price, $store->id) ?? SetNumberFormat($buy_x_price)); ?></ins>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($buy_x_qty) && $buy_x_qty != 0): ?>
                <br>
                <ins> X <?php echo e($discount_qty); ?></ins>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </span>
        </td>
        <td class="py-4 px-4 text-center" data-label="Total">
            <div class="font-bold text-primary-dark">
            <?php echo e(currency_format_with_sym($priceHTML['insPrice'], $store->id) ?? SetNumberFormat($priceHTML['insPrice'])); ?>

            </div>
        </td>
    <?php else: ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($priceHTML['delPrice']) && isset($priceHTML['insPrice'])): ?>
            <td class="py-4 px-4 text-center" data-label="Price">
                <span class="font-semibold">
                <del><?php echo e(currency_format_with_sym($priceHTML['delPrice'] / $item->qty, $store->id) ?? SetNumberFormat($priceHTML['delPrice'] / $item->qty)); ?></del>
                <ins><?php echo e(currency_format_with_sym($priceHTML['insPrice'] / $item->qty, $store->id) ?? SetNumberFormat($priceHTML['insPrice'] / $item->qty)); ?></ins>
                </span>
            </td>
            <td class="py-4 px-4 text-center" data-label="Total">
                <div class="font-bold text-primary-dark">
                <?php echo e(currency_format_with_sym($priceHTML['insPrice'], $store->id) ?? SetNumberFormat($priceHTML['insPrice'])); ?>

                </div>
            </td>
        <?php else: ?>
            <td class="py-4 px-4 text-center" data-label="Price">
                <span class="font-semibold">
                <?php echo e(currency_format_with_sym($priceHTML / $item->qty, $store->id) ?? SetNumberFormat($priceHTML / $item->qty)); ?>

                </span>
            </td>
            <td class="py-4 px-4 text-center" data-label="Total">
                <div class="font-bold text-primary-dark">
                <?php echo e(currency_format_with_sym($priceHTML, $store->id) ?? SetNumberFormat($priceHTML)); ?>

                </div>
            </td>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php else: ?>
    <td class="py-4 px-4 text-center" data-label="Price">
        <span class="font-semibold">
        <?php echo e(currency_format_with_sym($item->final_price / $item->qty, $store->id) ?? SetNumberFormat($item->final_price / $item->qty)); ?>

        </span>
    </td>
    <td class="py-4 px-4 text-center" data-label="Total">
        <div class="font-bold text-primary-dark">
        <?php echo e(currency_format_with_sym($item->final_price, $store->id) ?? SetNumberFormat($item->final_price)); ?>

        </div>
    </td>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/resources/views/front_end/hooks/manage_cartlist_price.blade.php ENDPATH**/ ?>