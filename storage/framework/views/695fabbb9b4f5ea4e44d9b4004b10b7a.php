<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_coupon_email', 'off'); ?>

        <label class="form-check-label form-label" for="enable_coupon_email"><?php echo e(__('Enable Coupon Email')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_coupon_email" id="enable_coupon_email" <?php echo e(isset($setting['enable_coupon_email']) && $setting['enable_coupon_email'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/CouponEmail/src/app/Providers/../../resources/views/pages/enablebutton.blade.php ENDPATH**/ ?>