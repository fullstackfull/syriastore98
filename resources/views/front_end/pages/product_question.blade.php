{{Form::model($slug, array('route' => array('product_question' ,$slug), 'method' => 'POST', 'enctype' => 'multipart/form-data', 'class' => 'space-y-4')) }}

    <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
        {!! Form::label('', __('Question'), ['class' => 'block mb-2 font-medium md:text-base text-sm']) !!}
        {!! Form::textarea('question', null, ['class' => 'form-input','placeholder' => __("Write your question here...") ,'rows' => "3"]) !!}
    </div>

    <input type="hidden" name="product_id" value="{{ $id }}">

    <div class="flex flex-wrap gap-4">
        <button type="submit" class="btn-primary">
            {{ __('Submit') }}
        </button>
        <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300">
            {{ __('Cancel') }}
        </button>
    </div>
{!! Form::close() !!}
