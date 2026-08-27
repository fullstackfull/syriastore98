<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="products_pdf_enable">{{ __('Enable Product PDF') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="products_pdf_enable" id="products_pdf_enable" {{ isset($setting['products_pdf_enable']) && $setting['products_pdf_enable'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>