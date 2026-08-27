<div class="tab-pane fade <?php if(isset($app_setting_tab) && ($app_setting_tab == 'pills-quick_checkout-tab')): ?> show active <?php endif; ?>" id="pills-quick_checkout" role="tabpanel" aria-labelledby="pills-quick_checkout-tab">
    <div id="quick_checkout_settings">
        <div class="card">
            <?php echo e(Form::model($settings, ['route' => ['update.quick.checkout.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

            <?php echo csrf_field(); ?>
            <input type="hidden" name="app_setting_tab" value="pills-quick_checkout-tab">
            <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class=""> <?php echo e(__('Quick Checkout Settings')); ?> </h5>
                    <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                        <div class="custom-control custom-switch">
                            <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="enable_quick_checkout"
                                id="enable_quick_checkout" <?php echo e($settings['enable_quick_checkout'] ?? '' == 'on' ? 'checked="checked"' : ''); ?>>
                            <label class="custom-control-label" for="enable_quick_checkout"></label>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row mt-2">
                        <div class="form-group col-md-6">
                                <?php echo e(Form::label('quick_button_title', __('Add Button Title'), ['class' => 'col-form-label'])); ?>

                                <?php echo e(Form::text('quick_button_title', null, array('class' => 'form-control','id'=>"store-locate-address",'required'=>'required','placeholder' => 'Enter Your Button Title'))); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['quick_button_title'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                    <span class="invalid-quick_button_title" role="alert">
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

<?php /**PATH /home/storesyria/public_html/packages/workdo/QuickCheckout/src/app/Providers/../../resources/views/admin/checkout-details.blade.php ENDPATH**/ ?>