<?php $__env->startSection('page-title', __('Order')); ?>

<?php $__env->startSection('action-button'); ?>
<div class="text-end">
    <a class="btn btn-sm btn-primary btn-icon export-btn" href="<?php echo e(route('order.export')); ?>" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo e(__('Export')); ?>" filename="<?php echo e(__('Order')); ?>">
        <i  class="ti ti-file-export"></i>
    </a>
    <a href="<?php echo e(route('order.grid')); ?>" data-bs-toggle="tooltip" data-bs-original-title="<?php echo e(__('Grid View')); ?>" class="btn btn-sm btn-primary btn-icon ">
        <i class="ti ti-layout-grid"></i>
    </a>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><?php echo e(__('Order')); ?></li>
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
        $(document).on('click', '.code', function() {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '.return_request', function() {
            var id = $(this).attr('data-id');
            var status = $(this).attr('data-status');
            var data = {
                id: id,
                status: status
            }
            $.ajax({
                url: '<?php echo e(route('order.return.request')); ?>',
                method: 'POST',
                data: data,
                context:this,
                success: function (response)
                {
                    $('#loader').fadeOut();
                    if(response.status == 'error') {
                        show_toastr('<?php echo e(__('Error')); ?>', response.message, 'error')
                    } else {
                        show_toastr('<?php echo e(__('Success')); ?>', response.message, 'success')
                        $(this).parent().find('.return_request').remove();
                    }
                }
            });
        });

        $(document).on('click', '#code-generate', function() {
            var length = 10;
            var result = '';
            var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var charactersLength = characters.length;
            for (var i = 0; i < length; i++) {
                result += characters.charAt(Math.floor(Math.random() * charactersLength));
            }
            $('#auto-code').val(result);
        });
    </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/order/index.blade.php ENDPATH**/ ?>