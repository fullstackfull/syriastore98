{{--
    M5 — LEGAL ENTRY POINTS.

    THE BINDING TEXT IS NOT WRITTEN HERE, AND THAT IS NOT AN OVERSIGHT. The owner's standing
    restrictions reserve "unapproved changes to legal or commercial policy". Terms of service, a
    privacy notice and a refund policy are promises a business is held to from the moment they are
    published; writing a plausible draft and shipping it would commit the owner to words nobody
    approved.

    So this page does the one thing engineering legitimately can: it gives each document a stable
    public address, states what it will cover, and says plainly that the approved text is not yet
    published. A visitor is told the truth, the navigation does not point at a dead end, and the
    owner can replace a section's body with approved text without any of the surrounding structure
    changing.

    Tracked as an owner item in the Phase 03 ledger.
--}}
@extends('platform.layout')

@section('title', __('Legal information'))
@section('meta_description', __('Store Syria legal documents: terms of service, privacy, acceptable use and refunds.'))

@php
    $documents = [
        [
            'id'    => 'terms',
            'title' => __('Terms of service'),
            'body'  => __('The agreement between a merchant and Store Syria: what the subscription entitles you to, what each side is responsible for, how a plan changes or ends, and what happens to a store afterwards.'),
        ],
        [
            'id'    => 'privacy',
            'title' => __('Privacy notice'),
            'body'  => __('What personal information the platform holds about merchants and about their customers, why it is held, who can reach it, and how long it is kept.'),
        ],
        [
            'id'    => 'acceptable-use',
            'title' => __('Acceptable use'),
            'body'  => __('What may and may not be sold or published through a store on this platform, and what happens when a store breaks those rules.'),
        ],
        [
            'id'    => 'refunds',
            'title' => __('Subscription, billing and refunds'),
            'body'  => __('How subscriptions are charged, what happens when a payment fails, and the circumstances in which a subscription charge is refunded.'),
        ],
        [
            'id'    => 'data-processing',
            'title' => __('Handling your customers\' data'),
            'body'  => __('A merchant\'s customers trust the merchant, and the platform holds that data on the merchant\'s behalf. This document sets out the terms of that arrangement.'),
        ],
    ];
@endphp

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ __('Legal') }}</span>
                <h1 class="ss-ps-hero__title">{{ __('Legal information') }}</h1>
                <p class="ss-ps-lede">{{ __('These are the documents that will govern using Store Syria.') }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            {{-- SAID ONCE, PLAINLY, AT THE TOP. A visitor should not have to open five sections to
                 discover that none of them is binding yet. --}}
            <p class="ss-ps-note">{{ __('None of these documents has been published yet. The summaries below describe what each one will cover; they are descriptions, not terms, and nothing on this page is binding on you or on us. If you need an answer now, ask us directly.') }}</p>

            <div class="ss-ps-rows ss-ps-after">
                @foreach ($documents as $doc)
                    <section class="ss-ps-row" id="{{ $doc['id'] }}">
                        <h2 class="ss-ps-h3">{{ $doc['title'] }}</h2>
                        <p class="ss-ps-body">{{ $doc['body'] }}</p>
                        <p class="ss-ps-after"><span class="ss-ps-tag">{{ __('Not published yet') }}</span></p>
                    </section>
                @endforeach
            </div>

        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-actions">
                <a class="ss-ps-btn ss-ps-btn--primary" href="{{ route('platform.contact') }}">{{ __('Ask about any of this') }}</a>
            </div>
        </div>
    </section>

@endsection
