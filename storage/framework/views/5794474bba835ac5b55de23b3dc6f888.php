
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 <?php echo e($activeTab == 'sms_setting' ? 'active' : ''); ?>" data-url="<?php echo e(route('sms-setting.form')); ?>" data-tab="sms_setting" id="pills-sms_setting-tab" data-bs-toggle="pill" data-bs-target="#eco-sms_setting" type="button" role="tab" aria-controls="pills-sms_setting" aria-selected="false">
    <?php echo e(__('SMS Settings')); ?>

    </a>
    <input type="hidden" name="sms_setting" value="<?php echo e(session()->get('sms_setting')); ?>" id="sms_setting_session">
</li>


<?php $__env->startPush('custom-script'); ?>
<script>
    $(document).ready(function() {
        var sms_setting = $('#sms_setting_session').val();
        getSMSInput(sms_setting);
    });
    $(window).on('load', function () {
        var sms_setting = $('#sms_setting_session').val();
        getSMSInput(sms_setting)
    });

    $(document).on('change', '#sms_setting', function() {
        var sms_setting = $('#sms_setting').val();
        $('#loader').fadeIn();
        getSMSInput(sms_setting);
    });

    function getSMSInput(sms_setting) {        
        $.ajax({
            url: '<?php echo e(route("get.sms.fields")); ?>',
            type: 'POST',

            data: {
                "_token": "<?php echo e(csrf_token()); ?>",
                "sms_setting": sms_setting,
            },
            success: function(data) {
                $('#loader').fadeOut();
                $('#getsmsfields').empty();
                $('#getsmsfields').append(data.html)
                $('.email').append(data.html)
            },

        });
    }
</script>
<?php $__env->stopPush(); ?>   <?php /**PATH /home/storesyria/public_html/packages/workdo/SMS/src/app/Providers/../../resources/views/pages/tab.blade.php ENDPATH**/ ?>