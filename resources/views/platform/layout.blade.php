{{--
    PHASE 03 M5 — THE OFFICIAL PLATFORM SITE'S CHROME.
    =============================================================================================

    The header, footer and document shell shared by every page of storesyria.com itself.

    THIS LAYOUT USES @yield AND IS EXTENDED WITH @extends. IT IS NOT A COMPONENT LAYOUT, AND THAT
    IS DELIBERATE. CLAUDE.md records the failure: `layouts/app.blade.php` and
    `layouts/guest.blade.php` both @yield and contain zero `$slot` references, so the two Breeze
    views still written in the `<x-*-layout>` shape rendered perfect chrome, HTTP 200 and an EMPTY
    CONTENT AREA in all four combinations. `tests/Unit/Ui/SlotLayoutViewsRenderTest` fails on that
    shape because the failure is otherwise invisible. Every page here @extends and @sections.

    NOTHING IS FETCHED FROM ANOTHER ORIGIN. No web font, no CDN, no analytics beacon, no icon
    pack -- the brand mark is a letter in a gradient box and the only two stylesheets are local.
    `PlatformAssetsAreLocalTest` fails the build if that stops being true. The merchant admin
    already carries a measured counter-example (jQuery UI loaded from the vendor's public demo
    site into authenticated sessions), so this is a rule with a scar behind it.

    WHAT IT DOES *NOT* TOUCH. The vendored WorkDo LandingPage module still owns `/`. This layout
    reads none of its entities: `Workdo\LandingPage\*` is autoloaded only once `loadAddons()` has
    run -- `composer.json` carries no PSR-4 entry for it, measured -- so a view that named one of
    those classes would be a fatal error wherever the module is off, which includes every test run
    that has not inserted an `add_on_managers` row.
--}}
@php
    use App\Support\Platform\PlatformCapabilities;

    /* The direction follows the ACTIVE LOCALE, not a stored setting. `setlocate` has already
       resolved it from the session, the cookie or the super admin's own language, and a public
       visitor has no row of their own to read. This is the rule `layouts/guest.blade.php:115`
       applies; it is restated here rather than shared because that layout reaches into the
       LandingPage entities at :133 and cannot be included from a module-independent page. */
    $locale = app()->getLocale() ?: 'en';
    $isRtl  = in_array($locale, ['ar', 'he'], true);

    $brand = config('platform.brand_name');

    /* `getSuperAdminAllSetting()` is wrapped: it reads `Setting` and a cached `User`, and the
       platform site must render on an installation where neither has been seeded yet. An
       exception here would turn the company's home page into a 500.

       AND IT IS FILTERED. Unlike `getAdminAllSetting()`, which routes all three of its returns
       through `withoutPageExposedCredentials()`, `getSuperAdminAllSetting()` returns the RAW bag --
       `helper.php:652-666` against `:717-755`. The helper exists because a vendored view printed a
       `password` row from that bag into a page; the Marketing module can write one for the super
       admin's own store. Nothing here prints the bag (only `cust_darklayout` is read), so there is
       no exposure today -- this is the one token that keeps it that way if somebody later dumps or
       iterates it on a PUBLIC page. The helper itself is deliberately NOT changed: it has roughly a
       hundred callers including currency formatting and payment paths, and filtering it centrally
       is the mistake `helper.php:691-697` warns against. */
    try {
        $siteSettings = withoutPageExposedCredentials(getSuperAdminAllSetting());
    } catch (\Throwable $e) {
        $siteSettings = [];
    }

    $dark = ($siteSettings['cust_darklayout'] ?? 'off') === 'on';

    /* The documentation centre is Phase 03 M6. Until its route exists the navigation must not
       call route() on a name the registry does not hold -- that is a RouteNotFoundException, not
       a broken link. Route::has() is the whole guard, and PlatformSiteRoutesTest drives both
       sides of it. */
    $docsUrl = \Illuminate\Support\Facades\Route::has('platform.docs.index')
        ? route('platform.docs.index')
        : null;

    $nav = [
        ['route' => 'platform.home',       'label' => 'Overview'],
        ['route' => 'platform.features',   'label' => 'Features'],
        ['route' => 'platform.pricing',    'label' => 'Pricing'],
        ['route' => 'platform.onboarding', 'label' => 'Getting started'],
        ['route' => 'platform.faq',        'label' => 'FAQ'],
        ['route' => 'platform.contact',    'label' => 'Contact'],
    ];

    $current = \Illuminate\Support\Facades\Route::currentRouteName();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}"
      dir="{{ $isRtl ? 'rtl' : 'ltr' }}"
      @if ($dark) data-ss-theme="dark" data-ss-theme-source="host" @endif>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', __('Overview')) — {{ $brand }}</title>
    <meta name="description" content="@yield('meta_description', __('Store Syria is a multi-store commerce platform for merchants in Syria: a themed online shop, a merchant dashboard and an admin app.'))">
    <meta name="robots" content="index,follow">
    <link rel="stylesheet" href="{{ asset('css/store-syria-ds.css') }}">
    <link rel="stylesheet" href="{{ asset('css/store-syria-platform.css') }}">
</head>
<body class="ss-ps">

<a class="ss-ps-skip" href="#ss-ps-main">{{ __('Skip to main content') }}</a>

<header class="ss-ps-header">
    <div class="ss-ps-wrap">
        <div class="ss-ps-header__bar">
            <a class="ss-ps-brand" href="{{ route('platform.home') }}">
                <span class="ss-ps-brand__mark" aria-hidden="true">S</span>
                <span>{{ $brand }}</span>
            </a>

            <span class="ss-ps-grow"></span>

            <nav class="ss-ps-header__nav" aria-label="{{ __('Primary') }}">
                <ul class="ss-ps-nav">
                    @foreach ($nav as $item)
                        <li>
                            <a class="ss-ps-nav__link"
                               href="{{ route($item['route']) }}"
                               @if ($current === $item['route']) aria-current="page" @endif>{{ __($item['label']) }}</a>
                        </li>
                    @endforeach
                    @if ($docsUrl)
                        <li><a class="ss-ps-nav__link" href="{{ $docsUrl }}">{{ __('Documentation') }}</a></li>
                    @endif
                </ul>
            </nav>

            <div class="ss-ps-header__cta">
                <a class="ss-ps-btn ss-ps-btn--primary" href="{{ url('login') }}">{{ __('Merchant sign in') }}</a>
            </div>

            {{-- A native <details>, so the small-screen menu needs NO JAVASCRIPT: the browser
                 supplies the toggle, the keyboard behaviour, the expanded state and the
                 screen-reader announcement. The panel is a CHILD of the <details>, which is the
                 only arrangement in which it opens without script -- an earlier draft put it in a
                 sibling <div> with `hidden`, where nothing could ever have revealed it. --}}
            <details class="ss-ps-burger">
                <summary>{{ __('Menu') }}</summary>
                <div class="ss-ps-burger__panel">
                    <nav aria-label="{{ __('Primary') }}">
                        <ul class="ss-ps-nav">
                            @foreach ($nav as $item)
                                <li><a class="ss-ps-nav__link" href="{{ route($item['route']) }}">{{ __($item['label']) }}</a></li>
                            @endforeach
                            @if ($docsUrl)
                                <li><a class="ss-ps-nav__link" href="{{ $docsUrl }}">{{ __('Documentation') }}</a></li>
                            @endif
                        </ul>
                    </nav>
                    <a class="ss-ps-btn ss-ps-btn--primary ss-ps-burger__cta" href="{{ url('login') }}">{{ __('Merchant sign in') }}</a>
                </div>
            </details>
        </div>
    </div>
</header>

<main class="ss-ps-main" id="ss-ps-main">
    @yield('content')
</main>

<footer class="ss-ps-footer">
    <div class="ss-ps-wrap">
        <div class="ss-ps-footer__cols">
            <div>
                <h2 class="ss-ps-footer__title">{{ __('Platform') }}</h2>
                <ul class="ss-ps-footer__list">
                    <li><a href="{{ route('platform.home') }}">{{ __('Overview') }}</a></li>
                    <li><a href="{{ route('platform.features') }}">{{ __('Features') }}</a></li>
                    <li><a href="{{ route('platform.pricing') }}">{{ __('Pricing') }}</a></li>
                </ul>
            </div>
            <div>
                <h2 class="ss-ps-footer__title">{{ __('Get started') }}</h2>
                <ul class="ss-ps-footer__list">
                    <li><a href="{{ route('platform.onboarding') }}">{{ __('Getting started') }}</a></li>
                    <li><a href="{{ url('login') }}">{{ __('Merchant sign in') }}</a></li>
                </ul>
            </div>
            <div>
                <h2 class="ss-ps-footer__title">{{ __('Support') }}</h2>
                <ul class="ss-ps-footer__list">
                    <li><a href="{{ route('platform.faq') }}">{{ __('FAQ') }}</a></li>
                    <li><a href="{{ route('platform.contact') }}">{{ __('Contact') }}</a></li>
                    @if ($docsUrl)
                        <li><a href="{{ $docsUrl }}">{{ __('Documentation') }}</a></li>
                    @endif
                </ul>
            </div>
            <div>
                <h2 class="ss-ps-footer__title">{{ __('Legal') }}</h2>
                <ul class="ss-ps-footer__list">
                    <li><a href="{{ route('platform.legal') }}">{{ __('Legal information') }}</a></li>
                </ul>
            </div>
        </div>

        <div class="ss-ps-footer__base">
            <span>&copy; {{ date('Y') }} {{ $brand }}</span>
            <span class="ss-ps-grow"></span>
            <nav aria-label="{{ __('Language') }}">
                <ul class="ss-ps-nav">
                    <li>
                        <a class="ss-ps-nav__link"
                           href="{{ route('changelanguage', 'en') }}"
                           lang="en"
                           @if (! $isRtl) aria-current="true" @endif>English</a>
                    </li>
                    <li>
                        <a class="ss-ps-nav__link"
                           href="{{ route('changelanguage', 'ar') }}"
                           lang="ar"
                           @if ($isRtl) aria-current="true" @endif>العربية</a>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</footer>

</body>
</html>
