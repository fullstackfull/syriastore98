<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($merged_products->isNotEmpty()): ?>
<section class="py-10 lg:py-20 bg-gray-50 relative">
    <div class="md:container w-full mx-auto px-4">
        <div class="text-center mb-8 lg:mb-12">
            <h2 class="text-2xl lg:text-3xl font-bold text-gray-900 mb-3">
                <?php echo e(__('Frequently bought together')); ?>

            </h2>
            <p class="text-gray-600 max-w-2xl mx-auto">
                <?php echo e(__('Customers who bought these items together saved more')); ?>

            </p>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6 lg:p-8">
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8 items-start">
                <!-- Products Grid -->
                <div class="lg:col-span-3">
                    <div class="flex flex-wrap items-center justify-center">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $merged_products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->variant_product != 0): ?>
                        <?php continue; ?>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <div class="flex items-center">
                                <div class="relative group">
                                    <div class="bg-white rounded-xl border-2 border-gray-100 hover:border-primary/30 overflow-hidden hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
                                        <!-- Checkbox -->
                                        <div class="absolute top-3 left-3 z-20">
                                            <div class="checkbox-custom">
                                                <input type="checkbox" class="frequently-value" 
                                                       id="checkbox_<?php echo e($product->id); ?>" 
                                                       product_id="<?php echo e($product->id); ?>" 
                                                       product_selected="1" 
                                                       value="<?php echo e($product->id); ?>" 
                                                       checked>
                                                <label for="checkbox_<?php echo e($product->id); ?>" class="w-6 h-6 bg-white border-2 border-gray-300 rounded-md flex items-center justify-center cursor-pointer hover:border-primary transition-all duration-200 shadow-sm">
                                                    <svg class="w-4 h-4 text-white opacity-0" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                                    </svg>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <!-- Product Card -->
                                        <div class="w-80 sm:w-84">
                                            <?php if (isset($component)) { $__componentOriginal3fd2897c1d6a149cdb97b41db9ff827a = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3fd2897c1d6a149cdb97b41db9ff827a = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.product-card','data' => ['store' => $store,'product' => $product]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('product-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['store' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($store),'product' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($product)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3fd2897c1d6a149cdb97b41db9ff827a)): ?>
<?php $attributes = $__attributesOriginal3fd2897c1d6a149cdb97b41db9ff827a; ?>
<?php unset($__attributesOriginal3fd2897c1d6a149cdb97b41db9ff827a); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3fd2897c1d6a149cdb97b41db9ff827a)): ?>
<?php $component = $__componentOriginal3fd2897c1d6a149cdb97b41db9ff827a; ?>
<?php unset($__componentOriginal3fd2897c1d6a149cdb97b41db9ff827a); ?>
<?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Plus Icon -->
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$loop->last): ?>
                                    <div class="hidden lg:flex mx-6 w-12 h-12 bg-primary text-white rounded-full items-center justify-center shadow-lg border-2 border-white hover:scale-110 transition-transform duration-200">
                                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                        </svg>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            
                <!-- Total Section -->
                <div class="lg:col-span-1">
                    <div class="bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border-2 border-primary/20 p-6 sticky top-4">
                        <div class="space-y-6">
                            <div class="text-center">
                                <div class="inline-flex items-center justify-center w-12 h-12 bg-primary/10 rounded-full mb-3">
                                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                                    </svg>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 mb-1"><?php echo e(__('Bundle Deal')); ?></h3>
                                <p class="text-sm text-gray-600"><?php echo e(__('Save when you buy together')); ?></p>
                            </div>
                            
                            <div class="hidden">
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span><?php echo e(__('Subtotal')); ?>:</span>
                                    <span><?php echo e($currency); ?> <span id="products_subtotal_frequently" class="subtotal_frequently"><?php echo e($total_price); ?></span></span>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span><?php echo e(__('Tax')); ?>:</span>
                                    <span><?php echo e($currency); ?> <span id="products_tax_product" class="tax_product"><?php echo e($tax_price); ?></span></span>
                                </div>
                            </div>
                            
                            <div class="border-t border-primary/20 pt-4">
                                <div class="flex justify-between items-center mb-6">
                                    <span class="text-lg font-semibold text-gray-900"><?php echo e(__('Total Price:')); ?></span>
                                    <span class="text-2xl font-bold text-primary">
                                        <?php echo e($currency); ?> <span id="products_total_price" class="total_fre_price"><?php echo e($total_price); ?></span>
                                    </span>
                                </div>
                                
                                <button class="w-full bg-primary hover:bg-primary/90 text-white font-bold py-4 px-6 rounded-xl transition-all duration-200 flex items-center justify-center gap-3 frequently_products shadow-lg hover:shadow-xl transform hover:-translate-y-0.5" 
                                        product_id="<?php echo e(implode(',', $product_ids)); ?>" 
                                        variant_id="0" 
                                        qty="1">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4m0 0L7 13m0 0l-1.5 6M7 13l-1.5-6m0 0h15M17 21a2 2 0 100-4 2 2 0 000 4zM9 21a2 2 0 100-4 2 2 0 000 4z"></path>
                                    </svg>
                                    <?php echo e(__('Add Bundle to Cart')); ?>

                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</section>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<style>
.checkbox-custom input[type="checkbox"] {
    display: none;
}

.checkbox-custom input[type="checkbox"]:checked + label {
    background-color: var(--primary-color);
    border-color: var(--primary-color);
    transform: scale(1.05);
    box-shadow: 0 0 0 3px rgba(var(--primary-color-rgb, 59, 130, 246), 0.1);
}

.checkbox-custom input[type="checkbox"]:checked + label svg {
    opacity: 1;
    color: white;
}

.checkbox-custom label {
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.checkbox-custom label:hover {
    border-color: var(--primary-color);
    transform: scale(1.02);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.frequently-product-card {
    transition: all 0.3s ease;
}

.frequently-product-card:hover {
    transform: translateY(-2px);
}
</style>

<?php $__env->startPush('page-script'); ?>
<script>
$(document).on('click', '.frequently_products', function(e) {
    var product_ids = $(this).attr('product_id').split(',');
    var product_id = $('.frequently-value').attr('product_id');
    var variant_id = $(this).attr('variant_id');
    var enable_product = [];
    $('.frequently-value:checked').each(function() {
        enable_product.push($(this).val());
    });
    var qty = $(this).attr('qty');
    var data = {
        product_ids: product_ids,
        variant_id: variant_id,
        enable_product: enable_product,
        qty: qty,
    };
    $.ajax({
        url: '<?php echo e(route('cart.product-data',$store->slug)); ?>',
        method: 'POST',
        data: {
            ...data,
            _token: '<?php echo e(csrf_token()); ?>'
        },
        success: function(response) {
            $('#loader').fadeOut();
            if(response.status == 1) {
                show_toastr('Success', response.data.message, 'success');
                get_cartlist();
                $('.cart-header .count').html(response.data.count || 0);
            } else {
                show_toastr('Error', response.data.message || 'Failed to add products to cart', 'error');
            }
        },
        error: function(xhr, status, error) {
            $('#loader').fadeOut();
            show_toastr('Error', 'Failed to add products to cart', 'error');
            console.error('AJAX Error:', error, xhr.responseText);
        }
    });
});

$(document).ready(function() {
    $('.frequently-value').on('change', function() {
        var product_id = $(this).attr('product_id');
        var product_selected = $(this).attr('product_selected');
        var price = $('.subtotal_frequently').text();
        var tax = $('.tax_product').text();
        var data = {
            product_id: product_id,
            product_selected: product_selected,
            price: price,
            tax: tax,
        };

        $.ajax({
            url: '<?php echo e(route('get.sale.price',$store->slug)); ?>',
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                $('#products_subtotal_frequently').text(response.subtotal);
                $('#products_tax_product').text(response.total_tax);
                $('#products_total_price').text(response.subtotal);
                if(product_selected == 1){
                    $('#checkbox_'+product_id).attr("product_selected","0");
                }else{
                    $('#checkbox_'+product_id).attr("product_selected","1");
                }
            }
        });
    });
});
</script>
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/packages/workdo/FrequentlyProduct/src/app/Providers/../../resources/views/pages/frequently_product_slider.blade.php ENDPATH**/ ?>