{{Form::model(null, array('route' => array('discover_update', $key), 'method' => 'POST','enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('discover_heading',$discover['discover_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'discover_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('discover_description', $discover['discover_description'], ['class' => 'summernote-simple form-control', 'placeholder' => __('Enter Description'), 'id' => 'discover_description']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_logo', __('Logo'), ['class' => 'form-label']) }}
                <input type="file" name="discover_logo" id="discover_logo" class="form-control">
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
