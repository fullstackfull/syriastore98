{{ Form::model($state, ['route' => ['state.update', $state->id], 'method' => 'put']) }}
<div class="row">
<input type="hidden" name="country_active_tab" value="pills-state-tab">
    <div class="form-group col-md-12">
        {!! Form::label('state', __('State'), ['class' => 'form-label', 'id' => 'adv_label']) !!}
        {{ Form::text('state', !empty($state->name) ? $state->name : '', ['class' => 'form-control', 'placeholder' => 'Enter State Name', 'required' => 'required', 'state' => 'state']) }}
    </div>
    <div class="form-group col-md-12">

        {{ Form::label('country', __('Country'), ['class' => 'form-label']) }}
        <select class="form-control" id="country" name="country">
            <option value="" disabled selected>{{ __('Select Country') }}</option>
            @foreach ($countries as $key => $count)
                <option value="{{ $key }}" {{ $country->name == $count ? 'selected' : '' }}>{{ $count }}
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
