<!DOCTYPE html>
<html dir="{{ $SITE_RTL == 'on' ? 'rtl' : '' }}">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $business->name }}</title>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
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
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/libs/@fortawesome/fontawesome-free/css/all.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/fonts/stylesheet.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/css/emojionearea.min.css') }}">
    <link rel="stylesheet"
        href="{{ asset('packages/workdo/Marketing/src/resources/assets/assets/css/plugins/animate.min.css') }}" />

    @if (isset($is_slug))
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/modal/bootstrap.min.css') }}">
    @endif

    @if ($SITE_RTL == 'on')
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/css/rtl-main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/css/rtl-responsive.css') }}">
    @else
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/css/main-style.css') }}">
        <link rel="stylesheet"
            href="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/css/responsive.css') }}">
    @endif



    @if (
            $googleFonts != 'Default' && isset($googleFonts) &&
            $googleFonts != null
        )
            <style>
                @import url('{{ Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)['link'] }}');

                :root {
                    --Strawford: '{{ strtok(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)["fontfamily"], ",") }}", {{ substr(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)["fontfamily"], strpos(Workdo\Marketing\app\Models\Store::getvalueoffont($googleFonts)["fontfamily"], ',
                                                    ') + 1) }};

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

    @foreach ($pixelScript as $script)
        <?= $script ?>
    @endforeach
</head>

<body class="tech-card-body">
    <div id="boxes">
        <div class="{{ $themeOne['theme14'][$store->theme_color]['theme_name'] ?? " theme14-v1" }}" id="view_theme3">
            <div class="home-wrapper">
                <section class="home-banner-section">
                    <img class="home-banner" id="banner_preview"
                        src="{{ isset($business->banner) && !empty($business->banner) ? get_file($business->banner) : asset('packages/workdo/Marketing/src/resources/assets/custom/img/placeholder-image.jpg') }}"
                        id="banner_preview" alt="fs">
                </section>
                <section class="client-info-section padding-top padding-bottom">
                    <div class="container">
                        <div class="client-intro">
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
                            <section class="client-info-section">
                                <div class="container">
                                    <div class="client-contact">
                                        <p id="{{ $stringid . '_desc' }}_preview">{{ $business->description }}</p>
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'contact_info')
                            <section class="client-info-section" id="contact-div">
                                <div class="container">
                                    <div class="client-contact">
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
                                                                                                            <a href="{{ $href }}">
                                                                                                                <div class="contact-svg">
                                                                                                                    <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
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
                                                                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/' . strtolower($key1) . '.svg') }}"
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
                        @if ($order_key == 'appointment')
                            <section class="appointment-section padding-top padding-bottom" id="appointment-div">
                                <div class="container">
                                    <div class="section-title">
                                        <h2>{{ __('Make an') }}{{ __(' appointment') }}</h2>
                                    </div>
                                    <div class="appointment-date">
                                        <div class="date-label">
                                            {{ __('Date') }}
                                        </div>
                                        <input type="text" placeholder="{{ __('Pick a Date') }}" class="text-center datepicker_min">
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
                                                style="{{ $SITE_RTL == 'on' ? 'margin-right: 46px;' : 'margin-left: 46px;' }}"></span>
                                        </div>
                                        <div class="appointment-btn">
                                            <a href="javascript:;" class="btn" tabindex="0">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"
                                                    fill="none">
                                                    <path fill-rule="evenodd" clip-rule="evenodd"
                                                        d="M4 1C4 0.447715 4.44772 0 5 0C5.55228 0 6 0.447715 6 1V2H14V1C14 0.447715 14.4477 0 15 0C15.5523 0 16 0.447715 16 1V2H17C18.6569 2 20 3.34315 20 5V17C20 18.6569 18.6569 20 17 20H3C1.34315 20 0 18.6569 0 17V5C0 3.34315 1.34315 2 3 2H4V1Z"
                                                        fill="#252429" />
                                                    <path class="theme-svg" fill-rule="evenodd" clip-rule="evenodd"
                                                        d="M8 10C7.44772 10 7 10.4477 7 11C7 11.5523 7.44772 12 8 12H15C15.5523 12 16 11.5523 16 11C16 10.4477 15.5523 10 15 10H8ZM5 14C4.44772 14 4 14.4477 4 15C4 15.5523 4.44772 16 5 16H11C11.5523 16 12 15.5523 12 15C12 14.4477 11.5523 14 11 14H5Z"
                                                        fill="#99E2B4" />
                                                </svg>
                                                {{ __('Make an appointment') }}
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'testimonials')
                            <section id="testimonials-div" class="testimonials-section common-border padding-top padding-bottom">
                                <div class="container">
                                    <div class="section-title">
                                        <h2>{{ __('Testimonials') }}</h2>
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
                                                <div class="testimonial-slider" id="inputrow_testimonials_preview">
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
                                                                                class="img-fluid" alt="image">
                                                                        </div>
                                                                        <h5 class="rating-number"><span
                                                                                class="{{ 'stars' . $testimonials_row_no }}">{{ $testi_content->rating }}</span>/5
                                                                        </h5>
                                                                        @php
                                                                            if (!empty($testi_content->rating)) {
                                                                                $rating = (int) $testi_content->rating;
                                                                                $overallrating = $rating;
                                                                            } else {
                                                                                $overallrating = 0;
                                                                            }
                                                                        @endphp
                                                                        <div id="{{ 'stars' . $testimonials_row_no }}_star"
                                                                            class="rating-star star-section">
                                                                            @for ($i = 1; $i <= 5; $i++)
                                                                                @if ($overallrating < $i)
                                                                                    @if (is_float($overallrating) && round($overallrating) == $i)
                                                                                        <i class="star-color fas fa-star-half-alt"></i>
                                                                                    @else
                                                                                        <i class=" fa fa-star"></i>
                                                                                    @endif
                                                                                @else
                                                                                    <i class="star-color fas fa-star"></i>
                                                                                @endif
                                                                            @endfor
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
                        @if ($order_key == 'bussiness_hour')
                            <section class="business-hour-section padding-top padding-bottom" id="business-hours-div">
                                <div class="container">
                                    <div class="section-title">
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
                        @if ($order_key == 'service')
                            <section class="service-section padding-top padding-bottom" id="services-div">
                                <div class="container">
                                    <div class="section-title">
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
                            <section class="product-section  padding-bottom" id="product-div">
                                <div class="container">
                                    <div class="section-title">
                                        <h2>{{ __('Product') }}</h2>
                                    </div>
                                    <div class="service-card-wrapper" id="inputrow_product_preview">
                                        @php            $pr_image_count = 0; @endphp
                                        @foreach ($products_content as $k1 => $content)
                                                        <div class=" service-card" id="product_{{ $product_row_no }}">
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
                            <section class="gallery-section  padding-bottom" id="gallery-div">
                                <div class="container">
                                    <div class="section-title">
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
                            </section>
                        @endif
                        @if ($order_key == 'more')
                            <section class="more-card-section">
                                <div class="container">
                                    <div class="section-title">
                                        <h2>{{ __('More') }}</h2>
                                    </div>
                                    <div class="more-btn">
                                        <a href="{{ route('vcard.save', $business->slug) }}" class="btn" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/folder.svg') }}"
                                                alt="folder" class="img-fluid">
                                            &nbsp;
                                            {{ __('Save Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-card" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/signout.svg') }}"
                                                alt="signout" class="img-fluid">
                                            &nbsp;
                                            {{ __('Share Card') }}
                                        </a>
                                        <a href="javascript:;" class="btn our-contact" tabindex="0">
                                            <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/phone.svg') }}"
                                                alt="signout" class="img-fluid">
                                            &nbsp;
                                            {{ __('Contact') }}
                                        </a>
                                    </div>
                                </div>
                            </section>
                        @endif
                        @if ($order_key == 'social')
                            <section class="social-icons-section padding-top" id="social-div">
                                <div class="container">
                                    <div class="section-title">
                                        <h2>{{ __('Social') }}</h2>
                                        <ul class="social-icon-wrapper" id="inputrow_socials_preview">
                                            @if (!is_null($social_content) && !is_null($sociallinks))
                                                @foreach ($social_content as $social_key => $social_val)
                                                    @foreach ($social_val as $social_key1 => $social_val1)
                                                        @if ($social_key1 != 'id')
                                                            <li>
                                                                <div class="socials_{{ $loop->parent->index + 1 }}"
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
                                                                    <a href="{{ $social_links }}"
                                                                        id="social_link_{{ $loop->parent->index + 1 }}_href_preview"
                                                                        class="social_link_{{ $loop->parent->index + 1 }}_href_preview"
                                                                        target="_blank">
                                                                        <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/social/' . strtolower($social_key1) . '.svg') }}"
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
                        @if ($order_key == 'custom_html')
                            <div id="{{ $stringid . '_chtml' }}_preview" class="custom_html_text">
                                {!! stripslashes($custom_html) !!}
                            </div>
                        @endif
                        @if ($order_key == 'payment')
                            <section class="card-payment-section" id="payment-section">
                                <div class="section-title ">
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
                            {{ $brandingText }}
                        </p>
                    </div>
                @endif


            </div>

            <!--  Appintment popupModal -->
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
                                        placeholder="{{ __('Enter your Phone no.') }}">
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
                                        <svg width="24" height="24" viewBox="0 0 682 682" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path opacity="0.25"
                                                d="M308.826 2.95819L308.821 2.95862C202.611 13.978 109.557 71.724 53.0033 161.731L51.9077 161.043L53.0032 161.732C-27.1728 289.296 -12.0346 458.686 89.3705 568.984C145.799 630.325 215.615 666.83 299.545 678.782L299.545 678.782C307.357 679.898 324.32 680.433 341.464 680.4C358.603 680.367 375.708 679.765 383.795 678.647C466.386 667.228 536.201 630.859 592.497 569.783L592.498 569.782C638.809 519.623 668.422 455.377 678.782 383.12L678.782 383.119C679.898 375.374 680.467 358.41 680.467 341.333C680.467 324.256 679.898 307.292 678.782 299.547L678.781 299.544C670.944 244.286 653.42 197.433 624.35 153.629L624.346 153.624C612.139 135.047 599.804 119.924 582.535 102.523L582.533 102.521C529.565 48.8887 463.997 15.837 388.448 4.6839C380.348 3.49878 364.498 2.63287 348.584 2.2829C332.666 1.93292 316.853 2.10278 308.826 2.95819ZM313.502 212.883L313.504 212.885C327.302 233.016 339.934 251.314 349.165 264.594C353.781 271.234 357.545 276.618 360.179 280.349C361.497 282.216 362.529 283.664 363.243 284.648C363.279 284.698 363.315 284.747 363.349 284.795C363.407 284.733 363.466 284.668 363.528 284.601C364.409 283.644 365.691 282.22 367.333 280.372C370.616 276.679 375.321 271.321 381.103 264.697C392.667 251.45 408.532 233.152 425.932 213.019L488.996 139.689L489.431 139.183L490.098 139.167L507.165 138.767L507.172 138.767L507.18 138.767C512.19 138.7 516.428 138.733 519.394 138.885C520.865 138.961 522.08 139.069 522.933 139.221C523.338 139.294 523.779 139.396 524.142 139.564C524.312 139.643 524.614 139.803 524.861 140.105C525.162 140.471 525.351 141.027 525.172 141.624C525.127 141.779 525.07 141.89 525.052 141.926L525.05 141.929C525.013 142.001 524.975 142.065 524.945 142.112C524.923 142.147 524.899 142.183 524.875 142.22C524.832 142.284 524.785 142.35 524.739 142.415C524.589 142.622 524.383 142.892 524.131 143.211C523.624 143.855 522.892 144.754 521.966 145.873C520.112 148.114 517.452 151.27 514.193 155.105C507.673 162.775 498.737 173.178 489.004 184.445C480.079 194.836 468.41 208.421 456.435 222.362C442.287 238.831 427.714 255.796 416.738 268.577L416.736 268.58L380.557 310.505L450.703 412.751C489.647 469.432 525.363 521.408 530.151 528.057L530.16 528.07L530.169 528.083L538.702 540.483L540.32 542.833H537.467H478.933H420.4H419.61L419.163 542.182L366.097 464.849C366.097 464.849 366.096 464.849 366.096 464.849C351.498 443.584 338.134 424.254 328.37 410.258C323.487 403.26 319.507 397.598 316.725 393.696C315.332 391.744 314.244 390.239 313.494 389.229C313.451 389.171 313.41 389.115 313.369 389.061C313.279 389.161 313.182 389.267 313.081 389.379C312.155 390.402 310.808 391.915 309.085 393.869C305.641 397.775 300.707 403.426 294.649 410.4C282.535 424.348 265.937 443.579 247.805 464.71L247.804 464.711L181.671 541.645L181.235 542.151L180.567 542.166L162.967 542.566L145.367 542.966L142.001 543.043L144.196 540.489L220.729 451.422L220.73 451.422L296.505 363.369L292.912 358.205C287.879 351.332 150.084 150.564 146.077 144.419L146.07 144.409L146.064 144.399L143.797 140.799L142.342 138.488L145.073 138.5L203.473 138.767L203.477 138.767L262.01 139.167L262.792 139.172L263.236 139.816L313.502 212.883Z"
                                                fill="black" stroke="white" stroke-width="3" />
                                            <path
                                                d="M315.762 340.74L315.763 340.741L437.723 515.179L463.081 515.567C463.082 515.567 463.084 515.567 463.085 515.567C470.273 515.633 476.82 515.6 481.563 515.484C483.756 515.43 485.548 515.359 486.833 515.274C486.493 514.766 486.084 514.159 485.607 513.455C483.981 511.058 481.581 507.56 478.49 503.079C472.309 494.117 463.368 481.231 452.335 465.373C430.27 433.658 399.837 390.059 366.371 342.193L245.077 168.822L218.918 168.433L218.911 168.433L195.034 168.196L315.762 340.74Z"
                                                fill="black" stroke="black" stroke-width="3" />
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
                                                fill="black" />
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a href="https://www.facebook.com/sharer.php?u={{ urlencode($url_link) }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20"
                                            viewBox="0 0 20 20" fill="none">
                                            <path
                                                d="M13.3333 19.25H11H3C2.61666 19.25 2.04526 18.9887 1.53351 18.4845C1.02334 17.9819 0.75 17.4126 0.75 17V3C0.75 2.58739 1.02334 2.01811 1.53351 1.51546C2.04526 1.01127 2.61666 0.75 3 0.75H17C17.3833 0.75 17.9547 1.01127 18.4665 1.51546C18.9767 2.01811 19.25 2.58739 19.25 3V17C19.25 17.4126 18.9767 17.9819 18.4665 18.4845C17.9547 18.9887 17.3833 19.25 17 19.25H13.3333Z"
                                                stroke="black" stroke-width="1.5" />
                                            <path
                                                d="M12 8.21429C12 7.62255 12.4477 7.14286 13 7.14286H14C14.5523 7.14286 15 6.66317 15 6.07143C15 5.47969 14.5523 5 14 5H13C11.3431 5 10 6.43908 10 8.21429V10.3571H9C8.44771 10.3571 8 10.8368 8 11.4286C8 12.0203 8.44771 12.5 9 12.5H10V18.9286C10 19.5203 10.4477 20 11 20C11.5523 20 12 19.5203 12 18.9286V12.5H14C14.5523 12.5 15 12.0203 15 11.4286C15 10.8368 14.5523 10.3571 14 10.3571H12V8.21429Z"
                                                fill="black" />
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a
                                        href="https://www.linkedin.com/shareArticle?url={{ urlencode($url_link) }}&title=">
                                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                                            xmlns="http://www.w3.org/2000/svg">
                                            <path opacity="0.25"
                                                d="M0 3C0 1.34315 1.34315 0 3 0H17C18.6569 0 20 1.34315 20 3V17C20 18.6569 18.6569 20 17 20H3C1.34315 20 0 18.6569 0 17V3Z"
                                                fill="#12131A" />
                                            <path
                                                d="M5 6C5.55228 6 6 5.55228 6 5C6 4.44772 5.55228 4 5 4C4.44772 4 4 4.44772 4 5C4 5.55228 4.44772 6 5 6Z"
                                                fill="#12131A" />
                                            <path
                                                d="M5 8C4.44772 8 4 8.44772 4 9V15C4 15.5523 4.44772 16 5 16C5.55228 16 6 15.5523 6 15V9C6 8.44772 5.55228 8 5 8Z"
                                                fill="#12131A" />
                                            <path
                                                d="M12 10C10.8954 10 10 10.8954 10 12V15C10 15.5523 9.55229 16 9 16C8.44771 16 8 15.5523 8 15V9C8 8.44772 8.44772 8 9 8C9.40537 8 9.7544 8.2412 9.91141 8.58791C10.5193 8.215 11.2346 8 12 8C14.2091 8 16 9.79086 16 12V15C16 15.5523 15.5523 16 15 16C14.4477 16 14 15.5523 14 15V12C14 10.8954 13.1046 10 12 10Z"
                                                fill="#12131A" />
                                        </svg>
                                    </a>
                                </li>
                                <li>
                                    <a href="" id="copylink" data-bs-toggle="tooltip" data-bs-placement="bottom"
                                        title="" class="copy-icon insta-icon">
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
                                <img src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/icon/' . $color . '/close.svg') }}"
                                    alt="back" class="img-fluid">
                            </div>
                        </div>
                        <div class="row contactform-form-details">
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
                            <button type="button" class="btn btn-secondary" id="makecontact">{{ __('Make Contact')
                                }}</button>
                        </div>
                    </form>
                </div>
            </div>
            <!--contact popup end here-->
            <!-- Modal -->
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

            {{-- Gallery Model --}}
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
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/modal/bootstrap.min.js') }}"></script>

    <script
        src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/js/jquery.min.js') }}"></script>
    <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/js/slick.min.js') }}"
        defer="defer">
        </script>

    @if ($SITE_RTL == 'on')
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/js/rtl-custom.js') }}"
            defer="defer">
            </script>
    @else
        <script src="{{ asset('packages/workdo/Marketing/src/resources/assets/custom/theme14/js/custom.js') }}"
            defer="defer"></script>
    @endif

    @include('marketing::layouts.theme_script')
</body>

</html>