<?php
    $shippingMethod = \App\Models\ShippingZone::modules();
?>

<?php echo e(Form::model($shippingzone, array('route' => array('shipping-zone.update', $shippingzone->id), 'method' => 'PUT'))); ?>


<div class="row">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shippingzone->zone_name != 'Locations not covered by your other zones'): ?>
        <div class="form-group col-md-12">
            <?php echo Form::label('', __('Name'), ['class' => 'form-label']); ?>

            <?php echo Form::text('zone_name', null, ['class' => 'form-control', "required"=>true]); ?>

        </div>
    <?php else: ?>
        <div class="form-group col-md-12">
            <?php echo Form::label('', __('Name'), ['class' => 'form-label']); ?>

            <?php echo Form::text('zone_name' , null, ['class' => 'form-control','readonly']); ?>

        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($shippingzone->zone_name != 'Locations not covered by your other zones'): ?>
        <div class="form-group list_height_css col-md-12">
            <label><?php echo e(__('Country')); ?></label>
            <?php echo Form::label('', __('Name'), ['class' => 'form-label']); ?>

            <?php echo Form::select('country_id', $country_option, null, ["class"=>"form-control country_change", "required"=>false]); ?>

        </div>
        <div class="form-group list_height_css col-md-12">
            <label><?php echo e(__('State')); ?></label>
            <div class="state">
            <?php echo Form::select('state_id', $state_option, null, ["class"=>"form-control state_name state_chage", "placeholder"=>"Select State", "required"=>false, 'data-select' => 0]); ?>

            </div>
        </div>
    <?php else: ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <div class="form-group col-md-12">
        <?php echo e(Form::label('shipping_method', __('Shipping Method'), ['class' => 'col-form-label'])); ?>

        <?php echo Form::select('shipping_method[]', $shippingMethod, $zone_selected, ['class' => 'form-control select2', 'id' => 'choices-multiple', 'multiple','data-role'=>'tagsinput']); ?>

    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="<?php echo e(__('Cancel')); ?>" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="<?php echo e(__('Update')); ?>" class="btn btn-badge btn-primary mx-1">
    </div>
</div>

<?php echo Form::close(); ?>


<script>
    $(document).on('change', '.country_change', function(e) {
        var country_id = $(this).val();
        var data = {
            country_id: country_id
        }
        $.ajax({
            url: '<?php echo e(route('shipping-states.list')); ?>',
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                $(this).closest('.row').find('.state_chage').html('').show();
                $(this).closest('.row').find('.nice-select.state_chage').remove();
                var state = $(this).closest('.row').find('.state_chage').attr('data-select');

                var option = '';
                $.each(response, function(i, item) {
                    var checked = '';
                    if (i == state) {
                        var checked = 'checked';
                    }
                    option += '<option value="' + i + '" ' + checked + '>' + item +
                        '</option>';
                });
                $(this).closest('.row').find('.state_chage').html(option);
                $(this).closest('.row').find('.state_chage').val(state);

                if (state != 0) {
                    $(this).closest('.row').find('.state_chage').trigger('change');
                }
               // getBillingdetail();
                $('select').niceSelect();
            }
        });
    });

</script>
<?php /**PATH /home/storesyria/public_html/resources/views/shippingzone/edit.blade.php ENDPATH**/ ?>