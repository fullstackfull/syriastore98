{{Form::model($query, array('route' => array('product-enquiry.update', $query->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
    <div class="row">
        <div class="form-group col-md-6">
            {!! Form::label('Product', __('Product'), ['class' => 'form-label']) !!}
            {!! Form::text('Product', $product->name, ['class' => 'form-control', 'id' => 'Product' ,'disabled']) !!}
        </div>
        <div class="form-group col-md-6">
            {!! Form::label('first_name', __('Customer'), ['class' => 'form-label']) !!}
            {!! Form::text('first_name', $query->first_name, ['class' => 'form-control', 'id' => 'first_name' ,'disabled']) !!}
        </div>
    </div>
    <div class="form-group">
        <div class="form-group row">
            <div class="form-group col-md-12">
                {!! Form::label('comment', __('Question'), ['class' => 'form-label']) !!}
                {!! Form::text('comment', null, ['class' => 'form-control', 'id' => 'comment']) !!}
            </div>
            <div class="col-md-12">
                {!! Form::label('answers', __('Answer'), ['class' => 'form-label']) !!}
                {!! Form::textarea('answers', null, [ 'rows' => 1, 'class'=>'form-control summernote-simple-product', 'id' => 'answers' ]) !!}

            </div>
        </div>
    </div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
</div>
{!! Form::close() !!}
