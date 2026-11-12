{{--
    WHAT IS IN ONE ABANDONED CART.  P2.5-PKG-C

    THE LINES NOW CARRY THEIR QUANTITY. The list showed product names only, so a merchant looking
    at an abandoned basket could not tell one unit of something from twenty -- and `carts.qty` was
    already on every row this view iterates, fetched and discarded. `qty` is a count, not money, so
    showing it is presentation of data already here; the cart's VALUE is a separate question and is
    recorded for the owner under §4-D rather than added, because a new money figure on a merchant
    screen is a financial change.

    THE WISHLIST MODAL DELIBERATELY DOES NOT MATCH. `wishlists` has no `qty` column (see the 2024
    migration) -- a wishlist is a wish, not a basket -- so the two modals differ because the data
    differs, not because one was missed.

    AND A DELETED PRODUCT NO LONGER 500s THIS MODAL. The line was `$product->product_data->name`
    with no null check, and `product_data` resolves to null once the catalogue row is gone --
    "Attempt to read property on null". An abandoned cart is exactly where that happens: the rows
    sit for weeks while the merchant edits the catalogue underneath them. The same class of defect
    as `CartController:1309`, which carries its own null guard for a deleted line. A cart holding
    one removed product used to take the whole modal down with it; now that line names itself and
    the others still render.

    `.ss-num` for the quantity: tabular digits so a column of them aligns, and `unicode-bidi:
    isolate` so the figure stays one left-to-right run on the Arabic line. The visually-hidden
    label is there because "x 2" alone announces as "times two" with no noun.
--}}
<ul class="list-group">
    @foreach ($cart_product as $line)
        <li class="list-group-item d-flex align-items-center justify-content-between gap-3">
            <span>{{ $line->product_data->name ?? __('Product no longer available') }}</span>
            <span class="text-nowrap">
                <span class="visually-hidden">{{ __('Quantity') }}</span>
                <span aria-hidden="true">&times;</span>
                <span class="ss-num">{{ (int) $line->qty }}</span>
            </span>
        </li>
    @endforeach
</ul>
