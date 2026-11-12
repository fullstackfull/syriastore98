{{-- P2.5 OWNER VISUAL REVIEW, 2026-11-12 -- item 1: "make dark theme styling consistent
     across the modal".

     MEASURED on /product-brand in dark mode, both languages: the Name field rendered
     rgb(41,50,64) -- a dark navy input sitting in a WHITE dialog, with every label, switch and
     button around it light. Nothing failed a contrast check (check-dark-form-surfaces-live.cjs
     reports 20 checks, 0 failed, and reported the same BEFORE this change), which is exactly why
     it survived: the field is legible, it just does not belong to the dialog it is in.

     THE CAUSE IS THAT THIS MODAL WAS NEVER CONVERTED, and the fix is the conversion the sibling
     already documents rather than a new rule. `category/create.blade.php:28` records the mapping
     -- `form-control` -> `ss-input`, `form-label` -> `ss-label`, the wrappers -> `ss-field`, the
     footer buttons -> the design system's -- and :45 records WHY the whole form is wrapped in
     `data-ss-theme="light"`: the host's Bootstrap modal keeps a white `.modal-content` even in
     dark mode, so design-system components inside it would otherwise resolve their DARK tokens
     against white. That white modal is P2.5-A-01's accepted premise and the live checker asserts
     it, so the dialog is NOT being made dark here; the field is being made to match the dialog.

     Routes, field names, ids, `enctype`, the `logo_update` upload hook and its `onchange`, the
     hidden `0` companion of the status switch and the ajax-popup contract are untouched. The
     file input keeps `form-control` because it is `ss-visually-hidden` and never painted. --}}
<div data-ss-theme="light">
{{ Form::open(['route' => 'product-brand.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'uploadForm']) }}

<div class="row">
    <div class="ss-field col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'ss-label']) !!}
        {!! Form::text('name', null, ['class' => 'ss-input', 'required' => 'true', 'id' => 'name', 'placeholder' => __('Enter Brand Name')]) !!}
        <span id="nameError" style="color: red; display: none;">{{ __('This field is required.') }}</span>
    </div>
    <div class="ss-field col-md-12">
        <div class="row">
            <div class="col-md-6">
            {{-- The caption is a SIBLING above the wrapping label, never inside it: a <label>
                 nested in a <label> is invalid per the HTML label content model, and
                 `Form::label('', …)` emitted `for=""` besides. Same shape as the shipped
                 category/create.blade.php:77. --}}
            {!! Form::label('brand_logo', __('Image'), ['class' => 'ss-label']) !!}
            <label for="brand_logo">
                <div class="image-upload bg-primary pointer w-100 logo_update"> <i
                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                </div>
                <input type="file" class="form-control file ss-visually-hidden"
                    name="logo" id="brand_logo"
                    data-filename="logo_update"
                    onchange="document.getElementById('brandLogo').src = window.URL.createObjectURL(this.files[0])">
            </label>
            </div>
            <div class="logo-content mt-3 col-md-6">
                    <img src="#"
                        class="big-logo invoice_logo img_setting" id="brandLogo" width="200px">
            </div>
        </div>
    </div>

    <div class="ss-field col-md-6">
        {{-- The caption lives INSIDE the label that is already associated with the control,
             which is the shape category/create.blade.php:123 and :132 already ship. The two
             used to sit side by side: a `Form::label('', …)` caption rendering `for=""`, and
             an EMPTY `.form-check-label` -- so the switch's accessible name was the empty
             string from every source. One label per control, and the caption is clickable.
             The `d-flex align-items-center gap-2` wrapper went with the caption it seated. --}}
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" class="form-check-input status mb-1" name="status" id="status" value="1">
            <label class="ss-label form-check-label" for="status">{{ __('Status') }}</label>
        </div>
    </div>
    <div class="ss-field col-md-6">
        <div class="form-check form-switch">
            <input type="hidden" name="is_popular" value="0">
            <input type="checkbox" name="is_popular" class="form-check-input input-primary mb-1" id="customCheckdef1trending" value="1">
            <label class="ss-label form-check-label" for="customCheckdef1trending">{{ __('Is Popular ?') }}</label>
        </div>
    </div>
    <div class="ss-dialog__footer modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
