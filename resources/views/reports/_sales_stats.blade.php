{{--
    The six sales figures, shared by the two order-report bodies.
    =============================================================================================

    `order_chart_data` and `order_bar_chart_data` render the SAME six numbers from the SAME
    handler data — one as plain cards, one as cards with icon buttons — and each had its own copy
    of the markup, so each carried the same two defects independently: no period on any figure
    (two of them LABELLED "in this period" with no period named), and the order COUNT and item
    COUNT printed with the currency symbol glued on, so "$7" meant seven orders.

    One copy, so the next fix lands on both.
--}}
    <x-ss.stats :label="__('Sales summary')">
        <x-ss.stat :label="__('Order value, before discounts')"
               :value="$currency . (!empty($TotalgrossSale) ? $TotalgrossSale : '0.00')"
               :period="$periodLabel"
               :hint="__('The total value of orders before any coupon was applied. This is not payments received, and not profit.')" />

        <x-ss.stat :label="__('Order value')"
               :value="$currency . (!empty($TotalNetSale) ? $TotalNetSale : '0.00')"
               :period="$periodLabel"
               :hint="__('The total value of items ordered. This is not payments received, and not profit.')" />

        {{-- COUNTS, so no currency symbol. --}}
        <x-ss.stat :label="__('Orders')"
               :value="number_format((int) ($TotalOrder ?? 0))"
               :period="$periodLabel" />

        <x-ss.stat :label="__('Items ordered')"
               :value="number_format((int) ($PurchasedProductItemTotal ?? 0))"
               :period="$periodLabel"
               :hint="__('How many units were ordered, not how many were delivered or paid for.')" />

        <x-ss.stat :label="__('Shipping charged')"
               :value="$currency . (!empty($TotalShippingCharge) ? $TotalShippingCharge : '0.00')"
               :period="$periodLabel" />

        <x-ss.stat :label="__('Coupon value applied')"
               :value="$currency . (!empty($TotalCouponAmount) ? $TotalCouponAmount : '0.00')"
               :period="$periodLabel"
               :hint="__('The amount discounted by coupons, already deducted from order value.')" />
    </x-ss.stats>
