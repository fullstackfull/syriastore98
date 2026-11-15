{{--
    M5 — WHAT THE PLATFORM DOES.

    Not one capability on this page is written here. Each comes from
    `App\Support\Platform\PlatformCapabilities`, which renders a module-backed claim only while
    that module ships in `packages/workdo/`. Delete the directory and the claim leaves the page —
    which is what `PlatformCapabilitiesTest` does, so the tie between this page and the product is
    measured rather than promised in a comment.
--}}
@extends('platform.layout')

@section('title', __('Features'))
@section('meta_description', __('What Store Syria does: storefront and themes, catalogue and orders, marketing, and the tools for running the business.'))

@php
    $headings = [
        'storefront' => [
            'title' => __('The shop your customers see'),
            'lede'  => __('The public side of a store: how it looks, what a shopper can do on it, and the languages it speaks.'),
        ],
        'commerce' => [
            'title' => __('Selling'),
            'lede'  => __('From a product in the catalogue to an order that has been paid for and sent.'),
        ],
        'growth' => [
            'title' => __('Reaching customers'),
            'lede'  => __('Bringing people to the shop, and giving them a reason to come back.'),
        ],
        'operations' => [
            'title' => __('Running the business'),
            'lede'  => __('The parts that are not the shop: your account, your colleagues, your data.'),
        ],
    ];
@endphp

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ __('Features') }}</span>
                <h1 class="ss-ps-hero__title">{{ __('What the platform does') }}</h1>
                <p class="ss-ps-lede">{{ __('Everything listed below is part of the platform. Which parts a particular account can switch on depends on its plan, so check the plan before you count on one of them.') }}</p>
            </div>
        </div>
    </section>

    @if (count($themes) > 0)
        <section class="ss-ps-section ss-ps-section--plain">
            <div class="ss-ps-wrap">
                <div class="ss-ps-section__head">
                    <h2 class="ss-ps-h2">{{ __('Storefront themes') }}</h2>
                    <p class="ss-ps-body">{{ __('These are the themes that ship with the platform today. A plan decides which of them an account may use.') }}</p>
                </div>
                <ul class="ss-ps-tags">
                    @foreach ($themes as $theme)
                        <li class="ss-ps-tag">{{ $theme }}</li>
                    @endforeach
                </ul>
            </div>
        </section>
    @endif

    @foreach ($groups as $name => $entries)
        @continue(count($entries) === 0)
        <section class="ss-ps-section">
            <div class="ss-ps-wrap">
                <div class="ss-ps-section__head">
                    <h2 class="ss-ps-h2">{{ $headings[$name]['title'] ?? $name }}</h2>
                    <p class="ss-ps-body">{{ $headings[$name]['lede'] ?? '' }}</p>
                </div>
                <div class="ss-ps-grid">
                    @foreach ($entries as $entry)
                        <article class="ss-ps-card">
                            <h3 class="ss-ps-h3">{{ __($entry['title']) }}</h3>
                            <p class="ss-ps-body">{{ __($entry['body']) }}</p>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-actions">
                <a class="ss-ps-btn ss-ps-btn--primary ss-ps-btn--lg" href="{{ route('platform.pricing') }}">{{ __('See plans and prices') }}</a>
                <a class="ss-ps-btn ss-ps-btn--quiet ss-ps-btn--lg" href="{{ route('platform.onboarding') }}">{{ __('How it works') }}</a>
            </div>
        </div>
    </section>

@endsection
