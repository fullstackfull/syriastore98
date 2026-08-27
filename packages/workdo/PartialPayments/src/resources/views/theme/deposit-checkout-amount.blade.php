@php
$user = \App\Models\User::where('type','admin')->first();
if ($user->type == 'admin') {
$plan = \App\Models\Plan::find($user->plan_id);
}
@endphp
<div class="order-paycol-inner">
    <div class="d-flex align-items-center justify-content-between payment-ttl1-row">
        <div class="payment-ttl-left">
            @if ($plan->shipping_method == 'on')
            <span>
                {{ __('Shipping') }} :
                <span class="payment-shipping">
                    <span class="CURRENCY"></span>
                    <span class="shipping_final_price"> -
                        <b>{{ currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0) }}</b>
                    </span>
                </span>
            </span>
            @endif
            <span>
                {{ __('Tax') }} :
                <b>
                    <span class="payment-shipping">
                        @if ($plan->shipping_method == 'on')
                        <span class="CURRENCY"></span>
                        @endif
                        <span class="final_tax_price"> -
                            {{ currency_format_with_sym($response->data->tax_price, $store->id) ?? SetNumberFormat($response->data->tax_price) }}
                        </span>
                    </span>
                </b>
            </span>
            <span>
                {{ __('Coupon') }}:
                <b class="discount_amount_currency">-
                    {{ currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0) }}</b>
            </span>
            <span>
                {{ __('Total') }} :
                <span @if ($plan->shipping_method == 'on') class="CURRENCY" @else class="" @endif></span>
                <div class="final_amount_currency shipping_total_price">
                    {{ currency_format_with_sym($response->data->final_price, $store->id) ?? SetNumberFormat($response->data->final_price) }}
                </div>
            </span>
            <span>
                {{ __('Second Payment') }} : <span class="CURRENCY"></span> <span class="pending_amount_value"> -
                    {{ currency_format_with_sym($response->data->pending_amount ?? 0, $store->id) ?? SetNumberFormat($response->data->pending_amount) }}
                </span>
            </span>
        </div>
        <div class="payment-ttl-left">
            <h5> {{ __('To Pay') }} : </h5><span class="CURRENCY"></span>
            <div class="ttl-pric"><span class="deposit_amount_value"> -
                    {{ currency_format_with_sym($response->data->deposite_amount ?? 0, $store->id) ?? SetNumberFormat($response->data->deposite_amount) }}
                </span></div>
        </div>
    </div>
</div>
<script>
    $('.checkout_amount .order-paymentcol').hide();
    $('.payment-ttl-row').hide();
    getDepositData();

    function getDepositData(response) {
        if (!response) {
            return;
        }
        var data = {
            tax_price: response.tax_price,
            shipping_price: response.shipping_final_price,
            coupon_price: response.total_coupon_price,
            status: false,
            total_sub_price: response.total_sub_price,
        }
        $.ajax({
            {{ route('manage.deposit.value', $store->slug) }}
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('.discount_amount_currency').html(response.currency + response.coupon_price_value);
                $('.deposit_amount_value').html(response.currency + response.deposite_amount);
                $('.pending_amount_value').html(response.currency + response.pending_amount);
                $('.shipping_final_price').html(response.currency + response.shipping_price_value);
                $('.final_tax_price').html(response.currency + response.tax_price_value);
            }
        });
    }
</script>