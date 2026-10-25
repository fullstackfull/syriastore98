{{--
    P2-03 — the product list's row actions, on the design system.

    THE SAME THREE DEFECTS THE ORDER LIST'S ACTIONS HAD, found the same way and fixed the same
    way; `order/action.blade.php` carries the full measurements. In short: `title` is not an
    accessible name once `data-bs-toggle="tooltip"` moves it into `data-bs-original-title`, so
    Edit and Delete were unnamed controls wrapping unlabelled `<i>`s; `btn-info`'s pencil
    measured 1.09:1 in dark mode and 2.52:1 in light, under the 3:1 non-text minimum; and the
    quick-preview button asked for an icon name the bundled font does not have, so it painted
    nothing at all.

    AND A FOURTH, ONLY HERE: `Form::open()` WAS NEVER CLOSED. The delete form opened in this
    cell and no `Form::close()` followed it, so the `</form>` the markup needs was never
    written. The browser recovers by closing it at the end of the enclosing element, which means
    the form's extent was whatever the surrounding markup happened to allow rather than the one
    button it is for. Nothing visibly broke, which is why it survived.

    The JavaScript hooks are behaviour and stay exactly as they were: `.show_confirm` (delegated
    in `public/js/custom.js`, so it survives a DataTables redraw) and the `data-ss-panel-*`
    attributes the preview panel reads.
--}}
<span class="d-flex gap-1 justify-content-end">
{{-- P2-03-6: quick preview, beside the editor rather than instead of it. The panel is
     read-only; editing a product is a full-page job. --}}
<button type="button"
    data-ss-panel-open="ss-product-preview-panel"
    data-ss-panel-url="{{ route('product.preview', $product->id) }}"
    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
    data-bs-toggle="tooltip"
    title="{{ __('Quick preview') }}"
    aria-label="{{ __('Quick preview of :name', ['name' => $product->name]) }}">
    {{-- `ti-layout-sidebar-right`, NOT `…-right-expand`: the bundled icon font has no
         `-expand` variant, so this button painted no glyph at all. Same mistake as the order
         list's; ratcheted by Tests\Unit\Ui\IconNamesExistTest. --}}
    <i class="ti ti-layout-sidebar-right" aria-hidden="true"></i>
</button>
<a href="{{ route('product.edit', $product->id) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
    data-title="{{ __('Edit Product') }}" data-bs-toggle="tooltip"
    title="{{ __('Edit') }}"
    aria-label="{{ __('Edit :name', ['name' => $product->name]) }}">
    <i class="ti ti-pencil" aria-hidden="true"></i>
</a>
{!! Form::open([
    'method' => 'DELETE',
    'route' => ['product.destroy', $product->id],
    'class' => 'd-inline',
]) !!}
<button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm"
data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
title="{{ __('Delete') }}"
aria-label="{{ __('Delete :name', ['name' => $product->name]) }}">
    <i class="ti ti-trash" aria-hidden="true"></i>
</button>
{!! Form::close() !!}
</span>
