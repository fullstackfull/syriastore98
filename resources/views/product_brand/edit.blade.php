{{-- P2.5 OWNER VISUAL REVIEW, 2026-11-12 -- item 1, the edit half.

     Converted with create.blade.php and for the same measured reason: the Name field rendered a
     dark navy input inside the host's deliberately-white modal. The mapping and the reason for
     the `data-ss-theme="light"` wrapper are recorded at the head of create.blade.php and, at
     length, in category/create.blade.php:28 and :45.

     `Form::model()` and its route, method and enctype are untouched. --}}
<div data-ss-theme="light">
 {{Form::model($productBrand, array('route' => array('product-brand.update', $productBrand->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}

<div class="row">
<div class="form-group  col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'ss-label']) !!}
        {!! Form::text('name', null, ['class' => 'ss-input', 'required' => 'true', 'id' => 'name', 'placeholder' => __('Enter Brand Name')]) !!}
    </div>
    <div class="ss-field col-md-12">
        <div class="row">
            <div class="col-md-6">
            {{-- Caption as a SIBLING above the wrapping label — see create.blade.php. --}}
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
                <img src="{{ get_file($productBrand->logo) }}"
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
            {!! Form::checkbox('status', 1, null, [
                'class' => 'form-check-input status mb-1',
                'id' => 'status',
            ]) !!}
            <label class="ss-label form-check-label" for="status">{{ __('Status') }}</label>
        </div>
    </div>

    {{-- The same translation key create uses. This form asked for the question-mark-less
         variant, whose Arabic in ar.json is the weaker 'هو شعبية' against this key's
         'هل هو مشهور ؟' -- one field announced two different ways on create and edit. --}}
    <div class="ss-field col-md-6">
        <div class="form-check form-switch">
            <input type="hidden" name="is_popular" value="0">
            {!! Form::checkbox("is_popular", 1, null, ["class" => "form-check-input input-primary mb-1", "id"=>"customCheckdef1popular"]) !!}
            <label class="ss-label form-check-label" for="customCheckdef1popular">{{ __('Is Popular ?') }}</label>
        </div>
    </div>
    <div class="ss-dialog__footer modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="ss-btn ss-btn--secondary ss-btn--sm" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="ss-btn ss-btn--primary ss-btn--sm mx-1">
    </div>
</div>
{!! Form::close() !!}
</div>
