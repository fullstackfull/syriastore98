@permission('Show Cart')
<button class="btn btn-sm  btn-outline-primary me-2"
    data-url="{{ route('carts.show', $cart->id) }}" data-size="md"
    data-ajax-popup="true" data-title="{{ __('Show Products') }}">
    <i  data-bs-toggle="tooltip" title="Product"> </i>{{ __('Show Product')}}
</button>
@endpermission
<span class="badge rounded p-2 f-w-600  bg-light-success">
    {{$cart_count}}</span>