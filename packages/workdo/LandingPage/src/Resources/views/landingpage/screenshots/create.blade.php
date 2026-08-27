{{ Form::open(array('route' => 'screenshots_store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('screenshots_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('screenshots_heading',null, ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'screenshots_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('screenshots', __('Screenshots'), ['class' => 'form-label']) }}
                <input type="file" name="screenshots" id="screenshots" class="form-control" required="required">
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-primary btn-badge mx-1">
    </div>
{{ Form::close() }}
