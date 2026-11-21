{{--
    MKT-010 / GAP-220 — THE PRODUCT CARD'S CONVERSION ACTIONS (quick view, compare, PDF, CSV).

    `ss-product-actions` IS NEW AND `supdiv` IS KEPT. Measured 2026-11-21: `supdiv` occurred in
    EXACTLY ONE file in the whole repository — this one. No theme, no module and no script styled
    or selected it, so its inline `top: 40px` was inert and these buttons fell into normal flow,
    unstyled, wherever the hook was called. That is what `MKT-010`'s "parity" was parity WITH.

    The styling now lives in the design system under `.ss-product-actions`, because
    `store-syria-ds.css` is loaded by all three theme layouts (via `front_end/partials/appearance`)
    and 705 of its 708 selectors are scoped by `.ss-` or `data-ss-` — a bare `.supdiv` rule would
    have falsified that claim in its own docblock. `supdiv` stays on the element so that anything
    outside this repository which does target it is not broken by the rename.

    THE INLINE `top: 40px` IS GONE. It did nothing while nothing positioned this element; it would
    have started fighting the stylesheet the moment something did, and an inline declaration beats
    any stylesheet rule short of `!important`. The offset is expressed in CSS, once, in logical
    properties so it mirrors in Arabic.
--}}
<div class="ss-product-actions supdiv">
    @if (module_is_active('ProductQuickView'))
        @php
            $enable_quickview = \App\Models\Utility::GetValueByName('enable_product_quickview', $store->id);
        @endphp
        @if (isset($enable_quickview) && $enable_quickview == 'on')
            @include('product-quick-view::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null])
        @endif
    @endif

    @if (module_is_active('ProductCompare'))
        @php
            $enable_compare = \App\Models\Utility::GetValueByName('enable_product_compare', $store->id);
        @endphp
        @if (isset($enable_compare) && $enable_compare == 'on')
            @include('product-compare::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null])
        @endif
    @endif


    @if (module_is_active('ProductsPDF'))
        @php
            $ProductsPDF_enable = \App\Models\Utility::GetValueByName('products_pdf_enable', $store->id);
        @endphp
        @if (isset($ProductsPDF_enable) && $ProductsPDF_enable == 'on')

            @include('products-pdf::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null])
        @endif
    @endif
    @if (module_is_active('ProductCSV'))
        @php
            $product_csvswich_is_enable = \App\Models\Utility::GetValueByName('product_csvswich_is_enable', $store->id);
        @endphp
        @if (isset($product_csvswich_is_enable) && $product_csvswich_is_enable == 'on')
            @include('product-csv::pages.button', ['product_slug' => $product->slug ?? null, 'slug' => $store->slug ?? null])
        @endif
    @endif

</div>