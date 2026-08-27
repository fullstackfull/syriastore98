<div id="products-container" class="grid-view grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5 xl:gap-6">
                
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
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
<?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<div class="flex justify-center md:mt-8 mt-5 pagination-wrapper">
    <div class="flex items-center gap-2">
        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($products->onFirstPage()): ?>
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </span>
        <?php else: ?>
            <button onclick="product_page_filter(<?php echo e($products->currentPage() - 1); ?>)"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php
            $current = $products->currentPage();
            $last = $products->lastPage();
        ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($current <= 2): ?>
            
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = 1; $i <= min(3, $last); $i++): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i == $current): ?>
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white"><?php echo e($i); ?></span>
                <?php else: ?>
                    <button onclick="product_page_filter(<?php echo e($i); ?>)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50"><?php echo e($i); ?></button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($last > 3): ?>
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
                <button onclick="product_page_filter(<?php echo e($last); ?>)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50"><?php echo e($last); ?></button>
            <?php endif; ?>
        <?php elseif($current >= $last - 1): ?>
            
            <button onclick="product_page_filter(1)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</button>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($last > 3): ?>
                <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = max(1, $last - 2); $i <= $last; $i++): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i == $current): ?>
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white"><?php echo e($i); ?></span>
                <?php else: ?>
                    <button onclick="product_page_filter(<?php echo e($i); ?>)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50"><?php echo e($i); ?></button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php else: ?>
            
            <button onclick="product_page_filter(1)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">1</button>
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php for($i = $current; $i <= $current + 1; $i++): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i == $current): ?>
                    <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-primary bg-primary text-white"><?php echo e($i); ?></span>
                <?php else: ?>
                    <button onclick="product_page_filter(<?php echo e($i); ?>)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50"><?php echo e($i); ?></button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endfor; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <span class="p-1 h-8 w-8 flex items-center justify-center text-gray-500">...</span>
            <button onclick="product_page_filter(<?php echo e($last); ?>)" class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50"><?php echo e($last); ?></button>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($products->hasMorePages()): ?>
            <button onclick="product_page_filter(<?php echo e($products->currentPage() + 1); ?>)"
                class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 hover:bg-gray-50">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </button>
        <?php else: ?>
            <span class="p-1 h-8 w-8 flex items-center justify-center rounded-md border border-gray-300 text-gray-500 cursor-not-allowed">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="h-4 w-4">
                    <path d="m9 18 6-6-6-6" />
                </svg>
            </span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div><?php /**PATH /home/storesyria/public_html/resources/views/front_end/pages/product_list_filter.blade.php ENDPATH**/ ?>