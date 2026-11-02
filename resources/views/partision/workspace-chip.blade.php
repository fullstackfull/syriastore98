@php
    /**
     * The workspace chip — P2-01-11.
     *
     * Shows which store the merchant is working in, and lets them switch.
     *
     * AN EARLIER VERSION OF THIS COMMENT SAID no view in the repository linked
     * to `change.store`. That was wrong, and the correction matters because it
     * was the justification on record for this component.
     * `partision/header.blade.php:130-184` has shipped a store switcher all
     * along -- current store at :141, `route('change.store', ...)` at :151.
     *
     * What is true is narrower, and is why this still exists: that switcher is
     * gated to `auth()->user()->type == 'admin'` (:130, :148) and lists
     * `auth()->user()->stores`, the `hasMany(Store, 'created_by', 'id')`
     * relation -- which keys on `$this->id` and so returns NOTHING for a staff
     * member. Staff have never had a switcher, and have never been shown which
     * store they are working in.
     *
     * CONSEQUENCE, AND IT IS AN OWNER DECISION, NOT A BUG: a principal whose
     * type is `admin` now has TWO switchers on every page, this one and the
     * header's, over the same stores. The header one is not removed here --
     * taking away a control admins use today is a product change, and if this
     * chip ever fails to render (a null `current_store` leaves it out entirely)
     * that admin would be left with none. Recorded in the ledger as F11.
     *
     * IT USES THE DESIGN SYSTEM'S OWN COMPONENT, and that is the second
     * attempt. The first wrote a fresh `.ss-workspace` rule into custom.css
     * and collided head-on with `.ss-workspace` in store-syria-ds.css:1539,
     * which P2-01-1 had already built for exactly this chip — per-store accent
     * on the wrapper, ellipsis on the name, print rule and all. The collision
     * was visible as a white box with a teal edge drawn around a dark chip,
     * and nothing but a screenshot would have shown it. The duplicate is gone;
     * `.ss-workspace`, `.ss-workspace__text`, `.ss-workspace__name` and
     * `.ss-workspace__meta` below are the design system's.
     *
     * WHAT THIS DOES NOT DO. It never resolves the tenant. `getCurrentStore()`
     * keeps its exact semantics; this reads the store it already resolved and
     * draws its name. Switching is an ordinary navigation to the existing
     * `change.store` handler, which does its own ownership check and 403s —
     * the chip is not a second gate and must never be relied on as one.
     *
     * The option list comes from User::switchableStores(), which mirrors that
     * handler's predicate exactly so the dropdown cannot name a store the
     * handler would refuse. See the docblock there; it is the whole security
     * argument for this file.
     *
     * Rendered OUTSIDE ul.dash-navbar, so it is never a
     * `.dash-navbar > .dash-item` and the sidebar search does not see it.
     */
    $ssCurrentStore = null;
    $ssStores = collect();

    if (auth()->check()) {
        $ssUser = auth()->user();
        // getStoreById(), not a fresh query: the resolution path stays the one
        // the rest of the request already used.
        $ssCurrentStore = getStoreById($ssUser->current_store);
        $ssStores = $ssUser->switchableStores();
    }
@endphp

@if ($ssCurrentStore)
    <div class="ss-workspace-wrap" data-ss-workspace>
        @if ($ssStores->count() > 1)
            <button type="button"
                    class="ss-workspace"
                    id="ss-workspace-trigger"
                    aria-haspopup="listbox"
                    aria-expanded="false"
                    aria-controls="ss-workspace-list">
                <span class="ss-workspace__text">
                    {{-- The visually-hidden label is what makes the button's
                         accessible name say WHAT it switches, instead of
                         reading the store name as if it were a heading. --}}
                    <span class="ss-visually-hidden">{{ __('Current store') }}:</span>
                    <span class="ss-workspace__name">{{ $ssCurrentStore->name }}</span>
                    <span class="ss-workspace__meta">{{ __('Switch store') }}</span>
                </span>
                <i class="ti ti-selector ss-workspace__caret" aria-hidden="true"></i>
            </button>

            <ul class="ss-menu ss-workspace__list" id="ss-workspace-list" role="listbox"
                aria-labelledby="ss-workspace-trigger" hidden>
                @foreach ($ssStores as $ssStore)
                    @php $ssIsCurrent = (int) $ssStore->id === (int) $ssCurrentStore->id; @endphp
                    <li role="none">
                        {{-- P2-SEC-F12. A POSTING FORM, NOT A LINK. Choosing a workspace writes
                             `users.current_store`, the tenant pointer every store-scoped query
                             reads, so it is a state-changing request and needs a CSRF token; as
                             a GET it fired from a third-party `<img src>` and from link
                             prefetching with no click at all.

                             THE ARIA IS UNCHANGED, deliberately. `role="option"`,
                             `aria-selected` and `.ss-menu__item` move onto the submit control,
                             and the interposed form carries `role="none"` so the listbox still
                             owns its options -- an element with a presentational role is
                             transparent for that ownership. The three browser checks that read
                             `#ss-workspace-list [role="option"]`, `[aria-selected="true"]` and
                             `.ss-menu__item` therefore measure the same thing. --}}
                        <form method="POST"
                              action="{{ route('change.store', $ssStore->id) }}"
                              role="none"
                              class="ss-workspace__form">
                            @csrf
                            <button type="submit"
                                    role="option"
                                    aria-selected="{{ $ssIsCurrent ? 'true' : 'false' }}"
                                    class="ss-menu__item">
                                <span class="ss-workspace__name">{{ $ssStore->name }}</span>
                                @if ($ssIsCurrent)
                                    {{-- A tick is colour-and-shape only; the state a
                                         screen reader needs is aria-selected above,
                                         so the icon is hidden from it. --}}
                                    <i class="ti ti-check ss-workspace__tick" aria-hidden="true"></i>
                                @endif
                            </button>
                        </form>
                    </li>
                @endforeach
            </ul>
        @else
            {{-- One store: a button opening a list of one is a control that
                 does nothing, so it renders as static text. Same reasoning as
                 the ungated chips on the dashboard (P2-02-11). --}}
            <div class="ss-workspace ss-workspace--static">
                <span class="ss-workspace__text">
                    <span class="ss-visually-hidden">{{ __('Current store') }}:</span>
                    <span class="ss-workspace__name">{{ $ssCurrentStore->name }}</span>
                </span>
            </div>
        @endif
    </div>
@endif
