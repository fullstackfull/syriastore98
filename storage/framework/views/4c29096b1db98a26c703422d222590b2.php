<div class="tab-pane fade <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-purchase_notification-tab'): ?> show active <?php endif; ?>" id="pills-purchase_notification"
    role="tabpanel" aria-labelledby="pills-purchase_notification-tab">
    <div id="purchase_notification_settings">
        <div class="card">
            <?php echo e(Form::model($setting, ['route' => 'purchase.notification.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <?php echo csrf_field(); ?>
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> <?php echo e(__('Purchase Notification Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                            class="purchase_notification_enabled" name="purchase_notification_enabled"
                            id="purchase_notification_enabled"
                            <?php echo e(isset($setting['purchase_notification_enabled']) && $setting['purchase_notification_enabled'] == 'on' ? 'checked' : ''); ?>>
                        <label class="custom-control-label" for="purchase_notification_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2 purchase-notification">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <div>
                                <h5> <?php echo e(__('Product Setting')); ?> </h5>
                            </div>
                        </div>
                        <div class="card-body slider-body-rows">
                            <div class="row">
                                <?php
                                    $get_product =
                                        isset($setting['product_id']) && !empty($setting['product_id'])
                                            ? explode(',', $setting['product_id'])
                                            : [];
                                ?>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <?php echo e(Form::label('product_id', __('Select Products'), ['class' => ' form-label'])); ?>

                                        <select name="product_id[]" data-role="tagsinput" id="product_id" multiple>
                                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $Key => $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                                <option <?php if(in_array($product->id, $get_product)): ?> selected <?php endif; ?>
                                                    value=<?php echo e($product->id); ?>>
                                                    <?php echo e($product->name); ?></option>
                                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <div class="custom-control form-switch p-0 ">
                                            <label class="form-check-label mb-2"
                                                for="open_product_page"><?php echo e(__('Open Product Page')); ?></label><br>
                                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                                data-onstyle="primary" name="open_product_page" id="open_product_page"
                                                <?php echo e(isset($setting['open_product_page']) && $setting['open_product_page'] == 'on' ? 'checked="checked"' : ''); ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <div>
                                <h5> <?php echo e(__('Time Setting')); ?> </h5>
                            </div>
                        </div>
                        <div class="card-body slider-body-rows">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <?php echo e(Form::label('next_time_display', __('Next Time Display'), ['class' => 'form-label'])); ?>

                                        <?php echo e(Form::number('next_time_display', null, ['class' => 'form-control', 'placeholder' => 'Next Time Display'])); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['next_time_display'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-next_time_display" role="alert">
                                                <strong class="text-danger"><?php echo e($message); ?></strong>
                                            </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <?php echo e(Form::label('display_time', __('Display Time'), ['class' => 'form-label'])); ?>

                                        <?php echo e(Form::number('display_time', null, ['class' => 'form-control', 'placeholder' => 'Display Time'])); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['display_time'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-display_time" role="alert">
                                                <strong class="text-danger"><?php echo e($message); ?></strong>
                                            </span>
                                        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    </div>
                                </div>
                            </div>
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
        if ($('.purchase_notification_enabled').is(':checked')) {
            $('.purchase-notification').removeClass('disabledCookie');
        } else {
            $('.purchase-notification').addClass('disabledCookie');
        }

        $('.purchase_notification_enabled').on('change', function() {
            if ($('.purchase_notification_enabled').is(':checked')) {
                $('.purchase-notification').removeClass('disabledCookie');
            } else {
                $('.purchase-notification').addClass('disabledCookie');
            }
        });
    </script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/PurchaseNotification/src/app/Providers/../../resources/views/admin/purchaseNotificationSettingDetails.blade.php ENDPATH**/ ?>