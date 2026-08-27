@php
    $show_status = false;
@endphp
@if ($order_refunds && $order_refunds->order_id == $order['id'])
    @php
        $show_status = true;
        $productRefundIdData = json_decode($order_refunds['product_refund_id']);
        if (is_array($productRefundIdData)) {
            foreach ($productRefundIdData as $item) {
                $productRefundId = $item->product_refund_id;
                $returnPrice = $item->return_price;
                $quantity = $item->quantity;
            }
        }
        $order['coupon_info']['discount_amount'] = 0;
        $order_sum = $order['tax_price'] + $order_refunds->product_refund_price + $order['delivered_charge'];
        $final_price = $order_sum - $order['coupon_info']['discount_amount'];
    @endphp
@endif

<div class="md:container w-full mx-auto">
    {{ Form::open(['route' => ['order.refund.request', $store->slug, $order['id']], 'method' => 'POST', 'enctype' => 'multipart/form-data', 'id' => 'form_refund_data']) }}
    <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6 order-details-modal" id="printableArea">
        <div class="lg:col-span-2 space-y-6">
    
            @if ($themeSettings['order_info_item_status'] && $themeSettings['order_info_item_status'] == '1')
                <!-- Items Section -->
                <div class="card p-4">
                  <h2 class="font-semibold text-gray-800 mb-4 text-lg">{{ __('Items from Order') }} {{ $order['order_id'] }}</h2>
                  <div class="bg-white rounded border overflow-auto" id="carthtml">
                    <table class="min-w-full text-sm text-gray-700">
                      <thead class="bg-gray-50 border-b text-gray-800 font-medium">
                        <tr>
                            <th class="text-left rtl:text-right px-4 py-3">{{ __('ITEM') }}</th>
                            <th class="text-center px-4 py-3">{{ __('QUANTITY') }}</th>
                            <th class="text-right px-4 py-3">{{ __('TOTAL') }}</th>
                            @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                            <th class="text-right px-4 py-3">{{ __('Return') }}</th>
                            @endif
                            <th class="text-right px-4 py-3">{{ __('Action') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        @foreach ($order['product'] as $id => $item)
                            @php
                                $download_prod = \App\Models\ProductVariant::where('id', $item['variant_id'])->first();
                            @endphp
                            <tr id="product-id-{{ $id }}" class="border-b">
                                <td class="px-4 py-3 text-gray-800">{{ $item['name'] }} 
                                    <p class="font-medium text-sm text-gray-500">
                                        @if ($item['variant_id'] != 0)
                                            {!! \App\Models\ProductVariant::variantlist($item['variant_id']) !!}
                                        @endif
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-center text-gray-600">
                                    @if ($order['paymnet_type'] == 'POS')
                                        {{ $item['quantity'] }}
                                    @else
                                        <input type="hidden" name="order_id" value="{{ $order['id'] }}">
                                        @if (!$show_status)
                                            <div class="col-span-2 flex items-center justify-between md:justify-center">
                                                <div class="flex items-center border rounded-md text-sm quantity buttons_added">
                                                    <input type="button" value="-" class="minus px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm">
                                                    <input type="number" step="1" min="1"
                                                        max="{{ $item['qty'] }}" name="quantity[]"
                                                        title="{{ __('Quantity') }}"
                                                        class="input-number qtyyyy w-8 text-center p-1 focus:ring-0 border-s border-e outline-none"
                                                        data-id="{{ $item['product_id'] }}"
                                                        data-url="{{ url($store->slug, 'change-refund-cart') }}"
                                                        data-order-id="{{ $order['id'] }}"
                                                        data-product-id="{{ $item['product_id'] }}"
                                                        size="4" value="{{ $item['qty'] }}"
                                                        >
                                                    <input type="button" value="+" class="plus px-2 py-1 text-gray-500 hover:bg-gray-100 text-sm">
                                                </div>
                                            </div>
                                        @else
                                            {{ $quantity }}
                                        @endif
                                    @endif
                                </td>
                                <input type="hidden" class="product_refund_price" name="return_price[]"
                                        value="@if ($order['paymnet_type'] == 'POS') {{ currency_format_with_sym( ($item['orignal_price'] ?? 0), $store->id) ?? SetNumberFormat($item['orignal_price']) }} @else {{ currency_format_with_sym( ($item['final_price'] ?? 0), $store->id) ?? SetNumberFormat($item['final_price']) }} @endif">
                                <td class="px-4 py-3 text-right text-gray-800 font-medium product_price">
                                    @if (!$show_status)
                                        @if ($order['paymnet_type'] == 'POS')
                                            {{ currency_format_with_sym($item['orignal_price'] ?? 0, $store->id) ?? SetNumberFormat($item['orignal_price']) }}
                                        @else
                                            {!! \App\Models\Product::ManageProductPrice($item, $store) !!}
                                        @endif
                                    @else
                                        {{ $returnPrice }}
                                    @endif
                                </td>
                                @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                                    <td><div class="px-4 py-3 text-center text-gray-600"> - </div></td>
                                @endif
                                @if ($order['order_status_text'] == 'Delivered' && !empty($download_prod->downloadable_product))
                                    <tr>
                                        <td>
                                            <div class="detail-bottom">
                                                <a href="{{ get_file($download_prod->downloadable_product) }}"
                                                    data-value="{{ asset($download_prod->downloadable_product) }}"
                                                    data-id="{{ $order['id'] }}"
                                                    class="btn-primary p-2 h-10 w-10 flex items-center justify-center btn cart-btn downloadable_prodcut" download>{{ __('Download') }}
                                                    <i class="fas fa-shopping-basket"></i>
                                                </a>

                                            </div>
                                        </td>
                                        <td>
                                            <p>{{ __('Get your product from here') }}</p>
                                        </td>
                                    </tr>
                                @endif
                                <td>
                                    <div class="px-4 py-3 text-center text-gray-600">
                                        <div class="checkbox-custom">
                                            <input type="checkbox" name="product_refund_id[]"
                                                {{ $show_status ? 'checked' : '' }}
                                                id="{{ $item['product_id'] }}" value="{{ $item['product_id'] }}">
                                            <label for="{{ $item['product_id'] }}">
    
                                            </label>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                      </tbody>
                    </table>
                  </div>
                </div>
            @endif
            {{-- <div class="row"> --}}
                <div class="space-y-6">
                    <div class="card p-4">
                        <div class="grid grid-cols-1 md:grid-cols-1 gap-4">
                            <div class="">
                                <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Order Refund Reason') }}</label>
                                {!! Form::select('refund_reason', $refund_order, $order_refunds ? $order_refunds->refund_reason : null, [
                                    'class' => 'select2 w-full rounded-md border focus:ring-primary focus:border-primary',
                                    'id' => 'RefundReasonss',
                                ]) !!}
                            </div>
                            <div class="OtherReason">
                                <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Add Your Reason') }}</label>
                                {!! Form::text('custom_refund_reason', $order_refunds ? $order_refunds->custom_refund_reason : null, [
                                    'class' => 'form-input',
                                ]) !!}
                            </div>
                        </div>
                    </div>
                </div>
                @if (isset($RefundStatus['attachment']) && $RefundStatus['attachment'] == '1')
                    <div class="space-y-6">
                        <div class="card p-4">
                            <div class="">
                                <label class="block mb-2 font-medium md:text-base text-sm">{{ __('Attachments') }}:</label><small>({{ __('You can select multiple files') }})</small>

                                <div class="input-group file-select-set mb-3">
                                    <input type="text" class="form-input p-2 rounded" readonly=""
                                        placeholder="Choose file" id="attachments">
                                    <input type="file"
                                        class="form-input file-opc {{ $errors->has('attachments') ? ' is-invalid' : '' }}"
                                        name="attachments[]" id="file" aria-label="Upload" multiple=""
                                        data-filename="multiple_file_selection"
                                        onchange="document.getElementById('blah').src = window.URL.createObjectURL(this.files[0])">
                                    <label class="input-group-text file-opc bg-primary" for="attachments"><i
                                            class="ti ti-circle-plus"></i>{{ __('Browse') }}</label>
                                    <img src="" id="blah" width="20%" />

                                </div>
                                <p class="multiple_file_selection mx-4"></p>
                            </div>
                        </div>
                    </div>
                @endif
            {{-- </div> --}}
        </div>
        <div class="space-y-6">
            <div class="card p-4">
                <h3 class="font-semibold text-gray-800 mb-4 text-lg">{{ __('Extra Information') }}</h3>
                <ul class="space-y-3 text-sm">
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Sub Total') }}:</span>
                        <input type="hidden" name="product_sub_total" class="product_price_1" value="{{ $order['sub_total'] }}">
                        <span class="text-gray-800 product_price_1 CURRENCY">
                            @if (!$show_status)
                                <span class="">{{ currency_format_with_sym( ($order['sub_total'] ?? 0), $store->id) ?? SetNumberFormat($order['sub_total']) }}</span>
                            @else
                                {{ currency_format_with_sym( ($order_refunds->product_refund_price ?? 0), $store->id) ?? SetNumberFormat($order_refunds->product_refund_price) }}
                            @endif
                        </span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Estimated Tax') }}:</span>
                        <span class="text-gray-800">
                            {{ currency_format_with_sym( ($order['tax_price'] ?? 0), $store->id) ?? SetNumberFormat($order['tax_price']) }}
                        </span>
                    </li>
                    @if ($order['paymnet_type'] == 'POS')
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Discount') }}:</span>
                        <span class="text-gray-800">{{ currency_format_with_sym( ($order['coupon_price'] ?? 0), $store->id) ?? (!empty($order['coupon_price']) ? SetNumberFormat($order['coupon_price']) : SetNumberFormat(0)) }}</span>
                    </li>
                    @else
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Apply Coupon') }}:</span>
                        <span class="text-gray-800">{{ currency_format_with_sym( ($order['coupon_info']['discount_amount'] ?? 0), $store->id) ?? (!empty($order['coupon_info']['discount_amount']) ? SetNumberFormat($order['coupon_info']['discount_amount']) : SetNumberFormat(0)) }}</span>
                    </li>
                    @endif
                    @stack('savePriceShowOrderPage')
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Delivered Charges') }}:</span>
                        <span class="text-gray-800">{{ currency_format_with_sym( ($order['delivered_charge'] ?? 0), $store->id) ?? SetNumberFormat($order['delivered_charge']) }}</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Grand Total') }}:</span>
                        <input type="hidden" name="grand_total" class="grand_total" value="{{ $order['final_price'] }}">
                        <span class="text-gray-800 grand_total CURRENCY">
                            @if (!$show_status)
                                <b>{{ currency_format_with_sym( ($order['final_price'] ?? 0), $store->id) ?? SetNumberFormat($order['final_price']) }}</b>
                            @else
                                <b>{{ currency_format_with_sym( ($final_price ?? 0), $store->id) ?? SetNumberFormat($final_price) }}</b>
                            @endif
                        </span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Payment Type') }}:</span>
                        <span class="text-gray-800">{{ $order['paymnet_type'] }}</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-gray-800">{{ __('Order Status') }}:</span>
                        <span class="text-orange-600 font-medium">{{ $order['order_status_text'] }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="form-container">
        <div class="grid grid-cols-1 md:grid-cols-1 gap-4 pt-4">

            @if (!$show_status)
                <div class="checkbox-custom checkbox flex items-center">
                    <input type="checkbox" name="agg" id="agg" class="rounded border text-primary focus:ring-primary mr-2" {{ old('agg') ? 'checked' : '' }} />
                    <label for="agg" class="flex-1 font-medium">{{ __('I have read and agree to the') }}
                        @foreach ($pages as $page)
                            @if ($page->page_slug == 'refund-policy')
                                <a href="{{ url($store->slug . '/' . $page->page_slug) }}"
                                    target="_blank">{{ __('Refund') }} &amp;
                                    {{ __('Policy') }}.
                                </a>
                            @else
                            @endif
                        @endforeach
                    </label>
                </div>
                <div class="flex flex-wrap gap-4">
                    <button type="submit" class="btn-primary refund_button">
                        {{ __('Refund Request') }}
                    </button>
                    <button type="button" class="close-modal bg-gray-50 border text-gray-700 font-medium py-2.5 px-6 rounded-md hover:bg-primary/10 transition-all duration-300">
                        {{ __('Cancel') }}
                    </button>
                </div>
            @endif
        </div>
    </div>
    {!! Form::close() !!}
</div>

<script>
    $(document).ready(function() {
        $("#form_refund_data").submit(function(e) {
            // $('#loader').fadeIn();
            $(".refund_button").attr("disabled", true);
            return true;
        });
    });
</script>
<script>
    $(document).ready(function() {
        $("#RefundReasonss").trigger('change');
    });

    $(document).on('change', '#RefundReasonss', function(e) {
        var conceptName = $('#RefundReasonss').find(":selected").val();
        if (conceptName == "Other") {
            $('.OtherReason').addClass('d-block');
            $('.OtherReason').removeClass('d-none');
        } else {
            $('.OtherReason').removeClass('d-block');
            $('.OtherReason').addClass('d-none');
        }
    });

    $(document).on('change keyup', '#carthtml input[name="quantity[]"]', function(e) {
        e.preventDefault();
        var ele = $(this);
        var url = ele.data('url');
        var quantity = ele.val();
        var order_id = ele.data('order-id');
        var product_id = ele.data('product-id');

        var data = {
            order_id: order_id,
            quantity: quantity,
            product_id: product_id,
        }

        var closestTr = ele.closest('tr');

        $.ajax({
            url: url,
            method: 'GET',
            data: data,
            context: this,
            success: function(data) {
                $('#loader').fadeOut();
                $('.CURRENCY').html(data.CURRENCY);

                var productPrice = parseFloat(data.product_price.replace(/[^0-9.-]+/g, ""));
                closestTr.find('.product_price').text(data.product_price);
                closestTr.find('.product_refund_price').val(data.product_price);

                var totalSum = 0;
                $('#carthtml input[name="quantity[]"]').each(function() {
                    var rowPrice = parseFloat($(this).closest('tr').find('.product_price')
                        .text().replace(/[^0-9.-]+/g, ""));
                    totalSum += rowPrice;
                });

                var taxPrice = parseFloat(data.tax_price.replace(/[^0-9.-]+/g, ""));
                var deliveredCharge = parseFloat(data.delivered_charge.replace(/[^0-9.-]+/g, ""));
                var discountPrice = parseFloat(data.discount_price.replace(/[^0-9.-]+/g, ""));
                var sum = taxPrice + deliveredCharge + totalSum - discountPrice;

                var totalSum = data.CURRENCY + totalSum.toFixed(2);

                $('.product_price_1').html(totalSum);
                $('.product_price_1').val(totalSum);
                $('.grand_total').html('<b>' + data.CURRENCY + sum.toFixed(2) + '</b>');
                $('.grand_total').val(data.CURRENCY + sum.toFixed(2));
            }
        });
    });
</script>
