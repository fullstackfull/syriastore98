{{ Form::model($slug, [
    'route' => ['product_review.store', $slug],
    'method' => 'POST',
    'enctype' => 'multipart/form-data',
    'class' => 'space-y-6'
]) }}

<!-- Title -->
<div>
    {!! Form::label('title', __('Title'), ['class' => 'block text-sm font-medium text-gray-700 mb-1']) !!}
    {!! Form::text('title', null, [
        'class' => 'w-full px-3 py-2 border border-[var(--border-color)] rounded-md focus:ring-2 focus:ring-[var(--primary-color)] focus:outline-none text-sm transition'
    ]) !!}
</div>

<!-- Rating -->
<div>
    {!! Form::label('Rating', __('Rating'), ['class' => 'block text-sm font-medium text-gray-700 mb-1']) !!}
    <ul id="stars" class="flex space-x-1 text-2xl text-[var(--border-color)] cursor-pointer">
        @for ($i = 1; $i <= 5; $i++)
            <li class="star hover:text-[var(--accent-color)] transition" data-value="{{ $i }}">
                <i class="fa fa-star"></i>
            </li>
        @endfor
    </ul>
</div>

<!-- Description -->
<div>
    {!! Form::label('description', __('Description'), ['class' => 'block text-sm font-medium text-gray-700 mb-1']) !!}
    {!! Form::textarea('description', null, [
        'class' => 'w-full px-3 py-2 border border-[var(--border-color)] rounded-md focus:ring-2 focus:ring-[var(--primary-color)] focus:outline-none resize-none text-sm transition',
        'rows' => 3
    ]) !!}
</div>

<!-- Hidden Fields -->
<input type="hidden" name="product_id" value="{{ $product->id ?? null }}">
<input type="hidden" name="category_id" value="{{ $product->category_id ?? null }}">
<input type="hidden" name="store_id" value="{{ $product->store_id ?? null }}">
<input type="hidden" name="status" value="1">
<input type="hidden" name="rating_no" id="rating_no" value="0">

<!-- Submit Button -->
<div class="text-right pt-4">
    <button type="submit"
        class="inline-flex items-center px-5 py-2 text-sm font-medium text-white bg-[var(--primary-color)] rounded-md hover:bg-[var(--primary-dark)] transition">
        {{ __('Submit') }}
    </button>
</div>

{!! Form::close() !!}
