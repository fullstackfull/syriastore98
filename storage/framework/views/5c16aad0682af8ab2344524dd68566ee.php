<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_product_catelog', 'off'); ?>

        <label class="form-check-label form-label" for="enable_product_catelog"><?php echo e(__('Enable Product Catelog')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_product_catelog" id="enable_product_catelog" <?php echo e(isset($setting['enable_product_catelog']) && $setting['enable_product_catelog'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductCatelog/src/app/Providers/../../resources/views/pages/catelogbutton.blade.php ENDPATH**/ ?>