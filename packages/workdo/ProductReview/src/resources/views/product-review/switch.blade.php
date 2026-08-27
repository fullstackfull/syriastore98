<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="enable_product_review">{{ __('Enable Product Review') }}</label>
        
        <!-- Hidden input to ensure "off" value is sent when unchecked -->
        <input type="hidden" name="enable_product_review" value="off">

        <!-- Checkbox input for switch -->
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_product_review" id="enable_product_review" value="on" {{ isset($setting['enable_product_review']) && $setting['enable_product_review'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>