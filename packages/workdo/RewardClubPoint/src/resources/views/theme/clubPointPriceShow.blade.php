@if (
    isset($setting['order_point_price']) &&
    isset($setting['order_points']) &&
    isset($setting['reward_points']) &&
    isset($setting['reward_point_price']) &&
    isset($setting['checkout_points']) &&
    isset($setting['checkout_price']) &&
    $customer->total_club_point >= $setting['checkout_points'] &&
    $response->data->final_price >= $setting['checkout_price']
)
    <div class="flex justify-between">
        <span>{{ __('Reward Price') }}</span>
        <span class="font-semibold reward_price_show text-green-600">
            - {{ currency_format_with_sym(0.0, $customer->store_id) ?? SetNumberFormat(0.0) }}
        </span>
    </div>
@endif
