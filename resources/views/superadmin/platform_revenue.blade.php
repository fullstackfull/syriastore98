{{--
    GAP-192 / SAAS-007 — THE PLATFORM'S OWN REVENUE, KEPT APART FROM MERCHANT SALES.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape.

    THE SEPARATION IS THE REQUIREMENT, so this screen says what it is NOT twice: once at the top,
    where it names the other screen that holds merchant sales, and once beside the receipts table,
    where an operator might otherwise read a plan payment as a shopper's payment. The two must
    never be added, and neither must the per-currency rows be added to each other.

    NOTHING HERE COMPUTES, FORMATS OR ROUNDS. Every figure, period, hint and bucket count comes
    from `SaasRevenueFigures`. In particular the per-currency receipt rows must never be totalled:
    converting between them needs the exchange rate on each payment's own date, which this schema
    does not record anywhere.

    NOT TAILWIND. The merchant admin is Blade plus the vendored Bootstrap 5 WorkDo theme plus
    `public/css/store-syria-ds.css`; `@vite` appears in zero views and there is no build.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`. There is no hard-coded direction and no directional margin in this file.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Platform revenue') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Platform revenue') }}
    </li>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-12">
        <p class="ss-muted">
            {{ __('What merchants pay Store Syria. This is NOT what shoppers pay merchants: merchant sales and gross merchandise value are a different kind of money, in different currencies, and are reported separately on the platform analytics screen.') }}
        </p>

        <p class="ss-muted">
            <a class="ss-breadcrumb__link" href="{{ route('platform.analytics') }}">
                {{ __('Merchant sales across all stores') }}
            </a>
        </p>
    </div>
</div>

{{-- RECURRING REVENUE. The two figures the recovered requirement names by abbreviation. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Recurring revenue') }}</h2>

        <x-ss.stats :label="__('Recurring revenue')">
            <x-ss.stat :label="__('MRR (monthly recurring revenue)')"
                       :value="$figures['recurring']['mrr']['value']"
                       :period="$figures['recurring']['mrr']['period']"
                       :hint="$figures['recurring']['mrr']['hint']" />

            <x-ss.stat :label="__('ARR (annual recurring revenue)')"
                       :value="$figures['recurring']['arr']['value']"
                       :period="$figures['recurring']['arr']['period']"
                       :hint="$figures['recurring']['arr']['hint']" />

            <x-ss.stat :label="__('Paying merchants')"
                       :value="$figures['recurring']['subscribers']['value']"
                       :period="$figures['recurring']['subscribers']['period']"
                       :hint="$figures['recurring']['subscribers']['hint']" />
        </x-ss.stats>

        <p class="ss-stat__hint">
            {{ __('Plan prices carry no currency of their own in this product: `plans.price` is a bare number, so these figures are denominated in the platform currency setting, shown as :currency.', ['currency' => $figures['currency']]) }}
        </p>

        @if ($figures['disclosures']['inactive_contributors'] > 0)
            <p class="ss-stat__hint">
                {{ __('Of the subscriptions counted above, :count belong to merchant accounts that are currently switched off. They are counted because the subscription record is what this product bills on; whether a disabled account should still be charged is not a question this screen answers.', ['count' => $figures['disclosures']['inactive_contributors']]) }}
            </p>
        @endif
    </div>
</div>

{{--
    THE POPULATION THE FIGURE WAS TAKEN FROM. Every subscription that is not a live recurring one,
    counted by name — so an owner can see what a different rule would move instead of having to
    trust a SUM. This table is the whole of this slice's refusal to invent a commercial policy.
--}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Every subscription, by kind') }}</h2>

        <p class="ss-muted mb-3">
            {{ __('The first two rows are the ones in the figures above. The rest are not, and each row says why.') }}
        </p>

        <div class="table-responsive">
            <table class="table">
                <caption class="ss-visually-hidden">
                    {{ __('Merchant subscriptions grouped by kind, with the reason each kind is or is not part of recurring revenue.') }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Kind') }}</th>
                        <th scope="col">{{ __('Merchants') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($figures['buckets'] as $bucket)
                        <tr>
                            <th scope="row">{{ $bucket['label'] }}</th>
                            <td>{{ $bucket['count'] }}</td>
                        </tr>
                        <tr>
                            <td colspan="2" class="ss-stat__hint">{{ $bucket['hint'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{--
    WHAT WAS ACTUALLY COLLECTED, per currency, in two windows. A TABLE and not a tile because the
    number of currencies is however many the platform has charged in, and because there is
    deliberately no total.
--}}
@foreach (['all_time' => __('Plan payments received, all time'), 'recent' => __('Plan payments received, recently')] as $window => $heading)
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="h5 mb-1">{{ $heading }}</h2>

            <p class="ss-muted mb-3">
                {{ __('Period') }}: {{ $figures['receipts'][$window]['period'] }}
            </p>

            @if ($figures['receipts'][$window]['rows'] === [])
                <p>{{ __('Not available') }}</p>
            @else
                <div class="table-responsive">
                    <table class="table">
                        <caption class="ss-visually-hidden">
                            {{ __('Plan payments received, one row per currency. The rows are not added together.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Currency') }}</th>
                                <th scope="col">{{ __('Received') }}</th>
                                <th scope="col">{{ __('Payments') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($figures['receipts'][$window]['rows'] as $row)
                                <tr>
                                    <th scope="row">{{ $row['currency'] }}</th>
                                    <td>{{ $row['amount'] }}</td>
                                    <td>{{ $row['orders'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <p class="ss-stat__hint">{{ $figures['receipts'][$window]['hint'] }}</p>
        </div>
    </div>
@endforeach

{{-- WHAT THE RECEIPTS FIGURE LEAVES OUT, counted rather than implied. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Payments the figures above leave out') }}</h2>

        <div class="table-responsive">
            <table class="table">
                <caption class="ss-visually-hidden">
                    {{ __('Plan payments excluded from the received figures, with the reason for each.') }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ __('Excluded') }}</th>
                        <th scope="col">{{ __('Payments') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">{{ __('Refunded') }}</th>
                        <td>{{ $figures['receipts']['excluded']['refunded'] }}</td>
                    </tr>
                    <tr>
                        <td colspan="2" class="ss-stat__hint">
                            {{ __('Marked refunded, so the platform gave the money back. Counting these would overstate what it kept.') }}
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">{{ __('Not settled') }}</th>
                        <td>{{ $figures['receipts']['excluded']['unsettled'] }}</td>
                    </tr>
                    <tr>
                        <td colspan="2" class="ss-stat__hint">
                            {{ __('Pending, rejected or failed. The payment status column is free text written by about fifty gateway handlers in several spellings, so the settled set is re-derived from those files by a test rather than assumed.') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
