{{ Form::open(['route' => 'product-brand.store', 'method' => 'post', 'enctype' => 'multipart/form-data', 'id'=> 'uploadForm']) }}

<div class="row">
    <div class="form-group col-md-12">
        {!! Form::label('name', __('Name'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'required' => 'true', 'id' => 'name', 'placeholder' => 'Enter Brand Name']) !!}
        <span id="nameError" style="color: red; display: none;">{{ __('This field is required.') }}</span>
    </div>
    <div class="form-group col-md-12">
        <div class="row">
            <div class="col-md-6">
            {{-- The caption is a SIBLING above the wrapping label, never inside it: a <label>
                 nested in a <label> is invalid per the HTML label content model, and
                 `Form::label('', …)` emitted `for=""` besides. Same shape as the shipped
                 category/create.blade.php:77. --}}
            {!! Form::label('brand_logo', __('Image'), ['class' => 'form-label']) !!}
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

    <div class="form-group col-md-6">
        {{-- The caption lives INSIDE the label that is already associated with the control,
             which is the shape category/create.blade.php:123 and :132 already ship. The two
             used to sit side by side: a `Form::label('', …)` caption rendering `for=""`, and
             an EMPTY `.form-check-label` -- so the switch's accessible name was the empty
             string from every source. One label per control, and the caption is clickable.
             The `d-flex align-items-center gap-2` wrapper went with the caption it seated. --}}
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" class="form-check-input status mb-1" name="status" id="status" value="1">
            <label class="form-check-label" for="status">{{ __('Status') }}</label>
        </div>
    </div>
    <div class="form-group col-md-6">
        <div class="form-check form-switch">
            <input type="hidden" name="is_popular" value="0">
            <input type="checkbox" name="is_popular" class="form-check-input input-primary mb-1" id="customCheckdef1trending" value="1">
            <label class="form-check-label" for="customCheckdef1trending">{{ __('Is Popular ?') }}</label>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

