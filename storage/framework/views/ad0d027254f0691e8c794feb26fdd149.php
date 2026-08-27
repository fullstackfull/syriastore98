<span class="d-flex gap-1 justify-content-end">
<a href="<?php echo e(route('product.edit', $product->id)); ?>" class="btn btn-sm btn-info"
    data-title="<?php echo e(__('Edit Product')); ?>" data-bs-toggle="tooltip"
    title="<?php echo e(__('Edit')); ?>">
    <i class="ti ti-pencil"></i>
</a>
<?php echo Form::open([
    'method' => 'DELETE',
    'route' => ['product.destroy', $product->id],
    'class' => 'd-inline',
]); ?>

<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>" 
title="<?php echo e(__('Delete')); ?>">
    <i class="ti ti-trash"></i>
</button>
</span>
<?php /**PATH /home/storesyria/public_html/resources/views/product/action.blade.php ENDPATH**/ ?>