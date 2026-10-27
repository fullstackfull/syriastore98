{{--
    Email notifications, on the design system.

    THE SAME EMPTY-LABEL PATTERN AS THE WHATSAPP ROWS, once per email template: the visible name
    in a `<label>` with no `for`, beside a checkbox whose own associated label was empty. So
    every toggle on the screen — one per template, which is a dozen or more on a real store —
    had its name on screen and nowhere assistive technology would find it.

    The toggles post under the template's numeric id, which is data rather than contract, so this
    panel is not in `SettingsPanelContractTest`.
--}}
<div id="email-notification-settings" class="ss-card">
    {{ Form::model($setting, ['route' => ['update.email.statue'], 'method' => 'post']) }}
    @csrf

    <div class="ss-card__header">
        <div class="ss-page-header__text">
            <h2 class="ss-card__title">{{ __('Email Notification Settings') }}</h2>
        </div>
    </div>

    <div class="ss-card__body">
        <fieldset class="ss-field">
            <legend class="ss-label">{{ __('Send an email when') }}</legend>
            <div class="row">
                @foreach ($emailTemplates as $EmailTemplate)
                    <div class="col-lg-4 col-md-6">
                        <x-ss.switch :name="(string) $EmailTemplate->id"
                                     :id="'email_template_' . $EmailTemplate->template->id"
                                     value="1"
                                     :label="$EmailTemplate->name"
                                     :checked="$EmailTemplate->template->is_active == 1" />
                    </div>
                @endforeach
            </div>
        </fieldset>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <button type="submit" class="ss-btn ss-btn--primary btn-print-invoice">{{ __('Save Changes') }}</button>
    </div>
    {{ Form::close() }}
</div>
