<?php echo e(Form::open(['route' => 'product-attributes.store', 'method' => 'post', 'enctype' => 'multipart/form-data'])); ?>


<div class="row">
    <div class="form-group col-md-12">
        <?php echo e(Form::label('name', __('Name'), ['class' => 'form-label'])); ?>

        <?php echo e(Form::text('name', old('name'), ['class' => 'form-control font-style', 'required' => 'required'])); ?>

    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="<?php echo e(__('Cancel')); ?>" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="<?php echo e(__('Create')); ?>" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
<?php echo Form::close(); ?>


<?php /**PATH /home/storesyria/public_html/resources/views/attributes/create.blade.php ENDPATH**/ ?>