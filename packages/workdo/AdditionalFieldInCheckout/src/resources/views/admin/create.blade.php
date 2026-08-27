{{ Form::open(['route' => 'additionalfieldincheckout.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('field_title', __('Field Title'), ['class' => 'form-label']) !!}
        {!! Form::text('field_title', old('field_title'), ['class' => 'form-control', 'id' => 'field_title', 'required' => 'required']) !!}
    </div>
    <div class="form-group col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', old('name'), ['class' => 'form-control', 'id' => 'name', 'required' => 'required']) !!}
    </div>
    <div class="form-group col-md-12">
        {{ Form::label('type', __('Type'), ['class' => 'col-form-label']) }}
        {!! Form::select('type', $type, old('type'), ['class' => 'form-control', 'id' => 'type', 'required' => 'required']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('label', __('Label'), ['class' => 'form-label']) !!}
        {!! Form::text('label', old('label'), ['class' => 'form-control', 'id' => 'label']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('class', __('Class'), ['class' => 'form-label']) !!}
        {!! Form::text('class', old('class'), ['class' => 'form-control', 'id' => 'class']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('placeholder', __('Placeholder'), ['class' => 'form-label']) !!}
        {!! Form::text('placeholder', old('placeholder'), ['class' => 'form-control', 'id' => 'placeholder']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('default_value', __('Default Value'), ['class' => 'form-label']) !!}<br>
        {!! Form::text('default_value', old('default_value'), ['class' => 'form-control', 'id' => 'default_value']) !!}
        <small>{{__('Note*')}} : {{__('Please set default value according to your selected field type.')}}</small>
    </div>
    <div class="form-group row">
        <div class="col-9">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="require_enable" id="require" name="options[]" {{ old('options') && in_array('require_enable', old('options')) ? 'checked' : '' }}>
                <label class="form-check-label" for="require">
                    {{ __('Required') }}
                </label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox"
                    value="view_order_page" id="view_order_page"
                    name="options[]" {{ old('options') && in_array('view_order_page', old('options')) ? 'checked' : '' }}>
                <label class="form-check-label" for="view_order_page">
                    {{ __('View on Order Detail Page') }}
                </label>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

