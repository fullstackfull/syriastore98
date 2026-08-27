@if (
    isset($setting['order_point_price']) &&
    isset($setting['order_points']) &&
    isset($setting['reward_points']) &&
    isset($setting['reward_point_price']) &&
    isset($setting['checkout_points']) &&
    isset($setting['checkout_price'])
)
    @if ($customer->total_club_point >= $setting['checkout_points'] && $response->data->final_price >= $setting['checkout_price'])

        @php
            $savePrice = ($customer->total_club_point * $setting['reward_point_price'] ?? 0) / ($setting['reward_points'] ?? 0);
        @endphp

        <div class="bg-gray-50 p-4 rounded-lg space-y-4 border border-dashed">
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-600 font-medium">{{ __('Your Earned Points') }}</span>
                <span class="text-sm font-semibold text-gray-800">
                    {{ $customer->total_club_point }}
                    <input type="hidden" class="customer_total_club_point" name="customer_total_club_point" value="{{ $customer->total_club_point }}">
                </span>
            </div>

            <input type="hidden" class="save_price" name="save_price" value="{{ $savePrice }}">
            <input type="hidden" class="club_point_is_active" name="club_point_is_active" value="off">

            <div class="text-sm text-green-600 font-medium save_price_note">
                {{ __('Note: You can save') }} {{ currency_format_with_sym(($savePrice ?? 0), $customer->store_id) ?? SetNumberFormat($savePrice ?? 0) }} {{ __('after applying your earned points.') }}
            </div>

            <div class="flex justify-end">
                <button type="button" class="btn checkout-btn club_point_apply inline-flex items-center bg-primary text-white px-4 py-2 rounded-md hover:bg-primary-dark transition">
                    {{ __('Apply Points') }}
                    <svg xmlns="http://www.w3.org/2000/svg" class="ml-2" width="20" height="20" fill="none" viewBox="0 0 35 14">
                        <path d="M25.0749 14L35 7L25.0805 0L29.12 6.06667H0V7.93333H29.12L25.0749 14Z" fill="currentColor"></path>
                    </svg>
                </button>
            </div>
        </div>

    @endif
@endif



{{-- @push('page-script') --}}
<script>
    $(document).on('click', '.club_point_apply', function (e) {
        e.preventDefault();
        getClubPointData();
    });

    function getClubPointData(total_price) {
        var toastr_status = total_price === undefined;
        total_price = total_price ?? parseFloat($('.final_amount_currency').attr('final_total'));
        var customer_total_club_point = $('.customer_total_club_point').val();
        var save_price = $('.save_price').val();
        var club_point_is_active = $('.club_point_is_active').val();

        $.ajax({
            url: '{{ route('apply.points', $store->slug) }}',
            method: 'POST',
            data: {
                customer_total_club_point: customer_total_club_point,
                save_price: save_price,
                total_price: total_price,
                _token: '{{ csrf_token() }}'
            },
            success: function (response) {
                $('#loader').fadeOut();
                
                if (club_point_is_active === 'off' || !toastr_status) {
                    if (response.sub_total >= 0) {
                        $('.shipping_total_price').html(response.total_price);
                        $('.final_amount_currency').attr('final_total', response.sub_total);
                        $('.reward_price_show').html(response.save_price);
                        $('.final_amount_point').val(response.sub_total);

                        if (toastr_status) {
                            $('.club_point_is_active').val('on');
                            $('.save_price_note').hide();
                            $('.club_point_apply').hide();
                            $('.coupon_code').hide();
                            $('.apply_coupon').hide();
                            show_toastr('Success', response.message, 'success');
                        }
                    } else {
                        show_toastr('error', 'Amount is invalid.', 'error');
                    }
                } else {
                    show_toastr('error', 'You have already applied points.', 'error');
                }
            }
        });
    }
</script>


{{-- @endpush --}}
