<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_coupon_email', 'off') !!}
        <label class="form-check-label form-label" for="enable_coupon_email">{{ __('Enable Coupon Email') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_coupon_email" id="enable_coupon_email" {{ isset($setting['enable_coupon_email']) && $setting['enable_coupon_email'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
