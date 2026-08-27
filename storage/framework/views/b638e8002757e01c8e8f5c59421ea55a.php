<span class="d-flex gap-1 justify-content-end">
<button class="btn btn-sm btn-info"
    data-url="<?php echo e(route('category.edit', $category->id)); ?>" data-size="md"
    data-ajax-popup="true" data-title="<?php echo e(__('Edit Category')); ?>" data-bs-toggle="tooltip"
    title="<?php echo e(__('Edit')); ?>">
    <i class="ti ti-pencil"></i>
</button>
<?php echo Form::open([
    'method' => 'DELETE',
    'route' => ['category.destroy', $category->id],
    'class' => 'd-inline',
]); ?>

<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>" 
title="<?php echo e(__('Delete')); ?>"><i
        class="ti ti-trash"></i></button>
<?php echo Form::close(); ?>

</span>
<?php /**PATH /home/storesyria/public_html/resources/views/category/action.blade.php ENDPATH**/ ?>