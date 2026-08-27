<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $p_data = is_string($data['billing_info']) ? json_decode($data['billing_info']) : $data['billing_info'];
    $price = $data['cartlist_final_price'];
    $store = \App\Models\Store::where('slug', $data['slug'])->first();
    $razorpay_public_key = \App\Models\Utility::GetValueByName('razorpay_public_key',$store->id);
    $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME',$store->id);

    $logo = asset(Storage::url('uploads/logo/'));
    $company_logo = \App\Models\Utility::GetValByName('logo_dark', $store->id ?? null);

    if(!empty($company_logo)) {
        $company_logo = get_file($company_logo);
    } else {
        $company_logo = $logo . '/' . (isset($company_logo) && !empty($company_logo) ? $company_logo : 'logo-dark.png');
    }
@endphp
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
{{-- Flutterwave JAVASCRIPT FUNCTION --}}
<script>
    $(document).ready(function() {
        var slug = '{{ $store->slug }}';
        var getAmount = '{{ $price }}';
        var order_id = '{{ $order_id = time() }}';
        var product_id = '{{ $order_id }}';
        var useremail = '{{ $p_data['email'] ?? ($p_data->email ?? '') }}';
        var totalAmount = getAmount * 100;

        var coupon_id = $('.hidden_coupon').attr('data_id');
        var dicount_price = $('.dicount_price').html();
        var urls = "{{ route('store.payment.status', $store->slug) }}";
        var options = {
            "key": "{{ $razorpay_public_key }}",  // your Razorpay Key Id
            "amount": totalAmount,
            "currency": '{{ $CURRENCY_NAME }}',
            "description": "Order Id : " + order_id,
            "image": "{{ $company_logo }}",
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
