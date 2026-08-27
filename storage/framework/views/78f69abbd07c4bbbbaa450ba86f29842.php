<div class="tab-pane fade" id="pills-duo2FA" role="tabpanel" aria-labelledby="pills-duo2FA-tab">
    <div id="duo2fa_setting">
        <div class="card">
            <?php echo e(Form::model($store, ['route' => ['duo2fa.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data'])); ?>

                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class=""> <?php echo e(__('Duo2FA Setting')); ?> </h5>
                    <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                    <?php echo Form::hidden('duo_setting_enabled', 'off'); ?>

                        <div class="custom-control custom-switch">
                            <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                            data-onstyle="primary" name="duo_setting_enabled" id="duo_setting_enabled"
                            <?php echo e(isset($store->duo_setting_enabled) && $store->duo_setting_enabled == 'on' ? 'checked="checked"' : ''); ?>>
                        </div>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="row mt-2">
                        <div class="form-group col-md-6">
                            
                                <?php echo Form::label('duo_api_host_name', __('Duo API Host Name'), ['class' => 'form-label']); ?>

                                <?php echo Form::text(
                                    'duo_api_host_name',
                                    !empty($store->duo_api_host_name) ? $store->duo_api_host_name : '',
                                    [
                                        'class' => 'form-control',
                                        'placeholder' => 'Enter Here...',
                                    ],
                                ); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['duo_api_host_name'];
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
                        <div class="form-group col-md-6">
                            
                                <?php echo Form::label('duo_secret_key', __('Duo Secret Key'), ['class' => 'form-label']); ?>

                                <?php echo Form::text(
                                    'duo_secret_key',
                                    !empty($store->duo_secret_key) ? $store->duo_secret_key : '',
                                    ['class' => 'form-control', 'placeholder' => 'Enter Here...'],
                                ); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['duo_secret_key'];
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
                        <div class="form-group col-md-6">
                            
                            <?php echo Form::label('duo_integration_key', __('Duo Integration Key'), ['class' => 'form-label']); ?>

                            <?php echo Form::text(
                                'duo_integration_key',
                                !empty($store->duo_integration_key) ? $store->duo_integration_key : '',
                                ['class' => 'form-control', 'placeholder' => 'Enter Here...'],
                            ); ?>

                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['duo_integration_key'];
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
<?php /**PATH /home/storesyria/public_html/packages/workdo/Duo2FA/src/app/Providers/../../resources/views/pages/tab_form.blade.php ENDPATH**/ ?>