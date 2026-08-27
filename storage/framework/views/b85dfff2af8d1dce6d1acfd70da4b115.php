<div class="tab-pane fade show active" id="<?php echo e($tab_name); ?>-home" role="tabpanel" aria-labelledby="<?php echo e($tab_name); ?>-tab">
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($top_sales) && count($top_sales) > 0): ?>    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $top_sales; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $sale): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
    <div class="cat-item d-flex flex-wrap align-items-center gap-3 justify-contant-between">
        <div class="cat-img rounded-1 border overflow-hidden border border-primary border-2 rounded">
            <img src="<?php echo e(asset($sale->sale_image_path ?? '#')); ?>"
                alt="product-img">
        </div>    
        <div class="cat-tab-text d-flex align-items-start gap-2">
            <p class="m-0 text-muted"><?php echo e($sale->sale_name); ?></p>
            <span class="cat-price"><b><?php echo e(currency_format_with_sym(($sale->total_sale/100), getCurrentStore())); ?></b></span>
        </div>
    </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php else: ?>
    <div class="d-flex text-center align-items-center h-100 w-100 justify-content-center">
        <span><?php echo e(__('No Data Found')); ?></span>
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div><?php /**PATH /home/storesyria/public_html/resources/views/order/brand_category_chart.blade.php ENDPATH**/ ?>