<style>
.compare-btn {
    position: absolute;
     top: 40px;
    width: 36px;
    height: 36px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    z-index: 2;
    opacity: 0;
    visibility: hidden;
    transform: translateX(20px);
}

.product-box:hover .compare-btn,
.product-item:hover .compare-btn,
.product-card:hover .compare-btn {
    opacity: 1;
    visibility: visible;
    transform: translateX(0);
}
.compare-btn i {
    font-size: 14px;
    color: #6b7280;
    transition: all 0.3s ease;
}
.compare-btn.active {
    background: #3b82f6;
    border-color: #3b82f6;
}

.compare-btn.active i {
    color: white;
}
</style>

<button id="compareButton" class="compare-btn" 
        data-url="{{ route('product_compare', [$slug, $product_slug ?? '']) }}"
        title="{{ __('Compare Product') }}" 
        product_id="{{ $product->id ?? '' }}">
    <i class="fas fa-code-compare"></i>
</button>
@once

    <script>
     $(document).on('click', '#compareButton', function(event) {
        event.preventDefault();
        $('#loader').fadeIn();
        
        var $button = $(this);
        var url = $button.data('url');
        var productId = $button.attr('product_id');

        $.ajax({
            url: url,
            method: 'GET',
            data: {
                _token: "{{ csrf_token() }}",
                product_id: productId
            },
            success: function(response) {
                $('#loader').fadeOut();
                
                if (response.success === true) {
                    show_toastr('Success', response.message, 'success');
                    $button.toggleClass('active');
                } else {
                    show_toastr('Error', response.message, 'error');
                }
            },
            error: function(xhr, status, error) {
                $('#loader').fadeOut();
                show_toastr('Error', 'An error occurred. Please try again.', 'error');
            }
        });
    });
    </script>

@endonce