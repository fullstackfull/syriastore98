@php
    $setting = getAdminAllSetting();
    $store_slug = \Request::segment(1);
@endphp

@if (isset($setting['product_csvswich_is_enable']) && ($setting['product_csvswich_is_enable'] == 'on'))
<style>
.csv-btn {
    position: absolute;
    width: 36px;
     top: 80px;
    height: 36px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    z-index: 2;
    opacity: 0;
    visibility: hidden;
    transform: translateX(20px);
    text-decoration: none;
}

.product-box:hover .csv-btn,
.product-item:hover .csv-btn,
.product-card:hover .csv-btn {
    opacity: 1;
    visibility: visible;
    transform: translateX(0);
}
.csv-btn i {
    font-size: 14px;
    color: #6b7280;
    transition: all 0.3s ease;
}


/* RTL Support */
[dir="rtl"] .csv-btn {
    right: auto;
    left: 15px;
}

/* Mobile Responsive */
@media (max-width: 768px) {
    .csv-btn {
        top: 75px;
        right: 12px;
    }
    
    [dir="rtl"] .csv-btn {
        left: 12px;
    }
}
</style>

<a href="{{ url($store_slug . '/csvExport', $product->id) }}" 
   class="csv-btn export-btn" 
   data-toggle="tooltip"
   title="{{ __('Download CSV') }}" 
   filename="{{ $product->name ?? __('Product') }}" 
   product_id="{{ $product->id ?? '' }}">
    <i class="fas fa-download"></i>
</a>
@endif