{{--
    THE BASKET'S SIZE, AND A WAY INTO IT.  P2.5-PKG-C

    THIS CELL RENDERED A BARE NUMBER IN A GREEN PILL. `bg-light-success` is the SUCCESS tone --
    the same tone this admin uses for a paid order and an active record -- and what the pill
    actually holds is a count of the products a customer WALKED AWAY FROM. The colour asserted a
    meaning the number does not carry, which is why the pill also read as a status rather than as
    a measure. A count is a measure, so it is now set as a figure and carries no tone at all.

    AND THE NUMBER HAD NO NOUN. In a column headed `Product` -- singular -- nothing on the screen
    said whether 5 meant five products, five units or five separate carts. One `carts` row is one
    product line carrying its own `qty` (see the migration), so it is five PRODUCTS. The column
    heading now says `Products`, and `store-syria-ds.js` copies that heading onto this cell as
    `data-ss-label` at phone width, so the figure is labelled in BOTH layouts from one string.

    `.ss-num` rather than a plain run: tabular digits so a column of counts aligns, and
    `unicode-bidi: isolate` so the figure stays one left-to-right run inside the Arabic line.

    AND THE BUTTON ADDRESSED THE WRONG ROW. It sent `$cart->id`, which
    `AbandonCartDataTable::query()` sets to `MIN(carts.id)` over a `groupBy('carts.customer_id')`
    -- a cart ROW id -- while `CartController::abandon_carts_show()` keys on `customer_id`. The
    lookup missed, `redirect()->back()` fired, and the ajax popup dropped the REDIRECTED LIST PAGE
    into the modal, so clicking this button showed the sidebar instead of the products. It has
    never worked. The route placeholder and the handler parameter were both named `cartId`, which
    is why the view believed them; both are now named `customerId`, and `carts.destroy` two files
    over was already passing `$cart->customer_id`, so the family is finally consistent about what
    identifies a row on this screen. `AbandonedCartShowIdentifierTest` drives the round trip.

    THE BUTTON was also the last raw-Bootstrap control left in this family (`btn-outline-primary`), and
    it was labelled `Show Product` while opening a LIST. It is now the design system's bordered
    secondary button -- a real boundary, which the outline variant did not reliably have in dark
    mode -- and it is named for what it opens. Its empty `<i>` carried a tooltip reading "Product"
    on a button whose own label already said so; a tooltip that repeats the label is noise to a
    pointer user and a second announcement to a screen reader, so it is gone.
--}}
<span class="d-inline-flex align-items-center gap-2 flex-wrap">
    <span class="ss-num fw-semibold">{{ $cart_count }}</span>
    @permission('Show Cart')
        <button type="button" class="ss-btn ss-btn--sm ss-btn--secondary"
            data-url="{{ route('carts.show', $cart->customer_id) }}" data-size="md"
            data-ajax-popup="true" data-title="{{ __('Products') }}">
            {{ __('Show Products') }}
        </button>
    @endpermission
</span>
