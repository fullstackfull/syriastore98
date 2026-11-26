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
        {{-- GAP-060 WS5. SAVING THIS PANEL IS A REPLACEMENT, NOT AN AMENDMENT, and the screen shows
             a dozen independent-looking switches.

             `EmailTemplateController::updateEmailNotificationStatus()` line 169 runs
             `UserEmailTemplate::where('user_id', $user->id)->update(['is_active' => 0])` and THEN
             re-enables only the ids that were posted. An unchecked checkbox posts nothing, so that
             zeroing is how the handler learns a switch was turned off -- and the consequence is that
             anything not ticked at the moment of saving is switched off, whether or not the merchant
             touched it.

             MEASURED through the real endpoint: with two templates both active, posting only the
             first one's id left the first at `is_active = 1` and took the second to 0.

             AND IT IS PER USER, NOT PER STORE: the predicate is `user_id`, and
             `EmailTemplate::template()` (app/Models/EmailTemplate.php:17-20) constrains the relation
             this view reads to `auth()->user()->id` as well. Two staff members of the same store
             therefore keep separate answers, which the sentence says because nothing on the screen
             suggests it.

             The instruction is bound to the FIELDSET, not to a switch: the thing being described is
             the whole list, and a description hung off one toggle out of a dozen would be announced
             only by chance. The fieldset stays inline rather than becoming `x-ss.choice-group` so
             that the settings census keeps finding this group by its own legend. --}}
        <fieldset class="ss-field" aria-describedby="email_notify-group-help">
            <legend class="ss-label">{{ __('Send an email when') }}</legend>

            <p class="ss-field__hint" id="email_notify-group-help">
                {{ __('This is the complete list. Saving switches off every email that is not ticked here, not only the ones you just changed, and the choice is stored for your own account rather than for the whole store.') }}
            </p>

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
