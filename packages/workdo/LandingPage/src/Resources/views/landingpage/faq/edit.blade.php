{{Form::model(null, array('route' => array('faq_update', $key), 'method' => 'POST','enctype' => "multipart/form-data")) }}
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('faq_questions', __('Questions'), ['class' => 'form-label']) }}
                {{ Form::text('faq_questions',$faq['faq_questions'], ['class' => 'form-control ', 'placeholder' => __('Enter Questions'), 'required', 'id' => 'faq_questions']) }}
            </div>
        </div>

        <div class="col-md-12">
            <div class="form-group">
                {{ Form::label('faq_answer', __('Answer'), ['class' => 'form-label']) }}
                {{ Form::textarea('faq_answer', $faq['faq_answer'], ['class' => 'summernote-simple form-control', 'placeholder' => __('Enter Answer'), 'required', 'id' => 'faq_answer']) }}
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
