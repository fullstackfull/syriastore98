<?php $__env->startSection('page-title', __('Product Brand')); ?>

<?php $__env->startSection('action-button'); ?>
    <?php if (app('laratrust')->hasPermission('Create Product Brand')) : ?>
    <div class=" text-end  gap-2 d-flex all-button-box justify-content-md-end justify-content-end">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(module_is_active('ImportExport')): ?>
            <?php if (app('laratrust')->hasPermission('brand import')) : ?>
                <?php echo $__env->make('import-export::import.brand_import', ['module' => 'brand'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; // app('laratrust')->permission ?>
            <?php if (app('laratrust')->hasPermission('brand export')) : ?>
                <?php echo $__env->make('import-export::export.brand_export', ['module' => 'brand'], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            <?php endif; // app('laratrust')->permission ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
            data-title="<?php echo e(__('Add Brand')); ?>" data-url="<?php echo e(route('product-brand.create')); ?>" data-bs-toggle="tooltip"
            title="<?php echo e(__('Add Brand')); ?>">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    <?php endif; // app('laratrust')->permission ?>

<?php $__env->stopSection(); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><?php echo e(__('Brand')); ?></li>
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
<?php $__env->startPush('scripts'); ?>
    <script>
        $(document).on('change', '.status-index', function() {
            var status = $(this).prop('checked') == true ? 1 : 0;
            var id = $(this).data('id');
            $.ajax({
                type: "GET",
                dataType: "json",
                url: "<?php echo e(route('product-brand.status')); ?>",
                data: {
                    'status': status,
                    'id': id
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status != 'success') {
                        show_toastr('Error', data.message, 'error');
                    } else {
                        show_toastr('Success', data.message, 'success');
                    }
                }
            });
        });

        $(document).on('change', '.popular-index', function() {
            var is_popular = $(this).prop('checked') == true ? 1 : 0;
            var id = $(this).data('id');
            $.ajax({
                type: "GET",
                dataType: "json",
                url: "<?php echo e(route('product-brand.popular')); ?>",
                data: {
                    'is_popular': is_popular,
                    'id': id
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status != 'success') {
                        show_toastr('Error', data.message, 'error');
                    } else {
                        show_toastr('Success', data.message, 'success');
                    }
                }
            });
        });
    </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/product_brand/index.blade.php ENDPATH**/ ?>