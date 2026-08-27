@if (isset($product['stock_status']) && ($product['stock_status'] == 'in_stock' || $product['stock_status'] == 'on_backorder' || $product['stock_status'] == 'notify_customer' || $product['stock_status'] == 'allow'))
<span class="badge rounded p-2 f-w-600  bg-light-primary">{{ __("In stock") }}</span>
@endif