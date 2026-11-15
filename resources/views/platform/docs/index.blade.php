@extends('platform.layout')

@section('title', __('Documentation'))
@section('meta_description', __('How to use Store Syria: opening a shop, themes, products, orders and your account.'))

@section('content')

    <section class="ss-ps-hero">
        <div class="ss-ps-wrap">
            <div class="ss-ps-hero__inner">
                <span class="ss-ps-eyebrow">{{ __('Documentation') }}</span>
                <h1 class="ss-ps-hero__title">{{ __('How to use Store Syria') }}</h1>
                <p class="ss-ps-lede">{{ __('Written for merchants, not for developers. Start at the beginning or search for what you are stuck on.') }}</p>
                @include('platform.docs._search-form', ['query' => ''])
            </div>
        </div>
    </section>

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">
            @if (count($categories) === 0)
                <p class="ss-ps-note">{{ __('No documentation has been published yet.') }}</p>
            @else
                <div class="ss-ps-grid ss-ps-grid--2">
                    @foreach ($categories as $cat)
                        <section class="ss-ps-card">
                            <h2 class="ss-ps-h3">
                                <a class="ss-ps-link-plain" href="{{ route('platform.docs.category', $cat['slug']) }}">{{ $cat['title'] }}</a>
                            </h2>
                            @if ($cat['summary'] !== '')
                                <p class="ss-ps-body">{{ $cat['summary'] }}</p>
                            @endif
                            <ul class="ss-ps-doclist">
                                @foreach ($cat['articles'] as $entry)
                                    <li>
                                        <a href="{{ route('platform.docs.article', [$cat['slug'], $entry['slug']]) }}">{{ $entry['title'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

@endsection
