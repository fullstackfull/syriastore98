<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $store    = \App\Models\Store::where('slug', $data['slug'])->first();
    $slug = $data['slug'];
    $khalti_public_key = \App\Models\Utility::GetValueByName('khalti_public_key', $store->id);
    $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME', $store->id);
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('public/js/custom.js') }}"></script>
<script src="https://khalti.s3.ap-south-1.amazonaws.com/KPG/dist/2020.12.17.0.0.0/khalti-checkout.iffe.js"></script>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
<script>
    $(document).ready(function() {
        {{--
            P1-PAY-03. THE PAISA FIGURE IS COMPUTED ON THE SERVER, NOT IN JAVASCRIPT.

            This was `{{$data['cartlist_final_price']}} * 100`, multiplied by the browser. That was
            harmless while the handler echoed the browser's own figure back to Khalti -- the two
            agreed by construction. The handler now asks Khalti about
            `(int) round(serverPayableAmount() * 100)`, and IEEE-754 makes the browser disagree on
            ordinary totals: 10.07 * 100 is 1006.9999999999999 in JavaScript. A one-paisa
            divergence fails Khalti's token/amount pair check, which this change turns into a
            REJECTED verification -- after the stock has been decremented and the cart cleared, so
            the shopper is charged and told the transaction failed.

            Rounding here, in PHP, with the same expression the handler uses, removes the
            disagreement at its source.
        --}}
        var amount = {{ (int) round(((float) $data['cartlist_final_price']) * 100) }};
        var config = {
            "publicKey": "{{ isset($khalti_public_key) ? $khalti_public_key : '' }}",
            "productIdentity": "1234567890",
            "productName": "demo",
            "productUrl": {!! json_encode(config('app.url')) !!},
            "paymentPreference": [
                "KHALTI",
                "EBANKING",
                "MOBILE_BANKING",
                "CONNECT_IPS",
                "SCT",
            ],
            "eventHandler": {
                onSuccess (payload) {
                    if(payload.status==200) {
                        $.ajaxSetup({
                                headers: {
                                    'X-CSRF-Token': '{{csrf_token()}}'
                                }
                            });
                        $.ajax({
                            url: '{{ route('order.khalti',$slug) }}',
                            method: 'POST',
                            data : {
                                'payload' : payload,
                                'amount' : amount,
                            },
                            beforeSend: function () {

                            },
                            success: function(data) {
                                $('#loader').fadeOut();
                                if(data.status_code === 200){
                                    window.location.href = data.store_complete;
                                    show_toastr('Success','Payment Done Successfully', 'success');
                                }
                                else{
                                    show_toastr('Error','Payment Failed', 'error');
                                }
                            },
                            error: function(err) {
                                $('#loader').fadeOut();
                                show_toastr('Error', err.response, 'error')
                            },
                        });
                    }
                },
                onError (error) {
                    $('#loader').fadeOut();
                    show_toastr('Error', error, 'error')
                },
                onClose () {
                }
            }

        };

        var checkout = new KhaltiCheckout(config);

        checkout.show({ amount: amount });
    });
</script>
