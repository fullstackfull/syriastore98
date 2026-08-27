<!-- Payment Setting -->
<div class="card" id="Payment_Setting">
    <div class="card-header">
        <div class="float-end">
            <div class="badge bg-success p-2 px-3 rounded"></div>
        </div>
        <h5><?php echo e(__('Payment Settings')); ?></h5>
        <small class="text-muted"><?php echo e(__('Configure your payment gateways for accepting online payments')); ?></small>
    </div>
    <?php echo e(Form::open(['route' => 'payment.settings', 'method' => 'post', 'enctype' => 'multipart/form-data'])); ?>

    <div class="card-body">
        <div class="faq">
            <div class="row">
                <div class="col-12">
                    <div class="accordion accordion-flush" id="payment-gateways">
                       

                        <div class="mb-3">
                            <div class="search-box">
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-light"><i class="ti ti-search"></i></span>
                                    <input type="text" class="form-control border-light" id="payment-search" placeholder="<?php echo e(__('Search payment gateway...')); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="gateway-section">
                            <!-- Admin-only gateways -->
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $paymentGateways; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $gateway): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user() && auth()->user()->type == 'admin' && isset($gateway['is_only_admin']) && ($gateway['is_only_admin'] == true || $gateway['is_only_admin'] == false)): ?>
                                        <?php echo $__env->make('setting.payment_partials.payment_gateway', ['gateway' => $gateway], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                    <?php elseif(auth()->user() && auth()->user()->type != 'admin' && isset($gateway['is_only_admin']) && $gateway['is_only_admin'] == false): ?>
                                    <?php echo $__env->make('setting.payment_partials.payment_gateway', ['gateway' => $gateway], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap">
        <input type="submit" value="<?php echo e(__('Save Changes')); ?>" class="btn-submit btn btn-primary">
    </div>
    <?php echo Form::close(); ?>

</div>
<!-- End Payment Setting --><?php /**PATH /home/storesyria/public_html/resources/views/setting/payment_setting.blade.php ENDPATH**/ ?>