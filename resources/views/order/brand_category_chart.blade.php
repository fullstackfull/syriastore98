{{--
    The Top Category / Top Brand "By Sales" body, replaced into
    `#category-tab-data` / `#brand-tab-data` by the dashboard's AJAX.

    ---------------------------------------------------------------------------
    [2026-11-01] THE `/100` IS GONE, AND SO IS THE FAN-OUT IT COMPENSATED FOR (F242, owner-approved).

    EVERYTHING THIS BLOCK USED TO SAY WAS TRUE OF A QUERY THAT NO LONGER EXISTS, and it is kept in
    outline because the reasoning is the record of why the divisor was defended twice before it was
    removed correctly.

    WS C once removed `/100` on the reasoning that `total_sale` is `SUM(orders.final_price)` and
    `final_price` is a major-unit decimal. That reasoning was right about the column and wrong about
    the query: the twelve queries in `HomeController::top_brand_category_chart()` cross-joined a
    generated series `numbers.n` of 1..100, so a single-product order joined 100 times and its price
    was summed 100 times. A real MariaDB said so, and removing the divisor then multiplied every
    figure on both cards by a hundred. So it went back.

    WHAT THE DIVISOR COULD NEVER FIX, and what F242 recorded as still wrong: the fan-out is NOT
    UNIFORM. Nothing bounded `n` to the element count, so for an order of `k` products the first
    `k-1` matched once each and the last matched `101-k` times. `/100` was therefore exact only for
    `k = 1`, and under-reported every earlier category of a multi-product order a hundredfold. And
    separately, summing an ORDER total credited each matched category the whole order, shipping and
    tax included, so a two-category order was counted twice over.

    BOTH ARE FIXED AT THE SOURCE. `App\Support\TopSellersByRevenue` sums each order LINE's own
    revenue from `orders.product_json` and attributes it to that line's product's category or brand.
    No cross join, so no fan-out; no divisor, arbitrary or otherwise; correct for 1, 2, 3 and more
    products; explicit store and half-open date predicates. It runs on SQLite as well as MySQL, so
    these figures are testable in the ordinary Feature suite for the first time — the old query
    could not execute there at all, which is why the only coverage was a MariaDB script and why the
    browser harness rendered these cards empty.

    SO `total_sale` IS NOW PRODUCT REVENUE IN MAJOR UNITS, printed as-is. It is deliberately NOT the
    sum of order totals: shipping, tax and coupon discounts are order-level and belong to no
    category, so these figures do not add up to the dashboard's order-value tile and should not.
    ---------------------------------------------------------------------------

    WS C DID FIX THE WRAPPER'S FALSE ARIA. It carried an `id` and an
    `aria-labelledby` interpolated from `$tab_name` — the request's own `tabId` —
    so after the first load the id was `#week-category-order-home`, an id
    beginning with `#`, and the `aria-labelledby` named an element that has never
    existed. Bootstrap does not toggle this element either: the five tabs now name
    `#category-tab-data`, the container this markup is injected INTO, and that
    container carries `role="tabpanel"`. A second nested tabpanel with a broken
    label is worse than none.
--}}
@if (isset($top_sales) && count($top_sales) > 0)
    @foreach ($top_sales as $sale)
        <div class="cat-item d-flex flex-wrap align-items-center gap-3 justify-contant-between">
            <div class="cat-img rounded-1 border overflow-hidden border border-primary border-2 rounded">
                <img src="{{ asset($sale->sale_image_path ?? '#') }}" alt="product-img">
            </div>
            <div class="cat-tab-text ss-rank d-flex align-items-start gap-2">
                {{-- THE NAME WAS BIGGER AND QUIETER THAN THE NUMBER IT LABELS: measured
                     18px/400 muted against 16px/600 for the figure, so the row read
                     upside down. `text-muted` goes rather than being overridden, which
                     is what this project does with a host utility a later sheet owns. --}}
                <p class="ss-rank__name">{{ $sale->sale_name }}</p>
                <span class="cat-price ss-rank__value">{{ currency_format_with_sym(($sale->total_sale ?? 0), getCurrentStore()) }}</span>
            </div>
        </div>
    @endforeach
@else
    {{-- AN EMPTY STATE THAT SAYS WHAT IS MISSING AND WHEN IT WILL FILL.

         It used to be the two words "No Data Found", centred in a card measured at 490px tall,
         with no icon and no guidance -- and identical on both cards, because this partial had no
         way to tell Top Category from Top Brand. `$type` now comes from the controller, so each
         card names its own dimension.

         `<x-ss.empty>` is the design system's component for this and is what the notifications,
         customer-timeline and catalogue screens already use, so the merchant meets one empty
         state across the admin rather than a different improvisation per card. --}}
    @php
        $ssDimension = ($type ?? '') === 'category' ? 'category' : (($type ?? '') === 'brand' ? 'brand' : '');
    @endphp
    @if ($ssDimension === 'category')
        <x-ss.empty icon="chart-bar"
                    :title="__('No category sales yet')"
                    :body="__('Once orders include products that belong to a category, the categories that sell most appear here.')" />
    @elseif ($ssDimension === 'brand')
        <x-ss.empty icon="tag"
                    :title="__('No brand sales yet')"
                    :body="__('Once orders include products that carry a brand, the brands that sell most appear here.')" />
    @else
        <x-ss.empty icon="chart-bar"
                    :title="__('No sales yet')"
                    :body="__('Top sellers appear here once orders start coming in.')" />
    @endif
@endif
