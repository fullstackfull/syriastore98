<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="author" content="{{ $store->name }}">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <meta name="HandheldFriendly" content="True">

    <title>{{ $store->name }}</title>
    <meta name="author" content="{{ $store->name }}">
    <meta name="keywords" content="{{ $metaKeyword }}">
    <meta name="description" content="{{ $metaDesc }}">

    {{-- Meta tag Preview --}}
    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ $url_link }}">
    <meta property="og:title" content="{{ $store->name }}">
    <meta property="og:description" content="{{ $metaDesc }}">
    <meta property="og:image"
        content="{{ !empty($metaImg) ? $metaImg : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ $url_link }}">
    <meta property="twitter:title" content="{{ $store->name }}">
    <meta property="twitter:description" content="{{  $metaDesc }}">
    <meta property="twitter:image"
        content="{{ !empty($metaImg) ? $metaImg : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}">

    {{-- End Meta tag Preview --}}

    <link rel="icon" href="{{ isset($company_favicon) && !empty($company_favicon) ? $company_favicon : 'favicon.png' }}"
        type="image" sizes="16x16">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/libs/@fortawesome/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/fonts/stylesheet.css') }}">
    @if ($SITE_RTL == 'on')
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/css/rtl-main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/css/rtl-responsive.css') }}">
    @else
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/css/main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/css/responsive.css') }}">
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
            {{ $customCss }}
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
    <div class="{{ $themeOne['theme3'][$store->theme_color]['theme_name'] ?? "theme3-v1"}}" id="view_theme13">
        <main id="boxes">
            <div class="card-wrapper">
                <div class="bussiness-card">
                    <div class="bussiness-card-body">
                        <section class="profile-section">
                            <div class="profile-cover">
                                <img src="{{ isset($store->banner) && !empty($store->banner) ? get_file($store->banner) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}"
                                    id="banner_preview" alt="fs">
                            </div>
                            <div class="profile-content">
                                <div class="text-left desc-wrapper">
                                    <p id="{{ $stringid . '_desc' }}_preview">{{ $store->description }}
                                    </p>
                                </div>
                                <div class="user-profile">
                                    <div class="user-avatar">
                                        <img src="{{ isset($store->logo) && !empty($store->logo) ? get_file($store->logo) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                            id="business_logo_preview" alt="user" class="mb-4 img-thumbnail"
                                            style="border-radius: 100%;">
                                    </div>
                                    <div class="user-name">
                                        <h3 class="text-white" id="{{ $stringid . '_title' }}_preview">
                                            {{ $store->name }}
                                        </h3>
                                        <p id="{{ $stringid . '_designation' }}_preview">{{ $store->Company }}
                                        </p>
                                        <span id="{{ $stringid . '_subtitle' }}_preview">{{ $store->industry }}</span>
                                    </div>
                                </div>
                            </div>
                        </section>
                        @php $j = 1; @endphp
                        @foreach ($card_theme["order"] as $order_key => $order_value)
                                    @if ($j == $order_value)
                                                @if ($order_key == 'gallery')
                                                    <section class="gallery-section" id="gallery-div">
                                                        <div class="section-title text-left">
                                                            <h2 class="text-white">{{ __('Gallery') }}</h2>
                                                        </div>
                                                        <div id="inputrow_gallery_preview">
                                                            @php            $image_count = 0; @endphp
                                                            @if (isset($is_pdf))
                                                                        <div class="gallery-cards">
                                                                            <div class="row">
                                                                                @if (!is_null($gallery_contents) && !is_null($gallery))
                                                                                                    @foreach ($gallery_contents as $key => $gallery_content)
                                                                                                                        @if (isset($gallery_content->type))
                                                                                                                            @if ($gallery_content->type == 'video')
                                                                                                                            @elseif($gallery_content->type == 'image')
                                                                                                                                <div class="gallery-itm col-12">
                                                                                                                                    <div class="gallery-media">
                                                                                                                                        <a href="javascript:;" id="imagepopup" tabindex="0" class="imagepopup">
                                                                                                                                            <img src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                                alt="images" class="imageresource">
                                                                                                                                        </a>
                                                                                                                                    </div>
                                                                                                                                </div>
                                                                                                                            @endif
                                                                                                                        @endif
                                                                                                                        @php
                                                                                                                            $image_count++;
                                                                                                                            $gallery_row_no++;
                                                                                                                        @endphp
                                                                                                    @endforeach
                                                                                @endif
                                                                            </div>

                                                                        </div>
                                                            @else
                                                                        <div class="gallery-slider">
                                                                            @if (!is_null($gallery_contents) && !is_null($gallery))
                                                                                            @foreach ($gallery_contents as $key => $gallery_content)
                                                                                                            <div class="gallery-itm" id="gallery_{{ $gallery_row_no }}">
                                                                                                                <div class="gallery-media">
                                                                                                                    @if (isset($gallery_content->type))
                                                                                                                                @if ($gallery_content->type == 'video')
                                                                                                                                    <a href="javascript:;" tabindex="0" class="videopop play-btn">

                                                                                                                                        <video loop autoplay controls="true">
                                                                                                                                            <source class="videoresource"
                                                                                                                                                src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                                type="video/mp4">
                                                                                                                                        </video>
                                                                                                                                    </a>
                                                                                                                                @elseif($gallery_content->type == 'image')
                                                                                                                                    <a href="javascript:;" tabindex="0" class="imagepopup">

                                                                                                                                        <img src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? get_file($gallery_content->value) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                            alt="images" class="imageresource">
                                                                                                                                    </a>
                                                                                                                                @elseif($gallery_content->type == 'custom_video_link')
                                                                                                                                            @if (str_contains($gallery_content->value, 'youtube') || str_contains($gallery_content->value, 'youtu.be'))
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
                                                                                                                                    <a href="javascript:;" tabindex="0" class="imagepopup1">

                                                                                                                                        <img class="imageresource1"
                                                                                                                                            src="{{ isset($gallery_content->value) && !empty($gallery_content->value) ? $gallery_content->value : asset('packages/workdo/Marketing/src/resources/assets/custom/img/logo-placeholder-image-2.png') }}"
                                                                                                                                            alt="images" id="upload_image">
                                                                                                                                    </a>
                                                                                                                                @endif
                                                                                                                    @endif
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

                                                    </section>
                                                @endif
                                                @if ($order_key == 'service')
                                                            <section class="service-section" id="services-div">
                                                                <div class="section-title">
                                                                    <h2> {{ __('Services') }}</h2>
                                                                </div>
                                                                <div class="row row-gap" id="inputrow_service_preview">
                                                                    @php            $image_count = 0; @endphp
                                                                    @foreach ($services_content as $k1 => $content)
                                                                                        <div class="col-sm-6 col-12 service-card" id="services_{{ $service_row_no }}">
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
                                                                                                    <a href="{{ url($content->purchase_link) }}" class="btn"
                                                                                                        id="{{ 'link_title_' . $service_row_no . '_preview' }}">{{ $content->link_title }}
                                                                                                        <svg xmlns="http://www.w3.org/2000/svg" width="21" height="22"
                                                                                                            viewBox="0 0 21 22" fill="none">
                                                                                                            <path opacity="0.4"
                                                                                                                d="M10.5 19.5731C15.1833 19.5731 18.9799 15.7765 18.9799 11.0932C18.9799 6.40986 15.1833 2.61328 10.5 2.61328C5.81672 2.61328 2.02014 6.40986 2.02014 11.0932C2.02014 15.7765 5.81672 19.5731 10.5 19.5731Z"
                                                                                                                fill="white"></path>
                                                                                                            <path
                                                                                                                d="M14.4787 10.8497C14.4464 10.7717 14.3999 10.7014 14.3414 10.6429L11.7974 8.09894C11.549 7.85048 11.1462 7.85048 10.8977 8.09894C10.6492 8.3474 10.6492 8.75023 10.8977 8.99869L12.3562 10.4572H7.10804C6.75697 10.4572 6.47205 10.7421 6.47205 11.0932C6.47205 11.4443 6.75697 11.7292 7.10804 11.7292H12.3562L10.8977 13.1877C10.6492 13.4362 10.6492 13.839 10.8977 14.0875C11.0215 14.2113 11.1843 14.274 11.3472 14.274C11.51 14.274 11.6728 14.2121 11.7966 14.0875L14.3406 11.5435C14.3991 11.485 14.4456 11.4147 14.4778 11.3367C14.5431 11.1806 14.5431 11.0058 14.4787 10.8497Z"
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
                                                            </section>
                                                @endif
                                                @if ($order_key == 'product')
                                                            <section class="product-section" id="product-div">
                                                                <div class="section-title text-center">
                                                                    <h2>{{ __('Product') }}</h2>
                                                                </div>
                                                                <div class="row row-gap" id="inputrow_product_preview">
                                                                    @php            $pr_image_count = 0; @endphp
                                                                    @foreach ($products_content as $k1 => $content)
                                                                                        <div class="col-sm-6 col-12 service-card" id="product_{{ $product_row_no }}">
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
                                                                                                    <span
                                                                                                        id="{{ 'product_currency_select' . $product_row_no . '_preview' }}">{{ $content->currency }}</span>
                                                                                                    <span
                                                                                                        id="{{ 'product_price_' . $product_row_no . '_preview' }}">{{ $content->price }}</span>
                                                                                                </div>

                                                                                                @if (!empty($content->purchase_link))
                                                                                                    <a href="{{ url($content->purchase_link) }}"
                                                                                                        id="{{ 'product_link_title_' . $product_row_no . '_preview' }}"
                                                                                                        class="btn">{{ $content->link_title }}</a>
                                                                                                @endif
                                                                                            </div>
                                                                                        </div>
                                                                                        @php
                                                                                            $pr_image_count++;
                                                                                            $product_row_no++;
                                                                                        @endphp
                                                                    @endforeach
                                                                </div>
                                                            </section>
                                                @endif
                                                @if ($order_key == 'bussiness_hour')
                                                    <section class="bussiness-hour" id="business-hours-div">
                                                        <div class="section-title">
                                                            <h2 class="text-white">{{ __('Business Hours') }}</h2>
                                                        </div>
                                                        <div class="d-flex justify-content-center">
                                                            <ul class="hours-list">
                                                                @foreach ($days as $k => $day)
                                                                    <li><b>{{ __($day) }}:</b>
                                                                        <span class="days_{{ $k }}">
                                                                            @if (isset($business_hours->$k) && $business_hours->$k->days == 'on')
                                                                                <span
                                                                                    class="days_{{ $k }}_start">{{ !empty($business_hours->$k->start_time) && isset($business_hours->$k->start_time) ? date('h:i A', strtotime($business_hours->$k->start_time)) : '00:00' }}</span>
                                                                                - <span
                                                                                    class="days_{{ $k }}_end">{{ !empty($business_hours->$k->end_time) && isset($business_hours->$k->end_time) ? date('h:i A', strtotime($business_hours->$k->end_time)) : '00:00' }}</span>
                                                                            @else
                                                                                {{ __('Closed') }}
                                                                            @endif
                                                                        </span>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>

                                                    </section>
                                                @endif
                                                @if ($order_key == 'contact_info')
                                                    <section class="contact-section" id="contact-div">
                                                        <h2>{{ __('Contact') }}</h2>
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
                                                                                                    <li id="contact_{{ $loop->parent->index + 1 }}">
                                                                                                        @if ($key1 == 'Address')
                                                                                                            @foreach ($val1 as $key2 => $val2)
                                                                                                                @if ($key2 == 'Address_url')
                                                                                                                    @php                                        $href = $val2; @endphp
                                                                                                                @endif
                                                                                                            @endforeach
                                                                                                            <a href="{{ $href }}">
                                                                                                                <span>
                                                                                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
                                                                                                                        class="img-fluid">
                                                                                                                </span>
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
                                                                                                                    <span>
                                                                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
                                                                                                                            class="img-fluid">
                                                                                                                    </span>
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
                                                    </section>
                                                @endif
                                                @if ($order_key == 'appointment')
                                                        <section class="make-appointment" id="appointment-div">
                                                            <div class="section-title text-center">
                                                                <h2>{{ __('Make') }} <br>{{ __('an appointment') }} </h2>
                                                            </div>
                                                            <div class="appointment-form">
                                                                <div class="form-group">
                                                                    <label>{{ __('Date:') }}</label>
                                                                    <input type="text" name="date" class="datepicker_min"
                                                                        placeholder="{{ __('Pick a Date') }}">
                                                                    <span class="text-danger text-center h6 span-error-date"></span>
                                                                </div>
                                                                <div class="form-group">
                                                                    <label class="primary-text">{{ __('Hour:') }}</label>
                                                                    <div class="cust-checkbox">
                                                                        <div class="row row-gap" id="inputrow_appointment_preview">
                                                                            @php            $radiocount = 1; @endphp
                                                                            @if (!is_null($appoinment_hours))
                                                                                                        @foreach ($appoinment_hours as $k => $hour)
                                                                                                                                    <div class="col-sm-6 col-12" id="{{ 'appointment_' . $appointment_no }}">
                                                                                                                                        <div class="form-check">
                                                                                                                                            <input type="radio" class="app_time" id="radio-{{ $radiocount }}"
                                                                                                                                                data-id="@if (!empty($hour->start)) {{ $hour->start }} @else 00:00 @endif-@if (!empty($hour->end)) {{ $hour->end }} @else 00:00 @endif"
                                                                                                                                                name="time">
                                                                                                                                            <label for="radio-{{ $radiocount }}"><span
                                                                                                                                                    id="appoinment_start_{{ $appointment_no }}_preview">
                                                                                                                                                    @if (!empty($hour->start))
                                                                                                                                                        {{ $hour->start }}
                                                                                                                                                    @else
                                                                                                                                                        00:00
                                                                                                                                                    @endif
                                                                                                                                                </span> - <span
                                                                                                                                                    id="appoinment_end_{{ $appointment_no }}_preview">
                                                                                                                                                    @if (!empty($hour->end))
                                                                                                                                                        {{ $hour->end }}
                                                                                                                                                    @else
                                                                                                                                                        00:00
                                                                                                                                                    @endif
                                                                                                                                                </span></label>
                                                                                                                                        </div>
                                                                                                                                    </div>
                                                                                                                                    @php
                                                                                                                                        $radiocount++;
                                                                                                                                        $appointment_no++;
                                                                                                                                    @endphp
                                                                                                        @endforeach
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                    <span class="text-danger text-center h6 span-error-time"></span>
                                                                </div>
                                                                <div class="text-center">
                                                                    <button
                                                                        class="btn hover-secondary appointment-modal-toggle">{{ __('Make an appointment') }}</button>
                                                                </div>
                                                            </div>
                                                        </section>
                                                @endif
                                                @if ($order_key == 'testimonials')
                                                            <section class="testimonial-section text-center" id="testimonials-div">
                                                                <div class="section-title">
                                                                    <h2>{{ __('Testimonials') }}</h2>
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
                                                                                                    <div class="testimonial-card">
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
                                                    <section class="social" id="social-div">
                                                        <div class="section-title text-center">
                                                            <h2>{{ __('Social') }}</h2>
                                                        </div>
                                                        <ul class="social-list" id="inputrow_socials_preview">
                                                            @if (!is_null($social_content) && !is_null($sociallinks))
                                                                        @foreach ($social_content as $social_key => $social_val)
                                                                                    @foreach ($social_val as $social_key1 => $social_val1)
                                                                                                @if ($social_key1 != 'id')
                                                                                                    <li id="socials_{{ $loop->parent->index + 1 }}">
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
                                                                                                        <a href="{{ $social_links }}" target="_blank"
                                                                                                            id="{{ 'social_link_' . $social_no . '_href_preview' }}">
                                                                                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/icon/social/' . strtolower($social_key1) . '.svg') }}"
                                                                                                                alt="social" class="img-fluid">
                                                                                                        </a>
                                                                                                    </li>
                                                                                                @endif
                                                                                                @php
                                                                                                    $social_no++;
                                                                                                @endphp
                                                                                    @endforeach
                                                                        @endforeach
                                                            @endif
                                                        </ul>
                                                    </section>
                                                @endif
                                                @if ($order_key == 'more')
                                                    <section class="more-info">
                                                        <div class="section-title text-center">
                                                            <h2>{{ __('More') }}</h2>
                                                        </div>
                                                        <ul class="btn-list">
                                                            <li>
                                                                <a href="{{ route('vcard.save', $store->slug) }}" class="btn">{{ __('Save Card') }}
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="33" height="32"
                                                                        viewBox="0 0 33 32" fill="none">
                                                                        <path opacity="0.4"
                                                                            d="M8.5 13.3333C5.83333 13.3333 4.5 14.6666 4.5 17.3333V23.9999C4.5 26.6666 5.83333 27.9999 8.5 27.9999H24.5C27.1667 27.9999 28.5 26.6666 28.5 23.9999V17.3333C28.5 14.6666 27.1667 13.3333 24.5 13.3333H8.5Z"
                                                                            fill="#242424"></path>
                                                                        <path
                                                                            d="M21.2067 16.6266C20.816 16.236 20.1826 16.236 19.792 16.6266L17.4987 18.92V4C17.4987 3.448 17.0507 3 16.4987 3C15.9467 3 15.4987 3.448 15.4987 4V18.9187L13.2054 16.6253C12.8147 16.2347 12.1813 16.2347 11.7907 16.6253C11.4 17.016 11.4 17.6494 11.7907 18.04L15.7907 22.04C15.8827 22.132 15.9932 22.2054 16.1159 22.256C16.2385 22.3067 16.368 22.3333 16.4987 22.3333C16.6294 22.3333 16.7585 22.3067 16.8812 22.256C17.0038 22.2054 17.1147 22.132 17.2067 22.04L21.2067 18.04C21.5974 17.6507 21.5974 17.016 21.2067 16.6266Z"
                                                                            fill="#242424"></path>
                                                                    </svg></a>
                                                            </li>
                                                            <li>
                                                                <a href="javascript:void(0)" class="btn share-modal-toggle">{{ __('Share Card') }}
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="33" height="32"
                                                                        viewBox="0 0 33 32" fill="none">
                                                                        <path opacity="0.4"
                                                                            d="M10.972 15.0919C10.6054 15.0919 10.2519 14.8893 10.0759 14.5386C9.82788 14.0439 10.0295 13.444 10.5241 13.1973L21.0508 7.9346C21.5455 7.6866 22.144 7.88929 22.392 8.38129C22.64 8.87596 22.4387 9.47594 21.944 9.7226L11.4173 14.9853C11.2733 15.0573 11.1214 15.0919 10.972 15.0919Z"
                                                                            fill="#242424"></path>
                                                                        <path opacity="0.4"
                                                                            d="M22.2335 24.5413C22.0841 24.5413 21.9308 24.5066 21.7881 24.436L9.78684 18.436C9.29217 18.1893 9.09193 17.588 9.3386 17.0947C9.58793 16.6027 10.1867 16.4 10.6801 16.648L22.6814 22.648C23.176 22.8946 23.376 23.496 23.1293 23.9893C22.9533 24.3373 22.6001 24.5413 22.2335 24.5413Z"
                                                                            fill="#242424"></path>
                                                                        <path
                                                                            d="M8.5 20C10.7091 20 12.5 18.2091 12.5 16C12.5 13.7909 10.7091 12 8.5 12C6.29086 12 4.5 13.7909 4.5 16C4.5 18.2091 6.29086 20 8.5 20Z"
                                                                            fill="#242424"></path>
                                                                        <path
                                                                            d="M24.5 12C26.7091 12 28.5 10.2091 28.5 8C28.5 5.79086 26.7091 4 24.5 4C22.2909 4 20.5 5.79086 20.5 8C20.5 10.2091 22.2909 12 24.5 12Z"
                                                                            fill="#242424"></path>
                                                                        <path
                                                                            d="M24.5 28C26.7091 28 28.5 26.2091 28.5 24C28.5 21.7909 26.7091 20 24.5 20C22.2909 20 20.5 21.7909 20.5 24C20.5 26.2091 22.2909 28 24.5 28Z"
                                                                            fill="#242424"></path>
                                                                    </svg></a>
                                                            </li>
                                                            <li>
                                                                <a href="javascript:void(0)"
                                                                    class="btn make-contact-modal-toggle">{{ __('Contact') }}
                                                                    <svg xmlns="http://www.w3.org/2000/svg" width="33" height="32"
                                                                        viewBox="0 0 33 32" fill="none">
                                                                        <path opacity="0.4"
                                                                            d="M20.3251 19.7253L18.8384 21.9453C15.1051 20.3853 12.1185 17.3867 10.5572 13.644L12.772 12.1706C13.9827 11.3653 14.3519 9.75333 13.6106 8.50133L11.5266 4.98265C11.0932 4.25065 10.2453 3.87332 9.41068 4.03998L9.3879 4.04397C5.97456 4.72664 3.79042 8.15733 4.71309 11.5133C6.89442 19.4493 13.0799 25.616 20.9986 27.7893C24.3546 28.7107 27.7798 26.524 28.4624 23.112C28.6291 22.28 28.2533 21.4347 27.5253 21L24.0012 18.8987C22.7492 18.1533 21.1357 18.516 20.3251 19.7253Z"
                                                                            fill="#242424"></path>
                                                                        <path
                                                                            d="M23.1666 14.3333C22.6146 14.3333 22.1666 13.8853 22.1666 13.3333C22.1666 11.6786 20.82 10.3333 19.1666 10.3333C18.6146 10.3333 18.1666 9.88525 18.1666 9.33325C18.1666 8.78125 18.6146 8.33325 19.1666 8.33325C21.9226 8.33325 24.1666 10.5759 24.1666 13.3333C24.1666 13.8853 23.7186 14.3333 23.1666 14.3333ZM28.1666 13.3333C28.1666 8.37059 24.1293 4.33325 19.1666 4.33325C18.6146 4.33325 18.1666 4.78125 18.1666 5.33325C18.1666 5.88525 18.6146 6.33325 19.1666 6.33325C23.0266 6.33325 26.1666 9.47325 26.1666 13.3333C26.1666 13.8853 26.6146 14.3333 27.1666 14.3333C27.7186 14.3333 28.1666 13.8853 28.1666 13.3333Z"
                                                                            fill="#242424"></path>
                                                                    </svg></a>
                                                            </li>
                                                        </ul>
                                                    </section>
                                                @endif
                                                @if ($order_key == 'custom_html')
                                                    <section class="card-footer custom_html_text">
                                                        <div class="greetings-desk">
                                                            <div class="user-text-avatar  text-white" id="{{ $stringid . '_chtml' }}_preview">
                                                                {!! stripslashes($custom_html) !!}
                                                            </div>
                                                        </div>

                                                    </section>
                                                @endif
                                                @if ($order_key == 'payment')
                                                    <section class="card-payment-section" id="payment-section">
                                                        <div class="section-title text-center">
                                                            <h2 class="">{{ __('Payment') }}</h2>
                                                        </div>
                                                        @if (!is_null($cardPayment_content) && !is_null($cardPayment) && !empty($cardPayment_content))
                                                            <div
                                                                class="payment-list {{ $cardPayment->payment_status == 'Paid' ? 'disablePayment' : '' }}">
                                                                @if ($cardPayment_content->stripe->status == 'on')
                                                                    <div class="payment-div">
                                                                        <a href="{{ route('card.pay.with.stripe', $store->id) }}">
                                                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/img/payments/stripe.png') }}"
                                                                                alt="social" class="img-fluid">
                                                                            {{ __('Stripe') }}
                                                                        </a>
                                                                    </div>
                                                                @endif

                                                                @if ($cardPayment_content->paypal->status == 'on')
                                                                    <div class="payment-div">
                                                                        <a href="{{ route('card.pay.with.paypal', $store->id) }}">
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
                            <div class="copy-right is_branding_enable" id="is_branding_enabled">
                                <p id="{{ $stringid . '_branding' }}_preview">{{ $brandingText }}</p>
                            </div>
                        @endif


                    </div>
                </div>
            </div>
            <img src="{{ isset($qr_detail->image) ? get_file($qr_detail->image) : '' }}" id="image-buffers"
                style="display: none">
        </main>
        <div id="previewImage"> </div>
        <a id="download" href="#" class="font-lg download mr-3 text-white">
            <i class="fas fa-download"></i>
        </a>
        <!-- Share card popup -->
        <div class="theme-modal share-card">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="btn-close share-modal-toggle">
                            <svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 45 45"
                                fill="none">
                                <path opacity="0.4"
                                    d="M37.2376 24.5509H7.76834C6.74981 24.5509 5.92651 23.7258 5.92651 22.7091C5.92651 21.6924 6.74981 20.8672 7.76834 20.8672H37.2376C38.2561 20.8672 39.0794 21.6924 39.0794 22.7091C39.0794 23.7258 38.2561 24.5509 37.2376 24.5509Z"
                                    fill="#F9D254" />
                                <path
                                    d="M15.1357 31.9183C14.6642 31.9183 14.1926 31.7378 13.8335 31.3787L6.46614 24.0114C5.74599 23.2912 5.74599 22.1271 6.46614 21.4069L13.8335 14.0396C14.5536 13.3194 15.7178 13.3194 16.4379 14.0396C17.1581 14.7598 17.1581 15.9239 16.4379 16.6441L10.3728 22.7091L16.4379 28.7742C17.1581 29.4944 17.1581 30.6585 16.4379 31.3787C16.0788 31.7378 15.6072 31.9183 15.1357 31.9183Z"
                                    fill="#F9D254" />
                            </svg>
                        </button>
                        <h5 class="modal-title">{{ __('Share This Card') }}</h5>
                    </div>
                    <div class="modal-body">
                        <div class="qrcode-wrapper">
                            <div class="shareqrcode"></div>
                        </div>
                        <p>{{ __('Point your camera at the QR code,') }} <br> <span class="qr-link text-wrap"></span>
                        </p>

                        <p>{{ __('Or check my social channels') }}</p>
                        <ul class="social-list modal-share">
                            <li>
                                <a href="https://www.facebook.com/sharer.php?u={{ urlencode($url_link) }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="55" height="55" viewBox="0 0 55 55"
                                        fill="none">
                                        <g clip-path="url(#clip0_5_272)">
                                            <path
                                                d="M54.9802 27.7657C54.9802 12.7763 42.8289 0.625 27.8395 0.625C12.8501 0.625 0.698853 12.7763 0.698853 27.7657C0.698853 41.3122 10.6238 52.5406 23.5988 54.5767V35.611H16.7076V27.7657H23.5988V21.7863C23.5988 14.9841 27.6508 11.2268 33.8503 11.2268C36.8188 11.2268 39.9256 11.7569 39.9256 11.7569V18.4361H36.5034C33.132 18.4361 32.0803 20.5284 32.0803 22.6768V27.7657H39.6076L38.4043 35.611H32.0803V54.5767C45.0553 52.5406 54.9802 41.3122 54.9802 27.7657Z"
                                                fill="#1877F2" />
                                            <path
                                                d="M38.4043 35.6113L39.6076 27.7659H32.0803V22.677C32.0803 20.5307 33.132 18.4363 36.5034 18.4363H39.9257V11.7571C39.9257 11.7571 36.8198 11.2271 33.8503 11.2271C27.6509 11.2271 23.5988 14.9843 23.5988 21.7865V27.7659H16.7076V35.6113H23.5988V54.5769C26.4089 55.0165 29.2702 55.0165 32.0803 54.5769V35.6113H38.4043Z"
                                                fill="white" />
                                        </g>
                                        <defs>
                                            <clipPath id="clip0_5_272">
                                                <rect width="54.2814" height="54.2814" fill="white"
                                                    transform="translate(0.698853 0.625)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </a>
                            </li>
                            <li>
                                <a href="https://twitter.com/share?url={{ urlencode($url_link) }}&text=">
                                    <svg width="682" height="682" viewBox="0 0 682 682" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M308.826 2.95819L308.821 2.95862C202.611 13.978 109.557 71.724 53.0033 161.731L51.9077 161.043L53.0032 161.732C-27.1728 289.296 -12.0346 458.686 89.3705 568.984C145.799 630.325 215.615 666.83 299.545 678.782L299.545 678.782C307.357 679.898 324.32 680.433 341.464 680.4C358.603 680.367 375.708 679.765 383.795 678.647C466.386 667.228 536.201 630.859 592.497 569.783L592.498 569.782C638.809 519.623 668.422 455.377 678.782 383.12L678.782 383.119C679.898 375.374 680.467 358.41 680.467 341.333C680.467 324.256 679.898 307.292 678.782 299.547L678.781 299.544C670.944 244.286 653.42 197.433 624.35 153.629L624.346 153.624C612.139 135.047 599.804 119.924 582.535 102.523L582.533 102.521C529.565 48.8887 463.997 15.837 388.448 4.6839C380.348 3.49878 364.498 2.63287 348.584 2.2829C332.666 1.93292 316.853 2.10278 308.826 2.95819ZM313.502 212.883L313.504 212.885C327.302 233.016 339.934 251.314 349.165 264.594C353.781 271.234 357.545 276.618 360.179 280.349C361.497 282.216 362.529 283.664 363.243 284.648C363.279 284.698 363.315 284.747 363.349 284.795C363.407 284.733 363.466 284.668 363.528 284.601C364.409 283.644 365.691 282.22 367.333 280.372C370.616 276.679 375.321 271.321 381.103 264.697C392.667 251.45 408.532 233.152 425.932 213.019L488.996 139.689L489.431 139.183L490.098 139.167L507.165 138.767L507.172 138.767L507.18 138.767C512.19 138.7 516.428 138.733 519.394 138.885C520.865 138.961 522.08 139.069 522.933 139.221C523.338 139.294 523.779 139.396 524.142 139.564C524.312 139.643 524.614 139.803 524.861 140.105C525.162 140.471 525.351 141.027 525.172 141.624C525.127 141.779 525.07 141.89 525.052 141.926L525.05 141.929C525.013 142.001 524.975 142.065 524.945 142.112C524.923 142.147 524.899 142.183 524.875 142.22C524.832 142.284 524.785 142.35 524.739 142.415C524.589 142.622 524.383 142.892 524.131 143.211C523.624 143.855 522.892 144.754 521.966 145.873C520.112 148.114 517.452 151.27 514.193 155.105C507.673 162.775 498.737 173.178 489.004 184.445C480.079 194.836 468.41 208.421 456.435 222.362C442.287 238.831 427.714 255.796 416.738 268.577L416.736 268.58L380.557 310.505L450.703 412.751C489.647 469.432 525.363 521.408 530.151 528.057L530.16 528.07L530.169 528.083L538.702 540.483L540.32 542.833H537.467H478.933H420.4H419.61L419.163 542.182L366.097 464.849C366.097 464.849 366.096 464.849 366.096 464.849C351.498 443.584 338.134 424.254 328.37 410.258C323.487 403.26 319.507 397.598 316.725 393.696C315.332 391.744 314.244 390.239 313.494 389.229C313.451 389.171 313.41 389.115 313.369 389.061C313.279 389.161 313.182 389.267 313.081 389.379C312.155 390.402 310.808 391.915 309.085 393.869C305.641 397.775 300.707 403.426 294.649 410.4C282.535 424.348 265.937 443.579 247.805 464.71L247.804 464.711L181.671 541.645L181.235 542.151L180.567 542.166L162.967 542.566L145.367 542.966L142.001 543.043L144.196 540.489L220.729 451.422L220.73 451.422L296.505 363.369L292.912 358.205C287.879 351.332 150.084 150.564 146.077 144.419L146.07 144.409L146.064 144.399L143.797 140.799L142.342 138.488L145.073 138.5L203.473 138.767L203.477 138.767L262.01 139.167L262.792 139.172L263.236 139.816L313.502 212.883Z"
                                            fill="black" stroke="black" stroke-width="3" />
                                        <path
                                            d="M315.762 340.74L315.763 340.741L437.723 515.179L463.081 515.567C463.082 515.567 463.084 515.567 463.085 515.567C470.273 515.633 476.82 515.6 481.563 515.484C483.756 515.43 485.548 515.359 486.833 515.274C486.493 514.766 486.084 514.159 485.607 513.455C483.981 511.058 481.581 507.56 478.49 503.079C472.309 494.117 463.368 481.231 452.335 465.373C430.27 433.658 399.837 390.059 366.371 342.193L245.077 168.822L218.918 168.433L218.911 168.433L195.034 168.196L315.762 340.74Z"
                                            fill="black" stroke="black" stroke-width="3" />
                                    </svg>

                                </a>
                            </li>
                            <li>
                                <a href="https://www.linkedin.com/shareArticle?url={{ urlencode($url_link) }}&title="
                                    class="linkdin-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="55" height="55" viewBox="0 0 55 55"
                                        fill="none">
                                        <g clip-path="url(#clip0_5_274)">
                                            <path
                                                d="M50.3735 0.625H4.10614C3.04341 0.625 2.02421 1.04717 1.27275 1.79863C0.521289 2.55009 0.0991211 3.56929 0.0991211 4.63202V50.8994C0.0991211 51.9621 0.521289 52.9813 1.27275 53.7328C2.02421 54.4842 3.04341 54.9064 4.10614 54.9064H50.3735C51.4362 54.9064 52.4554 54.4842 53.2069 53.7328C53.9583 52.9813 54.3805 51.9621 54.3805 50.8994V4.63202C54.3805 3.56929 53.9583 2.55009 53.2069 1.79863C52.4554 1.04717 51.4362 0.625 50.3735 0.625ZM16.278 46.866H8.11694V20.9428H16.278V46.866ZM12.1918 17.3505C11.2661 17.3452 10.3626 17.0659 9.5955 16.5477C8.82837 16.0296 8.23192 15.2957 7.88144 14.4389C7.53095 13.5821 7.44214 12.6406 7.62621 11.7334C7.81028 10.8261 8.25897 9.99369 8.91567 9.34119C9.57237 8.68869 10.4076 8.24535 11.3161 8.06711C12.2245 7.88888 13.1654 7.98373 14.0199 8.33971C14.8745 8.69569 15.6045 9.29683 16.1177 10.0673C16.6309 10.8377 16.9045 11.7429 16.9037 12.6687C16.9125 13.2885 16.7963 13.9037 16.5622 14.4776C16.3282 15.0516 15.9809 15.5725 15.5412 16.0094C15.1015 16.4463 14.5783 16.7902 14.0029 17.0206C13.4275 17.251 12.8115 17.3632 12.1918 17.3505ZM46.3589 46.8886H38.2016V32.7264C38.2016 28.5498 36.4262 27.2606 34.1343 27.2606C31.7143 27.2606 29.3394 29.085 29.3394 32.832V46.8886H21.1784V20.9617H29.0266V24.554H29.1321C29.92 22.9595 32.6793 20.2342 36.8898 20.2342C41.4434 20.2342 46.3627 22.9369 46.3627 30.8529L46.3589 46.8886Z"
                                                fill="#0A66C2" />
                                        </g>
                                        <defs>
                                            <clipPath id="clip0_5_274">
                                                <rect width="54.2814" height="54.2814" fill="white"
                                                    transform="translate(0.0991211 0.625)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </a>
                            </li>
                            <li>
                                @php
                                    $whatsapp_link = url('https://wa.me/?text=' . urlencode($url_link));
                                @endphp
                                <a href="{{ $whatsapp_link }}">
                                    <svg width="30" height="30" viewBox="0 0 30 30" fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g clip-path="url(#clip0_213_9336)">
                                            <path
                                                d="M15 30C23.2843 30 30 23.2843 30 15C30 6.71573 23.2843 0 15 0C6.71573 0 0 6.71573 0 15C0 23.2843 6.71573 30 15 30Z"
                                                fill="#29A71A" />
                                            <path
                                                d="M21.6137 8.38606C20.0531 6.80986 17.9806 5.84323 15.7701 5.66053C13.5595 5.47782 11.3564 6.09107 9.55818 7.38965C7.75996 8.68824 6.48496 10.5867 5.96319 12.7425C5.44141 14.8984 5.70719 17.1697 6.7126 19.1469L5.72567 23.9383C5.71543 23.986 5.71514 24.0353 5.72481 24.0831C5.73449 24.1309 5.75393 24.1762 5.78192 24.2162C5.82291 24.2768 5.88143 24.3235 5.94965 24.35C6.01788 24.3765 6.09256 24.3815 6.16373 24.3645L10.8598 23.2514C12.8313 24.2314 15.0867 24.48 17.2244 23.9532C19.3621 23.4264 21.2436 22.1583 22.5341 20.3744C23.8246 18.5906 24.4404 16.4067 24.2718 14.2115C24.1033 12.0163 23.1614 9.95202 21.6137 8.38606ZM20.1495 20.0724C19.0697 21.1492 17.6793 21.86 16.1741 22.1046C14.669 22.3492 13.125 22.1154 11.7598 21.4361L11.1052 21.1122L8.22623 21.794L8.23476 21.7582L8.83135 18.8605L8.51089 18.2281C7.81333 16.8581 7.56725 15.3025 7.80792 13.7841C8.04858 12.2657 8.76364 10.8624 9.85067 9.77526C11.2165 8.40983 13.0688 7.64277 15.0001 7.64277C16.9314 7.64277 18.7837 8.40983 20.1495 9.77526C20.1612 9.7886 20.1737 9.80113 20.187 9.81276C21.536 11.1817 22.289 13.0284 22.282 14.9503C22.275 16.8722 21.5084 18.7134 20.1495 20.0724Z"
                                                fill="white" />
                                            <path
                                                d="M19.8939 17.9466C19.5411 18.5023 18.9837 19.1824 18.2831 19.3512C17.0559 19.6478 15.1724 19.3614 12.8286 17.1762L12.7996 17.1506C10.7388 15.2398 10.2036 13.6495 10.3331 12.3881C10.4047 11.6722 11.0013 11.0245 11.5042 10.6018C11.5837 10.5339 11.6779 10.4856 11.7794 10.4607C11.881 10.4358 11.9869 10.435 12.0887 10.4583C12.1906 10.4817 12.2856 10.5286 12.3661 10.5952C12.4467 10.6618 12.5105 10.7464 12.5525 10.8421L13.311 12.5466C13.3603 12.6572 13.3785 12.779 13.3638 12.8991C13.3491 13.0193 13.302 13.1331 13.2275 13.2285L12.8439 13.7262C12.7616 13.829 12.712 13.954 12.7014 14.0852C12.6907 14.2165 12.7196 14.3479 12.7843 14.4626C12.9991 14.8393 13.5138 15.3932 14.0849 15.9063C14.7258 16.4858 15.4366 17.016 15.8866 17.1966C16.007 17.2458 16.1394 17.2578 16.2666 17.2311C16.3939 17.2044 16.5103 17.1401 16.6008 17.0466L17.0456 16.5983C17.1315 16.5137 17.2382 16.4533 17.355 16.4234C17.4718 16.3934 17.5944 16.395 17.7104 16.4279L19.5121 16.9393C19.6115 16.9697 19.7026 17.0226 19.7785 17.0936C19.8543 17.1647 19.9129 17.2522 19.9497 17.3494C19.9866 17.4467 20.0007 17.551 19.991 17.6545C19.9814 17.758 19.9482 17.8579 19.8939 17.9466Z"
                                                fill="white" />
                                        </g>
                                        <defs>
                                            <clipPath id="clip0_213_9336">
                                                <rect width="30" height="30" fill="white" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </a>
                            </li>
                            <li>
                                <a href="" id="copylink" data-bs-toggle="tooltip" data-bs-placement="bottom" title=""
                                    class="copy-icon insta-icon">
                                    <svg id="Layer_1" enable-background="new 0 0 24 24" height="512" viewBox="0 0 24 24"
                                        width="512" xmlns="http://www.w3.org/2000/svg">
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
        <!-- appointment popup -->
        <div class="theme-modal appointment-modal">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="btn-close appointment-modal-toggle close-search1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 45 45"
                                fill="none">
                                <path opacity="0.4"
                                    d="M37.2376 24.5509H7.76834C6.74981 24.5509 5.92651 23.7258 5.92651 22.7091C5.92651 21.6924 6.74981 20.8672 7.76834 20.8672H37.2376C38.2561 20.8672 39.0794 21.6924 39.0794 22.7091C39.0794 23.7258 38.2561 24.5509 37.2376 24.5509Z"
                                    fill="#F9D254" />
                                <path
                                    d="M15.1357 31.9183C14.6642 31.9183 14.1926 31.7378 13.8335 31.3787L6.46614 24.0114C5.74599 23.2912 5.74599 22.1271 6.46614 21.4069L13.8335 14.0396C14.5536 13.3194 15.7178 13.3194 16.4379 14.0396C17.1581 14.7598 17.1581 15.9239 16.4379 16.6441L10.3728 22.7091L16.4379 28.7742C17.1581 29.4944 17.1581 30.6585 16.4379 31.3787C16.0788 31.7378 15.6072 31.9183 15.1357 31.9183Z"
                                    fill="#F9D254" />
                            </svg>
                        </button>
                        <h5 class="modal-title">{{ __('Make Appointment') }}</h5>
                    </div>
                    <form action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>{{ __('Name:') }}</label>
                                <input type="text" name="name" class="app_name"
                                    placeholder="{{ __('Enter your name') }}">
                                <span class="text-danger  h6 span-error-name"></span>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Email:') }}</label>
                                <input type="email" name="email" class="app_email"
                                    placeholder="{{ __('Enter your email') }}">
                                <span class="text-danger  h6 span-error-email"></span>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Phone:') }}</label>
                                <input type="tel" name="phone" class="app_phone"
                                    placeholder="{{ __('Enter your phone no') }}">
                                <span class="text-danger  h6 span-error-phone"></span>
                            </div>

                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary appointment-modal-toggle" type="button">{{ __('Close') }}
                            </button>
                            <button class="btn-secondary" id="makeappointment"
                                type="button">{{ __('Make Appointment') }} </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <!-- Make Contact Popup -->
        <div class="theme-modal contact-modal">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close-search2 btn-close  make-contact-modal-toggle">
                            <svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 45 45"
                                fill="none">
                                <path opacity="0.4"
                                    d="M37.2376 24.5509H7.76834C6.74981 24.5509 5.92651 23.7258 5.92651 22.7091C5.92651 21.6924 6.74981 20.8672 7.76834 20.8672H37.2376C38.2561 20.8672 39.0794 21.6924 39.0794 22.7091C39.0794 23.7258 38.2561 24.5509 37.2376 24.5509Z"
                                    fill="#F9D254" />
                                <path
                                    d="M15.1357 31.9183C14.6642 31.9183 14.1926 31.7378 13.8335 31.3787L6.46614 24.0114C5.74599 23.2912 5.74599 22.1271 6.46614 21.4069L13.8335 14.0396C14.5536 13.3194 15.7178 13.3194 16.4379 14.0396C17.1581 14.7598 17.1581 15.9239 16.4379 16.6441L10.3728 22.7091L16.4379 28.7742C17.1581 29.4944 17.1581 30.6585 16.4379 31.3787C16.0788 31.7378 15.6072 31.9183 15.1357 31.9183Z"
                                    fill="#F9D254" />
                            </svg>
                        </button>
                        <h5 class="modal-title">{{ __('Make Contact') }}</h5>
                    </div>
                    <form action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>{{ __('Name:') }}</label>
                                <input type="text" name="name" class="contact_name"
                                    placeholder="{{ __('Enter your name') }}">
                                <span class="text-danger  h6 span-error-contactname"></span>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Email:') }}</label>
                                <input type="email" name="email" class="contact_email"
                                    placeholder="{{ __('Enter your email') }}">
                                <span class="text-danger  h6 span-error-contactemail"></span>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Phone:') }}</label>
                                <input type="tel" name="phone" class="contact_phone"
                                    placeholder="{{ __('Enter your phone no') }}">
                                <span class="text-danger  h6 span-error-contactphone"></span>
                            </div>
                            <div class="form-group">
                                <label>{{ __('Message:') }}</label>
                                <textarea name="message" class="contact_message" id="" cols="30" rows="5"></textarea>
                                <span class="text-danger  h6 span-error-contactmessage"></span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary make-contact-modal-toggle"
                                type="button">{{ __('Close') }}</button>
                            <button class="btn-secondary" id="makecontact"
                                type="button">{{ __('Make Contact') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        {{-- Password modal --}}
        <div class="theme-modal" id="passwordmodel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">

                        <h5 class="modal-title">{{ __('Password') }}</h5>
                    </div>
                    <form action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>{{ __('Password:') }}</label>
                                <input type="password" name="Password" class="password_val"
                                    placeholder="{{ __('Enter password') }}">
                                <span class="text-danger h6 span-error-password"></span>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary password-submit" type="button">{{ __('Submit') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        {{-- End password modal --}}
        {{-- Image modal --}}
        <div class="theme-modal" id="gallerymodel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="btn-close close-model">
                            <svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 45 45"
                                fill="none">
                                <path opacity="0.4"
                                    d="M37.2376 24.5509H7.76834C6.74981 24.5509 5.92651 23.7258 5.92651 22.7091C5.92651 21.6924 6.74981 20.8672 7.76834 20.8672H37.2376C38.2561 20.8672 39.0794 21.6924 39.0794 22.7091C39.0794 23.7258 38.2561 24.5509 37.2376 24.5509Z"
                                    fill="#F9D254" />
                                <path
                                    d="M15.1357 31.9183C14.6642 31.9183 14.1926 31.7378 13.8335 31.3787L6.46614 24.0114C5.74599 23.2912 5.74599 22.1271 6.46614 21.4069L13.8335 14.0396C14.5536 13.3194 15.7178 13.3194 16.4379 14.0396C17.1581 14.7598 17.1581 15.9239 16.4379 16.6441L10.3728 22.7091L16.4379 28.7742C17.1581 29.4944 17.1581 30.6585 16.4379 31.3787C16.0788 31.7378 15.6072 31.9183 15.1357 31.9183Z"
                                    fill="#F9D254" />
                            </svg>
                        </button>
                        <h5 class="modal-title">{{ __('Gallary') }}</h5>
                    </div>
                    <form action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>{{ __('Image preview:') }}</label>
                                <img src="" class="imagepreview" style="width: 500px; height: 300px;">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary close-model" type="button">{{ __('Close') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        {{-- End image modal --}}
        {{-- Video modal --}}
        <div class="theme-modal" id="videomodel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="btn-close close-model1">
                            <svg xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 45 45"
                                fill="none">
                                <path opacity="0.4"
                                    d="M37.2376 24.5509H7.76834C6.74981 24.5509 5.92651 23.7258 5.92651 22.7091C5.92651 21.6924 6.74981 20.8672 7.76834 20.8672H37.2376C38.2561 20.8672 39.0794 21.6924 39.0794 22.7091C39.0794 23.7258 38.2561 24.5509 37.2376 24.5509Z"
                                    fill="#F9D254" />
                                <path
                                    d="M15.1357 31.9183C14.6642 31.9183 14.1926 31.7378 13.8335 31.3787L6.46614 24.0114C5.74599 23.2912 5.74599 22.1271 6.46614 21.4069L13.8335 14.0396C14.5536 13.3194 15.7178 13.3194 16.4379 14.0396C17.1581 14.7598 17.1581 15.9239 16.4379 16.6441L10.3728 22.7091L16.4379 28.7742C17.1581 29.4944 17.1581 30.6585 16.4379 31.3787C16.0788 31.7378 15.6072 31.9183 15.1357 31.9183Z"
                                    fill="#F9D254" />
                            </svg>
                        </button>
                        <h5 class="modal-title">{{ __('Gallary') }}</h5>
                    </div>
                    <form action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label>{{ __('Video preview:') }}</label>
                                <iframe width="100%" height="360" class="videopreview" src="" frameborder="0"
                                    allowfullscreen autoplay></iframe>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn-secondary close-model1" type="button">{{ __('Close') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        {{-- End video modal --}}
        {{-- All Model end here --}}
    </div>
    <script
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/js/jquery.min.js') }}"></script>
    <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/js/slick.min.js') }}"
        defer="defer"></script>
    @if ($SITE_RTL == 'on')
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/js/rtl-custom.js') }}"
            defer="defer"></script>
    @else
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme3/js/custom.js') }}"
            defer="defer"></script>
    @endif
    @include('marketing::layouts.theme_script')
</body>

</html>