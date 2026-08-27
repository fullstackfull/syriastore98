<div class="form-group col-lg-2 col-md-4 col-sm-6">
    <div class="form-check form-switch p-0">
        <label class="form-check-label form-label" for="market_place_list_enable">{{ __('Enable MarketPlace') }}</label>
        <input type="checkbox" class="form-check-input" data-toggle="switchbutton" data-onstyle="primary" name="market_place_list_enable" id="market_place_list_enable" {{ isset($setting['market_place_list_enable']) && $setting['market_place_list_enable'] == 'on' ? 'checked="checked"' : '' }}>
    </div>
</div>
