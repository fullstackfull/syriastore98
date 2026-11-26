{{--
    GAP-195 / OPS-002 — THE PLATFORM OPERATOR'S RESOURCE MEASUREMENTS.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape
    because the failure is otherwise invisible.

    NOTHING HERE MEASURES, FORMATS, ROUNDS OR JUDGES. Every figure, period, hint, source and
    unavailability sentence comes from `SaasPlatformResources`. In particular this file must never
    be "improved" with a colour, a badge or a threshold: a red disk figure would be this platform
    stating an operational policy that nobody has set, and OPS-003 — incidents, alerts and
    diagnosis — is a separate recovered requirement that is not started. Both halves are pinned:
    the producer may carry no verdict key, and the measurement block below may contain no
    `text-danger`/`bg-warning`-style judgement.

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no build, so a
    utility class written here would simply not paint.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`, which `layouts/app.blade.php:63-66` forces to rtl for an `ar` or `he` principal. There is
    no hard-coded direction and no directional margin in this file, so it mirrors with the page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Platform resources') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Platform resources') }}
    </li>
@endsection

@section('content')
@php
    /*
     * ONE SENTENCE PER FIGURE, assembled in the order a reader needs it: what went wrong (only
     * when something did), what the figure means, and what measured it. The pieces are separate
     * in the producer because the tests assert them separately — a reason must exist for every
     * withheld figure — and are joined here because `x-ss.stat` has one visible hint slot.
     */
    $hintOf = function (array $row): string {
        $parts = [];

        if ($row['reason'] !== null) {
            $parts[] = $row['reason'];
        }

        $parts[] = $row['hint'];
        $parts[] = __('Measured with :source.', ['source' => $row['source']]);

        return implode(' ', $parts);
    };
@endphp

<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('What this machine reports about itself, read at the moment this page was built. Nothing here is cached, and nothing here is judged: the figures are stated and no threshold is applied to them.') }}
        </p>

        <p class="ss-muted">
            @if ($measured['context']['hostname'] !== null)
                {{ __('Measured on :host at :time.', [
                    'host' => $measured['context']['hostname'],
                    'time' => $measured['context']['measured_at']->toDateTimeString(),
                ]) }}
            @else
                {{ __('Measured at :time.', ['time' => $measured['context']['measured_at']->toDateTimeString()]) }}
            @endif
        </p>
    </div>
</div>

<div class="ss-resource-measurements">
    {{-- PROCESSOR. The three load averages and the number they have to be read against. --}}
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h5 mb-3">{{ __('Processor') }}</h2>

            <x-ss.stats :label="__('Processor')">
                @foreach ($measured['processor'] as $row)
                    <x-ss.stat :label="$row['label']"
                               :value="$row['value']"
                               :period="$row['period']"
                               :hint="$hintOf($row)" />
                @endforeach
            </x-ss.stats>
        </div>
    </div>

    {{--
        MEMORY. A TABLE AND NOT TILES, because two of these rows are the machine's and three are
        this one request's, and a grid of equal tiles invites exactly the misreading the hints
        exist to prevent. In a table the reader sees the measurement, the figure and the span
        side by side, and the `Scope`-less rows cannot be skimmed into one another.
    --}}
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h5 mb-3">{{ __('Memory') }}</h2>

            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('Memory measurements. The first rows describe the machine; the rows naming this request describe only the process that built this page.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Measurement') }}</th>
                            <th scope="col">{{ __('Figure') }}</th>
                            <th scope="col">{{ __('Period') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($measured['memory'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>
                                    @if ($row['value'] === null)
                                        {{ __('Not available') }}
                                    @else
                                        {{ $row['value'] }}
                                    @endif
                                </td>
                                <td>{{ $row['period'] }}</td>
                            </tr>
                            <tr>
                                <td colspan="3" class="ss-stat__hint">{{ $hintOf($row) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{--
        DISK, ONE TABLE PER PATH, each naming its own path. The path is printed because it is what
        makes the figure checkable by hand, and because the two paths are frequently the same
        filesystem and sometimes not — which is why both are measured and neither is assumed.
    --}}
    @foreach ($measured['disks'] as $disk)
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="h5 mb-1">{{ $disk['label'] }}</h2>

                {{-- GAP-179 / P06-06. `.ss-code` AND NOT A BARE `<code>`, measured rather than
                     preferred: Bootstrap paints an unclassed `<code>` at #e83e8c, which on this
                     screen measures 3.65:1 in the light admin and 4.05:1 in the dark one. Both
                     fail 4.5:1, and the dark figure failing by LESS is why it lasted -- it reads
                     as merely dim. `.ss-code` takes `--ss-fg` over `--ss-surface-sunken`, a pair
                     that re-themes, so it is correct in both without a second measurement. --}}
                <p class="ss-muted mb-3">{{ __('Path') }}: <code class="ss-code">{{ $disk['path'] }}</code></p>

                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">
                            {{ __('The filesystem holding :path.', ['path' => $disk['path']]) }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Measurement') }}</th>
                                <th scope="col">{{ __('Figure') }}</th>
                                <th scope="col">{{ __('Period') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($disk['rows'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['label'] }}</th>
                                    <td>
                                        @if ($row['value'] === null)
                                            {{ __('Not available') }}
                                        @else
                                            {{ $row['value'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['period'] }}</td>
                                </tr>
                                <tr>
                                    <td colspan="3" class="ss-stat__hint">{{ $hintOf($row) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>
<!-- ss-resource-measurements-end -->

{{--
    WHAT THIS SCREEN DOES NOT MEASURE, said on the screen itself rather than only in a document.
    OPS-001 (queues, databases, integrations) and OPS-003 (incidents and alerts) are separate
    recovered requirements and are not started; an operator who cannot see that stated here would
    reasonably read this page as the whole of platform monitoring.
--}}
<div class="row">
    <div class="col-12">
        <p class="ss-stat__hint">
            {{ __('This screen measures the machine only. Queue depth, database size and integration reachability are not measured here, and no alerting is configured anywhere in this product.') }}
        </p>
    </div>
</div>
@endsection
