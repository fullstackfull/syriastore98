<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@if (trim($__env->yieldContent('template_title')))@yield('template_title') | @endif {{ trans('installer_messages.updater.title') }}</title>
        <link rel="icon" type="image/png" href="{{ asset('installer/img/favicon/favicon-16x16.png') }}" sizes="16x16"/>
        <link rel="icon" type="image/png" href="{{ asset('installer/img/favicon/favicon-32x32.png') }}" sizes="32x32"/>
        <link rel="icon" type="image/png" href="{{ asset('installer/img/favicon/favicon-96x96.png') }}" sizes="96x96"/>
        <link href="{{ asset('installer/css/style.min.css') }}" rel="stylesheet"/>
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}" >
        @yield('style')
        <script>
            window.Laravel = {!! json_encode(['csrfToken' => csrf_token()]) !!};
        </script>
    </head>
    <body>
        <div class="master">
            <div class="box">
                <div class="header">
                    <h1 class="header__title">@yield('title')</h1>
                </div>
                <ul class="step">
                    <li class="step__divider"></li>
                    <li class="step__item {{ isActive('LaravelUpdater::final') }}">
                        <i class="step__icon fa fa-database" aria-hidden="true"></i>
                    </li>
                    <li class="step__divider"></li>
                    <li class="step__item {{ isActive('LaravelUpdater::overview') }}">
                        <i class="step__icon fa fa-reorder" aria-hidden="true"></i>
                    </li>
                    <li class="step__divider"></li>
                    <li class="step__item {{ isActive('LaravelUpdater::welcome') }}">
                        <i class="step__icon fa fa-refresh" aria-hidden="true"></i>
                    </li>
                    <li class="step__divider"></li>
                </ul>
                <div class="main">
                    @yield('container')
                </div>
            </div>
        </div>
        <div id="loader" class="loader-wrapper" style="display: none;">
            <span class="site-loader"> </span>
            <h3 class="loader-content"> {{ __('Updating . . .') }} </h3>
        </div>

        <script src="{{ asset('js/loader.js') }}"></script>
    </body>
</html>
