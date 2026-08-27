<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $store    = getStore($slug);
    $theme_name = $store->theme_id;
    $paymentwall_public_key = \App\Models\Utility::GetValueByName('paymentwall_public_key',$theme_name);
    $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME',$theme_name);

    @endphp
    <input type="hidden" id="return_url">
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('public/js/jquery.form.js') }}"></script>
<script src="{{ asset('js/custom.js') }}" defer="defer"></script>
<script>
    $( document ).ready(function () {
            var order_id = '{{time()}}';
            var total_price = '{{ $donation_amount }}';
            var data = {
                type: 'telegram',
                total_price: total_price,
                order_id: order_id,
            }

            getWhatsappUrl(total_price, data);

            $.ajax({
                url: '{{ route('donation.customer.telegram',$store->slug) }}',
                method: 'POST',
                data: data,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (response) {
                    $('#loader').fadeOut();
                    if (response.status === 'success') {
                        window.location.href = response.data.redirect_url;
                    }  else {
                        // Handle error
                    }

                }
            });
        });

        //for create/get Whatsapp Url
        function getWhatsappUrl(coupon = '', finalprice = '', coupon_id = '', data = '') {
            $.ajax({
                url: '{{ route('donation.get.whatsappurl',$store->slug) }}',
                method: 'post',
                data: {finalprice: finalprice, data: data},
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (data) {
                    $('#loader').fadeOut();
                    $('#return_url').attr('value', data);
                    $('#return_url').val(data);
                }
            });
        }

</script>
