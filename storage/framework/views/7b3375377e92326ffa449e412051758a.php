<div class="tab-pane fade  <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-free_shipping_popup-tab')): ?> show active <?php endif; ?>" id="pills-free_shipping_popup" role="tabpanel" aria-labelledby="pills-free_shipping_popup-tab">
    <div id="free_shipping_popup_settings">
        <div class="card">
            <?php echo e(Form::model($setting, ['route' => 'free.shipping.popup.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-free_shipping_popup-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> <?php echo e(__('Free Shipping Pop-Up Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="free_shipping_popup_enabled"
                            id="free_shipping_popup_enabled" <?php echo e($setting['free_shipping_popup_enabled'] ?? '' == 'on' ? 'checked="checked"' : ''); ?>>
                        <label class="custom-control-label" for="free_shipping_popup_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="col-md-6 free-shipping-settings">
                        <div class="form-group">
                            <?php echo e(Form::label('free_shipping_apply_price', __('Frre Shipping Apply Price'), ['class' => 'form-label'])); ?>

                            <?php echo e(Form::number('free_shipping_apply_price', null, ['class' => 'form-control free_shipping_apply_price', 'placeholder' => 'Enter Frre Shipping Apply Price'])); ?>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['free_shipping_apply_price'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <span class="invalid-free_shipping_apply_price" role="alert">
                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                </span>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            <span class="text-muted"><?php echo e(__('Note: Enter the minimum price from which you want the free shipping price to apply.')); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary btn-badge">
                
            </div>
            <?php echo Form::close(); ?>

        </div>
    </div>
</div>

<?php $__env->startPush('custom-script'); ?>
    <script>
        if ($('#free_shipping_popup_enabled').is(':checked')) {

            $('.free-shipping-settings').removeClass('disabledCookie');
            $('.free_shipping_apply_price').attr("required", true);
            $('.free_shipping_apply_price').attr("min", 1);
        } else {

            $('.free-shipping-settings').addClass('disabledCookie');
            $('.free_shipping_apply_price').removeAttr("required");
            $('.free_shipping_apply_price').removeAttr("min");
        }

        $('#free_shipping_popup_enabled').on('change', function() {
            if ($('#free_shipping_popup_enabled').is(':checked')) {

                $('.free-shipping-settings').removeClass('disabledCookie');
                $('.free_shipping_apply_price').attr("required", true);
                $('.free_shipping_apply_price').attr("min", 1);
            } else {

                $('.free-shipping-settings').addClass('disabledCookie');
                $('.free_shipping_apply_price').removeAttr("required");
                $('.free_shipping_apply_price').removeAttr("min");
            }
        });
    </script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/FreeShippingPopup/src/app/Providers/../../resources/views/admin/freeShippingSettingDetails.blade.php ENDPATH**/ ?>