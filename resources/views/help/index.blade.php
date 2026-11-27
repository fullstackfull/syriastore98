@extends('layouts.app')

@section('page-title')
    {{ __('Help') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Help') }}</li>
@endsection

@section('content')
    @include('layouts.includes.store-syria-ds')

    {{--
        PHASE 06 SUPPORT-002 -- «مساعدة سياقية»: THE HELP FOR THE SCREEN THE MERCHANT CAME FROM.

        THERE IS NO HELP PROSE IN THIS FILE. Every article title and summary comes out of
        `resources/docs/<locale>/`, the owner's bilingual documentation centre, through
        `App\Support\Help\ContextualHelp`. A second corpus of help text would be a second thing to
        keep bilingual and a second thing to review, and ARCH-002 forbids the parallel engine.

        THE LANGUAGE SUBSTITUTION IS PRINTED, NOT SWALLOWED. `is_fallback` is true when the centre
        served a language the reader did not ask for, and the notice beside the article is what stops
        a missing Arabic translation from being invisible to us as well as to them.

        NO DIRECTION IS PINNED: no `ml-`/`mr-`/`ps-`/`pe-`, no `text-left`, no `dir`. The layout
        already forces `dir="rtl"` for an Arabic principal and this mirrors with it.

        THE FULL MAP IS ALWAYS BELOW, so a merchant who arrived with no surface -- or with one this
        build does not know -- lands on something useful rather than an empty screen.

        THE SURFACE HEADINGS ARE LITERAL `__()` CALLS AND NOT `__('help.surface.' . $key)`.
        A concatenated key is invisible to a translation census -- which is how a key goes missing
        from `ar.json` and Laravel renders the KEY ITSELF on the screen, in both languages. One
        literal per surface, every one declared in both language files, and
        `ContextualHelpScreenTest` reads this file (comments stripped) and reddens if a surface in
        `ContextualHelp::SURFACES` has no heading here.
    --}}

    @php
        $surfaceLabels = [
            'overview' => __('Getting started'),
            'products' => __('Products'),
            'orders' => __('Orders'),
            'storefront' => __('Your storefront'),
            'staff' => __('Staff and permissions'),
            'plan' => __('Your plan'),
            'account' => __('Your account'),
        ];
    @endphp

    @if ($surface !== '' && ! $known)
        <div class="row">
            <div class="col-12">
                {{-- SAID OUT LOUD, because a server that quietly showed the full map would hide a
                     drift between the shipped app's surface keys and this build's. --}}
                <x-ss.card>
                    <p class="ss-muted mb-0">
                        {{ __('There is no help for that screen yet. Everything available is listed below.') }}
                    </p>
                </x-ss.card>
            </div>
        </div>
    @endif

    @if ($articles !== [])
        <div class="row">
            <div class="col-12">
                <x-ss.card>
                    <h2 class="h5 mb-1">{{ __('Help for this screen') }}</h2>

                    <ul class="ss-help-list list-unstyled mb-0 mt-3">
                        @foreach ($articles as $article)
                            <li class="mb-3">
                                <a href="{{ $article['url'] }}" class="ss-link">{{ $article['title'] }}</a>

                                @if ($article['summary'] !== '')
                                    <p class="ss-muted mb-0">{{ $article['summary'] }}</p>
                                @endif

                                @if ($article['is_fallback'])
                                    <p class="ss-field__hint mb-0">
                                        {{ __('This guide is not available in your language yet.') }}
                                    </p>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ss.card>
                {{-- A MARKER, BECAUSE THE HEADING IS NOT A USABLE ASSERTION TARGET. The header's
                     help control carries `aria-label="Help for this screen"` on EVERY merchant
                     page, so a page-wide search for that phrase is true whether or not this section
                     rendered -- CLAUDE.md's census trap, met here rather than read about. Two cases
                     in ContextualHelpScreenTest went red on exactly that and now key on this. --}}
                <!-- ss-help-context-end -->
            </div>
        </div>
    @endif

    <div class="row mt-4">
        <div class="col-12">
            <x-ss.card>
                <h2 class="h5 mb-1">{{ __('All guides') }}</h2>

                <p class="ss-muted">
                    {{ __('The full documentation centre, grouped by the part of your store it covers.') }}
                </p>

                @foreach ($vocabulary as $entry)
                    <h3 class="h6 mb-2 mt-3">{{ $surfaceLabels[$entry['surface']] ?? $entry['surface'] }}</h3>

                    <ul class="list-unstyled mb-0">
                        @foreach ($entry['articles'] as $article)
                            <li class="mb-2">
                                <a href="{{ $article['url'] }}" class="ss-link">{{ $article['title'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endforeach

                <p class="mt-4 mb-0">
                    <a href="{{ route('platform.docs.index') }}" class="ss-link">
                        {{ __('Open the documentation centre') }}
                    </a>
                </p>
            </x-ss.card>
        </div>
    </div>
    <!-- ss-help-screen-end -->
@endsection
