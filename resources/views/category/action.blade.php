{{--
    P2-05-4 — the category list's row actions, on the design system.

    THE SAME THREE DEFECTS P2-04-4 FIXED ON THE ORDER LIST AND P2-05-2 ON THE CUSTOMER LIST.
    This screen was in neither sweep.

    1. BOTH ROW ACTIONS WERE UNNAMED AT RUNTIME. They carried `title`, which looks like a name
       and is not: `data-bs-toggle="tooltip"` makes Bootstrap MOVE the title into
       `data-bs-original-title` and blank the attribute on initialisation, so after page load each
       was a control wrapping an unlabelled `<i>` — "button", twice per row, down the whole list.
       Each now carries an `aria-label` naming the CATEGORY as well as the verb, because a
       screen-reader user moving through the table hears a row's actions without the row's context.

    2. THE BUTTONS CAME OFF THE VENDORED THEME — `btn-info` and `btn-danger`, whose contrast the
       theme leaves alone and which P2-04-4 measured as low as 1.03:1 in dark mode. They are the
       design system's own quiet icon buttons now, whose colours are theme-aware tokens.

    3. NEITHER ACTION WAS PERMISSION-GATED, while `CategoryController::update` and `::destroy`
       both are (`Edit Product Category`, `Delete Product Category`). So a staff member holding
       only `Manage Product Category` was shown two controls that would refuse them. Gated to
       match the controller, and to match the pilot screen (`attribute_option/index`), which gates
       its own row actions. **This removes no functionality:** the handler already refused these
       users, so what disappears is a control that never worked for them. Verified by test in
       both directions rather than reasoned.

    WHAT IS DELIBERATELY UNCHANGED. `data-bs-toggle="tooltip"` and `title` stay — the tooltip is a
    sighted affordance worth keeping, and where both exist `aria-label` is what assistive
    technology announces. The `data-ajax-popup` contract, the routes, the DELETE form and the
    `.show_confirm` handler are untouched: this is a styling, labelling and gating change, not a
    behaviour one.

    ON THE NAME IN THE LABEL: Blade escapes `$category->name` into the attribute, so a
    merchant-written name cannot break out of it. The OTHER route the same value takes into this
    page — `CategoryDataTable`'s raw `parent_id` column — needed `e()` separately, because that
    path builds HTML by concatenation.
--}}
<span class="d-flex gap-1 justify-content-end">
    @permission('Edit Product Category')
        <button type="button"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-url="{{ route('category.edit', $category->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Edit Category') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Edit') }}"
            aria-label="{{ __('Edit category :name', ['name' => $category->name]) }}">
            <i class="ti ti-pencil" aria-hidden="true"></i>
        </button>
    @endpermission

    @permission('Delete Product Category')
        {!! Form::open([
            'method' => 'DELETE',
            'route' => ['category.destroy', $category->id],
            'class' => 'd-inline',
        ]) !!}
        <button type="button"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
            data-bs-placement="top" data-bs-toggle="tooltip"
            data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
            data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
            title="{{ __('Delete') }}"
            aria-label="{{ __('Delete category :name', ['name' => $category->name]) }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>
