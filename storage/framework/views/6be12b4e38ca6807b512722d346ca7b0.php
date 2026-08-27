<?php echo e(Form::open(array('route' => array('product-attribute-option.store', $id), 'method' => 'post', 'enctype' => 'multipart/form-data'))); ?>

<div class="row">
    <div class="form-group col-md-12">
        <?php echo e(Form::label('terms', __('Name'), ['class' => 'form-label'])); ?>

        <?php echo e(Form::text('terms',old('terms'), ['class' => 'form-control font-style', 'id' => 'terms', 'required' => 'required'])); ?>

    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="<?php echo e(__('Cancel')); ?>" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="<?php echo e(__('Create')); ?>" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
<?php echo Form::close(); ?>



<?php /**PATH /home/storesyria/public_html/resources/views/attribute_option/create.blade.php ENDPATH**/ ?>