<span class="d-flex gap-1 justify-content-end">
<a href="<?php echo e(route('shipping-zone.show',$shippingZone->id)); ?>" class="btn btn-sm btn-warning" data-title="<?php echo e(__('Show Shipping Zone')); ?>">
    <i class="ti ti-eye" data-bs-toggle="tooltip" title="" data-bs-original-title="<?php echo e(__('Show')); ?>" aria-label="Show Shipping Zone"></i>
</a>
<button class="btn btn-sm btn-info" data-url="<?php echo e(route('shipping-zone.edit', $shippingZone->id)); ?>" data-size="md"
    data-ajax-popup="true" data-title="<?php echo e(__('Edit')); ?>"  data-bs-toggle="tooltip"
    title="<?php echo e(__('Edit')); ?>">
    <i class="ti ti-pencil"></i>
</button>
<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shippingZone->zone_name != 'Locations not covered by your other zones'): ?>
    <?php echo Form::open([
        'method' => 'DELETE',
        'route' => ['shipping-zone.destroy', $shippingZone->id],
        'class' => 'd-inline',
    ]); ?>

    <button type="button" class="btn btn-sm btn-danger btn-badge mr-1 show_confirm"  data-confirm="<?php echo e(__('Are You Sure?')); ?>"
    data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>"  data-bs-toggle="tooltip"
    title="<?php echo e(__('Delete')); ?>">
        <i class="ti ti-trash"></i>
    </button>
    <?php echo Form::close(); ?>

<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</span>
<?php /**PATH /home/storesyria/public_html/resources/views/shippingzone/action.blade.php ENDPATH**/ ?>