@extends('layouts.app')

@section('page-title', __('Order Detail'))

@php
    if ($order['deliveryboy_id'] == 0 && $order['order_status'] == 0) {
        $disableSelectBox = false;
    } else {
        $disableSelectBox = true;
    }
@endphp
@section('action-button')
    <div class="text-end d-flex flex-wrap all-button-box justify-content-md-end justify-content-center">

        @if ($order['payment_receipt'])
            <a href="{{ asset($order['payment_receipt']) }}"
                target="_blank" rel="noopener noreferrer" class="btn btn-badge mr-2 btn-sm btn-primary btn-icon d-flex align-items-center" data-bs-toggle="tooltip"
                data-bs-placement="top" title="Payment Receipt" aria-label="Payment Receipt" download>
                <i class="ti ti-download" style="font-size:20px"></i>
            </a>
        @endif
        @if (module_is_active('AutomaticOrderPrinting'))
            @stack('invoice-button')
        @else
            <a href="{{ route('shippinglabel.pdf', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}"
                target="_blank" rel="noopener noreferrer" class="btn btn-badge mr-2 btn-sm btn-primary btn-icon d-flex align-items-center" data-bs-toggle="tooltip"
                data-bs-placement="top" title="Print" aria-label="Print">
                <i class="ti ti-printer" style="font-size:20px"></i>
            </a>
        @endif
        <a href="{{ route('order.receipt', \Illuminate\Support\Facades\Crypt::encrypt($order['id'])) }}"
            class="mx-1 btn btn-sm btn-badge mr-2 btn-primary btn-icon d-flex align-items-center" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Receipt') }}"><i class="ti ti-receipt" style="font-size:20px"></i>
        </a>
        {{--
            P2-04-6. THREE THINGS, one of which only breaks in production.

            `config('app.url')`, NOT `env('APP_URL')`. `env()` reads `$_ENV`, which
            `php artisan config:cache` does not populate -- and caching config is the normal
            production posture. So on a cached deployment this button copied
            `/<slug>/order/<cipher>` with NO HOST: a broken link, broken only in production,
            which is why nobody had seen it. (Ledger F48: 29 such calls across host views, three
            distinct variables. The sweep is its own slice; this is the one on this page.)

            `data-ss-copy`, NOT `id`. The full url was stored in the element's `id` and read back
            as `element.id` -- not what the attribute is for, and not valid as an id.

            And the tooltip was a bare English string with no `__()`, so the Arabic page said
            "Copy Link".
        --}}
        <a href="#"
            data-ss-copy="{{ config('app.url') . '/' . $store->slug . '/order/' . \Illuminate\Support\Facades\Crypt::encrypt($order['id']) }}"
            class="btn btn-sm btn-primary btn-badge mr-2 btn-icon d-flex align-items-center" onclick="copyToClipboard(this)"
            title="{{ __('Copy Link') }}" data-bs-toggle="tooltip" data-original-title="{{ __('Click to copy') }}"
            aria-label="{{ __('Copy the public link to this order') }}"><i class="ti ti-link" aria-hidden="true"
                style="font-size:20px"></i></a>
        @php
            $btn_class = 'btn-info';
            if ($order['order_status'] == 2 || $order['order_status'] == 3) {
                $btn_class = 'btn-danger';
            } elseif ($order['order_status'] == 1) {
                $btn_class = 'btn-success';
            } elseif ($order['order_status'] == 4) {
                $btn_class = ' btn-warning';
            } elseif ($order['order_status'] == 5) {
                $btn_class = 'btn-secondary';
            } elseif ($order['order_status'] == 6 || $order['order_status'] == 8) {
                $btn_class = 'btn-dark';
            }

        @endphp


    </div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('order.index') }}">{{ __('Order') }}</a></li>
    <li class="breadcrumb-item"> {{ __('Items from Order') }} {{ $order['order_id'] }}</li>
@endsection

@section('content')
    <div class="row">
    <div class="col-md-12">
            <div class="mt-2 " id="multiCollapseExample1">
                <div class="card">
                    <div class="card-body">
                        <div class="row row-gap  justify-content-end">
                            <div class="col-xxl-2 col-lg-3 col-sm-4 col-12 px-2 d-flex align-items-center">
                                <div class="btn-group w-100" id="deliver_btn">
                                    <button
                                        {{-- P2-04-6. `ss-badge` + THE SAME TONE THE ORDER LIST USES.
                                             `OrderStatusTone` already answers "what colour is this
                                             status" for `OrderDataTable`; before this, the detail
                                             page answered it differently with `$btn_class`, which
                                             measured 2.52:1 in LIGHT mode -- below AA on the most
                                             prominent control on the page. Added rather than
                                             substituted: `btn`, `btn-badge` and `order_status_btn`
                                             are kept because the dropdown's own behaviour and the
                                             theme's sizing hang off them. --}}
                                        class="btn-badge w-100  d-flex align-items-center  justify-content-between text-wrap  btn {{ $btn_class }} ss-badge ss-badge--{{ \App\Support\OrderStatusTone::for((int) $order['order_status']) }} {{ in_array($order['order_status'], [0, 1, 4, 5, 6]) ? 'dropdown-toggle' : '' }} order_status_btn"
                                        type="button" {{ in_array($order['order_status'], [2, 3]) ? 'data-bs-toggle="dropdown"' : '' }}
                                        data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="true">
                                        {{ __('Status') }} : {{ $order['order_status_text'] }}
                                    </button>
                                    @if (in_array($order['order_status'], [0, 1, 4, 5, 6, 7, 8]))
                                        <div class="dropdown-menu ss-menu" data-popper-placement="bottom-start">
                                            <h5 class="dropdown-header text-wrap ss-menu__group" style="font-size: 14px;">{{ __('Set order status') }}</h5>
                                            @if ($order['order_status'] == 0 || $order['order_status'] == 8)
                                                <a class="dropdown-item order_status ss-menu__item" href="#" data-value="confirmed">
                                                    <i class="fa fa-check-circle text-success me-1" style="margin-left: -5px;"></i> {{ __(' Confirmed') }}
                                                </a>
                                                @stack('AddOrderStatus')
                                            @endif
                                            @if ($order['order_status'] == 0 || $order['order_status'] == 4 || $order['order_status'] == 7 || $order['order_status'] == 8)
                                                <a class="dropdown-item order_status ss-menu__item" href="#" data-value="pickedup">
                                                    <i class="fa fa-truck text-success me-1" style="margin-left: -5px;"></i> {{ __(' Picked Up') }}
                                                </a>
                                            @endif
                                            @if (
                                                $order['order_status'] == 0 ||
                                                    $order['order_status'] == 5 ||
                                                    $order['order_status'] == 4 ||
                                                    $order['order_status'] == 7 ||
                                                    $order['order_status'] == 8)
                                                <a class="dropdown-item order_status ss-menu__item" href="#" data-value="shipped">
                                                    <i class="fa fa-spinner text-success me-1" style="margin-left: -5px;"></i> {{ __(' Shipped') }}
                                                </a>
                                            @endif
                                            @if (
                                                $order['order_status'] == 0 ||
                                                    $order['order_status'] == 4 ||
                                                    $order['order_status'] == 5 ||
                                                    $order['order_status'] == 6 ||
                                                    $order['order_status'] == 7 ||
                                                    $order['order_status'] == 8)
                                                <a class="dropdown-item order_status ss-menu__item" href="#" data-value="delivered">
                                                    <i class="fa fa-check text-success me-1" style="margin-left: -5px;"></i> {{ __(' Delivered') }}
                                                </a>
                                            @endif
                                            @if (
                                                $order['order_status'] == 0 ||
                                                    $order['order_status'] == 4 ||
                                                    $order['order_status'] == 5 ||
                                                    $order['order_status'] == 7 ||
                                                    $order['order_status'] == 8)
                                                <a class="dropdown-item order_status ss-menu__item ss-menu__item--danger" href="#" data-value="cancel">
                                                    <i class="fa fa-check text-danger me-1" style="margin-left: -5px;"></i> {{ __(' Cancel Order') }}
                                                </a>
                                            @endif
                                            @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                                                <a class="dropdown-item order_status ss-menu__item ss-menu__item--danger" href="#" data-value="return">
                                                    <i class="fa fa-check text-danger me-1" style="margin-left: -5px;"></i> {{ __(' Return Order') }}
                                                </a>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                            <div class="col-xxl-2 col-lg-3 col-sm-4 col-12  px-2 d-flex align-items-center">
                                {{-- P2-04-6. `ss-select` ADDED, not substituted. Measured in the
                                     running page: this control's options read 4.03:1 in dark mode,
                                     below AA. The existing classes stay because `form-control` and
                                     `select category` may be matched by theme CSS or a handler, and
                                     a restyle that silently removed a hook would break the control
                                     rather than recolour it. --}}
                                {!! Form::select('delivery_boy', $deliveryboys, $order['deliveryboy_id'], [
                                    'class' => 'form-control select category h-100 ss-select',
                                    'style' => 'padding-right: 35px !important;',
                                    'id' => 'delivery_boy',
                                    'disabled' => $disableSelectBox,
                                ]) !!}
                            </div>
                            <div class="col-xxl-2 col-lg-3 col-sm-4 col-12 px-2 d-flex align-items-center">
                                {{-- P2-04-6. TRANSLATED AT THE CALL SITE, not in the model.
                                     `Order::payment_status()` returns `['Paid' => 'Paid', 'Unpaid' => 'Unpaid']`
                                     -- the KEY is what gets posted and stored, the value is only a label, and
                                     the label was rendering in English on the Arabic page. Translating inside
                                     the model would put presentation in the data layer and would surprise any
                                     future caller that reads the values as data (the LynomiaChat fixture seeder
                                     comments on these exact spellings). Mapping here changes only the label. --}}
                                @php
                                    $ssPaymentOptions = collect(App\Models\Order::payment_status())
                                        ->map(fn ($label) => __($label))->all();
                                @endphp
                                {{-- P2-04-6. `ss-select` added; see the delivery select above. --}}
                                {!! Form::select('payment_status', $ssPaymentOptions, $order['payment_status'], [
                                    'class' => 'form-control select category btn-badge h-100 ss-select',
                                    'id' => 'payment_status',
                                ]) !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-sm-12">
            <div class="row" id="printableArea">
                <div class="col-xxl-7">
                    <div class="card">
                        <div class="row">
                            <div class="col-md-6 col-sm-12 col-lg-6 ">
                                <div class="p-3 d-flex gap-3">
                                    <div style="background-color: #E8F8FF; border-radius: 4px; padding: 5px; display: inline-flex;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36" fill="none">
                                            <circle cx="18" cy="18" r="18" fill="#E8F8FF"></circle>
                                            <path d="M16.98 28.055C17.0959 28.2289 17.291 28.3333 17.5 28.3333C17.709 28.3333 17.9041 28.2289 18.02 28.055C19.4992 25.8364 21.6778 23.0963 23.196 20.3096C24.4099 18.0815 25 16.1811 25 14.5C25 10.3645 21.6355 7 17.5 7C13.3645 7 10 10.3645 10 14.5C10 16.1811 10.5901 18.0815 11.804 20.3096C13.3211 23.0942 15.5039 25.841 16.98 28.055ZM17.5 8.25C20.9462 8.25 23.75 11.0538 23.75 14.5C23.75 15.9668 23.2097 17.6716 22.0983 19.7116C20.7897 22.1137 18.9222 24.5503 17.5 26.5987C16.078 24.5506 14.2104 22.1138 12.9017 19.7116C11.7903 17.6716 11.25 15.9668 11.25 14.5C11.25 11.0538 14.0538 8.25 17.5 8.25Z" fill="#002332"></path>
                                            <path d="M17.5 18.25C19.5677 18.25 21.25 16.5677 21.25 14.5C21.25 12.4323 19.5677 10.75 17.5 10.75C15.4323 10.75 13.75 12.4323 13.75 14.5C13.75 16.5677 15.4323 18.25 17.5 18.25ZM17.5 12C18.8785 12 20 13.1215 20 14.5C20 15.8785 18.8785 17 17.5 17C16.1215 17 15 15.8785 15 14.5C15 13.1215 16.1215 12 17.5 12Z" fill="#002332"></path>
                                        </svg>
                                    </div>
                                    {{-- `data-ss-address` IS THE VERIFICATION HOOK. The heading cannot be
                                         one: it is translated, so a browser check that found these two cards
                                         by an English fragment reported "card not found" on the Arabic page
                                         and passed on the English one. Same lesson as the dashboard's
                                         `data-ss-list`. --}}
                                    <h5 class="d-flex align-items-center mb-0" data-ss-address="shipping">{{ __('Shipping Information') }}</h5>
                                </div>

                                <div class="card-body pb-3 pt-0">
                                    <address class="mb-0 text-sm">
                                        <dl class="row mb-0 align-items-center">
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
                                            <dt class="col-sm-3 h6 text-sm mb-0">{{ __('Phone') }}</dt>
                                            <dd class="col-sm-9 text-sm mb-0">
                                                <a href="https://api.whatsapp.com/send?phone={{ !empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : '' }}&amp;text=Hi"
                                                    target="_blank" rel="noopener noreferrer">
                                                    {{ !empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : '' }}
                                                </a>
                                            </dd>
                                        </dl>
                                    </address>
                                </div>
                            </div>
                            <div class="col-md-6 col-sm-12 col-lg-6 ">
                            <div class="p-3 d-flex gap-3">
                                <div style="background-color: rgba(255, 174, 189, 0.41) !important; border-radius: 4px; padding: 5px; display: inline-flex;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 36 36" fill="none">
                                        <circle cx="18" cy="18" r="18" fill="rgba(255, 174, 189, 0.41) !important" fill-opacity="0.31" />
                                        <path d="M27.1475 22.2505V22.2605C27.1421 22.9341 26.5923 23.4806 25.9175 23.4806H10.0834C9.40525 23.4806 8.85336 22.9287 8.85336 22.2505V13.0835C8.85336 12.4053 9.40525 11.8534 10.0834 11.8534H25.9175C26.5956 11.8534 27.1475 12.4053 27.1475 13.0835V22.2505ZM25.9175 10.98H10.0834C8.924 10.98 7.98 11.924 7.98 13.0835V22.2506C7.98 23.41 8.924 24.354 10.0834 24.354H25.9175C27.0769 24.354 28.0209 23.41 28.0209 22.2506V13.0835C28.0209 11.924 27.0769 10.98 25.9175 10.98Z" fill="#D80027" stroke="#D80027" stroke-width="0.04" />
                                        <path d="M27.1475 15.9801H8.85336V14.3534H27.1475V15.9801ZM27.5842 13.48H8.4167C8.17564 13.48 7.98 13.6756 7.98 13.9167V16.4168C7.98 16.6579 8.17564 16.8535 8.4167 16.8535H27.5843C27.8253 16.8535 28.021 16.6579 28.021 16.4168V13.9167C28.0209 13.6756 27.8253 13.48 27.5842 13.48Z" fill="#D80027" stroke="#D80027" stroke-width="0.04" />
                                        <path d="M14.2503 19.314H10.9168C10.6757 19.314 10.4801 19.5096 10.4801 19.7507C10.4801 19.9917 10.6757 20.1873 10.9168 20.1873H14.2503C14.4913 20.1873 14.687 19.9917 14.687 19.7506C14.687 19.5096 14.4913 19.314 14.2503 19.314Z" fill="#D80027" stroke="#D80027" stroke-width="0.04" />
                                        <path d="M16.7504 20.98H10.9168C10.6757 20.98 10.4801 21.1756 10.4801 21.4167C10.4801 21.6578 10.6757 21.8534 10.9168 21.8534H16.7504C16.9914 21.8534 17.1871 21.6578 17.1871 21.4167C17.1871 21.1756 16.9914 20.98 16.7504 20.98Z" fill="#D80027" stroke="#D80027" stroke-width="0.04" />
                                    </svg>
                                </div>
                                <h5 class="d-flex align-items-center mb-0" data-ss-address="billing">{{ __('Billing Information') }}</h5>
                            </div>

                                <div class="card-body pb-3 pt-0">
                                    <dl class="row mb-0 align-items-center">
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
                                        <dt class="col-sm-3 h6 text-sm mb-0">{{ __('Phone') }}</dt>
                                        <dd class="col-sm-9 text-sm mb-0">
                                            <a href="https://api.whatsapp.com/send?phone={{ !empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : '' }}&amp;text=Hi"
                                                target="_blank" rel="noopener noreferrer">
                                                {{ !empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : '' }}
                                            </a>
                                        </dd>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between">
                            <h4 class="mb-0">{{ __('Items from Order') }} {{ $order['order_id'] }} </h4>
                        </div>
                        <div class="card-body">
                            @php
                                /* P2-04-6. ONE query each, scoped to the ORDER's store.
                                   Not the CALLER's: the platform operator legitimately opens another store's order
                                   (the operator limb is first in both OrderController::mayUseOrderScreen() and
                                   OrderDocumentAccessGuard), so `getCurrentStore()` here would blank the line items
                                   for exactly the principal the guard admits. `order_detail()` now returns the
                                   order's own store_id for this. */
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
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>{{ __('Item') }}</th>
                                            <th>{{ __('Quantity') }}</th>
                                            @if ($order['order_status'] == 1)
                                                <th>{{ __('Downloadable Product') }}</th>
                                            @endif
                                            <th>{{ __('Total') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($order['product'] as $item)
                                            @php
                                                /* P2-04-6. Two lookups that used to run PER ITEM and carried NO store
                                                   predicate. `Product` and `ProductVariant` both register no StoreScope
                                                   and neither is in config/tenancy.php's `scoped_models`, so
                                                   `Product::where('id', $item['product_id'])` returned whichever product
                                                   held that id -- and the only field read from it is
                                                   `downloadable_product`, which this view turns into a download link.
                                                   Measured: store A's order naming store B's product id rendered store
                                                   B's file path. `product_json` is written at checkout from the cart,
                                                   and S19 established a cart can carry a foreign product id.

                                                   Both are now resolved once, above the loop, against the ORDER's store
                                                   (see $ssItemProducts below). */
                                                $product = $ssItemProducts[$item['product_id'] ?? null] ?? null;
                                                $variant = $ssItemVariants[$item['variant_id'] ?? null] ?? null;
                                            @endphp

                                            <tr>
                                                <td class="total">
                                                    {{-- P2-04-6. TEXT, NOT A LINK. It was `<a href="#">`: a link to
                                                         nowhere on every line item, which a keyboard user tabs to
                                                         and a screen reader announces as a link. It also measured
                                                         2.59:1 in light mode, because an anchor inherits the brand
                                                         colour on a white surface -- F9's case a third time. A line
                                                         item is a record of what was ordered, including products
                                                         that have since been deleted, so there is nothing it can
                                                         reliably link to. --}}
                                                    <span class="h6 text-sm ss-text-strong">{{ $item['name'] }}</span> <br>
                                                    <span class="text-sm"> {{ $item['variant_name'] }} </span>
                                                </td>
                                                <td>
                                                    @if ($order['paymnet_type'] == 'POS')
                                                        {{ $item['quantity'] }}
                                                    @else
                                                        {{ $item['qty'] }}
                                                    @endif
                                                </td>
                                                {{-- P2-04-6. ONE condition, the SAME one the header uses.
                                                     It read `@if ($product || $variant)` wrapped around
                                                     `@if ($order['order_status'] == 1)`, while the header only
                                                     asks the second -- so a DELIVERED order naming a product that
                                                     has since been deleted rendered three cells against four
                                                     headers and slid Total under "Downloadable Product". The cell
                                                     is now emitted whenever the header is, and what goes INSIDE it
                                                     is the only thing the product's existence decides.

                                                     `text-white` is also gone from the icon: it overrode the
                                                     button's own `--ss-onbrand` colour and left that one icon at
                                                     2.59:1 on the merchant's brand while every other
                                                     `.btn-primary` on the page is correct. --}}
                                                @if ($order['order_status'] == 1)
                                                    @php
                                                        $ssDownloads = array_values(array_filter([
                                                            $variant->downloadable_product ?? null,
                                                            $product->downloadable_product ?? null,
                                                        ]));
                                                    @endphp
                                                    <td>
                                                        @if (!empty($ssDownloads))
                                                            @foreach ($ssDownloads as $ssFile)
                                                                <a class="downloadable_product_{{ $item['product_id'] }}"
                                                                    href="{{ get_file($ssFile) }}"
                                                                    download style="display: none;"></a>
                                                            @endforeach
                                                            <button
                                                                class="download-btn action-btn btn-primary btn btn-sm align-items-center"
                                                                data-bs-toggle="tooltip" data-bs-placement="top"
                                                                title="{{ __('Download') }}"
                                                                aria-label="{{ __('Download :name', ['name' => $item['name'] ?? '']) }}"
                                                                data-product-id="{{ $item['product_id'] }}">
                                                                <i class="ti ti-download" aria-hidden="true"></i>
                                                            </button>
                                                        @else
                                                            -
                                                        @endif
                                                    </td>
                                                @endif
                                                <td>
@if ($order['paymnet_type'] == 'POS')
    {{ order_currency_format_with_sym(($item['orignal_price'] ?? 0) * ($item['quantity'] ?? 0), $order['id']) }}
@else
    @if (module_is_active('ProductPricing') && isset($item['sale_price']))
        {{ order_currency_format_with_sym($item['sale_price'] ?? 0, $order['id']) }}
    @else
        {{ order_currency_format_with_sym($item['final_price'] ?? 0, $order['id']) }}
    @endif
@endif                                                </td>


                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="sub-total-footer">
                                <ul class="list-unstyled mt-4">
                                    <li class="d-flex justify-content-end">
                                        <span>{{ __('Sub Total') }} :</span>
                                        <span
<span class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['sub_total'] ?? 0, $order['id'] ?? ($order['order_id'] ?? 0)) }}</b></span>                                    </li>
                                    <li class="d-flex justify-content-end">
                                        <span>{{ __('Estimated Tax') }} :</span>
                                        <span class="text-start ps-3"><b>
                                            
                                            
@if ($order['paymnet_type'] == 'POS')
    {{ order_currency_format_with_sym($order['tax_price'] ?? 0, $order['id']) }}
@else
    {{ order_currency_format_with_sym($order['tax_price'] ?? 0, $order['id']) }}
@endif                                                
                                                
                                            </b></span>
                                    </li>
                                    @if ($order['paymnet_type'] == 'POS')
                                        <li class="d-flex justify-content-end">
                                            <span>{{ __('Discount') }} :</span>
                                            <span
                                            
class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['coupon_price'] ?? 0, $order['id']) }}</b></span>                                                
                                                
                                                
                                                
                                        </li>
                                    @else
                                    
                                    
<li class="d-flex justify-content-end">
                                    <span>{{ __('Discount') }} :</span>
                                    <span class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['coupon_info']['discount_amount'] ?? 0, $order['id']) }}</b></span>
                                </li>
                            @endif
                            @stack('savePriceShowAdminOrder')
                            <li class="d-flex justify-content-end">
                                <span>{{ __('Delivered Charges') }} :</span>
                                <span class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['delivered_charge'] ?? 0, $order['id']) }}</b></span>
                            </li>
                            <li class="d-flex justify-content-end">
                                <span><b>{{ __('Grand Total') }} :</b></span>
                                <span class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['final_price'] ?? 0, $order['id']) }}</b></span>
                            </li>

<li class="d-flex justify-content-end">
    <span>{{ __('Return') }} :</span>
    <span class="text-start ps-3"><b>{{ order_currency_format_with_sym($order['return_price'] ?? 0, $order['id']) }}</b></span>
</li>
<li class="d-flex justify-content-end">
                                        <span>{{ __('Payment Type') }} :</span>
                                        <span class="text-start ps-3"><b>{{ $order['paymnet_type'] }}</b></span>
                                    </li>


@if(!empty($order['shipping_method']))
<li class="d-flex justify-content-end">
    <span>{{ __('Shipping Method') }} :</span>
    <span class="text-start ps-3">
        <b>{{ $order['shipping_method'] }}</b>
    </span>
</li>
@endif



                                    {{-- P2-04-11. WHICH PATH CREATED THIS ORDER.

                                         Two different statements, not one with a blank value. A
                                         recorded source is named; an unrecorded one says so IN
                                         WORDS rather than showing a dash, because this page has
                                         the room and because "-" beside the labelled rows around
                                         it reads as "the field is empty" instead of "nobody
                                         recorded this". Orders written before the column existed
                                         are in the second case -- and so are orders from writers
                                         the host may not edit (F148, `LotteryPrizes`), which is
                                         why this row states the absence WITHOUT inferring the
                                         order's age. Presenting any of them as storefront orders
                                         is the one thing this row must not do.

                                         `$orderSource` is passed by `OrderController::order_view`
                                         and is store-scoped there; it is NOT part of
                                         `order_detail()`'s array, so no API payload changed. --}}
                                    <li class="d-flex justify-content-end">
                                        <span>{{ __('Source') }} :</span>
                                        <span class="text-start ps-3">
                                            @if (\App\Models\Order::orderSourceLabel($orderSource ?? null) !== null)
                                                <b>{{ \App\Models\Order::orderSourceLabel($orderSource) }}</b>
                                            @else
                                                <b title="{{ __('The source of this order was not recorded.') }}">{{ __('Not recorded') }}</b>
                                            @endif
                                        </span>
                                    </li>
                                    <li class="d-flex justify-content-end">
                                        <span>{{ __('Order Status') }} :</span>
                                        <span class="text-start ps-3"><b>{{ $order['order_status_text'] }}</b></span>
                                    </li>
                                    <li class="d-flex justify-content-end">
                                        <span>{{ __('Last Return Date') }} :</span>
                                        <span class="text-start ps-3"><b>-</b></span>
                                    </li>
                                </ul>
                                @if(isset($order['additional_note']) || isset($order['payment_comment']))
                                <div>
                                    <span class="text-danger"><b>{{ __('Note') }} :</b></span>
                                    <span class="text-start ps-3"><p>{{ $order['additional_note'] ?? ($order['payment_comment'] ?? '-') }}</p></span>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                    @stack('OrderPartialPaymentView')
                </div>
                <div class="col-xxl-5">
                    @permission('Manage Order Note')
                    <div class="card  p-0">
                        <div class="card-header d-flex justify-content-between pb-0">
                            <h4 class="mb-4">{{ __('Order Notes') }}</h4>
                        </div>
                        <div class="card-body">
                            @foreach ($order_notes as $note)
                                <div class="card">
                                    @if ($note->status == 'Stock Manage')
                                        <div class="card-header note-bg-color  stock-mange-note">
                                            <span class="tl-btn licence-btn ">
                                                {{ $note->notes }}
                                            </span>
                                            <div class="cart-time-wrapper">
                                                {{-- P2-04-6. NO AUTHOR. This read
                                                     `{{ __(' by ') }}{{ Auth::user()->name }}` -- the name of
                                                     whoever is LOOKING at the page, so the same note read "by
                                                     Amina" to Amina and "by Khalid" to Khalid. And there is no
                                                     honest name to put in its place: `order_notes` has NO author
                                                     column (one migration, no later one, nothing in
                                                     `_gate_f_pending/`, nothing in any module), and `customer_id`
                                                     is a FK to the SHOPPER while these notes are written by staff.
                                                     Adding `created_by` is a migration and therefore an owner
                                                     decision, so the timestamp stands alone. --}}
                                                <span
                                                    class="time">{{ $note->created_at->format('d M Y h:i A') }}</span>
                                                @include('order.partials.note-author', ['note' => $note])
                                                @permission('Delete Order Note')
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['order-note.destroy', $note->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm order-not-dlt"
                                                    data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                    data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                    aria-label="{{ __('Delete this note') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                                @endpermission
                                            </div>
                                        </div>
                                    @elseif ($note->status == 'Order Created')
                                        <div class="card-header note-bg-color order-create-note">
                                            <span class="tl-btn licence-btn ">
                                                {{ $note->notes }}
                                            </span>
                                            <div class="cart-time-wrapper">
                                                <span
                                                    class="time">{{ $note->created_at->format('d M Y h:i A') }}</span>
                                                @include('order.partials.note-author', ['note' => $note])
                                                @permission('Delete Order Note')
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['order-note.destroy', $note->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm order-not-dlt"
                                                    data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                    data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                    aria-label="{{ __('Delete this note') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                                @endpermission
                                            </div>
                                        </div>
                                    @elseif ($note->status == 'Order status change')
                                        <div class="card-header note-bg-color order-status-note">
                                            <span class="tl-btn licence-btn ">
                                                {{ $note->notes }}
                                            </span>
                                            <div class="cart-time-wrapper">
                                                <span
                                                    class="time">{{ $note->created_at->format('d M Y h:i A') }}</span>
                                                @include('order.partials.note-author', ['note' => $note])
                                                @permission('Delete Order Note')
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['order-note.destroy', $note->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm order-not-dlt"
                                                    data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                    data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                    aria-label="{{ __('Delete this note') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                                @endpermission
                                            </div>
                                        </div>
                                    @elseif ($note->status == '' && $note->note_type == 'private_note')
                                        <div class="card-header note-bg-color admin-private-note">
                                            <span class="tl-btn licence-btn ">
                                                {{ $note->notes }}
                                            </span>
                                            <div class="cart-time-wrapper">
                                                <span
                                                    class="time">{{ $note->created_at->format('d M Y h:i A') }}</span>
                                                @include('order.partials.note-author', ['note' => $note])
                                                @permission('Delete Order Note')
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['order-note.destroy', $note->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm order-not-dlt"
                                                    data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                    data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                    aria-label="{{ __('Delete this note') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                                @endpermission
                                            </div>
                                        </div>
                                    @elseif ($note->status == '' && $note->note_type == 'to_customer')
                                        <div class="card-header note-bg-color customer-note">
                                            <span class="tl-btn licence-btn ">
                                                {{ $note->notes }}
                                            </span>
                                            <div class="cart-time-wrapper">
                                                <span
                                                    class="time">{{ $note->created_at->format('d M Y h:i A') }}</span>
                                                @include('order.partials.note-author', ['note' => $note])
                                                @permission('Delete Order Note')
                                                {!! Form::open([
                                                    'method' => 'DELETE',
                                                    'route' => ['order-note.destroy', $note->id],
                                                    'class' => 'd-inline',
                                                ]) !!}
                                                <button type="button"
                                                    class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm order-not-dlt"
                                                    data-confirm="{{ __('Are You Sure?') }}"
                                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                                    data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                                                    data-bs-toggle="tooltip" title="{{ __('Delete') }}"
                                                    aria-label="{{ __('Delete this note') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                                @endpermission
                                            </div>
                                        </div>
                                    @endif

                                </div>
                            @endforeach
                            @permission('Create Order Note')
                            {{ Form::open(['route' => 'order-note.store', 'method' => 'POST', 'id' => 'choice_form', 'enctype' => 'multipart/form-data']) }}
                            <div class="row">
                                <input type="hidden" class="hidden" name="order_id" value="{{ $order['id'] }}">
                                <div class="form-group col-md-8">
                                    {!! Form::label('', __('Note'), ['class' => 'form-label']) !!}
                                    <textarea name="note" rows="3" class="autogrow form-control" placeholder="Enter Message here..."></textarea>
                                </div>
                                <div class="form-group col-md-4">
                                    {!! Form::label('', __('Note Type'), ['class' => 'form-label']) !!}
                                    {{-- P2-04-6. `ss-select` added: these two options measured
                                         4.03:1 in dark mode. The labels are translated at the call
                                         site for the same reason the payment select's are -- the
                                         KEY is what gets posted and stored. --}}
                                    {!! Form::select('note_type', [
                                        'private_note' => __('Private Note'),
                                        'to_customer' => __('To Customer'),
                                    ], null, [
                                        'class' => 'form-control ss-select',
                                    ]) !!}
                                </div>
                                <div class="form-group col-md-4">
                                    <input type="submit" value="Create" class="btn btn-primary btn-badge">
                                </div>
                            </div>
                            {!! Form::close() !!}
                            @endpermission
                        </div>
                    </div>
                    @endpermission
                    @stack('showdigitalproductattachment')
                    @stack('CheckoutAttachment')
                    @stack('ViewAdditionalFields')
                </div>
            </div>
        </div>
    </div>
@endsection

@push('custom-script')
    <script src="{{ asset('js/html2pdf.bundle.min.js') }}{{ '?' . time() }}"></script>
    <script>
        var filename = $('#filesname').val();

        function saveAsPDF() {
            var element = document.getElementById('printableArea');
            var opt = {
                margin: 0.3,
                filename: filename,
                image: {
                    type: 'jpeg',
                    quality: 1
                },
                html2canvas: {
                    scale: 4,
                    dpi: 72,
                    letterRendering: true
                },
                jsPDF: {
                    unit: 'in',
                    format: 'A2'
                }
            };
            html2pdf().set(opt).from(element).save();

        }

        $(document).on('click', '.order_return', function() {
            var product_id = $(this).attr('product-id');
            var variant_id = $(this).attr('variant-id');
            var order_id = $(this).attr('order-id');

            var data = {
                product_id: product_id,
                variant_id: variant_id,
                order_id: order_id
            }
            $.ajax({
                url: '{{ route('order.return') }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status == 'error') {
                        show_toastr('{{ __('Error') }}', data.message, 'error')
                    } else {
                        show_toastr('{{ __('Success') }}', data.message, 'success')
                        $(this).parent().append('<h6 class="text-danger">{{ __('Returned') }}</h6>');
                        $(this).parent().find('.order_return').remove();
                    }
                },
                error: function (xhr) {
                    /*
                     * P1-INV-15 (F). There was NO error: handler, so every non-2xx answer was a
                     * SILENT no-op: the spinner stayed up and the merchant was told nothing. That
                     * matters now because the return endpoints legitimately answer 403 (P1-INV-02
                     * added the ownership and permission check) and 419 on an expired CSRF token,
                     * and a refusal the user cannot see reads as a broken button.
                     */
                    $('#loader').fadeOut();
                    var message = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : (xhr.status === 403
                            ? '{{ __('You are not allowed to do this.') }}'
                            : (xhr.status === 419
                                ? '{{ __('Your session has expired. Please reload the page.') }}'
                                : '{{ __('Something went wrong. Please try again.') }}'));
                    show_toastr('{{ __('Error') }}', message, 'error')
                }
            });
        });

        $(document).on('click', '.order_status', function() {
            var status = $(this).attr('data-value');

            var data = {
                delivered: status,
                id: "{{ $order['id'] }}",
            }
            $('#loader').fadeIn();
            $.ajax({
                url: '{{ route('order.status.change', $order['id']) }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status == false) {
                        show_toastr('{{ __('Error') }}', data.message, 'error')
                    } else {
                        var newStatusText = data.order_status;
                        $('.order_status_btn').text('{{ __('Status') }} : ' + newStatusText);
                    }
                },
                error: function(xhr, status, error) {
                    $('#loader').fadeOut();
                    show_toastr('{{ __('Error') }}', error, 'error');
                },
                complete: function() {
                    $('#loader').fadeOut();
                   show_toastr('{{ __('Success') }}', 'Order status changed.', 'success');
                }
            });
        });

        $(document).on('change', '#payment_status', function() {
            var payment_status = $(this).val();
            var data = {
                payment_status: payment_status,
                order_id: "{{ $order['id'] }}",

            }
            $.ajax({
                url: '{{ route('order.payment.status') }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(data) {
                    $('#loader').fadeOut();
                    show_toastr('{{ __('Success') }}', data.message, 'success')


                }
            });
        });

        $(document).on('change', '#delivery_boy', function() {
            var delivery_boy = $(this).val();

            var data = {
                delivery_boy: delivery_boy,
                order_id: "{{ $order['id'] }}",
            }

            $.ajax({
                url: '{{ route('order.assign') }}',
                method: 'POST',
                data: data,
                context: this,
                success: function(data) {
                    $('#loader').fadeOut();
                    show_toastr('{{ __('Success') }}', data.message, 'success')


                },
                {{-- P1-PLT-08. There was no error callback, so a refusal from this endpoint -- the 403 the
                     assignment guard returns, or the one authorize('assign') already threw -- showed the
                     merchant nothing: no toast, and the dropdown left showing a driver that was not saved.
                     The same gap P1-SEC-34 found in SpamPrevent's widget. --}}
                error: function(xhr) {
                    $('#loader').fadeOut();

                    var message = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : '{{ __('The delivery boy could not be assigned.') }}';

                    show_toastr('{{ __('Error') }}', message, 'error');
                }
            });
        });

        function myFunction() {
            var copyText = document.getElementById("myInput");
            copyText.select();
            copyText.setSelectionRange(0, 99999)
            document.execCommand("copy");
            show_toastr('Success', 'Link copied', 'success');
        }

        function copyToClipboard(element) {

            // `data-ss-copy`, not `id`: the url used to be stored in the element's id.
            var copyText = element.dataset.ssCopy || element.id;
            document.addEventListener('copy', function(e) {
                e.clipboardData.setData('text/plain', copyText);
                e.preventDefault();
            }, true);

            document.execCommand('copy');
            show_toastr('success', 'Url copied to clipboard', 'success');
        }

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
@endpush