{{--
    Cookie banner settings, on the design system.

    `enable_cookie` sat inside a `<div onclick="enablecookie()">`. TWO THINGS WERE WRONG WITH
    THAT, and the second is the one that matters: the handler was on the WRAPPER rather than the
    control, so a keyboard user toggling the switch with Space never fired it — and
    `enablecookie` IS NOT DEFINED ANYWHERE in this repository, so every click of it threw a
    ReferenceError. Grepped `public/` and `resources/` for it: the only occurrence left is this
    sentence. Removed rather than rewired, because there is nothing to rewire it to.
--}}
<div class="ss-card" id="Cookie_Setting">
    {{ Form::model($setting, ['route' => 'cookie.setting', 'method' => 'post']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Cookie Settings') }}</h2>
        </div>
        <x-ss.switch name="enable_cookie" id="enable_cookie" value="on"
                     :label="__('Enable cookie')"
                     :checked="isset($setting['enable_cookie']) && $setting['enable_cookie'] == 'on'" />
    </div>

    <div class="ss-card__body cookieDiv">
        <div class="row">
            <div class="col-md-6">
                <x-ss.switch name="cookie_logging" id="cookie_logging" value="on"
                             :label="__('Enable logging')"
                             :checked="isset($setting['cookie_logging']) && $setting['cookie_logging'] == 'on'"
                             class="cookie_setting" />

                <div class="ss-field">
                    <label class="ss-label" for="cookie_title">{{ __('Cookie Title') }}</label>
                    <input type="text" class="ss-input cookie_setting" id="cookie_title" name="cookie_title"
                           value="{{ $setting['cookie_title'] ?? '' }}"
                           placeholder="{{ __('Enter Cookie Title') }}">
                </div>

                <div class="ss-field">
                    <label class="ss-label" for="cookie_description">{{ __('Cookie Description') }}</label>
                    <textarea class="ss-input cookie_setting" id="cookie_description" name="cookie_description"
                              rows="3" placeholder="{{ __('Enter Cookie Description') }}">{{ $setting['cookie_description'] ?? '' }}</textarea>
                </div>
            </div>

            <div class="col-md-6">
                <x-ss.switch name="necessary_cookies" id="necessary_cookies" value="on"
                             :label="__('Strictly necessary cookies')"
                             :checked="isset($setting['necessary_cookies']) && $setting['necessary_cookies'] == 'on'" />

                <div class="ss-field">
                    <label class="ss-label" for="strictly_cookie_title">{{ __('Strictly Cookie Title') }}</label>
                    <input type="text" class="ss-input cookie_setting" id="strictly_cookie_title"
                           name="strictly_cookie_title"
                           value="{{ $setting['strictly_cookie_title'] ?? '' }}"
                           placeholder="{{ __('Enter Strictly Cookie Title') }}">
                </div>

                <div class="ss-field">
                    <label class="ss-label" for="strictly_cookie_description">{{ __('Strictly Cookie Description') }}</label>
                    <textarea class="ss-input cookie_setting" id="strictly_cookie_description"
                              name="strictly_cookie_description" rows="3"
                              placeholder="{{ __('Enter Strictly Cookie Description') }}">{{ $setting['strictly_cookie_description'] ?? '' }}</textarea>
                </div>
            </div>

            <div class="col-12">
                <h3 class="ss-label">{{ __('More Information') }}</h3>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="more_information_description">{{ __('Contact Us Description') }}</label>
                    <input type="text" class="ss-input cookie_setting" id="more_information_description"
                           name="more_information_description"
                           value="{{ $setting['more_information_description'] ?? '' }}"
                           placeholder="{{ __('Enter Contact Us Description') }}">
                </div>
            </div>

            <div class="col-md-6">
                <div class="ss-field">
                    <label class="ss-label" for="contactus_url">{{ __('Contact Us URL') }}</label>
                    <input type="url" class="ss-input cookie_setting" id="contactus_url" name="contactus_url"
                           value="{{ $setting['contactus_url'] ?? '' }}"
                           placeholder="{{ __('Enter Contact Us URL') }}">
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--between">
        <div>
            @if (isset($setting['cookie_logging']) && $setting['cookie_logging'] == 'on')
                @if (Storage::exists('uploads/sample/cookie_data.csv'))
                    <a href="{{ asset(Storage::url('uploads/sample/cookie_data.csv')) }}"
                       class="ss-btn ss-btn--secondary ss-btn--sm">
                        <i class="ti ti-download" aria-hidden="true"></i>
                        {{ __('Download cookie accepted data') }}
                    </a>
                @endif
            @endif
        </div>

        <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
