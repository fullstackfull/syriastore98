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

                    {{-- GAP-107 (P03-D-04). AN UNPUBLISHED ARTICLE SAYS SO, LOUDLY.

                         Only the operator's editorial preview can reach this view with an
                         unpublished article; the public controller cannot, because
                         `DocumentationLibrary`'s public reads return published articles only. So
                         the notice is not a second gate -- it is what stops a reviewer, or anybody
                         they screen-share with, mistaking an unapproved page for the live one. A
                         draft rendered identically to a published article is how an unapproved
                         answer ends up quoted to a merchant.

                         `role="status"` rather than `alert`: it is the state of the page, not an
                         error, and `alert` interrupts a screen reader mid-sentence.

                         The variable is passed by the editorial controller and NEVER by the public
                         one, so the `?? null` is the whole of the difference between the two
                         pages. --}}
                    @if (! empty($editorialNotice ?? null))
                        <p class="ss-ps-note" role="status">
                            {{-- `Not published yet` rather than a sentence of its own: the key
                                 already exists in both language files, so the notice reads
                                 correctly in Arabic on the day it ships rather than on the day
                                 somebody writes a translation for it. --}}
                            <strong>{{ __('Not published yet') }}</strong>
                            {{ __('Status') }}: {{ __($editorialNotice) }}
                        </p>
                    @endif

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

                    {{-- GAP-108. HOW OLD THIS PAGE IS, AND WHICH RELEASE IT DESCRIBES. Nothing in
                         the centre carried a date, so a merchant reading how an order moves could
                         not tell whether it described the product as it is or as it was a year ago.

                         THE MACHINE VALUE AND THE HUMAN ONE ARE NO LONGER THE SAME STRING, and the
                         reason this changed is a measurement. The first version of this block
                         printed the ISO value as the visible text in both languages and justified
                         it with "a localised month name would need Arabic calendar data this
                         application does not ship". That is false in this repository: Carbon ships
                         Arabic month names, and `App\Support\TimelineMoment` already formats dates
                         with them for the customer timeline. It asks for `ar_SY` rather than `ar`
                         on purpose -- the generic Arabic locale gives the transliterated Gregorian
                         names a Syrian merchant does not write (`نوفمبر`), the Syrian variant gives
                         the Levantine ones (`تشرين الثاني`). So the help pages of a product called
                         Store Syria printed their dates in no language at all while the customer
                         screen three directories away printed them in Levantine Arabic.

                         `datetime` STAYS ISO. It is the only part a machine reads and the only part
                         a parser accepts; the visible text is for the reader.

                         `<bdi>` REPLACES THE `dir="ltr"` THAT STOOD HERE, and the swap is the
                         subtle part. Forcing LTR was right for `2026-11-25`, a run of neutral
                         characters the bidi algorithm is otherwise free to reorder inside an Arabic
                         paragraph. It is wrong for `25 تشرين الثاني 2026`: under an LTR base
                         direction the two Arabic words form one right-to-left run and display in
                         the wrong order against the digits. `<bdi>` isolates the value from the
                         label beside it AND resolves its direction from its own first strong
                         character -- Arabic for the Arabic form, Latin for the English one -- which
                         is the same element and the same reason as
                         `resources/views/customer/timeline.blade.php`.

                         GUARDED ON EMPTY, because `DocumentationLibrary` empties the field rather
                         than hiding an article whose front matter is mistyped -- an unguarded
                         render would emit an empty `datetime`, which no parser accepts. The guard
                         is not a licence to ship an article without a date:
                         `DocumentationArticleFreshnessTest` fails on any file that has none. The
                         ISO value is also the FALLBACK for the visible text, so a date the
                         formatter cannot read still shows the reader something true.

                         `.ss-ps-help` rather than a new class: it is the platform stylesheet own
                         muted supporting line -- `--ss-fg-subtle` at `--ss-text-sm` -- so the
                         colour pair is one the platform site token work already validated in light
                         and dark. --}}
                    @if ($article['updated'] !== '' || ($article['version'] ?? '') !== '')
                        <p class="ss-ps-help">
                            {{-- The inner guard repeats the outer one on purpose. The outer opens
                                 the paragraph whenever EITHER fact exists, so an article that
                                 declared a release and no date would still show something rather
                                 than nothing; the inner is what keeps the date itself out of an
                                 empty `datetime`. --}}
                            @if ($article['updated'] !== '')
                                {{ __('Last Updated') }}:
                                <time datetime="{{ $article['updated'] }}"><bdi>{{ \App\Support\TimelineMoment::formatDate($article['updated']) ?: $article['updated'] }}</bdi></time>
                            @endif

                            {{-- GAP-108, the gap second clause: "or which release it describes". OPTIONAL,
                                 AND DELIBERATELY UNUSED BY THE SHIPPED CORPUS.

                                 This application carries no release identifier anywhere -- no `version` in
                                 `composer.json`, none in `config/app.php`, nothing in the repository that
                                 numbers a release -- so a release label on a help page would have had to be
                                 invented, and an invented release number looks authoritative while meaning
                                 nothing. Whether the product has releases to name is the owner question,
                                 not an engineering one.

                                 What is built is the plumbing and nothing else: the key parses, it renders
                                 when an article declares it, it renders nothing when no article does, and
                                 `DocumentationReleaseLabelTest` proves all three against fixtures rather
                                 than against a corpus that declares none. The day the owner names a release
                                 scheme it is a front-matter edit, not a code change.

                                 `dir="ltr"` HERE AND NOT ON THE DATE ABOVE, which is the opposite choice
                                 for the opposite reason: a release identifier is a machine token with no
                                 language, and `2026.11 (beta)` under an RTL base direction would mirror its
                                 brackets. It is not localised, so it does not follow the paragraph. --}}
                            @if (($article['version'] ?? '') !== '')
                                @if ($article['updated'] !== '')
                                    <span aria-hidden="true">&middot;</span>
                                @endif
                                {{ __('Applies to version') }}:
                                <span dir="ltr">{{ $article['version'] }}</span>
                            @endif
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
