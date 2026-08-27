{{ Form::open(array('route' => 'discover_store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('discover_heading',null, ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'discover_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('discover_description', null, ['class' => 'summernote-simple form-control', 'id' => 'discover_description', 'placeholder' => __('Enter Description')]) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('discover_logo', __('Logo'), ['class' => 'form-label']) }}
                <input type="file" name="discover_logo" id="discover_logo" class="form-control" required="required">
            </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Create')}}" class="btn btn-badge btn-primary mx-1">
    </div>
{{ Form::close() }}
<script>
    tinymce.init({
      selector: '#mytextarea',
      menubar: '',
    });
</script>
