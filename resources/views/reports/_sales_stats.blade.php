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
        <x-ss.stat :label="__('Order value, including shipping and tax')"
               :value="$currency . (!empty($TotalgrossSale) ? $TotalgrossSale : '0.00')"
               :period="$periodLabel"
               :hint="\App\Support\Reports\ReportFigureNote::grossOrderValueHint()" />

        <x-ss.stat :label="__('Order value')"
               :value="$currency . (!empty($TotalNetSale) ? $TotalNetSale : '0.00')"
               :period="$periodLabel"
               :hint="\App\Support\Reports\ReportFigureNote::orderValueHint()" />

        {{-- COUNTS, so no currency symbol. --}}
        <x-ss.stat :label="__('Orders')"
               :value="number_format((int) ($TotalOrder ?? 0))"
               :period="$periodLabel" />

        <x-ss.stat :label="__('Items ordered')"
               :value="number_format((int) ($PurchasedProductItemTotal ?? 0))"
               :period="$periodLabel"
               :hint="__('How many units were ordered, not how many were delivered or paid for.')" />

        {{-- GAP-217. The only money tile on this card with NO hint at all. Its figure is
             accumulated exactly like the two above it -- `+= (float) $order->delivery_price` off a
             FLOAT column -- so the precision caveat is as true of it, and it had no definition
             either. This is a NEW hint, which is why the <x-ss.stat> census moves. --}}
        <x-ss.stat :label="__('Shipping charged')"
               :value="$currency . (!empty($TotalShippingCharge) ? $TotalShippingCharge : '0.00')"
               :period="$periodLabel"
               :hint="\App\Support\Reports\ReportFigureNote::shippingChargedHint()" />

        {{-- ANA-005 / D2's fourth figure to reach a screen. It is the ONLY tile on this card that is
             not accumulated in the handler's own loop over `orders`: it reads the entitlement ledger
             through `FinanceLedgerFigures`, dated by the day the money ARRIVED rather than the day the
             order was placed, so it deliberately disagrees with the two order-value tiles above. Its
             own `:period` is passed separately because the assembler REPLACES the card's period when
             the ledger cannot vouch for the whole of it -- printing "This month" over a figure that
             starts mid-month is the defect this slice exists to avoid.

             RENDERED ONLY WHEN THE HANDLER RESOLVED IT. A null is "the question cannot be asked" (no
             current store, or a window the report cannot express), not "no money" -- the unavailable
             case is a tile with a stated reason, which the assembler builds. The guard also keeps a
             future includer of this partial from a 500; `SalesStatsPassesItsCollectionFigureTest`
             is what stops that includer silently dropping the tile instead. --}}
        @if (!empty($collection))
            <x-ss.stat :label="__('Payments received')"
                   :value="$collection['value']"
                   :period="$collection['period']"
                   :hint="$collection['hint']" />
        @endif

        <x-ss.stat :label="__('Coupon value applied')"
               :value="$currency . (!empty($TotalCouponAmount) ? $TotalCouponAmount : '0.00')"
               :period="$periodLabel"
               {{-- GAP-217. This tile already said WHAT it is -- the only money tile here that
                    did -- and still said nothing about its precision. The definition is unchanged;
                    the caveat is appended, from the same source as its neighbours. --}}
               :hint="\App\Support\Reports\ReportFigureNote::couponValueAppliedHint()" />
    </x-ss.stats>
