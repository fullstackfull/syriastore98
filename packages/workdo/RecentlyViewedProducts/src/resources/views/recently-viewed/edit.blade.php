{{Form::model($recent_product, array('route' => array('recently-view.update', $recent_product->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div class="row">
    <div class="form-group  col-12 product_id_div" data_val='{{ $recent_product->product_id }}'>
        {!! Form::label('', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', $products, null, ['class' => 'form-control', 'data-role' => 'tagsinput']) !!}
        </span>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
