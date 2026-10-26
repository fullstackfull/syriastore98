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
    @endif
@endpermission