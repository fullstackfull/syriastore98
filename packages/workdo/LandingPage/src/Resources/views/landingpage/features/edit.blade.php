{{Form::model(null, array('route' => array('feature_update', $key), 'method' => 'POST','enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('feature_heading',$feature['feature_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'feature_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('feature_description', $feature['feature_description'], ['class' => 'summernote-simple form-control', 'placeholder' => __('Enter Description'), 'id' => 'feature_description']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_logo', __('Logo'), ['class' => 'form-label']) }}
                <input type="file" id="feature_logo" name="feature_logo" class="form-control">
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
{{ Form::close() }}

<script>
    tinymce.init({
      selector: '#mytextarea',
      menubar: '',
    });
</script>
