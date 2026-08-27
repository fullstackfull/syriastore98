{{ Form::open(['route' => 'product-review.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
<div class="row">

    <div class="form-group  col-md-12">
        {!! Form::label('title', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('title', null, ['class' => 'form-control', 'id' => 'title']) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
        {!! Form::textarea('description', null, ['class' => 'form-control autogrow', 'id' => 'description', 'rows' => '3']) !!}
    </div>
    <div class="form-group  col-md-6">
        <label class="form-label">{{ __('Category') }}</label><span class="text-danger">*</span>
        <select name="category_id" class="form-control" data-role="tagsinput" id="category_id">
            <option value="">{{ __('Select Category') }}</option>
            @foreach ($categoryTree as $category)
                <option value="{{ $category['id'] }}">{!! $category['name'] !!}</option>
            @endforeach
        </select>
    </div>
    <div class="form-group  col-md-6 product_id_div" data_val='0'>
        {!! Form::label('product-dropdown', __('Product'), ['class' => 'form-label']) !!}
        <span>
            {!! Form::select('product_id', [], null, ['class' => 'form-control', 'id' => 'title', 'data-role' => 'tagsinput', 'id' => 'product-dropdown']) !!}
        </span>
    </div>
    <div class="form-group  col-md-6">
        {!! Form::label('rating_no', __('Rating'), ['class' => 'form-label']) !!}
        {!! Form::select('rating_no', ['1' => 1,'2' => 2,'3' => 3,'4' => 4,'5' => 5,], null, ['class' => 'form-control', 'id' => 'title', 'data-role' => 'tagsinput', 'id' => 'rating_no']) !!}
    </div>

    <div class="form-group col-md-4">
        {!! Form::label('status', __('Status'), ['class' => 'form-label']) !!}
        <div class="form-check form-switch" id="status">
            <input type="hidden" name="status" value="0">
            <input type="checkbox" name="status" class="form-check-input input-primary" id="customCheckdef1" value="1">
            <label class="form-check-label" for="customCheckdef1"></label>
        </div>
    </div>

    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
