<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_affiliate_product', 'off'); ?>

        <label class="form-check-label form-label" for="enable_affiliate_product"><?php echo e(__('Enable Affiliate Product')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_affiliate_product" id="enable_affiliate_product" <?php echo e(isset($setting['enable_affiliate_product']) && $setting['enable_affiliate_product'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/ProductAffiliate/src/app/Providers/../../resources/views/pages/enablebutton.blade.php ENDPATH**/ ?>