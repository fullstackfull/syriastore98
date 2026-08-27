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