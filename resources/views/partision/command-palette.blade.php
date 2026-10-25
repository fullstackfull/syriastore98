{{--
    P2-06-2 — the merchant's global search, as a command palette.

    OPENED WITH Ctrl/Cmd+K OR FROM THE SIDEBAR SEARCH. The sidebar's own `#sideBarsearch` filters
    the NAVIGATION and keeps doing exactly that — it is not replaced, because filtering the menu
    is a different job from finding a record and merchants already use it. Focusing it offers
    this instead; the existing behaviour is untouched if they ignore the offer.

    EVERY RESULT IS AUTHORISED SERVER-SIDE. This markup renders whatever
    `MerchantSearchController` returns and decides nothing: sections the caller may not see never
    arrive, and rows outside their store never arrive. A palette that filtered client-side would
    be an enumeration oracle with a nice keyboard shortcut.

    ACCESSIBILITY. `role="combobox"` on the input with `aria-expanded` and `aria-controls`, the
    results as a `listbox`, and the active row carried by `aria-activedescendant` so arrow keys
    move a SELECTION without moving focus out of the input — which is what lets a screen reader
    announce the row while the user keeps typing. Esc closes and returns focus to whatever opened
    it; the whole thing is a dialog, so Tab is trapped inside it.
--}}
<div class="ss-command-backdrop" data-ss-command-backdrop hidden></div>

<div class="ss-command ss-scope" id="ss-command" role="dialog" aria-modal="true"
     aria-label="{{ __('Search') }}" hidden>

    <input type="text"
           class="ss-command__input"
           id="ss-command-input"
           role="combobox"
           aria-expanded="false"
           aria-controls="ss-command-results"
           aria-autocomplete="list"
           autocomplete="off"
           placeholder="{{ __('Search products, orders and customers…') }}"
           data-ss-search-url="{{ route('merchant.search') }}"
           data-ss-empty-text="{{ __('Nothing found') }}"
           data-ss-error-text="{{ __('Search is unavailable right now.') }}"
           data-ss-searching-text="{{ __('Searching…') }}"
           data-ss-hint-text="{{ __('Type at least two characters') }}">

    {{-- `aria-live="polite"`: the result COUNT is announced as it changes, which is the one
         thing a screen-reader user cannot see happening while they type. --}}
    <p class="ss-visually-hidden" aria-live="polite" id="ss-command-status"></p>

    <ul class="ss-command__results" id="ss-command-results" role="listbox"
        aria-label="{{ __('Search results') }}"></ul>
</div>
