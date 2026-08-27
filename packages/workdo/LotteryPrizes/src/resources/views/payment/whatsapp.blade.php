<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>
@php
    $store    = getStore($slug);
    $theme_name = $store->theme_id;
    $paymentwall_public_key = \App\Models\Utility::GetValueByName('paymentwall_public_key',$theme_name);
    $CURRENCY_NAME = \App\Models\Utility::GetValueByName('CURRENCY_NAME',$theme_name);

    $wts_number = $data['lottery_info']['lottery_user_telephone'];

@endphp
<input type="hidden" id="return_url">
<script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('public/js/jquery.form.js') }}"></script>
<script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>
<script src="{{ asset('js/custom.js') }}" defer="defer"></script>
<script>
    $( document ).ready(function () {
            var wts_number = '{{$wts_number}}';
            var total_price = '{{ $lottery_price }}';

            var data = {
                type: 'whatsapp',
                total_price: total_price,
                wts_number: wts_number
            }

            getWhatsappUrl(total_price, data);

            $.ajax({
                url: '{{ route('ticket.customer.whatsapp',$store->slug) }}',
                method: 'POST',
                data: data,
                headers: {
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
                },
                success: function (response) {
                    $('#loader').fadeOut();
                    if (response.status == 'success') {
                        setTimeout(function () {
                        var get_url_msg_url = $('#return_url').val();
                        var append_href = get_url_msg_url;
                        window.open(append_href, '_blank');
                    }, 100);

                    setTimeout(function () {
                        var url = response.data.redirect_url;
                        window.location.href = url;
                    }, 1000);

                    } else {
                    $('#owner-whatsapp').prop('disabled',false);
                    show_toastr("Error", data.success, data["status"]);
                    }

                }
            });
        });


        //for create/get Whatsapp Url
        function getWhatsappUrl(coupon = '', finalprice = '', coupon_id = '', data = '') {
            $.ajax({
                url: '{{ route('ticket.get.whatsappurl',$store->slug) }}',
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
