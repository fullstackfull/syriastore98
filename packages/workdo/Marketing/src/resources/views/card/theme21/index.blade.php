<!DOCTYPE html>
<html dir="{{ $SITE_RTL == 'on' ? 'rtl' : '' }}" lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $business->name }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="apple-touch-fullscreen" content="yes">
    <meta name="HandheldFriendly" content="True">

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
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/libs/@fortawesome/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/fonts/stylesheet.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/css/emojionearea.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/assets/css/plugins/animate.min.css') }}" />

    @if (isset($is_slug))
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/modal/bootstrap.min.css') }}">
    @endif

    @if ($SITE_RTL == 'on')
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/css/rtl-main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/css/rtl-responsive.css') }}">
    @else
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/css/main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/css/responsive.css') }}">
    @endif
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
            href='{{ asset(' packages/workdo/Marketing/src/resources/assets/custom/css/cookieconsent.css') }}'
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
</head>

<body class="tech-card-body">
    <div id="boxes">
        <div class="{{ $themeOne['theme21'][$store->theme_color]['theme_name'] ?? " theme21-v1" }}" id="view_theme10">
            <div class="home-wrapper">
                <section class="client-info-section padding-top padding-bottom">
                    <div class="container">
                        <div class="client-info-wrapper bg-white">
                            <div class="client-intro text-center">
                                <div class="client-image">

                                    <img src="{{ isset($business->logo) && !empty($business->logo) ? get_file($business->logo) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                        id="business_logo_preview" alt="image">
                                </div>
                                <h3 id="{{ $stringid . '_title' }}_preview" class="text-black">{{ $business->name }}
                                </h3>
                                <h6 id="{{ $stringid . '_designation' }}_preview" class="text-black">
                                    {{ $business->Company }}
                                </h6>
                                <span id="{{ $stringid . '_subtitle' }}_preview">{{ $business->industry }}</span>
                            </div>
                        </div>
                    </div>
                </section>
                @php $j = 1; @endphp
                @foreach ($card_theme["order"] as $order_key => $order_value)
                    @if ($j == $order_value)
                        @if ($order_key == 'contact_info')
                            <section class="top-social-section" id="contact-div">
                                <div class="container">
                                    <ul class="banner-social-icons bg-white" id="inputrow_contact_preview">
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
                                                                    @php                            $href = $val1; @endphp
                                                                @endif

                                                                @if ($key1 != 'id')
                                                                    <li id="contact_{{ $loop->parent->index + 1 }}">
                                                                        @if ($key1 == 'Address')
                                                                            @foreach ($val1 as $key2 => $val2)
                                                                                @if ($key2 == 'Address_url')
                                                                                    @php                                        $href = $val2; @endphp
                                                                                @endif
                                                                            @endforeach

                                                                            <a href="{{ $href }}" target="_blank">
                                                                                <div class="contact-svg">
                                                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/icon/' . $color . '/contact/' . strtolower($key1) . '.svg') }}"
                                                                                        class="img-fluid">
                                                                                </div>
                                                                                @foreach ($val1 as $key2 => $val2)
                                                                                    @if ($key2 == 'Address')
                                                                                        <span id="{{ $key1 . '_' . $no }}_preview">{{ $key1 }}</span>
                                                                                    @endif
                                                                                @endforeach

                                                                            </a>
                                                                        @else
                                                                            @if ($key1 == 'Whatsapp')
                                                                                <a href="{{ url('https://wa.me/' . $href) }}" target="_blank">
                                                                                    <div class="contact-svg">
                                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/icon/' . $color . '/contact/' . strtolower($key1) . '.svg') }}"
                                                                                            class="img-fluid">
                                                                                    </div>
                                                                            @else
                                                                                <a href="{{ $href }}">
                                                                                    <div class="contact-svg">
                                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/icon/' . $color . '/contact/' . strtolower($key1) . '.svg') }}"
                                                                                            class="img-fluid">
                                                                                    </div>
                                                                            @endif
                                                                                    <span>
                                                                                        @if ($key1 == 'Web_url')
                                                                                        {{ 'Web URL' }} @else{{ __($key1) }}
                                                                                        @endif
                                                                                    </span>
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
                            </section>
                        @endif
                        @if ($order_key == 'description')
                            <section class="client-text-section padding-top description-div" id="description-div">
                                <div class="container">
                                    <div class="client-text bg-white">
                                        <p id="{{ $stringid . '_desc' }}_preview">{{ $business->description }}</p>
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'appointment')
                            <section class="appointment-section padding-top" id="appointment-div">
                                <div class="container">
                                    <div class="appointment-wraper bg-white">
                                        <div class="section-title text-center">
                                            <h2>{{ __('Make an') }}{{ __(' appointment') }}</h2>
                                        </div>
                                        <div class="appointment-date">
                                            <div class="date-label">
                                                {{ __('Date') }}
                                            </div>
                                            <input type="text" placeholder="{{ __('Pick a Date') }}"
                                                class="text-center datepicker_min">
                                        </div>
                                        <div class="pl-1 mt-0">
                                            <span class="text-danger text-center h5 span-error-date"
                                                style="{{ $SITE_RTL == 'on' ? 'margin-right: 80px;' : 'margin-left: 80px;' }}"></span>
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
                                                                                            <label for="radio-{{ $radiocount }}" class="radio-label">
                                                                                                @if (!empty($hour->start))
                                                                                                    {{ $hour->start }}
                                                                                                @else
                                                                                                    00:00
                                                                                                @endif - @if (!empty($hour->end))
                                                                                                    {{ $hour->end }}
                                                                                                @else
                                                                                                    00:00
                                                                                                @endif
                                                                                            </label>
                                                                                        </div>
                                                                                        @php
                                                                                            $radiocount++;
                                                                                            $appointment_no++;
                                                                                        @endphp
                                                                    @endforeach
                                                @endif
                                            </div>
                                            <div class="mt-0 mb-3 col-12">
                                                <span class="text-danger text-center span-error-time"
                                                    style="{{ $SITE_RTL == 'on' ? 'margin-right: 80px;' : 'margin-left: 80px;' }}"></span>
                                            </div>
                                        </div>
                                        <div class="appointment-btn">
                                            <a href="javascript:;" class="btn" tabindex="0">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                                        d="M6 3C6 2.44772 6.44772 2 7 2C7.55228 2 8 2.44772 8 3V4H16V3C16 2.44772 16.4477 2 17 2C17.5523 2 18 2.44772 18 3V4H19C20.6569 4 22 5.34315 22 7V19C22 20.6569 20.6569 22 19 22H5C3.34315 22 2 20.6569 2 19V7C2 5.34315 3.34315 4 5 4H6V3Z"
                                                        fill="white"></path>
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
                                <div class="container">
                                    <div class="service-section-wraper bg-white">
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
                                                                        <p style="color: white;"
                                                                            id="{{ 'description_' . $service_row_no . '_preview' }}">
                                                                            {{ $content->description }}
                                                                        </p>

                                                                        @if (!empty($content->purchase_link))
                                                                            <a href="{{ url($content->purchase_link) }}" class="read-more-btn"
                                                                                id="{{ 'link_title_' . $service_row_no . '_preview' }}">
                                                                                {{ $content->link_title }}
                                                                                <svg xmlns="http://www.w3.org/2000/svg" width="4" height="6"
                                                                                    viewBox="0 0 4 6" fill="none">
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
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'product')
                            <section class="product-section padding-bottom" id="product-div">
                                <div class="container">
                                    <div class="service-section-wraper bg-white">
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
                                                                        <p style="color: white;"
                                                                            id="{{ 'product_description_' . $product_row_no . '_preview' }}">
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
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'gallery')
                            <section class="gallery-section  padding-bottom" id="gallery-div">
                                <div class="container">
                                    <div class="service-section-wraper bg-white">
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
                                                                                    <div class="col-md-6 col-12 p-0 gallery-card-pdf"
                                                                                        id="gallery_{{ $gallery_row_no }}">
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
                                                                                                                                                                    <a href="javascript:;" id="" tabindex="0" class="videopop1">
                                                                                                                                                                        <video loop autoplay controls="true"
                                                                                                                                                                            poster="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/video-poster.jpg') }}">
                                                                                                                                                                            <source class="videoresource1"
                                                                                                                                                                                src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? 'https://www.youtube.com/embed/' . $video_url : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                                                                type="video/mp4">
                                                                                                                                                                        </video>
                                                                                                                                                                    </a>
                                                                                                                                                @else
                                                                                                                                                    <a href="javascript:;" id="" tabindex="0" class="videopop1">
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
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'testimonials')
                            <section class="testimonial-section text-center" id="testimonials-div">
                                <div class="section-title">
                                    <h2 class="text-white">{{ __('Testimonials') }}</h2>
                                </div>
                                @if (isset($is_pdf))
                                        <div class="row gap-bottom" id="inputrow_testimonials_preview">
                                            @php
                                                $t_image_count = 0;
                                                $rating = 0;
                                            @endphp
                                            @foreach ($testimonials_content as $k2 => $testi_content)
                                                    <div class="testimonial-card col-sm-6" id="testimonials_{{ $testimonials_row_no }}">
                                                        <div class="testimonial-card-inner">
                                                            <div class="user-pro">
                                                                <div class="user-avatar">
                                                                    <img id="{{ 't_image' . $t_image_count . '_preview' }}"
                                                                        src="{{ isset($testi_content->image) && !empty($testi_content->image) ? get_file($testi_content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image12.jpg') }}"
                                                                        alt="image">
                                                                </div>
                                                                <span class="total-rat">{{ $testi_content->rating }}/5</span>
                                                                @php
                                                                    if (!empty($testi_content->rating)) {
                                                                        $rating = (int) $testi_content->rating;
                                                                        $overallrating = $rating;
                                                                    } else {
                                                                        $overallrating = 0;
                                                                    }
                                                                @endphp
                                                                <br>
                                                                <span id="{{ 'stars' . $testimonials_row_no }}_star" class="stars">
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
                                @else
                                        <div class="testimonial-slider" id="inputrow_testimonials_preview">
                                            @php
                                                $t_image_count = 0;
                                                $rating = 0;
                                            @endphp
                                            @foreach ($testimonials_content as $k2 => $testi_content)
                                                    <div class="testimonial-card" id="testimonials_{{ $testimonials_row_no }}">
                                                        <div class="testimonial-card-inner">
                                                            <div class="user-pro">
                                                                <div class="user-avatar">
                                                                    <img id="{{ 't_image' . $t_image_count . '_preview' }}"
                                                                        src="{{ isset($testi_content->image) && !empty($testi_content->image) ? get_file($testi_content->image) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image12.jpg') }}"
                                                                        alt="image">
                                                                </div>
                                                                <span class="total-rat">{{ $testi_content->rating }}/5</span><br>
                                                                @php
                                                                    if (!empty($testi_content->rating)) {
                                                                        $rating = (int) $testi_content->rating;
                                                                        $overallrating = $rating;
                                                                    } else {
                                                                        $overallrating = 0;
                                                                    }
                                                                @endphp
                                                                <span id="{{ 'stars' . $testimonials_row_no }}_star" class="stars">
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
                            </section>
                        @endif
                        @if ($order_key == 'social')
                            <section class="social-icons-second-section" id="social-div">
                                <div class="container">
                                    <div class="social-icon-wrapper bg-white">
                                        <div class="section-title text-center">
                                            <h2>{{ __('Social') }}</h2>
                                        </div>
                                        <ul class="social-icon" id="inputrow_socials_preview">
                                            @if (!is_null($social_content) && !is_null($sociallinks))
                                                @foreach ($social_content as $social_key => $social_val)
                                                    @foreach ($social_val as $social_key1 => $social_val1)
                                                        @if ($social_key1 != 'id')
                                                            <li class="socials_{{ $loop->parent->index + 1 }}"
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
                                                                <div>
                                                                    <a href="{{ $social_links }}"
                                                                        id="social_link_{{ $loop->parent->index + 1 }}_href_preview"
                                                                        class="social_link_{{ $loop->parent->index + 1 }}_href_preview"
                                                                        target="_blank">
                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/icon/' . $color . '/social/' . strtolower($social_key1) . '.svg') }}"
                                                                            alt="{{ $social_key1 }}" class="img-fluid">
                                                                    </a>
                                                                </div>
                                                            </li>
                                                        @endif
                                                    @endforeach
                                                @endforeach
                                            @endif
                                        </ul>
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'more')
                            <section class="more-card-section padding-top">
                                <div class="container">
                                    <div class="more-btn">
                                        <a href="javascript:;" class="btn" tabindex="0"
                                            onclick="location.href = '{{ route('vcard.save', $business->slug) }}'">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="22" height="19" viewBox="0 0 22 19"
                                                fill="none">
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M10.8676 5.41797L9.6954 3.46428C9.39795 2.96852 8.94858 2.68502 8.49937 2.62776C7.63403 2.51745 6.55518 2.41797 5.5 2.41797C4.76672 2.41797 4.0186 2.46607 3.33466 2.5334C2.70504 2.59538 2.24029 3.06309 2.19011 3.71721C2.08968 5.02657 2 6.90062 2 9.41797C2 11.1906 2.06456 12.7695 2.14656 14.0596C2.22311 15.2638 3.13628 16.1727 4.34979 16.2427C5.99648 16.3376 8.2132 16.418 11 16.418C13.7868 16.418 16.0035 16.3376 17.6502 16.2427C18.8637 16.1727 19.7769 15.2638 19.8534 14.0596C19.9354 12.7695 20 11.1906 20 9.41797C20 8.45184 19.944 7.53469 19.8699 6.74589C19.8087 6.09488 19.3231 5.60922 18.6721 5.54806C17.8833 5.47396 16.9661 5.41797 16 5.41797H10.8676ZM12 3.41797L11.4104 2.43529C10.8306 1.46891 9.87021 0.786322 8.75227 0.643814C7.83875 0.527364 6.66937 0.417969 5.5 0.417969C4.68392 0.417969 3.86784 0.471247 3.13873 0.543021C1.53169 0.701216 0.319474 1.95417 0.19597 3.56425C0.0913573 4.92805 0 6.85326 0 9.41797C0 11.2385 0.0662874 12.8602 0.150591 14.1865C0.291559 16.4042 2.01613 18.1114 4.23467 18.2394C5.91963 18.3365 8.17474 18.418 11 18.418C13.8253 18.418 16.0804 18.3365 17.7653 18.2394C19.9839 18.1114 21.7084 16.4042 21.8494 14.1865C21.9337 12.8602 22 11.2385 22 9.41797C22 8.37683 21.9398 7.39592 21.8611 6.55882C21.7101 4.95109 20.4669 3.70787 18.8591 3.55683C18.0221 3.47819 17.0411 3.41797 16 3.41797H12Z"
                                                    fill="white"></path>
                                            </svg>
                                            {{ __('Save Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-card" tabindex="0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="22" viewBox="0 0 18 22"
                                                fill="none">
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M5.99858 9.03956C6.02798 9.59107 5.60474 10.062 5.05324 10.0914C4.30055 10.1315 3.7044 10.1806 3.23854 10.2297C2.61292 10.2957 2.23278 10.68 2.16959 11.2328C2.07886 12.0264 2 13.2275 2 14.9997C2 16.772 2.07886 17.973 2.16959 18.7666C2.23289 19.3204 2.61207 19.7036 3.23675 19.7695C4.33078 19.885 6.13925 19.9997 9 19.9997C11.8608 19.9997 13.6692 19.885 14.7632 19.7695C15.3879 19.7036 15.7671 19.3204 15.8304 18.7666C15.9211 17.973 16 16.7719 16 14.9997C16 13.2275 15.9211 12.0264 15.8304 11.2328C15.7672 10.68 15.3871 10.2957 14.7615 10.2297C14.2956 10.1806 13.6995 10.1315 12.9468 10.0914C12.3953 10.062 11.972 9.59107 12.0014 9.03956C12.0308 8.48806 12.5017 8.06482 13.0532 8.09422C13.8361 8.13595 14.4669 8.18757 14.9712 8.24075C16.4556 8.3973 17.6397 9.4504 17.8175 11.0056C17.9188 11.892 18 13.1712 18 14.9997C18 16.8282 17.9188 18.1074 17.8175 18.9938C17.6398 20.5481 16.4585 21.6017 14.9732 21.7585C13.7919 21.8831 11.9108 21.9997 9 21.9997C6.08922 21.9997 4.20806 21.8831 3.02684 21.7585C1.54151 21.6017 0.360208 20.5481 0.182529 18.9938C0.081204 18.1074 0 16.8282 0 14.9997C0 13.1712 0.0812039 11.892 0.182529 11.0056C0.360314 9.4504 1.54436 8.3973 3.02877 8.24075C3.53306 8.18757 4.16393 8.13595 4.94676 8.09422C5.49827 8.06482 5.96918 8.48806 5.99858 9.03956Z"
                                                    fill="white"></path>
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M6.20711 5.20711C5.81658 5.59763 5.18342 5.59763 4.79289 5.20711C4.40237 4.81658 4.40237 4.18342 4.79289 3.79289L8.29289 0.292893C8.68342 -0.0976311 9.31658 -0.0976311 9.70711 0.292893L13.2071 3.79289C13.5976 4.18342 13.5976 4.81658 13.2071 5.20711C12.8166 5.59763 12.1834 5.59763 11.7929 5.20711L10 3.41421V13C10 13.5523 9.55228 14 9 14C8.44772 14 8 13.5523 8 13L8 3.41421L6.20711 5.20711Z"
                                                    fill="white"></path>
                                            </svg>
                                            {{ __('Share Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-contact" tabindex="0">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"
                                                fill="none">
                                                <path fill-rule="evenodd" clip-rule="evenodd"
                                                    d="M4.82379 1.66449C5.83674 0.651544 7.512 0.770603 8.37152 1.91662L9.79825 3.81894C10.5011 4.75611 10.4079 6.06751 9.57958 6.89586L8.52769 7.94775C8.62963 8.2131 8.96522 8.79539 9.98717 9.81734C11.0091 10.8393 11.5914 11.1749 11.8567 11.2768L12.9086 10.2249C13.737 9.39657 15.0484 9.30336 15.9856 10.0062L17.8879 11.433C19.0339 12.2925 19.153 13.9678 18.14 14.9807C17.8107 15.31 17.755 15.3658 17.2054 15.9153C16.6453 16.4754 15.456 16.999 14.2519 17.0513C12.3677 17.1332 9.80829 16.2966 6.65811 13.1464C3.50793 9.99621 2.67127 7.43681 2.7532 5.55258C2.79877 4.50442 3.13201 3.35321 3.89355 2.6035C4.43874 2.04954 4.50964 1.97864 4.82379 1.66449ZM4.32105 5.62075C4.26426 6.92686 4.81471 9.08362 7.7678 12.0367C10.7209 14.9898 12.8776 15.5402 14.1838 15.4835C15.403 15.4304 16.0571 14.8413 16.0957 14.8056L17.0303 13.871C17.368 13.5334 17.3283 12.975 16.9463 12.6885L15.044 11.2617C14.7316 11.0274 14.2944 11.0585 14.0183 11.3346C13.6023 11.7506 13.3183 12.0388 12.7624 12.5926C11.6077 13.743 9.63106 11.6806 8.87748 10.927C8.18495 10.2345 6.07446 8.1951 7.21078 7.04531C7.21302 7.04305 7.51909 6.73699 8.4699 5.78618C8.74601 5.51006 8.77708 5.07293 8.54279 4.76054L7.11605 2.85822C6.82955 2.47621 6.27112 2.43653 5.93348 2.77418C5.62271 3.08494 5.271 3.43665 4.99979 3.70973C4.44161 4.27178 4.35092 4.93369 4.32105 5.62075Z"
                                                    fill="white"></path>
                                            </svg>
                                            {{ __('Contact') }}
                                        </a>
                                    </div>
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
                                <div class="bg-white">
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
                                </div>
                            </section>
                        @endif

                        @php        $j = $j + 1; @endphp
                    @endif
                @endforeach

                @if ($is_branding_enabled)
                    <div id="is_branding_enabled" class="is_branding_enable copyright mt-3 pb-2">
                        <p id="{{ $stringid . '_branding' }}_preview" class="branding_text">
                            {{ $brandingText }}
                        </p>
                    </div>
                @endif


            </div>
            <!--appointment popup start here-->
            <div class="appointment-popup">
                <div class="container">
                    <form class="appointment-form-wrapper">
                        <div class="section-title">
                            <h5>{{ __('Make Appointment') }}</h5>
                            <div class="close-search">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"
                                    fill="none">
                                    <path
                                        d="M14.6 17.4L0.600001 3.4C-0.2 2.6 -0.2 1.4 0.600001 0.600001C1.4 -0.2 2.6 -0.2 3.4 0.600001L17.4 14.6C18.2 15.4 18.2 16.6 17.4 17.4C16.6 18.2 15.4 18.2 14.6 17.4V17.4Z"
                                        fill="#000" />
                                    <path
                                        d="M0.600001 14.6L14.6 0.600001C15.4 -0.2 16.6 -0.2 17.4 0.600001C18.2 1.4 18.2 2.6 17.4 3.4L3.4 17.4C2.6 18.2 1.4 18.2 0.600001 17.4C-0.2 16.6 -0.2 15.4 0.600001 14.6V14.6Z"
                                        fill="#000" />
                                </svg>
                            </div>
                        </div>
                        <div class="row appo-form-details">
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Name:') }} </label>
                                    <input type="text" class="form-control app_name"
                                        placeholder="{{ __('Enter your name') }}">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-name"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Email:') }} </label>
                                    <input type="email" class="form-control app_email"
                                        placeholder="{{ __('Enter your email') }}">
                                    <div class="">
                                        <span class="text-danger  h5 span-error-email"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <div class="form-group">
                                    <label>{{ __('Phone:') }} </label>
                                    <input type="number" class="form-control app_phone"
                                        placeholder="{{ __('Enter your phone no.') }}">
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
                            <button type="button" name="SUBMIT" class="btn btn-secondary" id="makeappointment">
                                {{ __('Make Appointment') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!--appointment popup end here-->
            <!--card popup start here-->
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
                            <div class="qr-code-text">
                                <p>{{ __('Point your camera at the QR code, or visit ') }}<span
                                        class="qr-link text-center mr-2 text-wrap"></span><br>{{ __('Or check my social
                                    channels') }}
                                </p>
                            </div>
                            <ul class="card-social-icons">
                                <li>
                                    <a href="https://twitter.com/share?url={{ urlencode($url_link) }}&text=">
                                        <svg width="21" height="20" viewBox="0 0 21 20" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M9.81853 5.43101L9.81845 5.43089L8.08407 2.90322L8.00958 2.79466H7.87793H5.84668H3.81934H3.34465L3.61319 3.18609L3.98019 3.72105L9.81853 5.43101ZM9.81853 5.43101C10.2948 6.126 10.7318 6.75822 11.0525 7.21692C11.1969 7.42343 11.3174 7.59423 11.407 7.71956C11.5154 7.59853 11.6634 7.43089 11.8422 7.22673C12.2302 6.78361 12.7609 6.1709 13.3406 5.49546C13.9206 4.81959 14.4569 4.1953 14.8541 3.73457C15.0526 3.50424 15.2166 3.31454 15.334 3.17962C15.3927 3.11222 15.44 3.05808 15.4744 3.01924L15.4745 3.01913C15.5007 2.9895 15.5311 2.95517 15.5497 2.93741C15.5802 2.90856 15.6223 2.87371 15.6837 2.84763C15.7406 2.8235 15.7991 2.81317 15.8559 2.80758C15.9629 2.79706 16.127 2.7987 16.3855 2.8025L16.3869 2.80253L16.965 2.81425L17.4955 2.825L17.1495 3.22722L14.6964 6.07916L14.6964 6.07917C14.0941 6.77929 13.5333 7.43187 13.094 7.94317C12.6926 8.41037 12.3925 8.75961 12.2547 8.91938C12.2954 8.99226 12.369 9.1113 12.4976 9.30726C12.837 9.82444 13.5223 10.8224 14.873 12.7871L14.8731 12.7873C15.629 13.889 16.3165 14.8911 16.8151 15.6183C17.0644 15.9819 17.2664 16.2768 17.4062 16.4811L17.5674 16.7171L17.6099 16.7796L17.6216 16.7971L17.6264 16.8043L17.6308 16.8114C17.6316 16.8127 17.6341 16.8169 17.6371 16.8224C17.6388 16.8256 17.6431 16.8343 17.6456 16.8399C17.6505 16.8516 17.6639 16.9018 17.667 16.941C17.667 17.0225 17.6288 17.0799 17.6016 17.1096C17.5758 17.1379 17.5495 17.1536 17.5368 17.1605C17.5109 17.1747 17.4884 17.1809 17.482 17.1826C17.4654 17.1871 17.4516 17.189 17.4478 17.1895L17.4477 17.1895C17.4373 17.1909 17.4277 17.1916 17.423 17.1919C17.4116 17.1926 17.3978 17.1932 17.3836 17.1936C17.3543 17.1946 17.3126 17.1954 17.261 17.1961C17.1571 17.1976 17.0076 17.1988 16.8241 17.1998C16.4568 17.2018 15.9504 17.2027 15.3936 17.2027H13.3701H13.2384L13.1639 17.0941L11.3166 14.3988C11.3165 14.3987 11.3164 14.3986 11.3163 14.3984C10.8068 13.6586 10.3415 12.9873 10.0022 12.5027C9.85055 12.2861 9.72442 12.1072 9.63093 11.9764M9.81853 5.43101L9.63093 11.9764M9.63093 11.9764C9.51187 12.1129 9.34487 12.3055 9.14125 12.5409C8.7196 13.0285 8.14166 13.6993 7.50895 14.4356L7.50889 14.4357L5.2042 17.1157L5.12939 17.2027H5.01465H4.41699H3.81543H3.28171L3.62336 16.7927L3.77961 16.6052L3.77961 16.6052L3.78082 16.6037C3.86775 16.501 5.06406 15.109 6.43851 13.5157C6.43855 13.5157 6.43859 13.5156 6.43863 13.5156L8.81274 10.7553L6.63295 7.58935L6.63255 7.58877C5.75191 6.30193 4.89647 5.05595 4.39698 4.32843C4.18705 4.02265 4.03999 3.80846 3.98037 3.72132L9.63093 11.9764ZM0.94092 1.68266L0.940946 1.68267L0.940013 1.68622L0.939827 1.68692C0.910513 1.79833 0.889714 1.87737 0.879872 2.95173C0.87012 4.0163 0.870117 6.03444 0.870117 9.9987C0.870117 13.963 0.87012 15.9811 0.879872 17.0457C0.889714 18.12 0.910513 18.1991 0.939827 18.3105L0.940013 18.3112L0.940039 18.3112L0.94092 18.3147C1.02675 18.6615 1.17412 18.9153 1.43754 19.1825C1.70476 19.446 1.95845 19.5934 2.30519 19.6792L2.3052 19.6792L2.30875 19.6801L2.30946 19.6803C2.42083 19.7096 2.49987 19.7304 3.57407 19.7402C4.6385 19.75 6.65638 19.75 10.6201 19.75C14.5839 19.75 16.6017 19.75 17.6662 19.7402C18.7404 19.7304 18.8194 19.7096 18.9308 19.6803L18.9315 19.6801L18.9315 19.6801L18.935 19.6792C19.2818 19.5934 19.5354 19.446 19.8027 19.1826C20.0661 18.9153 20.2135 18.6616 20.2993 18.3147L20.2993 18.3147L20.3002 18.3112L20.3004 18.3105C20.3297 18.1991 20.3505 18.12 20.3604 17.0457C20.3701 15.9811 20.3701 13.963 20.3701 9.9987C20.3701 6.03444 20.3701 4.0163 20.3604 2.95173C20.3505 1.87737 20.3297 1.79833 20.3004 1.68692L20.3002 1.68622L20.2996 1.68378C20.2099 1.32839 20.0664 1.07912 19.7849 0.800982C19.5056 0.528778 19.2982 0.408131 18.935 0.318193L18.935 0.318218L18.9315 0.317291L18.9313 0.317245C18.8198 0.287882 18.7404 0.267002 17.6614 0.257635C16.594 0.248369 14.5723 0.249342 10.6046 0.251295H10.6045C6.678 0.251295 4.65511 0.252275 3.57893 0.26203C2.49496 0.271854 2.39474 0.29159 2.29257 0.317444C1.9586 0.407234 1.70018 0.556009 1.43751 0.814888C1.17412 1.08214 1.02675 1.33586 0.94092 1.68266ZM12.2165 8.84327C12.2162 8.84219 12.216 8.84163 12.216 8.84162L12.2165 8.84327Z"
                                                fill="white" stroke="url(#paint0_linear_728_593)" stroke-width="0.5" />
                                            <path
                                                d="M5.51074 4.02932C5.68262 4.29107 13.8779 16.0035 13.917 16.0465C13.9521 16.0817 14.1787 16.0934 14.8701 16.0856L15.7725 16.0739L11.542 10.0184L7.30762 3.9629L6.38184 3.95118L5.45215 3.94337L5.51074 4.02932Z"
                                                fill="url(#paint1_linear_728_593)" />
                                            <defs>
                                                <linearGradient id="paint0_linear_728_593" x1="18.2568" y1="19.192"
                                                    x2="2.36082" y2="0.00203801" gradientUnits="userSpaceOnUse">
                                                    <stop stop-color="#F05C35" />
                                                    <stop offset="1" stop-color="#F05C35" />
                                                </linearGradient>
                                                <linearGradient id="paint1_linear_728_593" x1="18.7842" y1="19.8074"
                                                    x2="1.71634" y2="1.21835" gradientUnits="userSpaceOnUse">
                                                    <stop stop-color="#F05C35" />
                                                    <stop offset="1" stop-color="#F05C35" />
                                                </linearGradient>
                                            </defs>
                                        </svg>

                                    </a>
                                </li>
                                <li>
                                    @php
                                        $whatsapp_link = url('https://wa.me/?text=' . urlencode($url_link));
                                    @endphp
                                    <a href="{{ $whatsapp_link }}">
                                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M10 0C4.5 0 0 4.5 0 10C0 11.8 0.500781 13.5 1.30078 15L0 20L5.19922 18.8008C6.69922 19.6008 8.3 20 10 20C15.5 20 20 15.5 20 10C20 7.3 18.9996 4.80039 17.0996 2.90039C15.1996 1.00039 12.7 0 10 0ZM10 2C12.1 2 14.0992 2.80078 15.6992 4.30078C17.1992 5.90078 18 7.9 18 10C18 14.4 14.4 18 10 18C8.7 18 7.29922 17.7 6.19922 17L5.5 16.5996L4.80078 16.8008L2.80078 17.3008L3.30078 15.5L3.5 14.6992L3.09961 14C2.39961 12.8 2 11.4 2 10C2 5.6 5.6 2 10 2ZM6.5 5.40039C6.3 5.40039 6.00078 5.39922 5.80078 5.69922C5.50078 5.99922 4.90039 6.60078 4.90039 7.80078C4.90039 9.00078 5.80039 10.2004 5.90039 10.4004C6.10039 10.6004 7.69922 13.1992 10.1992 14.1992C12.2992 14.9992 12.6992 14.8008 13.1992 14.8008C13.6992 14.7008 14.7004 14.1996 14.9004 13.5996C15.1004 12.9996 15.0992 12.4992 15.1992 12.1992C15.0992 12.0992 14.9992 12.0004 14.6992 11.9004C14.4992 11.8004 13.3 11.1996 13 11.0996C12.7 10.9996 12.6004 10.8992 12.4004 11.1992C12.2004 11.4992 11.6996 11.9992 11.5996 12.1992C11.4996 12.3992 11.3996 12.4008 11.0996 12.3008C10.8996 12.2008 10.0996 11.9996 9.09961 11.0996C8.29961 10.4996 7.79922 9.70039 7.69922 9.40039C7.49922 9.20039 7.70078 9.00039 7.80078 8.90039L8.19922 8.5C8.29922 8.4 8.30039 8.19961 8.40039 8.09961C8.50039 7.99961 8.50039 7.89922 8.40039 7.69922C8.30039 7.49922 7.79961 6.30078 7.59961 5.80078C7.39961 5.40078 7.2 5.40039 7 5.40039H6.5Z"
                                                fill="white" />
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a href="https://www.facebook.com/sharer.php?u={{ urlencode($url_link) }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                            viewBox="0 0 20 20" fill="none">
                                            <path
                                                d="M13.3333 19.25H11H3C2.61666 19.25 2.04526 18.9887 1.53351 18.4845C1.02334 17.9819 0.75 17.4126 0.75 17V3C0.75 2.58739 1.02334 2.01811 1.53351 1.51546C2.04526 1.01127 2.61666 0.75 3 0.75H17C17.3833 0.75 17.9547 1.01127 18.4665 1.51546C18.9767 2.01811 19.25 2.58739 19.25 3V17C19.25 17.4126 18.9767 17.9819 18.4665 18.4845C17.9547 18.9887 17.3833 19.25 17 19.25H13.3333Z"
                                                stroke="white" stroke-width="1.5"></path>
                                            <path
                                                d="M12 8.21429C12 7.62255 12.4477 7.14286 13 7.14286H14C14.5523 7.14286 15 6.66317 15 6.07143C15 5.47969 14.5523 5 14 5H13C11.3431 5 10 6.43908 10 8.21429V10.3571H9C8.44771 10.3571 8 10.8368 8 11.4286C8 12.0203 8.44771 12.5 9 12.5H10V18.9286C10 19.5203 10.4477 20 11 20C11.5523 20 12 19.5203 12 18.9286V12.5H14C14.5523 12.5 15 12.0203 15 11.4286C15 10.8368 14.5523 10.3571 14 10.3571H12V8.21429Z"
                                                fill="white"></path>
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://www.linkedin.com/shareArticle?url={{ urlencode($url_link) }}&title=">
                                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M0 3C0 1.34315 1.34315 0 3 0H17C18.6569 0 20 1.34315 20 3V17C20 18.6569 18.6569 20 17 20H3C1.34315 20 0 18.6569 0 17V3Z"
                                                stroke="white" stroke-width="1.5" />
                                            <path
                                                d="M5 6C5.55228 6 6 5.55228 6 5C6 4.44772 5.55228 4 5 4C4.44772 4 4 4.44772 4 5C4 5.55228 4.44772 6 5 6Z"
                                                fill="white" />
                                            <path
                                                d="M5 8C4.44772 8 4 8.44772 4 9V15C4 15.5523 4.44772 16 5 16C5.55228 16 6 15.5523 6 15V9C6 8.44772 5.55228 8 5 8Z"
                                                fill="white" />
                                            <path
                                                d="M12 10C10.8954 10 10 10.8954 10 12V15C10 15.5523 9.55229 16 9 16C8.44771 16 8 15.5523 8 15V9C8 8.44772 8.44772 8 9 8C9.40537 8 9.7544 8.2412 9.91141 8.58791C10.5193 8.215 11.2346 8 12 8C14.2091 8 16 9.79086 16 12V15C16 15.5523 15.5523 16 15 16C14.4477 16 14 15.5523 14 15V12C14 10.8954 13.1046 10 12 10Z"
                                                fill="white" />
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a href="" id="copylink" data-bs-toggle="tooltip" data-bs-placement="bottom"
                                        title="" class="copy-icon insta-icon" style="fill: white;">
                                        <svg id="Layer_1" enable-background="new 0 0 24 24" height="512"
                                            viewBox="0 0 24 24" width="512" xmlns="http://www.w3.org/2000/svg">
                                            <g>
                                                <path
                                                    d="m21.71 5.29-5-5c-.19-.18-.44-.29-.71-.29h-11c-1.65 0-3 1.35-3 3v18c0 1.65 1.35 3 3 3h3c.55 0 1-.45 1-1s-.45-1-1-1h-3c-.55 0-1-.45-1-1v-18c0-.55.45-1 1-1h9v5c0 .55.45 1 1 1h5v5c0 .55.45 1 1 1s1-.45 1-1v-7c0-.27-.11-.52-.29-.71zm-5.71.71v-3.59l3.59 3.59z" />
                                            </g>
                                            <g>
                                                <path
                                                    d="m14 24c-2.21 0-4-1.79-4-4s1.79-4 4-4c.55 0 1 .45 1 1s-.45 1-1 1c-1.1 0-2 .9-2 2s.9 2 2 2c.55 0 1 .45 1 1s-.45 1-1 1z" />
                                            </g>
                                            <g>
                                                <path
                                                    d="m18 24c-.55 0-1-.45-1-1s.45-1 1-1c1.1 0 2-.9 2-2s-.9-2-2-2c-.55 0-1-.45-1-1s.45-1 1-1c2.21 0 4 1.79 4 4s-1.79 4-4 4z" />
                                            </g>
                                            <g>
                                                <path
                                                    d="m18 21h-4c-.55 0-1-.45-1-1s.45-1 1-1h4c.55 0 1 .45 1 1s-.45 1-1 1z" />
                                            </g>
                                        </svg>
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
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18"
                                    fill="none">
                                    <path
                                        d="M14.6 17.4L0.600001 3.4C-0.2 2.6 -0.2 1.4 0.600001 0.600001C1.4 -0.2 2.6 -0.2 3.4 0.600001L17.4 14.6C18.2 15.4 18.2 16.6 17.4 17.4C16.6 18.2 15.4 18.2 14.6 17.4V17.4Z"
                                        fill="#000" />
                                    <path
                                        d="M0.600001 14.6L14.6 0.600001C15.4 -0.2 16.6 -0.2 17.4 0.600001C18.2 1.4 18.2 2.6 17.4 3.4L3.4 17.4C2.6 18.2 1.4 18.2 0.600001 17.4C-0.2 16.6 -0.2 15.4 0.600001 14.6V14.6Z"
                                        fill="#000" />
                                </svg>
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
                                    <textarea name="message" placeholder="" row="3"
                                        class=" custom_size contact_message emojiarea"
                                        id="recipient-message"></textarea>
                                    <div class="">
                                        <span class="text-danger h5 span-error-contactmessage"></span>
                                    </div>
                                </div>
                                <input type="hidden" name="business_id" value="{{ $business->id }}">
                            </div>
                        </div>
                        <div class="form-btn-group">
                            <button type="button" name="CLOSE" class="close-btn btn">
                                {{ __('Close') }}
                            </button>
                            <button type="button" name="SUBMIT" class="btn btn-secondary" id="makecontact">
                                {{ __('Make Contact') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <!--contact popup end here-->
            <!-- Modal -->
            <div class="contact-popup2" id="passwordmodel" role="dialog" data-backdrop="static" data-keyboard="false">
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
                                        <span class="text-danger  h5 span-error-password"></span>
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
            <div class="contact-popup2" id="gallerymodel" role="dialog" data-backdrop="static" data-keyboard="false">
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
            <div class="contact-popup2" id="videomodel" role="dialog" data-backdrop="static" data-keyboard="false">
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

            <div class="overlay"></div>
            <img src="{{ isset($qr_detail->image) ? get_file($qr_detail->image) : '' }}" id="image-buffers"
                style="display: none">
        </div>
    </div>
    <div id="previewImage"> </div>
    <a id="download" href="#" class="font-lg download mr-3 text-white">
        <i class="fas fa-download"></i>
    </a>


    <script
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/modal/bootstrap.min.js') }}"></script>

    <script
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/js/jquery.min.js') }}"></script>
    <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/js/slick.min.js') }}"
        defer="defer">
        </script>

    @if ($SITE_RTL == 'on')
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/js/rtl-custom.js') }}"
            defer="defer">
            </script>
    @else
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme21/js/custom.js') }}"
            defer="defer"></script>
    @endif

    @include('marketing::layouts.theme_script')
</body>

</html>