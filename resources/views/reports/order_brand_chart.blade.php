<div class="col-xxl-12">
    <div class="row">
        <div class="col-12">
            {{--
                PRD-001 / P2-02-5. Each card read `Sales in <name>` over a money figure with no
                period, while the figure changes with the window button at the top of the page.
                `$NetSalesofbrand` sums `final_price` out of each order's product JSON — the same
                quantity the dashboard labels ORDER VALUE and qualifies "gross". It is not
                revenue, not cash collected and not profit, and "Sales in" claimed otherwise.
            --}}
            <x-ss.stats :label="__('Order value by brand')">
                @foreach ($NetSalesofbrand as $name => $saleAmount)
                    <x-ss.stat :label="$name"
                               :value="$currency . number_format($saleAmount, 2)"
                               :period="$periodLabel"
                               :hint="__('The total value of items ordered. This is not payments received, and not profit.')" />
                @endforeach
            </x-ss.stats>
        </div>
    </div>
    <div class="col-xxl-12">
        {{-- See `order_product_chart`: the series the handler already returned to the browser
             now reach the view, so the chart ships a real table. --}}
        <x-ss.card :title="__('Brand Sales Summary')"
                   :note="__('Order value over the selected period.')">
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="array_map('intval', $netSeries ?? [])"
                :title="__('Brand Sales Summary')"
                :value-label="__('Order value')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>
</div>

<script src="{{ asset('public/assets/js/plugins/apexcharts.min.js') }}"></script>
