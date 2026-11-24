{{--
    THE OPT-IN PAGE. D8-3b / GAP-200 / MKT-008.

    Self-contained for the same reasons as the unsubscribe page beside it (four storefront layouts, the
    forbidden `<x-*-layout>` slot shape, and a reader who should not need theme assets to change their
    own mind); only the stylesheet is shared. Direction follows the LOCALE, because every string comes
    from __().

    ONE CHECKBOX PER CHANNEL THE SHOPPER ACTUALLY HAS AN ADDRESS FOR. The controller builds `$rows`
    from the signed-in customer's OWN row and normalises each address, so a channel with no usable
    address offers no box rather than one that could never work. The box's NAME is the only thing the
    POST reads back -- no store, no customer id and no address is carried in the form, because none of
    those may come from the request.

    A BLOCKED CHANNEL IS SHOWN AS BLOCKED, not as an unticked box that silently refuses to tick. A
    complaint, a legal request or a bounce is a hard block and is lifted deliberately, elsewhere.

    THE ADDRESS IS MASKED (`s***@example.test`, `********1234`). The page is behind a customer session,
    so the person could see their own address anyway -- but there is no reason for this page to be the
    thing that prints it, and masking keeps a shoulder-surfer or a screenshot harmless.
--}}
@php
    $locale = app()->getLocale();
    $isRtl = in_array($locale, ['ar', 'he', 'fa', 'ur'], true);
    $channelLabels = [
        'email' => __('Email'),
        'whatsapp' => __('WhatsApp'),
        'sms' => __('SMS'),
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Communication preferences') }}</title>
    @include('marketing.partials.page-styles')
</head>
<body>
    <main class="ss-card">
        <h1>{{ __('Communication preferences') }}</h1>
        <p class="ss-muted">{{ __('Choose which marketing messages you want to receive.') }}</p>

        @if ($saved)
            <p class="ss-badge ss-badge--ok" role="status">{{ __('Your preferences have been saved.') }}</p>
        @endif

        @if ($rows === [])
            {{-- No channel has a usable address, or the account names no store (see channelsFor). Saying so
                 beats a form whose only button could never write anything. --}}
            <p class="ss-muted">{{ __('There is no address on your account that we can send marketing to.') }}</p>
        @else
        {{-- The slug is carried so the shopper stays in the store's own URL space; it decides nothing
             server-side, and the route requires it so a signed-out visitor is never sent to the merchant
             login (review finding F3). --}}
        <form method="POST" action="{{ route('marketing.preferences.update', ['storeSlug' => request()->route('storeSlug')]) }}">
            @csrf

            @foreach ($rows as $row)
                <div class="ss-row">
                    <input
                        type="checkbox"
                        id="ss-ch-{{ $row['key'] }}"
                        name="{{ $row['key'] }}"
                        value="on"
                        @checked($row['granted'])
                        @disabled($row['blocked'])
                    >
                    <div>
                        <label for="ss-ch-{{ $row['key'] }}">
                            {{ $channelLabels[$row['key']] ?? $row['channel'] }}
                            @if ($row['blocked'])
                                <span class="ss-badge ss-badge--blocked">{{ __('Blocked') }}</span>
                            @endif
                        </label>
                        <div><span class="ss-contact">{{ $row['hint'] }}</span></div>
                        @if ($row['blocked'])
                            <p class="ss-muted">{{ __('This address is blocked and cannot be re-enabled here.') }}</p>
                        @endif
                    </div>
                </div>
            @endforeach

            <button type="submit">{{ __('Save preferences') }}</button>
        </form>
        @endif

        {{-- True either way: withdrawing marketing consent never stops an order confirmation. --}}
        <p class="ss-muted ss-note">{{ __('Messages about your orders will still be sent.') }}</p>
    </main>
</body>
</html>
