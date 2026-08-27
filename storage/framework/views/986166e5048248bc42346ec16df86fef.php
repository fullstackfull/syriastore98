<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <?php echo Form::hidden('enable_subscribe_popup', 'off'); ?>

        <label class="form-check-label form-label" for="enable_subscribe_popup"><?php echo e(__('Enable Subscribe Popup')); ?></label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_subscribe_popup" id="enable_subscribe_popup" <?php echo e(isset($setting['enable_subscribe_popup']) && $setting['enable_subscribe_popup'] == 'on' ? 'checked="checked"' : ''); ?>>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/Subscribe/src/app/Providers/../../resources/views/pages/subscribebutton.blade.php ENDPATH**/ ?>