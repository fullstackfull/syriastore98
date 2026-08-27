{{ Form::model($bundle_product, ['route' => ['bundle-product.update', $bundle_product->id], 'method' => 'PUT', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="form-group col-12">
        {!! Form::label('product_id', __('Product'), ['class' => 'form-label']) !!}
        {!! Form::select('product_id', $product, null, [
            'class' => 'form-control',
            'data-role' => 'tagsinput',
            'id' => 'product_id',
        ]) !!}
    </div>
    <div class="form-group col-12 format">
        {!! Form::label('bundle_products', __('Bundle Products'), ['class' => 'form-label']) !!}
        {!! Form::select('bundle_products[]', $product, json_decode($bundle_product->bundle_products), [
            'class' => 'form-control select2',
            'id' => 'bundle_products',
            'multiple',
            'data-role' => 'tagsinput',
        ]) !!}
    </div>


    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
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
