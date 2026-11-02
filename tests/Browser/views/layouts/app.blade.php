<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['ar','he']) ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="browser-verification-token">
<title>@yield('page-title')</title>
<link rel="stylesheet" href="/assets/css/style{{ in_array(app()->getLocale(), ['ar','he']) ? '-rtl' : '' }}.css">
@stack('css')
</head>
<body class="{{ in_array(app()->getLocale(), ['ar','he']) ? 'rtl' : '' }}">
<div class="container-fluid py-3">
  <ol class="ss-breadcrumb">@yield('breadcrumb')</ol>{{-- P2-01-14: the DS class, so this harness renders the items the real screens now emit. Not the <x-ss.breadcrumb> component: this standalone harness has no `dashboard` route for its Home link to resolve. --}}
  @yield('content')
</div>
<script src="/js/jquery-3.6.0.min.js"></script>
<script src="/assets/js/plugins/popper.min.js"></script>
<script src="/assets/js/plugins/bootstrap.min.js"></script>
@stack('scripts')
@stack('custom-script')
</body>
</html>
