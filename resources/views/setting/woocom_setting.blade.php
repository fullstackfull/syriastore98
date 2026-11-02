{{--
    WooCommerce, on the design system. The enable toggle's label was empty, so the control had no
    accessible name — the shape every integration panel in this directory shared.
--}}
<div class="ss-card" id="Woocommerce_Setting">
    {{ Form::open(['route' => 'woocommerce.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Woocommerce Settings') }}</h2>
        </div>
        {!! Form::hidden('woocommerce_setting_enabled', 'off') !!}
        <x-ss.switch name="woocommerce_setting_enabled"
                     id="woocommerce_setting_enabled"
                     value="on"
                     :label="__('Enable Woocommerce')"
                     :checked="isset($setting['woocommerce_setting_enabled']) && $setting['woocommerce_setting_enabled'] == 'on'" />
    </div>

    <div class="ss-card__body">
        <div class="ss-field">
            <label class="ss-label" for="woocommerce_store_url">{{ __('Store Url') }}</label>
            <input type="url" class="ss-input" id="woocommerce_store_url" name="woocommerce_store_url"
                   value="{{ $setting['woocommerce_store_url'] ?? '' }}"
                   placeholder="https://example.com">
        </div>

        <div class="ss-field">
            <label class="ss-label" for="woocommerce_consumer_key">{{ __('Consumer Key') }}</label>
            <input type="text" class="ss-input" id="woocommerce_consumer_key" name="woocommerce_consumer_key"
                   value="{{ $setting['woocommerce_consumer_key'] ?? '' }}"
                   placeholder="{{ __('Consumer Key') }}">
        </div>

        {{-- F117: the stored credential never reaches the page; the handler keeps it when
             this box posts empty. Both halves, because either alone is a defect. --}}
        <x-ss.secret-field
            name="woocommerce_consumer_secret"
            :label="__('Consumer Secret')"
            :stored="$setting['woocommerce_consumer_secret'] ?? null" />
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
