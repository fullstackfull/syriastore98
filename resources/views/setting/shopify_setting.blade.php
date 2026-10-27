{{--
    Shopify, on the design system. The enable toggle's label was empty, so the control had no
    accessible name — see `twilio_setting` for the shape this directory shared.

    The `.myshopify.com` suffix is an ADD-ON, not a field: it was a bare `<span>` with
    `id="basic-addon2"` that nothing referenced and that collided with the identical id in the
    WooCommerce panel when both tabs were in the page. It is `aria-hidden` and the field's own
    hint says the same thing in words, because a visual suffix beside a box is not a statement a
    screen reader makes.
--}}
<div class="ss-card" id="shopify_Setting">
    {{ Form::open(['route' => 'shopify.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Shopify Settings') }}</h2>
        </div>
        {!! Form::hidden('shopify_setting_enabled', 'off') !!}
        <x-ss.switch name="shopify_setting_enabled"
                     id="shopify_setting_enabled"
                     value="on"
                     :label="__('Enable Shopify')"
                     :checked="isset($setting['shopify_setting_enabled']) && $setting['shopify_setting_enabled'] == 'on'" />
    </div>

    <div class="ss-card__body">
        <div class="ss-field">
            <label class="ss-label" for="shopify_store_url">{{ __('Shopify store url') }}</label>
            <div class="ss-input-group">
                <input type="text" class="ss-input" id="shopify_store_url" name="shopify_store_url"
                       value="{{ $setting['shopify_store_url'] ?? '' }}"
                       placeholder="{{ __('your-shop-name') }}"
                       aria-describedby="shopify_store_url-hint">
                <span class="ss-input-group__suffix" aria-hidden="true">{{ __('.myshopify.com') }}</span>
            </div>
            {{-- P1-SEC-06: this is a DNS LABEL, not a URL. A value like `169.254.169.254#` would
                 end the host and comment out the suffix in the thirteen URLs built from it. --}}
            <p class="ss-field__hint" id="shopify_store_url-hint">
                {{ __('Just the shop name. The .myshopify.com suffix is added for you.') }}
            </p>
        </div>

        {{-- F117: the stored credential never reaches the page; the handler keeps it when
             this box posts empty. Both halves, because either alone is a defect. --}}
        <x-ss.secret-field
            name="shopify_access_token"
            :label="__('Shopify access token')"
            :stored="$setting['shopify_access_token'] ?? null" />
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
