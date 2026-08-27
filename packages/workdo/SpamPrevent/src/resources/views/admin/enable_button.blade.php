
<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_spam_prevent', 'off') !!}
        <label class="form-check-label form-label" for="enable_spam_prevent">{{ __('Enable Spam Prevent') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_spam_prevent" id="enable_spam_prevent" {{ isset($setting['enable_spam_prevent']) && $setting['enable_spam_prevent'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
