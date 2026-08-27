@if ($plan->shipping_method == 'on')
    <div class="u-save d-flex justify-end">
        {{ __('Shipping') }} : <span class="CURRENCY"></span> <span class="shipping_final_price"> -
            {{ currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0) }} </span>
    </div>
@endif

@stack('clubPointPriceShow')

<input type="hidden" value="{{ $response->data->total_coupon_price ?? 0 }}" id="coupon_amount">
<div class="u-save d-flex justify-end">
    {{ __('Coupon') }} : <span class="discount_amount_currency"> -
        {{ currency_format_with_sym($response->data->total_coupon_price ?? 0, $store->id) ?? SetNumberFormat($response->data->total_coupon_price ?? 0) }}
    </span>
</div>

<div class="u-save d-flex justify-end">
    {{ __('Tax') }} : <span @if ($plan->shipping_method == 'on') class="CURRENCY" @else class="" @endif></span>
    <span class="final_tax_price">
        {{ currency_format_with_sym($response->data->tax_price ?? 0, $store->id) ?? SetNumberFormat($response->data->tax_price) }}
    </span>
</div>

<div class="u-save d-flex justify-end" >
    {{ __('Total') }} :  <span class="final_amount_currency shipping_total_price" final_total="{{ $response->data->total_sub_price  }}">{{ currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_sub_price) }}</span>
</div>
<div class="u-save d-flex justify-end">
    {{ __('To Pay') }} : <span class="deposit_amount_value"> -
        {{ currency_format_with_sym($response->data->deposite_amount ?? 0, $store->id) ?? SetNumberFormat($response->data->deposite_amount) }}
    </span>
</div>

<div class="u-save d-flex justify-end">
    {{ __('Second Payment') }} : <span class="pending_amount_value"> -
        {{ currency_format_with_sym($response->data->pending_amount ?? 0, $store->id) ?? SetNumberFormat($response->data->pending_amount) }}
    </span>
</div>

<script>
    $('.cart-amount-section').hide();
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
            url: '{{ route('manage.deposit.value', $store->slug) }}',
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('.discount_amount_currency').html(response.coupon_price_value);
                $('.deposit_amount_value').html(response.deposite_amount);
                $('.pending_amount_value').html(response.pending_amount);
                $('.shipping_final_price').html(response.shipping_price_value);
                $('.final_tax_price').html(response.tax_price_value);
            }
        });
    }
</script>
