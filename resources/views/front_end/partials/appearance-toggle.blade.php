{{--
    M2-A — THE SHOPPER'S APPEARANCE CONTROL.

    Included from each theme's own header, OUTSIDE any `@auth('customers')` gate: a guest browsing
    a shop is exactly the shopper most likely to want this, and the wishlist/cart icons it sits
    beside are auth-gated while this must not be.

    THREE STATES, AND `auto` REALLY FOLLOWS THE DEVICE. The directive is explicit that a
    decorative Auto with no working state is not acceptable, so `auto` installs a
    `matchMedia` listener and re-themes live when the OS flips. Two states would have left a
    shopper who once pressed Dark with no way back to "whatever my phone does".

    ACCESSIBILITY. It is a real <button>, so keyboard and touch work without help. The state is
    carried in `aria-pressed` ... deliberately NOT: `aria-pressed` is binary and this is ternary,
    so announcing it that way would be a lie to a screen reader. Instead the current state is in
    the accessible name, which is rebuilt on every change, and the visually-hidden text is in a
    polite live region so the change is announced without stealing focus.

    NO ENGLISH IS BAKED IN. Every string arrives through `__()` as a data attribute and the label
    is composed in JS from those, so an Arabic page never shows an English word. Merchant-authored
    content is untouched -- this control carries no tenant data.
--}}

<button type="button"
        class="ss-appearance-toggle"
        data-ss-appearance-toggle
        data-label-appearance="{{ __('Appearance') }}"
        data-label-auto="{{ __('Follow device') }}"
        data-label-light="{{ __('Light') }}"
        data-label-dark="{{ __('Dark') }}"
        data-label-activate="{{ __('Activate to switch to :mode') }}"
        aria-label="{{ __('Appearance') }}">
    {{-- One icon per state; CSS shows exactly one. Not mirrored in RTL: a sun and a moon are
         objects, not directional affordances, so flipping them would be wrong. --}}
    <svg class="ss-appearance-toggle__icon ss-appearance-toggle__icon--auto" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/>
        <path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"/>
    </svg>
    <svg class="ss-appearance-toggle__icon ss-appearance-toggle__icon--light" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.6"/>
        <path d="M12 2.4v2.2M12 19.4v2.2M2.4 12h2.2M19.4 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M18.8 5.2l-1.6 1.6M6.8 17.2l-1.6 1.6"
              stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
    </svg>
    <svg class="ss-appearance-toggle__icon ss-appearance-toggle__icon--dark" viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
        <path d="M20.5 14.3A8.6 8.6 0 0 1 9.7 3.5a8.8 8.8 0 1 0 10.8 10.8z"
              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span class="ss-appearance-toggle__state" aria-live="polite"></span>
</button>
