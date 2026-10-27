{{--
    reCAPTCHA, on the design system.
    =============================================================================================

    The enable toggle's label was empty, so the control had no accessible name — and this one was
    worse than its siblings: the empty label was followed by an EMPTY ANCHOR
    (`<a href="…" target="_blank" class="text-blue"></a>`), a focusable element with no text at
    all, which a keyboard user lands on and a screen reader announces as "link". Nothing was ever
    inside it; the same URL is already linked in the heading.

    THE FIELD NAMES AND THE STORAGE NAMES DIFFER HERE, and that is contract, not accident: the
    form posts `google_recaptcha_key` / `google_recaptcha_secret` / `google_recaptcha_version`
    while the rows are `NOCAPTCHA_SITEKEY` / `NOCAPTCHA_SECRET` / `NOCAPTCHA_VERSON` (sic).
    F117 keyed its lookup on the STORAGE name for exactly this reason — keying it on the field
    name would have read nothing, resolved to '', and silently restored the erase.
--}}
<div class="ss-card" id="Recaptcha_Settings">
    <form method="POST" action="{{ route('recaptcha.settings') }}" accept-charset="UTF-8">
        @csrf

        <div class="ss-card__header">
            <div class="ss-page-header__text">
                <h2 class="ss-card__title">{{ __('ReCaptcha Settings') }}</h2>
                <p class="ss-page-header__subtitle">
                    <a href="https://phppot.com/php/how-to-get-google-recaptcha-site-and-secret-key/"
                       target="_blank" rel="noopener noreferrer">
                        {{ __('How to Get Google reCaptcha Site and Secret key') }}
                    </a>
                </p>
            </div>
            <x-ss.switch name="recaptcha_module"
                         id="recaptcha_module"
                         value="yes"
                         :label="__('Enable reCAPTCHA')"
                         :checked="! empty($setting['RECAPTCHA_MODULE']) && $setting['RECAPTCHA_MODULE'] == 'yes'" />
        </div>

        <div class="ss-card__body">
            <div class="row">
                <div class="col-lg-6">
                    <div class="ss-field">
                        <label class="ss-label" for="google_recaptcha_version">{{ __('Google Recaptcha Version') }}</label>
                        <select id="google_recaptcha_version" class="ss-input" name="google_recaptcha_version">
                            <option value="v2" @selected(($setting['NOCAPTCHA_VERSON'] ?? '') === 'v2')>{{ __('v2') }}</option>
                            <option value="v3" @selected(($setting['NOCAPTCHA_VERSON'] ?? '') === 'v3')>{{ __('v3') }}</option>
                        </select>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="ss-field">
                        <label class="ss-label" for="google_recaptcha_key">{{ __('Google Recaptcha Key') }}</label>
                        <input type="text" class="ss-input" id="google_recaptcha_key" name="google_recaptcha_key"
                               value="{{ $setting['NOCAPTCHA_SITEKEY'] ?? '' }}"
                               placeholder="{{ __('Enter Google Recaptcha Key') }}">
                    </div>
                </div>

                <div class="col-lg-6">
                    {{-- F117: the stored credential never reaches the page; the handler keeps it when
                         this box posts empty. Both halves, because either alone is a defect. --}}
                    <x-ss.secret-field
                        name="google_recaptcha_secret"
                        :label="__('Google Recaptcha Secret')"
                        :stored="$setting['NOCAPTCHA_SECRET'] ?? null" />
                </div>
            </div>
        </div>

        <div class="ss-card__footer ss-card__footer--end">
            <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
        </div>
    </form>
</div>
