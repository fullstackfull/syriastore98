<div class="">
    {!! Form::open([
        'route' => ['payment.process', $slug],
        'method' => 'post',
        'enctype' => 'multipart/form-data',
        'id' => 'formdata',
    ]) !!}
    <div class="col-sm-12">
        <div class="row order-details-modal" id="printableArea">
            <div class="col-xxl-7 col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <p class="mb-0"><b>{{ __('Items from Order') }} {{ $order['order_id'] }}</b></p>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{{ __('Item') }}</th>
                                        <th>{{ __('Quantity') }}</th>
                                        <th>{{ __('Total') }}</th>
                                        @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                                            <th>{{ __('Return') }}</th>
                                        @endif
                                        @if ($order['order_status'] == 1)
                                            <th>{{ __('Downloadable Product') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($order['product'] as $item)
                                        @php
                                            $download_prod = \App\Models\ProductVariant::where(
                                                'id',
                                                $item['variant_id'],
                                            )->first();
                                            $download_product = \App\Models\Product::where(
                                                'id',
                                                $item['product_id'],
                                            )->first();
                                        @endphp
                                        <tr>
                                            <td class="total">
                                                <span class="p text-sm"> <a href="#">{{ $item['name'] }}</a>
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
                                                @if ($order['paymnet_type'] == 'POS')
                                                    {{ currency_format_with_sym($item['orignal_price'] ?? 0, getCurrentStore()) ?? SetNumberFormat($item['orignal_price']) }}
                                                @else
                                                    {{ currency_format_with_sym($item['final_price'] ?? 0, getCurrentStore()) ?? SetNumberFormat($item['final_price']) }}
                                                @endif
                                            </td>
                                            @if ($order['order_status'] == 1 && $order['is_guest'] == 0)
                                                <td> - </td>
                                            @endif
                                            @if ($order['order_status_text'] == 'Delivered')
                                                @if (!empty($download_prod->downloadable_product) || !empty($download_product->downloadable_product))
                                                    <td>
                                                        <div class="detail-bottom">
                                                            @if (!empty($download_product->downloadable_product))
                                                                <a class="download_prod_{{ $item['product_id'] }}"
                                                                    href="{{ get_file($download_product->downloadable_product) }} "
                                                                    download></a>
                                                            @endif
                                                            @if (!empty($download_prod->downloadable_product))
                                                                <a class="download_prod_{{ $item['product_id'] }}"
                                                                    href="{{ get_file($download_prod->downloadable_product) }}"
                                                                    download></a>
                                                            @endif
                                                            <button data-id="{{ $order['id'] }}"
                                                                class="btn cart-btn downloadable_prodcut_variant_{{ $item['product_id'] }}">{{ __('Download') }}
                                                                <i class="fas fa-shopping-basket"></i>
                                                            </button>

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
                </div>
            </div>
            <div class="col-xxl-5 col-12">
                <div class="card  p-0">
                    <div class="card-header d-flex justify-content-between pb-0">
                        <b class="mb-4">{{ __('Your Pending Amount') }}</b>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <tbody>
                                    <tr>
                                        <td>{{ __('Alredy Paid Payment') }} :</td>
                                        <td>{{ currency_format_with_sym($paid_order->payment_amount ?? 0, getCurrentStore()) ?? SetNumberFormat($paid_order->payment_amount) }}
                                        </td>
                                    </tr>
                                    <input type="hidden" class="hidden" name="payment_amount"
                                        value="{{ $pending_order->payment_amount ?? 0 }}">
                                    <input type="hidden" class="hidden" name="partial_payment_id"
                                        value="{{ $pending_order->id }}">
                                    <input type="hidden" class="hidden" name="partial_payment_type"
                                        value="pending_payment">
                                    <input type="hidden" class="hidden" name="order_id" value="{{ $order['id'] }}">
                                    <tr>
                                        <td>{{ __('Partial Payment') }} :</td>
                                        <td>{{ currency_format_with_sym($pending_order->payment_amount ?? 0, getCurrentStore()) ?? SetNumberFormat($pending_order->payment_amount) }}
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><b>{{ __('You have to Pay') }}</b> : </td>
                                        <td>{{ currency_format_with_sym($pending_order->payment_amount ?? 0, getCurrentStore()) ?? SetNumberFormat($pending_order->payment_amount) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between">
                        <p class="mb-0"><b>{{ __('Select Payment Method') }} :</b></p>
                    </div>
                    <div class="card-body">
                        <div class="payment-method-form">
                            @foreach ($filtered_payment_methods as $payment_key => $payment_data)
                                @if ($payment_data->status == 'on')
                                    <div class="radio-group">
                                        <div class="payment_type">
                                            <input type="radio" id="{{ $payment_data->name }}" name="payment_type"
                                                {{ $payment_key == 0 ? 'checked' : '' }}
                                                value="{{ $payment_data->name }}" class="payment_change">
                                            <label for="{{ $payment_data->name }}">
                                                <span>{{ $payment_data->name_string }}</span>
                                                <div class="center-descrp"> {{ $payment_data->detail }} </div>
                                                <div class="radio-right">
                                                    <img src="{{ asset($payment_data->image) }}" alt="cod"
                                                        class="paymentimag{{ $payment_data->name }}">
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="form-container">
        <div class="d-flex acc-back-btn-wrp align-items-center justify-content-end">
            <button class="btn continue-btn" type="submit">
                {{ __('Make Payment') }}
                <svg xmlns="http://www.w3.org/2000/svg" width="35" height="14" viewBox="0 0 35 14"
                    fill="none">
                    <path d="M25.0749 14L35 7L25.0805 0L29.12 6.06667H0V7.93333H29.12L25.0749 14Z">
                    </path>
                </svg>
            </button>
        </div>
    </div>
    {!! Form::close() !!}
</div>
