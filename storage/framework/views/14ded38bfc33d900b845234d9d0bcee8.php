<?php $__env->startSection('page-title'); ?>
    <?php echo e(__('Products')); ?>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($themeSettings['product_page_status'] && $themeSettings['product_page_status'] == '1'): ?>

    <section class="lg:pt-20 pt-10 bg-gray-50">
        <div class="md:container w-full mx-auto px-4">
            <div class="bg-white rounded-lg shadow-sm overflow-hidden">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 p-4 md:p-8">
                    <!-- Product Images -->
                     <div>
                        <!-- Main Image -->
                        <div class="swiper main-image-slider mb-4">
                            <div class="swiper-wrapper">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($product->Sub_image($product->id)['data'])): ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $product->Sub_image($product->id)['data']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="<?php echo e(get_file($item->image_path)); ?>" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php else: ?>
                                    <div class="swiper-slide">
                                        <div class="relative pt-[65%] border bg-gray-100 rounded-lg overflow-hidden">
                                        <img src="<?php echo e(get_file($product->cover_image_path)); ?>" alt="Organic Vegetable Box"
                                            class="w-full h-full absolute top-0 left-0 object-contain" />
                                        </div>
                                    </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <!-- Add Navigation -->
                            <div class="arrow-wrapper">
                            <div class="swiper-button-next pdp-arrow">
                            </div>
                            <div class="swiper-button-prev pdp-arrow">
                            </div>
                            </div>
                        </div>
                        <!-- Thumbnail Images -->
                        <div class="swiper thumbnail-slider">
                            <div class="swiper-wrapper">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($product->Sub_image($product->id)['data'])): ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $product->Sub_image($product->id)['data']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="swiper-slide">
                                        <div
                                        class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                        <img src="<?php echo e(get_file($item->image_path)); ?>" alt="Organic Vegetable Box - View 1"
                                            class="absolute top-0 left-0 w-full h-full object-contain" />
                                        </div>
                                    </div>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php else: ?>
                                <div class="swiper-slide">
                                    <div class="relative pt-[90%] thumb-image rounded-lg overflow-hidden border bg-gray-100 cursor-pointer">
                                    <img src="<?php echo e(get_file($product->cover_image_path)); ?>" alt="Organic Vegetable Box - View 2"
                                        class="absolute top-0 left-0 w-full h-full object-contain" />
                                    </div>
                                </div>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            
                            
                            </div>
                        </div>
                    </div>
                    <!-- Product Info -->
                    <div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($product->label) && !empty($product->label)): ?>
                            <div class="flex items-center mb-3">
                                <span class="bg-primary/10 text-primary text-xs font-bold px-2 py-1 rounded-md mr-2">
                                    <?php echo e(ucfirst(optional($product->label)->name ?? '')); ?>

                                </span>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <h2 class="font-bold text-2xl md:text-3xl mb-2"><?php echo e($product->name); ?></h2>

                        

                        <div class="md:mb-6 mb-4">
                            <div class="flex items-baseline md:mb-4 mb-2">
                                <span class="font-bold sm:text-3xl text-xl text-primary-dark mr-3 product-price-amount">
                                    <?php echo \App\Models\Product::getProductPrice($product, $store); ?>

                                </span>
                            </div>
                             <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->track_stock == 0 && $product->stock_status == 'out_of_stock'): ?>
                                <p class="stock_status_color text-red-600 flex items-center mb-1" product_id="<?php echo e($product->id); ?>"
                                    variant_id="<?php echo e($product->default_variant_id); ?>" qty="1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4 mr-1">
                                        <path d="M22 11.08V12a10 10 0 border-t border-b py-6 mb-6 1-5.93-9.14" />
                                        <path d="m9 11 3 3L22 4" />
                                    </svg>
                                    <span class="stock_status"><?php echo e(__('Out of Stock')); ?></span>
                                </p>
                             <?php else: ?>
                                <p class="stock_status_color text-green-600 flex items-center" product_id="<?php echo e($product->id); ?>"
                                    variant_id="<?php echo e($product->default_variant_id); ?>" qty="1">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="h-4 w-4 mr-1">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                                        <path d="m9 11 3 3L22 4" />
                                    </svg>
                                    <span class="stock_status"><?php echo e(__('In Stock')); ?></span>
                                </p>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>

                        <div class="border-t border-b md:py-6 py-4 md:mb-6 mb-4">
                            <?php echo $product->description; ?>

                        </div>

                        <div class="md:mb-6 mb-4">
                            <form class="variant_form">
                                <div class="mb-5 quantity-wrp quantity-select flex gap-3 items-center">
                                    <h3 class="font-semibold"><?php echo e(__('quantity:')); ?></h3>
                                    <div class="flex items-center">
                                        <button type="button"
                                            class="quantity-decrement change_price w-10 h-10 bg-gray-100 flex items-center justify-center ltr:rounded-l-md rtl:rounded-r-md border ltr:border-r-0 rtl:border-l-0 border-gray-300 hover:bg-gray-200" data-product="<?php echo e($product->id); ?>">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="text" class="quantity w-14 h-10 border border-gray-300 text-center product-quantity" data-cke-saved-name="quantity"
                                            name="quantity" value="1" min="1" max="10" data-product="<?php echo e($product->id); ?>">
                                        <button type="button"
                                            class="quantity-increment change_price w-10 h-10 bg-gray-100 flex items-center justify-center ltr:rounded-r-md rtl:rounded-l-md border ltr:border-l-0 rtl:border-r-0 border-gray-300 hover:bg-gray-200" data-product="<?php echo e($product->id); ?>">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                    <?php echo $__env->make('front_end.common.product.variant', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                            </form>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($product->track_stock == 0 && $product->stock_status == 'out_of_stock'): ?>
                            <?php else: ?>
                            <button class="btn-primary max-w-sm w-full addtocart-btn btn addcart-btn  addcart-btn-globaly price-wise-btn product_var_option" product_id="<?php echo e($product->id); ?>" variant_id="<?php echo e($product->default_variant_id); ?>" qty="1">
                                    <i class="fa-solid fa-cart-shopping"></i>
                                   <?php echo e(__('Add to cart')); ?>

                            </button>
                          
                                <?php echo \App\Models\Product::ProductcardButton($slug, $product); ?>

                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                        <div class="product-hook">
                            <?php echo $__env->make('front_end.hooks.product_detail_info_button', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        </div>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4 text-sm text-gray-600">
                            <?php echo $__env->make('front_end.common.product.sale_counter', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                        </div>
                    </div>
                </div>

                <!-- Product Tabs -->
                <?php echo $__env->make('front_end.theme_common_table', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                
            </div>
        </div>
    </section>

    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
     <?php echo $__env->make('front_end.hooks.product_detail_slider', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    
    <?php echo $__env->make('front_end.pages.bestseller', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php $__env->stopSection(); ?>


<?php echo $__env->make('front_end.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/product-detail.blade.php ENDPATH**/ ?>