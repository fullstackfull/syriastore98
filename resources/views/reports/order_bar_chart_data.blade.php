{{--
    The sales report's bar-chart body.
    =============================================================================================

    It rendered the SAME six figures as `order_chart_data` from the same handler data, in its own
    copy of the markup — and so carried the same two defects independently: no period on any
    figure, and the order and item COUNTS printed with the currency symbol glued on, so "$7"
    meant seven orders. The figures come from `reports/_sales_stats` now, so the next fix lands
    on both bodies.

    The two charts had no accessible representation: a radial-bar and a line, each an empty div
    that ApexCharts drew into. A radial bar of two values is a bar chart that is harder to read,
    and a line over days is a bar chart that implies continuity between them; both ship their
    numbers as real tables now.
--}}

<div class="row">
    <div class="col-12">
        @include('reports._sales_stats')
    </div>

    <div class="col-12 mt-3">
        <x-ss.card :title="__('Order and Purchase Product Overview')"
                   :note="__('Orders placed against units ordered, day by day.')">
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="array_map('intval', $orderSeries ?? [])"
                :values2="array_map('intval', $itemSeries ?? [])"
                :title="__('Order and Purchase Product Overview')"
                :value-label="__('Orders')"
                :value-label2="__('Items ordered')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>

    <div class="col-12 mt-3">
        <x-ss.card :title="__('Order Summary')"
                   :note="__('Order value before and after discounts, day by day.')">
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="array_map('intval', $grossSeries ?? [])"
                :values2="array_map('intval', $netSeries ?? [])"
                :title="__('Order Summary')"
                :value-label="__('Order value, before discounts')"
                :value-label2="__('Order value')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>
</div>
