{{--
    GAP-265. THIS FILE WAS `themes/stylique/views/layouts/app.blade.php` AND HAD TO MOVE.

    At that path it answered to the view name `layouts.app` -- which is the MERCHANT PANEL's
    layout name, not a storefront one. `ActiveTheme::handle()` calls `Theme::set($store->theme_id)`,
    which `prependLocation()`s `themes/stylique/views` onto the view finder, and `FileViewFinder`
    MEMOISES name -> path. So after one storefront request, every `layouts.app` in the same PHP
    process resolved to THIS file. Measured by execution: `GET /order` as a provisioned merchant
    answered HTTP 500, `Undefined variable $store`, while the same request in a clean process
    answered 200. One FPM process serves one request, which is the only reason it was latent rather
    than live -- and `config('theme.active')` is `stylique`, so it is the fallback every store with
    an empty `theme_id` gets.

    It now sits under `front_end/`, the namespace the storefront renders through and where a theme
    shadowing a host view is the intended mechanism rather than a cross-surface trap. The name
    `front_end.layouts.app-scaffold` has no host counterpart.

    THE FILE IS AN UNUSED, NON-FUNCTIONAL SCAFFOLD, and the move does not change that. Measured:
    nothing in any theme or in `resources/views` `@extends` or `@include`s it -- all three themes'
    `main_file.blade.php` extend `front_end.layouts.app`, which is a different, working file that
    also exists in this directory. Its own <body> is the placeholder marker below, so it has no
    `@yield('content')` and no `$slot` and could never have rendered a page. It is kept rather than
    deleted because removing theme content is a theme decision; the hazard was the NAME.

    The property is now ratcheted, for every theme and every name, by
    tests/Unit/Ui/ThemeViewsStayInTheStorefrontNamespaceTest.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('page-title') - {{ $store->name }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">

    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('themes/stylique/assets/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/stylique/assets/css/ltr.css') }}">
    @if(app()->getLocale() == 'ar')
        <link rel="stylesheet" href="{{ asset('themes/stylique/assets/css/rtl.css') }}">
    @endif

    <!-- Scripts -->
    <script src="{{ asset('themes/stylique/assets/js/custom.js') }}" defer></script>
</head>
<body class="font-sans antialiased">
    // ... existing code ...
</body>
</html> 