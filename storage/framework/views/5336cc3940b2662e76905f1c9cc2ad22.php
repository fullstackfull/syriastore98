<?php
$is_checkout_login_required = \App\Models\Utility::GetValueByName('is_checkout_login_required', $store->id);
?>

<div class="md:container w-full mx-auto px-4">

    <div class="flex flex-col lg:flex-row gap-8">
        <!-- Left Side - Cart Items -->
        <div class="lg:w-2/3">
            <!-- Cart Header (Desktop Only) -->
            <div class="overflow-x-auto border border-gray-200 rounded-md">
                <table class="md:w-full min-w-[650px] border-collapse">
                    <thead>
                        <th class="py-3 px-4 text-left rtl:text-right font-semibold text-white bg-primary rounded-st-md">
                            <?php echo e(__('Product')); ?></th>
                        <?php echo $__env->make('front_end.hooks.cart_table_head', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary"><?php echo e(__('Quantity')); ?></th>
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary"><?php echo e(__('Price')); ?></th>                            
                        <th class="py-3 px-4 text-center font-semibold text-white bg-primary rounded-et-md">
                                <?php echo e(__('Subtotal')); ?></th>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($response->data->cart_total_product)): ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $response->data->product_list; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-4">
                                    <button class="text-gray-500 hover:text-red-500 transition-all duration-300 remove_item_from_cart text-red-600"
                                        title="Remove item" data-id="<?php echo e($product->cart_id); ?>" data-product-id = "<?php echo e($product->product_id); ?>"  data-variant-id = "<?php echo e($product->variant_id); ?>">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                            viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                            stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5">
                                            <path d="M3 6h18"></path>
                                            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"></path>
                                            <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                            <line x1="10" x2="10" y1="11" y2="17"></line>
                                            <line x1="14" x2="14" y1="11" y2="17"></line>
                                        </svg>
                                    </button>
                                    <div class="h-20 w-20 flex-shrink-0 border bg-gray-100 rounded-lg">
                                        <img src="<?php echo e(get_file($product->image)); ?>" alt="cart-image"
                                            class="h-full w-full object-contain rounded-lg" />
                                    </div>
                                    <div>
                                        <h3 class="font-medium mb-1">
                                            <a href="<?php echo e(url($slug.'/product/'. getProductSlug($product->product_id))); ?>"
                                                class="hover:text-primary transition-all duration-300"><?php echo e($product->name); ?></a>
                                        </h3>
                                        <p class="text-sm text-gray-500">
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_id != 0): ?>
                                            <?php echo \App\Models\ProductVariant::variantlist($product->variant_id); ?>

                                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <?php echo $__env->make('front_end.hooks.cart_table_body', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            <td class="py-4 px-4 text-center">
                                <div class="col-span-2 flex items-center justify-between md:justify-center">
                                    <div class="flex items-center border rounded-md text-sm">
                                        <button class="px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm quantity-decrement change-cart-globaly" cart-id="<?php echo e($product->cart_id); ?>" quantity_type="decrease" data-product-id = "<?php echo e($product->product_id); ?>" data-variant-id = "<?php echo e($product->variant_id); ?>">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="text" value="<?php echo e($product->qty); ?>" min="01" id="cart_list_quantity<?php echo e($product->qty); ?>" class="w-8 text-center p-1 focus:ring-0 border-s border-e outline-none quantity" />
                                        <button class="px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm quantity-increment change-cart-globaly" cart-id="<?php echo e($product->cart_id); ?>" quantity_type="increase" data-product-id = "<?php echo e($product->product_id); ?>" data-variant-id = "<?php echo e($product->variant_id); ?>">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </td>
                            <?php echo \App\Models\Product::ManageCartListPrice($product, $store); ?>

                        </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="100%" class="text-center py-4 text-gray-500">
                                    <?php echo e(__('You have no items in your shopping cart.')); ?>

                                </td>
                            </tr>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Cart Actions -->
            <div class="flex flex-wrap gap-4 justify-between md:mt-8 mt-6">
                <a href="<?php echo e(route('page.product-list', $store->slug)); ?>"
                    class="inline-flex gap-2 items-center text-primary hover:text-primary-dark continue-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="h-4 w-4">
                        <path d="m15 18-6-6 6-6" />
                    </svg>
                    <?php echo e($themeSettings['cart_product_button_text'] ?? __('Continue Shopping')); ?>

                </a>
                <button class="inline-flex gap-2 items-center text-gray-600 hover:text-red-500 transition-all duration-300 empty_cart">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        class="h-4 w-4">
                        <path d="M3 6h18" />
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                    </svg>
                    <?php echo e($themeSettings['cart_clear_button_text'] ?? __('Clear Cart')); ?>

                </button>
            </div>
        </div>

        <!-- Right Side - Cart Summary -->
        <div class="lg:w-1/3">
            <div class="bg-gray-50 md:p-6 p-4 rounded-md border">
                <h2 class="font-heading font-bold text-xl md:mb-6 mb-4">
                    <?php echo e($themeSettings['cart_summary'] ?? __('Order Summary')); ?></h2>

                <!-- Summary Items -->
                <div class="space-y-3 mb-6">
                    <div class="flex justify-between">
                        <span><?php echo e($themeSettings['cart_subtotal'] ?? __('Subtotal')); ?></span>
                        <span class="font-semibold"><?php echo e(currency_format_with_sym(($response->data->final_price ?? 0) , $store->id) ?? SetNumberFormat($response->data->final_price ?? ($response->data->sub_total ?? 0))); ?></span>
                    </div>
                    
                    <?php
                        $tax_option = \App\Models\TaxOption::where('store_id', $store->id)->pluck('value', 'name')->toArray();
                        $is_inclusive_tax = isset($tax_option['price_type']) && $tax_option['price_type'] == 'inclusive' && isset($tax_option['shop_price']) && $tax_option['shop_price'] == 'including';
                    ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($response->data->tax_price ?? 0) > 0 || $is_inclusive_tax): ?>
                    <div class="flex justify-between">
                        <span><?php echo e($themeSettings['cart_tax'] ?? __('Tax')); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($is_inclusive_tax): ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></span>
                        <span class="font-semibold"><?php echo e(currency_format_with_sym(($response->data->tax_price ?? 0) , $store->id) ?? SetNumberFormat($response->data->tax_price)); ?></span>
                    </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php
                        $final = $response->data->sub_total+$response->data->tax_price;
                    ?>
                    <div class="pt-3 border-t border-gray-200 flex justify-between font-bold text-lg">
                        <span><?php echo e($themeSettings['cart_total'] ?? __('Total')); ?></span>
                        <span class="text-primary-dark"><?php echo e(currency_format_with_sym(($final ?? 0) , $store->id) ?? SetNumberFormat($final)); ?></span>
                    </div>
                </div>

                <!-- Coupon Code -->
                

                 <!-- Checkout Button -->
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($is_checkout_login_required == 'on' && !auth('customers')->user()): ?>
                <a href="<?php echo e(route('customer.login', $store->slug)); ?>" class="btn-primary w-full">
                    <?php echo e($themeSettings['cart_checkout'] ?? __('Proceed to Checkout')); ?>

                </a>
                <?php else: ?>
                <!-- Checkout Button -->
                <a href="<?php echo e(route('checkout', $slug)); ?>" class="btn-primary w-full">
                    <?php echo e($themeSettings['cart_checkout'] ?? __('Proceed to Checkout')); ?>

                </a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </div>
</div><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/cart-list.blade.php ENDPATH**/ ?>