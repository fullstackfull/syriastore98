{{--
    P2-05-5 — the permission chips on the roles list.

    TWO DEFECTS, AND THE SECOND IS WHY THE FIRST IS WORTH FIXING HERE RATHER THAN RECOLOURING.

    1. **2.59:1**, measured by `check-row-actions-live.cjs` in ALL FOUR configurations. These were
       `btn btn-badge bg-primary btn-sm`, i.e. white text on the host brand green `#10B77F` — the
       identical figure P2-04-4 recorded for the order-reference pill and P2-05-3 for the active
       tab pill. Against WCAG's 4.5:1 for text.

    2. **They were links that went nowhere.** Each was `<a href="#">`, so assistive technology
       announced "link" for every permission a role holds — eleven of them per row before the
       "Show More" cut — and activating one did nothing. They are not controls; they are labels
       listing what the role can do.

    Fixing (2) is what fixes (1) properly: `ss-chip ss-chip--static` is the design system's
    non-interactive chip, the same component the attribute terms use, and its colours are
    theme-aware tokens rather than the brand fill. Recolouring an `<a href="#">` would have
    cleared the contrast check and left a page full of fake links.

    THE SHOW MORE / SHOW LESS CONTROLS STAY LINKS, because they are real controls — the page's
    own script binds `.show-more` / `.show-less` — but they now carry `type`-appropriate semantics
    and an explicit accessible name rather than relying on their text alone being understood out
    of context. `d-none` on index >= 11 and the two class hooks are unchanged, because the script
    depends on them.
--}}
<div class="role-permission-table align-middle p-3" id="useradd-sidenav">
    <ul class="nav nav-pills row store-setting-tab ss-chips" id="pills-tab" role="list">
        @foreach ($role->permissions()->pluck('name') as $index => $permission)
            <li class="nav-item col-2 text-center {{ $index >= 11 ? 'd-none' : '' }}">
                <span class="ss-chip ss-chip--static">{{ $permission }}</span>
            </li>
        @endforeach
        @if ($role->permissions->count() > 10)
            <li class="nav-item show-more col-2 text-center d-inline-block mt-2">
                <a href="#" class="ss-btn ss-btn--ghost ss-btn--sm"
                    aria-label="{{ __('Show all permissions for :name', ['name' => $role->name]) }}">
                    {{ __('Show More') }}
                </a>
            </li>
            <li class="nav-item show-less col-2 text-center d-inline-block mt-2 d-none">
                <a href="#" class="ss-btn ss-btn--ghost ss-btn--sm"
                    aria-label="{{ __('Show fewer permissions for :name', ['name' => $role->name]) }}">
                    {{ __('Show Less') }}
                </a>
            </li>
        @endif
    </ul>
</div>
