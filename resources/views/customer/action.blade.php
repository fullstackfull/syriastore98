{{--
    P2-05-2 — the customer list's row actions, on the design system.

    THE SAME THREE DEFECTS P2-04-4 FIXED ON THE ORDER LIST, measured here in the running
    application with the same harness (`check-row-actions-live.cjs`, to which `/customer` is now
    added). This screen was simply not in that sweep.

    1. EVERY ROW ACTION WAS UNNAMED AT RUNTIME — six per page, in all four configurations. They
       carried `title`, which looks like a name and is not: `data-bs-toggle="tooltip"` makes
       Bootstrap MOVE the title into `data-bs-original-title` and blank the attribute on
       initialisation. So after page load each was a control wrapping an unlabelled `<i>` —
       "button" and nothing else, down the whole list. Each now carries an `aria-label` that names
       the CUSTOMER as well as the verb, because a screen-reader user moving through the table
       hears a row's actions without the row's context.

    2. ICON CONTRAST BELOW THE 3:1 NON-TEXT MINIMUM in light mode: the eye measured **1.68:1** —
       the identical figure P2-04-4 recorded for `btn-warning`'s eye on the order list, white on a
       pale yellow the vendored theme leaves alone. These are the design system's own quiet icon
       buttons now, whose colours are theme-aware tokens.

    3. THE DELETE USED A DIFFERENT CONFIRMATION MECHANISM FROM EVERY OTHER LIST. It was an inline
       `onsubmit="return confirm(...)"` — a raw browser dialog — while orders and products use
       `.show_confirm`, bound by a delegated handler in `public/js/custom.js` that opens the
       project's SweetAlert dialog and submits `closest('form')`. The harness found **0 delete
       controls** on this screen for exactly that reason, and that was a real finding rather than a
       detector gap. Converted, so the merchant gets the same dialog here as everywhere else and
       the standard handler governs it. The form, the CSRF token and `@method('DELETE')` are
       unchanged, so what is submitted is identical.

    WHAT IS DELIBERATELY UNCHANGED. `data-bs-toggle="tooltip"` and `title` stay: the tooltip is a
    sighted affordance worth keeping, and where both exist `aria-label` is what assistive
    technology announces. The `@permission` gates, the routes, the enclosing
    `<span class="d-flex …">` and the RewardClubPoint include are untouched — this is a styling and
    labelling change, not a behaviour one.

    ON THE NAME IN THE LABEL: `$customer->name` is the model's own accessor
    (`first_name . ' ' . last_name`) and Blade escapes it, so a shopper-chosen string cannot break
    out of the attribute. F98 is the same values' OTHER route into this page, through
    `CustomerDataTable`'s raw column, which needed `e()` because that path builds HTML by
    concatenation.
--}}
<span class="d-flex gap-1 justify-content-end">
    @if (($customer && $customer->id) || $activityLogEntry)
    <a href="{{ route('customer.timeline', $customer->id) }}"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Show') }}"
        aria-label="{{ __('Activity timeline for :name', ['name' => $customer->name]) }}">
        <i class="ti ti-eye" aria-hidden="true"></i>
    </a>
    @endif
    @permission('Show Customer')
    <a href="{{ route('customer.show', $customer->id) }}"
        class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Cart') }}"
        aria-label="{{ __('Cart of :name', ['name' => $customer->name]) }}">
        <i class="ti ti-shopping-cart" aria-hidden="true"></i>
    </a>
    @endpermission
    @if (module_is_active('RewardClubPoint'))
    @include('reward-club-point::admin.clubPointHistoryBtn', ['customerId' => $customer->id])
    @endif
    <form action="{{ route('customer.destroy', $customer->id) }}"
          method="POST"
          class="d-inline">
        @csrf
        @method('DELETE')
        <button type="button"
                class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
                data-bs-placement="top"
                data-bs-toggle="tooltip"
                data-confirm="{{ __('Are You Sure?') }}"
                data-text="{{ __('Are you sure you want to delete this customer? This will archive the customer data.') }}"
                data-text-yes="{{ __('Yes') }}"
                data-text-no="{{ __('No') }}"
                title="{{ __('Delete') }}"
                aria-label="{{ __('Delete customer :name', ['name' => $customer->name]) }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </button>
    </form>
</span>
