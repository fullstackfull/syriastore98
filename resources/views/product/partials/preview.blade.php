{{--
    P2-03-6 — the product quick preview, rendered into the side panel.

    READ-ONLY. Editing a product is a full-page job: it has media, variants, tax, shipping and a
    dozen fields, and Workstream F's rule is that a panel inspects or edits one to five fields,
    never a form like that. So this previews and then LINKS to the editor.

    STOCK IS PRESENTED HONESTLY, which is the only judgement this file makes. `track_stock = 0`
    means the merchant has told the system not to count, so a number would be fiction — it says
    "not tracked" instead of "0 in stock", because those are different facts and only one of them
    is a problem. The low-stock threshold comes from the product's OWN store, not the viewer's.
--}}
@php
    $ssTracks   = (int) ($product->track_stock ?? 0) === 1;
    $ssStock    = (int) ($product->product_stock ?? 0);
    $ssLow      = (int) ($product->low_stock_threshold ?: $outOfStockThreshold);
    $ssIsActive = (int) ($product->status ?? 0) === 1;

    $ssStockTone = ! $ssTracks ? 'neutral'
        : ($ssStock <= 0 ? 'danger' : ($ssLow > 0 && $ssStock <= $ssLow ? 'warning' : 'success'));

    $ssStockLabel = ! $ssTracks
        ? __('Not tracked')
        : ($ssStock <= 0
            ? __('Out of stock')
            : ($ssLow > 0 && $ssStock <= $ssLow
                ? __(':n left', ['n' => $ssStock])
                : __(':n in stock', ['n' => $ssStock])));
@endphp

<div class="ss-scope ss-order-preview">

    <dl class="ss-order-preview__meta">
        <div>
            <dt>{{ __('Product') }}</dt>
            <dd>{{ $product->name }}</dd>
        </div>
        <div>
            <dt>{{ __('Status') }}</dt>
            <dd>
                <x-ss.status-badge :tone="$ssIsActive ? 'success' : 'neutral'"
                                   :label="$ssIsActive ? __('Active') : __('Inactive')" />
            </dd>
        </div>
        <div>
            <dt>{{ __('Stock') }}</dt>
            <dd><x-ss.status-badge :tone="$ssStockTone" :label="$ssStockLabel" /></dd>
        </div>
    </dl>

    <section class="ss-order-preview__totals" aria-labelledby="ss-product-preview-pricing">
        <h3 id="ss-product-preview-pricing" class="ss-order-preview__h">{{ __('Pricing') }}</h3>

        <dl class="ss-order-preview__totalgrid">
            <div>
                <dt>{{ __('Price') }}</dt>
                <dd>{{ currency_format_with_sym($product->price) }}</dd>
            </div>
            @if ((float) ($product->sale_price ?? 0) > 0)
                <div>
                    <dt>{{ __('Sale price') }}</dt>
                    <dd>{{ currency_format_with_sym($product->sale_price) }}</dd>
                </div>
            @endif
        </dl>

        {{-- PRD-001: a price is what the product is listed at. It is not revenue, and this panel
             computes no margin — `cost_price` is a GATE F column with no migration, so any
             margin shown here would be arithmetic on a field the schema does not manage. --}}
        <x-ss.help>{{ __('This is the listed price. It is not revenue, and no margin is calculated here.') }}</x-ss.help>
    </section>

    <section aria-labelledby="ss-product-preview-detail">
        <h3 id="ss-product-preview-detail" class="ss-order-preview__h">{{ __('Details') }}</h3>

        <dl class="ss-order-preview__statusgrid">
            <div>
                <dt>{{ __('Category') }}</dt>
                <dd>{{ optional($product->ProductData)->name ?: __('Not recorded') }}</dd>
            </div>
            <div>
                <dt>{{ __('Brand') }}</dt>
                <dd>{{ optional($product->brand)->name ?: __('Not recorded') }}</dd>
            </div>
            <div>
                <dt>{{ __('Variants') }}</dt>
                <dd>{{ (int) ($product->variant_product ?? 0) === 1 ? __('Has variants') : __('No variants') }}</dd>
            </div>
            <div>
                <dt>{{ __('Last saved') }}</dt>
                <dd>
                    @if ($product->updated_at)
                        <time datetime="{{ $product->updated_at->toDateString() }}">
                            {{ $product->updated_at->isoFormat('D MMM YYYY') }}
                        </time>
                    @else
                        {{ __('Not recorded') }}
                    @endif
                </dd>
            </div>
        </dl>
    </section>

    <div class="ss-order-preview__actions">
        {{-- `product.edit` is route-model-bound and carries its OWN ownership check
             (ProductController::edit, P1-INV-01), so reaching this preview is not treated as
             authorisation for the editor: that request is judged again. --}}
        <a class="ss-btn ss-btn--primary" href="{{ route('product.edit', $product->id) }}">
            {{ __('Open full product') }}
        </a>
    </div>
</div>
