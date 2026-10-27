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
                        <label class="ss-label" for="email_setting">{{ __('Email Setting') }}</label>
                        {!! Form::select('email_setting', $email_setting ?? [], $setting['email_setting'] ?? $get_setting, [
                            'id' => 'email_setting',
                            'class' => 'ss-input',
                        ]) !!}
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
