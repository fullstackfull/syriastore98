<span class="d-flex gap-1 justify-content-end">
<button class="btn btn-sm btn-info btn-badge" data-url="<?php echo e(route('shipping.edit', $shipping->id)); ?>" data-size="md" data-ajax-popup="true" data-title="<?php echo e(__('Edit Shipping Class')); ?>" data-bs-toggle="tooltip"
title="<?php echo e(__('Edit')); ?>">
    <i class="ti ti-pencil"></i>
</button>
<?php echo Form::open(['method' => 'DELETE', 'route' => ['shipping.destroy', $shipping->id], 'class' => 'd-inline']); ?>

<button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm"  data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>" 
title="<?php echo e(__('Delete')); ?>">
    <i class="ti ti-trash"></i>
</button>
<?php echo Form::close(); ?>

</span>
<?php /**PATH /home/storesyria/public_html/resources/views/shipping/action.blade.php ENDPATH**/ ?>