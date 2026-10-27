{{--
    The send-a-test-mail modal, on the design system.

    `id="email_sending"` is shared with `test_whatsappmessage`, so both modals in one DOM is a
    duplicate id — and `id` matching is CASE-SENSITIVE and must be unique for `for`,
    `aria-describedby` and `getElementById` to mean anything. Prefixed per modal.
--}}
<form class="px-3" method="post" action="{{ route('test.send.mail') }}" id="test_email">
    @csrf

    <input type="hidden" name="mail_driver" value="{{ $data['mail_driver'] }}" />
    <input type="hidden" name="mail_host" value="{{ $data['mail_host'] }}" />
    <input type="hidden" name="mail_port" value="{{ $data['mail_port'] }}" />
    <input type="hidden" name="mail_username" value="{{ $data['mail_username'] }}" />
    {{-- F117: the SMTP password is NOT posted back through the browser. It used to sit here in a
         hidden input carrying the stored value. The send handler resolves it: what the merchant
         typed if they typed one, otherwise the stored value — never published to the page. --}}
    <input type="hidden" name="mail_encryption" value="{{ $data['mail_encryption'] }}" />
    <input type="hidden" name="mail_from_address" value="{{ $data['mail_from_address'] }}" />
    <input type="hidden" name="mail_from_name" value="{{ $data['mail_from_name'] }}" />

    <div class="ss-field">
        <label class="ss-label" for="test_mail_email">{{ __('E-Mail Address') }}</label>
        <input type="email" class="ss-input" id="test_mail_email" name="email"
               placeholder="{{ __('Enter Email Address') }}" required>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <span id="test_mail_sending" class="ss-field__hint" style="display: none;" role="status">
            {{ __('Sending…') }}
        </span>
        <button type="button" class="ss-btn ss-btn--secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
        <button type="submit" class="ss-btn ss-btn--primary btn-create">{{ __('Send Test Mail') }}</button>
    </div>
</form>
