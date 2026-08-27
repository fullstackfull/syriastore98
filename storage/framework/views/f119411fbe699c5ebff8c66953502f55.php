<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($pre_order_available)): ?>
<div class="pre_order_countdown" style="<?php echo e($product->variant_product == 1 ? 'display: none;' : ''); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $pre_order_available; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $productId => $pre_order_detail): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div class="preorder-info mb-3">
            <div class="flex items-center gap-2 mb-2">
                <b><?php echo e(__('Product available on')); ?></b><span> : <?php echo e($pre_order_detail['formated_end_date']); ?></span>
            </div>
            
            <input type="hidden" class="preorder_sale_start_date" value="<?php echo e($pre_order_detail['start_date']); ?>">
            <input type="hidden" class="preorder_end_date" value="<?php echo e($pre_order_detail['end_date']); ?>">
            <input type="hidden" class="preorder_sale_start_time" value="<?php echo e($pre_order_detail['start_time']); ?>">
            <input type="hidden" class="preorder_end_time" value="<?php echo e($pre_order_detail['end_time']); ?>">
            
            <div class="preorder-timer-container">
                <div id="preOrderTimer" class="preOrderTimer flex gap-2"></div>
            </div>
            
            <div class="mt-2">
                <b><?php echo e(__('Available Quantity')); ?>: <?php echo e($pre_order_detail['qty']); ?></b>
            </div>
        </div>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>

<?php $__env->startPush('page-script'); ?>
<script>
// Pre-order countdown timer
function updatePreOrderTimer() {
    var end_date = $('.preorder_end_date').val();
    var end_time = $('.preorder_end_time').val();
    
    if (!end_date || !end_time) {
        $('.preOrderTimer').html('<div class="text-gray-500">Timer not available</div>');
        return;
    }
    
    let future;
    try {
        // Since end_date already contains full datetime, use it directly
        future = new Date(end_date).getTime();
        
        // If that fails, try parsing as separate date and time
        if (isNaN(future)) {
            // Extract just the date part if end_date contains datetime
            let dateOnly = end_date.split(' ')[0];
            future = new Date(dateOnly + 'T' + end_time).getTime();
        }
    } catch (e) {
        future = NaN;
    }
    
    let now = new Date().getTime();
    let diff = future - now;
    
    if (isNaN(future)) {
        $('.preOrderTimer').html('<div class="text-red-500 font-semibold">Invalid date format</div>');
        return;
    }
    
    if (diff <= 0) {
        $('.preOrderTimer').html('<div class="text-red-500 font-semibold">Pre-order period ended</div>');
        return;
    }
    
    let days = Math.floor(diff / (1000 * 60 * 60 * 24));
    let hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
    let mins = Math.floor((diff / (1000 * 60)) % 60);
    let secs = Math.floor((diff / 1000) % 60);
    
    days = isNaN(days) ? 0 : days;
    hours = isNaN(hours) ? 0 : hours;
    mins = isNaN(mins) ? 0 : mins;
    secs = isNaN(secs) ? 0 : secs;
    
    let timerHTML = 
        `<div class="flex flex-col items-center bg-gray-100 px-3 py-2 rounded">${days < 10 ? "0" + days : days}<span class="text-xs text-gray-600">days</span></div>` +
        `<div class="flex flex-col items-center bg-gray-100 px-3 py-2 rounded">${hours < 10 ? "0" + hours : hours}<span class="text-xs text-gray-600">hours</span></div>` +
        `<div class="flex flex-col items-center bg-gray-100 px-3 py-2 rounded">${mins < 10 ? "0" + mins : mins}<span class="text-xs text-gray-600">mins</span></div>` +
        `<div class="flex flex-col items-center bg-gray-100 px-3 py-2 rounded">${secs < 10 ? "0" + secs : secs}<span class="text-xs text-gray-600">sec</span></div>`;
    
    $('.preOrderTimer').html(timerHTML);
}

// Initialize and update timer
$(document).ready(function() {
    if ($('.preorder_end_date').length > 0) {
        updatePreOrderTimer();
        setInterval(updatePreOrderTimer, 1000);
    }
});
</script>
<?php $__env->stopPush(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><?php /**PATH /home/storesyria/public_html/packages/workdo/PreOrder/src/app/Providers/../../resources/views/pages/preSaleCoundown.blade.php ENDPATH**/ ?>