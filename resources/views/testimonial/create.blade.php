{{ Form::open(['route' => 'testimonial.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">

    <div class="form-group  col-md-12">
        {!! Form::label('title', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('title', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
        {!! Form::textarea('description', null, ['class' => 'form-control autogrow', 'rows' => '3']) !!}
    </div>
    <div class="form-group  col-md-6">
        {{-- P2.5-WAVE-25: the label sat BESIDE the select without a `for`, so it named nothing.
             The select already carried `id="category_id"`. --}}
        <label class="form-label" for="category_id">{{ __('Category') }}</label><span class="text-danger">*</span>
        <select name="category_id" class="form-control" data-role="tagsinput" id="category_id">
            <option value="">{{ __('Select Category') }}</option>
            @foreach ($categoryTree as $category)
                <option value="{{ $category['id'] }}">{!! $category['name'] !!}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group  col-md-6 product_id_div" data_val='0'>
        {{-- `product_id`, not `product-dropdown`. TestimonialController::get_product() answers
             `{status, html}` whose html carries `id="product_id"`, and index.blade.php replaces
             this span's innerHTML with it the first time a category is chosen -- so a `for`
             pointing at `product-dropdown` would dangle from that moment on. testimonial/edit,
             wishlist/create and wishlist/edit all already use `product_id`; this was the outlier.
             The shared get.product contract is untouched. --}}
        {!! Form::label('product_id', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', [], null, ['class' => 'form-control', 'data-role' => 'tagsinput', 'id' => 'product_id']) !!}
        </span>
    </div>
    <div class="form-group  col-md-6">
        {!! Form::label('rating_no', __('Rating'), ['class' => 'form-label']) !!}
        {!! Form::select('rating_no', ['1' => 1,'2' => 2,'3' => 3,'4' => 4,'5' => 5,], null, ['class' => 'form-control', 'data-role' => 'tagsinput', 'id' => 'rating_no']) !!}
    </div>

    <div class="form-group col-md-6">
        {!! Form::label('username', __('User Name'), ['class' => 'form-label']) !!}
        {!! Form::text('username', null, ['class' => 'form-control', 'id' => 'username']) !!}
    </div>

    <div class="form-group col-md-6">
        {!! Form::label('upload_avatar', __('Avatar'), ['class' => 'form-label']) !!}

        <div class="row">
            <div class="col-md-12">
            <label for="upload_avatar">
                <div class="image-upload bg-primary pointer w-100 logo_update"> <i
                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                </div>
                <input type="file" class="form-control file ss-visually-hidden"
                    name="avatar" id="upload_avatar"
                    data-filename="logo_update"
                    onchange="document.getElementById('avatarImage').src = window.URL.createObjectURL(this.files[0])">
            </label>
            </div>
            <div class="logo-content mt-3 col-md-12">
                    <img src="#"
                        class="big-logo invoice_logo img_setting" id="avatarImage" width="200px">
            </div>
        </div>

    </div>

    <div class="form-group col-md-4">
        {{-- Caption inside the already-associated label -- see category/create.blade.php:132.
             It was an empty `.form-check-label` beside a `Form::label('', …)` rendering
             `for=""`, so the switch had no accessible name from any source. --}}
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" name="status" class="form-check-input input-primary" id="customCheckdef1" value="1"
                checked>
            <label class="form-check-label" for="customCheckdef1">{{ __('Status') }}</label>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
