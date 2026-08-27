
{{Form::model($shippingMethod, array('route' => array('local-shipping.update', $shippingMethod->id))) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('method_name', null, ['class' => 'form-control','disabled']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('', __('Cost'), ['class' => 'form-label']) !!}
        {!! Form::number('cost', null, ['class' => 'form-control','min'=>0,'step'=>'0.01']) !!}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>

{!! Form::close() !!}
