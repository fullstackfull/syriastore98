{{--
    The send-a-test-message modal, on the design system.

    ITS LABEL POINTED AT NOTHING: `<label for="name">` over an input with `id="mobile"`. A
    dangling `for` names no control, so the field had no accessible name at all — and it is
    silent, because the label still LOOKS attached. `id="email_sending"` was also shared with the
    test-mail modal.
--}}
<form class="px-3" method="post" action="{{ route('whatsapp.send.massage') }}" id="whatsapp-notification">
    @csrf

    <input type="hidden" name="whatsapp_phone_number_id" value="{{ $data['whatsapp_phone_number_id'] }}" />
    {{-- F117: the access token is NOT posted back through the browser. It used to sit here in a
         hidden input carrying the stored value, which put the credential in the DOM of anyone who
         opened this modal. The send handler reads it from the store's settings instead. --}}

    <div class="ss-field">
        <label class="ss-label" for="test_whatsapp_mobile">{{ __('Mobile No') }}</label>
        <input type="text" class="ss-input" id="test_whatsapp_mobile" name="mobile" required>
    </div>

    <div class="ss-card__footer ss-card__footer--end">
        <span id="test_whatsapp_sending" class="ss-field__hint" style="display: none;" role="status">
            {{ __('Sending…') }}
        </span>
        <button type="button" class="ss-btn ss-btn--secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
        <button type="submit" class="ss-btn ss-btn--primary btn-create">{{ __('send') }}</button>
    </div>
</form>
