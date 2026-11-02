{{--
    WhatsApp live support, on the design system. The enable toggle's label was empty, as in every
    other integration panel.

    The note under the number was a `<small class="text-muted text-danger">` — two colour classes
    that contradict each other, and not associated with the field. It is the field's hint now,
    wired with `aria-describedby`, so a screen reader reads it as part of the control rather than
    as loose text the user may never reach.
--}}
<div class="ss-card" id="whatsapp_Setting">
    {{ Form::open(['route' => 'whatsapp.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Whatsapp Settings') }}</h2>
            <p class="ss-page-header__subtitle">{{ __('WhatsApp live support setting for customers') }}</p>
        </div>
        {!! Form::hidden('whatsapp_setting_enabled', 'off') !!}
        <x-ss.switch name="whatsapp_setting_enabled" id="whatsapp_setting_enabled" value="on"
                     :label="__('Enable WhatsApp support')"
                     :checked="isset($setting['whatsapp_setting_enabled']) && $setting['whatsapp_setting_enabled'] === 'on'" />
    </div>

    <div class="ss-card__body">
        <div class="ss-field">
            <label class="ss-label" for="whatsapp_contact_number">{{ __('Contact Number') }}</label>
            <input type="text" class="ss-input" id="whatsapp_contact_number" name="whatsapp_contact_number"
                   value="{{ $setting['whatsapp_contact_number'] ?? '' }}"
                   placeholder="+XXXXXXXXXXX"
                   pattern="^\+[1-9]{1}[0-9]{3,14}$"
                   required
                   aria-describedby="whatsapp_contact_number-hint">
            <p class="ss-field__hint" id="whatsapp_contact_number-hint">
                {{ __("Note : Enter your WhatsApp number along with your country code (with the '+' symbol). For instance, if your country code is +91 and your WhatsApp number is 7878787878, then type +917878787878.") }}
            </p>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
