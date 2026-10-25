<span class="d-flex gap-1 justify-content-end">
{{-- P2-03-6: quick preview, beside the editor rather than instead of it. The panel is
     read-only; editing a product is a full-page job. --}}
<button type="button"
    data-ss-panel-open="ss-product-preview-panel"
    data-ss-panel-url="{{ route('product.preview', $product->id) }}"
    class="btn btn-sm btn-light-primary"
    data-bs-toggle="tooltip"
    title="{{ __('Quick preview') }}"
    aria-label="{{ __('Quick preview of :name', ['name' => $product->name]) }}">
    <i class="ti ti-layout-sidebar-right-expand" aria-hidden="true"></i>
</button>
<a href="{{ route('product.edit', $product->id) }}" class="btn btn-sm btn-info"
    data-title="{{ __('Edit Product') }}" data-bs-toggle="tooltip"
    title="{{ __('Edit') }}">
    <i class="ti ti-pencil"></i>
</a>
{!! Form::open([
    'method' => 'DELETE',
    'route' => ['product.destroy', $product->id],
    'class' => 'd-inline',
]) !!}
<button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
title="{{ __('Delete') }}">
    <i class="ti ti-trash"></i>
</button>
</span>
