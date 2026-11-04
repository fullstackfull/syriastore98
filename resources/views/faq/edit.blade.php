
{{Form::model($faq, array('route' => array('faqs.update', $faq->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div id="kt_docs_repeater_basic" >
    <div class="form-group col-md-12">
        {!! Form::label('topic', __('Topic'), ['class' => 'form-label']) !!}
        {!! Form::text('topic', null, ['class' => 'form-control']) !!}
    </div>
    <!--begin::Form group-->
    <div class="form-group">
        <div data-repeater-list="kt_docs_repeater_basic">
            @if(!empty($faq->description))
            @foreach (json_decode($faq->description, true) as $item)
                <div data-repeater-item>
                    <div class="form-group row">
                        <div class="col-md-12">
                            {{-- NO id-BASED ASSOCIATION IS POSSIBLE INSIDE THE REPEATER.
                                 public/js/repeater.js rewrites only `name` and contains no
                                 `attr('id')` handling at all, so any id inside
                                 `data-repeater-item` duplicates once per FAQ item at render and
                                 again per clone at runtime -- `id="answer"` already did. A caption
                                 <span> plus `aria-label` on the control needs no id, is carried by
                                 every clone and collides with nothing. `d-block` on the span
                                 because `.form-label`'s `margin-bottom` only applies to a
                                 non-inline box, and the caption was a <label>
                                 (`label { display: inline-block }`, style.css:445) until now. --}}
                            <span class="form-label d-block">{{ __('Question') }}</span>
                            {!! Form::text('question', $item['question'], ['class' => 'form-control', 'aria-label' => __('Question')]) !!}
                        </div>
                        <div class="col-md-12 mt-2">
                            <span class="form-label d-block">{{ __('Answer') }}</span>
                            {!! Form::textarea('answer', $item['answer'], ['rows' => 4, 'class'=>'form-control', 'aria-label' => __('Answer')]) !!}

                        </div>
                        <div class="col-md-4">
                            <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger mt-3 mt-md-8">
                                <i class="la la-trash-o"></i>{{__('Delete')}}
                            </a>
                        </div>
                    </div>
                </div>
            @endforeach
            @else
            <div data-repeater-item>
                <div class="form-group row">
                    <div class="col-md-12">
                        {{-- The @else branch — same shape as the @foreach above, and it has to be
                             done too: only one branch runs per request. --}}
                        <span class="form-label d-block">{{ __('Question') }}</span>
                        {!! Form::text('question', null, ['class' => 'form-control', 'aria-label' => __('Question')]) !!}
                    </div>
                    <div class="col-md-12">
                        <span class="form-label d-block">{{ __('Answer') }}</span>
                        {!! Form::textarea('answer', null, ['rows' => 4, 'class'=>'form-control', 'aria-label' => __('Answer')]) !!}

                    </div>
                    <div class="col-md-4">
                        <a href="javascript:;" data-repeater-delete class="btn btn-sm btn-light-danger mt-3 mt-md-8">
                            <i class="la la-trash-o"></i>{{__('Delete')}}
                        </a>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
    <!--end::Form group-->

    <!--begin::Form group-->
    <div class="form-group mt-5">
        <a href="javascript:;" data-repeater-create class="btn btn-light-primary">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    <!--end::Form group-->
</div>
<!--end::Repeater-->
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
</div>
{!! Form::close() !!}


<script src="{{asset('js/jquery-ui.min.js')}}"></script>
<script src="{{asset('js/repeater.js')}}"></script>


<script>
$('#kt_docs_repeater_basic').repeater({
    initEmpty: false,

    defaultValues: {
        'text-input': 'foo'
    },

    show: function () {
        $(this).slideDown();
    },

    hide: function (deleteElement) {
        $(this).slideUp(deleteElement);
    }
});
</script>

