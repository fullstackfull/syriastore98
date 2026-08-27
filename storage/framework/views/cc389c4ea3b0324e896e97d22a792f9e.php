<?php $__env->startSection('page-title', __('Delivery Syria')); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item">
        <a href="<?php echo e(route('dashboard')); ?>">
            <?php echo e(__('Dashboard')); ?>

        </a>
    </li>

    <li class="breadcrumb-item">
        <?php echo e(__('Delivery Syria')); ?>

    </li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
    <div class="row">

        
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><?php echo e(__('Wallet')); ?></h5>
                </div>

                <div class="card-body">
                    <?php
                        $balances = $wallet['balances_by_currency'] ?? [];
                        $walletDetails = $wallet['details'] ?? [];
                    ?>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($balances)): ?>
                        <div class="row">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $balances; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $currency => $balance): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="border rounded p-3 h-100">
                                        <div class="text-muted">
                                            <?php echo e(__('Available Balance')); ?>

                                        </div>

                                        <div class="fs-4 fw-bold">
                                            <?php echo e($balance); ?> <?php echo e($currency); ?>

                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">
                            <?php echo e(__('No wallet balance is available.')); ?>

                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($walletDetails)): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered">
                                <thead>
                                    <tr>
                                        <th><?php echo e(__('Type')); ?></th>
                                        <th><?php echo e(__('Currency')); ?></th>
                                        <th><?php echo e(__('Balance')); ?></th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $walletDetails; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $detail): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                        <tr>
                                            <td>
                                                <?php echo e(ucfirst($detail['type'] ?? '-')); ?>

                                            </td>

                                            <td>
                                                <?php echo e($detail['currency'] ?? '-'); ?>

                                            </td>

                                            <td>
                                                <?php echo e($detail['balance'] ?? '0.00'); ?>

                                            </td>
                                        </tr>
                                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </div>

        
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0"><?php echo e(__('Parcels')); ?></h5>

                    <div class="btn-group">
                        <a
                            href="<?php echo e(route('delivery-syria.index', ['visible' => 0])); ?>"
                            class="btn btn-sm <?php echo e($visible === 0 ? 'btn-primary' : 'btn-outline-primary'); ?>"
                        >
                            <?php echo e(__('Awaiting Transfer')); ?>

                        </a>

                        <a
                            href="<?php echo e(route('delivery-syria.index', ['visible' => 1])); ?>"
                            class="btn btn-sm <?php echo e($visible === 1 ? 'btn-primary' : 'btn-outline-primary'); ?>"
                        >
                            <?php echo e(__('Transferred')); ?>

                        </a>
                    </div>
                </div>

                <div class="card-body">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($errorMessage)): ?>
                        <div class="alert alert-danger">
                            <?php echo e($errorMessage); ?>

                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th><?php echo e(__('Tracking ID')); ?></th>
                                    <th><?php echo e(__('Invoice')); ?></th>
                                    <th><?php echo e(__('Customer')); ?></th>
                                    <th><?php echo e(__('Phone')); ?></th>
                                    <th><?php echo e(__('Address')); ?></th>
                                    <th><?php echo e(__('Weight')); ?></th>
           
                                    <th><?php echo e(__('Company')); ?></th>
                                    <th><?php echo e(__('Delivery Charge')); ?></th>
                                    <th><?php echo e(__('COD')); ?></th>
                                    <th><?php echo e(__('Status')); ?></th>
                                    <th><?php echo e(__('Created At')); ?></th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $parcels; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $parcel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?php echo e($parcel['tracking_id'] ?? '-'); ?>

                                            </strong>
                                        </td>

                                        <td>
                                            <?php echo e($parcel['invoice_no'] ?? '-'); ?>

                                        </td>

                                        <td>
                                            <?php echo e($parcel['customer_name'] ?? '-'); ?>

                                        </td>

                                        <td>
                                            <?php echo e($parcel['customer_phone'] ?? '-'); ?>

                                        </td>

                                        <td style="min-width: 220px;">
                                            <?php echo e($parcel['customer_address'] ?? '-'); ?>

                                        </td>

                                        <td>
                                            <?php echo e($parcel['weight'] ?? '-'); ?>

                                        </td>

                            

                                        <td>
                                            <?php echo e($parcel['company_name'] ?? '-'); ?>

                                        </td>

                                        <td>
                                            <?php echo e($parcel['delivery_charge'] ?? '0.00'); ?>

                                        </td>

                                        <td>
                                            <?php echo e($parcel['cod_amount'] ?? '0.00'); ?>

                                        </td>

                                        <td>
                                            <span class="badge bg-info">
                                                <?php echo e($parcel['statusName'] ?? __('Unknown')); ?>

                                            </span>
                                        </td>

                                        <td>
                                            <?php echo e($parcel['created_at'] ?? '-'); ?>

                                        </td>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                                    <tr>
                                        <td colspan="12" class="text-center py-4 text-muted">
                                            <?php echo e(__('No parcels found.')); ?>

                                        </td>
                                    </tr>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!empty($meta) && ($meta['last_page'] ?? 1) > 1): ?>
                        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3">
                            <div class="text-muted">
                                <?php echo e(__('Page')); ?>

                                <?php echo e($meta['current_page'] ?? 1); ?>

                                <?php echo e(__('of')); ?>

                                <?php echo e($meta['last_page'] ?? 1); ?>


                                —
                                <?php echo e($meta['total'] ?? 0); ?>

                                <?php echo e(__('parcels')); ?>

                            </div>

                            <div class="btn-group">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($meta['current_page'] ?? 1) > 1): ?>
                                    <a
                                        href="<?php echo e(route('delivery-syria.index', [
                                            'visible' => $visible,
                                            'page' => ($meta['current_page'] ?? 1) - 1,
                                        ])); ?>"
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        <?php echo e(__('Previous')); ?>

                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                                <?php if(($meta['current_page'] ?? 1) < ($meta['last_page'] ?? 1)): ?>
                                    <a
                                        href="<?php echo e(route('delivery-syria.index', [
                                            'visible' => $visible,
                                            'page' => ($meta['current_page'] ?? 1) + 1,
                                        ])); ?>"
                                        class="btn btn-outline-primary btn-sm"
                                    >
                                        <?php echo e(__('Next')); ?>

                                    </a>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/delivery-syria/index.blade.php ENDPATH**/ ?>