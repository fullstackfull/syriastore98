{{ Form::open(['route' => 'city.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">
<input type="hidden" name="country_active_tab" value="pills-city-tab">
    <div class="form-group col-md-12">
        {{ Form::label('name', __('Name'), ['class' => 'form-label']) }}
        {{ Form::text('name', null, ['class' => 'form-control font-style', 'placeholder' => 'Enter City Name', 'required' => 'required', 'id' => 'name']) }}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('city_country_id', __('Country'), ['class' => 'form-label']) !!}
        {!! Form::select('country_id', $countries, old('country_id'), [
            'class' => 'form-control',
            'id' => 'city_country_id',
            'placeholder' => 'Select Option',
        ]) !!}
    </div>
    <div class="form-group col-md-12">
        {{ Form::label('city_state_id', __('State'), ['class' => 'form-label']) }}
        {{ Form::select('state_id', [], old('state_id'), ['class' => 'form-control', 'id' => 'city_state_id', 'required' => 'required', 'placeholder' => 'Select Option']) }}
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
