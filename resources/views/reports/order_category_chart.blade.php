<div class="col-xxl-12">
    <div class="row">
        <div class="col-12">
            {{--
                PRD-001 / P2-02-5. Each card read `Sales in <name>` over a money figure with no
                period, while the figure changes with the window button at the top of the page.
                `$NetSalesofcategory` sums `final_price` out of each order's product JSON — the same
                quantity the dashboard labels ORDER VALUE and qualifies "gross". It is not
                revenue, not cash collected and not profit, and "Sales in" claimed otherwise.
            --}}
            <x-ss.stats :label="__('Order value by category')">
                @foreach ($NetSalesofcategory as $name => $saleAmount)
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

             D-42.3 — THIS TABLE WAS NEVER SHOWING MONEY, and `array_map('intval', …)` is why.
             `ReportController::order_category_reports()` builds `$NetSaleTotal` as an
             APEXCHARTS SERIES LIST, not a numeric series:

                 [['name' => 'Shirts',         'data' => [0, 1234.56, …]],
                  ['name' => 'All Categories', 'data' => [0, 1234.56, …]]]

             one entry per CATEGORY, while `$labels` is one entry per DAY. `intval()` of a
             non-empty array is `1`, so every row read `1` whatever the money was, against a
             label from a different axis. MEASURED on the real route `/category-order-reports`
             with a single order worth 1234.56: `01-Nov | 1`, `02-Nov | 1`, `Total | 2` — the
             last of those being the NUMBER OF SERIES, printed as the merchant's total.

             BOTH HALVES ARE NOW FIXED. The cast is gone, so a shape the component cannot read
             as numbers can no longer be coerced into a figure — it falls to the honest empty
             state instead. AND the handler now passes the per-period money total it was already
             computing and throwing away, so this chart no longer needs that empty state: see the
             `$netSeries` resolution in `order_category_reports()`, which resolves
             `$allCategoriesTotal` or, in the `year` branch, `$totalNetSalesAllCategories`.
             Nothing is calculated here or there; `$return['NetSaleTotal']` still carries the
             untouched nested series to the browser's ApexCharts.

             The per-category money totals were never affected: they come from
             `$NetSalesofcategory` and render in the cards above, already at two places. --}}
        <x-ss.card :title="__('Category Sales Summary')"
                   :note="__('Order value over the selected period.')">
            <x-ss.bar-chart
                :labels="$labels ?? []"
                :values="$netSeries ?? []"
                :value-decimals="2"
                :title="__('Category Sales Summary')"
                :value-label="__('Order value')"
                :column-label="__('Day')"
                :period="$periodLabel" />
        </x-ss.card>
    </div>
</div>

<script src="{{ asset('public/assets/js/plugins/apexcharts.min.js') }}"></script>
