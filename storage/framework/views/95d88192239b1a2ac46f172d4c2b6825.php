<style>
    .badge-view {
        background-color: <?php echo e($setting['bgcolor'] ?? '#000000'); ?>;
        color: <?php echo e($setting['textcolor'] ?? '#ffffff'); ?>;
        padding-top: <?php echo e($setting['padding_top'] ?? '1'); ?>px;
        padding-left: <?php echo e($setting['padding_left'] ?? '1'); ?>px;
        padding-bottom: <?php echo e($setting['padding_bottom'] ?? '1'); ?>px;
        padding-right: <?php echo e($setting['padding_right'] ?? '1'); ?>px;
        top: <?php echo e($setting['position_top'] ?? '1'); ?>px;
        left: <?php echo e($setting['position_left'] ?? '1'); ?>px;
        border-top-left-radius: <?php echo e($setting['radius_top_left'] ?? '1'); ?>px;
        border-bottom-left-radius: <?php echo e($setting['radius_bottom_left'] ?? '1'); ?>px;
        border-top-right-radius: <?php echo e($setting['radius_top_right'] ?? '1'); ?>px;
        border-bottom-right-radius: <?php echo e($setting['radius_bottom_right'] ?? '1'); ?>px;
        opacity: <?php echo e($setting['opacity'] ?? '1'); ?>;
        z-index: 10;
    }

    .img-badge-view {
        padding-top: <?php echo e($existingImageBadge['padding_top'] ?? '1'); ?>px;
        padding-left: <?php echo e($existingImageBadge['padding_left'] ?? '1'); ?>px;
        padding-bottom: <?php echo e($existingImageBadge['padding_bottom'] ?? '1'); ?>px;
        padding-right: <?php echo e($existingImageBadge['padding_right'] ?? '1'); ?>px;
        top: <?php echo e($existingImageBadge['position_top'] ?? '1'); ?>px;
        left: <?php echo e($existingImageBadge['position_left'] ?? '1'); ?>px;
        width: <?php echo e($existingImageBadge['width'] ?? '100'); ?>px;
        opacity: <?php echo e($existingImageBadge['opacity'] ?? '1'); ?>;
        z-index: 10;
    }

    #bg-preview {
        border-top-left-radius: <?php echo e($existingImageBadge['radius_top_left'] ?? '1'); ?>px;
        border-bottom-left-radius: <?php echo e($existingImageBadge['radius_bottom_left'] ?? '1'); ?>px;
        border-top-right-radius: <?php echo e($existingImageBadge['radius_top_right'] ?? '1'); ?>px;
        border-bottom-right-radius: <?php echo e($existingImageBadge['radius_bottom_right'] ?? '1'); ?>px;
    }
</style>


<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($badgesetting != null): ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($badgesetting->storage_setting == 'textbadge'): ?>
        <div class="relative">
            <div class="badge-view absolute ml-0 z-10">
                <span><?php echo e($setting['badgetxt'] ?? 'test'); ?></span>
            </div>
        </div>
    <?php elseif($badgesetting->storage_setting == 'imagebadge'): ?>
        <div class="relative">
            <div class="img-badge-view absolute ml-0 z-10">
                <img id="bg-preview" src="<?php echo e(get_file($existingImageBadge->filepath ?? '')); ?>"
                     alt="Uploaded Background Image" class="p-0">
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductBadgeManagement/src/app/Providers/../../resources/views/pages/mainform.blade.php ENDPATH**/ ?>