{{--
    GAP-104 (P03-C-05) — ONE PAGE OF THE OFFICIAL WEBSITE, IN BOTH LANGUAGES.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape — see the note in index.blade.php
    and tests/Unit/Ui/SlotLayoutViewsRenderTest.

    BOTH LANGUAGES ON ONE FORM, SIDE BY SIDE, which is a deliberate choice over a language switcher.
    The failure this site has already had is a translation drifting away from the text it translates;
    a form that shows the two together is the only shape in which that is visible while editing. The
    two fields carry their OWN `lang` and `dir`, not the page's, because an Arabic field inside an
    English screen is exactly what this form is for.

    THE SHIPPED WORDING IS SHOWN BESIDE EVERY FIELD THAT HAS ONE, and the field is left EMPTY until
    somebody overrides it. The alternative — pre-filling each box with the shipped text — reads
    better and is worse: it makes "unchanged" and "deliberately retyped identically" the same state,
    so nobody can tell what has been taken over from the release and nothing can be handed back.

    AN OWED FIELD CARRIES NO SHIPPED WORDING AND SAYS SO. The five legal documents and the four
    contact details are the owner's content. This screen gives them somewhere to land; it does not
    draft them, and no placeholder legal text or specimen address exists anywhere in this change.

    `.ss-form-legend` AND NOT THE HOST'S `.form-label` ON A `<legend>`: the host class is not
    theme-aware there and measured 1.1:1 in dark mode on the customer form, which is why the design
    system carries this one. `.ss-field` is the flex column that spaces a label from its control.
--}}
@extends('layouts.app')

@section('page-title')
    {{ __('Official website content') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item">
        <a class="ss-breadcrumb__link" href="{{ route('platform.site_content.index') }}">
            {{ __('Official website content') }}
        </a>
    </li>
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __($label) }}
    </li>
@endsection

@section('content')
<div class="row">
    <div class="col-12">
        <form method="POST" action="{{ route('platform.site_content.update', $page) }}">
            @csrf

            <x-ss.card :title="__($label)"
                       :note="__('Leave a text empty to use the wording the site ships with. A change is published as soon as it is saved.')">
                <x-slot:actions>
                    <a class="ss-link" href="{{ route($preview) }}">
                        {{ __('Open the public page') }}
                    </a>
                </x-slot:actions>

                @foreach ($fields as $key => $field)
                    @php
                        $owed = ($field['owed'] ?? false) === true;
                        $heading = __($field['label']) . (isset($field['ordinal']) ? ' ' . $field['ordinal'] : '');
                        $textareaRows = $field['kind'] === 'line' ? 1 : ($field['kind'] === 'markdown' ? 12 : 3);
                    @endphp

                    <fieldset class="mb-4">
                        <legend class="ss-label ss-form-legend mb-3">
                            {{ $heading }}
                            @if ($owed)
                                <span class="ss-badge ss-badge--danger">{{ __('Owner content') }}</span>
                            @endif
                        </legend>

                        @if ($owed)
                            <p class="ss-field__hint mb-3">
                                {{ __('The site ships with no wording for this. Nothing is published until the approved text is supplied here.') }}
                            </p>
                        @endif

                        <div class="row">
                            @foreach ($locales as $locale)
                                @php
                                    $id = 'c-' . $locale . '-' . \Illuminate\Support\Str::slug($key);
                                    $shipped = \App\Support\Platform\PlatformContent::builtIn($locale, $key);
                                    $value = \App\Support\Platform\PlatformContent::stored($locale, $key);
                                @endphp

                                <div class="col-md-6">
                                    <div class="form-group ss-field">
                                        <label class="ss-label" for="{{ $id }}" lang="{{ $locale }}">
                                            {{ $locale === 'ar' ? 'العربية' : 'English' }}
                                        </label>

                                        <textarea class="form-control ss-input"
                                                  id="{{ $id }}"
                                                  name="{{ $locale }}[{{ $key }}]"
                                                  rows="{{ $textareaRows }}"
                                                  lang="{{ $locale }}"
                                                  dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"
                                                  maxlength="{{ \App\Support\Platform\PlatformContent::maxLength($field['kind']) }}"
                                                  @if ($shipped !== '') aria-describedby="{{ $id }}-shipped" @endif
                                        >{{ $value }}</textarea>

                                        @if ($shipped !== '')
                                            <p class="ss-field__hint" id="{{ $id }}-shipped"
                                               lang="{{ $locale }}"
                                               dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
                                                {{ __('Shipped wording') }}: {{ $shipped }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach

                {{-- `.ss-btn` AND NOT THE HOST'S `.btn btn-primary`, and this was measured rather
                     than preferred. The host's primary fill paints rgb(4,34,42) on rgb(51,93,168)
                     in the dark admin theme -- 2.58:1 against a 4.5:1 requirement, measured on
                     this very screen before the change. It is a KNOWN, pre-existing host defect:
                     store-syria-ds.css:5640 records the same two colours at 2.63:1 on the order
                     grid and solved it there by removing the fill. The design system's own button
                     takes `--ss-brand` / `--ss-on-brand`, so it is correct in both themes by
                     construction, and 20-odd Phase 02 screens already use it. --}}
                <x-slot:footer>
                    <a href="{{ route('platform.site_content.index') }}" class="ss-btn ss-btn--secondary">
                        {{ __('Cancel') }}
                    </a>
                    <button type="submit" class="ss-btn ss-btn--primary">{{ __('Save') }}</button>
                </x-slot:footer>
            </x-ss.card>
        </form>
    </div>
</div>
@endsection
