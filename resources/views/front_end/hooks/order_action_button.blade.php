@php
$store = getStore($slug);
    if ($store) {
        $user = \App\Models\User::find($store->created_by);
    }
    $order_data = \App\Models\Order::find($order->id);
    $order_status = \App\Models\Utility::GetValueByName('set_order_status', $store->id);
@endphp
@if (module_is_active('PartialPayments'))
    @if ($order_data['delivered_status'] == 7 && $order_status != 'null')
        @php
            $pending_order = \Workdo\PartialPayments\app\Models\OrderPartialPayments::where(
                'order_id',
                $order_data->product_order_id,
            )
                ->where('payment_status', 'Pending payment')
                ->whereNot('payment_amount', 0)
                ->first();
        @endphp
        @if (isset($pending_order) && $pending_order != null)
            @include('partial-payments::theme.payment-button', [
                'order' => $order ?? null,
                'slug' => $slug ?? null,
                'currentTheme' => $currentTheme,
            ])
        @endif
    @endif
@endif
