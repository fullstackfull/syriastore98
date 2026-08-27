<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="enable_product_compare">{{ __('Enable Product Compare') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_product_compare" id="enable_product_compare" {{ isset($setting['enable_product_compare']) && $setting['enable_product_compare'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
