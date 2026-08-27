<!DOCTYPE html>
<html dir="{{ $SITE_RTL == 'on' ? 'rtl' : '' }}" lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
    <title>{{ $business->name }}</title>
    <meta name="author" content="{{ $business->name }}">
    <meta name="keywords" content="{{ $metaKeyword }}">
    <meta name="description" content="{{ $metaDesc }}">
    {{-- Meta tag Preview --}}
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $url_link }}">
    <meta property="og:title" content="{{ $business->name }}">
    <meta property="og:description" content="{{ $metaDesc }}">
    <meta property="og:image"
        content="{{ !empty($metaImg) ? $metaImg : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ $url_link }}">
    <meta property="twitter:title" content="{{ $business->name }}">
    <meta property="twitter:description" content="{{  $metaDesc }}">
    <meta property="twitter:image"
        content="{{ !empty($metaImg) ? $metaImg : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}">

    {{-- End Meta tag Preview --}}
    <link rel="icon" href="{{ isset($company_favicon) && !empty($company_favicon) ? $company_favicon : 'favicon.png' }}"
        type="image" sizes="16x16">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/libs/@fortawesome/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/fonts/stylesheet.css') }}">
    @if (isset($is_slug))
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/modal/bootstrap.min.css') }}">
    @endif

    @if ($SITE_RTL == 'on')
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/css/rtl-main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/css/rtl-responsive.css') }}">
    @else
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/css/main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/css/responsive.css') }}">
    @endif
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/css/emojionearea.min.css') }}">
    @if ($googleFonts != 'Default' && $googleFonts != null)
        <style>
            @import url('{{ Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)['link'] }}');

            :root {
                --Strawford: '{{ strtok(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)['fontfamily'], ',') }}',
                    {{ substr(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)['fontfamily'], strpos(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)['fontfamily'], ',') + 1) }}
                ;
            }
        </style>
    @endif

    @if (isset($is_slug))
        <link rel='stylesheet'
            href='{{ asset('packages/workdo/Marketing/src/resources/assets/custom/css/cookieconsent.css') }}'
            media="screen" />
        <style type="text/css">
            {
                    {
                    $customCss
                }
            }
        </style>
    @endif
    {{-- pwa customer app --}}
    <meta name="mobile-wep-app-capable" content="yes">
    <meta name="apple-mobile-wep-app-capable" content="yes">
    <meta name="msapplication-starturl" content="/">
    <link rel="apple-touch-icon" href="{{ $company_favicon }}" />
    @if ($enablePWABusiness == 'on')
    <link rel="manifest" href="{{ get_file('storage/uploads/theme_app/store_' . $store->id . '/manifest.json') }}" />
    @endif
    @if (!empty($pwa_data->theme_color))
        <meta name="theme-color" content="{{ $pwa_data->theme_color }}" />
    @endif
    @if (!empty($pwa_data->background_color))
        <meta name="apple-mobile-web-app-status-bar" content="{{ $pwa_data->background_color }}" />
    @endif
    @foreach ($pixelScript as $script)
        <?= $script ?>
    @endforeach

</head>

<body class="tech-card-body">
    <div id="boxes">
        <div class="{{ $themeOne['theme12'][$store->theme_color]['theme_name'] ?? " theme12-v1"}}" id="view_css">
            <!--wrapper start here-->
            <div class="home-wrapper">
                <section class="home-banner-section">
                    <img src="{{ isset($business->banner) && !empty($business->banner) ? get_file($business->banner) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}"
                        id="banner_preview" alt="fs" class="home-banner">
                </section>

                <section class="client-image-section">
                    <div class="container">
                        <div class="client-intro text-center">
                            <div class="client-image">
                                <img src="{{ isset($business->logo) && !empty($business->logo) ? get_file($business->logo) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                    id="business_logo_preview" alt="user" class="mb-4 img-thumbnail">
                            </div>
                            <h3 id="{{ $stringid . '_title' }}_preview" class="text-black">{{ $business->name }}</h3>
                            <h6 id="{{ $stringid . '_designation' }}_preview" class="text-black">
                                {{ $business->Company }}
                            </h6>
                            <span id="{{ $stringid . '_subtitle' }}_preview">{{ $business->industry }}</span>
                        </div>
                    </div>
                </section>
                @php $j = 1; @endphp


                @foreach ($card_theme["order"] as $order_key => $order_value)
                    @if ($j == $order_value)
                        @if ($order_key == 'description')
                            <section class="client-description-section text-center pb-5 pt-1">
                                <div class="container">
                                    <p id="{{ $stringid . '_desc' }}_preview">{{ $business->description }}</p>
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'contact_info')
                            <section class="client-info-section padding-bottom pt-1">
                                <div class="container" id="contact-div">
                                    <div class="client-contact text-center">
                                        <!-- decs -->
                                        <div class="calllink contactlink">
                                            <ul id="inputrow_contact_preview">
                                                @if (!is_null($contactinfo_content) && !is_null($contactinfo))
                                                                @foreach ($contactinfo_content as $key => $val)
                                                                                @foreach ($val as $key1 => $val1)
                                                                                                @if ($key1 == 'Phone')
                                                                                                    @php                            $href = 'tel:' . $val1; @endphp
                                                                                                @elseif($key1 == 'Email')
                                                                                                    @php                            $href = 'mailto:' . $val1; @endphp
                                                                                                @elseif($key1 == 'Address')
                                                                                                    @php                            $href = ''; @endphp
                                                                                                @else
                                                                                                    @php                            $href = $val1 @endphp
                                                                                                @endif
                                                                                                @if ($key1 != 'id')
                                                                                                    <li class="d-flex align-items-center" id="contact_{{ $loop->parent->index + 1 }}">
                                                                                                        @if ($key1 == 'Address')
                                                                                                            @foreach ($val1 as $key2 => $val2)
                                                                                                                @if ($key2 == 'Address_url')
                                                                                                                    @php                                        $href = $val2; @endphp
                                                                                                                @endif
                                                                                                            @endforeach
                                                                                                            <a href="{{ $href }}" target="_blank">

                                                                                                                <div class="contact-svg">
                                                                                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
                                                                                                                        class="img-fluid">
                                                                                                                </div>

                                                                                                                @foreach ($val1 as $key2 => $val2)
                                                                                                                    @if ($key2 == 'Address')
                                                                                                                        <span id="{{ $key1 . '_' . $no }}_preview">
                                                                                                                            {{ $val2 }}
                                                                                                                        </span>
                                                                                                                    @endif
                                                                                                                @endforeach
                                                                                                            </a>
                                                                                                        @else
                                                                                                            @if ($key1 == 'Whatsapp')
                                                                                                                <a href="{{ url('https://wa.me/' . $href) }}" target="_blank">
                                                                                                            @else
                                                                                                                <a href="{{ $href }}">
                                                                                                            @endif

                                                                                                                    <div class="contact-svg">
                                                                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
                                                                                                                            class="img-fluid">
                                                                                                                    </div>

                                                                                                                    <span id="{{ $key1 . '_' . $no }}_preview">
                                                                                                                        {{ $val1 }}</span>
                                                                                                                </a>
                                                                                                        @endif
                                                                                                    </li>
                                                                                                @endif
                                                                                                @php
                                                                                                    $no++;
                                                                                                @endphp
                                                                                @endforeach
                                                                @endforeach
                                                @endif
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'bussiness_hour')
                            <section class="business-hour-section padding-top padding-bottom" id="business-hours-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/clock.svg') }}"
                                        alt="clock" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>{{ __('Business Hours') }}</h2>
                                    </div>
                                    <div class="daily-hours-content">
                                        <div class="daily-hours-inner">
                                            <ul class="pl-1">
                                                @foreach ($days as $k => $day)
                                                                <li>
                                                                    <p>{{ __($day) }}:<span class="days_{{ $k }}">
                                                                            @if (isset($business_hours->$k) && $business_hours->$k->days == 'on')
                                                                                                        <span class="days_{{ $k }}_start">{{
                                                                                !empty($business_hours->$k->start_time) &&
                                                                                isset($business_hours->$k->start_time) ? date(
                                                                                    'h:i A',
                                                                                    strtotime($business_hours->$k->start_time)
                                                                                ) : '00:00' }}</span>
                                                                                                        - <span class="days_{{ $k }}_end">{{
                                                                                !empty($business_hours->$k->end_time) &&
                                                                                isset($business_hours->$k->end_time) ? date(
                                                                                    'h:i A',
                                                                                    strtotime($business_hours->$k->end_time)
                                                                                ) : '00:00' }}</span>
                                                                            @else
                                                                                {{ __('Closed') }}
                                                                            @endif
                                                                        </span></p>
                                                                </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'appointment')
                            <section class="appointment-section padding-top padding-bottom" id="appointment-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/calendar.svg') }}"
                                        alt="calendar" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>{{ __('Make an appointment') }}</h2>
                                    </div>
                                    <div class="appointment-date">
                                        <div class="date-label">
                                            {{ __('Date') }}
                                        </div>
                                        <input type="text" name="date" class="text-center datepicker_min"
                                            placeholder="{{ __('Pick a Date') }}">
                                    </div>
                                    <div class="text-center pl-3 mt-0">
                                        <span class="text-danger text-center h5 span-error-date"
                                            style="{{ $SITE_RTL == 'on' ? 'margin-right: 100px;' : 'margin-left: 100px;' }}"></span>
                                    </div>
                                    <div class="appointment-hour">
                                        <div class="hour-label">
                                            {{ __('Hour') }}
                                        </div>
                                        <div class="text-radio" id="inputrow_appointment_preview">
                                            @php            $radiocount = 1; @endphp
                                            @if (!is_null($appoinment_hours))
                                                            @foreach ($appoinment_hours as $k => $hour)
                                                                            <div class="radio {{ $radiocount % 2 == 0 ? 'radio-left' : '' }}"
                                                                                id="{{ 'appointment_' . $appointment_no }}">
                                                                                <input id="radio-{{ $radiocount }}" name="time" type="radio" value=".blue"
                                                                                    data-id="@if (!empty($hour->start)) {{ $hour->start }} @else 00:00 @endif-@if (!empty($hour->end)) {{ $hour->end }} @else 00:00 @endif"
                                                                                    class="app_time">
                                                                                <label for="radio-{{ $radiocount }}" class="radio-label"><span
                                                                                        id="appoinment_start_{{ $appointment_no }}_preview">
                                                                                        @if (!empty($hour->start))
                                                                                            {{ $hour->start }}
                                                                                        @else
                                                                                            00:00
                                                                                        @endif
                                                                                    </span> - <span id="appoinment_end_{{ $appointment_no }}_preview">
                                                                                        @if (!empty($hour->end))
                                                                                            {{ $hour->end }}
                                                                                        @else
                                                                                            00:00
                                                                                        @endif
                                                                                    </span></label>
                                                                            </div>
                                                                            @php
                                                                                $radiocount++;
                                                                                $appointment_no++;
                                                                            @endphp
                                                            @endforeach
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-center mt-0 mb-3 col-12">
                                        <span class="text-danger text-center h5 span-error-time"
                                            style="{{ $SITE_RTL == 'on' ? 'margin-right: 78px;' : 'margin-left: 78px;' }}"></span>
                                    </div>
                                    <div class="appointment-btn">
                                        <a href="javascript:;" class="btn" tabindex="0">
                                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M6 3C6 2.44772 6.44772 2 7 2C7.55228 2 8 2.44772 8 3V4H16V3C16 2.44772 16.4477 2 17 2C17.5523 2 18 2.44772 18 3V4H19C20.6569 4 22 5.34315 22 7V19C22 20.6569 20.6569 22 19 22H5C3.34315 22 2 20.6569 2 19V7C2 5.34315 3.34315 4 5 4H6V3Z"
                                                    fill="#000"></path>
                                                <path class="theme-svg" fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M10 12C9.44772 12 9 12.4477 9 13C9 13.5523 9.44772 14 10 14H17C17.5523 14 18 13.5523 18 13C18 12.4477 17.5523 12 17 12H10ZM7 16C6.44772 16 6 16.4477 6 17C6 17.5523 6.44772 18 7 18H13C13.5523 18 14 17.5523 14 17C14 16.4477 13.5523 16 13 16H7Z"
                                                    fill="#FDD395"></path>
                                            </svg>
                                            {{ __('Make an appointment') }}
                                        </a>
                                    </div>
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'service')
                            <section class="service-section padding-top padding-bottom" id="services-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/phone.svg') }}"
                                        alt="phone" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>{{ __('Services') }}</h2>
                                    </div>
                                    <div class="service-card-wrapper" id="inputrow_service_preview">
                                        @php            $image_count = 0; @endphp
                                        @foreach ($services_content as $k1 => $content)
                                                        <div class="service-card" id="services_{{ $service_row_no }}">
                                                            <div class="service-card-inner">
                                                                <div class="service-icon">
                                                                    <img id="{{ 's_image' . $image_count . '_preview' }}"
                                                                        src="{{ isset($content->image) && !empty($content->image) ? get_file($content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-21.png') }}"
                                                                        class="img-fluid" alt="image">
                                                                </div>
                                                                <h5 id="{{ 'title_' . $service_row_no . '_preview' }}">
                                                                    {{ $content->title }}
                                                                </h5>
                                                                <p id="{{ 'description_' . $service_row_no . '_preview' }}">
                                                                    {{ $content->description }}
                                                                </p>

                                                                @if (!empty($content->purchase_link))
                                                                    <a href="{{ url($content->purchase_link) }}" class="read-more-btn"
                                                                        id="{{ 'link_title_' . $service_row_no . '_preview' }}">
                                                                        {{ $content->link_title }}
                                                                        <svg xmlns="http://www.w3.org/2000/svg" width="4" height="6" viewBox="0 0 4 6"
                                                                            fill="none">
                                                                            <path fill-rule="evenodd" clip-rule="evenodd"
                                                                                d="M0.65976 0.662719C0.446746 0.879677 0.446746 1.23143 0.65976 1.44839L2.18316 3L0.65976 4.55161C0.446747 4.76856 0.446747 5.12032 0.65976 5.33728C0.872773 5.55424 1.21814 5.55424 1.43115 5.33728L3.34024 3.39284C3.55325 3.17588 3.55325 2.82412 3.34024 2.60716L1.43115 0.662719C1.21814 0.445761 0.872773 0.445761 0.65976 0.662719Z"
                                                                                fill="white"></path>
                                                                        </svg>
                                                                    </a>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        @php
                                                            $image_count++;
                                                            $service_row_no++;
                                                        @endphp
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'product')
                            <section class="product-section padding-top padding-bottom" id="product-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/product.svg') }}"
                                        alt="phone" class="img-fluid">
                                </div>
                                <div class="container">

                                    <div class="section-title text-center">
                                        <h2>{{ __('Product') }}</h2>
                                    </div>
                                    <div class="service-card-wrapper" id="inputrow_product_preview">
                                        @php            $pr_image_count = 0; @endphp
                                        @foreach ($products_content as $k1 => $content)
                                                        <div class="service-card" id="product_{{ $product_row_no }}">
                                                            <div class="service-card-inner">
                                                                <div class="service-icon">
                                                                    <img id="{{ 'pr_image' . $pr_image_count . '_preview' }}"
                                                                        src="{{ isset($content->image) && !empty($content->image) ? get_file($content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-21.png') }}"
                                                                        class="img-fluid" alt="image">
                                                                </div>
                                                                <h5 id="{{ 'product_title_' . $product_row_no . '_preview' }}">
                                                                    {{ $content->title }}
                                                                </h5>
                                                                <p id="{{ 'product_description_' . $product_row_no . '_preview' }}">
                                                                    {{ $content->description }}
                                                                </p>
                                                                <div class="product-currency">
                                                                    <span id="{{ 'product_currency_select' . $product_row_no . '_preview' }}">{{
                                                $content->currency }}</span>
                                                                    <span id="{{ 'product_price_' . $product_row_no . '_preview' }}">{{
                                                $content->price }}</span>
                                                                </div>

                                                                @if (!empty($content->purchase_link))
                                                                    <a href="{{ url($content->purchase_link) }}"
                                                                        id="{{ 'product_link_title_' . $product_row_no . '_preview' }}"
                                                                        class="read-more-btn">{{ $content->link_title }}</a>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        @php
                                                            $pr_image_count++;
                                                            $product_row_no++;
                                                        @endphp
                                        @endforeach
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'gallery')
                            <section class="gallery-section padding-top padding-bottom" id="gallery-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/gallery.svg') }}"
                                        alt="phone" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>{{ __('Gallery') }}</h2>
                                    </div>
                                    <div class="gallery-card-wrapper" id="inputrow_gallery_preview">
                                        @php            $image_count = 0; @endphp
                                        @if (isset($is_pdf))
                                            <div class="row gallery-cards">
                                                @if (!is_null($gallery_contents) && !is_null($gallery))
                                                    @foreach ($gallery_contents as $key => $gallery_content)
                                                        @if (isset($gallery_content->type))
                                                            @if ($gallery_content->type == 'image')
                                                                <div class="col-md-6 col-12 p-0 gallery-card-pdf" id="gallery_{{ $gallery_row_no }}">
                                                                    <div class="gallery-card-inner-pdf">
                                                                        <div class="gallery-icon-pdf">
                                                                            <a href="javascript:;" id="imagepopup" tabindex="0" class="imagepopup">
                                                                                <img src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                    alt="images" class="imageresource">
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                @php
                                                                    $image_count++;
                                                                    $gallery_row_no++;
                                                                @endphp
                                                            @endif
                                                        @endif
                                                    @endforeach
                                                @endif
                                            </div>
                                        @else
                                                <div class="gallery-slider">
                                                    @if (!is_null($gallery_contents) && !is_null($gallery))
                                                                @foreach ($gallery_contents as $key => $gallery_content)
                                                                            <div class="gallery-card" id="gallery_{{ $gallery_row_no }}">
                                                                                <div class="gallery-card-inner">
                                                                                    <div class="gallery-icon">
                                                                                        @if (isset($gallery_content->type))
                                                                                                        @if ($gallery_content->type == 'video')
                                                                                                            <a href="javascript:;" id="" tabindex="0" class="videopop">
                                                                                                                <video loop autoplay controls="true">
                                                                                                                    <source class="videoresource"
                                                                                                                        src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                        type="video/mp4">
                                                                                                                </video>
                                                                                                            </a>
                                                                                                        @elseif($gallery_content->type == 'image')
                                                                                                            <a href="javascript:;" id="imagepopup" tabindex="0" class="imagepopup">
                                                                                                                <img src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                    alt="images" class="imageresource">
                                                                                                            </a>
                                                                                                        @elseif($gallery_content->type == 'custom_video_link')
                                                                                                                        @if (
                                                                                                                                str_contains($gallery_content->value, 'youtube') ||
                                                                                                                                str_contains($gallery_content->value, 'youtu.be')
                                                                                                                            )
                                                                                                                                        @php
                                                                                                                                            if (
                                                                                                                                                strpos(
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                    'src',
                                                                                                                                                ) !== false
                                                                                                                                            ) {
                                                                                                                                                preg_match(
                                                                                                                                                    '/src="([^"]+)"/',
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                    $match,
                                                                                                                                                );
                                                                                                                                                $url = $match[1];
                                                                                                                                                $video_url = str_replace(
                                                                                                                                                    'https://www.youtube.com/embed/',
                                                                                                                                                    '',
                                                                                                                                                    $url,
                                                                                                                                                );
                                                                                                                                            } elseif (
                                                                                                                                                strpos(
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                    'src',
                                                                                                                                                ) == false &&
                                                                                                                                                strpos(
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                    'embed',
                                                                                                                                                ) !== false
                                                                                                                                            ) {
                                                                                                                                                $video_url = str_replace(
                                                                                                                                                    'https://www.youtube.com/embed/',
                                                                                                                                                    '',
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                );
                                                                                                                                            } else {
                                                                                                                                                $video_url = str_replace(
                                                                                                                                                    'https://youtu.be/',
                                                                                                                                                    '',
                                                                                                                                                    str_replace(
                                                                                                                                                        'https://www.youtube.com/watch?v=',
                                                                                                                                                        '',
                                                                                                                                                        $gallery_content->value,
                                                                                                                                                    ),
                                                                                                                                                );
                                                                                                                                                preg_match(
                                                                                                                                                    '/[\\?\\&]v=([^\\?\\&]+)/',
                                                                                                                                                    $gallery_content->value,
                                                                                                                                                    $matches,
                                                                                                                                                );
                                                                                                                                                if (count($matches) > 0) {
                                                                                                                                                    $videoId = $matches[1];
                                                                                                                                                    $video_url = strtok(
                                                                                                                                                        $videoId,
                                                                                                                                                        '&',
                                                                                                                                                    );
                                                                                                                                                }
                                                                                                                                            }
                                                                                                                                        @endphp

                                                                                                                                        <a href="javascript:;" id="" tabindex="0" class="videopop1 play-btn">
                                                                                                                                            <video loop autoplay controls="true"
                                                                                                                                                poster="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/video-poster.jpg') }}">
                                                                                                                                                <source class="videoresource1"
                                                                                                                                                    src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? 'https://www.youtube.com/embed/' . $video_url : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                                    type="video/mp4">
                                                                                                                                            </video>
                                                                                                                                        </a>
                                                                                                                        @else
                                                                                                                            <a href="javascript:;" id="" tabindex="0" class="videopop1 play-btn">
                                                                                                                                <video loop autoplay controls="true"
                                                                                                                                    poster="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/video-poster.jpg') }}">
                                                                                                                                    <source class="videoresource1"
                                                                                                                                        src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? $gallery_content->value : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                        type="video/mp4">
                                                                                                                                </video>
                                                                                                                            </a>
                                                                                                                        @endif
                                                                                                        @elseif($gallery_content->type == 'custom_image_link')
                                                                                                            <a href="javascript:;" id="" target="" tabindex="0" class="imagepopup1">
                                                                                                                <img class="imageresource1"
                                                                                                                    src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? $gallery_content->value : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                    alt="images" id="upload_image">
                                                                                                            </a>
                                                                                                        @endif
                                                                                        @endif
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            @php
                                                                                $image_count++;
                                                                                $gallery_row_no++;
                                                                            @endphp
                                                                @endforeach
                                                    @endif
                                                </div>
                                        @endif
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'more')
                            <section class="more-card-section padding-top padding-bottom">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/bookmark.svg') }}"
                                        alt="bookmark" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>{{ __('More') }}</h2>
                                    </div>
                                    <div class="more-btn">
                                        <a href="{{ route('vcard.save', $business->slug) }}" class="btn" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/folder.svg') }}"
                                                alt="folder" class="img-fluid">
                                            &nbsp;
                                            {{ __('Save Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-card" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/signout.svg') }}"
                                                alt="signout" class="img-fluid">
                                            &nbsp;
                                            {{ __('Share Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-contact" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/phone.svg') }}"
                                                alt="signout" class="img-fluid">
                                            &nbsp;
                                            {{ __('Contact') }}
                                        </a>
                                    </div>
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'testimonials')
                            <section class="testimonials-section padding-bottom padding-top" id="testimonials-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/phone.svg') }}"
                                        alt="phone" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2>
                                            {{ __('Testimonials') }}
                                        </h2>
                                    </div>
                                    @if (isset($is_pdf))
                                                <div class="row testimonial-pdf-row" id="inputrow_testimonials_preview">
                                                    @php
                                                        $t_image_count = 0;
                                                        $rating = 0;
                                                    @endphp
                                                    @foreach ($testimonials_content as $k2 => $testi_content)
                                                                <div class=" col-md-6 col-12 testimonial-itm-pdf"
                                                                    id="testimonials_{{ $testimonials_row_no }}">
                                                                    <div class="testimonial-itm-inner-pdf">
                                                                        <div class="testi-client-img-pdf">
                                                                            <img id="{{ 't_image' . $t_image_count . '_preview' }}"
                                                                                src="{{ isset($testi_content->image) && !empty($testi_content->image) ? get_file($testi_content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image12.jpg') }}"
                                                                                alt="image">
                                                                        </div>
                                                                        <div class="testimonial-pdf-bdy">
                                                                            <h5 class="rating-number">{{ $testi_content->rating }}/5
                                                                            </h5>

                                                                            <div class="rating-star">
                                                                                @php
                                                                                    if (!empty($testi_content->rating)) {
                                                                                        $rating = (int) $testi_content->rating;
                                                                                        $overallrating = $rating;
                                                                                    } else {
                                                                                        $overallrating = 0;
                                                                                    }
                                                                                @endphp
                                                                                <span id="{{ 'stars' . $testimonials_row_no }}_star"
                                                                                    class="star-section d-flex align-items-center justify-content-center">
                                                                                    @for ($i = 1; $i <= 5; $i++)
                                                                                        @if ($overallrating < $i)
                                                                                            @if (is_float($overallrating) && round($overallrating) == $i)
                                                                                                <i class="star-color fas fa-star-half-alt"></i>
                                                                                            @else
                                                                                                <i class="fa fa-star"></i>
                                                                                            @endif
                                                                                        @else
                                                                                            <i class="star-color fas fa-star"></i>
                                                                                        @endif
                                                                                    @endfor
                                                                                </span>
                                                                            </div>
                                                                            <h6 id="{{ 'testimonial_name_' . $testimonials_row_no . '_preview' }}">
                                                                                {{ isset($testi_content->name) ? $testi_content->name : '' }}
                                                                            </h6>
                                                                            <p id="{{ 'testimonial_description_' . $testimonials_row_no . '_preview' }}">
                                                                                {{ $testi_content->description }}
                                                                            </p>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                @php
                                                                    $t_image_count++;
                                                                    $testimonials_row_no++;
                                                                @endphp
                                                    @endforeach
                                                </div>
                                    @else
                                                <div class="testimonial-slider flex-slider" id="inputrow_testimonials_preview">
                                                    @php
                                                        $t_image_count = 0;
                                                        $rating = 0;
                                                    @endphp
                                                    @foreach ($testimonials_content as $k2 => $testi_content)
                                                                <div class="testimonial-itm" id="testimonials_{{ $testimonials_row_no }}">
                                                                    <div class="testimonial-itm-inner">
                                                                        <div class="testi-client-img">
                                                                            <img id="{{ 't_image' . $t_image_count . '_preview' }}"
                                                                                src="{{ isset($testi_content->image) && !empty($testi_content->image) ? get_file($testi_content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image12.jpg') }}"
                                                                                alt="image">
                                                                        </div>
                                                                        <h5 class="rating-number">{{ $testi_content->rating }}/5</h5>

                                                                        <div class="rating-star">
                                                                            @php
                                                                                if (!empty($testi_content->rating)) {
                                                                                    $rating = (int) $testi_content->rating;
                                                                                    $overallrating = $rating;
                                                                                } else {
                                                                                    $overallrating = 0;
                                                                                }
                                                                            @endphp
                                                                            <span id="{{ 'stars' . $testimonials_row_no }}_star"
                                                                                class="star-section d-flex align-items-center justify-content-center">
                                                                                @for ($i = 1; $i <= 5; $i++)
                                                                                    @if ($overallrating < $i)
                                                                                        @if (is_float($overallrating) && round($overallrating) == $i)
                                                                                            <i class="star-color fas fa-star-half-alt"></i>
                                                                                        @else
                                                                                            <i class="fa fa-star"></i>
                                                                                        @endif
                                                                                    @else
                                                                                        <i class="star-color fas fa-star"></i>
                                                                                    @endif
                                                                                @endfor
                                                                            </span>
                                                                        </div>
                                                                        <h6 id="{{ 'testimonial_name_' . $testimonials_row_no . '_preview' }}">
                                                                            {{ isset($testi_content->name) ? $testi_content->name : '' }}
                                                                        </h6>
                                                                        <p id="{{ 'testimonial_description_' . $testimonials_row_no . '_preview' }}">
                                                                            {{ $testi_content->description }}
                                                                        </p>
                                                                    </div>
                                                                </div>
                                                                @php
                                                                    $t_image_count++;
                                                                    $testimonials_row_no++;
                                                                @endphp
                                                    @endforeach
                                                </div>
                                    @endif
                                </div>
                            </section>
                        @endif

                        @if ($order_key == 'social')
                            <section class="social-icons-section padding-top" id="social-div">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/share.svg') }}"
                                        alt="user" class="img-fluid">
                                </div>
                                <div class="container">
                                    <div class="section-title text-center">
                                        <h2> {{ __('Social') }}</h2>
                                    </div>
                                    <ul class="social-icon-wrapper" id="inputrow_socials_preview">
                                        @if (!is_null($social_content) && !is_null($sociallinks))
                                                @foreach ($social_content as $social_key => $social_val)
                                                        @foreach ($social_val as $social_key1 => $social_val1)
                                                                @if ($social_key1 != 'id')
                                                                    <li class="socials_{{ $loop->parent->index + 1 }} social-image-icon"
                                                                        id="socials_{{ $loop->parent->index + 1 }}">
                                                                        @if ($social_key1 == 'Whatsapp')
                                                                            @if ($device_type == 'desktop' ?? 'desktop')
                                                                                @php
                                                                                    $social_links = url(
                                                                                        'https://web.whatsapp.com/send?phone=' .
                                                                                        $social_val1,
                                                                                    );
                                                                                @endphp
                                                                            @else
                                                                                @php
                                                                                    $social_links = url(
                                                                                        'https://wa.me/' . $social_val1,
                                                                                    );
                                                                                @endphp
                                                                            @endif
                                                                        @else
                                                                            @php
                                                                                $social_links = url($social_val1);
                                                                            @endphp
                                                                        @endif
                                                                        <a href="{{ $social_links }}" id="{{ 'social_link_' . $social_no . '_href_preview' }}"
                                                                            class="{{ 'social_link_' . $social_no . '_href_preview' }}" target="_blank">
                                                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/' . strtolower($social_key1) . '.svg') }}"
                                                                                alt="twitter" class="img-fluid"></a>
                                                                    </li>
                                                                @endif
                                                                @php
                                                                    $social_no++;
                                                                @endphp
                                                        @endforeach
                                                @endforeach
                                        @endif
                                    </ul>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'custom_html')
                            <div id="{{ $stringid . '_chtml' }}_preview" class="custom_html_text">
                                {!! stripslashes($custom_html) !!}
                            </div>
                        @endif
                        @if ($order_key == 'payment')
                            <section class="card-payment-section" id="payment-section">
                                <div class="title-svg">
                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/credit-card.svg') }}"
                                        alt="user" class="img-fluid">
                                </div>

                                <div class="section-title text-center">
                                    <h2 class="">{{ __('Payment') }}</h2>
                                </div>
                                @if (!is_null($cardPayment_content) && !is_null($cardPayment) && !empty($cardPayment_content))
                                    <div class="payment-list {{ $cardPayment->payment_status == 'Paid' ? 'disablePayment' : '' }}">
                                        @if ($cardPayment_content->stripe->status == 'on')
                                            <div class="payment-div">
                                                <a href="{{ route('card.pay.with.stripe', $business->id) }}">
                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/payments/stripe.png') }}"
                                                        alt="social" class="img-fluid">
                                                    {{ __('Stripe') }}
                                                </a>
                                            </div>
                                        @endif
                                        @if ($cardPayment_content->paypal->status == 'on')
                                            <div class="payment-div">
                                                <a href="{{ route('card.pay.with.paypal', $business->id) }}">
                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/payments/paypal.png') }}"
                                                        alt="social" class="img-fluid">
                                                    {{ __('Paypal') }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </section>
                        @endif
                        @php        $j = $j + 1; @endphp
                    @endif
                @endforeach


                @if ($is_branding_enabled)
                    <div id="is_branding_enabled" class="is_branding_enable copyright mt-3 pb-2">
                        <p id="{{ $stringid . '_branding' }}_preview" class="branding_text">
                            {{$brandingText}}
                        </p>
                    </div>
                @endif
            </div>
            <!---wrapper end here-->
            <!--appointment popup start here-->
            <div class="appointment-popup">
                <div class="container">
                    <form class="appointment-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('Make Appointment') }}</h5>
                            <div class="close-search">
                                <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/close.svg') }}"
                                    alt="back" class="img-fluid">
                            </div>
                        </div>
                        <div class="row appo-form-details">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Name:') }}</label>
                                    <input type="text" name="name" placeholder="{{ __('Enter your name') }}"
                                        class="form-control app_name" id="recipient-name">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-name"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Email:') }}</label>
                                    <input type="email" name="email" placeholder="{{ __('Enter your email') }}"
                                        class="form-control app_email" id="recipient-name">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-email"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Phone:') }}</label>
                                    <input type="number" name="phone" placeholder="{{ __('Enter your phone no') }}"
                                        class="form-control app_phone" id="recipient-name">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-phone"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" name="CLOSE" class="close-btn btn">
                                {{ __('Close') }}
                            </button>
                            <button type="button" class="btn btn-secondary" id="makeappointment">
                                {{ __('Make Appointment') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!--appointment popup end here-->
            <!--share card popup start here-->
            <div class="card-popup">
                <div class="container">
                    <div class="share-card-wrapper">
                        <div class="section-title">
                            <div class="close-search">
                                <svg xmlns="http://www.w3.org/2000/svg" width="7" height="9" viewBox="0 0 7 9"
                                    fill="none">
                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                        d="M5.84542 0.409757C6.21819 0.789434 6.21819 1.40501 5.84542 1.78469L3.17948 4.5L5.84542 7.21531C6.21819 7.59499 6.21819 8.21057 5.84542 8.59024C5.47265 8.96992 4.86826 8.96992 4.49549 8.59024L1.15458 5.18746C0.781807 4.80779 0.781807 4.19221 1.15458 3.81254L4.49549 0.409757C4.86826 0.0300809 5.47265 0.0300809 5.84542 0.409757Z"
                                        fill="#12131A" />
                                </svg>
                            </div>
                            <div class="section-title-center">
                                <h5>{{ __('Share This Card') }}</h5>
                            </div>
                            <button type="button" name="LOGOUT" class="logout-btn">

                            </button>
                        </div>
                        <div class="qr-scaner-wrapper">
                            <div class="qr-image shareqrcode">
                            </div>
                            <div class="qr-code-text ">
                                <p>
                                    {{ __('Point your camera at the QR code, or visit') }}
                                    <span class="qr-link text-center mr-2 text-wrap"></span>
                                </p>
                                <p class="mb-0">
                                    {{ __('Or check my social channels') }}
                                </p>
                            </div>
                            <ul class="card-social-icons">
                                <li><a href="https://twitter.com/share?url={{ urlencode($url_link) }}&text=">
                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/twitter.svg') }}"
                                            alt="twitter" class="img-fluid" height="30px" width="30px"></a></li>
                                <li>@php
                                    $whatsapp_link = url('https://wa.me/?text=' . urlencode($url_link));
                                @endphp
                                    <a href="{{ $whatsapp_link }}">
                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/whatsapp.svg') }}"
                                            alt="youtube" class="img-fluid" height="30px" width="30px"></a>
                                </li>
                                <li><a href="https://www.facebook.com/sharer.php?u={{ urlencode($url_link) }}"><img
                                            src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/facebook.svg') }}"
                                            alt="facebook" class="img-fluid" height="30px" width="30px"></a></li>
                                <li><a
                                        href="https://www.linkedin.com/shareArticle?url={{ urlencode($url_link) }}&title="><img
                                            src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/linkedin.svg') }}"
                                            alt="linkedin" class="img-fluid" height="30px" width="30px"></a>
                                </li>
                                <li>
                                    <a href="" id="copylink" data-bs-toggle="tooltip" data-bs-placement="bottom"
                                        title="" class="copy-icon insta-icon">
                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/copylink.svg') }}"
                                            alt="copylink" class="img-fluid" height="30px" width="30px">
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <!--card popup end here-->
            <!--contact popup start here-->
            <div class="contact-popup">
                <div class="container">
                    <form class="appointment-form-wrapper contact-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('Make Contact') }}</h5>
                            <div class="close-search close-search2">
                                <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/icon/' . $color . '/close.svg') }}"
                                    alt="back" class="img-fluid">
                            </div>
                        </div>
                        <div class="row appo-form-details">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Name:') }}</label>
                                    <input type="text" name="name" placeholder="{{ __('Enter your name') }}"
                                        class="form-control contact_name" id="recipient-name">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-contactname"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Email:') }}</label>
                                    <input type="email" name="email" placeholder="{{ __('Enter your email') }}"
                                        class="form-control contact_email" id="recipient-email">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-contactemail"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Phone:') }}</label>
                                    <input type="text" name="phone" placeholder="{{ __('Enter your phone no') }}"
                                        class="form-control contact_phone" id="recipient-phone">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-contactphone"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Message:') }}</label>
                                    <textarea name="message" placeholder=""
                                        class="custom_size contact_message emojiarea" id="recipient-message"></textarea>
                                    <div class="">
                                        <span class="text-danger  h5 span-error-contactmessage"></span>
                                    </div>
                                </div>
                                <input type="hidden" name="business_id" value="{{ $business->id }}">
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" name="CLOSE" class="close-btn btn">
                                {{ __('Close') }}
                            </button>
                            <button type="button" class="btn btn-secondary" id="makecontact">{{ __('Make Contact')
                                }}</button>
                        </div>
                    </form>
                </div>
            </div>
            <!--contact popup end here-->
            <!--Password model start here-->
            <div class="password-popup" id="passwordmodel" role="dialog" data-backdrop="static" data-keyboard="false">
                <div class="container">
                    <form class="appointment-form-wrapper contact-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('Enter Password') }}</h5>
                        </div>
                        <div class="row appo-form-details">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Password') }}:</label>
                                    <input type="password" name="Password" placeholder="{{ __('Enter password') }}"
                                        class="form-control password_val" id="recipient-name" placeholder="Password">
                                    <div class="">
                                        <span class="text-danger h5 span-error-password"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" class="btn form-btn--submit password-submit">{{ __('Submit')
                                }}</button>


                        </div>
                    </form>
                </div>
            </div>
            {{-- Gallery Models --}}
            <div class="password-popup" id="gallerymodel" role="dialog" data-backdrop="static" data-keyboard="false">
                <div class="container">
                    <form class="appointment-form-wrapper contact-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('') }}</h5>
                        </div>
                        <div class="row">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Image preview') }}:</label>
                                    <img src="" class="imagepreview" style="width: 500px; height: 300px;">
                                </div>
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" class="btn btn-default close-btn close-model"
                                data-dismiss="modal">Close</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="password-popup" id="videomodel" role="dialog" data-backdrop="static" data-keyboard="false">
                <div class="container">
                    <form class="appointment-form-wrapper contact-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('') }}</h5>
                        </div>
                        <div class="row ">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Video preview') }}:</label>
                                    <iframe width="100%" height="360" class="videopreview" src="" frameborder="0"
                                        allowfullscreen autoplay></iframe>
                                </div>
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" class="btn btn-default close-btn close-model1"
                                data-dismiss="modal">Close</button>
                        </div>
                    </form>
                </div>
            </div>
            <!--Password model end here-->
            <div class="overlay"></div>
            <img src="{{ isset($qr_detail->image) ? get_file($qr_detail->image) : '' }}" id="image-buffers"
                style="display: none">
        </div>
    </div>
    <div id="previewImage"> </div>
    <a id="download" href="#" class="font-lg download mr-3 text-white">
        <i class="fas fa-download"></i>
    </a>
    <!--scripts start here-->
    <script
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/js/jquery.min.js') }}"></script>
    <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/js/slick.min.js') }}"
        defer="defer">
        </script>
    @if ($SITE_RTL == 'on')
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/js/rtl-custom.js') }}"
            defer="defer">
            </script>
    @else
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme12/js/custom.js') }}"
            defer="defer"></script>
    @endif


    @include('marketing::layouts.theme_script')
</body>

</html>