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

    GAP-104 (P03-C-05) -- THE OWNER CAN NOW PUBLISH EACH DOCUMENT WITHOUT A DEVELOPER, which is the
    knock-on that row singles out. Each of the five has an editable body in
    App\Support\Platform\PlatformContent, OWED: it ships with no wording at all. While a document's
    text is empty this page is exactly what it was -- the summary and the "not published yet" tag.
    When approved text is supplied it is published in its place and the tag goes. The summaries and
    the titles stay in code because they are structure, not terms.

    THE BODY IS RENDERED THROUGH THE DOCUMENTATION CENTRE'S MARKDOWN RENDERER, which is why the echo
    below is unescaped. DocumentationRenderer::OPTIONS sets html_input => strip and
    allow_unsafe_links => false, so raw markup in the source is removed rather than rendered and a
    javascript: link cannot survive. The operator supplies the words; this page decides the HTML.
--}}
@extends('platform.layout')

@section('title', __('Legal information'))
@section('meta_description', __('Store Syria legal documents: terms of service, privacy, acceptable use and refunds.'))

@php
    /* Titles and summaries are STRUCTURE and stay in code; the body is the owner's text. The id is
       the public fragment a link points at, so it must not change when a document is published. */
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

    /* THE ONE PLACE THIS PAGE LOOKS A CONTENT KEY UP, and it is here rather than in the loop below
       for a reason `PlatformContentRegistryTest::test_every_content_key_a_public_view_reads_is_declared`
       enforces: that census reads `$c['literal']` out of the view source, so a CONCATENATED key is
       invisible to it -- and an undefined key is an ErrorException, i.e. a 500 on a public page,
       not a blank. Resolving the five here, once, with an explicit fallback, keeps the loop's reads
       static and confines the dynamic lookup to a line that cannot throw.

       The count is taken rather than assumed, because the top-of-page notice has to be true of the
       page beneath it: "none of these has been published" printed above four published documents
       would be a false statement about binding terms. */
    $publishedCount = 0;
    foreach ($documents as $i => $doc) {
        $documents[$i]['text'] = $c['legal.' . $doc['id'] . '.text'] ?? '';

        if ($documents[$i]['text'] !== '') {
            $publishedCount++;
        }
    }
@endphp

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ $c['legal.hero.eyebrow'] }}</span>
                <h1 class="ss-ps-hero__title">{{ $c['legal.hero.title'] }}</h1>
                <p class="ss-ps-lede">{{ $c['legal.hero.lede'] }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            {{-- SAID ONCE, PLAINLY, AT THE TOP. A visitor should not have to open five sections to
                 discover that none of them is binding yet.

                 THREE STATES AND NOT TWO, which is the part that changed with GAP-104. While
                 nothing is published the original sentence is the whole truth. Once SOME documents
                 are published it is false, so it is replaced by one that names the mixture. Once
                 all five are published there is nothing to warn about and the notice goes. --}}
            @if ($publishedCount === 0)
                <p class="ss-ps-note">{{ __('None of these documents has been published yet. The summaries below describe what each one will cover; they are descriptions, not terms, and nothing on this page is binding on you or on us. If you need an answer now, ask us directly.') }}</p>
            @elseif ($publishedCount < count($documents))
                <p class="ss-ps-note">{{ __('Some of these documents have been published and some have not. A document marked as not published yet is a description of what it will cover, not terms, and nothing in it is binding on you or on us.') }}</p>
            @endif

            <div class="ss-ps-rows ss-ps-after">
                @foreach ($documents as $doc)
                    @php($text = $doc['text'])
                    <section class="ss-ps-row" id="{{ $doc['id'] }}">
                        <h2 class="ss-ps-h3">{{ $doc['title'] }}</h2>
                        @if ($text === '')
                            <p class="ss-ps-body">{{ $doc['body'] }}</p>
                            <p class="ss-ps-after"><span class="ss-ps-tag">{{ __('Not published yet') }}</span></p>
                        @else
                            <div class="ss-ps-prose">{!! \App\Support\Docs\DocumentationRenderer::html($text) !!}</div>
                        @endif
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
