{{--
    Email settings, on the design system.
    =============================================================================================

    THE MARKUP WAS UNBALANCED. The file opened a `.tab-pane`, a `.card` and an
    `.email-setting-wrap`, and closed four divs — so one more than it opened, which is what put
    the "End Email Setting" comment OUTSIDE the panel and let the next tab's content fall into
    this one's container in some states. It also carried `<input type="hidden" class="email">`,
    a nameless hidden input that posts nothing and that nothing reads.

    The `.tab-pane` wrapper is gone: this partial is injected INTO the tab body by
    `settingForm()`, so it was declaring a second pane inside the one it was already in — with
    `id="eco-1"` and `aria-labelledby="pills-home-tab"`, neither of which exists on this screen.
    A dangling `aria-labelledby` names nothing, which is worse than none.

    The SMTP fields themselves arrive by AJAX into `#getfields` from
    `setting/email_fields.blade.php`, which F117 already converted.
--}}
<div class="ss-card" id="email-settings">
    <div class="email-setting-wrap">
        {{ Form::open(['route' => 'email.settings', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
        @csrf

        <div class="ss-card__header">
            <div class="ss-page-header__text">
                <h2 class="ss-card__title">{{ __('Email Settings') }}</h2>
            </div>
        </div>

        <div class="ss-card__body">
            <div class="row">
                <div class="col-sm-6 col-12">
                    <div class="ss-field">
                        {{-- GAP-060 WS5. The select does two things a merchant cannot see: it
                             rebuilds the form, and it decides which boxes they are allowed to fill.

                             `setting/index.blade.php:423-426` binds `change` on this id to
                             `getEmailSettingFields()`, which POSTs to `get.email.fields` and
                             replaces `#getfields` with that provider's partial -- so the fields
                             below change the moment the select changes, with nothing saved yet.

                             AND `email_fields.blade.php`'s preset table decides editability:
                             `$editable` is true only for `custom`, `smtp` and an unrecognised
                             driver, so for the other eleven the Mail Host is a readonly preset
                             (`smtp.gmail.com`, `smtp.office365.com`, ...) and Mail Port and Mail
                             Encryption are a readonly `587`/`tls` pair. The option labels come from
                             `EmailTemplate::$email_settings` through `Form::select` and are NOT run
                             through the translator, so "Custom" and "SMTP" read the same on the
                             Arabic page and the sentence may quote them in both languages.

                             "Nothing is stored until you press Save Changes" is the third clause
                             because the first two make the screen look as though something already
                             happened. --}}
                        <label class="ss-label" for="email_setting">{{ __('Email Setting') }}</label>
                        {!! Form::select('email_setting', $email_setting ?? [], $setting['email_setting'] ?? $get_setting, [
                            'id' => 'email_setting',
                            'class' => 'ss-input',
                            'aria-describedby' => 'email_setting-help',
                        ]) !!}

                        <x-ss.field-help for="email_setting">{{ __('Choosing a provider replaces the fields on this screen straight away, and for every provider except Custom and SMTP the mail host, port and encryption are filled in for you and cannot be edited. Nothing is stored until you press Save Changes.') }}</x-ss.field-help>
                    </div>
                </div>
            </div>

            {{-- The driver's own fields land here, fetched by `getEmailSettingFields()`. --}}
            <div class="row">
                <div class="col-12" id="getfields"></div>
            </div>
        </div>

        <div class="ss-card__footer ss-card__footer--between">
            <input type="hidden" name="custom_email" id="custom_email"
                   value="{{ $settings['email_setting'] ?? $get_setting }}">

            <a href="#" data-ajax-popup1="true" data-size="md" data-title="{{ __('Send Test Mail') }}"
               data-url="{{ route('email.test') }}"
               class="ss-btn ss-btn--secondary send_email">{{ __('Send Test Mail') }}</a>

            <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save Changes') }}</button>
        </div>
        {{ Form::close() }}
    </div>
</div>

@push('custom-script')
<script>
    $(document).ready(function() {
        getEmailSettingFields($('#email_setting').val());
    });
</script>
@endpush
