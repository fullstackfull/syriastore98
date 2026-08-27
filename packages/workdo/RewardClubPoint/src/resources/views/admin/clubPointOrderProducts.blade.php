<ul class="list-group">
    @foreach ($products as $productId)
        @php
            $product = App\Models\Product::find($productId);
        @endphp
        <li class="list-group-item">{{ $product->name }}</li>
    @endforeach
    <div class="modal-footer pb-0">
        <input type="button" value="{{__('Cancel')}}" class="btn btn-secondary btn-badge mx-1" data-bs-dismiss="modal">
    </div>
</ul>
