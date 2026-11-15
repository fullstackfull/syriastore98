@extends('platform.layout')

@section('title', $article['title'])
@section('meta_description', $article['summary'] !== '' ? $article['summary'] : __('How to use Store Syria.'))

@section('content')

    <section class="ss-ps-section ss-ps-section--plain">
        <div class="ss-ps-wrap">

            <nav class="ss-ps-breadcrumb" aria-label="{{ __('Breadcrumb') }}">
                <ol>
                    <li><a href="{{ route('platform.docs.index') }}">{{ __('Documentation') }}</a></li>
                    @if ($category)
                        <li><a href="{{ route('platform.docs.category', $category['slug']) }}">{{ $category['title'] }}</a></li>
                    @endif
                    <li aria-current="page">{{ $article['title'] }}</li>
                </ol>
            </nav>

            <div class="ss-ps-docs">
                @include('platform.docs._aside', ['categories' => $categories, 'current' => $article['id']])

                <div class="ss-ps-docs__main">

                    @if ($isFallback)
                        {{-- SAID OUT LOUD. An article that exists in English and not yet in Arabic is
                             served in English rather than 404ing, and the reader is told why. A silent
                             swap would also hide the gap from us. --}}
                        <p class="ss-ps-note" lang="{{ $article['locale'] }}" dir="ltr">
                            {{ __('This page has not been translated yet, so it is shown in English.') }}
                        </p>
                    @endif

                    <h1 class="ss-ps-h2">{{ $article['title'] }}</h1>
                    @if ($article['summary'] !== '')
                        <p class="ss-ps-lede">{{ $article['summary'] }}</p>
                    @endif

                    @if (count($toc) > 1)
                        <nav class="ss-ps-toc" aria-labelledby="ss-ps-toc-title">
                            <h2 class="ss-ps-toc__title" id="ss-ps-toc-title">{{ __('On this page') }}</h2>
                            <ul>
                                @foreach ($toc as $entry)
                                    <li class="ss-ps-toc__item ss-ps-toc__item--l{{ $entry['level'] }}">
                                        <a href="#{{ $entry['id'] }}">{{ $entry['text'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </nav>
                    @endif

                    {{-- The only unescaped output on the platform site. The source is Markdown from
                         `resources/docs/`, rendered by `DocumentationRenderer` with CommonMark's
                         `html_input => strip` and `allow_unsafe_links => false`, so raw markup in a
                         source file is removed rather than rendered and a `javascript:` link cannot
                         survive. `DocumentationRendererTest` drives both. --}}
                    <article class="ss-ps-prose" lang="{{ $article['locale'] }}"
                             @if ($isFallback) dir="ltr" @endif>{!! $html !!}</article>

                    @if (count($related) > 0)
                        <section class="ss-ps-section ss-ps-section--plain">
                            <h2 class="ss-ps-h3">{{ __('Related') }}</h2>
                            <ul class="ss-ps-doclist">
                                @foreach ($related as $entry)
                                    <li>
                                        <a href="{{ route('platform.docs.article', [$entry['category'], $entry['slug']]) }}">{{ $entry['title'] }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endif

                    @if ($neighbours['prev'] || $neighbours['next'])
                        <nav class="ss-ps-pager" aria-label="{{ __('Article') }}">
                            @if ($neighbours['prev'])
                                <a class="ss-ps-pager__link" href="{{ route('platform.docs.article', [$neighbours['prev']['category'], $neighbours['prev']['slug']]) }}">
                                    <span class="ss-ps-pager__label">{{ __('Previous') }}</span>
                                    <span>{{ $neighbours['prev']['title'] }}</span>
                                </a>
                            @endif
                            @if ($neighbours['next'])
                                <a class="ss-ps-pager__link ss-ps-pager__link--next" href="{{ route('platform.docs.article', [$neighbours['next']['category'], $neighbours['next']['slug']]) }}">
                                    <span class="ss-ps-pager__label">{{ __('Next') }}</span>
                                    <span>{{ $neighbours['next']['title'] }}</span>
                                </a>
                            @endif
                        </nav>
                    @endif

                </div>
            </div>

        </div>
    </section>

@endsection
