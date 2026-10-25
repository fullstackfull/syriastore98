@php
    if(auth()->user() && auth()->user()->type == 'admin') {
        $setting = getAdminAllSetting();
        $supperadminSetting = \App\Models\Setting::where('name', 'disable_lang')->where('created_by', 1)->pluck('value', 'name')->toArray();
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
    if($cust_theme_bg == ''){
        $setting['cust_theme_bg'] = 'on';
    }

    $SITE_RTL = \App\Models\Utility::GetValueByName('SITE_RTL');
    if($SITE_RTL == ''){
        $setting['SITE_RTL'] = 'off';
    }

    if (!isset($setting['color'])) {
        $themeColor = 'theme-3';
        $color = 'theme-3';
    } elseif (isset($setting['color_flag']) && $setting['color_flag'] == 'true') {
        $themeColor = 'custom-color';
        $color = $setting['color'];
    } else {
        if (!in_array($setting['color'], ['theme-1','theme-2','theme-3','theme-4','theme-5','theme-6','theme-7','theme-8','theme-9','theme-10'])) {
            $themeColor = 'custom-color' ?? 'theme-3';
            $color = $setting['color'];
        } else {
            $themeColor = $setting['color'] ?? 'theme-3';
            $color = $setting['color'];
        }

    }

    if(auth()->user() && auth()->user()->language) {
        $setting['currantLang'] = auth()->user()->language;
    } else {
        $setting['currantLang'] = 'en';
    }

    if ($setting['currantLang'] == 'ar' || $setting['currantLang'] == 'he') {
        $setting['SITE_RTL'] = 'on';
    }
@endphp

<!DOCTYPE html>
{{-- P2-01-11: data-ss-theme is set SERVER-SIDE here, not only by the opt-in
     include at layouts/includes/store-syria-ds.blade.php.

     That include is per-page (dashboard, attribute_option, previews), but the
     SIDEBAR is rendered by this layout on EVERY page -- so sidebar rules that
     need to know the theme could not rely on it. Setting the attribute here
     makes the theme known to CSS on every page, with no script and no
     flash-of-wrong-theme.

     `data-ss-theme-source="host"` is set with it on purpose: the brand-settings
     page swaps #main-style-link's href live, and the include's sync() only
     removes an attribute whose source is "host". Omitting it would leave a
     stale data-ss-theme="dark" behind when a merchant toggles to light without
     reloading. --}}
<html lang="{{ str_replace('_', '-', ($setting['currantLang'] ?? app()->getLocale())) }}" dir="{{ isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on'? 'rtl' : '' }}" id="html-dir-tag"
    @if (isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on') data-ss-theme="dark" data-ss-theme-source="host" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=0, minimal-ui" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="author" content="WorkDo.io" />
    <meta name="base-url" content="{{ URL::to('/') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="title" content="{{ isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo' }}">
    <meta name="keywords" content="{{ isset($SuperadminData['metakeyword']) ? $SuperadminData['metakeyword'] : 'EcommerceGo, Store with Multi theme and Multi Store' }}">
    <meta name="description" content="{{ isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'}}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ env('APP_URL') }}">
    <meta property="og:title" content="{{ isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo' }}">
    <meta property="og:description" content="{{ isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'}} ">
    <meta property="og:image" content="{{ get_file(isset($SuperadminData['metaimage']) ? $SuperadminData['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')  }}{{'?'.time() }}">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="{{ env('APP_URL') }}">
    <meta property="twitter:title" content="{{ isset($SuperadminData['metatitle']) ? $SuperadminData['metatitle'] : 'EcommerceGo' }}">
    <meta property="twitter:description" content="{{ isset($SuperadminData['metadesc']) ? $SuperadminData['metadesc'] : 'Discover the efficiency of EcommerceGo, a user-friendly web application by Workdo.io.'}} ">
    <meta property="twitter:image" content="{{ get_file(isset($SuperadminData['metaimage']) ? $SuperadminData['metaimage'] : 'storage/uploads/ecommercego-saas-preview.png')  }}{{'?'.time() }}">

    <title>{{ isset($setting['title_text']) ? $setting['title_text'] : ( env('APP_NAME') ?? 'Ecommercego saas') }} - @yield('page-title') </title>

    <link rel="stylesheet" href="{{ asset('assets/css/plugins/select2.min.css') }}">
    <!-- Favicon icon -->
    <link rel="icon" href="{{ isset($setting['favicon']) ? get_file($setting['favicon']) . '?timestamp=' . time() : asset(Storage::url('uploads/logo/favicon.png')) . '?timestamp=' . time() }}" type="image/x-icon" />

    <!-- notification css -->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/notifier.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/bootstrap-switch-button.min.css') }}">
    <!-- datatable css -->
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/style.css') }}">
    <link rel="stylesheet" href="{{ asset('css/custom-color.css') }}">
    <!-- Fonts -->
    <!-- font css -->
    <link rel="stylesheet" href="{{ asset('/assets/fonts/tabler-icons.min.css') }}">
    <link rel="stylesheet" href="{{ asset('/assets/fonts/feather.css') }}">
    <link rel="stylesheet" href="{{ asset('/assets/fonts/fontawesome.css') }}">
    <link rel="stylesheet" href="{{ asset('/assets/fonts/material.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/customizer.css') }}">
    {{-- P2-A-01: `asset('public/...')` resolves to /public/..., which 404s -- the docroot IS
         public/, so the segment is doubled. See the note on the RTL-dark sheet below. --}}
    <link rel="stylesheet" href="{{ asset('assets/css/plugins/dropzone.css') }}" type="text/css" />

    @if (isset($setting['cust_darklayout']) && isset($setting['SITE_RTL']) && $setting['cust_darklayout'] == 'on' && $setting['SITE_RTL'] == 'on')
        {{--
            P2-A-01. ARABIC + DARK MODE LOADED NO STYLESHEET AT ALL.

            `asset('public/assets/css/rtl-style-dark.css')` resolves to
            `/public/assets/css/rtl-style-dark.css`, and the docroot already IS `public/`, so the
            segment is doubled and the request 404s. MEASURED against the running application:
            that url answers 404 while `/assets/css/rtl-style-dark.css` answers 200, and the file
            is on disk at `public/assets/css/rtl-style-dark.css`.

            `#main-style-link` is THE layout stylesheet, so an Arabic merchant with dark mode on
            got the admin with no layout CSS: no sidebar, the language menu rendered as a bare
            bulleted list, every link blue and underlined. Found in a screenshot of the running
            application; the three sibling branches use the correct unprefixed form, so this one
            was simply wrong.

            THE WIDER PATTERN IS NOT FIXED HERE. `asset('public/...')` appears about 100 times
            across 32 view files -- payment pages, theme layouts, the marketplace -- and a sweep
            of those is a different blast radius with different callers to verify. Recorded in
            the ledger. What is fixed is the admin layout, which is this phase's own surface.
        --}}
        <link rel="stylesheet" href="{{ asset('assets/css/rtl-style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}" >
    @elseif(isset($setting['cust_darklayout']) && $setting['cust_darklayout'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-dark.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}" >
    @elseif(isset($setting['SITE_RTL']) && $setting['SITE_RTL'] == 'on')
        <link rel="stylesheet" href="{{ asset('assets/css/style-rtl.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
        <link rel="stylesheet" href="{{ asset('css/rtl-loader.css') }}{{ '?v=' . time() }}" >
    @else
        <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}" id="main-style-link">
        <link rel="stylesheet" href="{{ asset('css/custom.css') }}{{ '?v=' . time() }}"  id="main-style-custom-link">
        <link rel="stylesheet" href="{{ asset('css/loader.css') }}{{ '?v=' . time() }}" >
    @endif

    {{-- P2-01-11: the design system is loaded for EVERY admin page now, not
         only the screens that opt in via layouts/includes/store-syria-ds.blade.php.

         Why it moved: the sidebar is shared chrome rendered by this layout on
         every page, and its workspace chip and nav-group headings are
         design-system components (.ss-workspace, .ss-navgroup, built in
         P2-01-1). Opting in per screen would have left them unstyled almost
         everywhere — which is how the first attempt at this ended up with a
         SECOND, duplicate copy of the chip's CSS in custom.css that then
         collided with the real one.

         Why that is safe for all 70 WorkDo modules: NO SELECTOR IN
         store-syria-ds.css CAN MATCH AN ELEMENT OUTSIDE THE DESIGN SYSTEM.
         That is the operative property, and it is checked rather than assumed.
         The non-`.ss-` subjects in the file are:

           :root (:52, :106)                custom properties only
           [data-ss-theme="dark"] (:246)    custom properties only
           [data-ss-theme="auto"] (:289)    custom properties only
           @keyframes ss-spin, ss-shimmer   no subject
           .ss-scope *                      scoped by an .ss- ancestor
           [dir="rtl"] .ss-*  (11 of them)  likewise
           .minimenu .ss-workspace-wrap     a HOST class as ancestor, .ss- subject
           .ss-sort-list .ui-sortable-placeholder (:833)

         That last one is the exception worth knowing, because a stricter claim
         — "every selector carries an `.ss-` class" — is literally FALSE and an
         earlier version of this comment made it. Its subject is jQuery UI's
         own placeholder class, not an `.ss-` one. It is still unreachable
         outside the design system: it is descendant-scoped under
         `.ss-sort-list`, so it can only fire inside a DS sort list.

         No existing markup in the application uses an `.ss-` class, so nothing
         that was already on the page is restyled. Re-check this if the design
         system's standing rule — never style a bare element selector — is ever
         relaxed.

         NOT COVERED BY ANY OF THAT: this slice also adds host-selector rules
         to public/css/custom.css and rtl-custom.css —
         `.dash-sidebar .sidebar-input` and its `.form-control` — which really
         do restyle the host's sidebar search row on every admin page. They are
         confined to `.dash-sidebar` and justified by a measurement recorded
         beside them, but they are a host change and are not made safe by
         anything written above.

         The per-screen include remains and is now a no-op duplicate link on
         those screens; it is kept so a screen outside this layout (guest,
         theme_layout) can still opt in. --}}
    <link rel="stylesheet" href="{{ asset('css/store-syria-ds.css') }}?v=p02-2">
    {{-- P2-04-5. The BEHAVIOUR follows the stylesheet into the layout, for the same reason the
         stylesheet was promoted: a screen that uses a design-system component should not also
         have to remember an include. The order list's quick-preview panel is the case that
         forced it -- order/index.blade.php never opted in, so the panel rendered and opened with
         an empty body and the fetch never ran, measured across eight screenshot combinations.

         `defer`, so it does not block the parse, and dependency-free, so order does not matter.
         The file guards itself against a second execution, because the opt-in partial is still
         included by dashboard.blade.php and attribute_option/index.blade.php and a repeated
         <script src> runs again rather than being skipped. --}}
    <script defer src="{{ asset('js/store-syria-ds.js') }}?v=p02-3"></script>

    {{-- P2-A-02. THE DARK-MODE HOOK, in <head> and on every admin page.

         The host does not mark dark mode with a class: it swaps `#main-style-link` between
         style.css, style-dark.css, style-rtl.css and rtl-style-dark.css server-side. This reads
         that same signal and publishes it as `data-ss-theme`, so a first-party stylesheet can
         have a dark-only rule at all -- custom.css is loaded in BOTH light and dark, which is
         why the Phase 02 contrast fixes could not previously be scoped.

         The identical logic has always been in layouts/includes/store-syria-ds.blade.php, but
         that partial is included by exactly two views, so every other admin screen had no hook.
         Running in both places is harmless: it is idempotent and only ever sets a value it
         itself would remove. Inline and in <head>, after #main-style-link exists and before the
         body paints, so there is no flash of light cards in a dark admin. --}}
    <script>
        (function () {
            var html = document.documentElement;
            var main = document.getElementById('main-style-link');
            if (!main) { return; }
            var sync = function () {
                if (/style-dark\.css/.test(main.getAttribute('href') || '')) {
                    html.setAttribute('data-ss-theme', 'dark');
                    html.setAttribute('data-ss-theme-source', 'host');
                } else if (html.getAttribute('data-ss-theme-source') === 'host') {
                    html.removeAttribute('data-ss-theme');
                    html.removeAttribute('data-ss-theme-source');
                }
            };
            sync();
            if (window.MutationObserver) {
                new MutationObserver(sync).observe(main, { attributes: true, attributeFilter: ['href'] });
            }
        })();
    </script>

    <link rel="stylesheet" href="{{ asset('assets/css/plugins/flatpickr.min.css') }}">

    <link rel="stylesheet" href="{{ asset('assets/css/summernote/summernote-bs4.css') }}">



    <link rel="stylesheet" href="{{ asset('css/emojionearea.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/calendar.css') }}">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css"/>


    <!-- Scripts -->

    <style>
        {!! isset($setting['storecss']) ? $setting['storecss'] :  '' !!}

        :root {
            --color-customColor: <?= $color ?? 'linear-gradient(141.55deg, rgba(240, 244, 243, 0) 3.46%, #ffffff 99.86%)' ?>;
            --bs-custom-color-border: <?= $color ?? '#ffff' ?>;
        }

        #storeList {
            display: none;
        }

        .dropdown-menu.show #storeList {
            display: block;
        }
        .select2-container {
            width: 100% !important;
        }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {

        }
        .note-link-unlink-sample #commanModel{
            display: none !important;
        }
        .note-modal-footer {
            height: 50px;
        }

    </style>
     @if (app()->getLocale() == 'ar' || app()->getLocale() == 'he')
        <style>
            .select2-selection__rendered {
                float : right;
            }

        </style>
    @else
        <style>
        .select2-selection__rendered {
            float : left;
        }
        </style>
    @endif
    @stack('css')

    {!! isset($setting['storejs']) ? $setting['storejs'] :  '' !!}

</head>

<body class="{{ $themeColor ?? 'theme-3'}}">
    {{-- P2-01-11: restore the collapsed rail BEFORE the sidebar paints.

         This is inline and first-in-body rather than in store-syria-nav.js for
         one reason: a deferred script runs after first paint, so the merchant
         would see the full-width sidebar appear and then snap to the rail on
         every single page load. Three lines here, no flash.

         It only ever ADDS a class the host stylesheets already define
         (38 `.minimenu` rules in each of style.css, style-dark.css and
         style-rtl.css). localStorage is the right store for this and not a
         users column: it is a per-DEVICE layout preference, and a merchant who
         collapses the rail on a laptop has said nothing about their phone.
         Wrapped in try/catch because localStorage throws, not returns null, in
         a private window with site data blocked. --}}
    <script>
        try {
            if (localStorage.getItem('ss-nav-rail') === 'collapsed') {
                document.body.classList.add('minimenu');
            }
        } catch (e) {}
    </script>

    {{-- P2-A-03 / ledger F9. A READABLE LABEL ON THE MERCHANT'S OWN BRAND COLOUR.

         THE DEFECT: `.dash-item.active > .dash-link` is `#fff` on `var(--theme-color)`, so the
         merchant's CURRENT LOCATION was the least legible label on the screen. On the default
         theme-3 (#10B77F) that measures 2.59:1 against a 4.5:1 requirement, at every width,
         desktop included -- measured in the running application, all four
         light/dark x LTR/RTL configurations.

         WHY THIS AND NOT A NEW COLOUR: `--theme-color` is the merchant's brand -- ten presets
         plus a colour picker -- so there is no single safe value, and the ledger recorded F9 as
         an owner decision for that reason. This changes NO brand colour. The background stays
         exactly what the merchant chose; only the LABEL flips to whichever of white or
         near-black is readable on it, which is what a contrast-aware design system does and
         needs no global policy.

         MEASURED ACROSS ALL TEN PRESETS: white wins on six, near-black on four, and every one
         of the ten reaches AA under this rule -- the worst becomes 4.90:1, where six of them
         were previously below 4.5:1. A custom picker colour is handled by the same arithmetic
         rather than by a lookup table, so it cannot be a theme nobody checked.

         FIRST-IN-BODY, like the rail above and for the same reason: `--theme-color` is declared
         on `:root .theme-N`, i.e. on this BODY element, so it cannot be read from <head>, and a
         deferred script would let the unreadable label paint first. It only ever SETS a custom
         property; if it throws or is blocked, the CSS falls back to #fff and the screen is
         exactly what it is today. --}}
    <script>
        (function () {
            try {
                var raw = getComputedStyle(document.body).getPropertyValue('--theme-color').trim();
                if (!raw) { return; }

                var rgb = null;
                var hex = raw.replace('#', '');
                if (/^[0-9a-f]{3}$/i.test(hex)) {
                    rgb = [parseInt(hex[0] + hex[0], 16), parseInt(hex[1] + hex[1], 16), parseInt(hex[2] + hex[2], 16)];
                } else if (/^[0-9a-f]{6}$/i.test(hex)) {
                    rgb = [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
                } else {
                    var m = raw.match(/rgba?\(([^)]+)\)/);
                    if (m) { rgb = m[1].split(',').slice(0, 3).map(function (v) { return parseFloat(v); }); }
                }
                if (!rgb || rgb.some(function (v) { return isNaN(v); })) { return; }

                // WCAG 2.x relative luminance, then the standard contrast ratio against each
                // candidate. Choosing by ratio rather than by a luminance threshold is what
                // makes the mid-tone brands (#008ecc, #0c7785) come out right.
                var lum = function (c) {
                    var s = c.map(function (v) {
                        v = v / 255;
                        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
                    });
                    return 0.2126 * s[0] + 0.7152 * s[1] + 0.0722 * s[2];
                };
                var ratio = function (a, b) {
                    var la = lum(a), lb = lum(b);
                    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
                };

                var WHITE = [255, 255, 255];
                var INK = [17, 24, 28];   // near-black, not pure black: softer on a mid tone

                document.documentElement.style.setProperty(
                    '--ss-onbrand',
                    ratio(WHITE, rgb) >= ratio(INK, rgb) ? '#ffffff' : '#11181c'
                );

                /* AND THE BRAND AS LINK TEXT ON A LIGHT SURFACE, which is the same defect
                   pointing the other way: the breadcrumb link is `var(--theme-color)` on the
                   page background, and on theme-3 that measures 2.48:1.

                   DARKENED TOWARDS BLACK, WHICH KEEPS THE HUE. Only the lightness moves, and
                   only until AA is met -- measured across the ten presets, SIX ARE ALREADY
                   ABOVE 4.5:1 AND COME OUT UNCHANGED (the loop exits on the first test). The
                   four that need it land between 4.51:1 and 4.73:1, close to the threshold
                   rather than washed out. The brand colour itself is untouched: this is a
                   separate property used only where the brand is TEXT, so every fill, border
                   and badge keeps the merchant's exact colour. */
                var PAGE = [249, 250, 251];
                var scale = 1;
                var text = rgb;
                while (scale > 0.05) {
                    text = rgb.map(function (v) { return Math.round(v * scale); });
                    if (ratio(text, PAGE) >= 4.5) { break; }
                    scale -= 0.02;
                }
                document.documentElement.style.setProperty(
                    '--ss-brand-ontext',
                    'rgb(' + text[0] + ',' + text[1] + ',' + text[2] + ')'
                );
            } catch (e) {}
        })();
    </script>

    @include('partision.sidebar')

    @include('partision.header')

    <!-- [ Main Content ] start -->
    <div class="dash-container">
        <div class="dash-content">
           <!-- [ breadcrumb ] start -->
           <div class="page-header mb-md-4 mb-3">
                <div class="page-block d-flex align-items-center justify-content-between gap-3 flex-wrap">
                        <div class="page-header-wrp">
                            <div class="page-header-title">
                                <h2 class="h3 mb-0">@yield('page-title')</h4>
                            </div>
                            @if (\Request::route()->getName() != 'dashboard')
                            <ul class="breadcrumb mt-2">
                                <li class="breadcrumb-item">
                                        <a href="{{ route('dashboard') }}">{{ __('Home') }}</a>
                                    </li>
                                    @yield('breadcrumb')
                            </ul>
                            @endif
                        </div>
                        <div class="page-header-icon d-flex flex-wrap gap-2">
                            @yield('action-button')
                        </div>
                </div>
            </div>
            <!-- [ breadcrumb ] end -->
            @yield('content')
        </div>
    </div>
    <!-- [ Main Content ] end -->

    @if (\Request::route()->getName() != 'pos.index')
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

        <div id="commanModelOver" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="modelCommanModelLabel"
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
    @else
        <div class="modal fade" id="commonModal" tabindex="-1" role="dialog" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="exampleModalLabel"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                    </div>
                </div>
            </div>
        </div>
    @endif


    <div id="loader" class="loader-wrapper" style="display: none;">
        <span class="site-loader"> </span>
        <h3 class="loader-content"> {{ __('Loading . . .') }} </h3>
    </div>

    @include('partision.settingPopup')
    @include('partision.footerlink')
    @stack('scripts')
    @stack('custom-script')
    @stack('custom-script1')
    <script type="text/javascript">
        function setActiveTheme(themeColor) {
            // Update the --active-theme-border variable based on the theme number
            document.documentElement.style.setProperty('--active-theme-border', `var(--bs-${themeColor}-border)`);
        }
        setActiveTheme("{{$themeColor}}");
        $(document).ready(function(){
            if ($('.select2').length > 0) {
                $('.select2').select2({
                    tags: true,
                    createTag: function (params) {
                      var term = $.trim(params.term);
                      if (term === '') {
                        return null;
                      }
                      return {
                        id: term,
                        text: term,
                        newTag: true
                      };
                    }
                });
            }
        })
        </script>
    <script>
        function add_more_choice_option(i, name) {
            // Create a safe CSS selector by removing special characters
            var safe_i = i.toString().replace(/[^a-zA-Z0-9_-]/g, '_');

        $('#attribute_options').append(
            '<div class="card oprtion"><div class="card-body "><input type="hidden" class="abd" name="attribute_no[]" value="' +
            i + '"><input type="hidden" class="abd" name="option_no[]" value="' + i + '">' +

            '<div class="row">' +
            '<div class="form-group col-lg-6 col-12">' +
            '<label for="choice_attributes" class="col-6">' + name + ':</label></div>' +

            '<div class="form-group col-lg-6 col-12 d-flex justify-content-end all-button-box">' +
            '<a href="#" class="btn btn-sm btn-primary add_attribute btn-badge" data-ajax-popup="true" data-title="{{ __('Add Attribute Option') }}" data-size="md" ' +
            'data-url="{{ route('product-attribute-option.create') }}/' + i + '" ' +
            'data-toggle="tooltip">' +
            '<i class="ti ti-plus">{{ __('Add Attribute Option') }}</i></a></div></div>' +

            '<div class="row parent-clase">' +
            '<div class="form-group col-12">' +
            '<div class="form-chec1k form-switch p-0">' +
            '<input type="hidden" name="visible_attribute_' + i + '" value="0">' +
            '<input type="checkbox" class="form-check-input attribute-form-check" name="visible_attribute_' + i +
            '" id="visible_attribute_' + safe_i + '" value="1">' +
            '<label class="form-check-label" for="visible_attribute_' + safe_i + '"></label>' +
            '<label for="visible_attribute_' + safe_i + '" class=""> Visible on the product page</label></div>' +

            ' <div style="margin-top: 9px;"></div>' +

            '<div class="for-variation_data form-chec1k form-switch p-0 d-none use_for_variation" id="use_for_variation_' + safe_i + '"  data-id="' +
            i + '">' +
            '<input type="hidden" name="for_variation_' + i + '" value="0">' +
            '<input type="checkbox" class="form-check-input input-options attribute-form-check enable_variation_' +
            safe_i + '" name="for_variation_' + i + '" id="for_variation_' + safe_i + '" value="1" data-id="' + i +
            '" data-enable-variation=" enable_variation_' + safe_i + ' ">' +
            '<label class="form-check-label" for="for_variation_' + safe_i + '"></label>' +
            '<label for="for_variation_' + safe_i + '" class=""> Used for variations</label></div>' +
            '</div>' +

            '<div class="form-group col-12">' +
            '<select class="col-6 form-control attribute attribute_option_data" name="attribute_options_' + i +
            '[]" __="{{ __('Enter choice values') }}"  data-role="" multiple id="attribute' + safe_i +
            '" data-id="' + i + '" required ></select></div></div>' +

            '</div></div>');

        if ($('.enable_product_variant').prop('checked') == true) {
            $(".use_for_variation").removeClass("d-none");
        }

        comman_function();
    }
    $(document).on('click', '.note-btn', function () {
        var ariaLabel = $(this).attr('aria-label');
        if (ariaLabel === 'Link (CTRL+K)' || ariaLabel === 'Edit') {
            $('body').addClass('note-link-unlink-sample');
        }
    });
    $(document).on('click', '.close, .note-link-btn', function () {
        $('body').removeClass('note-link-unlink-sample');
    });

    $('#sideBarsearch').on('keyup', function () {
        let value = $(this).val().toLowerCase().trim();

        if (value === "") {
            // If input is empty, show all
            $('.dash-navbar > .dash-item').show();
            $('.dash-submenu').hide();
            return;
        }

        $('.dash-navbar > .dash-item').each(function () {
            let $item = $(this);
            let isMatch = false;

            // Main menu match
            let mainText = $item.find('> .dash-link .dash-mtext').text().toLowerCase();
            if (mainText.includes(value)) {
                isMatch = true;
            }

            // Submenu match
            $item.find('.dash-submenu .dash-item').each(function () {
                let $subItem = $(this);
                let subText = $subItem.find('.dash-link').text().toLowerCase();

                if (subText.includes(value)) {
                    isMatch = true;
                    $subItem.show();
                } else {
                    $subItem.hide();
                }
            });

            if (isMatch) {
                $item.show();
                $item.find('.dash-submenu').show();
            } else {
                $item.hide();
            }
        });
    });
</script>
    @if (Session::has('success'))
        <script>
            {{-- P1-SEC-22 (b). This line used to interpolate the flashed message UNESCAPED, inside single
             quotes, into a JavaScript string literal -- so an apostrophe ended the literal and a closing
             script tag ended the block. An injection sink, not merely an HTML-escaping gap. Js::from()
             emits a JSON literal INCLUDING its own quotes, which is why the surrounding ones are gone.
             (The vulnerable construct is deliberately not spelled out here: a docblock that quotes what
             a ratchet greps for makes the ratchet satisfiable by prose, which this project records.) --}}
            show_toastr('{{ __('Success') }}', {{ \Illuminate\Support\Js::from(Session::get('success')) }}, 'success');
        </script>
        <?php Session::forget('success'); ?>
    @endif

    @if (Session::has('error'))
        <script>
            show_toastr('{{ __('Error') }}', {{ \Illuminate\Support\Js::from(Session::get('error')) }}, 'error');
        </script>
        <?php Session::forget('error'); ?>
    @endif


    @php
        $setting = getSuperAdminAllSetting();
    @endphp
    @if (isset($setting['enable_cookie']) && $setting['enable_cookie'] == 'on')
        @include('layouts.cookie_consent')
    @endif
</body>

</html>
