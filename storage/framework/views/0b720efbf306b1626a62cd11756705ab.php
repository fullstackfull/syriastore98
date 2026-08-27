
<div class="card" id="whatsapp_Setting">
    <?php echo e(Form::open(['route' => 'whatsapp.settings', 'method' => 'post', 'enctype' => 'multipart/form-data'])); ?>

    <div class="card-header d-flex justify-content-between ">
        <div class="Whatsapp-title-div">
            <h5 class=""> <?php echo e(__('Whatsapp Settings')); ?> </h5>
            <small><?php echo e(__('WhatsApp live support setting for customers')); ?></small>
        </div>
        <?php echo Form::hidden('whatsapp_setting_enabled', 'off'); ?>

        <div class="form-check form-switch d-inline-block">
            <?php echo Form::checkbox(
            'whatsapp_setting_enabled',
            'on',
            isset($setting['whatsapp_setting_enabled']) && $setting['whatsapp_setting_enabled'] === 'on',
            [
            'class' => 'form-check-input',
            'id' => 'whatsapp_setting_enabled',
            ],
            ); ?>

            <label class="custom-control-label form-control-label" for="whatsapp_setting_enabled"></label>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="row">
            <div class="col-lg-12 form-group">
                <?php echo Form::label('whatsapp_contact_number', __('Contact Number'), ['class' => 'form-label']); ?>

                <?php echo Form::text(
                'whatsapp_contact_number',
                !empty($setting['whatsapp_contact_number']) ? $setting['whatsapp_contact_number'] : '',
                [
                    'class' => 'form-control',
                    'placeholder' => 'XXXXXXXXXX',
                    'pattern' => '^\+[1-9]{1}[0-9]{3,14}$', // Add pattern for validation
                    'required' => 'required' // Optional: to make the field mandatory
                ],
                ); ?>

                <small class="text-muted text-danger"><?php echo e(__("Note : Enter your WhatsApp number along with your country code (with the '+' symbol). For instance, if your country code is +91 and your WhatsApp number is 7878787878, then type +917878787878.")); ?></small>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap ">
        <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn-badge btn btn-primary">
    </div>
    <?php echo Form::close(); ?>

</div>
<?php /**PATH /home/storesyria/public_html/resources/views/setting/whatsapp_setting.blade.php ENDPATH**/ ?>