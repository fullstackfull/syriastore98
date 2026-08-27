{{ Form::open(array('route' => 'faq_store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('faq_questions', __('Question'), ['class' => 'form-label']) }}
                {{ Form::text('faq_questions',null, ['class' => 'form-control ', 'id' => 'faq_questions', 'placeholder' => __('Enter Question'), 'required']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('faq_answer', __('Answer'), ['class' => 'form-label']) }}
                {{ Form::textarea('faq_answer', null, ['class' => 'form-control summernote-simple', 'placeholder' => __('Enter Answer'), 'required', 'id' => 'faq_answer']) }}
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
