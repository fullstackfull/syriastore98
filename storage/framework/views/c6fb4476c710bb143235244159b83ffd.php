<div class="accordion-item card mb-3">
    <h2 class="accordion-header" id="<?php echo e($gateway['id']); ?>">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
            data-bs-target="#collapseone_<?php echo e($gateway['id']); ?>" aria-expanded="false"
            aria-controls="collapseone_<?php echo e($gateway['id']); ?>">
            <span class="d-flex align-items-center w-100 justify-content-between">
                <div>
                    <i class="ti ti-credit-card me-2"></i><?php echo e(__($gateway['name'])); ?>

                </div>
                <div class="form-check form-switch d-inline-block me-2">
                    <?php echo Form::checkbox(
                        $gateway['enable_key'],
                        'on',
                        isset($setting[$gateway['enable_key']]) && $setting[$gateway['enable_key']] === 'on',
                        [
                            'class' => 'form-check-input',
                            'id' => $gateway['enable_key'],
                        ],
                    ); ?>

                    <label class="custom-control-label form-control-label"
                        for="<?php echo e($gateway['enable_key']); ?>"></label>
                </div>
            </span>
        </button>
    </h2>
    <div id="collapseone_<?php echo e($gateway['id']); ?>" class="accordion-collapse collapse"
        aria-labelledby="<?php echo e($gateway['id']); ?>" data-bs-parent="#payment-gateways">
        <div class="accordion-body">
            <div class="row">
                <div class="col-md-12 mb-2">
                    <small class="text-muted">
                        <i class="ti ti-info-circle me-1"></i>
                        <?php echo e(__('This configuration will be used for product checkout processing.')); ?>

                    </small>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($gateway['mode_options'])): ?>
                <div class="col-lg-12 pb-3">
                    <h6 class="mb-2"><?php echo e(__($gateway['name'].' Mode')); ?></h6>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $gateway['mode_options']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mode => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="mode-selector">
                            <div class="p-3 <?php echo e((isset($setting[$gateway['id'].'_mode']) && $setting[$gateway['id'].'_mode'] == $mode) || (!isset($setting[$gateway['id'].'_mode']) && $mode == 'sandbox') ? 'border-primary' : ''); ?>">
                                <div class="form-check">
                                    <input type="radio" name="<?php echo e($gateway['id']); ?>_mode" value="<?php echo e($mode); ?>"
                                        class="form-check-input" id="<?php echo e($gateway['id']); ?>_<?php echo e($mode); ?>"
                                        <?php echo e((isset($setting[$gateway['id'].'_mode']) && $setting[$gateway['id'].'_mode'] == $mode) || (!isset($setting[$gateway['id'].'_mode']) && $mode == 'sandbox') ? 'checked="checked"' : ''); ?>>
                                    <label class="form-check-label text-dark" for="<?php echo e($gateway['id']); ?>_<?php echo e($mode); ?>">
                                        <?php echo e(__($label)); ?>

                                    </label>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <div class="row g-3">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($gateway['fields'])): ?>
                    <div class="col-md-<?php echo e(count($gateway['fields']) <= 2 ? '8' : '7'); ?>">
                        <div class="row g-3">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $gateway['fields']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $field): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="col-md-<?php echo e(count($gateway['fields']) <= 2 ? '12' : '6'); ?>">
                                <div class="form-group">
                                    <label for="<?php echo e($field['key']); ?>" class="form-label"><?php echo e(__($field['label'])); ?></label>
                                    <input class="form-control" placeholder="<?php echo e(__('Enter').' '.__($field['label'])); ?>"
                                        name="<?php echo e($field['key']); ?>" id="<?php echo e($field['key']); ?>" type="text" 
                                        value="<?php echo e($setting[$field['key']] ?? ''); ?>">
                                </div>
                            </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-<?php echo e(count($gateway['fields']) <= 2 ? '4' : '5'); ?>">
                        <div class="form-group">
                            <label class="form-label d-block"><?php echo e(__('Gateway Image')); ?></label>
                            <div class="border p-3 rounded bg-light text-center position-relative">
                                <label for="<?php echo e($gateway['image_key']); ?>" class="btn btn-sm btn-primary position-absolute" style="top:5px; right:5px;">
                                    <i class="ti ti-upload"></i>
                                </label>
                                <input type="file" name="<?php echo e($gateway['image_key']); ?>" id="<?php echo e($gateway['image_key']); ?>" class="d-none">
                                <img alt="<?php echo e(__($gateway['name']).' '.__('Image')); ?>"
                                    src="<?php echo e(get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default']))); ?>"
                                    class="img-fluid p-1" style="max-height: 100px; max-width: 100%;">
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="<?php echo e($gateway['description_key']); ?>" class="form-label"><?php echo e(__('Description')); ?></label>
                            <?php echo Form::textarea(
                                $gateway['description_key'], 
                                $setting[$gateway['description_key']] ?? $gateway['description_default'] ?? '', 
                                [
                                    'class' => 'form-control',
                                    'placeholder' => __('Enter Description'),
                                    'rows' => '4',
                                    'id' => $gateway['description_key'],
                                ]
                            ); ?>

                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label class="form-label d-block"><?php echo e(__('Gateway Image')); ?></label>
                            <div class="border p-3 rounded bg-light text-center position-relative">
                                <label for="<?php echo e($gateway['image_key']); ?>" class="btn btn-sm btn-primary position-absolute" style="top:5px; right:5px;">
                                    <i class="ti ti-upload"></i>
                                </label>
                                <input type="file" name="<?php echo e($gateway['image_key']); ?>" id="<?php echo e($gateway['image_key']); ?>" class="d-none">
                                <img alt="<?php echo e(__($gateway['name']).' '.__('Image')); ?>"
                                    src="<?php echo e(get_file($setting[$gateway['image_key']] ?? Storage::url($gateway['image_default'])) ?? asset(Storage::url($gateway['image_default']))); ?>"
                                    class="img-fluid p-1" style="max-height: 100px; max-width: 100%;">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(\Auth::user()->type == 'admin' && isset($gateway['description_key']) && isset($gateway['fields'])): ?>
                <div class="col-md-12 mt-3">
                    <div class="form-group">
                        <label for="<?php echo e($gateway['description_key']); ?>" class="form-label"><?php echo e(__('Description')); ?></label>
                        <?php echo Form::textarea(
                            $gateway['description_key'], 
                            $setting[$gateway['description_key']] ?? '', 
                            [
                                'class' => 'form-control',
                                'placeholder' => __('Enter Description'),
                                'rows' => '3',
                                'id' => $gateway['description_key'],
                            ]
                        ); ?>

                        <small class="form-text text-muted">
                            <?php echo e(__('This description will be shown to customers during checkout.')); ?>

                        </small>
                    </div>
                </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </div>
</div><?php /**PATH /home/storesyria/public_html/resources/views/setting/payment_partials/payment_gateway.blade.php ENDPATH**/ ?>