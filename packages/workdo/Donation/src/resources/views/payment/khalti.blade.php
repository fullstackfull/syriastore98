<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $store    = \App\Models\Store::where('slug', $store->slug)->first();
    $slug = $store->slug;
   $khalti_public_key = \App\Models\Utility::GetValueByName('khalti_public_key',$store->id);
    $CURRENCY_NAME = $admin_payment_setting['CURRENCY_NAME'] ?? 'USD';
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('public/js/custom.js') }}"></script>
<script src="https://khalti.s3.ap-south-1.amazonaws.com/KPG/dist/2020.12.17.0.0.0/khalti-checkout.iffe.js"></script>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
<script>
    $(document).ready(function() {
        var amount = {{$donation_amount}} * 100;
        var config = {
            "publicKey": "{{ isset($khalti_public_key) ? $khalti_public_key : '' }}",
            "productIdentity": "1234567890",
            "productName": "demo",
            "productUrl": "{{env('APP_URL')}}",
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
                            url: '{{ route('store.donation.payment.khalti',$slug) }}',
                            method: 'POST',
                            data : {
                                'payload' : payload,
                                'amount' : amount,
                            },
                            beforeSend: function () {

                            },
                            success: function(response) {
                                $('#loader').fadeOut();
                                if(response.data.status_code === 200){
                                    window.location.href = response.data.redirect_url;
                                }
                            },
                            error: function(err) {
                                $('#loader').fadeOut();

                            },
                        });
                    }
                },
                onError (error) {
                    $('#loader').fadeOut();
                },
                onClose () {
                }
            }

        };

        var checkout = new KhaltiCheckout(config);

        checkout.show({ amount: amount });
    });
</script>
