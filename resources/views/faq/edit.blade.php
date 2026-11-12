
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
                            {{-- P2.5 -- A MISSING KEY WAS A 500, NOT A BLANK FIELD.
                                 `description` is free-form JSON, and this view read
                                 `$item['question']` / `$item['answer']` unguarded. A row whose
                                 JSON carries only one of the two raises "Undefined array key",
                                 which Laravel turns into an ErrorException -- so the edit modal
                                 answered HTTP 500 and opened empty.

                                 MEASURED on a row with no `answer` key: two warnings at render
                                 and `GET /faqs/{id}/edit` -> 500, twice, with the modal showing
                                 its title and nothing else. Production ships NO faq rows at all
                                 (0 INSERTs in the dump), so this is a fail-safe rather than a
                                 live repair -- but an import or an older row reaches it, and a
                                 blank field a merchant can fill is the right answer to a key
                                 that is not there. --}}
                            {!! Form::text('question', $item['question'] ?? '', ['class' => 'form-control', 'aria-label' => __('Question')]) !!}
                        </div>
                        <div class="col-md-12 mt-2">
                            <span class="form-label d-block">{{ __('Answer') }}</span>
                            {!! Form::textarea('answer', $item['answer'] ?? '', ['rows' => 4, 'class'=>'form-control', 'aria-label' => __('Answer')]) !!}

                        </div>
                        <div class="col-md-4">
                            {{-- P2.5 contrast -- THE FILLED DANGER BUTTON, AND NOT THE QUIET ONE.
                                 Measured, in order:
                                   btn btn-sm btn-light-danger     2.66:1   all four combinations
                                   ss-btn--danger-quiet            ok light, 1.91:1 DARK
                                   ss-btn--danger                  0 findings
                                 The quiet variant paints `color: var(--ss-danger)`, which is
                                 `#b3261e` in a light scope and `#f3a9a4` in a dark one -- and this
                                 modal's surface stays WHITE in dark mode (the F126 condition the
                                 design sheet records). A light-pink glyph on white is the 1.91.
                                 `.ss-btn--danger` pairs `--ss-danger` with `--ss-on-danger`, so its
                                 foreground and background flip TOGETHER and it reads on either
                                 surface. Do not "simplify" this back to the quiet variant. --}}
                            <a href="javascript:;" data-repeater-delete class="ss-btn ss-btn--sm ss-btn--danger mt-3 mt-md-8">
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
                        <a href="javascript:;" data-repeater-delete class="ss-btn ss-btn--sm ss-btn--danger mt-3 mt-md-8">
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

