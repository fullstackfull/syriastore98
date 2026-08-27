{{Form::model($Testimonial, array('route' => array('testimonial.update', $Testimonial->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div class="row">

    <div class="form-group  col-md-12">
        {!! Form::label('', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('title', null, ['class' => 'form-control']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('', __('Description'), ['class' => 'form-label']) !!}
        {!! Form::textarea('description', null, ['class' => 'form-control autogrow', 'rows' => '3']) !!}
    </div>
    <div class="form-group  col-md-6">
        <label class="form-label">{{ __('Category') }}</label><span class="text-danger">*</span>
        <select name="category_id" class="form-control" data-role="tagsinput" id="category_id">
            <option value="">{{ __('Select Category') }}</option>
            @foreach ($categoryTree as $cat)
                <option value="{{ $cat['id'] }}" {{ $cat['id'] ==  $Testimonial->category_id ? 'selected' : ''}}>
                    {!! $cat['name'] !!}
                </option>
            @endforeach
        </select>
    </div>
    <div class="form-group  col-md-6 product_id_div" data_val='{{ $Testimonial->product_id }}'>
        {!! Form::label('', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', $product, null, ['class' => 'form-control', 'data-role' => 'tagsinput', 'id' => 'product_id']) !!}
        </span>
    </div>
    <div class="form-group  col-md-6">
        {!! Form::label('', __('Rating'), ['class' => 'form-label']) !!}
        {!! Form::select('rating_no', ['1' => 1,'2' => 2,'3' => 3,'4' => 4,'5' => 5,], null, ['class' => 'form-control', 'data-role' => 'tagsinput', 'id' => 'rating_no']) !!}
    </div>

    <div class="form-group  col-md-6">
        {!! Form::label('username', __('User Name'), ['class' => 'form-label']) !!}
        {!! Form::text('username', null, ['class' => 'form-control', 'id'=> 'username']) !!}
    </div>

     <div class="form-group col-md-6">
        {!! Form::label('', __('Avatar'), ['class' => 'form-label']) !!}

        <div class="row">
            <div class="col-md-12">
            <label for="upload_avatar">
                <div class="image-upload bg-primary pointer w-100 avatar_update"> <i
                        class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                </div>
                <input type="file" class="form-control file d-none"
                    name="avatar" id="upload_avatar"
                    data-filename="avatar_update"
                    onchange="document.getElementById('avatarImage').src = window.URL.createObjectURL(this.files[0])">
            </label>
            </div>
            <div class="logo-content mt-3 col-md-12">
                    <img src="{{ get_file($Testimonial->avatar) ?? '#' }}"
                        class="big-logo invoice_logo img_setting" id="avatarImage" width="200px">
            </div>
        </div>

    </div>

    <div class="form-group col-md-4">
        {!! Form::label('', __('Status'), ['class' => 'form-label']) !!}
        <div class="form-check form-switch">
            <input type="checkbox" name="status" class="form-check-input input-primary" id="customCheckdef1" value="1" @if($Testimonial->status == '1') checked @endif>
            <label class="form-check-label" for="customCheckdef1"></label>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
