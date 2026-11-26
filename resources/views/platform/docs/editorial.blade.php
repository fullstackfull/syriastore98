{{--
    GAP-107 (P03-D-04) — WHERE EVERY DOCUMENTATION ARTICLE IS IN THE PIPELINE.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    ONE ROW PER ARTICLE, NOT PER FILE, with a column per language. The question this screen answers
    is "is this article live, and is its Arabic as far along as its English" — a row per file would
    list every article twice and leave the reader to pair them up.

    THE SCREEN IS READ-ONLY AND THAT IS THE DESIGN, NOT A MISSING FEATURE. An article changes state
    when somebody edits the `status` line in `resources/docs/<locale>/...` and that change is
    reviewed and deployed. A button here would rewrite a container filesystem the next deploy
    replaces. `DocumentationStatus` and PHASE-03-DOCS-EDITORIAL-WORKFLOW.md both say so at length,
    and the note under the heading says it to the person looking at the screen.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`, so there is no hard-coded ltr/rtl and no directional margin in this file. The one
    exception is the per-language cell, which carries `lang` and `dir` for its OWN language because
    an Arabic title inside an English page (and the reverse) is exactly what this screen shows.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Documentation editorial') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Documentation editorial') }}
    </li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        {{-- ONE NOTE AND NOT TWO. It has to carry both facts a reader of this screen needs -- that
             only published articles are public, and that the screen reports the state rather than
             changing it -- and every sentence here is a string somebody has to translate. --}}
        <x-ss.card :title="__('Documentation editorial')"
                   :note="__('Every help article and the state it is in. Only published articles are visible to readers; an article changes state when that change is reviewed and deployed.')">

            <p class="ss-muted">
                @foreach ($states as $state)
                    <span class="ss-badge {{ \App\Support\Docs\DocumentationStatus::badgeModifier($state) }}">
                        {{ __(\App\Support\Docs\DocumentationStatus::label($state)) }}
                    </span>
                    <span class="ss-num">{{ $counts[$state] ?? 0 }}</span>
                @endforeach
            </p>

            @if (count($rows) === 0)
                {{-- `Nothing published yet` rather than a new string: it already exists in both
                     language files, and this branch is unreachable while the corpus has articles
                     (`DocumentationLibraryTest` asserts it has at least 40 files). A defensive
                     branch is not worth a translation nobody will ever read. --}}
                <x-ss.empty :title="__('Nothing published yet')" />
            @else
                <div class="ss-table-wrap">
                    <table class="ss-table">
                        <caption class="ss-visually-hidden">
                            {{ __('Every documentation article, with its editorial state in each language.') }}
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Category') }}</th>
                                <th scope="col">{{ __('Title') }}</th>
                                {{-- A LANGUAGE IS NAMED IN ITS OWN LANGUAGE, which is both the
                                     better convention and two translation keys nobody has to
                                     write. The site chrome's own language switch does the same
                                     (`platform/layout.blade.php`), so the two agree. --}}
                                @foreach (\App\Support\Docs\DocumentationLibrary::LOCALES as $locale)
                                    <th scope="col" lang="{{ $locale }}">
                                        {{ $locale === 'ar' ? 'العربية' : 'English' }}
                                    </th>
                                @endforeach
                                <th scope="col">{{ __('Last Updated') }}</th>
                                <th scope="col">{{ __('Version') }}</th>
                                <th scope="col">{{ __('Preview') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ $row['categoryTitle'] }}</td>

                                    {{-- `<th scope="row">` because the title is what identifies the
                                         row; a screen reader then announces it with every cell. --}}
                                    <th scope="row">
                                        {{ $row['title'] }}
                                        <span class="d-block ss-muted"><bdi dir="ltr">{{ $row['id'] }}</bdi></span>
                                    </th>

                                    @foreach (\App\Support\Docs\DocumentationLibrary::LOCALES as $locale)
                                        @php($article = $row['locales'][$locale] ?? null)
                                        <td lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
                                            @if ($article === null)
                                                {{-- A translation that does not exist yet. The reader
                                                     of that language is served the English text with
                                                     a notice, so this is a gap and not a failure. --}}
                                                <span class="ss-badge ss-badge--danger">{{ __('Not translated') }}</span>
                                            @else
                                                <span class="ss-badge {{ \App\Support\Docs\DocumentationStatus::badgeModifier($article['status']) }}">
                                                    {{ __(\App\Support\Docs\DocumentationStatus::label($article['status'])) }}
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach

                                    @php($primary = $row['locales'][\App\Support\Docs\DocumentationLibrary::LOCALES[0]] ?? null)

                                    <td>
                                        @if ($primary && $primary['updated'] !== '')
                                            <time datetime="{{ $primary['updated'] }}"><bdi>{{ \App\Support\TimelineMoment::formatDate($primary['updated']) ?: $primary['updated'] }}</bdi></time>
                                        @else
                                            <span class="ss-muted">{{ __('Not set') }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        @if ($primary && $primary['version'] !== '')
                                            <span dir="ltr">{{ $primary['version'] }}</span>
                                        @else
                                            <span class="ss-muted">{{ __('Not set') }}</span>
                                        @endif
                                    </td>

                                    <td>
                                        {{-- The link text names the article, so a screen-reader user
                                             moving between links does not hear "Preview" nine times
                                             with no way to tell them apart. --}}
                                        <a href="{{ route('platform.documentation.preview', [$row['category'], $row['slug']]) }}">
                                            {{ __('Preview') }}
                                            <span class="ss-visually-hidden">{{ $row['title'] }}</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ss.card>
    </div>
</div>
@endsection
