<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) ?? 'en' }}" dir="{{ ($currantLang == 'ar' || $currantLang == 'he') ? 'rtl' : 'ltr' }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1" />
  <meta name="author" content="{{ config('app.name') ?? Greentic}}">
  <meta name="base-url" content="{{ URL::to('/') }}">
  <meta name="csrf-token" content="{{ csrf_token() }}">
   {!! metaKeywordSetting($metakeyword ?? null,$metadesc ?? null,$metaimage ?? null,$slug) !!}
  <title>@yield('page-title')</title>
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/all.min.css') }}">
  <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/swiper-bundle.min.css') }}" />
 
    <link rel="stylesheet" href="{{ asset('themes/' . $currentTheme . '/assets/css/main-style.css') }}">

  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/tailwind.js') }}"></script>
 
    <meta name="mobile-wep-app-capable" content="yes">
    <meta name="apple-mobile-wep-app-capable" content="yes">
    <meta name="msapplication-starturl" content="/">
    <!-- Favicon icon -->
    <link rel="icon" href="{{ get_file($themeSettings['header_favicon'] ?? '') }}" type="favicon" />
    <link rel="apple-touch-icon" href="{{ get_file($themeSettings['header_favicon'] ?? '') }}">
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
    <script>
        tailwind.config = {
            darkMode: ["class"],
            theme: {
                container: {
                    center: true,
                    padding: '2rem',
                    screens: {
                        'sm': '640px',
                        'md': '768px',
                        'lg': '1024px',
                        'xl': '1280px',
                        '2xl': '1400px'
                    }
                },
                extend: {
                    fontFamily: {
                        inter: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        border: 'hsl(var(--border))',
                        input: 'hsl(var(--input))',
                        ring: 'hsl(var(--ring))',
                        background: 'hsl(var(--background))',
                        foreground: 'hsl(var(--foreground))',
                        primary: {
                            DEFAULT: 'hsl(var(--primary))',
                            foreground: 'hsl(var(--primary-foreground))'
                        },
                        secondary: {
                            DEFAULT: 'hsl(var(--secondary))',
                            foreground: 'hsl(var(--secondary-foreground))'
                        },
                        destructive: {
                            DEFAULT: 'hsl(var(--destructive))',
                            foreground: 'hsl(var(--destructive-foreground))'
                        },
                        muted: {
                            DEFAULT: 'hsl(var(--muted))',
                            foreground: 'hsl(var(--muted-foreground))'
                        },
                        accent: {
                            DEFAULT: 'hsl(var(--accent))',
                            foreground: 'hsl(var(--accent-foreground))'
                        },
                        popover: {
                            DEFAULT: 'hsl(var(--popover))',
                            foreground: 'hsl(var(--popover-foreground))'
                        },
                        card: {
                            DEFAULT: 'hsl(var(--card))',
                            foreground: 'hsl(var(--card-foreground))'
                        }
                    },
                    borderRadius: {
                        lg: 'var(--radius)',
                        md: 'calc(var(--radius) - 2px)',
                        sm: 'calc(var(--radius) - 4px)'
                    },
                    keyframes: {
                        'accordion-down': {
                            from: { height: '0' },
                            to: { height: 'var(--radix-accordion-content-height)' }
                        },
                        'accordion-up': {
                            from: { height: 'var(--radix-accordion-content-height)' },
                            to: { height: '0' }
                        },
                        'fade-in': {
                            '0%': {
                                opacity: '0',
                                transform: 'translateY(10px)'
                            },
                            '100%': {
                                opacity: '1',
                                transform: 'translateY(0)'
                            }
                        },
                        'pulse-gentle': {
                            '0%, 100%': { opacity: '1' },
                            '50%': { opacity: '0.8' }
                        }
                    },
                    animation: {
                        'accordion-down': 'accordion-down 0.2s ease-out',
                        'accordion-up': 'accordion-up 0.2s ease-out',
                        'fade-in': 'fade-in 0.3s ease-out',
                        'pulse-gentle': 'pulse-gentle 2s ease-in-out infinite'
                    }
                }
            }
        }
    </script>
    <script src="{{ asset('js/jquery.min.js') }}"></script>
    @if($currantLang == 'ar' || $currantLang == 'he')
        <link rel="stylesheet" href="{{ asset('assets/css/rtl-floating-wpp.min.css') }}">
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/floating-wpp.min.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/select2.min.css') }}">
    <script src="{{ asset('assets/js/plugins/select2.min.js') }}"></script>
</head>

<body>
<div class="overlay" id="filter-overlay"></div>
   @include('front_end.partison.announcement')
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
  
  <script src="{{ asset('themes/' . $currentTheme . '/assets/js/custom.js') }}"></script>
 
   <!--Theme Routes Script-->
    
    <!--scripts end here-->
   @if ($message = Session::get('success'))
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            notifier.show('Success', '{!! $message !!}', 'success', site_url +
                '/public/assets/images/notification/ok-48.png', 4000);
        </script>
    @endif

    @if ($message = Session::get('error'))
        <script>
            var site_url = $('meta[name="base-url"]').attr('content');
            notifier.show('Error', '{!! $message !!}', 'danger', site_url +
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
