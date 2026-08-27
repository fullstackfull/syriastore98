<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_lottery_button', 'off') !!}
        <label class="form-check-label form-label" for="enable_lottery_button">{{ __('Enable Lottery Prizes') }}</label><br>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_lottery_button" id="enable_lottery_button" {{ isset($setting['enable_lottery_button']) && $setting['enable_lottery_button'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
