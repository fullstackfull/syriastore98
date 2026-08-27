<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="enable_product_quickview">{{ __('Enable Product Quick View') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_product_quickview" id="enable_product_quickview" {{ isset($setting['enable_product_quickview']) && $setting['enable_product_quickview'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
