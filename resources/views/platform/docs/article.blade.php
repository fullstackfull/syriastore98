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

                    {{-- GAP-108. HOW OLD THIS PAGE IS. Nothing in the centre carried a date, so a
                         merchant reading how an order moves could not tell whether it described the
                         product as it is or as it was a year ago.

                         THE ISO VALUE AND THE READABLE ONE ARE THE SAME VALUE. The `datetime`
                         attribute is what a machine reads and must stay ISO; the visible text is
                         the same string, because a localised month name would need Arabic calendar
                         data this application does not ship, and a date rendered in the wrong
                         language is worse than one rendered unambiguously in neither.

                         GUARDED ON EMPTY, because `DocumentationLibrary` empties the field rather
                         than hiding an article whose front matter is mistyped — an unguarded render
                         would emit `datetime=""`, which no parser accepts. The guard is not a
                         licence to ship an article without a date:
                         `DocumentationArticleFreshnessTest` fails on any file that has none.

                         `dir="ltr"` on the value: an ISO date is a left-to-right sequence, and
                         inside an Arabic paragraph the bidirectional algorithm would otherwise be
                         free to reorder the hyphen-separated groups. --}}
                    {{-- `.ss-ps-help` RATHER THAN A NEW CLASS. It is the platform stylesheet's own
                         muted supporting line — `--ss-fg-subtle` at `--ss-text-sm` — so the colour
                         pair is one the platform site's token work already validated in light and
                         dark. A new class would have meant an unstyled full-size date or a rule in
                         a stylesheet this change has no reason to touch. --}}
                    @if ($article['updated'] !== '')
                        <p class="ss-ps-help">
                            {{ __('Last Updated') }}:
                            <time datetime="{{ $article['updated'] }}" dir="ltr">{{ $article['updated'] }}</time>
                        </p>
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
