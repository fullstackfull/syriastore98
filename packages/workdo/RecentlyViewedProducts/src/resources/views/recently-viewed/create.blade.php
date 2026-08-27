{{ Form::open(['route' => 'recently-view.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">
    <div class="form-group  col-12 product_id_div" data_val='0'>
        {!! Form::label('', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', $products, null, [
                'class' => 'form-control',
                'data-role' => 'tagsinput',
                'id' => 'product-dropdown',
            ]) !!}
        </span>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
