
<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="coupon_list_enable">{{ __('Enable Coupon List') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="coupon_list_enable" id="coupon_list_enable" {{ isset($setting['coupon_list_enable']) && $setting['coupon_list_enable'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
