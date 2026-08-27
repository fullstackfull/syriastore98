@php
    if (auth()->user() && auth()->user()->type == 'admin') {
        $setting = getAdminAllSetting();
        $supperadminSetting = \App\Models\Setting::where('name', 'disable_lang')
            ->where('created_by', 1)
            ->pluck('value', 'name')
            ->toArray();
        $setting['disable_lang'] = $supperadminSetting['disable_lang'] ?? null;
    } else {
        $setting = getSuperAdminAllSetting();
    }
    $SuperadminData = getSuperAdminAllSetting();
    $cust_darklayout = \App\Models\Utility::GetValueByName('cust_darklayout');
    if ($cust_darklayout == '') {
        $setting['cust_darklayout'] = 'off';
    }

    $cust_theme_bg = \App\Models\Utility::GetValueByName('cust_theme_bg');
    if ($cust_theme_bg == '') {
        $setting['cust_theme_bg'] = 'on';
    }

    $SITE_RTL = \App\Models\Utility::GetValueByName('SITE_RTL');
    if ($SITE_RTL == '') {
        $setting['SITE_RTL'] = 'off';
    }

    if (!isset($setting['color'])) {
        $themeColor = 'theme-3';
        $color = 'theme-3';
    } elseif (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $themeColor = 'custom-color';
        $color = $setting['color'];
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
            $themeColor = 'custom-color' ?? 'theme-3';
            $color = $setting['color'];
        } else {
            $themeColor = $setting['color'] ?? 'theme-3';
            $color = $setting['color'];
        }
    }

    if (auth()->user() && auth()->user()->language) {
        $setting['currantLang'] = auth()->user()->language;
    } else {
        $setting['currantLang'] = 'en';
    }

    if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
        $setting['SITE_RTL'] = 'on';
    }
@endphp

<!DOCTYPE html>

<html lang="{{ str_replace('_', '-', $setting['currantLang'] ?? app()->getLocale()) }}"
    dir="{{ isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on' ? 'rtl' : '' }}" id="html-dir-tag">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ __('Theme Customize') }}</title>

    <link rel="icon"
        href="{{ isset($setting['favicon']) ? get_file($setting['favicon']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/favicon.png')) . '?timestamp=' . time() }}"
        type="image/x-icon" />

    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="base-url" content="{{ URL::to('/') }}">

    <link rel="stylesheet" href="{{ asset('css/custom-color.css') }}">

    <!-- font css -->
    <link rel="stylesheet" href="{{ asset('assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/fonts/material.css') }}">

    <!-- notification css -->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/notifier.css') }}">
    <!--bootstrap -->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/bootstrap-switch-button.min.css') }}">

    @if (isset($setting['cust_darklayout']) &&
            isset($setting['SITE_RTL']) &&
            $setting['cust_darklayout'] == 'on' &&
            $setting['SITE_RTL'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/rtl-style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}">
    @elseif(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}">
    @elseif(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}">
    @endif
    <!-- SweetAlert CSS -->
    <style>
        :root {
            --color-customColor: <?=$color ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
            --bs-custom-color-border: <?=$color ?? '#ffff' ?>;
        }
    </style>
    @stack('css-page')

</head>

<body class="{{ $themeColor ?? 'theme-3' }} custom-body">
    @yield('customize-section')

    <div id="commanModel" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modelCommanModelLabel"
        aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content ">
                <div class="modal-header">
                    <h4 class="modal-title" id="modelCommanModelLabel"></h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body"></div>
            </div>
        </div>
    </div>

    <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> {{ __('Loading . . .') }} </h3>
    </div>

    <script src="{{ asset('themes/' . $currentTheme . '/assets/js/jquery.min.js') }}"></script>
    <script src="{{ asset('themes/' . $currentTheme . '/assets/js/custom.js') }}"></script>
    <!-- Required Js -->
    <script src="{{ asset('public/js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/popper.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/simplebar.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/bootstrap.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/feather.min.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/bootstrap-switch-button.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/dash.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/apexcharts.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/simple-datatables.js') }}"></script>
    <script src="{{ asset('assets/js/plugins/notifier.js') }}"></script>
    <script src="{{ asset('public/assets/js/pages/ac-notification.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/choices.min.js') }}"></script>
    <script src="{{ asset('assets/css/summernote/summernote-bs4.js') }}"></script>
    <script src="{{ asset('public/assets/js/plugins/flatpickr.min.js') }}"></script>
    <script src="{{ asset('public/js/socialSharing.js') }}"></script>
    <script src="{{ asset('public/js/custom.js') }}"></script>
    <script src="{{ asset('public/js/jquery.form.js') }}"></script>
    <script src="{{ asset('public/js/jquery-ui.min.js') }}"></script>
    <script src="{{ asset('js/loader.js') }}"></script>
    <!-- SweetAlert JS -->
    @if ($message = Session::get('success'))
        <script>
            show_toastr('success', '{!! $message !!}');
        </script>
    @endif
    @if ($message = Session::get('error'))
        <script>
            show_toastr('error', '{!! $message !!}');
        </script>
    @endif
    @stack('script-page')

    <script>
        var saveThemeRoute = "{{ route('save-theme-layout') }}";
        var sidebarThemeRoute = "{{ route('sidebar-option') }}";
        var site_url = $('meta[name="base-url"]').attr('content');
        function show_toastr(title, message, type) {
            var o, i;
            var icon = '';
            var cls = '';
            if (type == 'success') {
                cls = 'primary';
                notifier.show('Success', message, 'success', site_url + '/public/assets/images/notification/ok-48.png', 4000);
            } else {
                cls = 'danger';
                notifier.show('Error', message, 'danger', site_url + '/public/assets/images/notification/high_priority-48.png', 4000);
            }
        }
    </script>
    <!-- theme custome JS File-->
    <script src="{{ asset('public/js/theme-custom.js') }}"></script>


    <script>
        var site_currency_symbol_position = '{{ \App\Models\Utility::getValByName('currency_symbol_position') }}';
        var site_currency_symbol =
            '{{ \App\Models\Store::where('id', auth()->user()->current_store)->first()->currency }}';
    </script>
    @stack('script')
    @stack('page-script')

</body>

</html>
