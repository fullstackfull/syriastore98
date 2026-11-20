{{--
    THE UNSUBSCRIBE PAGE. D8-3a / GAP-200 / MKT-008.

    SELF-CONTAINED, AND THAT IS A DECISION. It renders its own <html> rather than extending a storefront
    layout, for three reasons recorded here so the next reader does not "tidy" it into one:
      * there are FOUR copies of front_end/layouts/app.blade.php (one per theme plus the fallback) and a
        layout-level change must be made four times (CLAUDE.md); an unsubscribe page must not depend on
        which of them a store happens to resolve.
      * it is opened by a recipient who may be logged out, from an e-mail or WhatsApp client, possibly
        long after the message was sent. It must render with no session, no cart and no theme assets.
      * the `<x-*-layout>` slot shape renders NOTHING under this project's @yield layouts -- measured, and
        pinned by tests/Unit/Ui/SlotLayoutViewsRenderTest. Writing a new view in that shape is forbidden.

    DIRECTION FOLLOWS THE LANGUAGE, not the admin's SITE_RTL toggle. Every string here comes from __(),
    so the text really is in the active locale and the direction has to match it; `themelanguage` sets
    that locale on storefront routes. SITE_RTL is a panel setting and says nothing about this page.

    IT NEVER PRINTS THE ADDRESS. `$hint` is the masked hint the token row stores (`s***@example.test`) --
    enough to recognise, not enough to use -- and it is printed through {{ }}, never raw.
--}}
@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he', 'fa', 'ur'], true);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- An unsubscribe page carries a capability in its URL: it must never be indexed or followed. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Unsubscribe') }}</title>
    <style>
        :root {
            --ss-bg: #f5f6f8;
            --ss-surface: #ffffff;
            --ss-text: #1b1f24;
            --ss-muted: #4a5159;
            --ss-border: #d7dbe0;
            --ss-accent: #0b5fff;
            --ss-accent-text: #ffffff;
            --ss-danger: #8a1c1c;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --ss-bg: #14171a;
                --ss-surface: #1e2227;
                --ss-text: #f2f4f6;
                --ss-muted: #b9c0c7;
                --ss-border: #343a41;
                --ss-accent: #6aa1ff;
                --ss-accent-text: #10151b;
                --ss-danger: #ff9b9b;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--ss-bg);
            color: var(--ss-text);
            font-family: system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif;
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .ss-card {
            background: var(--ss-surface);
            border: 1px solid var(--ss-border);
            border-radius: 12px;
            max-width: 32rem;
            width: 100%;
            padding: 28px 24px;
        }
        .ss-store { color: var(--ss-muted); font-size: 0.875rem; margin: 0 0 8px; }
        h1 { font-size: 1.375rem; margin: 0 0 12px; }
        p { margin: 0 0 12px; color: var(--ss-text); }
        .ss-muted { color: var(--ss-muted); font-size: 0.9375rem; }
        .ss-contact {
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            background: var(--ss-bg);
            border: 1px solid var(--ss-border);
            border-radius: 6px;
            padding: 2px 8px;
            direction: ltr;
            unicode-bidi: isolate;
        }
        button {
            font: inherit;
            cursor: pointer;
            background: var(--ss-accent);
            color: var(--ss-accent-text);
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 12px 20px;
            margin-top: 8px;
        }
        button:hover { filter: brightness(0.95); }
        button:focus-visible { outline: 3px solid var(--ss-accent); outline-offset: 2px; }
        .ss-note { border-top: 1px solid var(--ss-border); margin-top: 20px; padding-top: 14px; }
    </style>
</head>
<body>
    <main class="ss-card">
        @if ($state === 'confirm')
            @if (! empty($storeName))
                <p class="ss-store">{{ $storeName }}</p>
            @endif
            <h1>{{ __('Stop marketing messages') }}</h1>
            {{-- The address and the sentence are separate elements ON PURPOSE: no {!! !!} is needed, so
                 nothing on this page is printed raw, and a translator never has to carry markup. --}}
            <p>{{ __('You are about to stop marketing messages sent to this address:') }}</p>
            <p><span class="ss-contact">{{ $hint }}</span></p>
            <form method="POST" action="{{ $action }}">
                @csrf
                <button type="submit">{{ __('Confirm unsubscribe') }}</button>
            </form>
            <p class="ss-muted ss-note">{{ __('Messages about your orders will still be sent.') }}</p>
        @elseif ($state === 'done')
            @if (! empty($storeName))
                <p class="ss-store">{{ $storeName }}</p>
            @endif
            <h1>{{ __('You have been unsubscribed') }}</h1>
            <p>{{ __('We will no longer send marketing messages to this address:') }}</p>
            <p><span class="ss-contact">{{ $hint }}</span></p>
            <p class="ss-muted ss-note">{{ __('Messages about your orders will still be sent.') }}</p>
        @else
            <h1>{{ __('This unsubscribe link is not recognised.') }}</h1>
            <p class="ss-muted">{{ __('It may have been mistyped, or it may belong to a different store.') }}</p>
        @endif
    </main>
</body>
</html>
