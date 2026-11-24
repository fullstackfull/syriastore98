{{--
    THE PLATFORM OPERATOR'S CROSS-TENANT ANALYTICS SCREEN. P5-7 / ANA-001.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    EVERY FIGURE AND EVERY CAVEAT COMES FROM PlatformAnalyticsFigures. Nothing here computes,
    formats, rounds or sums. In particular the per-currency rows below are NOT added together, and
    this file must never be "improved" by totalling them: the amounts are in different currencies
    and converting them would need the exchange rate on each order's own date, which this schema does
    not record. The figures class says so in its own hint, beside the number.

    BILINGUAL BY CONSTRUCTION: every string is `__()`, and direction comes from the layout's own
    `dir`. There is no hard-coded ltr/rtl and no directional margin in this file, so the layout
    mirrors with the page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Platform analytics') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Platform analytics') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('Every store on the platform, together. Each figure states the period it covers and what it leaves out.') }}
        </p>
    </div>
</div>

{{-- COUNTS. These need neither a currency nor the financial record, so they are always available. --}}
<div class="row mb-4">
    <div class="col-12">
        <x-ss.stats :label="__('Across all stores')">
            <x-ss.stat :label="__('Stores')"
                       :value="$figures['stores']['value']"
                       :period="$figures['stores']['period']"
                       :hint="$figures['stores']['hint']" />

            <x-ss.stat :label="__('Orders')"
                       :value="$figures['orders']['value']"
                       :period="$figures['orders']['period']"
                       :hint="$figures['orders']['hint']" />

            <x-ss.stat :label="__('Buyers')"
                       :value="$figures['buyers']['value']"
                       :period="$figures['buyers']['period']"
                       :hint="$figures['buyers']['hint']" />

            {{-- P5-R13. The first of the nine figures the merchant screen had and this one did not.
                 It sits beside Buyers because it is the same population seen twice -- how many can
                 be recognised again, and how many of those came back -- and its period is the
                 LEDGER's intersection rather than the recent window the two tiles above use, which
                 is why the tile states its own. --}}
            <x-ss.stat :label="__('Repeat purchase')"
                       :value="$figures['retention']['value']"
                       :period="$figures['retention']['period']"
                       :hint="$figures['retention']['hint']" />
        </x-ss.stats>
    </div>
</div>

{{-- MONEY, PER CURRENCY. A table and not a tile because the number of currencies is however many
     the platform's stores use, and because there is deliberately no total: see the file header. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Payments collected') }}</h2>

        <p class="ss-muted mb-3">
            {{ __('Period') }}: {{ $figures['payments']['period'] }}
        </p>

        @if ($figures['payments']['rows'] === [])
            <p>{{ __('Not available') }}</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('Payments collected across all stores, one row per currency. The rows are not added together.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Currency') }}</th>
                            <th scope="col">{{ __('Collected') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($figures['payments']['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['currency'] }}</th>
                                <td>{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="ss-stat__hint">{{ $figures['payments']['hint'] }}</p>
    </div>
</div>

{{--
    DEVICES, ACROSS TENANTS -- GAP-162 / P05-14.

    A BREAKDOWN, so it is a table rather than an <x-ss.stat> tile, and it is modelled on the
    payments block above rather than on the merchant screen's markup: this page already had one
    rows-shaped figure and a second shape would be a second thing to keep accessible.

    The empty case prints "Not available" rather than an empty table, as payments does, because an
    empty <tbody> reads as a broken page and a table with no rows has nothing for a screen reader
    to announce.
--}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Devices') }}</h2>

        <p class="ss-muted mb-3">
            {{ __('Period') }}: {{ $figures['devices']['period'] }}
        </p>

        @if ($figures['devices']['rows'] === [])
            <p>{{ __('Not available') }}</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('Page views across all stores, one row per device. It counts requests rather than people.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Device') }}</th>
                            <th scope="col">{{ __('Page views') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($figures['devices']['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>{{ $row['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="ss-stat__hint">{{ $figures['devices']['hint'] }}</p>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('These figures cross store boundaries and are visible to the platform operator only.') }}
        </p>
    </div>
</div>
{{-- P5-8. The export is reachable from the screen it exports, and carries the same gate. The
     description below is not decoration: it is the one place a merchant learns that the file keeps
     the disclosures, which is why the file is worth downloading instead of copying the tiles. --}}
<div class="row mb-4">
    <div class="col-12">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('platform.analytics.export') }}">
            {{ __('Download as CSV') }}
        </a>
        <p class="ss-stat__hint mt-2">
            {{ __('Download these figures as a spreadsheet. Every row carries its own period and what it leaves out, so a number cannot be read apart from its limits.') }}
        </p>
    </div>
</div>
@endsection
