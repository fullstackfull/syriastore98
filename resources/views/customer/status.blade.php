{{--
    P2-05-2 — the status toggle's accessible name.

    The control was an `<input type="checkbox">` with no `<label>`, no `aria-label` and no text:
    an unnamed switch, announced as "checkbox" and nothing else. Measured on the live list, where
    it appears once per row.

    THE NAME DESCRIBES WHAT THE CONTROL GOVERNS, not its current position. A checkbox's role
    already conveys checked/unchecked to assistive technology, so putting "active"/"inactive" in
    the name would announce the state twice and disagree with itself the moment it is toggled.
    It names the CUSTOMER too, because a screen-reader user moving through the table meets this
    switch without the row's context.

    The `id` stays as it is. It is the customer's numeric id, which is not a valid CSS selector
    (`#123`), but nothing selects it that way: the handler is delegated on the class
    (`$(document).on('change', '.page-checkbox', …)`), so this is not the F72 family.
--}}
{{--
    P2.5-PKG-G SLICE 3 -- THE COLUMN NO LONGER PRINTS A HEADER OVER NOTHING.

    Both conditions below used to wrap the switch and have no else, so the Status cell was
    LITERALLY EMPTY -- not a dash, not a word -- for two populous cases, while
    CustomerDataTable.php still printed a `__('Status')` header above them:

      * A GUEST. A null `regiester_date` is how this codebase models one: ReportController
        queries `regiester_date = NULL` under the name `$new_guest`, and
        PaymentController::createCustomer() writes the column only for a registered customer.
        `CustomerDataTable::query()` applies no `regiester_date` predicate, so guests really do
        appear in the merchant's list.
      * A VIEWER WITHOUT `Status Customer`. `@permission` compiles to Laratrust's
        `hasPermission()`, which has NO super-admin bypass -- so the platform operator, whom
        `customerStatus()` itself admits through its own operator-first check, saw an empty
        column on every row.

    Each now gets a READ-ONLY badge. The state is still shown; only the ability to change it is
    withheld, which is what the permission actually governs. A guest has no account to activate,
    so it says so rather than showing a switch that would have nothing to write to.
--}}
@permission('Status Customer')
    @if ($customer->regiester_date != null)
        <div class="form-check form-switch">
            <input class="form-check-input page-checkbox" id="{{ $customer->id }}"
            aria-label="{{ __('Account status for :name', ['name' => $customer->name]) }}"
                type="checkbox" name="page_active" data-onstyle="success"
                data-offstyle="danger" data-toggle="toggle" data-on="off"
                data-off="on"
                @if ($customer->status == 1) checked="checked" @endif />
        </div>
    @else
        <x-ss.status-badge tone="neutral" :label="__('Guest')" />
    @endif
@else
    @if ($customer->regiester_date != null)
        <x-ss.status-badge
            :tone="$customer->status == 1 ? 'success' : 'danger'"
            :label="$customer->status == 1 ? __('Active') : __('Inactive')" />
    @else
        <x-ss.status-badge tone="neutral" :label="__('Guest')" />
    @endif
@endpermission