{{--
    A small bar chart whose ACCESSIBLE REPRESENTATION IS A REAL TABLE.

    `period` is REQUIRED and has no default, for the same reason <x-ss.stat>
    requires one: a series with no stated period is the commonest way a
    dashboard misleads. "Orders" could be today, this week or all time, and the
    reader cannot tell by looking.

    ─────────────────────────────────────────────────────────────────────
    HOW THE ACCESSIBILITY ACTUALLY WORKS, AND WHY IT IS NOT role="img"
    ─────────────────────────────────────────────────────────────────────

    The common pattern is `role="img"` with an `aria-label` summarising the
    series. It is wrong here: a summary sentence is not the data. A merchant
    using a screen reader is entitled to the same seven numbers a sighted
    merchant gets, not a prose approximation of their shape.

    So the bars are `aria-hidden="true"` -- they are a picture of the table --
    and the table is the real content, in a `<details>` that any user can open.
    That makes the data reachable by keyboard, by screen reader, by search in
    the page, and by copy-and-paste, none of which a canvas chart allows.

    The `<table>` carries a `<caption>` naming the series and its period, and
    `scope` on both header axes, so a screen reader announces "Orders, 25 Oct"
    for a cell rather than reading a bare number.

    EMPTY IS NOT ZERO. A series the controller could not build at all
    (`$values` empty) renders an honest empty state; a series of seven zeroes
    renders seven zero bars, because "no orders this week" is a real and
    different answer. <x-ss.stat> draws the same distinction for a single
    figure.

    ─────────────────────────────────────────────────────────────────────
    THE NUMBERS ARE CARRIED FAITHFULLY, AND MONEY IS FORMATTED AS MONEY
    ─────────────────────────────────────────────────────────────────────

    D-42.3. This component used to cast every value with
    `array_map('intval', …)` and then print `number_format($v)` with no
    decimal places. It is the accessible representation of FIVE MONEY CHARTS,
    so the table — which IS the content, the bars being a picture of it — was
    printing a number that was not the merchant's money, in two ways.

    TRUNCATION. An order worth 1234.56 rendered as `1,234`. MEASURED on the
    real route `/product-order-reports`. Five caller files cast a second time
    before passing, at eleven sites, but the component's own cast is the one
    that mattered: removing the callers' wrappers alone left this file
    truncating.

    FABRICATION, which is worse. `reports/order_category_chart` and
    `reports/order_brand_chart` are handed an APEXCHARTS SERIES LIST, not a
    numeric series — `[['name' => 'Shirts', 'data' => [...]], …]`, one entry
    per CATEGORY against labels that are one per DAY. `intval()` of a
    non-empty array is `1`, so every row of those two tables read `1`
    whatever the money was. MEASURED on `/category-order-reports` with one
    order worth 1234.56: `01-Nov | 1`, `02-Nov | 1`, `Total | 2`.

    So a value this component cannot read as a number makes the series
    UNBUILDABLE — it falls to the same empty state as an absent series rather
    than being coerced into a figure. That is a deliberately conservative
    repair: it refuses to state a false amount, and it does NOT invent a rule
    for collapsing a per-category series list into a per-day one, because
    choosing what those two charts plot is a controller fix
    (`order_category_reports()` and `order_brand_reports()` already compute
    `$allCategoriesTotal` / `$totalNetSalesAllBrands` and simply never pass
    them) and this change is display-only.

    `valueDecimals` / `valueDecimals2` DEFAULT TO ZERO, so every caller that
    passes counts renders byte for byte as before — the dashboard's orders
    chart, the order-status report, the four top-sales charts, the two
    customer-report charts and the country report, none of which are money.
    A money caller passes `2`, which is this project's established money rule:
    `number_format($x, 2)`, as on the finance screen and in these very report
    files' own stat cards. The formatting is deliberately NOT locale-derived —
    one money format in Arabic and in English, and `.ss-num` already carries
    `unicode-bidi: isolate` so the figure stays one left-to-right run inside
    an Arabic line.

    @param array  $labels         one per column, already formatted and translated
    @param array  $values         one per column, numeric; decimals preserved
    @param string $title          what the series measures
    @param string $period         REQUIRED: the span the series covers
    @param int    $valueDecimals  decimal places for series A: 0 for a count, 2 for money
    @param int    $valueDecimals2 the same, for series B — the two may differ, and on
                                  `order_product_chart` they do: money against a unit count
--}}
@props([
    'labels' => [],
    'values' => [],
    'title',
    'period',
    'valueLabel' => null,
    'columnLabel' => null,
    'values2' => [],
    'valueLabel2' => null,
    'valueDecimals' => 0,
    'valueDecimals2' => 0,
])

@php
    /* Pair up defensively. The controller builds label[] and data[] in one
       loop so they match today, but a chart that silently drops the last day
       because one array is shorter is worse than one that renders fewer
       columns honestly.

       A SECOND SERIES is optional and narrows the pairing further, for the
       same reason: three arrays that disagree would otherwise let one column
       show series A's October beside series B's November. Added for the
       reports hub, where every chart compares customer against guest; the
       single-series path is untouched, so the dashboard's chart renders byte
       for byte as before. */
    $ssHasB   = is_array($values2) && count($values2) > 0;
    $ssCount  = min(count($labels), count($values));
    if ($ssHasB) {
        $ssCount = min($ssCount, count($values2));
    }
    $ssLabels = array_slice(array_values($labels), 0, $ssCount);

    /* FAITHFUL, OR NOTHING. `null` is returned for a value that is not a
       number, and the caller below turns that into the empty state.

       null and '' become 0, and a bool becomes 0/1, ONLY because that is what
       `intval()` did here before — six count callers rely on today's output
       byte for byte, so this keeps every case that already worked and changes
       only the cases that were wrong. A numeric STRING is normalised with
       `+ 0`, which yields an int for "7" and a float for "7.50": four callers
       pass `pluck()` results, and MySQL hands those back as strings.
       NAN and INF are refused rather than printed as "nan". */
    $ssNumber = static function ($v) {
        if ($v === null || $v === '') {
            return 0;
        }
        if (is_bool($v)) {
            return (int) $v;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v)) {
            return is_finite($v) ? $v : null;
        }
        if (is_string($v) && is_numeric($v)) {
            $n = $v + 0;

            return is_float($n) && ! is_finite($n) ? null : $n;
        }

        return null;
    };

    $ssReadSeries = static function ($raw, int $count) use ($ssNumber) {
        if (! is_array($raw)) {
            return null;
        }
        $out = [];
        foreach (array_slice(array_values($raw), 0, $count) as $v) {
            $n = $ssNumber($v);
            if ($n === null) {
                return null;   /* one unreadable value spoils the series */
            }
            $out[] = $n;
        }

        return $out;
    };

    $ssValues  = $ssReadSeries($values, $ssCount);
    $ssValuesB = $ssHasB ? $ssReadSeries($values2, $ssCount) : [];

    /* An unreadable series is UNBUILDABLE, and falls to the same honest empty
       state as an absent one. It must never become a number: that is the
       `intval(array) === 1` defect this guard exists to stop. */
    if ($ssValues === null || $ssValuesB === null) {
        $ssCount   = 0;
        $ssLabels  = [];
        $ssValues  = [];
        $ssValuesB = [];
    }

    /* ONE SCALE FOR BOTH SERIES. The bars are stacked, so the tallest column
       is the largest SUM, not the largest single value — scaling each series
       to its own maximum would draw a 3 beside a 300 at the same height. */
    $ssStacks = $ssHasB
        ? array_map(static fn ($a, $b) => $a + $b, $ssValues, $ssValuesB)
        : $ssValues;
    /* FLOORED AT ZERO. A money series can genuinely go negative — the sales
       report's net series is `final_price - delivery_price - tax_price` — and
       a negative maximum would invert every height. The table carries the
       negative; the bars cannot draw one and say so by staying at the
       baseline. */
    $ssMax    = $ssCount ? max(0, max($ssStacks)) : 0;
    $ssTotal  = array_sum($ssValues);
    $ssTotalB = array_sum($ssValuesB);

    $ssDecimals  = max(0, (int) $valueDecimals);
    $ssDecimalsB = max(0, (int) $valueDecimals2);
    $ssFormat = static fn ($v, int $places) => number_format((float) $v, $places);

    /* `=== 0` was right while every value was an int and is wrong now: a
       float 0.0 is not identical to int 0, so an exactly-zero money figure
       would have silently lost its baseline marker. */
    $ssIsZero = static fn ($v) => (float) $v === 0.0;

    $ssValueLabel = $valueLabel ?: $title;
    $ssValueLabelB = $valueLabel2 ?: __('Series 2');
    $ssColumnLabel = $columnLabel ?: __('Day');
    $ssTableId = 'ss-chart-' . substr(md5($title . $period), 0, 8);
@endphp

<div {{ $attributes->merge(['class' => 'ss-chart-block']) }}>
    @if ($ssCount === 0)
        <x-ss.empty :title="__('No data for this period yet')" />
    @else
        {{-- The picture. Hidden from assistive technology because the table
             below says the same thing, exactly, and better. --}}
        <div class="ss-chart" aria-hidden="true">
            @foreach ($ssValues as $i => $v)
                @php
                    /* Height as a percentage of the largest bar. When every
                       value is 0 the max is 0, so guard the division -- and
                       render a flat baseline rather than full-height bars,
                       which is what `max(1, ...)` would have done.

                       CLAMPED AT ZERO at the other end too: a negative value
                       would emit `block-size: -37%`, which browsers discard,
                       leaving the bar at its auto height -- a LOSS drawn as
                       tall as a gain. The negative is in the table, which is
                       the real content. */
                    $ssPct = $ssMax > 0 ? max(0, round($v / $ssMax * 100)) : 0;
                @endphp
                <div class="ss-chart__col">
                    @if ($ssHasB)
                        @php
                            $ssPctB = $ssMax > 0 ? max(0, round($ssValuesB[$i] / $ssMax * 100)) : 0;
                        @endphp
                        {{-- Series B sits on top: the column order is bottom-up because
                             `.ss-chart__col` is `flex-direction: column; justify-content:
                             flex-end`, so the LAST child is the lowest. --}}
                        <div class="ss-chart__bar ss-chart__bar--b @if ($ssIsZero($ssValuesB[$i])) ss-chart__bar--zero @endif"
                             style="block-size: {{ $ssPctB }}%"></div>
                    @endif
                    <div class="ss-chart__bar @if ($ssIsZero($v)) ss-chart__bar--zero @endif"
                         style="block-size: {{ $ssPct }}%"></div>
                </div>
            @endforeach
        </div>
        @if ($ssHasB)
            {{-- The legend is `aria-hidden` with the bars: the table names both series
                 in its own column headers, which is the accessible statement. --}}
            <ul class="ss-chart__legend" aria-hidden="true">
                <li class="ss-chart__legend-item"><span class="ss-chart__swatch"></span>{{ $ssValueLabel }}</li>
                <li class="ss-chart__legend-item"><span class="ss-chart__swatch ss-chart__swatch--b"></span>{{ $ssValueLabelB }}</li>
            </ul>
        @endif
        <div class="ss-chart__labels" aria-hidden="true">
            @foreach ($ssLabels as $l)
                <span class="ss-chart__label">{{ $l }}</span>
            @endforeach
        </div>

        {{-- The data. This is the accessible content, not a fallback. --}}
        <details class="ss-chart-data">
            <summary>{{ __('Show the numbers') }}</summary>
            <div class="ss-table-wrap">
                <table class="ss-table" id="{{ $ssTableId }}">
                    <caption class="ss-visually-hidden">
                        {{ $title }} — {{ $period }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ $ssColumnLabel }}</th>
                            <th scope="col" class="ss-table__num">{{ $ssValueLabel }}</th>
                            @if ($ssHasB)
                                <th scope="col" class="ss-table__num">{{ $ssValueLabelB }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ssLabels as $i => $l)
                            <tr>
                                <th scope="row">{{ $l }}</th>
                                <td class="ss-table__num ss-num">{{ $ssFormat($ssValues[$i], $ssDecimals) }}</td>
                                @if ($ssHasB)
                                    <td class="ss-table__num ss-num">{{ $ssFormat($ssValuesB[$i], $ssDecimalsB) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">{{ __('Total') }}</th>
                            <td class="ss-table__num ss-num">{{ $ssFormat($ssTotal, $ssDecimals) }}</td>
                            @if ($ssHasB)
                                <td class="ss-table__num ss-num">{{ $ssFormat($ssTotalB, $ssDecimalsB) }}</td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>
        </details>
    @endif
</div>
