{{ Form::open(['route' => 'bundle-product.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">
    <div class="form-group  col-12 product_id_div" data_val='0'>
        {!! Form::label('product-dropdown', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', $products, null, [
                'class' => 'form-control',
                'data-role' => 'tagsinput',
                'id' => 'product-dropdown',
            ]) !!}
        </span>
    </div>
    <div class="form-group  col-12 " data_val='0'>
        {!! Form::label('bundle_products', __('Bundle Product'), ['class' => 'form-label']) !!}
        {!! Form::select('bundle_products[]', $bundle_products, null, [
            'class' => 'form-control multi-select ',
            'multiple' => 'multiple',
            'data-role' => 'tagsinput',
            'id' => 'bundle_products',
        ]) !!}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

<script>
    if ($(".multi-select").length > 0) {
        $($(".multi-select")).each(function(index, element) {
            var id = $(element).attr('id');
            var multipleCancelButton = new Choices(
                '#' + id, {
                    removeItemButton: true,
                }
            );
        });
    }
</script>
