{{--
    PWA, on the design system.
    =============================================================================================

    THE ENABLE TOGGLE HAD NO LABEL AT ALL — not an empty one, as in the other panels, but none:
    a bare `<input type="checkbox" name="pwa_store">` in a `<div class="custom-control">`. Even
    the panels with an empty label at least had an element to fill.

    AND THE TWO COLOUR PICKERS SAID `placeholder="18761234567"`. A phone number, copied in from
    another panel's SMS field, on an `<input type="color">` — where a placeholder does nothing at
    all, because a colour input always has a value. Removed rather than translated; that string
    was one of the 49 the Arabic ratchet flagged on these screens, and translating a phone number
    into Arabic to sit invisibly on a colour picker would have been the wrong fix.
--}}
<div class="ss-card" id="Pwa_Setting">
    {{ Form::model($store_settings, ['route' => ['pwa.setting', $store_settings->id], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('PWA Settings') }}</h2>
        </div>
        <x-ss.switch name="pwa_store" id="pwa_store" value="on"
                     :label="__('Enable the PWA')"
                     :checked="($store_settings->enable_pwa_store ?? '') == 'on'"
                     class="enable_pwa_store" />
    </div>

    <div class="ss-card__body">
        <div class="row">
            <div class="col-md-6 pwa_is_enable">
                <div class="ss-field">
                    <label class="ss-label" for="pwa_app_title">{{ __('App Title') }}</label>
                    <input type="text" class="ss-input" id="pwa_app_title" name="pwa_app_title"
                           value="{{ $pwa_data->name ?? '' }}" placeholder="{{ __('App Title') }}">
                </div>
            </div>

            <div class="col-md-6 pwa_is_enable">
                <div class="ss-field">
                    <label class="ss-label" for="pwa_app_name">{{ __('App Name') }}</label>
                    <input type="text" class="ss-input" id="pwa_app_name" name="pwa_app_name"
                           value="{{ $pwa_data->short_name ?? '' }}" placeholder="{{ __('App Name') }}">
                </div>
            </div>

            <div class="col-md-6 pwa_is_enable">
                <div class="ss-field">
                    <label class="ss-label" for="pwa_app_background_color">{{ __('App Background Color') }}</label>
                    <input type="color" class="ss-input ss-input--color color-picker"
                           id="pwa_app_background_color" name="pwa_app_background_color"
                           value="{{ $pwa_data->background_color ?? '#ffffff' }}">
                </div>
            </div>

            <div class="col-md-6 pwa_is_enable">
                <div class="ss-field">
                    <label class="ss-label" for="pwa_app_theme_color">{{ __('App Theme Color') }}</label>
                    <input type="color" class="ss-input ss-input--color color-picker"
                           id="pwa_app_theme_color" name="pwa_app_theme_color"
                           value="{{ $pwa_data->theme_color ?? '#ffffff' }}">
                </div>
            </div>
        </div>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-submit">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
