@php
    $setting = getSuperAdminAllSetting();

    $profile = asset(Storage::url('uploads/logo/'));
    if (isset($setting['cust_darklayout']) && $setting['cust_darklayout']=='on') {
        $company_logo = $setting['logo_light'] ?? \App\Models\Utility::GetValueByName('logo_light');
    }elseif(isset($setting['cust_darklayout']) && $setting['cust_darklayout']=='off'){
        $company_logo = $setting['logo_dark'] ?? \App\Models\Utility::GetValueByName('logo_dark');
    } else {
        $company_logo = \App\Models\Utility::GetValueByName('logo_dark');
    }

    $company_logo = get_file($company_logo);

    if (isset($setting['favicon'])) {
        $favicon = $setting['favicon'] ?? \App\Models\Utility::GetValueByName('favicon');
    } else {
        $favicon = \App\Models\Utility::GetValueByName('favicon');
    }

    $favicon = get_file($favicon);

    $cust_darklayout = $setting['cust_darklayout'] ?? null;
    if ($cust_darklayout == '' || empty($cust_darklayout)) {
        $cust_darklayout = 'off';
    }
    $cust_theme_bg = $setting['cust_theme_bg'] ?? null;
    if ($cust_theme_bg == '' || empty($cust_theme_bg)) {
        $cust_theme_bg = 'on';
    }
    $SITE_RTL = $setting['SITE_RTL'] ?? null;
    if ($SITE_RTL == '' || empty($SITE_RTL)) {
        $SITE_RTL = 'off';
    }

    if (!isset($setting['color'])) {
        $color = 'theme-3';
    } elseif (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $color = 'custom-color';
    } else {
        if (
            !in_array($setting['color'], [
                'theme-1',
                'theme-2',
                'theme-3',
                'theme-4',
                'theme-5',
                'theme-6',
                'theme-7',
                'theme-8',
                'theme-9',
                'theme-10',
            ])
        ) {
            $color = 'custom-color' ?? 'theme-3';
        } else {
            $color = $setting['color'] ?? 'theme-3';
        }
    }

    $lang = Session::get('LANGUAGE');
    if (empty($lang)) {
        $lang = Cookie::get('LANGUAGE') ?? null;
    }

    if (empty($lang)) {
        $lang = $setting['defult_language'] ?? null;
    }

    if (empty($lang)) {
        $lang = app()->getLocale() ?? 'en';
    }

    if (empty($lang) && app()->getLocale() != $lang) {
        $lang = app()->getLocale();
    }
    if ($lang == 'ar' || $lang == 'he') {
        $SITE_RTL = 'on';
    } else {
        $SITE_RTL = 'off';
    }

    $displaylang = App\Models\Utility::languages();

    if (isset($setting['disable_lang'])) {
        $toDisable = explode(',', $setting['disable_lang']);
    }

    foreach ($displaylang as $key => $data) {
        if (isset($setting['disable_lang']) && str_contains($setting['disable_lang'], $key)) {
            unset($displaylang[$key]);
        }
    }

    $footer_text = $setting['footer_text'] ?? config('platform.brand_name');
    $menusettings = \Workdo\LandingPage\Entities\OwnerMenuSetting::where('created_by', 1)->first();
    //$menusettings = null;

    if (isset($menusettings) && $menusettings->menus_id) {
        $topNavItems = \Workdo\LandingPage\Entities\OwnerMenuSetting::get_ownernav_menu($menusettings->menus_id);
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    dir="{{ isset($SITE_RTL) && $SITE_RTL == 'on' ? 'rtl' : '' }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="author" content="WorkDo.io" />

    <meta name="title" content="{{ isset($setting['metatitle']) ? $setting['metatitle'] : 'EcommerceGo' }}">
    <meta name="keywords"
        content="{{ isset($setting['metakeyword']) ? $setting['metakeyword'] : 'EcommerceGo, Store with Multi theme and Multi Store' }}">
    <meta name="description"
        content="{{ isset($setting['metadesc']) ? $setting['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.' }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ config('app.url') }}">
    <meta property="og:title" content="{{ isset($setting['metatitle']) ? $setting['metatitle'] : 'EcommerceGo' }}">
    <meta property="og:description"
        content="{{ isset($setting['metadesc']) ? $setting['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.' }} ">
    <meta property="og:image"
        content="{{ get_file(isset($setting['metaimage']) ? $setting['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png') }}{{ '?' . time() }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ config('app.url') }}">
    <meta property="twitter:title"
        content="{{ isset($setting['metatitle']) ? $setting['metatitle'] : 'EcommerceGo' }}">
    <meta property="twitter:description"
        content="{{ isset($setting['metadesc']) ? $setting['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.' }} ">
    <meta property="twitter:image"
        content="{{ get_file(isset($setting['metaimage']) ? $setting['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png') }}{{ '?' . time() }}">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}-@yield('page-title')</title>

    <!-- Favicon icon -->
    <link rel="icon"
        href="{{ !empty($favicon) ? $favicon . '?timestamp=' . time() : $profile . '/logo-sm.svg' . '?timestamp=' . time() }}"
        type="image/x-icon" />
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">

    <!-- vendor css -->
    @if ($cust_darklayout == 'on' && $SITE_RTL == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('assets/css/rtl-style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}">
    @elseif($cust_darklayout == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}">
    @elseif($SITE_RTL == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}">
    @endif
    {{-- <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link"> --}}

    <!-- Scripts -->
    <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">

    <link rel="stylesheet" href="{{ asset('css/custom.css') }}{{ '?v=' . time() }}">
    <link rel="stylesheet" href="{{ asset('css/custom-color.css') }}">

    <style>
        :root {
            --color-customColor: <?=$setting['color'] ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
        }
    </style>
    <style>
        .lnding-menubar {
            display: flex;
            align-items: center;
            color: #000000;
        }

        .lnding-menubar li {
            list-style-type: none;

        }

        .lnding-menubar li a {
            /* color: #000000; */
            text-transform: capitalize;
        }

        .lnding-menubar li.has-item>a {
            padding-right: 20px;
            display: flex;
            align-items: center;
            gap: 8px
        }

        html[dir="rtl"] .lnding-menubar li.has-item>a {
            padding-left: 20px;
            padding-right: 0px;
        }

        .lnding-menubar li.has-item .menu-dropdown {
            position: absolute;
            top: 100%;
            background-color: #ffffff;
            transform-origin: top;
            box-shadow: 0px 10px 40px rgb(0 0 0 / 5%);
            opacity: 0;
            visibility: hidden;
            min-width: 220px;
            z-index: 2;
            padding: 20px;
            -moz-transition: all ease-in-out 0.3s;
            -ms-transition: all ease-in-out 0.3s;
            -o-transition: all ease-in-out 0.3s;
            -webkit-transition: all ease-in-out 0.3s;
            transition: all ease-in-out 0.3s;
            -moz-transform: scaleY(0);
            -ms-transform: scaleY(0);
            -o-transform: scaleY(0);
            -webkit-transform: scaleY(0);
            transform: scaleY(0);
        }

        .lnding-menubar li.has-item:hover .menu-dropdown {
            opacity: 1;
            visibility: visible;
            -webkit-transform: scaleY(1);
            -moz-transform: scaleY(1);
            -ms-transform: scaleY(1);
            -o-transform: scaleY(1);
            transform: scaleY(1);
        }

        .lnding-menubar li.has-item .menu-dropdown li.lnk-itm>.dropdown-item {
            margin-bottom: 7px;
        }

        .lnding-menubar li.has-item .menu-dropdown li.lnk-itm:not(:last-of-type) {
            margin-bottom: 15px;
        }

        .lnding-menubar li.has-item .menu-dropdown li.lnk-itm .lnk-child li:not(:last-of-type) {
            margin-bottom: 10px;
        }

        .lnding-menubar li.has-item .menu-dropdown li.lnk-itm .lnk-child li {
            list-style-type: disc;
        }
    </style>
    @if ($cust_darklayout == 'on')
        <style>
            .lnding-menubar li.has-item .menu-dropdown,
            .new-login-design {
                background-color: #292a33;
            }
        </style>
    @endif
</head>

<body class="{{ $color ?? 'theme-3' }}">

    <div class="register-page auth-wrapper auth-v3">
        <div class="login-back-img"></div>

        <!-- <div class="bg-auth-side bg-primary login-page"></div> -->
        <div class="auth-content">
            <nav class="navbar navbar-expand-md navbar-light default">
                <div class="container-fluid">

                    <a class="navbar-brand p-0" href="{{ \URL::to('/') }}">
                        <img src="{{ isset($company_logo) && !empty($company_logo) ? $company_logo . '?timestamp=' . time() : $profile . '/logo-dark.svg' . '?timestamp=' . time() }}"
                            alt="logo" class="brand_icon" />
                    </a>

                    <div class="d-flex">
                        @if (isset($menusettings) &&
                                isset($menusettings->menus_id) &&
                                $menusettings->enable_login == 'on' &&
                                !empty($topNavItems))
                            <div class="collapse navbar-collapse" id="navbarTogglerDemo01">
                                <ul class="lnding-menubar p-0 m-0">
                                    @foreach ($topNavItems as $navGroup)
                                        <li class="menu-lnk has-item">
                                            <a class="dash-head-link" href="#">
                                                <span>
                                                    {{ $navGroup['name'] }}
                                                </span>
                                                <i class="ti ti-chevron-down drp-arrow"></i>
                                            </a>
                                            <div class="menu-dropdown">
                                                <ul class="p-0 m-0">
                                                    @foreach ($navGroup['items'] as $nav)
                                                        @if ($nav->type == 'page')
                                                            <li class="lnk-itm">
                                                                <a href="{{ url('landing-pages' . '/' . $nav->slug) }}"
                                                                    target="{{ $nav->target }}"
                                                                    class="dropdown-item">
                                                                    <span>{{ $nav->title }}</span>
                                                                </a>
                                                                @if (!empty($nav->children) && isset($nav->children))
                                                                    <ul class="lnk-child">
                                                                        @foreach ($nav->children[0] as $child)
                                                                            @if (!empty($child))
                                                                                <li>
                                                                                    @if ($child->type == 'page')
                                                                                        <a href="{{ url('landing-pages' . '/' . $child->slug) }}"
                                                                                            target="{{ $child->target }}"
                                                                                            class="dropdown-item">
                                                                                            <span>{{ $child->title }}</span>
                                                                                        </a>
                                                                                    @else
                                                                                        <a href="{{ $child->slug }}"
                                                                                            target="{{ $child->target }}"
                                                                                            class="dropdown-item">
                                                                                            <span>{{ $child->title }}</span>
                                                                                        </a>
                                                                                    @endif
                                                                                </li>
                                                                            @endif
                                                                        @endforeach
                                                                    </ul>
                                                                @endif
                                                            </li>
                                                        @else
                                                            <li>
                                                                <a href="{{ $nav->slug }}"
                                                                    target="{{ $nav->target }}"
                                                                    class="dropdown-item">
                                                                    <span>{{ $nav->title }}</span>
                                                                </a>
                                                                @if (!empty($nav->children))
                                                                    <ul>
                                                                        @foreach ($nav->children[0] as $child)
                                                                            @if (!empty($child))
                                                                                <li>
                                                                                    @if ($child->type == 'page')
                                                                                        <a href="{{ url('landing-pages' . '/' . $child->slug) }}"
                                                                                            target="{{ $child->target }}"
                                                                                            class="dropdown-item">
                                                                                            <span>{{ $child->title }}</span>
                                                                                        </a>
                                                                                    @else
                                                                                        <a href="{{ $child->slug }}"
                                                                                            target="{{ $child->target }}"
                                                                                            class="dropdown-item">
                                                                                            <span>{{ $child->title }}</span>
                                                                                        </a>
                                                                                    @endif
                                                                                </li>
                                                                            @endif
                                                                        @endforeach
                                                                    </ul>
                                                                @endif
                                                            </li>
                                                        @endif
                                                    @endforeach
                                                </ul>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                                <button class="navbar-toggler bg-primary" type="button" data-bs-toggle="collapse"
                                    data-bs-target="#navbarTogglerDemo01" aria-controls="navbarTogglerDemo01"
                                    aria-expanded="false" aria-label="Toggle navigation">
                                    <span class="navbar-toggler-icon"></span>
                                </button>
                            </div>
                        @endif
                        <div class="dropdown dash-h-item drp-language ecom-lang-drp">
                            <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-primary"
                                data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                                aria-expanded="false">
                                <i class="ti ti-world nocolor"></i>
                                <span class="drp-text">{{ strtoupper($lang) }}</span>
                                <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                            </a>

                            <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                                onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);">

                                @foreach ($displaylang as $key => $language)
                                    <a href="{{ route('changelanguage', $key) }}"
                                        class="dropdown-item {{ $lang == $key ? 'text-primary' : '' }}">
                                        <span>{{ Str::ucfirst($language) }}</span>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </nav>
            <div class="card">
                <div class="card-body mx-auto mt-5 new-login-design">
                    <div class="border-top"></div>
                    <div class="border-bottom"></div>
                    @yield('content')
                </div>
                <div class="auth-footer">
                    <p class="text-black mb-0">
                        @if (isset($setting['footer_text']) &&
                                (strpos($setting['footer_text'], '©') === false && strpos($setting['footer_text'], '&copy;') === false))
                            &copy;
                        @endif

                        {{ date('Y') }}
                        {{ isset($setting['footer_text']) ? $setting['footer_text'] : config('app.name', 'E-CommerceGo') }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> {{ __('Loading . . .') }} </h3>
    </div>
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('assets/js/vendor-all.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/bootstrap.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/feather.min.js') }}"></script>
    <script src="{{ asset('js/loader.js') }}"></script>
    @stack('scripts')
    @php
        $setting = getSuperAdminAllSetting();
    @endphp
    @if (isset($setting['enable_cookie']) && $setting['enable_cookie'] == 'on')
        @include('layouts.cookie_consent')
    @endif
</body>

</html>
