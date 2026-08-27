{{Form::model($productAttribute, array('route' => array('product-attributes.update', $productAttribute->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}

<div class="row">
    <div class="form-group col-md-12">
        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
        {{ Form::text('name', null, ['class' => 'form-control font-style', 'required' => 'required']) }}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
