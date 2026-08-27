    <div class="tab-pane fade show active" id="eco-1" role="tabpanel" aria-labelledby="pills-home-tab">
        <!--Start Email Setting-->
        <div class="card" id="email-settings">
            <div class="email-setting-wrap ">
                <?php echo e(Form::open(['route' => 'email.settings', 'method' => 'post', 'enctype' => 'multipart/form-data'])); ?>

                <?php echo csrf_field(); ?>
                <input type="hidden" class="email">
                <div class="card-header">
                    <h3 class="h5"><?php echo e(__('Email Settings')); ?></h3>
                </div>
            <div class="card-body pb-0">
                <div class="d-flex">
                    <div class="col-sm-6 col-12">

                        <div class="form-group col switch-width">
                            <?php echo e(Form::label('email_setting', __('Email Setting'), ['class' => ' col-form-label'])); ?>


                            <?php echo e(Form::select('email_setting', $email_setting ?? [], isset($setting['email_setting']) ? $setting['email_setting'] : $get_setting, ['id' => 'email_setting', 'class' => 'form-control choices', 'searchEnabled' => 'true'])); ?>

                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12" id="getfields">
                    </div>
                </div>

            </div>

            <div class="card-footer d-flex justify-content-between flex-wrap" style="gap:10px">

                <input type="hidden" name="custom_email" id="custom_email"
                    value="<?php echo e(isset($settings['email_setting']) ? $settings['email_setting'] : $get_setting); ?>">

                <div class="text-start ">
                    <a href="#" data-ajax-popup1="true" data-size="md" data-title="<?php echo e(__('Send Test Mail')); ?>"
                        data-url="<?php echo e(route('email.test')); ?>" data-toggle="tooltip" title="<?php echo e(__('Send Test Mail')); ?>"
                        class="btn btn-print-invoice btn-badge btn-primary m-r-10 send_email">
                        <?php echo e(__('Send Test Mail')); ?>

                    </a>
                </div>

                <input class="btn btn-print-invoice btn-badge btn-primary m-r-10" type="submit" value="<?php echo e(__('Save Changes')); ?>">
            </div>
            <?php echo e(Form::close()); ?>

        </div>
    </div>
    <!--End Email Setting-->
</div>

<?php $__env->startPush('custom-script'); ?>
<script>
    $(document).ready(function() {
        var emailSetting = $('#email_setting').val();
        getEmailSettingFields(emailSetting);

    });
    
</script>
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/resources/views/setting/email_setting.blade.php ENDPATH**/ ?>