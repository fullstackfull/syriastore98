{{--
    M5 — FREQUENTLY ASKED QUESTIONS.

    Native <details>/<summary>, so the accordion needs no script and the expanded state, the
    keyboard behaviour and the screen-reader announcement are the browser's rather than ARIA this
    page would have to get right on its own.

    Every answer is one the product can stand behind. Where the honest answer is "that depends on
    your plan" or "that is not published yet", it says so.

    GAP-104 (P03-C-05): THE TEN PAIRS ARE NO LONGER WRITTEN HERE. They moved into
    App\Support\Platform\PlatformContent, which resolves each question and each answer to the
    operator's wording where one has been supplied and to the same literal this file used to carry
    where none has — so an un-edited page renders byte for byte what it rendered before, in both
    languages, and an FAQ answer can be changed without a release. The array was removed rather than
    left beside the registry: two copies of the shipped copy is the drift this project keeps paying
    for.
--}}
@extends('platform.layout')

@section('title', __('FAQ'))
@section('meta_description', __('Common questions about Store Syria: plans, stores, themes, languages, data and support.'))


@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ $c['faq.hero.eyebrow'] }}</span>
                <h1 class="ss-ps-hero__title">{{ $c['faq.hero.title'] }}</h1>
                <p class="ss-ps-lede">{{ $c['faq.hero.lede'] }}</p>
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
