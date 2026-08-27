<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php

    $payment_setting = getSuperAdminAllSetting();
    $razorpay_public_key = isset($payment_setting['razorpay_public_key']) ? $payment_setting['razorpay_public_key'] : '';
    $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
    $user=\Auth::user();
@endphp

@php
    $logo = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::getValByName('company_logo');
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
{{-- Flutterwave JAVASCRIPT FUNCTION --}}
<script>
    $(document).ready(function() {
        var getAmount = '{{ $amount }}';
        var useremail = '{{ $user->email }}';
        var totalAmount = getAmount * 100;
        var order_id = '{{ time() }}';
        var coupon_id = $('.hidden_coupon').attr('data_id');
        var dicount_price = $('.dicount_price').html();
        var urls = "{{ route('promote.success')}}";
        var options = {
            "key": "{{ $razorpay_public_key }}",  // your Razorpay Key Id
            "amount": totalAmount,
            "currency": '{{ $currency }}',
            "description": "Order Id : " + order_id,
            "image": "{{ $logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png') }}",
            "handler": function(response) {

                var razorPay_callback =  urls;
                window.location.href = razorPay_callback;

            },
            "theme": {
                "color": "#528FF0"
            }
        };

        var rzp1 = new Razorpay(options);
        rzp1.open();
    })
</script>

{{-- /Razerpay JAVASCRIPT FUNCTION --}}
