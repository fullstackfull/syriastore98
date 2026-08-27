<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_checkout_attachment', 'off') !!}
        <label class="form-check-label form-label" for="enable_checkout_attachment">{{ __('Enable Checkout Attachment') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_checkout_attachment" id="enable_checkout_attachment" {{ isset($setting['enable_checkout_attachment']) && $setting['enable_checkout_attachment'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
