@php
    $store = getStore($slug);
    $checkout_setting = App\Models\Utility::GetValueByName('skip_cart_checkout_setting', $store->id);
    $button_text = App\Models\Utility::GetValueByName('skip_cart_button_text', $store->id);
    $button_link = App\Models\Utility::GetValueByName('skip_cart_button_link', $store->id);
@endphp

<x-product-card-button :store="$store" :product="$product" :checkout_setting="$checkout_setting" :button_text="$button_text" :button_link="$button_link" />




@once
    @push('page-script')
        <script>
            var checkout_Setting = {!! json_encode($checkout_setting) !!};
            if (checkout_Setting && checkout_Setting != '') {
                $('.addcart-btn-globaly').not('.one-click-checkout, .on-page-checkout, .checkout-button').hide();
            }
            if (checkout_Setting == 'sticky_cart') {
                $('.cartDrawer').hide();
            }

            $(document).on('click', '.one-click-checkout', function(e) {

                var product_id = $(this).attr('product_id');
                var variant_id = $(this).attr('variant_id');
                var qty = $('#product-quantity').val();
                var button_link = {!! json_encode($button_link) !!};
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
                        $('.cart-header .count').html(response.data.count);
                        if (response.status == 0) {
                            show_toastr('Error', response.data.message, 'error');
                        } else {
                            if (button_link == 'cart_page') {
                                var cart_route = '{{ route('page.cart', $slug) }}';
                                window.location.href = cart_route;
                            } else {
                                var chechout_route = '{{ route('checkout', $slug) }}';
                                window.location.href = chechout_route;
                            }
                        }
                        get_cartlist();
                    }
                });
            });

            $(document).on('click', '.on-page-checkout', function(e) {
                e.preventDefault();

                var product_id = $(this).attr('product_id');
                var variant_id = $(this).attr('variant_id');
                var qty = $('#product-quantity').val();

                qty = 1;

                $.ajax({
                    url: "{{ route('skip.cart.empty') }}",
                    method: 'POST',
                    data: {
                        _token: '{{ csrf_token() }}',
                        customer_id: '{{ Auth::guard('customers')->id() }}'
                    },
                    success: function(response) {
                        if (response.status == 'success') {
                            addProductToCart(product_id, variant_id, qty);
                        } else {
                            show_toastr('Error', 'Failed to empty the cart', 'error');
                        }
                    }
                });
            });

            function addProductToCart(product_id, variant_id, qty) {
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
                        $('.cart-header .count').html(response.data.count);
                        if (response.status == 0) {
                            show_toastr('Error', response.data.message, 'error');
                        } else {
                            var modalUrl = $('.on-page-checkout').attr('data-url');
                            loadAndShowModal(modalUrl);
                        }
                        get_cartlist();
                    }
                });
            }

            function loadAndShowModal(url) {
                $.ajax({
                    url: url,
                    method: 'GET',
                    success: function(modalContent) {
                        $('#skipCartModal').remove();
                        $('body').append('<div class="fixed inset-0 z-50 overflow-y-auto" id="skipCartModal"><div class="flex items-center justify-center min-h-screen px-4"><div class="fixed inset-0 bg-black opacity-50" onclick="$(\"#skipCartModal\").remove()"></div><div class="relative bg-white rounded-lg max-w-4xl w-full p-6 z-10 max-h-[90vh] overflow-y-auto"><button class="absolute top-4 right-4 text-2xl text-gray-500 hover:text-gray-700" onclick="$(\"#skipCartModal\").remove()">&times;</button><h3 class="text-xl font-bold mb-4">' + $('.on-page-checkout').attr('data-title') + '</h3><div>' + modalContent + '</div></div></div></div>');
                    },
                    error: function() {
                        show_toastr('Error', 'Failed to load the modal content', 'error');
                    }
                });
            }
            if (checkout_Setting == 'sticky_cart') {
                $(document).on('click', '.cart-header', function() {
                    if ($('#stickySidebar').length === 0) {
                        $.ajax({
                            url: '{{ route('CheckoutView', $slug) }}',
                            method: 'POST',
                            data: {
                                _token: '{{ csrf_token() }}'
                            },
                            success: function(response) {
                                if (response) {
                                    get_cartlist();
                                    $('body').append(response);
                                    $('#stickySidebar').addClass('show');
                                } else {
                                    $('body').removeClass('no-scroll cartOpen');
                                    show_toastr('error', 'You have no items in your shopping cart.',
                                        'error');
                                }
                            }
                        });
                    } else {
                        $('#stickySidebar').addClass('show');
                    }
                });
                $('body').on('click', '.overlay.cart-overlay, .close-btn', function(e) {
                    e.preventDefault();
                    $('#stickySidebar').removeClass('show');
                    $('body').removeClass('no-scroll cartOpen');
                });
            }
        </script>
    @endpush
@endonce
