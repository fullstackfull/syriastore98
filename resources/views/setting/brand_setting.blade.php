{{--
    Brand settings, on the design system.
    =============================================================================================

    THE THEME COLOUR PICKER WAS UNREACHABLE BY KEYBOARD AND INVISIBLE TO A SCREEN READER, and it
    did not even remember the saved colour.

    It was ten EMPTY `<a href="#!">` with `onclick="check_theme('theme-n')"`, each followed by an
    `<input type="radio" style="display: none;">`. `display: none` removes an element from the
    accessibility tree and from the tab order entirely, so the only focusable things were ten
    anchors with no text — announced as "link" ten times, with nothing to distinguish them. And
    not one radio carried `checked`: the saved colour was shown only as an `active_color` CLASS
    on the anchor, so a merchant who opened this panel and pressed Save without clicking a
    swatch posted NO `color` at all.

    `check_theme()` set the radio on click. Its first line, `$('.theme-color').prop('checked',
    false)`, targets the wrapper `<div class="theme-color">` and not the inputs — which are
    `theme_color`, with an underscore — so it never did anything; radios of one name uncheck each
    other anyway.

    They are real radios with real labels now: focusable, arrow-key navigable, each with a
    visually-hidden name, and the stored colour is `@checked`. The function is left alone — other
    panels may call it — but nothing here needs it.

    THE THREE IMAGE PREVIEWS called `URL.createObjectURL(this.files[0])` with no guard, which
    throws when the file picker is cancelled. And each preview `<img>` had no `alt` at all.
--}}
<div class="ss-card" id="Brand_Setting">
    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Brand Settings') }}</h2>
        </div>
    </div>

    {{ Form::model($setting, ['route' => 'business.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
    <div class="ss-card__body">
        <div class="row">
            <div class="col-lg-4 col-sm-6 col-md-6">
                <div class="ss-card">
                    <div class="ss-card__header">
                        <div class="ss-page-header__text">
                            <h3 class="ss-card__title">{{ __('Logo dark') }}</h3>
                        </div>
                    </div>
                    <div class="ss-card__body">
                        <img src="{{ ! empty($setting['logo_dark']) ? get_file($setting['logo_dark']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/logo-dark.png')) . '?timestamp=' . time() }}"
                             class="img_setting" id="before" alt="" aria-hidden="true">

                        <div class="ss-field mt-3">
                            <label class="ss-label" for="company_logo">{{ __('Choose File Here') }}</label>
                            <input type="file" class="ss-input file" id="company_logo" name="logo_dark"
                                   data-filename="company_logo_update"
                                   accept="image/png, image/jpeg, image/jpg, image/gif, image/svg+xml"
                                   onchange="if (this.files[0]) { document.getElementById('before').src = window.URL.createObjectURL(this.files[0]); }"
                                   @error('logo_dark') aria-describedby="logo_dark-error" aria-invalid="true" @enderror>
                            @error('logo_dark')
                                <p class="ss-field__error" id="logo_dark-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-sm-6 col-md-6">
                <div class="ss-card">
                    <div class="ss-card__header">
                        <div class="ss-page-header__text">
                            <h3 class="ss-card__title">{{ __('Logo Light') }}</h3>
                        </div>
                    </div>
                    <div class="ss-card__body">
                        <img src="{{ ! empty($setting['logo_light']) ? get_file($setting['logo_light']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/logo-light.png')) . '?timestamp=' . time() }}"
                             class="img_setting" id="logo-light" alt="" aria-hidden="true">

                        <div class="ss-field mt-3">
                            <label class="ss-label" for="company_logo_light">{{ __('Choose File Here') }}</label>
                            <input type="file" class="ss-input file" id="company_logo_light" name="logo_light"
                                   data-filename="dark_logo_update"
                                   accept="image/png, image/jpeg, image/jpg, image/gif, image/svg+xml"
                                   onchange="if (this.files[0]) { document.getElementById('logo-light').src = window.URL.createObjectURL(this.files[0]); }"
                                   @error('logo_light') aria-describedby="logo_light-error" aria-invalid="true" @enderror>
                            @error('logo_light')
                                <p class="ss-field__error" id="logo_light-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 col-sm-6 col-md-6">
                <div class="ss-card">
                    <div class="ss-card__header">
                        <div class="ss-page-header__text">
                            <h3 class="ss-card__title">{{ __('Favicon') }}</h3>
                        </div>
                    </div>
                    <div class="ss-card__body">
                        <img src="{{ ! empty($setting['favicon']) ? get_file($setting['favicon']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/favicon.png')) . '?timestamp=' . time() }}"
                             class="img_setting" id="faviCon" alt="" aria-hidden="true">

                        <div class="ss-field mt-3">
                            <label class="ss-label" for="company_favicon">{{ __('Choose File Here') }}</label>
                            <input type="file" class="ss-input file" id="company_favicon" name="favicon"
                                   data-filename="company_favicon_update"
                                   accept="image/png, image/jpeg, image/jpg, image/gif, image/svg+xml"
                                   onchange="if (this.files[0]) { document.getElementById('faviCon').src = window.URL.createObjectURL(this.files[0]); }"
                                   @error('favicon') aria-describedby="favicon-error" aria-invalid="true" @enderror>
                            @error('favicon')
                                <p class="ss-field__error" id="favicon-error" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-6 col-md-12">
                <div class="ss-field">
                    <label class="ss-label" for="title_text">{{ __('Title Text') }}</label>
                    <input type="text" class="ss-input" id="title_text" name="title_text"
                           value="{{ $setting['title_text'] ?? '' }}" placeholder="{{ __('Title Text') }}"
                           @error('title_text') aria-describedby="title_text-error" aria-invalid="true" @enderror>
                    @error('title_text')
                        <p class="ss-field__error" id="title_text-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="col-lg-6 col-md-12">
                <div class="ss-field">
                    <label class="ss-label" for="footer_text">{{ __('Footer Text') }}</label>
                    <input type="text" class="ss-input" id="footer_text" name="footer_text"
                           value="{{ $setting['footer_text'] ?? '' }}" placeholder="{{ __('Footer Text') }}"
                           @error('footer_text') aria-describedby="footer_text-error" aria-invalid="true" @enderror>
                    @error('footer_text')
                        <p class="ss-field__error" id="footer_text-error" role="alert">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        </div>

        <div class="row">
                <div class="col-6 col-md-3">
                    <x-ss.switch name="SITE_RTL" id="SITE_RTL" value="on"
                                 :label="__('Enable RTL')"
                                 :checked="isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on'" />
                </div>
                @if (auth()->user() && auth()->user()->type == 'admin' && $plan && $plan->enable_tax == 'on')
                <div class="col-6 col-md-3">
                    <x-ss.switch name="taxes" id="Taxes" value="on"
                                 :label="__('Enable Taxes')"
                                 :checked="isset($setting['taxes']) && $setting['taxes'] == 'on'" />
                </div>
                @endif
                @if (auth()->user()->type == 'super admin')
                <div class="col-6 col-md-3">
                    <x-ss.switch name="display_landing" id="display_landing" value="on"
                                 :label="__('Enable Landing Page')"
                                 :checked="isset($setting['display_landing']) && $setting['display_landing'] == 'on'" />
                </div>
                @endif
                @if (auth()->user()->type == 'super admin')
                <div class="col-6 col-md-3">
                    <x-ss.switch name="SIGNUP" id="SIGNUP" value="on"
                                 :label="__('Enable Sign-Up Page')"
                                 :checked="isset($setting['SIGNUP']) && $setting['SIGNUP'] == 'on'" />
                </div>
                @endif
                @if (auth()->user()->type == 'super admin')
                <div class="col-6 col-md-3">
                    <x-ss.switch name="email_verification" id="email_verification" value="on"
                                 :label="__('Enable Email Verification')"
                                 :checked="isset($setting['email_verification']) && $setting['email_verification'] == 'on'" />
                </div>
                @endif
        </div>

        <div class="row mt-3">
            <div class="col-md-4 col-12">
                {{--
                    GAP-060 / GAP-061 (WS I4 + I5) — ten swatches whose only accessible names are
                    "Theme colour 1" to "Theme colour 10", so the group is exactly the case an
                    instruction exists for: the options cannot describe themselves.

                    WHAT IT SAYS IS MEASURED, not assumed. The stored `color` is printed by the
                    ADMIN layout and by nothing else: `resources/views/layouts/app.blade.php:338-340`
                    feeds it through `StoreColour::forCss()` into `--color-customColor`, which
                    public/css/custom-color.css drives 39 selectors from -- the sidebar and the
                    active nav item among them. No layout under `resources/views/front_end/`
                    references it. So a merchant choosing a swatch here to change their SHOP is
                    using the wrong control, and until now nothing on the screen said so.
                --}}
                <fieldset class="ss-field" aria-describedby="color-group-help">
                    <legend class="ss-label">{{ __('Primary Color Settings') }}</legend>

                    <p class="ss-field__hint" id="color-group-help">
                        {{ __('This colours the merchant panel: its sidebar, the selected menu item and the primary buttons. It does not change your storefront, whose colours come from the storefront theme.') }}
                    </p>

                    <div class="ss-swatches">
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-1" value="theme-1" @checked(($setting['color'] ?? '') === 'theme-1')>
                            <label class="ss-swatch__label ss-swatch--theme-1" for="theme-1">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 1]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-2" value="theme-2" @checked(($setting['color'] ?? '') === 'theme-2')>
                            <label class="ss-swatch__label ss-swatch--theme-2" for="theme-2">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 2]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-3" value="theme-3" @checked(($setting['color'] ?? '') === 'theme-3')>
                            <label class="ss-swatch__label ss-swatch--theme-3" for="theme-3">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 3]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-4" value="theme-4" @checked(($setting['color'] ?? '') === 'theme-4')>
                            <label class="ss-swatch__label ss-swatch--theme-4" for="theme-4">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 4]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-5" value="theme-5" @checked(($setting['color'] ?? '') === 'theme-5')>
                            <label class="ss-swatch__label ss-swatch--theme-5" for="theme-5">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 5]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-6" value="theme-6" @checked(($setting['color'] ?? '') === 'theme-6')>
                            <label class="ss-swatch__label ss-swatch--theme-6" for="theme-6">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 6]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-7" value="theme-7" @checked(($setting['color'] ?? '') === 'theme-7')>
                            <label class="ss-swatch__label ss-swatch--theme-7" for="theme-7">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 7]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-8" value="theme-8" @checked(($setting['color'] ?? '') === 'theme-8')>
                            <label class="ss-swatch__label ss-swatch--theme-8" for="theme-8">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 8]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-9" value="theme-9" @checked(($setting['color'] ?? '') === 'theme-9')>
                            <label class="ss-swatch__label ss-swatch--theme-9" for="theme-9">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 9]) }}</span>
                            </label>
                        </div>
                        <div class="ss-swatch">
                            <input type="radio" class="ss-swatch__input theme_color" name="color"
                                   id="theme-10" value="theme-10" @checked(($setting['color'] ?? '') === 'theme-10')>
                            <label class="ss-swatch__label ss-swatch--theme-10" for="theme-10">
                                <span class="ss-visually-hidden">{{ __('Theme colour :n', ['n' => 10]) }}</span>
                            </label>
                        </div>
                    </div>

                    <div class="ss-field mt-3">
                        <label class="ss-label" for="color-picker">{{ __('Custom colour') }}</label>
                        <input type="color" class="ss-input ss-input--color colorPicker {{ isset($flag) && $flag == 'true' ? 'active_color' : '' }}"
                               name="custom_color" id="color-picker"
                               value="{{ $setting['color'] ?? '#000000' }}">
                        <input type="hidden" name="color_flag"
                               value="{{ isset($flag) && $flag == 'true' ? 'true' : 'false' }}">
                    </div>
                </fieldset>
            </div>

            {{-- GAP-060 WS5 — the two switch groups on this card, each described on its FIELDSET.

                 `aria-describedby` on the fieldset and not on the switch, and the id convention is
                 `x-ss.choice-group`'s own (`<name>-group-help`) so the two surfaces cannot drift
                 into two conventions. The fieldsets stay INLINE rather than swapping in the
                 component, exactly as the colour group above them does -- and here that is load
                 bearing rather than cosmetic: `DeferredChoiceGuidanceTest`'s census finds a switch
                 group by the `<legend>` in THIS FILE's source, so moving the legend into a
                 component would take the census's count of switch groups from 5 to 0 and the
                 premise test would start reporting a settings tree that has none.

                 BOTH VALUES ARE STORED PER STORE, not per user: `SettingController` writes them
                 with `['name' => $key, 'store_id' => getCurrentStore()]` (around line 536) and
                 supplies 'off' itself when the box posts nothing (:518-523), which is why the dark
                 sentence says everyone who signs in sees the same choice. --}}
            <div class="col-md-4 col-12">
                {{-- Transparent layout: `partision/sidebar.blade.php:64` adds `transprent-bg` to
                     the nav when this is 'on', and the rule that acts on it --
                     `.dash-sidebar.light-sidebar.transprent-bg { background: transparent }` at
                     `public/assets/css/style.css:16359` -- sits inside
                     `@media (min-width: 1025px)`. Below that width the switch changes nothing
                     visible, which is the fact the sentence carries. `partision/header.blade.php:32`
                     has the class unconditionally, so the header is not what this controls. Nothing
                     under `resources/views/front_end/` reads the name. --}}
                <fieldset class="ss-field" aria-describedby="cust_theme_bg-group-help">
                    <legend class="ss-label">{{ __('Sidebar Settings') }}</legend>

                    <p class="ss-field__hint" id="cust_theme_bg-group-help">
                        {{ __('Transparent layout removes the background from the sidebar of this admin panel only, and only on screens 1025 pixels and wider; below that width the sidebar keeps its solid background whatever this is set to. It does not change your storefront.') }}
                    </p>

                    <x-ss.switch name="cust_theme_bg" id="cust_theme_bg" value="on"
                                 :label="__('Transparent layout')"
                                 :checked="isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on'" />
                </fieldset>
            </div>

            <div class="col-md-4 col-12">
                {{-- Dark Layout: `layouts/app.blade.php:86` puts `data-ss-theme="dark"` on the body
                     when this is 'on' and :189/:193 swap in `rtl-style-dark.css` or
                     `style-dark.css` -- the admin's dark mode is a STYLESHEET SWAP, not
                     `prefers-color-scheme`, which is why it is a saved setting at all. It ALSO
                     changes which logo the chrome prints: `Utility.php:2828` and
                     `helper.php:2189` (`sidebar_logo()`) return `logo_light` instead of
                     `logo_dark` when it is on, and the sentence names that because a merchant who
                     has only uploaded a dark logo will otherwise wonder where it went. --}}
                <fieldset class="ss-field" aria-describedby="cust_darklayout-group-help">
                    <legend class="ss-label">{{ __('Layout Settings') }}</legend>

                    <p class="ss-field__hint" id="cust_darklayout-group-help">
                        {{ __('Dark Layout switches this admin panel to its dark stylesheet and makes the sidebar show your light logo in place of your dark one. It is stored for the whole store, so everyone who signs in sees the same choice, and it does not change your storefront.') }}
                    </p>

                    <x-ss.switch name="cust_darklayout" id="cust-darklayout" value="on"
                                 :label="__('Dark Layout')"
                                 :checked="isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on'" />
                </fieldset>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {!! Form::close() !!}
</div>
