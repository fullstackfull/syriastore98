{{--
    The Top Category / Top Brand "By Sales" body, replaced into
    `#category-tab-data` / `#brand-tab-data` by the dashboard's AJAX.

    ---------------------------------------------------------------------------
    THE `/100` IS NOT A CURRENCY SCALE, AND IT STAYS. READ THIS BEFORE TOUCHING IT.

    WS C first removed it, on the reasoning that `$sale->total_sale` is
    `SUM(orders.final_price)` and `orders.final_price` is a major-unit decimal
    everywhere else it is displayed. That reasoning is correct about the column
    and WRONG about the query, and a real MariaDB said so:

        tests/Support/Security/bin/verify-dashboard-top-sales-scope-on-mysql.php

    The twelve queries in `HomeController::top_brand_category_chart()` CROSS JOIN
    a generated series `numbers.n` of 1..100 and match on
    `SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)`.
    Nothing bounds `n` to the number of elements in the list. For a
    SINGLE-product order, `SUBSTRING_INDEX('7', ',', n)` returns `'7'` for every
    one of the hundred values of `n`, so the row joins 100 TIMES and its price is
    summed 100 times. Measured, not reasoned:

        order        join rows   SUM(final_price)
        '7'   4500      100          450000
        '7,11' 100        1             100

    So `/100` is a compensation for the fan-out. Removing it multiplies every
    figure on both cards by one hundred — a 4,500 category would have read
    450,000. It was caught before it shipped only because the MariaDB script
    existed; the Feature suite cannot execute this query at all (SQLite has no
    `SUBSTRING_INDEX`), and the browser harness renders these cards empty for the
    same reason.

    WHAT IS STILL WRONG, measured and deliberately NOT fixed here (finding F242):
    the compensation is exact only for single-product orders. A two-product order
    joins once per product, so its price is summed once per product and then
    divided by 100 — under-reporting it a hundredfold. Correcting that means
    bounding `numbers.n` to the element count AND deciding whether an order's
    total should be counted once per CATEGORY or once per PRODUCT, which changes
    the figure the two cards report. That is a reporting-figure change for the
    owner to take, not a side effect of an accessibility fix.
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
            <div class="cat-tab-text d-flex align-items-start gap-2">
                <p class="m-0 text-muted">{{ $sale->sale_name }}</p>
                <span class="cat-price"><b>{{ currency_format_with_sym((($sale->total_sale ?? 0) / 100), getCurrentStore()) }}</b></span>
            </div>
        </div>
    @endforeach
@else
    <div class="d-flex text-center align-items-center h-100 w-100 justify-content-center">
        <span>{{ __('No Data Found') }}</span>
    </div>
@endif
