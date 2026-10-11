@extends('front_end.layouts.app')

@section('page-title')
{{ __('Order Detail') }}
@endsection

@section('content')
    @if ($themeSettings['order_info_banner_status'] && $themeSettings['order_info_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['order_info_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['order_info_banner_title'] ?? '' }}</h2>
            </div>
        </div>
    </section>
    @endif
    
    @if ($themeSettings['order_info_status'] && $themeSettings['order_info_status'] == '1')
    <section class="py-10 lg:py-20">
      <div class="md:container w-full mx-auto px-4">
        <div class="flex justify-between items-center flex-wrap gap-4 pb-4 border-b border-gray-300">
          <h2 class="md:text-2xl text-[22px] font-bold text-gray-800">{{ $themeSettings['order_info_title'] ?? __('your Order details') }}</h2>
          <div class="flex gap-2">
            @if (module_is_active('AutomaticOrderPrinting'))
                @stack('invoice-button')
            @else
            <a href="{{ route('shippinglabel.pdf', encrypt($order['id'])) }}"
                target="_blank" type="button" class="btn-primary p-2 h-10 w-10 flex items-center justify-center">
              <i class="fas fa-arrow-down"></i>
            </a>
            @endif
            <span class="border border-gray-300 rounded-md font-semibold text-gray-800 py-2 px-4 h-[40px]">
              {{ $order['order_status_text'] }}
            </span>
          </div>
        </div>

        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Left Column -->
          <div class="lg:col-span-2 space-y-6">
            @if ($themeSettings['order_info_item_status'] && $themeSettings['order_info_item_status'] == '1')
            <!-- Items Section -->
            <div class="card p-4">
              <h2 class="font-semibold text-gray-800 mb-4 text-lg">{{ $themeSettings['order_info_item_title'] ?? __('Items from Order') }} {{ $order['order_id'] }}</h2>
              <div class="bg-white rounded border overflow-auto" id="printableArea">
                <table class="min-w-full text-sm text-gray-700">
                  <thead class="bg-gray-50 border-b text-gray-800 font-medium">
                    <tr>
                      <th class="text-left rtl:text-right px-4 py-3">{{ __('ITEM') }}</th>
                      <th class="text-center px-4 py-3">{{ __('QUANTITY') }}</th>
                      <th class="text-right px-4 py-3">{{ __('TOTAL') }}</th>
                      @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                         <th class="text-right px-4 py-3">{{ __('Return') }}</th>
                      @endif
                      @if ($order['order_status'] == 1)
                          <th class="text-right px-4 py-3">{{ __('Downloadable Product') }}</th>
                      @endif
                    </tr>
                  </thead>
                  <tbody>
                    @foreach ($order['product'] as $item)
                        @php
                            $download_prod = \App\Models\ProductVariant::where('id', $item['variant_id'])->first();
                            $download_product = \App\Models\Product::where('id', $item['product_id'])->first();
                        @endphp
                    <tr class="border-b">
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
                                {{ $item['qty'] }}
                            @endif
                      </td>
                      
                      {{-- ✅ استخدام دالة تنسيق العملة التاريخية بناءً على معرف الطلب --}}
                      <td class="px-4 py-3 text-right text-gray-800 font-medium">
                          @if ($order['paymnet_type'] == 'POS')
                              {{ order_currency_format_with_sym($item['orignal_price'] ?? 0, $order['id']) }}
                          @else
                              {{ order_currency_format_with_sym($item['final_price'] ?? 0, $order['id']) }}
                          @endif
                      </td>

                      @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                          <td class="px-4 py-3 text-center text-gray-600"> - </td>
                      @endif
                      
                      @if ($order['order_status_text'] == 'Delivered')
                          @if (!empty($download_prod->downloadable_product) || !empty($download_product->downloadable_product))
                          <td class="px-4 py-3 justify-center text-gray-600">
                            <div class="flex gap-2 justify-center">
                              @if (!empty($download_product->downloadable_product))
                                <a href="{{ get_file($download_product->downloadable_product) }}" type="button" class="btn-primary p-2 h-10 w-10 flex items-center justify-center download_prod_{{ $item['product_id'] }}">
                                  <i class="fas fa-arrow-down"></i>
                                </a>
                              @endif
                                <a href="{{ get_file($download_product->downloadable_product) }}" data-product-id="{{ $item['product_id'] }}" class="btn-primary p-2 h-10 w-10 flex items-center justify-center downloadable_product_variant" download>
                                  <i class="fas fa-shopping-basket"></i>
                                </a>
                            </div>
                          </td>
                          @endif
                      @endif
                    </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            </div>
            @endif

            @if ($themeSettings['order_info_address_status'] && $themeSettings['order_info_address_status'] == '1')
            <!-- Shipping and Billing Information -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <!-- Shipping Information -->
              <div class="card p-4">
                <h3 class="font-semibold text-gray-800 mb-4">{{ $themeSettings['order_info_address_ship'] ?? __('Shipping Information') }}</h3>
                <ul class="space-y-2 text-sm">
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Name') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['name']) ? $order['delivery_informations']['name'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Email') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['email']) ? $order['delivery_informations']['email'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('City') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['city']) ? $order['delivery_informations']['city'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('State') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['state']) ? $order['delivery_informations']['state'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Country') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['country']) ? $order['delivery_informations']['country'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Postal Code') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['post_code']) ? $order['delivery_informations']['post_code'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Phone') }}</span>
                    <span class="text-gray-800">{{ !empty($order['delivery_informations']['phone']) ? $order['delivery_informations']['phone'] : '' }}</span>
                  </li>
                </ul>
              </div>

              <!-- Billing Information -->
              <div class="card p-4">
                <h3 class="font-semibold text-gray-800 mb-4">{{ $themeSettings['order_info_address_bill'] ?? __('Billing Information') }}</h3>
                <ul class="space-y-2 text-sm">
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Name') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['name']) ? $order['billing_informations']['name'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Email') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['email']) ? $order['billing_informations']['email'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('City') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['city']) ? $order['billing_informations']['city'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('State') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['state']) ? $order['billing_informations']['state'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Country') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['country']) ? $order['billing_informations']['country'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Postal Code') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['post_code']) ? $order['billing_informations']['post_code'] : '' }}</span>
                  </li>
                  <li class="flex gap-3">
                    <span class="font-semibold md:w-[150px] w-[100px] text-gray-900">{{ __('Phone') }}</span>
                    <span class="text-gray-800">{{ !empty($order['billing_informations']['phone']) ? $order['billing_informations']['phone'] : '' }}</span>
                  </li>
                </ul>
              </div>
            </div>
            @endif

            @stack('showdigitalproductattachment')
            @stack('CheckoutAttachment')
            @stack('ViewAdditionalFields')
          </div>

          <!-- Right Column - Order Summary -->
          <div class="space-y-6">
            @if ($themeSettings['order_info_amount_status'] && $themeSettings['order_info_amount_status'] == '1')
            <!-- Extra Information -->
            <div class="card p-4">
              <h3 class="font-semibold text-gray-800 mb-4 text-lg">{{ $themeSettings['order_info_amount_title'] ?? __('Extra Information') }}</h3>
              <ul class="space-y-3 text-sm">
                <li class="flex justify-between">
                  <span class="text-gray-800">{{ __('Sub Total') }}:</span>
                  <span class="text-gray-800">{{ order_currency_format_with_sym($order['sub_total'] ?? 0, $order['id']) }}</span>
                </li>
                
                <li class="flex justify-between">
                  <span class="text-gray-800">{{ __('Estimated Tax') }}:</span>
                  <span class="text-gray-800">{{ order_currency_format_with_sym($order['tax_price'] ?? 0, $order['id']) }}</span>
                </li>
                
                @if ($order['paymnet_type'] == 'POS')
                <li class="flex justify-between">
                  <span class="text-gray-800">{{ __('Discount') }}:</span>
                  <span class="text-gray-800">{{ order_currency_format_with_sym($order['coupon_price'] ?? 0, $order['id']) }}</span>
                </li>
                @else
                <li class="flex justify-between">
                  <span class="text-gray-800">{{ __('Apply Coupon') }}:</span>
                  <span class="text-gray-800">{{ order_currency_format_with_sym($order['coupon_info']['discount_amount'] ?? 0, $order['id']) }}</span>
                </li>
                @endif
                
                @stack('savePriceShowOrderPage')
                
                <li class="flex justify-between">
                  <span class="text-gray-800">{{ __('Delivered Charges') }}:</span>
                  <span class="text-gray-800">{{ order_currency_format_with_sym($order['delivered_charge'] ?? 0, $order['id']) }}</span>
                </li>
                
                

                
                <li class="flex justify-between">
                  <span class="text-gray-800 font-bold">{{ __('Grand Total') }}:</span>
                  <span class="text-gray-800 font-bold">{{ order_currency_format_with_sym($order['final_price'] ?? 0, $order['id']) }}</span>
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
            @endif

            @if ($themeSettings['order_info_note_status'] && $themeSettings['order_info_note_status'] == '1' && isset($order_note) && !empty($order_note))
            <!-- Order Updates -->
            <div class="card p-4">
              <h3 class="font-semibold text-gray-800">{{ $themeSettings['order_info_note_title'] ?? __('Order updates for') }} {{ $order['order_id'] }}</h3>
                <div class="card-body">
                    @php $i = 1; @endphp
                    @foreach ($order_note as $note)
                        <div class="card">
                            <div class="card-header">
                                <span class="time">{{ $i }} . {{ $note->created_at->format('l jS \of F Y, h:ia') }}</span>
                                <span class="tl-btn licence-btn">{{ $note->notes }}</span>
                            </div>
                        </div>
                        @php $i++; @endphp
                    @endforeach
                </div>
            </div>
            @endif
            @stack('OrderPartialPaymentView')
          </div>
        </div>
      </div>
    </section>
    @endif
@endsection

@push('page-script')
<script>
    var filename = $('#filesname').val();

    function saveAsPDF() {
        var element = document.getElementById('printableArea');
        var opt = {
            margin: 0.3,
            filename: filename,
            image: { type: 'jpeg', quality: 1 },
            html2canvas: { scale: 4, dpi: 72, letterRendering: true },
            jsPDF: { unit: 'in', format: 'A2' }
        };
        html2pdf().set(opt).from(element).save();
    }

    $(document).on('click', '.delstatus', function() {
        var order_id = $(this).attr('data-id');
        var data = { order_id: order_id, order_status: 'cancel' };
        
        $.ajax({
            url: '{{ route('status.cancel', $store->slug) }}',
            data: data,
            type: 'post',
            success: function(data) {
                $('#loader').fadeOut();
                if (data.status == 'error') {
                    show_toastr('{{ __('Error') }}', data.message, 'error');
                } else {
                    show_toastr('{{ __('Success') }}', data.message, 'success');
                    setTimeout(function() { location.reload(); }, 2000);
                }
            }
        });
    });

    document.querySelectorAll('.downloadable_product_variant').forEach(button => {
        button.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const downloadLink = document.querySelector('.download_prod_' + productId);
            if (downloadLink) {
                downloadLink.click();
            } else {
                console.error('Download link not found for product ID:', productId);
            }
        });
    });
</script>
@endpush