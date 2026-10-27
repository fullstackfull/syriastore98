{{--
    WhatsApp notifications, on the design system.
    =============================================================================================

    EVERY NOTIFICATION TOGGLE HAD NO ACCESSIBLE NAME. The pattern was a visible
    `<label class="form-label">` with NO `for`, beside a checkbox whose own
    `<label class="form-check-label" for="…"></label>` was EMPTY — so the name was on screen and
    nowhere a screen reader would find it, and the one label that WAS associated said nothing.
    Thirteen of them on one screen, depending on how many notification types the store has.

    `<x-ss.switch>` takes the name as text, so the control cannot ship without one.

    The toggles post under the notification's numeric id, which is why this panel's field
    contract pins only the two named fields.
--}}
<div id="whatsapp-notification-settings" class="ss-card">
    {{ Form::model($setting, ['route' => 'whatsapp-notification', 'method' => 'post']) }}
    @csrf

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('WhatsApp Business API') }}</h2>
        </div>
    </div>

    <div class="ss-card__body">
        <div class="row">
            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="whatsapp_phone_number_id">{{ __('WhatsApp Phone number ID') }}</label>
                    <input type="text" class="ss-input" id="whatsapp_phone_number_id"
                           name="whatsapp_phone_number_id"
                           value="{{ $setting['whatsapp_phone_number_id'] ?? '' }}"
                           placeholder="{{ __('WhatsApp Phone number ID') }}">
                </div>
            </div>
            <div class="col-lg-6">
                {{-- F117: the stored credential never reaches the page; the handler keeps it when
                     this box posts empty. Both halves, because either alone is a defect. --}}
                <x-ss.secret-field
                    name="whatsapp_access_token"
                    :label="__('WhatsApp Access Token')"
                    :stored="$setting['whatsapp_access_token'] ?? null" />
            </div>
        </div>

        <fieldset class="ss-field">
            <legend class="ss-label">{{ __('Send a WhatsApp message when') }}</legend>
            <div class="row">
                @foreach ($WhatsappNotification as $notification)
                    <div class="col-lg-4 col-md-6">
                        <x-ss.switch :name="(string) $notification->id"
                                     :id="'whatsapp-notify-' . $notification->id"
                                     value="1"
                                     :label="$notification->name"
                                     :checked="$notification->is_active == 1"
                                     class="whatsapp-notification" />
                    </div>
                @endforeach
            </div>
        </fieldset>
    </div>

    <div class="ss-card__footer ss-card__footer--between">
        <a href="#" data-ajax-popup1="true" data-size="md"
           data-title="{{ __('Send Test whatsapp massage') }}"
           data-url="{{ route('whatsappmassage.test') }}"
           class="ss-btn ss-btn--secondary test-whatsapp-massage">{{ __('Send Test Message') }}</a>

        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
