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
                        {{-- GAP-060 WS5. The version is read ONLY inside the enable gate, and only
                             on the panel's own authentication screens.

                             Every reader tests `RECAPTCHA_MODULE == 'yes'` first --
                             `AuthenticatedSessionController:46`, `RegisteredUserController:57`,
                             `PasswordResetLinkController:41` -- so the toggle in this card's header
                             decides whether this select matters at all, and the sentence names that
                             toggle by its real label, "Enable reCAPTCHA".

                             THE TWO VERSIONS BEHAVE DIFFERENTLY IN THE SAME HANDLER:
                             `v2` adds `g-recaptcha-response => required` to the validation set,
                             while `v3` fires `VerifyReCaptchaToken` and only falls back to requiring
                             that field when the event reports a failure
                             (AuthenticatedSessionController:47-57). The sentence says that rather
                             than anything about scores or key types, which are Google's
                             documentation and not this codebase's behaviour.

                             The readers are `resources/views/auth/*` and `marketplace/pricing`;
                             nothing under `front_end/` reads it, which is why the sentence scopes
                             itself to the sign-in, registration and password screens. --}}
                        <label class="ss-label" for="google_recaptcha_version">{{ __('Google Recaptcha Version') }}</label>
                        <select id="google_recaptcha_version" class="ss-input" name="google_recaptcha_version"
                                aria-describedby="google_recaptcha_version-help">
                            <option value="v2" @selected(($setting['NOCAPTCHA_VERSON'] ?? '') === 'v2')>{{ __('v2') }}</option>
                            <option value="v3" @selected(($setting['NOCAPTCHA_VERSON'] ?? '') === 'v3')>{{ __('v3') }}</option>
                        </select>

                        <x-ss.field-help for="google_recaptcha_version">{{ __('Only read while Enable reCAPTCHA is switched on, and only on the sign in, registration and password screens. v2 makes the challenge box a required field; v3 checks a token in the background instead and asks for the box only when that check fails.') }}</x-ss.field-help>
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
