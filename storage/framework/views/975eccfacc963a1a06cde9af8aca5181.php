<?php
    $setting = getAdminAllSetting();
?>
<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class=" form-check  form-switch p-0">
        <?php echo Form::hidden('product_image_zoom_is_enable', 'off'); ?>

        <label class="form-check-label mb-2"
            for="product_image_zoom_is_enable"><?php echo e(__('Enable Image Zoom Setting')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
            data-onstyle="primary" name="product_image_zoom_is_enable" id="product_image_zoom_is_enable"
            <?php echo e((isset($setting['product_image_zoom_is_enable']) && ($setting['product_image_zoom_is_enable'] == 'on')) ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductImageZoom/src/app/Providers/../../resources/views/ProductImageZoom-tab.blade.php ENDPATH**/ ?>