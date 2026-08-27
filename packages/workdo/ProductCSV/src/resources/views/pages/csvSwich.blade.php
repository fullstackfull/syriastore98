@php
    $setting = getAdminAllSetting();
@endphp
<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class=" form-check  form-switch p-0">
        {!! Form::hidden('product_csvswich_is_enable', 'off') !!}
        <label class="form-check-label mb-2"
            for="product_csvswich_is_enable">{{ __('Enable Product CSV') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
            data-onstyle="primary" name="product_csvswich_is_enable" id="product_csvswich_is_enable"
            {{ (isset($setting['product_csvswich_is_enable']) && ($setting['product_csvswich_is_enable'] == 'on')) ? 'checked="checked"' : '' }}>
    </div>
</div>
