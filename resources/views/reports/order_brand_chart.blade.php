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
             now reach the view, so the chart ships a real table.

             D-42.3 — THE SAME DEFECT AS `order_category_chart`, from the same shape, PLUS ONE
             OF ITS OWN. `ReportController::order_brand_reports()` builds `$NetSaleTotal` as an
             APEXCHARTS SERIES LIST — one `['name' => …, 'data' => [...]]` entry per BRAND against
             day labels — and `intval()` of a non-empty array is `1`, so every row read `1`
             whatever the money was. MEASURED on `/brand-order-reports` with a single order worth
             1234.56: `01-Nov | 1`, `Total | 1`.

             The cast is gone and the handler now passes the per-period total it already computes
             (`$allBrandsTotal`, or `$totalNetSalesAllBrands` in three of the five branches), so
             this table carries the real money.

             AND THAT IS HOW THE YEAR VIEW'S DOUBLE-COUNT WAS FOUND. With the real series
             reaching the table, `?chart_data=year` read `2,469.12` for a single order worth
             1234.56: the `year` branch accumulated `$totalNetSalesAllBrands` inside its
             month-by-brand loop AND array_mapped every brand's series onto it again afterwards.
             It was the only one of the five branches with both adds. That figure was ALREADY on
             the screen before any of this — it is what the browser's ApexCharts plots as
             `All Brands` and what `$NetSalesofbrand['All Brands']` sums for the card above — and
             the `1`s in this table are what hid it. Driven by
             `ChartMoneyPrecisionTest::test_the_aggregate_is_the_sum_and_never_double`, across all
             five periods of both routes.

             The per-brand money totals in the cards above are unaffected: they come from
             `$NetSalesofbrand`'s per-brand entries and are already formatted to two places. --}}
        <x-ss.card :title="__('Brand Sales Summary')"
                   :note="__('Order value over the selected period.')">
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="$netSeries ?? []"
                :value-decimals="2"
                :title="__('Brand Sales Summary')"
                :value-label="__('Order value')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>
</div>

<script src="{{ asset('public/assets/js/plugins/apexcharts.min.js') }}"></script>
