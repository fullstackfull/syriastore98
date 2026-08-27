{{Form::model($donations, array('route' => array('donation.update', $donations->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
<div class="row">
    <div class="form-group  col-md-12">
        {!! Form::label('title', __('Title'), ['class' => 'form-label']) !!}
        {!! Form::text('title', null, ['class' => 'form-control','id' => 'title', 'placeholder' => __('Enter Title')]) !!}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('description', __('Description'), ['class' => 'form-label']) !!}
        {{ Form::textarea('description', null, ['class' => 'form-control  summernote-simple-product', 'rows' => 1, 'placeholder' => __('Donation Description'), 'id' => 'description']) }}
    </div>
    <div class="form-group  col-md-12">
        {!! Form::label('note', __('Note'), ['class' => 'form-label']) !!}
        {!! Form::text('note', null, ['class' => 'form-control','id' => 'note', 'placeholder' => __('Enter Note')]) !!}
    </div>
    <div class="form-group col-12" >
        <div id="amount_repeater_basic">
            <div class="form-group">
                {!! Form::label('answer', __('Amount'), ['class' => 'form-label']) !!}
                <div data-repeater-list="amount_repeater_basic">
                    @if (!empty($donations->amount))
                        @foreach (json_decode($donations->amount, true) as $item)
                            <div data-repeater-item>
                                <div class="form-group row">
                                    <div class="col-md-10">
                                        {!! Form::text('amount', $item['amount'], ['id' => 'answer', 'rows' => 2, 'class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Amount')]) !!}
                                    </div>
                                    <div class="col-md-2">
                                        <a href="javascript:;" data-repeater-delete
                                            class="btn btn-sm btn-light-danger mt-2 mt-md-8" data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                            <i class="ti ti-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @else
                        <div data-repeater-item>
                            <div class="form-group row">
                                <div class="col-md-10">
                                    {!! Form::text('amount', null, ['id' => 'answer', 'rows' => 2, 'class' => 'form-control','required'=>'required', 'placeholder' => __('Enter Amount')]) !!}
                                </div>
                                <div class="col-md-2">
                                    <a href="javascript:;" data-repeater-delete
                                        class="btn btn-sm btn-light-danger mt-2 mt-md-8" data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                        <i class="ti ti-trash"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
                <div class="form-group mt-2 mb-0">
                    <a href="javascript:;" data-repeater-create class="btn btn-light-primary">
                        <i class="ti ti-plus"></i>
                    </a>
                </div>
        </div>
    </div>
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
        <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
    </div>
</div>
{!! Form::close() !!}

<script src="{{ asset('js/jquery-ui.min.js') }}"></script>
<script src="{{ asset('js/repeater.js') }}"></script>
<script>
    $('#amount_repeater_basic').repeater({
        initEmpty: false,
        defaultValues: {
            'text-input': 'foo'
        },

        show: function() {
            $(this).slideDown();
        },

        hide: function(deleteElement) {
            $(this).slideUp(deleteElement);
        }
    });
</script>
