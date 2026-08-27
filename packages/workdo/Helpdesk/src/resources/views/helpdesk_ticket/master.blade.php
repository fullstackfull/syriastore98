@php
    $admin_settings = getAdminAllSetting();
    $company_settings = getAdminAllSetting($ticket->created_by);
    $favicon = isset($company_settings['favicon'])
        ? $company_settings['favicon']
        : (isset($admin_settings['favicon'])
            ? $admin_settings['favicon']
            : 'uploads/logo/favicon.png');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ env('SITE_RTL') == 'on' ? 'rtl' : '' }}">

<head>

    <title>@yield('page-title') |
        {{ !empty($company_settings['title_text']) ? $company_settings['title_text'] : (!empty($admin_settings['title_text']) ? $admin_settings['title_text'] : 'WorkDo') }}
    </title>
    <meta name="title"
        content="{{ !empty($admin_settings['meta_title']) ? $admin_settings['meta_title'] : 'EcommerceGo-SaaS' }}">
    <meta name="keywords"
        content="{{ !empty($admin_settings['meta_keywords']) ? $admin_settings['meta_keywords'] : 'EcommerceGo,SaaS solution,Multi-workspace' }}">
    <meta name="description"
        content="{{ !empty($admin_settings['meta_description']) ? $admin_settings['meta_description'] : 'Discover the efficiency of Dash, a user-friendly web application by Rajodiya Apps.' }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ env('APP_URL') }}">
    <meta name="base-url" content="{{ URL::to('/') }}">
    <meta property="og:title"
        content="{{ !empty($admin_settings['meta_title']) ? $admin_settings['meta_title'] : 'EcommerceGo-SaaS' }}">
    <meta property="og:description"
        content="{{ !empty($admin_settings['meta_description']) ? $admin_settings['meta_description'] : 'Discover the efficiency of Dash, a user-friendly web application by Rajodiya Apps.' }} ">
    <meta property="og:image"
        content="{{ get_file(!empty($admin_settings['meta_image']) ? (check_file($admin_settings['meta_image']) ? $admin_settings['meta_image'] : 'uploads/meta/meta_image.png') : 'uploads/meta/meta_image.png') }}{{ '?' . time() }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ env('APP_URL') }}">
    <meta property="twitter:title"
        content="{{ !empty($admin_settings['meta_title']) ? $admin_settings['meta_title'] : 'EcommerceGo-SaaS' }}">
    <meta property="twitter:description"
        content="{{ !empty($admin_settings['meta_description']) ? $admin_settings['meta_description'] : 'Discover the efficiency of Dash, a user-friendly web application by Rajodiya Apps.' }} ">
    <meta property="twitter:image"
        content="{{ get_file(!empty($admin_settings['meta_image']) ? (check_file($admin_settings['meta_image']) ? $admin_settings['meta_image'] : 'uploads/meta/meta_image.png') : 'uploads/meta/meta_image.png') }}{{ '?' . time() }}">

    <meta name="author" content="Workdo.io">

    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

    <link rel="icon"
        href="{{ check_file($favicon) ? get_file($favicon) : get_file('uploads/logo/favicon.png') }}{{ '?' . time() }}"
        type="image/x-icon" />

    <!-- CSS Libraries -->
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    @stack('css')

    <!-- font css -->
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">
    <!-- vendor css -->
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
    <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">
    <!-- notification css -->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/notifier.css') }}">

    <!-- @if (isset($setting['cust_darklayout']) && isset($setting['SITE_RTL']) && $setting['cust_darklayout'] == 'on' && $setting['SITE_RTL'] == 'on')
        <link rel="stylesheet" href="{{ asset('css/rtl-custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
    @elseif(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}" id="main-style-link">
    @elseif(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on')
        <link rel="stylesheet" href="{{ asset('css/rtl-custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
    @else
        <link rel="stylesheet" href="{{ asset('css/custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
    @endif -->

</head>

<body class="{{ !empty($company_settings['color']) ? $company_settings['color'] : 'theme-1' }}">
    @yield('content')
    <div class="position-fixed top-0 end-0 p-3" style="z-index: 99999">
        <div id="liveToast" class="toast text-white  fade" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body"> </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"
                    aria-label="Close"></button>
            </div>
        </div>
    </div>
    <script src="{{ asset('js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/popper.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/bootstrap.min.js') }}"></script>

    <script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>
    <script src="{{ asset('js/custom.js') }}{{ "?".time() }}"></script>
    
    @if ($message = Session::get('success'))
        <script>
            show_toastr('{{ __('Success') }}', '{!! $message !!}', 'success')
        </script>
    @endif

    @if ($message = Session::get('error'))
        <script>
            show_toastr('{{ __('Error') }}', '{!! $message !!}', 'error')
        </script>
    @endif
    
    @stack('script')
    @if (isset($admin_settings['enable_cookie']) && $admin_settings['enable_cookie'] == 'on')
        @include('layouts.cookie_consent')
    @endif
</body>

</html>
