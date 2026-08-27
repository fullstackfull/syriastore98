<?php $__env->startSection('page-title', __('Shipping Zone')); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><?php echo e(__('Shipping Zone')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('action-button'); ?>
    <?php if (app('laratrust')->hasPermission('Create Shipping Zone')) : ?>
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
            data-title="<?php echo e(__('Add Shipping Zone')); ?>" data-url="<?php echo e(route('shipping-zone.create')); ?>" data-bs-toggle="tooltip"
            title="<?php echo e(__('Add Shipping Zone')); ?>">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    <?php endif; // app('laratrust')->permission ?>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
    <div class="row">
        <div class="col-xl-12">
            <?php if (isset($component)) { $__componentOriginal8aaf9779783cdf64609094123653a0b9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8aaf9779783cdf64609094123653a0b9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.datatable','data' => ['dataTable' => $dataTable]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('datatable'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['dataTable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dataTable)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8aaf9779783cdf64609094123653a0b9)): ?>
<?php $attributes = $__attributesOriginal8aaf9779783cdf64609094123653a0b9; ?>
<?php unset($__attributesOriginal8aaf9779783cdf64609094123653a0b9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8aaf9779783cdf64609094123653a0b9)): ?>
<?php $component = $__componentOriginal8aaf9779783cdf64609094123653a0b9; ?>
<?php unset($__componentOriginal8aaf9779783cdf64609094123653a0b9); ?>
<?php endif; ?>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/shippingzone/index.blade.php ENDPATH**/ ?>