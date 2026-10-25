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

    @param array  $labels  one per column, already formatted and translated
    @param array  $values  one per column, integers
    @param string $title   what the series measures
    @param string $period  REQUIRED: the span the series covers
--}}
@props([
    'labels' => [],
    'values' => [],
    'title',
    'period',
    'valueLabel' => null,
])

@php
    /* Pair up defensively. The controller builds label[] and data[] in one
       loop so they match today, but a chart that silently drops the last day
       because one array is shorter is worse than one that renders fewer
       columns honestly. */
    $ssCount  = min(count($labels), count($values));
    $ssLabels = array_slice(array_values($labels), 0, $ssCount);
    $ssValues = array_map('intval', array_slice(array_values($values), 0, $ssCount));
    $ssMax    = $ssCount ? max($ssValues) : 0;
    $ssTotal  = array_sum($ssValues);
    $ssValueLabel = $valueLabel ?: $title;
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
                       which is what `max(1, ...)` would have done. */
                    $ssPct = $ssMax > 0 ? round($v / $ssMax * 100) : 0;
                @endphp
                <div class="ss-chart__col">
                    <div class="ss-chart__bar @if ($v === 0) ss-chart__bar--zero @endif"
                         style="block-size: {{ $ssPct }}%"></div>
                </div>
            @endforeach
        </div>
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
                            <th scope="col">{{ __('Day') }}</th>
                            <th scope="col" class="ss-table__num">{{ $ssValueLabel }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ssLabels as $i => $l)
                            <tr>
                                <th scope="row">{{ $l }}</th>
                                <td class="ss-table__num ss-num">{{ number_format($ssValues[$i]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <th scope="row">{{ __('Total') }}</th>
                            <td class="ss-table__num ss-num">{{ number_format($ssTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </details>
    @endif
</div>
