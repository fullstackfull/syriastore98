<div class="row">
    <div class="col-12">
        <div class="order-historycontent">
            <table class="cart-tble">
                <thead>
                    <tr>
                        <th scope="col"> {{ __('Product') }} </th>
                        <th scope="col"> {{ __('Name') }} </th>
                        <th scope="col"> {{ __('date') }} </th>
                        <th scope="col"> {{ __('Price') }} </th>
                        <th scope="col"> {{ __('Total') }} </th>
                        <th scope="col"> {{ __('Delete') }} </th>
                    </tr>
                </thead>
                <tbody>
                    @if (!empty($response->data->cart_total_product))
                        @foreach ($response->data->product_list as $item)
                        <tr>
                            <td data-label="Product">
                                <a href="{{url($slug.'/product/'. getProductSlug($item->product_id))}}">
                                    <img src="{{asset($item->image )}}" alt="img">
                                </a>
                            </td>

                            <td data-label="Name">
                                <a href="{{url($slug.'/product/'. getProductSlug($item->product_id))}}">{{ $item->name }}</a>
                                @if ($item->variant_id != 0)
                                    <div class="mt-5">
                                        {!! \App\Models\ProductVariant::variantlist($item->variant_id) !!}
                                    </div>
                                @endif
                            </td>
                            <td data-label="date"> {{ SetDateFormat($item->cart_created) }} </td>
                            <td data-label="Price">
                                {{ currency_format_with_sym($item->final_price/$item->qty, $store->id) ?? SetNumberFormat($item->final_price/$item->qty) }}
                            </td>
                            <td data-label="Total">
                                {{ currency_format_with_sym($item->final_price, $store->id) ?? SetNumberFormat($item->final_price) }}
                            </td>
                            <td>

                                <button class="btn remove_item_from_cart" title="Remove item" href="JavaScript:void(0)" data-id="{{ $item->cart_id }}">
                                    <i class="ti ti-trash text-white py-1" data-bs-toggle="tooltip" title="delete"></i>
                                </button>

                            </td>
                        </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="6">
                                <h3 class="text-center"> {{ __('You have no items in your shopping cart.') }} </h3>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
