<span class="d-flex gap-1 justify-content-end">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($order->delivered_status == 3 && $order->return_status == 1): ?>
    <a href="#" class="btn btn-sm btn-primary return_request" data-id="<?php echo e($order->id); ?>" data-status="2" data-bs-toggle="tooltip"
        title="<?php echo e(__('Approve')); ?>">
        <i class="ti ti-check"></i>
    </a>
    <a href="#" class="btn btn-sm btn-danger return_request" data-id="<?php echo e($order->id); ?>" data-status="3" data-bs-toggle="tooltip"
        title="<?php echo e(__('Cancel')); ?>">
        <i class="ti ti-circle-x"></i>
    </a>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <a href="javascript:void(0)"
        data-url="<?php echo e(route('order.order_view', \Illuminate\Support\Facades\Crypt::encrypt($order->id))); ?>" data-size="lg"
        data-ajax-popup="true" data-title="<?php echo e(__('Order')); ?>    #<?php echo e($order->product_order_id); ?>"
        class="x-3 btn btn-sm align-items-center btn btn-sm btn-warning" data-bs-toggle="tooltip"
        data-original-title="<?php echo e(__('Show')); ?>" data-bs-toggle="tooltip"
        title="<?php echo e(__('Show')); ?>">
        <i class="ti ti-eye"></i>
    </a>
    <a href="<?php echo e(route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id))); ?>"
        class="btn btn-sm btn-info" data-bs-toggle="tooltip"
        title="<?php echo e(__('Edit')); ?>">
        <i class="ti ti-pencil"></i>
    </a>
    <?php echo Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']); ?>

    <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="<?php echo e(__('Are You Sure?')); ?>"
        data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>"
        title="<?php echo e(__('Delete')); ?>">
        <i class="ti ti-trash"></i>
    </button>
    <?php echo Form::close(); ?>

</span><?php /**PATH /home/storesyria/public_html/resources/views/order/action.blade.php ENDPATH**/ ?>