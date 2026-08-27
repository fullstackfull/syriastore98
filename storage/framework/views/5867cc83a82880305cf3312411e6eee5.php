<?php $__env->startSection('page-title', __('Attribute-Option')); ?>

<?php $__env->startSection('action-button'); ?>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($attribute): ?>
<?php if (app('laratrust')->hasPermission('Create Attributes Option')) : ?>
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" data-ajax-popup="true" data-size="md"
        data-title="<?php echo e(__('Add Attribute Option')); ?>"
        data-url="<?php echo e(route('product-attribute-option.create',$attribute->id)); ?>"
        data-bs-toggle="tooltip" title="<?php echo e(__('Add Attribute')); ?>">
        <i class="ti ti-plus"></i>
    </a>
</div>
<?php endif; // app('laratrust')->permission ?>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('breadcrumb'); ?>
    <li class="breadcrumb-item"><a href="<?php echo e(route('product-attributes.index')); ?>"><?php echo e(__('Attribute')); ?></a></li>
    <li class="breadcrumb-item"><?php echo e(__('Attribute-options')); ?></li>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('content'); ?>
<div class="row justify-content-center">
    <div class="col-sm-12 col-md-10 col-xxl-8">
        <div class="card mt-5">
            <div class="card-body">
                <div class="tab-content" id="pills-tabContent">
                    <?php ($i=0); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $attribute_option; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <div class="tab-pane fade show  <?php if($i==0): ?> active <?php endif; ?>" role="tabpanel">
                            <ul class="list-unstyled list-group sortable stage">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $attribute_option; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $option): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <li class="d-flex align-items-center gap-1 justify-content-between list-group-item p-3" data-id="<?php echo e($option->id); ?>">
                                        <h6 class="mb-0 text-break flex-1 d-flex  align-items-center">
                                            <i class="me-3 ti ti-arrows-maximize " data-feather="move"></i>
                                            <span class="flex-1"><?php echo e($option->terms); ?></span>
                                        </h6>
                                        <span class="float-end">
                                            <?php if (app('laratrust')->hasPermission('Edit Attributes Option')) : ?>
                                            <button class="btn btn-sm btn-info"
                                                data-url="<?php echo e(route('product-attribute-option.edit', $option->id)); ?>"
                                                data-size="md" data-ajax-popup="true"
                                                data-title="<?php echo e(__('Edit Attribute Option')); ?>" data-bs-toggle="tooltip" title="<?php echo e(__('Edit')); ?>">
                                                <i class="ti ti-pencil"></i>
                                            </button>
                                            <?php endif; // app('laratrust')->permission ?>
                                            <?php if (app('laratrust')->hasPermission('Delete Attributes Option')) : ?>
                                            <?php echo Form::open(['method' => 'DELETE', 'route' => ['product-attribute-option.destroy', $option->id], 'class' => 'd-inline']); ?>

                                                <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
                                                data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>"  title="<?php echo e(__('Delete')); ?>">
                                                    <i class="ti ti-trash"></i>
                                                </button>
                                            <?php echo Form::close(); ?>

                                            <?php endif; // app('laratrust')->permission ?>
                                        </span>
                                    </li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </ul>
                        </div>
                        <?php ($i++); ?>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <p class=" mt-4"><strong><?php echo e(__('Note')); ?> : </strong><b><?php echo e(__('You can easily change attribute option of attribute using drag & drop.')); ?></b></p>
            </div>
        </div>
    </div>
</div>


<?php $__env->stopSection(); ?>

<?php $__env->startPush('custom-script'); ?>
    <script src="<?php echo e(asset('public/js/jquery-ui.min.js')); ?>"></script>
    <script>
        $(function () {
            $(".sortable").sortable();
            $(".sortable").disableSelection();
            $(".sortable").sortable({
                stop: function () {
                    var terms = [];
                    $(this).find('li').each(function (index, data) {
                        terms[index] = $(data).attr('data-id');
                    });

                    $.ajax({
                        url: "<?php echo e(route('attribute-option')); ?>",
                        data: {terms: terms, _token: $('meta[name="csrf-token"]').attr('content')},
                        type: 'POST',
                        success: function (data) {
                            $('#loader').fadeOut();
                        },
                        error: function (data) {
                            data = data.responseJSON;
                            $('#loader').fadeOut();
                            show_toastr('Error', data.error, 'error')
                        }

                    })
                }
            });
        });
    </script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/storesyria/public_html/resources/views/attribute_option/index.blade.php ENDPATH**/ ?>