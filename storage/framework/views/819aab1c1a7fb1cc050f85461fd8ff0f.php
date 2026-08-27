<div class="row">
    <div class="row">
        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sms_setting == "sns"): ?>
        <div class="form-group col-md-4">
            <label for="sns_access_key" class="form-label"><?php echo e(__('SNS Access Key')); ?></label>
            <?php echo e(Form::text('sns_access_key', isset($settings['sns_access_key']) ? $settings['sns_access_key'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Access Key'), 'id' => 'sns_access_key'] )); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sns_secret_key" class="form-label"><?php echo e(__('SNS Secret Key')); ?></label>
            <?php echo e(Form::text('sns_secret_key', isset($settings['sns_secret_key']) ? $settings['sns_secret_key'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Secret Key'), 'id' => 'sns_secret_key'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sns_region" class="form-label"><?php echo e(__('AWS SNS Region')); ?></label>
            <?php echo e(Form::text('sns_region', isset($settings['sns_region']) ? $settings['sns_region'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Access Key'), 'id' => 'sns_region'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sns_sender_id" class="form-label"><?php echo e(__('AWS SNS Sender ID')); ?></label>
            <?php echo e(Form::text('sns_sender_id', isset($settings['sns_sender_id']) ? $settings['sns_sender_id'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Sender ID'), 'id' => 'sns_sender_id'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sns_type" class="form-label"><?php echo e(__('AWS Type')); ?></label>
            <?php echo e(Form::text('sns_type', isset($settings['sns_type']) ? $settings['sns_type'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Type'), 'id' => 'sns_type'])); ?>

        </div>


        
        <?php elseif($sms_setting == "twilio"): ?>
        <div class="form-group col-md-4">
            <label for="sms_twilio_sid" class="form-label"><?php echo e(__('Twilio Sid')); ?></label>
            <?php echo e(Form::text('sms_twilio_sid', isset($settings['sms_twilio_sid']) ? $settings['sms_twilio_sid'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Twilio Sid'), 'id' => 'sms_twilio_sid'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sms_twilio_token" class="form-label"><?php echo e(__('Twilio Token')); ?></label>
            <?php echo e(Form::text('sms_twilio_token', isset($settings['sms_twilio_token']) ? $settings['sms_twilio_token'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Twilio Token'), 'id' => 'sms_twilio_token'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="sms_twilo_from_number" class="form-label"><?php echo e(__('Twilio From Number')); ?></label>
            <?php echo e(Form::text('sms_twilo_from_number', isset($settings['sms_twilo_from_number']) ? $settings['sms_twilo_from_number'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Twilio From Number'), 'id' => 'sms_twilo_from_number'])); ?>

        </div>


        
        <?php elseif($sms_setting == "clockwork"): ?>
        <div class="form-group col-md-6">
            <label for="clockwork_api_key" class="form-label"><?php echo e(__('Clockwork API Key')); ?></label>
            <?php echo e(Form::text('clockwork_api_key', isset($settings['clockwork_api_key']) ? $settings['clockwork_api_key'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Clockwork API Key'), 'id' => 'clockwork_api_key'])); ?>

        </div>


        
        <?php elseif($sms_setting == "melipayamak"): ?>
        <div class="form-group col-md-4">
            <label for="melipayamak_username" class="form-label"><?php echo e(__('Melipayamak Username')); ?></label>
            <?php echo e(Form::text('melipayamak_username', isset($settings['melipayamak_username']) ? $settings['melipayamak_username'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Melipayamak Username'), 'id' => 'melipayamak_username'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="melipayamak_password" class="form-label"><?php echo e(__('Melipayamak Password')); ?></label>
            <?php echo e(Form::text('melipayamak_password', isset($settings['melipayamak_password']) ? $settings['melipayamak_password'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Secret Key'), 'id' => 'melipayamak_password'])); ?>

        </div>
        <div class="form-group col-md-4">
            <label for="melipayamak_from_number" class="form-label"><?php echo e(__('From Number')); ?></label>
            <?php echo e(Form::text('melipayamak_from_number', isset($settings['melipayamak_from_number']) ? $settings['melipayamak_from_number'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Melipayamak From Number'), 'id' => 'melipayamak_from_number'])); ?>

        </div>


        
        <?php elseif($sms_setting == "kavenegar"): ?>
        <div class="form-group col-md-6">
            <label for="kavenegar_apiKey" class="form-label"><?php echo e(__('Kavenegar Api Key')); ?></label>
            <?php echo e(Form::text('kavenegar_apiKey', isset($settings['kavenegar_apiKey']) ? $settings['kavenegar_apiKey'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter AWS SNS Access Key'), 'id' => 'kavenegar_apiKey'])); ?>

        </div>
        <div class="form-group col-md-6">
            <label for="kavenegar_from_number" class="form-label"><?php echo e(__('From Number')); ?></label>
            <?php echo e(Form::text('kavenegar_from_number', isset($settings['kavenegar_from_number']) ? $settings['kavenegar_from_number'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Kavenegar ApiKey'), 'id' => 'kavenegar_from_number'])); ?>

        </div>


        
        <?php elseif($sms_setting == "smsgatewayme"): ?>
        <div class="form-group col-md-6">
            <label for="smsgatewayme_apiToken" class="form-label"><?php echo e(__('SMS Gateway Me Api Token')); ?></label>
            <?php echo e(Form::text('smsgatewayme_apiToken', isset($settings['smsgatewayme_apiToken']) ? $settings['smsgatewayme_apiToken'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Api Token'), 'id' => 'smsgatewayme_apiToken'])); ?>

        </div>
        <div class="form-group col-md-6">
            <label for="Smsgatewayme_device_id" class="form-label"><?php echo e(__('Default Device ID')); ?></label>
            <?php echo e(Form::text('Smsgatewayme_device_id', isset($settings['Smsgatewayme_device_id']) ? $settings['Smsgatewayme_device_id'] : null,  ['class' => 'form-control', 'placeholder' => __('Enter Default Device ID'), 'id' => 'Smsgatewayme_device_id'])); ?>

        </div>

        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

</div><?php /**PATH /home/storesyria/public_html/packages/workdo/SMS/src/app/Providers/../../resources/views/pages/input.blade.php ENDPATH**/ ?>