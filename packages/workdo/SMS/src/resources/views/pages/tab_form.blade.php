<!-- SMS Setting -->
<div id="sms-settings">
    <div class="card">
        {{ Form::open(['route' => 'sms.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        <div class="card-header d-flex justify-content-between ">
            <div class="col-6">
                <h5> {{ __('SMS Settings') }} </h5>
                <small>{{ __('Edit your SMS settings') }}</small>
            </div>
            {!! Form::hidden('sms_setting_enabled', 'off') !!}
            <div class="form-check form-switch d-inline-block">
                {!! Form::checkbox('sms_setting_enabled', 'on', isset($settings['sms_setting_enabled']) && $settings['sms_setting_enabled'] === 'on', [
                'class' => 'form-check-input',
                'id' => 'sms_setting_enabled',
                ]) !!}
                <label class="custom-control-label form-control-label" for="sms_setting_enabled"></label>
            </div>
        </div>

        <div class="card-body p-4">
            <div class="row">
                <div class="col-lg-6 form-group">
                    {{ Form::label('sms_setting', __('SMS Setting'), ['class' => ' col-form-label']) }}
                    {{ Form::select('sms_setting', $sms_settings, isset($settings['sms_setting']) ? $settings['sms_setting'] : '', ['id' => 'sms_setting', 'class' => 'form-control choices', 'searchEnabled' => 'true']) }}
                </div>
            </div>
            <div class="row">
                <div class="col-12" id="getsmsfields">
                </div>
            </div>

        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap ">
            <input type="submit" value="{{ __('Save Changes') }}" class="btn-submit btn btn-primary btn-badge">
        </div>
        {!! Form::close() !!}
    </div>
</div>

