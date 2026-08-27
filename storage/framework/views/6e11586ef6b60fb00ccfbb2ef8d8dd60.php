<div class="tab-pane fade <?php if(isset($app_setting_tab) && $app_setting_tab == 'pills-recent_orders-tab'): ?> show active <?php endif; ?>" id="pills-recent_orders" role="tabpanel"
    aria-labelledby="pills-recent_orders-tab">
    <div id="recent_orders_settings">
        <div class="card">
            <?php echo e(Form::model($setting, ['route' => 'recent.orders.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-recent_orders-tab">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class=""> <?php echo e(__('Recent Orders Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                            class="recent_orders_enabled" name="recent_orders_enabled" id="recent_orders_enabled"
                            <?php echo e(isset($setting['recent_orders_enabled']) && $setting['recent_orders_enabled'] == 'on' ? 'checked' : ''); ?>>
                        <label class="custom-control-label" for="recent_orders_enabled"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row recent_orders-settings faq mt-2">
                    
                        
                            
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <?php echo e(Form::label('recent_orders', __('Recent Orders'), ['class' => 'form-label'])); ?>

                                        <?php echo e(Form::number('recent_orders', null, ['class' => 'form-control', 'placeholder' => 'Recent Orders'])); ?>

                                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['recent_orders'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                            <span class="invalid-recent_orders" role="alert">
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
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary btn-badge">
            </div>
            <?php echo Form::close(); ?>

        </div>
    </div>
</div>

<?php $__env->startPush('custom-script'); ?>
    <script>
        if ($('.recent_orders_enabled').is(':checked')) {
            $('.recent_orders-settings').removeClass('disabledCookie');
        } else {
            $('.recent_orders-settings').addClass('disabledCookie');
        }
        $('.recent_orders_enabled').on('change', function() {
            if ($('.recent_orders_enabled').is(':checked')) {
                $('.recent_orders-settings').removeClass('disabledCookie');
            } else {
                $('.recent_orders-settings').addClass('disabledCookie');
            }
        });
    </script>
<?php $__env->stopPush(); ?>
<?php /**PATH /home/storesyria/public_html/packages/workdo/RecentOrders/src/app/Providers/../../resources/views/admin/recentOrdersSettingDetails.blade.php ENDPATH**/ ?>