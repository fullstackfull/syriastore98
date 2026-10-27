{{--
    Twilio, on the design system.
    =============================================================================================

    THE ENABLE TOGGLE HAD NO ACCESSIBLE NAME. Its label was literally empty —
    `<label class="custom-control-label form-control-label" for="twilio_setting_enabled"></label>`
    — so a screen reader announced "checkbox, unchecked" with nothing to say what it switches.
    Every integration panel in this directory carried the same empty label. `<x-ss.switch>` takes
    the name as text, so the control cannot ship without one.

    AND THE TWO PHONE FIELDS BOTH SAID `placeholder="twilio consumer secret"` — a copy-paste from
    a different panel, untranslated, telling a merchant to type a secret into the box for their
    sender number.

    The field NAMES and the POST target are unchanged, and pinned by
    `tests/Unit/Ui/SettingsPanelContractTest.php`: a migration that renames one of these breaks
    the save silently, because the handler goes on reading the old key and writes the empty
    string over what is stored.
--}}
<div class="ss-card" id="twilio_setting">
    {{ Form::open(['route' => 'twilio.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Twilio Settings') }}</h2>
        </div>
        {!! Form::hidden('twilio_setting_enabled', 'off') !!}
        <x-ss.switch name="twilio_setting_enabled"
                     id="twilio_setting_enabled"
                     value="on"
                     :label="__('Enable Twilio')"
                     :checked="isset($setting['twilio_setting_enabled']) && $setting['twilio_setting_enabled'] === 'on'" />
    </div>

    <div class="ss-card__body">
        <div class="row">
            {{-- F117. These two printed the live Twilio credentials into `value="…"`, so the SID
                 and the auth token sat in the DOM of a panel a merchant opens to change the
                 sender number. The component carries no value and the handler keeps the stored
                 one when the box posts empty — both halves, because either alone is a defect:
                 masking without the save change erases the credential on the next save. --}}
            <div class="col-lg-6">
                <x-ss.secret-field
                    name="twilio_sid"
                    :label="__('Twilio SID')"
                    :stored="$setting['twilio_sid'] ?? null" />
            </div>
            <div class="col-lg-6">
                <x-ss.secret-field
                    name="twilio_token"
                    :label="__('Twilio Token')"
                    :stored="$setting['twilio_token'] ?? null" />
            </div>

            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="twilio_from">{{ __('Twilio From') }}</label>
                    <input type="text" class="ss-input" id="twilio_from" name="twilio_from"
                           value="{{ $setting['twilio_from'] ?? '' }}"
                           placeholder="{{ __('The number messages are sent from') }}">
                </div>
            </div>

            <div class="col-lg-6">
                <div class="ss-field">
                    <label class="ss-label" for="twilio_notification_number">{{ __('Notification Number') }}</label>
                    <input type="text" class="ss-input" id="twilio_notification_number"
                           name="twilio_notification_number"
                           value="{{ $setting['twilio_notification_number'] ?? '' }}"
                           placeholder="{{ __('The number notifications are sent to') }}"
                           aria-describedby="twilio_notification_number-hint">
                    {{-- Was a bare `<small>` beside the input, which a screen reader reads only
                         if the user happens to move past it. `aria-describedby` makes it part of
                         the field. --}}
                    <p class="ss-field__hint" id="twilio_notification_number-hint">
                        {{ __('Use country code with your number') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
