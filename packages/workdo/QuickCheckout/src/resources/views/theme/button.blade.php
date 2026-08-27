@php
    $store = getStore($slug);
    $button_title = \App\Models\Utility::GetValueByName('quick_button_title', $store->id);
@endphp

 <button class="quick-checkout-button addtocart-btn btn addcart-btn-globaly bg-[var(--primary-color)] text-white px-3 py-1 rounded hover:bg-opacity-90 transition-colors duration-300"
                tabindex="0"
                product_id="{{ $product->id }}"
                variant_id="0"
                qty="1">
                <span>{{ $button_title }}</span>
                </button>

@push('page-script')
@once
<script>
    $(document).on('click', '.quick-checkout-button', function(e) {
        e.preventDefault();
        var product_id = $(this).attr('product_id');
        var variant_id = $(this).attr('variant_id');
        var qty = $('#product-quantity').val();
        qty = parseInt(qty);
        if (isNaN(qty) || qty < 1) {
            qty = 1;
        }

        var data = {
            product_id: product_id,
            variant_id: variant_id,
            qty: qty,
        };

        $.ajax({
            url: ProductCart,
            method: 'POST',
            data: data,
            context: this,
            success: function(response) {
                $('#loader').fadeOut();
                $('.cart-header .count').html(response.data.count);
                if (response.status == 0) {
                    show_toastr('Error', response.data.message, 'error');
                } else {
                    var chechout_route = '{{ route('checkout', $slug) }}';
                    window.location.href = chechout_route;
                }
                get_cartlist();
            }
        });
    });
</script>
@endonce
@endpush
