@php
    $options = !empty(json_decode($field->options)) ? json_decode($field->options) : [];
@endphp
{{Form::model($field, array('route' => array('additionalfieldincheckout.update', $field->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('field_title', __('Field Title'), ['class' => 'form-label']) !!}
        {!! Form::text('field_title', null, ['class' => 'form-control', 'id' => 'field_title', 'required' => 'required']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'id' => 'name']) !!}
    </div>
    <div class="form-group col-md-12">
        {{ Form::label('type', __('Type'), ['class' => 'col-form-label']) }}
        {!! Form::select('type', $type, null, ['class' => 'form-control ', 'id' => 'type', 'required' => 'required']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('label', __('Label'), ['class' => 'form-label']) !!}
        {!! Form::text('label', null, ['class' => 'form-control', 'id' => 'label']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('class', __('Class'), ['class' => 'form-label']) !!}
        {!! Form::text('class', null, ['class' => 'form-control', 'id' => 'class']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('placeholder', __('Placeholder'), ['class' => 'form-label']) !!}
        {!! Form::text('placeholder', null, ['class' => 'form-control', 'id' => 'placeholder']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('default_value', __('Default Value'), ['class' => 'form-label']) !!}<br>
        {!! Form::text('default_value', null, ['class' => 'form-control', 'id' => 'default_value']) !!}
        <small>{{__('Note*')}} : {{__('Please set default value according to your selected field type.')}}</small>
    </div>
    <div class="form-group row">
        <div class="col-9">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="require_enable" id="require" name="options[]" {{ in_array('require_enable', $options) ? 'checked' : '' }}>
                <label class="form-check-label" for="require">
                    {{ __('Required') }}
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox"
                    value="view_order_page" id="view_order_page"
                    name="options[]" {{ in_array('view_order_page', $options) ? 'checked' : '' }}>
                <label class="form-check-label" for="view_order_page">
                    {{ __('View on Order Detail Page') }}
                </label>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
