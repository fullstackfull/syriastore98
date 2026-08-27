<button class="quickview-btn" style="top: 120px;">
    <a href="javascript:void(0)" class="quickview-btn" t data-ajax-popup="true" data-size="md" data-title="Product Quick-view"
        data-url="{{ route('product_quick_view.model', [$slug, $product_slug ?? '']) }}" data-toggle="tooltip"
        title="{{ __('Product Quick-view') }}" product_id="{{ $product->id ?? '' }}"> <svg xmlns="http://www.w3.org/2000/svg" width="16" height="11" viewBox="0 0 16 11" fill="none">
            <path d="M8.1608 2.67969C6.77275 2.67969 5.64331 3.80913 5.64331 5.19717C5.64331 6.58522 6.77275 7.71466 8.1608 7.71466C9.54884 7.71466 10.6783 6.58522 10.6783 5.19717C10.6783 3.80913 9.54884 2.67969 8.1608 2.67969ZM8.1608 6.67495C7.34586 6.67495 6.68302 6.01211 6.68302 5.19717C6.68302 4.38223 7.34586 3.7194 8.1608 3.7194C8.97573 3.7194 9.63857 4.38223 9.63857 5.19717C9.63857 6.01211 8.97573 6.67495 8.1608 6.67495Z" fill="#05303E"></path>
            <path d="M15.0666 3.81817C14.7818 3.42335 14.2816 2.817 13.5173 2.17123C12.7824 1.55016 11.9922 1.05296 11.1687 0.693449C10.1796 0.261855 9.16754 0.0429688 8.16078 0.0429688C7.15402 0.0429688 6.14197 0.261855 5.15256 0.693449C4.32909 1.05267 3.53887 1.54987 2.80395 2.17123C2.03961 2.81729 1.53947 3.42335 1.25468 3.81817C0.83986 4.39363 0.629211 4.85788 0.629211 5.19768C0.629211 5.53748 0.839566 6.00173 1.25498 6.57719C1.53977 6.97201 2.03991 7.57836 2.80425 8.22413C3.53916 8.84519 4.32939 9.34239 5.15286 9.70191C6.14196 10.1335 7.15431 10.3524 8.16107 10.3524C9.16783 10.3524 10.1799 10.1335 11.1693 9.70191C11.9928 9.34269 12.783 8.84549 13.5179 8.22413C14.2822 7.57807 14.7824 6.97201 15.0672 6.57719C15.482 6.00173 15.6923 5.53748 15.6923 5.19768C15.6923 4.85788 15.482 4.39363 15.0666 3.81817ZM12.7797 7.48569C11.775 8.31916 10.1581 9.31268 8.16078 9.31268C6.16344 9.31268 4.5468 8.31916 3.54181 7.48569C2.31205 6.46569 1.71393 5.45187 1.6701 5.19768C1.71364 4.94349 2.31205 3.92967 3.54181 2.90967C4.54651 2.0762 6.16344 1.08268 8.16078 1.08268C10.1578 1.08268 11.7748 2.0762 12.7797 2.90967C14.0095 3.92967 14.6079 4.94349 14.6515 5.19768C14.6076 5.45187 14.0095 6.46569 12.7797 7.48569Z" fill="#05303E"></path>
        </svg>
    </a>
</button>
<style>
.quickview-btn {
    position: absolute;
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

.product-box:hover .quickview-btn,
.product-item:hover .quickview-btn,
.product-card:hover .quickview-btn {
    opacity: 1;
    visibility: visible;
    transform: translateX(0);
}

.quickview-btn svg {
    width: 16px;
    height: 11px;
    transition: all 0.3s ease;
    fill: #6b7280;
}

.quickview-btn.active {
    background: #3b82f6;
    border-color: #3b82f6;
}

.quickview-btn.active svg {
    fill: #ffffff;
}
</style>
@once
<script>
$(document).on('click', '.quickview-btn', function (event) {
    event.preventDefault();
    $('#loader').fadeIn();

    var $button = $(this);
    var url = $button.data('url');

    $.ajax({
        url: url,
        method: 'GET',
        success: function (response) {
            $('#loader').fadeOut();

            $('#commonModal .modal-title').html("{{ __('Product Quick-view') }}");
            $('#commonModal .modal-body').html(response);
            $('#commonModal').modal('show');

            $button.toggleClass('active');
        },
        error: function () {
            $('#loader').fadeOut();
            show_toastr('Error', 'Something went wrong. Please try again.', 'error');
        }
    });
});
</script>
@endonce
