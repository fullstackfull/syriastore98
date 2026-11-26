{{--
    M5 — PRICING, FROM THE TABLE THE SUBSCRIPTION FLOW CHARGES AGAINST.

    THERE IS NO PRICE IN THIS FILE. Every figure comes from a `plans` row, and every limit beside
    it comes from `PlatformCapabilities::planHighlights()`, which reads the same row's columns. An
    installation with no plans renders a sentence saying so rather than an illustrative figure —
    `PlatformPricingTest` drives that case, and it drives the opposite one by changing a plan's
    price and asserting the page follows.

    `-1` IS THE SENTINEL FOR UNLIMITED, not `0`; the registry documents where that was measured.
    A plan whose limit column is the shipped `0` default really does permit zero, and this page
    prints the zero.
--}}
@extends('platform.layout')

@section('title', __('Pricing'))
@section('meta_description', __('Store Syria subscription plans, with the limits each one carries.'))

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ $c['pricing.hero.eyebrow'] }}</span>
                <h1 class="ss-ps-hero__title">{{ $c['pricing.hero.title'] }}</h1>
                <p class="ss-ps-lede">{{ $c['pricing.hero.lede'] }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            @if ($plans->isEmpty())
                <p class="ss-ps-note">{{ __('No subscription plans have been published yet. Plans and their prices will appear here as soon as they are available.') }}</p>
            @else
                <div class="ss-ps-plans">
                    @foreach ($plans as $plan)
                        @php $highlights = \App\Support\Platform\PlatformCapabilities::planHighlights($plan); @endphp
                        <article class="ss-ps-plan">
                            <h2 class="ss-ps-plan__name">{{ $plan->name }}</h2>

                            <p class="ss-ps-plan__price">
                                <span class="ss-ps-plan__amount">{{ default_currency_format_with_sym((float) $plan->price) }}</span>
                                @if (! empty($plan->duration))
                                    <span class="ss-ps-plan__period">{{ __(\App\Models\Plan::$arrDuration[$plan->duration] ?? $plan->duration) }}</span>
                                @endif
                            </p>

                            @if (! empty($plan->description))
                                <p class="ss-ps-body">{{ $plan->description }}</p>
                            @endif

                            @if ((int) $plan->trial === 1 && (int) $plan->trial_days > 0)
                                <p class="ss-ps-tag">{{ __('Free trial') }}: {{ $plan->trial_days }} {{ __('days') }}</p>
                            @endif

                            <ul class="ss-ps-plan__list">
                                @foreach ($highlights as $row)
                                    <li class="ss-ps-plan__item">
                                        @if ($row['state'] === 'yes')
                                            <span class="ss-ps-plan__state ss-ps-plan__state--yes" aria-hidden="true">&check;</span>
                                            <span>{{ __($row['label']) }}<span class="ss-visually-hidden"> — {{ __('included') }}</span></span>
                                        @elseif ($row['state'] === 'no')
                                            <span class="ss-ps-plan__state ss-ps-plan__state--no" aria-hidden="true">&times;</span>
                                            <span>{{ __($row['label']) }}<span class="ss-visually-hidden"> — {{ __('not included') }}</span></span>
                                        @elseif ($row['state'] === 'value')
                                            <span class="ss-ps-plan__state" aria-hidden="true">&bull;</span>
                                            <span>{{ __($row['label']) }}: <strong>{{ $row['value'] }}</strong></span>
                                        @else
                                            {{-- `null` is NOT `off`. A column that was never configured says so, because
                                                 "not set" and "not included" are different answers to a buyer. --}}
                                            <span class="ss-ps-plan__state ss-ps-plan__state--unknown" aria-hidden="true">?</span>
                                            <span>{{ __($row['label']) }}: {{ __('not specified') }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>

                            <a class="ss-ps-btn ss-ps-btn--primary" href="{{ url('login') }}">{{ __('Start with this plan') }}</a>
                        </article>
                    @endforeach
                </div>

                <p class="ss-ps-note ss-ps-after">{{ $c['pricing.note'] }}</p>
            @endif

        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-section__head">
                <h2 class="ss-ps-h2">{{ __('Questions about a plan') }}</h2>
                <p class="ss-ps-body">{{ __('If what you need is not listed, ask before you subscribe rather than after.') }}</p>
            </div>
            <div class="ss-ps-actions">
                <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ route('platform.faq') }}">{{ __('Read the FAQ') }}</a>
                <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ route('platform.contact') }}">{{ __('Contact us') }}</a>
            </div>
        </div>
    </section>

@endsection
