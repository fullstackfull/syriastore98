<span class="d-flex gap-1 justify-content-end">
<?php if (app('laratrust')->hasPermission('Show Coupon')) : ?>
<a class="btn btn-sm btn-warning btn-badge" href="<?php echo e(route('coupon.show', $coupon->id)); ?>"  data-bs-toggle="tooltip"
title="<?php echo e(__('Show')); ?>">
    <i class="ti ti-eye"></i>
</a>
<?php endif; // app('laratrust')->permission ?>
<?php if (app('laratrust')->hasPermission('Edit Coupon')) : ?>
<button class="btn btn-sm btn-info btn-badge"
    data-url="<?php echo e(route('coupon.edit', $coupon->id)); ?>"
    data-size="lg" data-ajax-popup="true"
    data-title="<?php echo e(__('Edit Coupon')); ?>"  data-bs-toggle="tooltip"
    title="<?php echo e(__('Edit')); ?>">
    <i class="ti ti-pencil"></i>
</button>
<?php endif; // app('laratrust')->permission ?>
<?php if (app('laratrust')->hasPermission('Delete Coupon')) : ?>
<?php echo Form::open(['method' => 'DELETE', 'route' => ['coupon.destroy', $coupon->id], 'class' => 'd-inline']); ?>

<button type="button" class="btn btn-sm btn-danger show_confirm btn-badge mr-1"  data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>"  title="<?php echo e(__('Delete')); ?>">
    <i class="ti ti-trash"></i>
</button>
<?php echo Form::close(); ?>

<?php endif; // app('laratrust')->permission ?>
</span>
<?php /**PATH /home/storesyria/public_html/resources/views/coupon/action.blade.php ENDPATH**/ ?>