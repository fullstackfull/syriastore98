{{Form::model($blogCategory, array('route' => array('blog-category.update', $blogCategory->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}

@if (isset(auth()->user()->currentPlan) && auth()->user()->currentPlan->enable_chatgpt == 'on')
<div class="d-flex justify-content-end mb-1">
    <a href="#" class="btn btn-primary me-2 ai-btn btn-badge" data-size="lg" data-ajax-popup-over="true" data-url="{{ route('generate',['category']) }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Generate') }}" data-title="{{ __('Generate Content With AI') }}">
        <i class="fas fa-robot"></i> {{ __('Generate with AI') }}
    </a>
</div>
@endif

<div class="row">
    <div class="form-group  col-md-12">
        {!! Form::label('title', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('name', null, ['class' => 'form-control', 'id' => 'title']) !!}
    </div>

    <div class="form-group col-md-12">
        {!! Form::label('status', __('Status'), ['class' => 'form-label']) !!}
        <div class="form-check form-switch">
            <input type="hidden" name="status" value="0">
            {!! Form::checkbox('status', 1, null, [
                'class' => 'form-check-input status',
                'id' => 'status',
            ]) !!}
            <label class="form-check-label" for="status"></label>
        </div>
    </div>


    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}
