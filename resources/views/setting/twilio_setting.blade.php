<div class="card" id="twilio_setting">
    {{ Form::open(['route' => 'twilio.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
    <div class="card-header d-flex justify-content-between ">
        <h5> {{ __('Twilio Settings') }} </h5>
        {!! Form::hidden('twilio_setting_enabled', 'off') !!}
        <div class="form-check form-switch d-inline-block">
            {!! Form::checkbox(
            'twilio_setting_enabled',
            'on',
            isset($setting['twilio_setting_enabled']) && $setting['twilio_setting_enabled'] === 'on',
            [
            'class' => 'form-check-input',
            'id' => 'twilio_setting_enabled',
            ],
            ) !!}
            <label class="custom-control-label form-control-label" for="twilio_setting_enabled"></label>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="row">
            {{-- F117. These two printed the live Twilio credentials into `value="…"`, so the SID
                 and the auth token sat in the DOM of a panel a merchant opens to change the
                 sender number. The component carries no value and the handler keeps the stored
                 one when the box posts empty — both halves, because either alone is a defect:
                 masking without the save change erases the credential on the next save. --}}
            <div class="col-lg-6">
                <x-ss.secret-field
                    name="twilio_sid"
                    :label="__('Twilio SID')"
                    :stored="$setting['twilio_sid'] ?? null" />
            </div>
            <div class="col-lg-6">
                <x-ss.secret-field
                    name="twilio_token"
                    :label="__('Twilio Token')"
                    :stored="$setting['twilio_token'] ?? null" />
            </div>
            <div class="col-lg-6 form-group">
                {!! Form::label('twilio_from', __('Twilio From'), ['class' => 'form-label']) !!}
                {!! Form::text('twilio_from', !empty($setting['twilio_from']) ? $setting['twilio_from'] : '', [
                'class' => 'form-control',
                'placeholder' => 'twilio consumer secret',
                ]) !!}
            </div>

            <div class="col-lg-6 form-group">
                {!! Form::label('twilio_notification_number', __('Notification Number'), ['class' => 'form-label'])
                !!}
                {!! Form::text(
                'twilio_notification_number',
                !empty($setting['twilio_notification_number']) ? $setting['twilio_notification_number'] : '',
                ['class' => 'form-control', 'placeholder' => 'twilio consumer secret'],
                ) !!}
                <small>* {{ __('Use country code with your number') }} *</small>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end flex-wrap ">
        <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
    </div>
    {!! Form::close() !!}
</div>
