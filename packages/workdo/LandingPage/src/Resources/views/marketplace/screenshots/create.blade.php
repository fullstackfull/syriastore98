{{ Form::open(array('route' => array('marketplace_screenshots_store',$slug), 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    <div class="">
        @csrf
        <div class="row">
            <div class="col-md-12">
                <div class="form-group">
                    {{ Form::label('screenshots_heading', __('Heading'), ['class' => 'form-label']) }}
                    {{ Form::text('screenshots_heading',null, ['class' => 'form-control ', 'placeholder' => __('Enter Heading'),'required'=>'required', 'id' => 'screenshots_heading']) }}
                </div>
            </div>

            <div class="col-md-12">
                <div class="form-group">
                    {{ Form::label('screenshots', __('Screenshots'), ['class' => 'form-label']) }}
                    <input type="file" name="screenshots" class="form-control" id="screenshots" required>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
{{ Form::close() }}
