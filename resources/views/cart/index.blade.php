
@extends('layouts.app')
@section('page-title')
{{ __('Abandoned carts') }}
@endsection
@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Abandoned carts') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
        <x-datatable :dataTable="$dataTable" />
        </div>
    </div>

@endsection
@push('custom-script')
<script>
    $(document).ready(function() {

    $(document).on('click', '.bandoncart', function(e) {
        var cart_id  = $(this).attr('data-id');
        $.ajax({
            url: '{{ route('carts.emailsend') }}',
            method: 'POST',
            data: { cart_id: cart_id },
            context: this,
            /*
             * P2.5-PKG-C. THE PENDING HALF of `abandoned-send-no-pending-no-error`. MEASURED through a
             * 1500ms in-flight request: `#loader` stayed `display: none`, the button was not disabled and
             * carried no `aria-busy` -- so the merchant clicked "send" and nothing on screen changed until
             * the toast arrived. The consequence is not only cosmetic: a second click sent a SECOND e-mail
             * or SMS to the same shopper, and an outbound message cannot be recalled.
             *
             * `aria-busy` is the design system's own affordance for this -- `.ss-btn[aria-busy="true"]`
             * hides the label and spins a pseudo-element (store-syria-ds.css:878), with a variant arm for
             * `--ghost` so it stays legible on a quiet button. `disabled` is what actually prevents the
             * double send; `aria-busy` is what assistive tech reads. Both are cleared in `complete`, which
             * runs after success AND after error, so no failure path can leave a button stuck spinning.
             */
            beforeSend: function() { $(this).attr('aria-busy', 'true').prop('disabled', true); },
            complete: function() { $(this).removeAttr('aria-busy').prop('disabled', false); },
            success: function(response) {
                $('#loader').fadeOut();
                if(response.is_success){

                    show_toastr('Success', response.message, 'success');
                }else{
                    show_toastr('Error', response.message, 'error');

                }
            },
            error: function(xhr) {
                /*
                 * P2.5-PKG-C. THE RECOVERY ACTIONS HAD NO `error` LEG AT ALL, so every failure
                 * was SILENT: the merchant clicked "send", nothing happened, and nothing said
                 * why. These controllers answer 403 for a missing permission and 404 for a cart
                 * that does not resolve -- jQuery routes NEITHER to `success`, so both were
                 * invisible. P2.5-PKG-C made the 404 reachable for a caller with no store, which
                 * turned a latent gap into one a real account hits, so it is closed rather than
                 * recorded.
                 */
                $('#loader').fadeOut();
                var payload = xhr.responseJSON || {};
                // Only a 4xx body is relayed. The first cut of this handler relayed any
                // `message`, and driving a missing wishlist row put `Attempt to read property
                // "customer_id" on null` on the merchant's screen -- Laravel's debug JSON uses
                // the same key. That 500 is fixed; the next one must not be a disclosure, and a
                // 4xx is the class the controller authored for a person to read.
                var authored = (xhr.status >= 400 && xhr.status < 500) ? payload.message : null;
                show_toastr('{{ __('Error') }}', authored || '{{ __('Something went wrong') }}', 'error');
            }
        });

    });
});
$(document).ready(function() {

$(document).on('click', '.bandoncartmess', function(e) {
    var cart_id  = $(this).attr('data-id');
    var cart_id  = $(this).attr('data-id');
    $.ajax({
        url: '{{ route('carts.messagesend') }}',
        method: 'POST',
        data: { cart_id: cart_id },
        context: this,
        // The pending state: see the first handler in cart/index.blade.php. `complete` runs after
        // both success and error, so no path leaves the button spinning.
        beforeSend: function() { $(this).attr('aria-busy', 'true').prop('disabled', true); },
        complete: function() { $(this).removeAttr('aria-busy').prop('disabled', false); },
        success: function(response) {
            $('#loader').fadeOut();
            if(response.is_success){

                show_toastr('Success', response.message, 'success');
            }else{
                show_toastr('Error', response.message, 'error');

            }


        },
        error: function(xhr) {
            // See the first handler in this file: a 403 or 404 was silent without this.
            $('#loader').fadeOut();
            var payload = xhr.responseJSON || {};
            // Only a 4xx body is relayed. The first cut of this handler relayed any
            // `message`, and driving a missing wishlist row put `Attempt to read property
            // "customer_id" on null` on the merchant's screen -- Laravel's debug JSON uses
            // the same key. That 500 is fixed; the next one must not be a disclosure, and a
            // 4xx is the class the controller authored for a person to read.
            var authored = (xhr.status >= 400 && xhr.status < 500) ? payload.message : null;
            show_toastr('{{ __('Error') }}', authored || '{{ __('Something went wrong') }}', 'error');
        }
    });

});
});



</script>
@endpush


