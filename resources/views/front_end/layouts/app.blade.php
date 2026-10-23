<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) ?? 'en' }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
  <meta name="author" content="{{ config('app.name') ?? Stylique }}">
  <meta name="base-url" content="{{ URL::to('/') }}">
  <meta name="csrf-token" content="{{ csrf_token() }}">
   {!! metaKeywordSetting($metakeyword ?? null,$metadesc ?? null,$metaimage ?? null,$slug) !!}
  <title>@yield('page-title')</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/all.min.css') }}">
  <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/swiper-bundle.min.css') }}" />
   @if($currantLang == 'ar' || $currantLang == 'he')
   <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/rtl-main-style.css') }}">
   @else
    <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/main-style.css') }}">
   @endif
  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/tailwind.js') }}"></script>
 
    <meta name="mobile-wep-app-capable" content="yes">
    <meta name="apple-mobile-wep-app-capable" content="yes">
    <meta name="msapplication-starturl" content="/">
    <!-- Favicon icon -->
    @php
        $favicon = $themeSettings['header_favicon'] ?? '';
        $faviconUrl = !empty($favicon) ? get_file($favicon) : asset('storage/uploads/logo/favicon.png');
    @endphp
    <link rel="icon" href="{{ $faviconUrl }}" type="image/x-icon" />
    <link rel="apple-touch-icon" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ $faviconUrl }}">
    <link rel="apple-touch-icon" sizes="167x167" href="{{ $faviconUrl }}">
    @if ($store->enable_pwa_store == 'on')
        <link rel="manifest"
            href="{{ asset('storage/uploads/customer_app/store_' . $store->id . '/manifest.json') }}" />
    @endif
    @if (!empty($store->pwa_store($store->slug)->theme_color))
        <meta name="theme-color" content="{{ $store->pwa_store($store->slug)->theme_color }}" />
    @endif
    @if (!empty($store->pwa_store($store->slug)->background_color))
        <meta name="apple-mobile-web-app-status-bar"
            content="{{ $store->pwa_store($store->slug)->background_color }}" />
    @endif
    <style>
        {!! $storecss ?? '' !!}
    </style>
    @stack('page-style')

    <script src="{{ asset('js/jquery.min.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/select2.min.css') }}">
    <script src="{{ asset('assets/js/plugins/select2.min.js') }}"></script>
</head>

<body>
   @include('front_end.partison.header')
    @if(isset($pixelScript))
        @foreach ($pixelScript as $script)
            <?= $script ?>
        @endforeach
    @endif

    @yield('content')
    @include('front_end.partison.footer')

    @include('front_end.jQueryRoute')
  <!-- Swiper JS -->

  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/swiper-bundle.min.js') }}"></script>
  @if($currantLang == 'ar' || $currantLang == 'he')
  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/rtl-custom.js') }}"></script>
  @else
  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/custom.js') }}"></script>
  @endif
   <!--Theme Routes Script-->
    
    <!--scripts end here-->
   @if ($message = Session::get('success'))
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            {{-- P1-SEC-22 (b). This line used to interpolate the flashed message UNESCAPED, inside single
             quotes, into a JavaScript string literal -- so an apostrophe ended the literal and a closing
             script tag ended the block. An injection sink, not merely an HTML-escaping gap. Js::from()
             emits a JSON literal INCLUDING its own quotes, which is why the surrounding ones are gone.
             (The vulnerable construct is deliberately not spelled out here: a docblock that quotes what
             a ratchet greps for makes the ratchet satisfiable by prose, which this project records.) --}}
            notifier.show('Success', {{ \Illuminate\Support\Js::from($message) }}, 'success', site_url +
                '/public/assets/images/notification/ok-48.png', 4000);
        </script>
    @endif

    @if ($message = Session::get('error'))
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            notifier.show('Error', {{ \Illuminate\Support\Js::from($message) }}, 'danger', site_url +
                '/public/assets/images/notification/high_priority-48.png', 4000);
        </script>
    @endif
    @include('front_end.hooks.footer_link')
    @stack('page-script')
    <script>

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
</body>
</html>
