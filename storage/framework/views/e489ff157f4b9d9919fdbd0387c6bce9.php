<div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm sticky top-[20px]">
    <h2 class="font-bold text-xl mb-6"><?php echo e(__('Order Summary')); ?>: <span class="checkout-cartcount">[<?php echo e($response->data->cart_total_product); ?>]</span></h2>

    <!-- Order Items -->
    <div class="space-y-4 mb-6">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($response->data->cart_total_product)): ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $response->data->product_list; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="flex items-start gap-3 pb-3 border-b">
                    <input type="hidden" id="product_id" value="<?php echo e($product->product_id); ?>" >
                    <input type="hidden" id="product_qty" value="<?php echo e($product->qty); ?>">
                    <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                        <a href="<?php echo e(route('page.product', [$slug, getProductSlug($product->product_id)])); ?>">
                            <img src="<?php echo e(get_file($product->image)); ?>" alt="cart-image" class="h-full w-full object-contain rounded-lg">
                        </a>
                    </div>
                    <div class="flex-grow">
                        <h3 class="font-medium mb-1"><a href="<?php echo e(route('page.product', [$slug, getProductSlug($product->product_id)])); ?>"><?php echo e($product->name); ?></a></h3>
                        <p class="text-sm text-gray-500">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_id != 0): ?>
                            <?php echo \App\Models\ProductVariant::variantlist($product->variant_id); ?>

                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </p>
                        <p class="text-sm text-gray-500"><?php echo e($product->qty . ' x ' . currency_format_with_sym($product->final_price / $product->qty, $store->id)); ?></p>
                    </div>
                    <div class="price">
                        <?php echo \App\Models\Product::ManageCheckoutPrice($product, $store); ?>

                    </div>
                </div>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php else: ?>
            <div class="flex items-start gap-3 pb-3 border-b">
                <?php echo e(__('You have no items in your shopping cart.')); ?>

            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <!-- Shipping -->
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($plan) && $plan->shipping_method == 'on'): ?>
        <div class="pb-4 mb-4 border-b">
            <div class="flex">
                

                <div class="shiping-type">
                    <h5 id="shipping_lable" class="d-none font-semibold mb-4 text-lg"><?php echo e(__('Select Shipping')); ?></h5>
                    <div class="radio-group change_shipping space-y-3" id="shipping_location_content" >

                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <!-- Coupon Code -->
    <div class="pb-4 mb-4 border-b">
        <div class="flex">
            <input class="p-2 form-input ltr:rounded-tr-none rtl:rounded-tl-none  ltr:rounded-br-none rtl:rounded-bl-none coupon_code" placeholder="<?php echo e(__('Enter coupon code')); ?>" name="coupon" type="text" value="">
            <a class="bg-primary hover:bg-primary-dark text-white px-4 py-2 ltr:rounded-r-md rtl:rounded-l-md transition checkout-btn apply_coupon">
                <?php echo e(__('Apply')); ?>

            </a>
        </div>
    </div>

    <!-- Order Totals -->
    <div class="space-y-2 mb-6">
        <div class="flex justify-between">
            <span><?php echo e(__('Subtotal')); ?></span>
            <input type="hidden" value="<?php echo e($response->data->final_price ?? ($response->data->sub_total ?? 0)); ?>" id="subtotal">
            <span class="font-semibold subtotal"><?php echo e(currency_format_with_sym(($response->data->final_price ?? ($response->data->sub_total ?? 0)), $store->id) ?? SetNumberFormat($response->data->final_price ?? ($response->data->sub_total ?? 0))); ?></span>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($plan) && $plan->shipping_method == 'on'): ?>
            <div class="flex justify-between">
                <span><?php echo e(__('Shipping')); ?></span>
                <span class="CURRENCY"></span> <span class="font-semibold shipping_final_price shipping_test_price"> <?php echo e(currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0)); ?> </span>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php echo $__env->yieldPushContent('clubPointPriceShow'); ?>
        <input type="hidden" value="<?php echo e($response->data->total_coupon_price ?? 0); ?>" id="coupon_amount">
        <div class="flex justify-between">
            <span><?php echo e(__('Coupon')); ?></span>
            <span class="font-semibold discount_amount_currency"> - <?php echo e(currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_coupon_price ?? 0)); ?> </span>
        </div>
        <div class="flex justify-between tax-line">
            <span><?php echo e(__('Tax')); ?></span>
            <span <?php if(isset($plan) && $plan->shipping_method == 'on'): ?> class="CURRENCY" <?php else: ?> class="" <?php endif; ?>></span>
            <span class="font-semibold final_tax_price"> <?php echo e(currency_format_with_sym(($response->data->tax_price ?? 0), $store->id) ?? SetNumberFormat($response->data->tax_price ?? 0)); ?> </span>
        </div>
        <div class="flex justify-between text-lg font-bold pt-2 border-t">
            <span><?php echo e(__('Total')); ?></span>
            <span class="text-primary-dark final_amount_currency shipping_total_price" final_total="<?php echo e($response->data->total_sub_price); ?>"><?php echo e(currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_sub_price)); ?></span>
        </div>
    </div>

    <?php echo $__env->make('front_end.hooks.checkout_list', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <!-- Terms -->
    

    <!-- Checkout Button -->
    
    <input type="hidden" class="method_id" id="method_id" name="method_id" value="<?php echo e(old('method_id')); ?>">
    <button class="btn-primary w-full continue-btn place_order_submit payfast_form" id="payfast_form" type="submit">
        <?php echo e($themeSettings['checkout_button'] ?? __('Complete Order')); ?>

    </button>

    <!-- Security Information -->
    
</div><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/checkout-cart-list.blade.php ENDPATH**/ ?>