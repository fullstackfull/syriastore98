@if($pre_order_button == true)
    @push('page-style') 
        <style>
            .product-hook {
                position: relative;
                margin-top: 35px;
            }
            .product-hook .btn.addcart-btn {
                max-width: 231px;
                width: 100%;
            }
            .note-after-button {
                position: absolute;
                top: -23px; /* Adjust the position as needed */
                left: 0;
                display: block; /* Makes it a block element instead of part of the flexbox layout */
                color: red;
                font-size: 12px;
                width: auto;
            }
        </style>
    @endpush

    <span class="note-after-button">{{ $pre_order_detail->message ?? '' }}</span> {{-- style="color: red; font-size: 12px; margin-top: 5px; display: block; width: auto;"--}}
    <button
        class="addtocart-btn btn addcart-btn  addcart-btn-globaly price-wise-btn product_var_option"
        tabindex="0" product_id="{{ $product->id }}"
        variant_id="{{ $product->default_variant_id }}" qty="1" order_type="pre_order">
        <span> {{ $pre_order_detail->name ?? 'Pre Order' }} </span>
        <svg viewBox="0 0 10 5">
            <path
                d="M2.37755e-08 2.57132C-3.38931e-06 2.7911 0.178166 2.96928 0.397953 2.96928L8.17233 2.9694L7.23718 3.87785C7.07954 4.031 7.07589 4.28295 7.22903 4.44059C7.38218 4.59824 7.63413 4.60189 7.79177 4.44874L9.43039 2.85691C9.50753 2.78197 9.55105 2.679 9.55105 2.57146C9.55105 2.46392 9.50753 2.36095 9.43039 2.28602L7.79177 0.69418C7.63413 0.541034 7.38218 0.544682 7.22903 0.702329C7.07589 0.859976 7.07954 1.11192 7.23718 1.26507L8.1723 2.17349L0.397965 2.17336C0.178179 2.17336 3.46059e-06 2.35153 2.37755e-08 2.57132Z">
            </path>
        </svg>
    </button>

@else
    @push('page-script')
        <script>
        // setTimeout(() => {
            var noteElement = document.createElement('span');
                noteElement.className = 'note-after-button';
                noteElement.style.color = "red";
                noteElement.style.fontSize = "12px";
                noteElement.style.marginTop = "5px";
                noteElement.style.display = "block";
                noteElement.style.width = "auto";
                noteElement.textContent = '{{ $pre_order_detail->message ?? '' }}';

            var $noteElement = $(noteElement);

            $('.addtocart-btn').each(function() { //.addcart-btn-globaly
                const button = $(this);
                const productId = button.attr('product_id');

                if (productId == {{$product->id}}) {
                    // Set the order_type attribute to 'pre_order'
                    button.attr('order_type', 'pre_order');

                    const span = button.find('span');
                    if (span.length > 0) {
                        span.text('{{ $pre_order_detail->name }}');
                    }
                    // if (button.attr('order_type') && button.hasClass('addcart-btn-globaly') && !button.prev('.note-after-button').length) {
                    // button.before($noteElement.clone());
                    // }
                    if (button.hasClass('quick-checkout-button')) {
                        button.remove();
                    }
                    if (button.hasClass('checkout-button')) {
                        button.remove();
                    }
                }
            });
        // }, 3000);
        </script>
    @endpush
@endif