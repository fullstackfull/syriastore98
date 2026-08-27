
<div class="product-price-info border rounded mb-4">
<div class="price-info-title p-3 border-bottom d-flex align-items-center  justify-content-between">
            <h5 class="mb-0"><?php echo e(__('Wholesale Prices')); ?></h5>
            <a href="javascript:;" data-repeater-create class="wholesale_repeater btn btn-sm btn-light-primary btn-badge">
                                <i class="ti ti-plus"></i>
                            </a>
                </div>
        <div class="card-body p-3 pb-0">
            <div class="row">
                <div class="form-group col-12">
                    <div id="wholesale_repeater_basic">
                        <div class="form-group mb-0">
                            <div data-repeater-list="wholesale_repeater_basic">
                                <div data-repeater-item class="mt-1">
                                    <div class="form-group row-gap row">
                                        <div class="col-xl-3 col-lg-6 col-sm-6 size-guilde-item">
                                            <?php echo Form::label('', __('Min Qty'), ['class' => 'form-label']); ?>

                                            <?php echo Form::text('min_qty', null, ['class' => 'form-control']); ?>

                                        </div>
                                        <div class="col-xl-3 col-lg-6 col-sm-6 size-guilde-item">
                                            <?php echo Form::label('', __('Max Qty'), ['class' => 'form-label']); ?>

                                            <?php echo Form::text('max_qty', null, ['class' => 'form-control']); ?>

                                        </div>
                                        <div class="col-xl-5 col-lg-6 col-sm-6 size-guilde-item">
                                            <?php echo Form::label('', __('Per Product Price'), ['class' => 'form-label']); ?>

                                            <?php echo Form::text('per_product_price', null, ['id' => 'answer', 'rows' => 2, 'class' => 'form-control']); ?>


                                        </div>
                                        <div class="col-md-1 col-sm-6 size-guilde-item">
                                            <label></label>
                                            <a href="javascript:;" data-repeater-delete class="btn field-trash-btn btn-sm btn-light-danger mt-3 mt-md-8 btn-badge"  data-bs-toggle="tooltip" title="<?php echo e(__('Delete')); ?>">
                                                <i class="ti ti-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
</div>
<?php $__env->startPush('custom-script'); ?>
<script src="<?php echo e(asset('js/jquery-ui.min.js')); ?>"></script>
<script src="<?php echo e(asset('js/repeater.js')); ?>"></script>
<script>
    $('#wholesale_repeater_basic').repeater({
        initEmpty: false,
        defaultValues: {
            'text-input': 'foo'
        },

        show: function() {
            $(this).slideDown();
        },

        hide: function(deleteElement) {
            $(this).slideUp(deleteElement);
        }
    });

    // Manually add a new item when the "Add More" button is clicked
    $('.wholesale_repeater').on('click', function(e) {
        e.preventDefault();

        // Clone the last repeater item, clear its values, and append it to the repeater list
        var $repeaterList = $('#wholesale_repeater_basic [data-repeater-list]');
        var $newItem = $repeaterList.children().last().clone();

        // Clear input values in the cloned item
        $newItem.find('input').val('');
        // Append the new item to the repeater list and reinitialize tooltips
        $repeaterList.append($newItem);
    });
</script>
<?php $__env->stopPush(); ?><?php /**PATH /home/storesyria/public_html/packages/workdo/WholesaleProduct/src/app/Providers/../../resources/views/pages/create_price.blade.php ENDPATH**/ ?>