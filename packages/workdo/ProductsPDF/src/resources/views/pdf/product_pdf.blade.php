<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) ?? 'en' }}" dir="{{isset($currantLang)&&($currantLang == 'ar' || $currantLang == 'he')?'rtl':''}}">
@php
    $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
    $invoice_logo = \App\Models\Utility::GetValueByName('invoice_logo', $theme_name);
    $invoice_logo = get_file($invoice_logo, APP_THEME($user->id));
@endphp

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link
        href="https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,100;0,300;0,400;0,700;0,900;1,100;1,300;1,400;1,700;1,900&display=swap"
        rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
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
            /* border: 1px solid #000; */
            outline: 10px solid #ffff;
            max-width: 700px;
            width: 100%;
            margin: 10px auto;
            background: #ffff;
            box-shadow: 0 0 10px #ddd;
            padding: 20px
        }

        .invoice-logo {
            max-width: 200px;
            width: 100%;
        }

        .invoice-header .page-logo {
            display: flex;
            justify-content: flex-end;
        }

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

        .view-qrcode {
            /* max-width: 114px; */
            /* height: 114px; */
            margin-left: auto;
            margin-top: 15px;
            background: var(--white);
        }

        .view-qrcode img {
            width: 100%;
            height: 100%;
        }

        .invoice-body {
            /* padding: 30px 25px 0; */
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
        .text-warning {
            --bs-text-opacity: 1;
            color: orange !important;
        }

        .cover-img {
            margin-top: 15px;
            border: 1px solid #c8c9ca;
            border-radius: 15px;
            /* border: 1px solid #000; */
            /* box-shadow: 0 0 10px #ddd; */
        }

        ins {
            text-decoration: none;
        }
        .price ins {
            font-weight: 700;
            font-size: 24px;
            align-items: end;
            line-height: 100%;
        }
        .price del {
            font-weight: 600;
            font-size: 13px;
            margin-left: 15px;
            line-height: 100%;
        }
        del {
            color: #173334;
            opacity: 0.6;
            text-decoration: line-through;
        }
        .price {
            display: flex;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .product-price-amount {
            margin-top: 15px;
            margin-bottom: 20px
        }
        .ms{
            margin-left: 5px;
        }
        .mt{
            margin-top: 8px;
        }
        .mt-4 {
            margin-top: 25px;
        }
        .mb-4 {
            margin-bottom: 25px;
        }
        .sku {
            font-weight: 700;
        }
        .lbl {
            font-weight: 700;
            font-size: 20px;
        }

        .row {
            --bs-gutter-x: 1.5rem;
            --bs-gutter-y: 0;
            display: flex;
            flex-wrap: wrap;
            margin-top: calc(-1 * var(--bs-gutter-y));
            margin-right: calc(-.5 * var(--bs-gutter-x));
            margin-left: calc(-.5 * var(--bs-gutter-x));
        }
        .row>* {
            flex-shrink: 0;
            width: 100%;
            max-width: 100%;
            padding-right: calc(var(--bs-gutter-x) * .5);
            padding-left: calc(var(--bs-gutter-x) * .5);
            margin-top: var(--bs-gutter-y);
        }
        .col-6 {
            flex: 0 0 auto;
            width: 50%;
        }
        .col-4 {
            flex: 0 0 auto;
            width: 33.33333%;
        }

        .w-100 {
            width: 100%;
            border: 1px solid #c8c9ca;
            border-radius: 15px;
        }



        .avoid-break {
            page-break-inside: avoid;
        }
        .page-break {
            /* page-break-before: always; */
            /* page-break-after: always; */
        }
        .page-break-test {
            page-break-inside: avoid;
        }
    </style>


</head>

<body class="">
    <div class="invoice-preview-main avoid-break" id="boxes">
        <div class="invoice-header">
            <div class="row">
                <div class="col-xl-12 d-flex">
                    <div class="page-logo">
                        <img class="invoice-logo"
                            src="{{ !empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png' }}"
                            alt="Invoice-Logo">
                    </div>
                </div>
            </div>
            <hr>
            <div class="row">
                <div class="col-xl-12 d-flex justify-content-center">
                    <p>{{ $product->category_name }}</p>
                </div>
                <div class="col-xl-12 d-flex justify-content-center">
                    <h3>{{ $product->name }}</h3>
                </div>
                <div class="col-xl-12 d-flex justify-content-center">
                    <div class="pdp-top-info d-flex align-items-center">
                        <p>{{ ucfirst($product->label->name ?? '') }}</p>
                        <div class="reviews-stars-wrap d-flex align-items-center">
                            <div class="reviews-stars-outer">
                                @for ($i = 0; $i < 5; $i++)
                                    <i
                                        class="ti ti-star review-stars {{ $i < $product->average_rating ? 'text-warning' : '' }} "></i>
                                @endfor
                            </div>
                            <div class="point-wrap">
                                <span class="review-point">{{ $product->average_rating }}.0 /
                                    <span>{{ __('5.0') }}</span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="cover-img  d-flex justify-content-center">
            <img src="{{ asset($product->cover_image_path) }}">
        </div>

        @if ($product->variant_product == 1)

            <div class="view-qrcode d-flex justify-content-center">
                <p> {!! DNS2D::getBarcodeHTML(url($store->slug.'/product/'.($product->slug ?? '')), "QRCODE",2,2) !!}
            </div>
            <hr>
            @php
                $variant = json_decode($product->product_attribute);

                $varint_name_array = [];
                if (!empty($product->DefaultVariantData->variant)) {
                    $varint_name_array = explode('-', $product->DefaultVariantData->variant);
                }
                $variant_products = \App\Models\ProductVariant::where('product_id',$product->id)->get();
                $variant_attribute = '';
            @endphp
            @foreach ($variant as $key => $value)
                @php
                    $p_variant = App\Models\Utility::ProductAttribute($value->attribute_id);
                    $attribute = json_decode($p_variant);
                    $propertyKey = 'for_variation_' . $attribute->id;
                    $variation_option = $value->$propertyKey;
                @endphp

                @if ($variation_option == 1)
                    @php
                        $variant_attribute .= (($key != 0)? ' & ' :'').$attribute->name;
                    @endphp
                @endif
            @endforeach
            <table class="add-border invoice-summary" style="margin-top: 30px;">
                <thead style="background-color: #c8c9ca; color: black;">
                    <tr>
                        <th>{{__('Image')}}</th>
                        <th>{{__('SKU')}}</th>
                        <th>{{__('Attribute')}}</th>
                        <th>{{__('Stock Status')}}</th>
                        <th>{{__('Price')}}</th>
                    </tr>
                </thead>
                <tbody>
                    @if(isset($variant_products) && count($variant_products) > 0)
                        @foreach($variant_products as $key => $variant_product)
                            <tr class="page-break-test">
                                <td><img src="{{ asset($product->cover_image_path) }}" style="width: 150px; border: 1px solid #c8c9ca; border-radius: 15px;"></td>
                                <td>{{$variant_product->sku}}</td>
                                <td>{{$variant_product->variant.' ('.$variant_attribute.')'}}</td>
                                @if ($variant_product->stock_status)
                                    @if ($variant_product->stock_status == 'out_of_stock')
                                        <td class="text-danger" style="margin-left: 10px;">
                                            {{ __('Out of stock') }}</td>
                                    @elseif ($variant_product->stock_status == 'on_backorder')
                                        <td class="text-warning" style="margin-left: 10px;">
                                            {{ __('On Backorder') }}</td>
                                    @else
                                        <td class="text-success" style="margin-left: 10px;">
                                            {{ __('In stock') }}</td>
                                    @endif
                                @elseif ($product->track_stock == 0)
                                    @if ($product->stock_status == 'out_of_stock')
                                        <td class="text-danger" style="margin-left: 10px;">
                                            {{ __('Out of stock') }}</td>
                                    @elseif ($product->stock_status == 'on_backorder')
                                        <td class="text-warning" style="margin-left: 10px;">
                                            {{ __('On Backorder') }}</td>
                                    @else
                                        <td class="text-success" style="margin-left: 10px;">
                                            {{ __('In stock') }}</td>
                                    @endif
                                @else
                                    @if ($product->product_stock <= (isset($admin['out_of_stock_threshold']) ? $admin['out_of_stock_threshold'] : 0))
                                        <td class="text-danger" style="margin-left: 10px;">
                                            {{ __('Out of stock') }}</td>
                                    @else
                                        <td class="text-success" style="margin-left: 10px;">
                                            {{ __('In stock') }}</td>
                                    @endif
                                @endif
                                <td>{{currency_format_with_sym($variant_product->price, $store->id, $theme_name)}}</td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                            <td>-</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        <hr>
        @else
            <div class="price product-price-amount price-value d-flex justify-content-center">
                <ins class="product_final_price">{{ currency_format_with_sym($product->sale_price, $store->id, $theme_name)}}</ins><del
                    class="product_orignal_price">{{ currency_format_with_sym($product->price, $store->id, $theme_name) ?? SetNumberFormat($product->price) }}</del><span
                    class="currency-type"></span>

                    @if ($product->track_stock == 0)
                        @if ($product->stock_status == 'out_of_stock')
                            <span class="text-danger" style="margin-left: 10px;">
                                {{ __('Out of stock') }}</span>
                        @elseif ($product->stock_status == 'on_backorder')
                            <span class="text-warning" style="margin-left: 10px;">
                                {{ __('On Backorder') }}</span>
                        @else
                            <span class="text-success" style="margin-left: 10px;">
                                {{ __('In stock') }}</span>
                        @endif
                    @else
                        @if ($product->product_stock <= (isset($admin['out_of_stock_threshold']) ? $admin['out_of_stock_threshold'] : 0))
                            <span class="text-danger" style="margin-left: 10px;">
                                {{ __('Out of stock') }}</span>
                        @else
                            <span class="text-success" style="margin-left: 10px;">
                                {{ __('In stock') }}</span>
                        @endif
                    @endif
            </div>

            <hr>
            <div class="view-qrcode d-flex justify-content-center">
                <p> {!! DNS2D::getBarcodeHTML(url($store->slug.'/product/'.($product->slug ?? '')), "QRCODE",2,2) !!}
            </div>
            <hr>
        @endif

        <div class="pdp-block pdp-info-block product-variant-description mt-4 page-break-test">
            <h5><b>{{__('Product Description:')}}</b></h5>
            <p>{!! strip_tags($product->description) !!}</p>
        </div>
        <div class="pdp-block pdp-info-block product-variant-description mt-4 page-break-test">
            <h5><b>{{__('Product Specification:')}}</b></h5>
            <p>{!! strip_tags($product->specification) !!}</p>
        </div>
        <div class="pdp-block pdp-info-block product-variant-description mt-4 mb-4 page-break-test">
            <h5><b>{{__('Product Details:')}}</b></h5>
            <p>{!! strip_tags($product->detail) !!}</p>
        </div>
        <hr>

        <h5 class="page-break-test"><b>{{__('GALLERY IMAGES')}} : </b></h5>
        <div class="row">
            @foreach ($product_images as $product_image)
                <div class="col-4 mt-4">
                    <img src="{{ asset($product_image->image_path) }}" class="w-100">
                </div>
            @endforeach
        </div>
    </div>
</body>

</html>


@include('products-pdf::pdf.script')
