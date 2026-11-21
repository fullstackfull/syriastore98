{{--
    THE MERCHANT ANALYTICS SCREEN. P5-4 / ANA-001…ANA-004.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    EVERY TILE'S TEXT COMES FROM THE FIGURES CLASS, not from this file. The label is the only string
    chosen here; the period and the caveat are computed beside the number they qualify, so a figure
    and its own limits cannot drift apart in a later edit of one file. `value` of null renders as
    "Not available" through the component — never as a zero, which would be a different claim.

    NOTHING HERE COMPUTES, FORMATS OR ROUNDS. If a number needs working out, it belongs in
    App\Support\Metrics\MerchantAnalyticsFigures where a test can see it; a Blade that does
    arithmetic is a figure no test covers.

    BILINGUAL BY CONSTRUCTION: every string is `__()`, and direction comes from the layout's own
    `dir` (the admin side follows the locale, not a SITE_RTL setting). There is no hard-coded ltr/rtl
    and no directional margin in this file, so the grid mirrors with the page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Analytics') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Analytics') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('How the store has been doing. Every figure states the period it covers and what it leaves out.') }}
        </p>
    </div>
</div>

{{-- ACTIVITY. Orders and page views are counts of rows, so they are always available; conversion is
     a ratio and is "Not available" whenever there is nothing to divide by. --}}
<div class="row mb-4">
    <div class="col-12">
        <x-ss.stats :label="__('Store activity')">
            <x-ss.stat :label="__('Orders')"
                       :value="$figures['orders']['value']"
                       :period="$figures['orders']['period']"
                       :hint="$figures['orders']['hint']"
                       :comparison="$figures['orders']['comparison']" />

            <x-ss.stat :label="__('Page views')"
                       :value="$figures['visits']['value']"
                       :period="$figures['visits']['period']"
                       :hint="$figures['visits']['hint']"
                       :comparison="$figures['visits']['comparison']" />

            <x-ss.stat :label="__('Conversion')"
                       :value="$figures['conversion']['value']"
                       :period="$figures['conversion']['period']"
                       :hint="$figures['conversion']['hint']" />

            <x-ss.stat :label="__('Buyers')"
                       :value="$figures['customers']['value']"
                       :period="$figures['customers']['period']"
                       :hint="$figures['customers']['hint']" />

            {{-- P5-6. Its PERIOD differs from its neighbours' thirty days on purpose: a repeat
                 purchase needs two orders, so a thirty-day window would report every store as less
                 loyal than it is. The tile states the period it really covers, which is the span the
                 financial record can vouch for (owner decision D1). --}}
            <x-ss.stat :label="__('Repeat purchase')"
                       :value="$figures['retention']['value']"
                       :period="$figures['retention']['period']"
                       :hint="$figures['retention']['hint']" />
        </x-ss.stats>
    </div>
</div>

{{-- ANA-004. WHERE THE VISITS CAME FROM — two breakdowns, and neither invents a category.

     These are TABLES rather than tiles because a breakdown is a set of rows and a tile is one
     number; the platform screen made the same choice for its per-currency amounts. Nothing here
     computes, folds, orders or totals: MerchantAnalyticsFigures did all of it, including the
     decision to order by count rather than by label and to name an absence an absence.

     THE TWO DISCLOSURES ARE THE POINT OF THE SECTION. The device list says a visit is a REQUEST
     and not a person, and that an undetermined device is shown as not recorded rather than
     guessed. The referrer list says that no host is filed under a marketing channel — that would
     need a maintained list of search and social domains — and that a visit with no referring page
     is NOT a direct visit, which is the reading a merchant will otherwise make. --}}
<div class="row">
    @foreach ([
        'devices' => ['heading' => __('Devices'), 'column' => __('Device')],
        'channels' => ['heading' => __('Referring pages'), 'column' => __('Referring page')],
    ] as $key => $meta)
        <div class="col-xl-6">
            <div class="ss-card mt-3">
                <h2 class="h5 mb-1">{{ $meta['heading'] }}</h2>

                <p class="ss-muted mb-3">
                    {{ __('Period') }}: {{ $figures[$key]['period'] }}
                </p>

                @if ($figures[$key]['rows'] === [])
                    <p>{{ __('Not available') }}</p>
                @else
                    <div class="table-responsive">
                        <table class="table">
                            <caption class="ss-visually-hidden">
                                {{ $meta['heading'] }} — {{ $figures[$key]['period'] }}
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ $meta['column'] }}</th>
                                    <th scope="col">{{ __('Visits') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($figures[$key]['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="ss-stat__hint">{{ $figures[$key]['hint'] }}</p>
            </div>
        </div>
    @endforeach
</div>

{{-- MONEY. The same two tiles as `/finance`, from the same FinanceLedgerFigures, so the two screens
     cannot disagree about one number — which is the defect this reuse exists to prevent. Their
     period is the ledger's own coverage (store_order_financials has no backfill), not the 30-day
     window above, and each tile says so itself. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Money') }}</h2>

        <x-ss.stats :label="__('Financial ledger')">
            <x-ss.stat :label="__('Payments received')"
                       :value="$figures['payments']['value']"
                       :period="$figures['payments']['period']"
                       :hint="$figures['payments']['hint']" />

            <x-ss.stat :label="__('Estimated gross profit')"
                       :value="$figures['profit']['value']"
                       :period="$figures['profit']['period']"
                       :hint="$figures['profit']['hint']" />

            {{-- P5-5 / section 9. Shown only for a period whose redemption records are complete; for
                 any earlier period the figures class returns a null value and says why, because a
                 number with "this cannot be trusted" under it gets read as a number. --}}
            <x-ss.stat :label="__('Coupon discounts given')"
                       :value="$figures['redemptions']['value']"
                       :period="$figures['redemptions']['period']"
                       :hint="$figures['redemptions']['hint']" />
        </x-ss.stats>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('These figures are read from this store\'s own records and are never shared between stores.') }}
        </p>
    </div>
</div>
{{-- P5-8. The export is reachable from the screen it exports, and carries the same gate. The
     description below is not decoration: it is the one place a merchant learns that the file keeps
     the disclosures, which is why the file is worth downloading instead of copying the tiles. --}}
<div class="row mb-4">
    <div class="col-12">
        <a class="btn btn-sm btn-outline-primary" href="{{ route('analytics.export') }}">
            {{ __('Download as CSV') }}
        </a>
        <p class="ss-stat__hint mt-2">
            {{ __('Download these figures as a spreadsheet. Every row carries its own period and what it leaves out, so a number cannot be read apart from its limits.') }}
        </p>
    </div>
</div>
@endsection
