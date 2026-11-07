{{--
    The sales-report body.
    =============================================================================================

    PRD-001, AND A COUNT PRINTED AS MONEY. Six cards, none carrying a period — two of them
    LABELLED "in this period" with no period named — and two of them rendered a COUNT with the
    currency symbol glued to the front: `{{ $currency }}{{ $TotalOrder }}` printed "$7" for seven
    orders, and the same for the item count. A reader has no way to tell that figure from the
    five money figures beside it.

    "Net sale" is also a claim the data does not support. `$TotalNetSale` sums `final_price` out
    of the order rows; the dashboard settled this wording in P2-02-5 and calls it ORDER VALUE,
    qualified gross. The one figure here that IS a different quantity — gross before discount —
    keeps its own label and says what the difference is.
--}}

<div class="col-xxl-12">
    <div class="col-12">
        @include('reports._sales_stats')
    </div>

    <div class="col-12 mt-3">
        <x-ss.card :title="__('Order value before and after discounts')">
            {{-- D-42.3. BOTH SERIES ARE MONEY, so both carry two decimal places and neither is
                 cast to an integer on the way in. `array_map('intval', …)` here, on top of the
                 component's own cast, printed an order worth 1234.56 as `1,234`. --}}
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="$grossSeries ?? []"
                :values2="$netSeries ?? []"
                :value-decimals="2"
                :value-decimals2="2"
                :title="__('Order value before and after discounts')"
                :value-label="__('Order value, before discounts')"
                :value-label2="__('Order value')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>

    <div class="col-12 mt-3">
        <x-ss.card :title="__('Orders placed')"
                   :note="__('Counts every order placed in the period, whatever its status or value. Not revenue.')">
            {{-- A COUNT, so no decimal places: `value-decimals` defaults to 0 and is left off
                 deliberately. The cast is dropped all the same — the component normalises, and
                 an `intval` here would hide a handler that started returning something else. --}}
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="$orderSeries ?? []"
                :title="__('Orders placed')"
                :value-label="__('Orders')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>
</div>
