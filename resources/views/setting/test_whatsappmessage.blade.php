
<form class="px-3" method="post" action="{{ route('whatsapp.send.massage') }}" id="whatsapp-notification">
    @csrf

    <input type="hidden" name="whatsapp_phone_number_id" value="{{ $data['whatsapp_phone_number_id'] }}" />
    {{-- F117: the access token is NOT posted back through the browser. It used to sit here in a
         hidden input carrying the stored value, which put the credential in the DOM of anyone who
         opened this modal. The send handler reads it from the store's settings instead. --}}
    <div class="row">
        <div class="col-md-12 mb-3">
            <label for="name" class="form-label">{{ __('Mobile No')}}</label>
            <input type="text" class="form-control" id="mobile" name="mobile" required/>
        </div>
        <div class="modal-footer pb-0">
            <label id="email_sending" style="display: none;"><i class="fas fa-clock"></i></label>
            <input type="button" value="{{ __('Cancel') }}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
            <input type="submit" value="{{ __('send') }}" class="btn-create btn btn-primary btn-badge mx-1">
        </div>
    </div>
</form>

