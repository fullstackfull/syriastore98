<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="cart_quantity_control_enable">{{ __('Cart Quantity Control') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="cart_quantity_control_enable" id="cart_quantity_control_enable" {{ isset($setting['cart_quantity_control_enable']) && $setting['cart_quantity_control_enable'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
