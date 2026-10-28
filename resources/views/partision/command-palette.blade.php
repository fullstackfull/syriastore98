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

    {{--
        P2-06-8 — the actions for the selected result.

        OUTSIDE THE LISTBOX, and that is an ARIA requirement rather than a layout choice: an
        `option` is a leaf and must not contain interactive descendants, so a button inside a row
        would break the `aria-activedescendant` pattern above — the one that lets a selection move
        while the caret stays in the input. A `role="group"` sibling keeps that intact.

        EMPTY IS THE DEFAULT AND THE COMMON CASE. The server sends only the actions the principal
        may actually perform, so a merchant who may list products but not edit them gets a result
        with no actions and this bar stays hidden. It is never populated client-side.
    --}}
    <div class="ss-command__actions" id="ss-command-actions" role="group"
         data-ss-actions-label="{{ __('Actions') }}" hidden></div>

    {{--
        P2-06-7 — favourite pages and recent pages.

        HERE, AND NOT IN SIXTY-NINE PAGE HEADERS. The toggle and the lists belong to the same
        surface: this is the merchant's "go somewhere" dialog, so the control that curates it
        sits with the list it changes. Putting a star in each page header would mean editing every
        screen -- including the vendored ones this project must not touch -- for a control that
        would then be in a different place on each.

        SERVER-RENDERED, SO EVERY ENTRY IS AUTHORISED BEFORE IT IS MARKUP, exactly like the search
        results above. `FavouritePages::all()` and `RecentPages::all()` re-ask
        `App\Classes\Menu::permits()` through `MerchantPageDirectory` on every render, so a
        favourite whose permission was revoked is not rendered-then-hidden: it never reaches the
        DOM. That is the owner's condition -- "أعد فحص الصلاحيات عند كل عرض أو انتقال" -- and a
        client-side filter could not satisfy it.

        A RECENT PAGE THAT IS ALREADY A FAVOURITE IS NOT LISTED TWICE. The favourite is the
        durable one, so it wins the slot.
    --}}
    @php
        $ssFavourites = \App\Services\FavouritePages::all();
        $ssCurrent = \App\Services\MerchantPageDirectory::nameForRoute(request()->route()?->getName());

        /* THE CURRENT PAGE IS DROPPED FROM "RECENT" TOO, not only the favourites. Without this it
           rendered TWICE from the second visit onward -- the middleware records on the way out, so
           the page you are looking at is already in the session list -- giving one page two live
           star controls. That was also the only way to race the unique index: the script disables
           just the button that was clicked, so both could be posted at once. `firstOrCreate` in
           `FavouritePages::toggle()` closes the race; this removes the duplicate. */
        $ssRecents = array_diff_key(
            \App\Services\RecentPages::all(),
            $ssFavourites,
            $ssCurrent ? [$ssCurrent => true] : []
        );

        /* D-24a. The four approved create-actions, authorised SERVER-SIDE on every render exactly
           as the favourites and recents above are -- same condition from the owner, "أعد فحص
           الصلاحيات عند كل عرض أو انتقال", and the same answer: a row this principal may not use
           never becomes markup. `MerchantQuickActions::available()` holds the whole decision,
           including which targets admit the platform operator (they disagree) and the store check
           that stops an operator with no `current_store` being pointed at the hardcoded store 1. */
        $ssQuickActions = \App\Services\MerchantQuickActions::available();
    @endphp

    <div class="ss-command__shortcuts" data-ss-command-shortcuts>
        {{-- D-24a — إنشاء منتج، إنشاء طلب يدوي، إضافة عميل، إنشاء كوبون.

             IN THE EXISTING PALETTE, AS THE APPROVAL REQUIRES, and with no second mechanism for
             search or for actions: these are plain links in the shortcuts region that Favourites
             and Recent already use, with the same classes, so the dialog's focus trap and its Tab
             order cover them for free and no new CSS or JS exists to maintain. The per-result
             action bar (`#ss-command-actions`) is untouched -- it answers a different question,
             "what can I do to THIS search result", and these four answer "make something new".

             FIRST, above Favourites, deliberately: this list is fixed and short, while favourites
             and recents grow, and a merchant should not have to scroll past twelve favourites to
             reach "create product".

             NO DELETE, PAYMENT OR REFUND ACTION APPEARS HERE and none can be added by accident --
             the list is a constant in `MerchantQuickActions`, not anything derived from the menu,
             and `MerchantQuickActionTargetsTest` asserts both the exact four and that no route
             name among them is destructive or money-moving. --}}
        @if (!empty($ssQuickActions))
            <div class="ss-command__group">
                <p class="ss-command__grouphead">{{ __('Quick create') }}</p>
                <ul class="ss-command__shortlist">
                    @foreach ($ssQuickActions as $ssActionName => $ssAction)
                        <li>
                            <a class="ss-command__shortcut" href="{{ $ssAction['href'] }}"
                               data-ss-quick-action="{{ $ssActionName }}">
                                <span aria-hidden="true">{{ $ssAction['glyph'] }}</span>
                                <span class="ss-command__shortcuttitle">{{ $ssAction['title'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($ssFavourites))
            <div class="ss-command__group">
                <p class="ss-command__grouphead">{{ __('Favourites') }}</p>
                <ul class="ss-command__shortlist">
                    @foreach ($ssFavourites as $ssName => $ssPage)
                        <li>
                            <a class="ss-command__shortcut" href="{{ $ssPage['href'] }}">
                                <span class="ss-command__shortcuttitle">{{ $ssPage['title'] }}</span>
                            </a>
                            {{-- P2-01-15: BOTH states' copy travels with the control, because the
                                 toggle used to flip `aria-pressed` and the colour while leaving the
                                 title and the hidden label saying the opposite -- a screen reader
                                 read "Add :page to favourites" on a page that had just been added.
                                 The JS still holds no copy; it swaps between these. --}}
                            <button type="button"
                                    class="ss-command__star is-on"
                                    data-ss-favourite="{{ $ssName }}"
                                    aria-pressed="true"
                                    title="{{ __('Remove from favourites') }}"
                                    data-ss-fav-title-on="{{ __('Remove from favourites') }}"
                                    data-ss-fav-title-off="{{ __('Add to favourites') }}"
                                    data-ss-fav-label-on="{{ __('Remove :page from favourites', ['page' => $ssPage['title']]) }}"
                                    data-ss-fav-label-off="{{ __('Add :page to favourites', ['page' => $ssPage['title']]) }}">
                                <span class="ss-visually-hidden" data-ss-fav-label>{{ __('Remove :page from favourites', ['page' => $ssPage['title']]) }}</span>
                                <span aria-hidden="true" data-ss-fav-glyph>&#9733;</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (!empty($ssRecents))
            <div class="ss-command__group">
                <p class="ss-command__grouphead">{{ __('Recent') }}</p>
                <ul class="ss-command__shortlist">
                    @foreach ($ssRecents as $ssName => $ssPage)
                        <li>
                            <a class="ss-command__shortcut" href="{{ $ssPage['href'] }}">
                                <span class="ss-command__shortcuttitle">{{ $ssPage['title'] }}</span>
                            </a>
                            <button type="button"
                                    class="ss-command__star"
                                    data-ss-favourite="{{ $ssName }}"
                                    aria-pressed="false"
                                    title="{{ __('Add to favourites') }}"
                                    data-ss-fav-title-on="{{ __('Remove from favourites') }}"
                                    data-ss-fav-title-off="{{ __('Add to favourites') }}"
                                    data-ss-fav-label-on="{{ __('Remove :page from favourites', ['page' => $ssPage['title']]) }}"
                                    data-ss-fav-label-off="{{ __('Add :page to favourites', ['page' => $ssPage['title']]) }}">
                                <span class="ss-visually-hidden" data-ss-fav-label>{{ __('Add :page to favourites', ['page' => $ssPage['title']]) }}</span>
                                <span aria-hidden="true" data-ss-fav-glyph>&#9734;</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($ssCurrent && !array_key_exists($ssCurrent, $ssFavourites))
            <button type="button"
                    class="ss-command__addcurrent"
                    data-ss-favourite="{{ $ssCurrent }}"
                    aria-pressed="false"
                    data-ss-fav-text-on="{{ __('Remove this page from favourites') }}"
                    data-ss-fav-text-off="{{ __('Add this page to favourites') }}">
                <span aria-hidden="true" data-ss-fav-glyph>&#9734;</span>
                <span data-ss-fav-text>{{ __('Add this page to favourites') }}</span>
            </button>
        @endif

        {{-- The endpoint and the strings the toggle needs, so the JS holds no URLs or copy. --}}
        <span hidden
              data-ss-favourite-url="{{ route('merchant.pages.favourite') }}"
              data-ss-favourite-error="{{ __('That could not be saved. Please try again.') }}"
              data-ss-favourite-busy="{{ __('Saving your change…') }}"></span>
    </div>
</div>
