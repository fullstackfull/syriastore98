{{--
    GAP-104 (P03-C-05) — WHERE THE OFFICIAL WEBSITE'S CONTENT STANDS.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape. `layouts/app.blade.php` is the
    vendored WorkDo layout and contains zero `$slot` references, so a view written in the component
    shape renders perfect chrome, HTTP 200 and an EMPTY content area — measured, in all four
    language/theme combinations. tests/Unit/Ui/SlotLayoutViewsRenderTest fails on that shape because
    the failure is otherwise invisible.

    ONE ROW PER PUBLIC PAGE, because that is the unit an operator thinks in: they want to change
    something on the pricing page, not to find field 31 of 54. The counts answer the two questions
    this screen exists for — how much of this page has been rewritten, and how much of it is still
    waiting on the owner.

    THE OUTSTANDING COLUMN IS THE POINT OF THE SCREEN. Nine fields carry no built-in copy because
    the owner owes them: the five legal documents and the four contact details. The capability's job
    is to make that visible and give the owner somewhere to put the approved text — not to fill it
    in. Nothing in this change drafts a privacy notice or invents an address.

    BILINGUAL BY CONSTRUCTION: every string is `__()` and direction comes from the layout's own
    `dir`, so there is no hard-coded ltr/rtl and no directional margin here. The two language names
    are given in their own language, which is both the better convention and two keys nobody has to
    translate — the same choice `platform/docs/editorial.blade.php` and the site chrome's own
    language switch already make.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Official website content') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Official website content') }}
    </li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <x-ss.card :title="__('Official website content')"
                   :note="__('The text on the official Store Syria website, changed here rather than in a release. Plan prices and limits are not editable: they are read from the plans the subscription flow charges against.')">

            <div class="ss-table-wrap">
                <table class="ss-table">
                    <caption class="ss-visually-hidden">
                        {{ __('Each page of the official website, how many of its texts have been supplied in each language, and how much content is still owed.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Page') }}</th>
                            <th scope="col">{{ __('Texts') }}</th>
                            @foreach ($locales as $locale)
                                <th scope="col" lang="{{ $locale }}">
                                    {{ $locale === 'ar' ? 'العربية' : 'English' }}
                                </th>
                            @endforeach
                            <th scope="col">{{ __('Owner content outstanding') }}</th>
                            <th scope="col">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($pages as $slug => $page)
                            <tr>
                                {{-- `<th scope="row">` because the page name identifies the row; a
                                     screen reader then announces it with every cell. --}}
                                <th scope="row">
                                    {{ __($page['label']) }}
                                    <span class="d-block ss-muted">
                                        <a class="ss-link" href="{{ route($page['route']) }}">
                                            {{ __('Open the public page') }}
                                            <span class="ss-visually-hidden">{{ __($page['label']) }}</span>
                                        </a>
                                    </span>
                                </th>

                                <td><span class="ss-num">{{ $page['total'] }}</span></td>

                                @foreach ($locales as $locale)
                                    <td>
                                        <span class="ss-num">{{ $page['supplied'][$locale] }}</span>
                                        <span class="ss-muted">/ {{ $page['total'] }}</span>
                                    </td>
                                @endforeach

                                <td>
                                    @if ($page['owed'] === 0)
                                        <span class="ss-muted">{{ __('None') }}</span>
                                    @else
                                        @foreach ($locales as $locale)
                                            <span class="ss-badge {{ $page['outstanding'][$locale] === 0 ? 'ss-badge--success' : 'ss-badge--danger' }}"
                                                  lang="{{ $locale }}">
                                                {{ $locale === 'ar' ? 'العربية' : 'English' }}
                                                <span class="ss-num">{{ $page['outstanding'][$locale] }}</span>
                                            </span>
                                        @endforeach
                                    @endif
                                </td>

                                <td>
                                    {{-- The link text names the page, so a screen-reader user moving
                                         between links does not hear "Edit" seven times with no way
                                         to tell them apart. --}}
                                    <a href="{{ route('platform.site_content.edit', $slug) }}">
                                        {{ __('Edit') }}
                                        <span class="ss-visually-hidden">{{ __($page['label']) }}</span>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="ss-muted">
                {{ __('A text left empty uses the wording the site ships with. The texts counted as outstanding carry no built-in wording at all, because the owner supplies them: until they do, those pages say so rather than showing anything.') }}
            </p>
        </x-ss.card>
    </div>
</div>
@endsection
