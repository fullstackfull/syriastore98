<!DOCTYPE html>
{{-- P1-SEC-11 criteria 1 and 4 -- see order/shippinglabel.blade.php for the full note. The logo and the
     direction come from the ORDER'S store now; `invoice_logo` used to be fetched here with no store id,
     which resolves getCurrentStore(), so the platform operator printing a merchant's receipt got the
     operator's logo on it. --}}
<html lang="{{ $documentLocale ?? app()->getLocale() }}" dir="{{ ($documentRtl ?? false) ? 'rtl' : 'ltr' }}">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="ie=edge">
<title>{{ env('APP_NAME') }}</title>
</head>

<body>
<div id="invoice-POS" style="width:100%">
   <style>
            #invoice-POS {
                /*box-shadow: 0 0 1in -0.25in rgba(0, 0, 0, 0.5);*/
            }

            #invoice-POS ::selection {
                /*background: #f31544;*/
                color: #fff;
            }

            #invoice-POS ::moz-selection {
                /*background: #f31544;*/
                color: #fff;
            }

            #invoice-POS h1 {
                font-size: 1.5em;
                color: #222;
                font-family: 'Trebuchet MS', sans-serif;
            }

            #invoice-POS h2 {
                font-size: 1.2em;
                font-family: 'Trebuchet MS', sans-serif;
            }

            #invoice-POS h3 {
                font-size: 1.2em;
                font-weight: bold;
                line-height: 2em;
                font-family: 'Trebuchet MS', sans-serif;
            }

            #invoice-POS p {
                font-size: 0.9em;
                color: #000;
                font-family: 'Trebuchet MS', sans-serif;
                font-weight: bold;
            }

            #invoice-POS .footer {
                font-size: 0.9em;
                color: #000;
                font-family: 'Trebuchet MS', sans-serif;
            }

            #invoice-POS #top,
            #invoice-POS #mid,
            #invoice-POS #bot {
                /* Targets all id with 'col-' */
                /*border-bottom: 1px solid #000;*/
            }

            #invoice-POS #top {
                min-height: 65px;
            }

            #invoice-POS #mid {
                min-height: 80px;
            }

            #invoice-POS #bot {
                min-height: 50px;
            }

            #invoice-POS #top .logo img {
                height: auto;
                width: 25%;
                height: auto;
                background-size: 60px 60px;
                filter: gray saturate(0%) brightness(70%) contrast(100%);
            }

            #invoice-POS .clientlogo {
                float: left;
                height: 60px;
                width: 60px;
                background-size: 60px 60px;
                border-radius: 50px;
            }

            #invoice-POS .info {
                display: block;
                margin-left: 0;
            }

            #invoice-POS .title {
                float: right;
            }

            #invoice-POS .title p {
                text-align: right;
            }

            #invoice-POS table {
                width: 100%;
                border-collapse: collapse;
            }

            #invoice-POS .tabletitle {
                font-size: 0.7em;
                /*background: #eee;*/
            }

            #invoice-POS .service {
                border-bottom: 2px solid #000;
            }

            #invoice-POS .item {
                width: 24mm;
            }

            #invoice-POS .itemtext {
                font-size: 0.9em;
                color: black;
            }

            #invoice-POS #legalcopy {
                margin-top: 5mm;
            }

            /*
               P1-SEC-11 criterion 4. THIS DOCUMENT NOW DECLARES A DIRECTION AND HAD NO RULES KEYED ON
               ONE -- found by the read-only review of the first cut, which is the right finding: giving
               a template `dir="rtl"` without mirroring its own floats leaves a HALF-FLIPPED document,
               base text direction reversed while every float and text-align stays left-oriented. That
               is worse than the LTR-only layout it replaced, not better.

               Each rule below mirrors one authored LTR rule in this same stylesheet and nothing else:
               `.clientlogo` floats left (:91), `.title` floats right (:104) and `.title p` is
               right-aligned (:108). `.info` sets `margin-left: 0` (:100), which is already
               direction-neutral and so is not restated. Table cells are not restated either: this
               document, unlike the shipping label, sets no `text-align` on them, so they inherit the
               direction and flip on their own.

               Scoped to `#invoice-POS` like every other rule here, so nothing outside this document
               can be reached.
            */
            html[dir="rtl"] #invoice-POS .clientlogo {
                float: right;
            }

            html[dir="rtl"] #invoice-POS .title {
                float: left;
            }

            html[dir="rtl"] #invoice-POS .title p {
                text-align: left;
            }

            @media print {
                /*#invoice-POS {transform: scale(2);}*/
                /*@page  {*/
                /*    size: 80mm 210mm; /* landscape */
                /* you can also specify margins here: */
                /*}*/
            }
        </style>

        <center id="top">
            <div class="logo">
                <center>
                    <img class="mt-2 mb-2 ml-2"
                        {{-- P1-SEC-11 criterion 4, found while driving this document. `$profile` IS DEFINED NOWHERE:
                             not by the controller, not by a view composer, not by a shared variable -- grepped. So
                             whenever `$invoice_logo` came back empty this line raised "Undefined variable $profile"
                             and the document answered 500. It stayed hidden because the old code read
                             `GetValueByName('invoice_logo')` with NO store id, which resolves the VIEWER's store and
                             so almost always found a row -- the very defect criterion 1 is about was what kept this
                             one latent. The handler now guarantees a value, so the fallback is gone. --}}
                        src="{{ $invoice_logo }}" width="120">
                </center>
            </div>
            <div class="info">
                <h2>{{ __('SALE RECEIPT') }}</h2>
            </div>
            <!--End Info-->
        </center>
        <!--End InvoiceTop-->
        <div id="bot">
            <div id="table">
                <table>
                    <tbody>
                        <tr class="tabletitle">
                            <td class="item">
                                <h2>{{ __('Item') }}</h2>
                            </td>
                            <td class="Hours" style="text-align: center">
                                <h2>{{ __('Qty') }}</h2>
                            </td>
                            <td class="Rate">
                                <h2>{{ __('Price') }}</h2>
                            </td>
                        </tr>

                        @foreach ($order['product'] as $product)
                            <tr class="service">
                                <td class="tableitem">
                                    {{ $product['name'] }}
                                </td>
                                <td class="tableitem" style="text-align: center">
                                    @if ($order['paymnet_type'] == 'POS')
                                        {{ $product['quantity'] }}
                                    @else
                                        {{ $product['qty'] }}
                                    @endif
                                    
                                </td>
<td class="tableitem">
    @if ($order['paymnet_type'] == 'POS')
        {{ order_currency_format_with_sym(($product['orignal_price'] ?? $product['final_price'] ?? 0), $order['id']) }}
    @else
        @if (module_is_active('ProductPricing') && isset($product['sale_price']))
            {{ order_currency_format_with_sym($product['sale_price'], $order['id']) }}
        @else
            {{ order_currency_format_with_sym($product['final_price'] ?? 0, $order['id']) }}
        @endif
    @endif
</td>                            </tr>
                            
                        @endforeach
                        <tr class="tabletitle">
                            <td></td>
                            <td class="Rate">
                                <h2>{{ __('Grand Total') }}</h2>
                            </td>
                            
                            
                            
<td class="payment">
    <h2><b>{{ order_currency_format_with_sym($order['final_price'] ?? $order['sub_total'] ?? 0, $order['id']) }}</b>
    </h2>
</td>                            
                            
                            
                        </tr>
                    </tbody>
                </table>
            </div>
            <!--End Table-->
        </div>
        <!--End InvoiceBot-->
        <div class="footer pt-2">
            <center>
                <p class="text-dark p-2">
                    {{ __('Merchandise may not be returned for refund at any time. Power bases cannot be
                                        exchanged and are non-refundable. For purchases made in a Milo Showroom, no refunds are available
                                        and sales c') }}
                </p>
            </center>
        </div>
    </div>
    <script>
        window.print();
        window.onafterprint = back;

        function back() {
            window.close();
            window.history.back();
        }
    </script>
</body>

</html>