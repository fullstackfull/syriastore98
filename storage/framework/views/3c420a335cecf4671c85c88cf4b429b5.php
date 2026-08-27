<?php $__env->startSection('page-title'); ?>
    <?php echo e(__('Product')); ?>

<?php $__env->stopSection(); ?>
<?php
    $logo = asset(Storage::url('uploads/profile/'));
    $admin = getAdminAllSetting();
?>
<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item" aria-current="page"><?php echo e(__('Product')); ?></li>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('action-button'); ?>
    <?php if (app('laratrust')->hasPermission('Create Product')) : ?>
    <div class="text-end gap-1 d-flex all-button-box justify-content-md-end justify-content-center">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ImportExport')): ?>
            <?php if (app('laratrust')->hasPermission('product import')) : ?>
                <?php echo $__env->make('import-export::import.button', ['module' => 'product'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; // app('laratrust')->permission ?>
            <?php if (app('laratrust')->hasPermission('product export')) : ?>
                <?php echo $__env->make('import-export::export.button', ['module' => 'product'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; // app('laratrust')->permission ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('HubSpot')): ?>
            <?php if (app('laratrust')->hasPermission('hubspot manage')) : ?>
                <?php echo $__env->make('hub-spot::product.button', ['module' => 'product'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; // app('laratrust')->permission ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>


        <a href="<?php echo e(route('product.list')); ?>" data-bs-toggle="tooltip" data-bs-original-title="<?php echo e(__('Grid View')); ?>" class="btn btn-sm btn-primary btn-icon ">
            <i class="ti ti-layout-grid"></i>
        </a>

        <a href="<?php echo e(route('product.create')); ?>" class="btn btn-sm btn-primary" data-title="<?php echo e(__('Create New Product')); ?>"
            data-bs-toggle="tooltip" title="<?php echo e(__('Add New Product')); ?>">
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
<?php $__env->startPush('custom-script'); ?>
<script>
    $(document).ready(function() {
        var successMsg = localStorage.getItem('success_msg');
        if (successMsg) {
            show_toastr('Success', successMsg, 'success');
            localStorage.removeItem('success_msg');
        }
    });
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/product/index.blade.php ENDPATH**/ ?>