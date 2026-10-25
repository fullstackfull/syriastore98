{{--
    Side panel (sheet). The third surface, between a dialog and a full page:
    inspect a record, or edit 1-5 fields, without losing the list behind it.

    A panel never opens a second panel. It opens a dialog, or it navigates.

    Behaviour lives in public/js/store-syria-ds.js, which moves focus to the
    heading on open, returns it to the trigger on close, traps Tab inside,
    closes on Esc and on a backdrop click, and locks the page behind from
    scrolling. The markup below only has to be the right markup.

    Open it from anywhere with:
        <button data-ss-panel-open="order-panel" data-ss-panel-url="/order-preview/4312">

    With `data-ss-panel-url` the script replaces the BODY's contents, so
    `loadingText` and `errorText` belong on this component rather than inside
    the slot: the script reads them off the same element it writes into, and
    `querySelector('[data-ss-panel-body]')` would find the body here before any
    element a caller nested inside it. They are passed through `__()` by the
    caller because the script has no translator -- omit them and an Arabic
    merchant sees English.
--}}
@props([
    'id',
    'title' => null,
    'labelledby' => null,
    'loadingText' => null,
    'errorText' => null,
])

<div class="ss-panel-backdrop" data-ss-panel-backdrop="{{ $id }}" hidden></div>

<aside id="{{ $id }}"
       class="ss-panel"
       data-ss-state="closed"
       role="dialog"
       aria-modal="true"
       @if ($labelledby) aria-labelledby="{{ $labelledby }}" @else aria-labelledby="{{ $id }}-title" @endif
       hidden
       {{ $attributes }}>
    <div class="ss-panel__header">
        {{-- tabindex="-1" so the open script can move focus here without
             making the heading a tab stop for everyone else. --}}
        <h2 class="ss-panel__title" id="{{ $id }}-title" tabindex="-1">{{ $title }}</h2>
        <button type="button" class="ss-panel__close" data-ss-panel-close="{{ $id }}">
            <i class="ti ti-x" aria-hidden="true"></i>
            <span class="ss-visually-hidden">{{ __('Close') }}</span>
        </button>
    </div>

    <div class="ss-panel__body"
         data-ss-panel-body
         @if ($loadingText) data-ss-loading-text="{{ $loadingText }}" @endif
         @if ($errorText) data-ss-error-text="{{ $errorText }}" @endif>
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="ss-panel__footer">{{ $footer }}</div>
    @endisset
</aside>
