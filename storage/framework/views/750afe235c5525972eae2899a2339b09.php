<div class="d-flex justify-content-center gap-2">
    <?php if (app('laratrust')->hasPermission('Edit AdditionalCustomFields')) : ?>    
        <a href="#" data-url="<?php echo e(route('additionalcustomfields.edit', $field->id)); ?>" 
           data-bs-toggle="tooltip" 
           title="<?php echo e(__('Edit')); ?>"
           data-ajax-popup="true" 
           data-title="<?php echo e(__('Edit Custom Field')); ?>" 
           class="btn btn-sm btn-info">
            <i class="ti ti-pencil"></i>
        </a>
    <?php endif; // app('laratrust')->permission ?>
    
    <?php if (app('laratrust')->hasPermission('Delete AdditionalCustomFields')) : ?>
    <form action="<?php echo e(route('additionalcustomfields.destroy', $field->id)); ?>" method="POST">
        <?php echo csrf_field(); ?>
        <?php echo method_field('DELETE'); ?>
        <button type="button" class="btn btn-sm btn-danger show_confirm"  data-confirm="<?php echo e(__('Are You Sure?')); ?>"
        data-text="<?php echo e(__('This action can not be undone. Do you want to continue?')); ?>" data-text-yes="<?php echo e(__('Yes')); ?>" data-text-no="<?php echo e(__('No')); ?>"  data-bs-toggle="tooltip" title="<?php echo e(__('Delete')); ?>">
            <i class="ti ti-trash"></i>
        </button>
    </form>
    <?php endif; // app('laratrust')->permission ?>
</div>
<?php /**PATH /home/storesyria/public_html/packages/workdo/AdditionalCustomFields/src/app/Providers/../../resources/views/partials/actions.blade.php ENDPATH**/ ?>