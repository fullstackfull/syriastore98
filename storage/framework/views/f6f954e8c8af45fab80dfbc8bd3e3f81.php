<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_additional_field', 'off'); ?>

        <label class="form-check-label form-label" for="enable_additional_field"><?php echo e(__('Enable Additional Field')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_additional_field" id="enable_additional_field" <?php echo e(isset($setting['enable_additional_field']) && $setting['enable_additional_field'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/AdditionalFieldInCheckout/src/app/Providers/../../resources/views/admin/additionalbutton.blade.php ENDPATH**/ ?>