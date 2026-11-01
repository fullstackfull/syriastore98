<meta name="csrf-token" content="{{ csrf_token() }}">

<div class="modal-body">
    <div class="row">
        <div class="col-md-6 col-sm-12 col-lg-6 ">
            <div class="">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="">{{ __('Shipping Information') }}</h5>
                </div>
                <div class="card-body pt-0">
                    <address class="mb-0 text-sm">
                        <dl class="row mt-4 align-items-center">
                            <dt class="col-sm-3 h6 text-sm">{{ __('Name') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['name']) ? $order['delivery_informations']['name'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('Email') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['email']) ? $order['delivery_informations']['email'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('City') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['city']) ? $order['delivery_informations']['city'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('State') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['state']) ? $order['delivery_informations']['state'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('Country') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['country']) ? $order['delivery_informations']['country'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('Postal Code') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                {{ !empty($order['delivery_informations']['post_code']) ? $order['delivery_informations']['post_code'] : '' }}
                            </dd>
                            <dt class="col-sm-3 h6 text-sm">{{ __('Phone') }}</dt>
                            <dd class="col-sm-9 text-sm">
                                <a href="https://api.whatsapp.com/send?phone={{ !empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : '' }}&amp;text=Hi"
                                    target="_blank">
                                    {{ !empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : '' }}
                                </a>
                            </dd>
                        </dl>
                    </address>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-sm-12 col-lg-6 ">
            <div class="">
                <div class="card-header d-flex justify-content-between">
                    <h5 class="">{{ __('Billing Information') }}</h5>
                </div>
                <div class="card-body pt-0">
                    <dl class="row mt-4 align-items-center">
                        <dt class="col-sm-3 h6 text-sm">{{ __('Name') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['name']) ? $order['billing_informations']['name'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('Email') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['email']) ? $order['billing_informations']['email'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('City') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['city']) ? $order['billing_informations']['city'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('State') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['state']) ? $order['billing_informations']['state'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('Country') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['country']) ? $order['billing_informations']['country'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('Postal Code') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            {{ !empty($order['billing_informations']['post_code']) ? $order['billing_informations']['post_code'] : '' }}
                        </dd>
                        <dt class="col-sm-3 h6 text-sm">{{ __('Phone') }}</dt>
                        <dd class="col-sm-9 text-sm">
                            <a href="https://api.whatsapp.com/send?phone={{ !empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : '' }}&amp;text=Hi"
                                target="_blank">
                                {{ !empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : '' }}
                            </a>
                        </dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
    <div class="row modal-shipping-row">
        <div class="col-md-6">
            <b class="col-sm-3 h6 text-sm mx-2 shipping-text">{{ __('Payment Type') }}</b>
            {{ $order['paymnet_type'] }}
        </div>
        @if ($orders->delivery_id != 0)
            @php
                $shipping = \App\Models\Shipping::find($orders->delivery_id);
            @endphp
            <div class="col-md-6">
                <b class="col-sm-3 h6 text-sm mx-2 ">{{ __('Shipping Method') }}</b>
                {{ $shipping->name ?? '' }}
            </div>
        @endif
    </div><br>
    <div class="row">
        <div class="col-lg-12">
            <div class="table-responsive">

            <table class="table modal-table">
                <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th>{{ __('Quantity') }}</th>
                        <th>{{ __('Total') }}</th>
                        @if ($order['order_status'] == 1)
                            <th>{{ __('Downloadable Product') }}</th>
                        @endif
                    </tr>
                </thead>
                @php
                    /* F260. THE SAME LEAK P2-04-6 FIXED ON THE OTHER ORDER PANEL, left on this one.
                     *
                     * These two lookups were `ProductVariant::where('id', $item['variant_id'])` and
                     * `Product::where('id', $item['product_id'])` — PER LINE ITEM, inside the
                     * @foreach, with NO store predicate. `Product` registers no `StoreScope` and is
                     * absent from `config/tenancy.php`'s `scoped_models` (finding S19), so a bare
                     * lookup by id reaches every tenant's catalogue. The only field read becomes an
                     * `href` below: `get_file($product->downloadable_product)`. So an order naming
                     * another store's product id rendered THAT STORE'S FILE PATH as a download link
                     * on this panel.
                     *
                     * `resources/views/order/view.blade.php:314-335` already carries the fix. It
                     * landed on the pencil button's page and not on the eye button's — the one a
                     * merchant opens far more often.
                     *
                     * SCOPED TO THE ORDER'S STORE, NOT THE CALLER'S, and that distinction is the
                     * twin's and worth repeating: the platform operator legitimately opens another
                     * store's order (the operator limb is first in both
                     * `OrderController::mayUseOrderScreen()` and `OrderDocumentAccessGuard`), so
                     * `getCurrentStore()` here would blank the line items for exactly the principal
                     * the guard admits. `Order::order_detail()` supplies the order's own store_id.
                     *
                     * Lifting them above the loop also replaces 2N queries with 2. */
                    $ssOrderStore = $order['store_id'] ?? null;

                    $ssItemProductIds = collect($order['product'])->pluck('product_id')->filter()->unique();
                    $ssItemVariantIds = collect($order['product'])->pluck('variant_id')->filter()->unique();

                    $ssItemProducts = $ssOrderStore === null || $ssItemProductIds->isEmpty()
                        ? collect()
                        : \App\Models\Product::whereIn('id', $ssItemProductIds)
                            ->where('store_id', $ssOrderStore)->get()->keyBy('id');

                    $ssItemVariants = $ssOrderStore === null || $ssItemVariantIds->isEmpty()
                        ? collect()
                        : \App\Models\ProductVariant::whereIn('id', $ssItemVariantIds)
                            ->where('store_id', $ssOrderStore)->get()->keyBy('id');
                @endphp
                <tbody>
                    @foreach ($order['product'] as $item)
                        @php
                            $variant = $ssItemVariants->get($item['variant_id']);
                            $product = $ssItemProducts->get($item['product_id']);
                        @endphp

                        <tr>
                            <td class="total">
                                <span class="h6 text-sm"> <a href="#">{{ $item['name'] }}</a>
                                </span> <br>
                                <span class="text-sm"> {{ $item['variant_name'] }} </span>
                            </td>
                            <td>
                                @if ($order['paymnet_type'] == 'POS')
                                    {{ $item['quantity'] }}
                                @else
                                    {{ $item['qty'] }}
                                @endif
                            </td>
                            <td>
                                {{-- استخدام دالة تنسيق الأسعار الجديدة المخصصة حسب رقم الطلب (Order ID) --}}
                                @if ($order['paymnet_type'] == 'POS')
                                    {{ order_currency_format_with_sym(($item['orignal_price'] ?? 0) * ($item['quantity'] ?? 0), $order['id'], getCurrentStore()) }}
                                @else
                                    @if (module_is_active('ProductPricing') && isset($item['sale_price']))
                                        {{ order_currency_format_with_sym($item['sale_price'] ?? 0, $order['id'], getCurrentStore()) }}
                                    @else
                                        {{ order_currency_format_with_sym($item['final_price'] ?? 0, $order['id'], getCurrentStore()) }}
                                    @endif
                                @endif
                            </td>
                            @if ($product || $variant)
                                @if ($order['order_status'] == 1)
                                    @if (!empty($variant->downloadable_product) != null || !empty($product->downloadable_product) != null)
                                        <td>
                                            @if (!empty($variant->downloadable_product))
                                                <a class="downloadable_product_{{ $item['product_id'] }}"
                                                    href="{{ get_file($variant->downloadable_product) }}" download
                                                    style="display: none;"></a>
                                            @endif
                                            @if (!empty($product->downloadable_product))
                                                <a class="downloadable_product_{{ $item['product_id'] }}"
                                                    href="{{ get_file($product->downloadable_product) }}" download
                                                    style="display: none;"></a>
                                            @endif
                                            <button
                                                class="download-btn action-btn btn-primary btn btn-sm align-items-center"
                                                data-bs-toggle="tooltip" data-bs-placement="top"
                                                title="{{ __('Download') }}"
                                                data-product-id="{{ $item['product_id'] }}">
                                                <i class="ti ti-download "></i>
                                            </button>
                                        </td>
                                    @else
                                        <td>-</td>
                                    @endif
                                @endif
                            @endif

                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        </div>
    </div>
    <div class="row modal-bottom-btn-wrapper">
        <div class="col-md-3 co-12">
            @if ($order['order_status'] == 0)
                <a href="#" class="btn btn-sm btn-info me-2 delivered-button-status">
                    {{ __('Delivered') }}
                </a>
            @endif
        </div>
        <div class="col-md-3 col-12 text-end">
            <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}"
                class="btn btn-sm btn-success me-2">
                {{ __('Edit Order') }}
            </a>
        </div>
    </div>
    <script>
        $(document).ready(function() {
            $(".delivered-button-status").click(function(event) {
                event.preventDefault();

                var csrfToken = $('meta[name="csrf-token"]').attr('content');
                var orderId = $(this).data("order-id");
                $.ajax({
                    type: "POST",
                    url: "{{ route('order.order_status_update', ['id' => $order['id']]) }}",
                    data: {
                        "_token": csrfToken
                    },
                    success: function(response) {
                        show_toastr('{{ __('Success') }}',
                            '{{ __('Status Updated Successfully!') }}', 'success');
                        $('#commanModel').modal('hide');
                        location.reload(); // Reload the page
                        $('#loader').fadeOut();
                    },
                    error: function(xhr, textStatus, errorThrown) {
                        $('#loader').fadeOut();
                    }
                });
            });

        });
        document.querySelectorAll('.download-btn').forEach(button => {
            button.addEventListener('click', function() {
                const productId = this.getAttribute('data-product-id');
                const downloadLink = document.querySelector('.downloadable_product_' + productId);
                if (downloadLink) {
                    downloadLink.click();
                } else {
                    console.error('Download link not found for product ID:', productId);
                }
            });
        });
    </script>
</div>