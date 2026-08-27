<!DOCTYPE html>
<html lang="en" id="printarea">
@php
    $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
    $invoice_logo = \App\Models\Utility::GetValueByName('invoice_logo', $theme_name);
    $invoice_logo = get_file($invoice_logo);

@endphp

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link
        href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap"
        rel="stylesheet">
    <link href="{{ asset('packages/workdo/AutomaticOrderPrinting/src/resources/assets/css/bootstrap.min.css')}}" rel="stylesheet" />

    <style type="text/css">
        :root {
            --theme-color: #FFF;
            --white: #ffffff;
            --black: #000000;
        }

        body {

            font-family: 'Lato', sans-serif;
        }

        p,
        li,
        ul,
        ol {
            margin: 0;
            padding: 0;
            list-style: none;
            line-height: 1.5;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table tr th {
            padding: 0.75rem;
            text-align: left;
        }

        table tr td {
            padding: 0.75rem;
            text-align: left;
        }

        table th small {
            display: block;
            font-size: 12px;
        }

        .invoice-preview-main {
            max-width: 700px;
            width: 100%;
            margin: 0 auto;
            background: #ffff;
            box-shadow: 0 0 10px #ddd;
        }

        .invoice-logo {
            max-width: 200px;
            width: 100%;
        }

        .invoice-header .page-logo {}

        .invoice-header table td {
            padding: 15px 30px;
        }

        .text-right {
            text-align: right;
        }

        .no-space tr td {
            padding: 0;
        }


        .vertical-align-top td {
            vertical-align: top;
        }


        .invoice-body {
            padding: 30px 25px 0;
        }

        .view-qrcode {
            max-width: 139px;
            margin-left: auto;
            background: var(--white);
            padding: 13px;
            border-radius: 10px;
            align-items: end;
        }

        .view-qrcode img {
            width: 100%;
            height: 100%;
        }

        table.add-border tr {
            border-top: 1px solid var(--theme-color);
        }

        tfoot tr:first-of-type {
            border-bottom: 1px solid var(--theme-color);
        }

        .total-table tr:first-of-type td {
            padding-top: 0;
        }

        .total-table tr:first-of-type {
            border-top: 0;
        }

        .sub-total {
            padding-right: 0;
            padding-left: 0;
        }

        .border-0 {
            border: none !important;
        }

        .invoice-summary td,
        .invoice-summary th {
            font-size: 13px;
            font-weight: 600;
        }

        .total-table td:last-of-type {
            width: 146px;
        }

        .invoice-footer {
            padding: 15px 20px;
        }

        .itm-description td {
            padding-top: 0;
        }

        html[dir="rtl"] table tr td,
        html[dir="rtl"] table tr th {
            text-align: right;
        }

        html[dir="rtl"] .text-right {
            text-align: left;
        }

        html[dir="rtl"] .view-qrcode {
            margin-left: 0;
            margin-right: auto;
        }

        p:not(:last-of-type) {
            margin-bottom: 15px;
        }

        .invoice-summary p {
            margin-bottom: 0;
        }

        .barcode {
            margin-top: 100px;
        }

        .page-logo {}
    </style>


</head>

<body class="">
    <div class="table-responsive">
        <div class="invoice-preview-main" id="boxes">
            <div class="invoice-header p-3" style="color:{{ $font_color }};  background-color: {{ $color }};">

                <div class="page-logo">
                    <img class="invoice-logo" src="{{ !empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png' }}"
                        alt="Invoice-Logo">
                </div>
                <div class="row top-bar">
                    <div class="col-xl-12 d-flex justify-content-between">
                        <div class="col-xl-6 mt-4 px-2">
                            <address style="background: {{ $color }}; color: {{ $font_color }}">
                                <strong style="margin-bottom: 10px;">{{ __('FROM') }}</strong>
                                <p style="font-weight: normal">
                                    @if ($settings['store_address'])
                                        {{ $settings['store_address'] }}
                                    @endif
                                    <br>
                                    @if ($settings['store_city'])
                                        {{ $settings['store_city'] }}
                                    @endif
                                    <br>
                                    @if ($settings['store_state'])
                                        {{ $settings['store_state'] }}
                                    @endif
                                    <br>
                                    @if ($settings['store_zipcode'])
                                        {{ $settings['store_zipcode'] }}
                                    @endif
                                    <br>
                                    @if ($settings['store_country'])
                                        {{ $settings['store_country'] }}
                                    @endif
                                </p>
                            </address>
                        </div>
                        <div class="col-xl-6 d-flex justify-content-end align-items-start px-3">
                            @if (isset($print_settings['order_qr_display']) && $print_settings['order_qr_display'] === 'on')
                                <td colspan="2">
                                    <div class="view-qrcode">
                                        {!! DNS2D::getBarcodeHTML(
                                            route('shippinglabel.pdf', [
                                                'id' => $order['id'] ?? 0,
                                                'template' => 'template1',
                                                'color' => '#fff',
                                            ]),
                                            'QRCODE',
                                            2,
                                            2,
                                        ) !!}
                                    </div>
                                </td>
                            @endif

                        </div>
                    </div>
                </div>
            </div>



            <div class="invoice-body" style="border-bottom: 15px solid {{ $color }} ">

                <strong style="margin-bottom: 10px;">Bill To:</strong>

                @if (isset($customerdetails) && !empty($customerdetails))
                    <p>
                        @foreach ($customerdetails as $key => $detail)
                            {{ $detail }} <br>
                        @endforeach
                    </p>
                @else
                    <p> - </p>
                @endif


                <table class="add-border invoice-summary" style="margin-top: 30px;">
                    <thead style="background: {{ $color }};color:{{ $font_color }}">
                        <tr>
                            <th>Item</th>
                            <th>Quantity</th>
                            <th>Total</th>

                        </tr>
                    </thead>
                    @php $i=0; @endphp
                    @foreach ($items as $item)
                        <tr style="border-bottom:1px solid {{ $color == '#ffffff' ? 'black' : $color }};">
                            <td>{{ $item->name }}</td>
                            <td>{{ $item->qty ?? ($item->quantity ?? '0') }}</td>
                            <td>{{ $item->final_price }}</td>

                        </tr>
                    @endforeach

                    </tbody>

                </table>

                <div class="invoice-body">
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('Order No:') }}</th>
                                <th>{{ __('Weight') }}:</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    {{ $order['order_id'] }}
                                </td>
                                <td>{{ $product_sum }}</td>
                            </tr>
                    </table>

                </div>
                <div class="d-header-50">
                    <p>
                        <b>{{ $print_settings['footer'] ?? '' }}</b><br>
                        {{ $print_settings['footer_notes'] ?? '2024 Copyright © EcommerceGo-Saas' }}
                    </p>
                </div>
            </div>
        </div>

    </div>

</body>

</html>
@if ($is_Invoice)
    @include('order.script')
@endif
