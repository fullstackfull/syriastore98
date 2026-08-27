<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $paystack_public_key = \App\Models\Utility::GetValueByName('paystack_public_key',$store->id);
    $CURRENCY_NAME = $admin_payment_setting['defult_currancy'] ?? 'USD';
    
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="https://js.paystack.co/v1/inline.js"></script>
<script src="https://checkout.paystack.com/service-worker.js"></script>
{{-- PAYSTACK JAVASCRIPT FUNCTION --}}
<script>
    $(document).ready(function() {
        var slug = '{{ $store->slug }}';
        var urls = "{{ route('store.lottery.payment', $store->slug) }}";
        var handler = PaystackPop.setup({
            key: '{{ $paystack_public_key }}',
            email: "test@gmail.com",
            amount: '{{ $lottery_price * 100 }} ',
            currency: '{{  $CURRENCY_NAME }}',
            ref: 'pay_ref_id' + Math.floor((Math.random() * 1000000000) +
                1
            ),
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
                alert('window closed');
            }
        });
        handler.openIframe();
    })
</script>
