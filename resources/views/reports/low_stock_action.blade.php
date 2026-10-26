{{--
    P2-05-3 — the stock report's row action, on the design system and with an accessible name.

    TWO DEFECTS, both measured on the live report:

    1. THE LINK HAD NO ACCESSIBLE NAME, and for a worse reason than the order and customer lists
       did (F101). The `title` was on the inner `<i>`, not on the `<a>` — and a `title` on an icon
       never names its parent link, so even the weak fallback was on the wrong element. Bootstrap
       then moved it into `data-bs-original-title` on initialisation and blanked the attribute.
       Measured: five action links on the active tab, accessible name empty on all five, with
       `data-bs-original-title="تحرير"` sitting uselessly on the `<i>`.

    2. `btn btn-sm btn-info`, not the design system. The order list recorded `btn-info`'s icon
       measuring 1.09:1 in dark mode, because the vendored dark theme turns button icons white
       while leaving this button's light background alone.

    The name states the PRODUCT as well as the verb, because a screen-reader user moving down the
    table meets a row's action without the row's context. `title` moves onto the link so the
    sighted tooltip still works, and `aria-hidden` on the icon stops it being announced twice.

    `$product` here is an ARRAY, not a model — these reports build plain rows
    (`ReportController::getLowStockProducts` and siblings) — so the name is read with array access.
    Blade escapes it, and `product_name` is merchant-authored.
--}}
<span class="d-flex gap-1 justify-content-start">
    <a class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost"
       href="{{ route('product.edit', $product['product_id']) }}"
       data-bs-toggle="tooltip" data-bs-placement="top"
       title="{{ __('Edit') }}"
       aria-label="{{ __('Edit :name', ['name' => $product['product_name'] ?? '']) }}">
        <i class="ti ti-pencil" aria-hidden="true"></i>
    </a>
</span>
