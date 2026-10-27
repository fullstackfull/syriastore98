{{--
    Style customisation, on the design system.

    These two boxes put raw CSS and raw JS on the storefront, which is why P1-OWN-12 left them
    OUT of `StoreSettingsAccess`'s restricted names: who may put script on a storefront is the
    owner's decision, not this panel's. The fields are unchanged; only the markup moved.

    `@error` was rendering a `<strong class="text-danger">` with no association to the field.
    `aria-describedby` + `role="alert"` on the message makes it part of the control.
--}}
<div class="ss-card mb-3" id="style_customize">
    <form method="POST" action="{{ route('customize.settings') }}" accept-charset="UTF-8">
        @csrf

        <div class="ss-card__header">
            <div class="ss-page-header__text">
                <h2 class="ss-card__title">{{ __('Style Customize') }}</h2>
            </div>
        </div>

        <div class="ss-card__body">
            <div class="ss-field">
                <label class="ss-label" for="storecss">{{ __('Style Customize') }}</label>
                <textarea class="ss-input" id="storecss" name="storecss" rows="3"
                          placeholder="{{ __('Custom CSS') }}"
                          @error('storecss') aria-describedby="storecss-error" aria-invalid="true" @enderror>{{ $setting['storecss'] ?? '' }}</textarea>
                @error('storecss')
                    <p class="ss-field__error" id="storecss-error" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="ss-field">
                <label class="ss-label" for="storejs">{{ __('JS Customize') }}</label>
                <textarea class="ss-input" id="storejs" name="storejs" rows="3"
                          placeholder="{{ __('Custom JS') }}"
                          @error('storejs') aria-describedby="storejs-error" aria-invalid="true" @enderror>{{ $setting['storejs'] ?? '' }}</textarea>
                @error('storejs')
                    <p class="ss-field__error" id="storejs-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="ss-card__footer ss-card__footer--end">
            <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save Changes') }}</button>
        </div>
    </form>
</div>
