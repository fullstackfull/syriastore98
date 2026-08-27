{{ Form::model($productAttributeOption, array('route' => array('product-attribute-option.update', $productAttributeOption->id), 'method' => 'POST', 'enctype' => 'multipart/form-data')) }}
<div class="row">
    <div class="form-group col-md-12">
        {{ Form::label('terms', __('Name'), ['class' => 'form-label']) }}
        {{ Form::text('terms', null, ['class' => 'form-control font-style', 'id' => 'terms', 'required' => 'required']) }}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
