{{--
    M5 — FREQUENTLY ASKED QUESTIONS.

    Native <details>/<summary>, so the accordion needs no script and the expanded state, the
    keyboard behaviour and the screen-reader announcement are the browser's rather than ARIA this
    page would have to get right on its own.

    Every answer is one the product can stand behind. Where the honest answer is "that depends on
    your plan" or "that is not published yet", it says so.
--}}
@extends('platform.layout')

@section('title', __('FAQ'))
@section('meta_description', __('Common questions about Store Syria: plans, stores, themes, languages, data and support.'))

@php
    $faqs = [
        [
            'q' => __('What is Store Syria?'),
            'a' => __('A platform for running an online shop. You get a public storefront with its own address and theme, a dashboard for products, orders and customers, and an admin app for the phone — all on one account.'),
        ],
        [
            'q' => __('Do I need to know how to build a website?'),
            'a' => __('No. You choose a theme and change its sections, text, images and colours from the dashboard. Nothing needs to be installed or written.'),
        ],
        [
            'q' => __('Can I run more than one shop?'),
            'a' => __('Yes, if your plan allows it. Each store keeps its own products, orders, customers and theme, and you switch between them from the same sign-in. The number of stores a plan permits is shown on the pricing page.'),
        ],
        [
            'q' => __('Is the platform available in Arabic?'),
            'a' => __('Yes. Both the shop and the dashboard are available in Arabic and in English, and the whole layout flips to right-to-left in Arabic. It is the same installation in either language, not two separate ones.'),
        ],
        [
            'q' => __('Will my changes to a theme appear immediately?'),
            'a' => __('Only when you publish them. Editing a theme saves a draft that nobody but you can see; you preview it privately, publish when you are ready, and an earlier version can be restored afterwards.'),
        ],
        [
            'q' => __('Can my staff have their own accounts?'),
            'a' => __('Yes. You create roles, decide what each role may see and change, and give each colleague their own sign-in rather than sharing yours.'),
        ],
        [
            'q' => __('Can another merchant on the platform see my products or orders?'),
            'a' => __('No. Each store\'s catalogue, orders and customers are reachable only from that store.'),
        ],
        [
            'q' => __('What happens to my data if I stop subscribing?'),
            'a' => __('The terms that govern this are being prepared and are not published yet. Until they are, ask us directly rather than relying on an answer here.'),
        ],
        [
            'q' => __('Which payment methods can my shop accept?'),
            'a' => __('That depends on the payment providers configured for your installation and on your plan. Ask before you subscribe if a particular method matters to you.'),
        ],
        [
            'q' => __('How do I get help?'),
            'a' => __('Through the contact page, and from inside the dashboard once you are signed in.'),
        ],
    ];
@endphp

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ __('FAQ') }}</span>
                <h1 class="ss-ps-hero__title">{{ __('Questions people ask') }}</h1>
                <p class="ss-ps-lede">{{ __('If your question is not here, the contact page will reach us.') }}</p>
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">
            <div class="ss-ps-faq">
                @foreach ($faqs as $i => $faq)
                    <details class="ss-ps-faq__item" @if ($i === 0) open @endif>
                        <summary class="ss-ps-faq__q">
                            <span>{{ $faq['q'] }}</span>
                            <span class="ss-ps-faq__sign" aria-hidden="true">+</span>
                        </summary>
                        <p class="ss-ps-faq__a">{{ $faq['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
    </section>

    <section class="ss-ps-section">
        <div class="ss-ps-wrap">
            <div class="ss-ps-actions">
                <a class="ss-ps-btn ss-ps-btn--primary" href="{{ route('platform.contact') }}">{{ __('Ask a question') }}</a>
                <a class="ss-ps-btn ss-ps-btn--quiet" href="{{ route('platform.pricing') }}">{{ __('See plans and prices') }}</a>
            </div>
        </div>
    </section>

@endsection
