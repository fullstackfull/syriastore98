{{--
    P2-05-4 — the create-attribute form, on the design system.

    A styling change only, and small: the field keeps its name, its `required`, its route and the
    ajax-popup contract, and the label already named its input correctly (unlike the category
    forms beside it, where every `Form::label('', …)` rendered an empty `for`). What changes is
    `form-control` -> `ss-input`, `form-label` -> `ss-label`, the wrapper -> `ss-field`, and the
    two footer buttons -> the design system's, so this modal matches the pilot screen it is
    opened from (`attribute_option/index`) instead of the vendored theme.
--}}
{{-- P2-05-4. `data-ss-theme="light"` IS NOT A STYLE PREFERENCE, it names the surface this form
     is actually drawn on. The host's Bootstrap modal keeps a WHITE `.modal-content` even when
     the admin is in dark mode -- measured `rgb(255, 255, 255)` with `data-ss-theme="dark"` on
     <html> -- so design-system components inside it would otherwise resolve their DARK tokens
     against white. The hint text below measured 2.18:1 that way, in both English and Arabic,
     against the 4.5:1 this slice is accepted on. Scoped here rather than by making the DS follow
     the host modal globally, because the modal's colour is the host's to decide and 70 vendored
     modules share it. --}}
<div data-ss-theme="light">
{{ Form::open(['route' => 'product-attributes.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}

<div class="row">
    <div class="ss-field col-md-12">
        {{ Form::label('name', __('Name'), ['class' => 'ss-label']) }}
        {{ Form::text('name', old('name'), ['class' => 'ss-input font-style', 'required' => 'required']) }}
    </div>
    <div class="ss-dialog__footer modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
