
{{Form::model($question, array('route' => array('product-question.update', $question->id), 'method' => 'PUT', 'enctype' => 'multipart/form-data')) }}
    <div class="form-group col-md-12">
        {{-- An explicit id rather than relying on the field name: the POSTED name is the
             mixed-case `Product` (and the control is `disabled`, so it posts nothing at all),
             and `for="Product"` reads as a mistake. The name is untouched. --}}
        {!! Form::label('product_display', __('Product'), ['class' => 'form-label']) !!}
        {!! Form::text('Product', $question->product->name, ['class' => 'form-control', 'id' => 'product_display', 'disabled']) !!}
    </div>
    <div class="form-group">
        <div class="form-group row">
            <div class="form-group col-md-12">
                {!! Form::label('question', __('Question'), ['class' => 'form-label']) !!}
                {!! Form::text('question', null, ['class' => 'form-control']) !!}
            </div>
            <div class="col-md-12">
                {!! Form::label('answers', __('Answer'), ['class' => 'form-label']) !!}
                {!! Form::textarea('answers', null, [ 'rows' => 4, 'class'=>'form-control' ]) !!}

            </div>
        </div>
    </div>
<div class="modal-footer pb-0">
    <input type="button" value="{{__('Cancel')}}" class="btn btn-badge btn-secondary" data-bs-dismiss="modal">
    <input type="submit" value="{{__('Update')}}" class="btn btn-badge btn-primary mx-1">
</div>
{!! Form::close() !!}


