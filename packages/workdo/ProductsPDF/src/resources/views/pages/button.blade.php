<button class="product-pdf-btn" style="top: 1px;">
    <a href="{{ route('product_pdf', [$slug, \Illuminate\Support\Facades\Crypt::encrypt($product->id) ?? '']) }}" target="_blank" class="product-pdf-btn"
        title="{{ __('Pdf Download') }}" product_id="{{ $product->id ?? '' }}">
        <i class="ti ti-printer" style="color: white;"></i>
    </a>
</button>
