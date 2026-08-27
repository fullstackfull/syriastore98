<?php
    $setting = getAdminAllSetting();
?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($setting['product_image_zoom_is_enable']) && $setting['product_image_zoom_is_enable'] == 'on'): ?>
    <?php $__env->startPush('page-script'); ?>
        <script src="<?php echo e(asset('packages/workdo/ProductImageZoom/src/resources/assets/js/zoom.js')); ?>"></script>
        <script>
            $(document).ready(function() {
                $('.main-image-slider .swiper-slide > div img').each(function() {
                    $(this).parent().zoom({
                        url: this.src,
                        magnify: 2,
                        on: 'mouseover'
                    });
                });
            });
        </script>
    <?php $__env->stopPush(); ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductImageZoom/src/app/Providers/../../resources/views/ProductImageZoom-details.blade.php ENDPATH**/ ?>