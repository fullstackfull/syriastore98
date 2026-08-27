{{ Form::open(array('route' => 'feature_store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('feature_heading',null, ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'feature_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('feature_description', null, ['class' => 'summernote-simple form-control', 'placeholder' => __('Enter Description'), 'id' => 'feature_description']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('feature_logo', __('Logo'), ['class' => 'form-label']) }}
                <input type="file" id="feature_logo" name="feature_logo" class="form-control" required="required">
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-primary btn-badge mx-1">
    </div>
{{ Form::close() }}

<script>
    tinymce.init({
      selector: '#mytextarea',
      menubar: '',
    });
</script>
