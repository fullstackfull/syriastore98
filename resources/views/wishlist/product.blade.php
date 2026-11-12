{{--
    THE WISHLIST'S SIZE, AND A WAY INTO IT.  P2.5-PKG-C

    The same cell as `cart/product.blade.php`, with the same two defects and the same repair: the
    count sat in a `bg-light-success` pill, so a SUCCESS tone was attached to a measure that is not
    a status, and the figure carried no noun under a column headed `Product` (singular). The column
    heading now reads `Products` and the responsive layout copies it onto this cell, so one string
    labels the figure in both layouts.

    This view's button additionally carried `data-bs-toggle="tooltip" title="Product"` on the
    button ITSELF, so hovering a button labelled `Show Product` produced a tooltip reading
    `Product`. Removed, along with the singular label on an action that opens a list.
--}}
<span class="d-inline-flex align-items-center gap-2 flex-wrap">
    <span class="ss-num fw-semibold">{{ $wishlist_count }}</span>
    @permission('Show Wishlist')
        <button type="button" class="ss-btn ss-btn--sm ss-btn--secondary"
            data-url="{{ route('wishlist.show', $wishlist->id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Products') }}">
            {{ __('Show Products') }}
        </button>
    @endpermission
</span>
