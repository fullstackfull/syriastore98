<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="enable_product_barcode">{{ __('Enable Product Barcode') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_product_barcode" id="enable_product_barcode" {{ isset($setting['enable_product_barcode']) && $setting['enable_product_barcode'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
