@permission('Show Wishlist')
<button class="btn btn-sm  btn-outline-primary me-2"
    data-url="{{ route('wishlist.show', $wishlist->id) }}" data-size="md"
    data-ajax-popup="true" data-title="{{ __('Products') }}" data-bs-toggle="tooltip"
    title="{{ __('Product') }}">
     {{ __('Show Product')}}
</button>
@endpermission
<span class="badge rounded p-2 f-w-600  bg-light-success">
    {{$wishlist_count}}</span>