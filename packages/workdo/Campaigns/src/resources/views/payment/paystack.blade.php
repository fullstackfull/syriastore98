<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $payment_setting = getSuperAdminAllSetting();
    $paystack_public_key = isset($payment_setting['paystack_public_key']) ? $payment_setting['paystack_public_key'] : '';
    $currency = isset($payment_setting['CURRENCY_NAME']) ? $payment_setting['CURRENCY_NAME'] : 'USD';
    $user=\Auth::user();

@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script src="https://checkout.paystack.com/service-worker.js"></script>
{{-- PAYSTACK JAVASCRIPT FUNCTION --}}
<script>
    $(document).ready(function() {
        var urls = "{{ route('promote.success')}}";
        var handler = PaystackPop.setup({
            key: '{{ $paystack_public_key }}',
            email: '{{ $user->email }}',
            amount: '{{ $amount * 100 }}',
            currency: '{{ $currency }}',
            ref: 'pay_ref_id' + Math.floor((Math.random() * 1000000000) + 1),
            metadata: {
                custom_fields: [{
                    display_name: "Mobile Number",
                    variable_name: "mobile_number",
                    value: "2345544657"
                }]
            },
            callback: function(response) {
                var paystack_callback = urls;
                window.location.href = paystack_callback;
            },

            onClose: function() {
                alert('Window closed');
            }
        });
        handler.openIframe();
    });
</script>

