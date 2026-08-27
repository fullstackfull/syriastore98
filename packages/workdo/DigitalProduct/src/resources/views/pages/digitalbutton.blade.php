<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        {!! Form::hidden('enable_digital_product', 'off') !!}
        <label class="form-check-label form-label" for="enable_digital_product">{{ __('Enable Digital Product') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="enable_digital_product" id="enable_digital_product" {{ isset($setting['enable_digital_product']) && $setting['enable_digital_product'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
