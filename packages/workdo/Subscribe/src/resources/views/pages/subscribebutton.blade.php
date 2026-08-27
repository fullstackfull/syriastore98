<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_subscribe_popup', 'off') !!}
        <label class="form-check-label form-label" for="enable_subscribe_popup">{{ __('Enable Subscribe Popup') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_subscribe_popup" id="enable_subscribe_popup" {{ isset($setting['enable_subscribe_popup']) && $setting['enable_subscribe_popup'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
