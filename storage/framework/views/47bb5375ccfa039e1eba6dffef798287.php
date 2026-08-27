<div class="tab-pane fade <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-store_donation-tab')): ?> show active <?php endif; ?>" id="pills-store_donation" role="tabpanel" aria-labelledby="pills-store_donation-tab">
    <div id="store_donation_settings">
        <div class="card">
            <?php echo e(Form::model($donations, ['route' => ['update.store.donation.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <input type="hidden" name="app_setting_tab" value="pills-store_donation-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class=""> <?php echo e(__('Donation Settings')); ?> </h5>
                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="enable_donation"
                            id="enable_donation" <?php echo e($donations['enable_donation'] ?? '' == 'on' ? 'checked="checked"' : ''); ?>>
                        <label class="custom-control-label" for="enable_donation"></label>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row mt-2">
                    <div class="form-group col-md-6">
                            <?php echo e(Form::label('payment_method', __('Payment Methods'), ['class' => 'col-form-label'])); ?>

                            <?php echo Form::select('payment_methods[]', $Payments ?? [], $enablePaymentdata ?? [], [
                                    'class' => 'form-control payment_method',
                                    'id' => 'payment_method',
                                    'multiple',
                                    'data-role' => 'tagsinput',
                                ]); ?>

                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['payment_method'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                <span class="invalid-payment_method" role="alert">
                                    <strong class="text-danger"><?php echo e($message); ?></strong>
                                </span>
                            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end flex-wrap ">
                <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary btn-badge">
            </div>
            <?php echo e(Form::close()); ?>

        </div>
    </div>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/Donation/src/app/Providers/../../resources/views/admin/donation-details.blade.php ENDPATH**/ ?>