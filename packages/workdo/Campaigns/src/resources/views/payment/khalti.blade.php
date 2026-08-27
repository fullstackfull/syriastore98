<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $payment_setting = getSuperAdminAllSetting();
    $khalti_public_key = isset($payment_setting['khalti_public_key']) ? $payment_setting['khalti_public_key'] : '';
    $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('public/js/custom.js') }}"></script>
<script src="https://khalti.s3.ap-south-1.amazonaws.com/KPG/dist/2020.12.17.0.0.0/khalti-checkout.iffe.js"></script>
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1/jquery.min.js"></script>
<script>
    $(document).ready(function() {
        var amount = {{ $amount }} * 100;
        var config = {
            "publicKey": "{{ $khalti_public_key }}",
            "productIdentity": "1234567890",
            "productName": "demo",
            "productUrl": "{{ env('APP_URL') }}",
            "paymentPreference": [
                "KHALTI",
                "EBANKING",
                "MOBILE_BANKING",
                "CONNECT_IPS",
                "SCT",
            ],
            "eventHandler": {
                onSuccess(payload) {
                    console.log('Payment Payload:', payload);
                    if (payload.status == 200) {
                        $.ajaxSetup({
                            headers: {
                                'X-CSRF-Token': '{{ csrf_token() }}'
                            }
                        });
                        $.ajax({
                            url: '{{ route('promote.success') }}',
                            method: 'POST',
                            data: {
                                'payload': payload,
                                'amount': amount,
                            },
                            beforeSend: function() {
                                // You can add a loader here
                            },
                            success: function(response) {
                                console.log(response, response.data.status_code, response.data
                                    .redirect_url);
                                if (response.data.status_code === 200) {
                                    window.location.href = response.data.redirect_url;
                                }
                            },
                            error: function(err) {
                                console.log(err);
                            },
                        });
                    }
                },
                onError(error) {
                    console.log(error);
                },
                onClose() {
                    // Handle the case when the payment modal is closed
                }
            }
        };
        var checkout = new KhaltiCheckout(config);
        checkout.show({
            amount: amount
        });
    });
</script>
