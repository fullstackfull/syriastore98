{{--
    WHAT IS IN ONE ABANDONED WISHLIST.  P2.5-PKG-C

    NO QUANTITY HERE, AND THAT IS THE DATA RATHER THAN AN OMISSION. `wishlists` has no `qty`
    column (2024_01_22_040531) -- a wishlist records that someone wants a product, not how many --
    so this modal lists names where its cart sibling lists names and quantities.

    THE NULL GUARD IS DEFENSIVE HERE, NOT A REPAIR -- and the first draft of this comment claimed
    the opposite. `$product->ProductData->name` has no null check and `ProductData` resolves to
    null once the catalogue row is gone, which is a live 500 on the CART side. It is not reachable
    here: `wishlists.product_id` carries a foreign key with `onDelete('cascade')`
    (2024_01_22_040531) and `carts` has none, so deleting a product REMOVES the wishlist row rather
    than stranding it. Measured with foreign keys enforced -- deleting one product left both cart
    lines and took the wishlist row with it.

    The guard stays, because the cascade is the database's doing rather than this view's and a row
    imported or restored without it would otherwise take the modal down. But it guards a case this
    schema does not currently produce, and saying so is the difference between a guard and a claim.
--}}
<ul class="list-group">
    @foreach ($wishlist_product as $item)
        <li class="list-group-item">{{ $item->ProductData->name ?? __('Product no longer available') }}</li>
    @endforeach
</ul>
