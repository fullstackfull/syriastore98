{{ Form::open(array('route' => 'features_store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('other_features_heading', __('Heading'), ['class' => 'form-label']) }}
                {{ Form::text('other_features_heading',null, ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'other_features_heading']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('other_featured_description', __('Description'), ['class' => 'form-label']) }}
                {{ Form::textarea('other_featured_description', null, ['class' => 'summernote-simple form-control', 'placeholder' => __('Enter Description'), 'id' => 'other_featured_description']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('other_feature_buy_now_link', __('Buy Now Link'), ['class' => 'form-label']) }}
                {{ Form::text('other_feature_buy_now_link', null, ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'other_feature_buy_now_link']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('other_features_image', __('Image'), ['class' => 'form-label']) }}
                <input type="file" name="other_features_image" id="other_features_image" class="form-control" required="required">
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
