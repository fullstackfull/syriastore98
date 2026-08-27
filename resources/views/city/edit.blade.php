{{ Form::model($city, ['route' => ['city.update', $city->id], 'method' => 'put']) }}
<div class="row">
<input type="hidden" name="country_active_tab" value="pills-city-tab">
    <div class="form-group col-md-12">
        {!! Form::label('city', __('City'), ['class' => 'form-label', 'id' => 'adv_label']) !!}
        {{ Form::text('city', !empty($city->name) ? $city->name : '', ['class' => 'form-control', 'placeholder' => 'Enter City Name', 'required' => 'required', 'id' => 'city']) }}
    </div>
    <div class="form-group col-md-12">
        {{ Form::label('country_id', __('Country'), ['class' => 'form-label']) }}
        <select class="form-control" id="country_id" name="country">
            <option value="" disabled selected>{{ __('Select Country') }}</option>
            @foreach ($countries as $key => $count)
                <option value="{{ $key }}" {{ $country->name == $count ? 'selected' : '' }}>{{ $count }}
                </option>
            @endforeach
        </select>
    </div>

    <div class="form-group col-md-12">
        {{ Form::label('state_id', __('State'), ['class' => 'form-label']) }}
        <select class="form-control" id="state_id" name="state">
            <option value="" disabled selected>{{ __('Select State') }}</option>
            @foreach ($states as $key => $count)
                <option value="{{ $key }}" {{ $state->name == $count ? 'selected' : '' }}>{{ $count }}
                </option>
            @endforeach
        </select>
    </div>
</div>
<div class="modal-footer pb-0">
    <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
    <input type="submit" value="{{ __('Update') }}" class="btn btn-primary btn-badge mx-1">
</div>
{{ Form::close() }}
