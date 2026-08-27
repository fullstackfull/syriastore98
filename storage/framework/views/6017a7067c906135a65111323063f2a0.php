<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($latestSales): ?>
<div class="pdp-timer">
    <div class="time-counter flex">
        <div class="time-svg flex items-center justify-center"> <svg xmlns="http://www.w3.org/2000/svg" width="36" height="39" viewBox="0 0 36 39" fill="none"> <path fill-rule="evenodd" clip-rule="evenodd" d="M14.4947 24.3495L13.6268 28.2594C13.3568 29.4718 13.9752 30.7086 15.1079 31.219C16.2406 31.7308 17.5778 31.3759 18.3081 30.3705L23.4727 23.2695C24.0539 22.4711 24.1374 21.4156 23.6887 20.5361C23.2413 19.6567 22.3387 19.1039 21.3513 19.1039H20.5889L22.0096 14.8418C22.277 14.0421 22.142 13.1626 21.6496 12.4786C21.1559 11.7946 20.3639 11.3896 19.5218 11.3896C18.3466 11.3896 16.8526 11.3896 15.8806 11.3896C14.6772 11.3896 13.6268 12.2086 13.335 13.3761L11.4064 21.0903C11.211 21.8746 11.3871 22.7038 11.8847 23.3415C12.381 23.978 13.1434 24.3495 13.9521 24.3495H14.4947ZM19.4498 14.0639L18.0291 18.326C17.7616 19.1257 17.8966 20.0051 18.3891 20.6891C18.8828 21.3731 19.6748 21.7781 20.5169 21.7781H21.2497L16.2921 28.5962L17.1201 24.8677C17.2924 24.0898 17.1034 23.2773 16.6058 22.6563C16.1082 22.0366 15.3548 21.6753 14.5589 21.6753H14.0177L15.9205 14.0639H19.4498Z" fill="white"></path> <path fill-rule="evenodd" clip-rule="evenodd" d="M17.6784 4.79395C8.44838 4.79395 0.954346 12.288 0.954346 21.518C0.954346 30.7479 8.44838 38.242 17.6784 38.242C26.9083 38.242 34.4024 30.7479 34.4024 21.518C34.4024 12.288 26.9083 4.79395 17.6784 4.79395ZM17.6784 7.46935C25.4312 7.46935 31.727 13.7651 31.727 21.518C31.727 29.2708 25.4312 35.5666 17.6784 35.5666C9.92546 35.5666 3.62975 29.2708 3.62975 21.518C3.62975 13.7651 9.92546 7.46935 17.6784 7.46935Z" fill="white"></path> <path fill-rule="evenodd" clip-rule="evenodd" d="M15.001 3.43943H20.3525C21.091 3.43943 21.6904 2.84006 21.6904 2.10155C21.6904 1.36304 21.091 0.763672 20.3525 0.763672H15.001C14.2625 0.763672 13.6631 1.36304 13.6631 2.10155C13.6631 2.84006 14.2625 3.43943 15.001 3.43943Z" fill="white"></path> <path fill-rule="evenodd" clip-rule="evenodd" d="M29.0396 6.99042L32.8237 10.7745C33.3459 11.2967 34.1935 11.2967 34.7157 10.7745C35.2379 10.2523 35.2379 9.40467 34.7157 8.88247L30.9316 5.09837C30.4094 4.57616 29.5618 4.57616 29.0396 5.09837C28.5174 5.62057 28.5174 6.46821 29.0396 6.99042Z" fill="white"></path> </svg> </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $latestSales; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $productId => $saleData): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <input type="hidden" class="sale_start_date" value="<?php echo e($saleData['start_date']); ?>">
            <input type="hidden" class="sale_end_date" value="<?php echo e($saleData['end_date']); ?>">
            <input type="hidden" class="sale_start_time" value="<?php echo e($saleData['start_time']); ?>">
            <input type="hidden" class="sale_end_time" value="<?php echo e($saleData['end_time']); ?>">
            <div id="timer"></div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('PreOrder')): ?>
    <?php echo $__env->make('pre-order::pages.preSaleCoundown', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ProductImageZoom')): ?>
    <?php echo $__env->make('product-image-zoom::ProductImageZoom-details', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<?php $__env->startPush('page-script'); ?>
    <script>
        // Call immediately to prevent initial delay
        updateTimers();
        // Update all timers every second
        setInterval(updateTimers, 1000);
        /**  timer counter **/
        function updateTimers() {
            var end_date = $('.sale_end_date').val();
            var end_time = $('.sale_end_time').val();
            
            // Check if date and time values exist
            if (!end_date || !end_time) {
                document.querySelectorAll("#timer").forEach(timer => {
                    timer.innerHTML = "<div class='flex flex-col items-center'>00<span> days </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> hours </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> mins </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> sec</span></div>";
                });
                return;
            }
            
            // Create future date - try different formats
            let future;
            try {
                // Try ISO format first
                future = new Date(end_date + 'T' + end_time).getTime();
                if (isNaN(future)) {
                    // Try with space separator
                    future = new Date(end_date + ' ' + end_time).getTime();
                }
            } catch (e) {
                future = NaN;
            }
            
            let now = new Date().getTime();
            let diff = future - now;
            
            // Handle invalid dates or expired timers
            if (isNaN(future) || diff <= 0) {
                document.querySelectorAll("#timer").forEach(timer => {
                    timer.innerHTML = "<div class='flex flex-col items-center'>00<span> days </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> hours </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> mins </span></div>" +
                                    "<div class='flex flex-col items-center'>00<span> sec</span></div>";
                });
                return;
            }
            
            let days = Math.floor(diff / (1000 * 60 * 60 * 24));
            let hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
            let mins = Math.floor((diff / (1000 * 60)) % 60);
            let secs = Math.floor((diff / 1000) % 60);
            
            // Ensure values are not NaN
            days = isNaN(days) ? 0 : days;
            hours = isNaN(hours) ? 0 : hours;
            mins = isNaN(mins) ? 0 : mins;
            secs = isNaN(secs) ? 0 : secs;
            
            let timerHTML = 
                `<div class="flex flex-col items-center">${days < 10 ? "0" + days : days}<span> days </span></div>` +
                `<div class="flex flex-col items-center">${hours < 10 ? "0" + hours : hours}<span> hours </span></div>` +
                `<div class="flex flex-col items-center">${mins < 10 ? "0" + mins : mins}<span> mins </span></div>` +
                `<div class="flex flex-col items-center">${secs < 10 ? "0" + secs : secs}<span> sec</span></div>`;
            
            document.querySelectorAll("#timer").forEach(timer => {
                timer.innerHTML = timerHTML;
            });
        }

    </script>
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/resources/views/front_end/common/product/sale_counter.blade.php ENDPATH**/ ?>