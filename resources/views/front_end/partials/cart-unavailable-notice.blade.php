{{--
    THE SHOPPER IS TOLD WHICH LINE WENT. One copy, included by the cart page and the checkout
    summary.
    =========================================================================================

    WHY IT EXISTS. GAP-241 / GAP-245 and their guest twin stopped `cart-list` answering HTTP 500
    for a basket holding a delisted product: the line is now DROPPED, and both the signed-in and
    the guest basket reads publish `unavailable_products` and `unavailable_message`. Nothing
    rendered either key, so the line simply vanished and the shopper was told nothing -- they
    believe they are buying something they are not, which is a worse failure than the 500 it
    replaced because it is invisible.

    ---------------------------------------------------------------------------------------------
    WHY THE COLOURS ARE DESIGN-SYSTEM CLASSES AND NOT TAILWIND UTILITIES. This is the measured
    trap, not a preference.

    The storefront loads the Tailwind RUNTIME JIT CDN, so a utility is injected after the theme
    stylesheet and wins on source order at equal specificity. Dark mode is delivered by
    `public/css/storefront-appearance.css`, which re-points a FIXED set of utilities at tokens --
    read from that file: `bg-white`, `bg-gray-50/100/200`, `bg-primary`, `text-black`, `text-white`,
    `text-primary`, `text-gray-400..900`, `border-gray-100/200/300`, and nothing else. An amber
    panel built the obvious way, from `bg-amber-50` and `text-amber-900`, would therefore have
    stayed LIGHT on a dark page while looking perfectly correct in every light-mode check -- the
    exact shape that let 18 contrast failures survive a stylesheet that had already been fixed.

    So the notice is the design system's own `.ss-alert ss-alert--warning`, whose pair is
    `--ss-warning-bg` / `--ss-warning-fg`. Those tokens carry explicit values in BOTH themes
    (`#fdf4e3` / `#5d3f04` light, `#2c2109` / `#f0c674` dark), and `store-syria-ds.css` is loaded on
    the storefront through `front_end/partials/appearance.blade.php`, which all three theme layouts
    include -- checked, because M1 had measured the DS loaded NOWHERE and that has since changed.
    `CartStaleLineNoticeRendersTest` ratchets both rules, with the mapped set read from the layer
    rather than hard-coded, and the measured contrast ratios are recorded by
    `tests/Support/bin/measure-cart-notice-contrast.cjs`.

    `--ss-warning-subtle` and `--ss-warning-border` MUST NOT be used: `store-syria-ds.css:1366-1370`
    records that neither token exists in any theme block, so a rule using them "read as if it used
    the warning palette while silently painting the neutral one".

    ---------------------------------------------------------------------------------------------
    RTL COMES FROM THE COMPONENT, NOT FROM A SECOND RULE. `.ss-alert` is built on
    `border-inline-start` and `padding-inline`, so it mirrors itself under `dir="rtl"`. There is
    deliberately no physical-direction utility anywhere below -- one would be wrong in Arabic only,
    which is the direction nobody checks first -- and a ratchet asserts their absence.

    ONE TRANSLATED STRING, NOT TWO. The body is `unavailable_message`, which the server already
    built through `__()`; there is no separate heading key. A second key would have to be
    translated in both files or the panel would print an English title above an Arabic sentence,
    and the Arabic case asserts that the English wording is ABSENT.
--}}
@php
    $ssUnavailableLines = $response->data->unavailable_products ?? [];
    $ssUnavailableText = (string) ($response->data->unavailable_message ?? '');
@endphp

@if (!empty($ssUnavailableLines) && $ssUnavailableText !== '')
    {{-- `role="status"` with `aria-live="polite"` because these views are re-rendered into the page
         by AJAX (`get_cartlist()` in front-theme.js), so a shopper using a screen reader is never
         told the basket changed unless the region announces itself. `polite` and not `assertive`:
         it is information, not an interruption. --}}
    {{-- `mb-4` is a Tailwind BLOCK-axis spacing utility: it is not a colour (so the dark layer has
         nothing to map) and not direction-sensitive (so RTL needs nothing). An earlier draft wrote
         `ss-stack-mb-4`, which does not exist in `store-syria-ds.css` -- checked, 0 occurrences --
         and would have been a class name pretending to be a rule, the same defect that file records
         for `--ss-warning-subtle`. --}}
    <div class="ss-alert ss-alert--warning mb-4" role="status" aria-live="polite">
        <svg class="ss-alert__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
             stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>
        <div class="ss-alert__content">
            <p class="ss-alert__body">{{ $ssUnavailableText }}</p>
        </div>
    </div>
@endif
