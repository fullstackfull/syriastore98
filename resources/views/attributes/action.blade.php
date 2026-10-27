{{--
    P2-05-4 — the attribute list's row actions, on the design system.

    The same three defects as the category list beside it, and the same fixes: an `aria-label`
    naming the ATTRIBUTE as well as the verb (both controls were unnamed at runtime, because
    Bootstrap's tooltip blanks the `title` it initialises on); the design system's quiet icon
    buttons instead of the vendored theme's `btn-info` / `btn-danger`; and the permission gates
    that `ProductAttributeController::update` and `::destroy` already enforce
    (`Edit Attributes`, `Delete Attributes`), which the markup did not.

    The pilot screen for this whole area — `attribute_option/index.blade.php` — already gates its
    row actions this way, so this brings the parent list into line with the reference rather than
    inventing a convention. Gating removes no functionality: the handler already refused a user
    without the permission, so what disappears is a control that never worked for them.

    `data-ajax-popup`, the routes, the DELETE form and `.show_confirm` are untouched.
--}}
<span class="d-flex gap-1 justify-content-end">
    @permission('Edit Attributes')
        <button type="button"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
            data-url="{{ route('product-attributes.edit', $attribute->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Edit Attribute') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Edit') }}"
            aria-label="{{ __('Edit attribute :name', ['name' => $attribute->name]) }}">
            <i class="ti ti-pencil" aria-hidden="true"></i>
        </button>
    @endpermission

    @permission('Delete Attributes')
        {!! Form::open([
            'method' => 'DELETE',
            'route' => ['product-attributes.destroy', $attribute->id],
            'class' => 'd-inline',
        ]) !!}
        <button type="button"
            class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
            data-bs-placement="top" data-bs-toggle="tooltip"
            data-confirm="{{ __('Are You Sure?') }}"
            data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
            data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
            title="{{ __('Delete') }}"
            aria-label="{{ __('Delete attribute :name', ['name' => $attribute->name]) }}">
            <i class="ti ti-trash" aria-hidden="true"></i>
        </button>
        {!! Form::close() !!}
    @endpermission
</span>
