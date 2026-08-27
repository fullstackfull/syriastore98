<?php $__env->startSection('page-title'); ?>
    <?php echo e(__('Home Page')); ?>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
    <main>
        <!-- Hero Slider Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['slider_status']) && $themeSettings['slider_status'] == 1): ?>
            <section class="section-spacing pb-0">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="flex flex-wrap gap-4 banner-wrp">
                        <div class="swiper hero-slider lg:flex-1 rounded-lg overflow-hidden">
                            <div class="swiper-wrapper">
                                    <?php
                                        $sliders = json_decode($themeSettings['slider_repeater'] ?? '[]', true);

                                        if (!is_array($sliders)) {
                                            $sliders = [];
                                        }
                                    ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $sliders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $slider_repeater): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <div class="swiper-slide h-auto relative">
                                            <img src="<?php echo e(get_file($slider_repeater['image'])); ?>"
                                                width="500"
                                                height="500"
                                                alt="<?php echo e($slider_repeater['big_text'] ?? 'New arrivals'); ?>"
                                                loading="lazy"
                                                class="w-full h-full object-cover object-left absolute rounded-lg" />

                                            <div class="relative z-10 flex items-center md:py-[130px] py-[60px]">
                                                <div class="text-white px-4 md:px-6 max-w-[500px]">
                                                    <h2 class="text-3xl md:text-5xl text-black font-bold mb-4">
                                                        <?php echo $slider_repeater['big_text'] ?? __('Step Into a World of Effortless Elegance'); ?>

                                                    </h2>
                                                    <p class="md:mb-8 mb-4 text-black max-w-sm">
                                                        <?php echo $slider_repeater['content'] ?? __('Simple cuts. Solid tones. Elevated basics. Our mens lineup is designed for effortless dressing with a modern edge.'); ?>

                                                    </p>
                                                    <a href="<?php echo e(url($slider_repeater['button_link'] ?? '/' . $slug . '/product-list')); ?>" class="btn-primary">
                                                        <?php echo e($slider_repeater['button_text'] ?? __('Shop Now')); ?>

                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>

                            <!-- Pagination -->
                            <div class="swiper-pagination text-start"></div>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['more_offer_status']) && $themeSettings['more_offer_status'] == 1): ?>
                            <div class="lg:h-auto h-96 w-full offer-banner overflow-hidden">
                                <a href="<?php echo e($themeSettings['more_offer_button_link'] ?? route('page.product-list', ['storeSlug' => $slug])); ?>" tabindex="0">
                                    <img src="<?php echo e(get_file($themeSettings['more_offer_image'])); ?>" width="300" height="400" alt="offer-image"
                                        class="w-full h-full object-cover object-top rounded-lg" loading="lazy">
                                </a>
                            </div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <!-- logo Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['logo_status']) && $themeSettings['logo_status'] == 1): ?>
        <section class="section-spacing">
            <div class="md:container mx-auto px-4 w-full">
                <!-- Swiper container -->
                <div class="swiper partnerSwiper pb-0 relative">
                    <div class="swiper-wrapper">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['logo_repeater'])): ?>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = json_decode($themeSettings['logo_repeater'],true); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $logo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="swiper-slide">
                                
                                    <div class="partner-logo flex items-center justify-center bg-white rounded-xl h-full">
                                        <img
                                            src="<?php echo e(get_file($logo['image'])); ?>"
                                            width="150"
                                            height="150"
                                            alt="<?php echo e($logo->alt_text ?? 'Partner Logo'); ?>"
                                            class="h-12 object-contain"
                                        />
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>

                    <div class="arrow-wrapper">
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                    </div>
                </div>
            </div>
        </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <!-- Feature Collections Banner -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['collection_status']) && $themeSettings['collection_status'] == 1): ?>
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="grid md:grid-cols-2 gap-6">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = collect($MainCategoryList)->reverse()->take(2); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="relative rounded-lg overflow-hidden group">
                                    <img src="<?php echo e(get_file($category->image_path)); ?>"
                                        alt="<?php echo e($category->name); ?>"
                                        loading="lazy" width="200" height="200"
                                        class="w-full md:h-96 h-64 object-cover transition-transform duration-500 group-hover:scale-105 rtl:scale-x-[-1] rtl:group-hover:[--tw-scale-x:-1.05]"
                                    />
                                    <div
                                        class="absolute inset-0 bg-black bg-opacity-20 group-hover:bg-opacity-30 transition-all duration-300"
                                    ></div>
                                    <div class="absolute inset-0 flex items-end md:p-6 p-4">
                                        <div>
                                            <h3 class="text-white md:text-3xl text-2xl font-bold mb-2">
                                                <?php echo e($category->name); ?>

                                            </h3>
                                            <p class="text-white mb-4 max-w-[200px] sm:max-w-[300px]">
                                                <!-- Optional description, if available -->
                                                <?php echo e($category->description ?? 'Explore our latest ' . $category->name . ' collection.'); ?>

                                            </p>
                                            <a href="<?php echo e(url($store->slug . '/' . $category->slug)); ?>" class="btn-primary inline-block">
                                                <?php echo e(__("Shop Collection")); ?>

                                            </a>
                                        </div>
                                    </div>
                                </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <!-- Products Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['product_status']) && $themeSettings['product_status'] == 1): ?>
            <section class="section-spacing">
                <div class="md:container mx-auto px-4 w-full">
                <div class="text-center md:mb-10 mb-6">
                    <h2 class="md:text-4xl text-3xl font-bold mb-4"><?php echo e($themeSettings['product_title'] ?? 'Featured Products'); ?></h2>
                    <p class="text-gray-600 max-w-2xl mx-auto">
                    <?php echo $themeSettings['product_description'] ?? 'Discover our carefully curated selection of premium fashion items'; ?>

                    </p>
                </div>
                <div class="swiper featured-products">
                    <div class="swiper-wrapper">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = collect($all_products)->reverse()->take(10); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="swiper-slide">
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
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="arrow-wrapper">
                        <div class="swiper-button-next"></div>
                        <div class="swiper-button-prev"></div>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <!-- Category Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['category_status']) && $themeSettings['category_status'] == 1): ?>
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="text-center md:mb-10 mb-6">
                        <h2 class="md:text-4xl text-3xl font-bold mb-4">
                        <?php echo $themeSettings['category_title'] ?? '__("Shop by Category")'; ?>    </h2>
                        <p class="text-gray-600 max-w-2xl mx-auto">
                            <?php echo $themeSettings['category_description'] ?? 'Explore our diverse range of categories to find exactly what you need.'; ?>

                        </p>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 sm:grid-cols-2 gap-4 md:gap-6">
                        <?php $__currentLoopData = collect($MainCategoryList)->take(3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="group relative rounded-lg overflow-hidden transition-transform duration-300 hover:scale-105">
                                <img
                                    src="<?php echo e(get_file($category->image_path)); ?>"
                                    alt="<?php echo e($category->name); ?>"
                                    width="400" height="400"
                                    loading="lazy"
                                    class="w-full h-64 object-cover object-top rtl:scale-x-[-1]"
                                />
                                <div class="absolute inset-0 bg-black bg-opacity-20 group-hover:bg-opacity-30 transition-all duration-300"></div>
                                <div class="absolute inset-0 flex items-end md:p-6 p-4">
                                    <div>
                                        <h3 class="text-white text-2xl font-bold mb-3"><?php echo e($category->name); ?></h3>
                                        <a href="<?php echo e(url($store->slug . '/' . $category->slug)); ?>"
                                        class="btn-primary px-4 py-2 text-sm">
                                           <?php echo e(__("Shop Now")); ?>

                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['bestseller_status']) && $themeSettings['bestseller_status'] == 1): ?>
            <section class="section-spacing collection-sec">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="text-center items-center md:mb-10 mb-6 flex flex-wrap gap-3 justify-center lg:justify-between">
                    <h2 class="md:text-4xl text-3xl font-bold">
                        <?php echo $themeSettings['bestseller_title'] ?? 'Explore Our Products'; ?>

                    </h2>
                    <div class="overflow-x-auto">
                        <ul class="flex mx-auto w-fit border-b">
                        <li class="mr-2 whitespace-nowrap">
                            <button class="tab-btn active py-2 px-4 font-medium text-center sm:border-b-2 border-[var(--primary-color)]" data-tab="all">
                        <?php echo e(__("All Products")); ?>

                            </button>
                        </li>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = collect($MainCategoryList)->reverse()->take(4); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <li class="mr-2 whitespace-nowrap">
                            <button class="tab-btn py-2 px-4 font-medium text-center sm:border-b-2 border-transparent" data-tab="<?php echo e($category->slug); ?>">
                                <?php echo e($category->name); ?>

                            </button>
                            </li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </ul>
                    </div>
                    </div>

                    
                    <div class="tab-content-wrapper">
                        
                        <div class="tab-content active" id="all-tab">
                            <div class="swiper featured-products">
                                <div class="swiper-wrapper">
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = collect($all_products)->reverse()->take(10); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <div class="swiper-slide">
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
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="arrow-wrapper">
                                    <div class="swiper-button-next"></div>
                                    <div class="swiper-button-prev"></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = collect($MainCategoryList)->reverse()->take(4); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="tab-content hidden" id="<?php echo e($category->slug); ?>-tab">
                            <div class="swiper featured-products">
                                <div class="swiper-wrapper">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $category->product_details; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="swiper-slide">
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
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="arrow-wrapper">
                                <div class="swiper-button-next"></div>
                                <div class="swiper-button-prev"></div>
                                </div>
                            </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php echo $__env->make('front_end.hooks.product_list', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
        <!-- Testimonials Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['testimonial_status']) && $themeSettings['testimonial_status'] == 1): ?>
            <section class="section-spacing bg-gray-50">
                <div class="md:container mx-auto px-4 w-full">
                    <!-- Section Title -->
                    <div class="text-center md:mb-10 mb-6">
                        <h2 class="md:text-4xl text-3xl font-bold mb-4">
                            <?php echo $themeSettings['testimonial_title'] ?? 'What Our Customers Say'; ?>

                        </h2>
                        <p class="text-gray-600 max-w-2xl mx-auto">
                            <?php echo $themeSettings['testimonial_sub_title'] ?? 'Read testimonials from our satisfied customers around the world.'; ?>

                        </p>
                    </div>
                    <!-- Testimonials Slider -->
                    <div class="swiper testimonials-slider">
                        <div class="swiper-wrapper">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $testimonials; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $review): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="swiper-slide">
                                    <div class="bg-white md:p-6 p-4 rounded-lg shadow-md h-full flex flex-col">
                                        <!-- Star Rating & Review -->
                                        <div class="flex-1">
                                            <div class="text-yellow-400 mb-4">
                                                <?php
                                                    $stars = '';
                                                    $fullStars = floor($review->rating_no);
                                                    $halfStar = ($review->rating_no - $fullStars) >= 0.5;

                                                    for ($i = 1; $i <= 5; $i++) {
                                                        if ($i <= $fullStars) {
                                                            $stars .= '<i class="fas fa-star"></i>';
                                                        } elseif ($i === $fullStars + 1 && $halfStar) {
                                                            $stars .= '<i class="fas fa-star-half-alt"></i>';
                                                        } else {
                                                            $stars .= '<i class="far fa-star"></i>';
                                                        }
                                                    }
                                                ?>
                                                <?php echo $stars; ?>

                                            </div>
                                            <p class="text-gray-600 mb-6">
                                                <?php echo $review->description ?? 'This customer did not leave a written review.'; ?>

                                            </p>
                                        </div>

                                        <!-- Reviewer Info -->
                                        <div class="flex items-center gap-4">
                                            <img
                                            src="<?php echo e(get_file($review->avatar)); ?>"
                                                alt="Customer"
                                                class="w-12 h-12 rounded-full object-cover"
                                            />
                                            <div>
                                                <h4 class="font-medium">
                                                    <?php echo e($review->username ?? 'User'); ?>

                                                </h4>
                                                <p class="text-gray-500 text-sm">
                                                    <?php echo e($review->productData?->name ?? 'Product Detail not provided'); ?>

                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>

                        <!-- Swiper Navigation -->
                        <div class="arrow-wrapper">
                            <div class="swiper-button-next"></div>
                            <div class="swiper-button-prev"></div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>                                         
        <!-- Blog Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($themeSettings['blog_status']) && $themeSettings['blog_status'] == 1): ?>
            <section class="section-spacing">
            <div class="md:container mx-auto px-4 w-full">
                <div class="text-center md:mb-10 mb-6">
                    <h2 class="md:text-4xl text-3xl font-bold mb-4"><?php echo $themeSettings['blog_title'] ?? 'Fashion Blog'; ?></h2>
                    <p class="text-gray-600 max-w-2xl mx-auto">
                        <?php echo $themeSettings['blog_sub_title'] ?? 'Style tips, trend reports, and fashion inspiration'; ?>

                    </p>
                </div>
                <div class="blog-slider swiper">
                <div class="swiper-wrapper">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $blogs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $blog): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="swiper-slide">
                     <?php if (isset($component)) { $__componentOriginalef84dbe2113ee1aa06beffddb73fe07d = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalef84dbe2113ee1aa06beffddb73fe07d = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.blog-card','data' => ['store' => $store,'blog' => $blog]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('blog-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['store' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($store),'blog' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($blog)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalef84dbe2113ee1aa06beffddb73fe07d)): ?>
<?php $attributes = $__attributesOriginalef84dbe2113ee1aa06beffddb73fe07d; ?>
<?php unset($__attributesOriginalef84dbe2113ee1aa06beffddb73fe07d); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalef84dbe2113ee1aa06beffddb73fe07d)): ?>
<?php $component = $__componentOriginalef84dbe2113ee1aa06beffddb73fe07d; ?>
<?php unset($__componentOriginalef84dbe2113ee1aa06beffddb73fe07d); ?>
<?php endif; ?>
                    </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="arrow-wrapper">
                    <div class="swiper-button-next"></div>
                    <div class="swiper-button-prev"></div>
                </div>
                </div>
            </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <!-- Service Section -->
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($themeSettings['service_status']) && $themeSettings['service_status'] == 1): ?>
            <section class="section-spacing pt-0">
                <div class="md:container mx-auto px-4 w-full">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 md:gap-6 gap-4">  
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = json_decode($themeSettings['service_repeater'], true); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="service-card bg-white rounded-xl p-4 border border-gray-100 flex items-center gap-4">
                                <div class="service-icon text-orange-500 floating">
                                    <img src="<?php echo e(get_file($service['image'])); ?>" alt="<?php echo e($service['title']); ?>" class="w-12 h-12 object-contain" loading="lazy">
                                </div>
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-800 mb-2"><?php echo e($service['title']); ?></h3>
                                    <p class="text-gray-600"><?php echo e($service['content']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </main>
<?php $__env->stopSection(); ?>


<?php echo $__env->make('front_end.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/themes/stylique/views/main_file.blade.php ENDPATH**/ ?>