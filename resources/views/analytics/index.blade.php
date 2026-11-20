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
                       :hint="$figures['orders']['hint']" />

            <x-ss.stat :label="__('Page views')"
                       :value="$figures['visits']['value']"
                       :period="$figures['visits']['period']"
                       :hint="$figures['visits']['hint']" />

            <x-ss.stat :label="__('Conversion')"
                       :value="$figures['conversion']['value']"
                       :period="$figures['conversion']['period']"
                       :hint="$figures['conversion']['hint']" />

            <x-ss.stat :label="__('Buyers')"
                       :value="$figures['customers']['value']"
                       :period="$figures['customers']['period']"
                       :hint="$figures['customers']['hint']" />
        </x-ss.stats>
    </div>
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
@endsection
