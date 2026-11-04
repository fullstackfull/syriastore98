@php
$logo = asset(Storage::url('uploads/logo/'));
$company_logo = \App\Models\Utility::GetLogo();
$company_logo = get_file($company_logo);

/* P2.5-DESIGN-B. THE BRAND BLOCK RENDERED NOTHING, AND IT IS THE FIRST THING ON EVERY SCREEN.
   -------------------------------------------------------------------------------------------
   MEASURED on the running admin, on all four screens probed: the <img> resolved to
   `/storage/uploads/logo/logo-dark.png`, which answered 404, and the element reported
   naturalWidth 0, naturalHeight 0 and a 0x0 box. So the top of the sidebar was ~75px of nothing
   on every page, in both themes -- not a faint logo, no logo.

   Three separate defects sat in one line of markup:

     1. THE FALLBACK FILE DOES NOT EXIST. `Utility::GetLogo()` reads the merchant's `logo_dark` /
        `logo_light` SETTING, and a store that has never uploaded one gets a path to a file that
        was never shipped. That is the normal state of a new tenant.
     2. `?timestamp=' . time()` ON EVERY REQUEST. A fresh query string each page load means the
        browser can never reuse the logo -- the one image that appears on every screen was the one
        guaranteed to be re-fetched. The merchant's own upload still needs busting when it changes,
        so it keeps a cache key, but one derived from the FILE rather than from the clock.
     3. `alt=""` ON THE ONLY CONTENT OF A LINK. An image-only link with an empty alt has no
        accessible name at all, so the first control in the tab order announced as nothing. The
        link is named here and the images stay decorative, which is the correct pairing -- naming
        both would announce it twice.

   THE FALLBACK IS THE REAL STORE SYRIA LOCKUP, from docs/design-system/brand (owner decision
   D-41: "the actual blue/cyan logo identity"). Light and dark variants are both emitted and CSS
   picks one, rather than Blade picking from `$setting['cust_darklayout']`: the theme can also be
   switched client-side by store-syria-ds.js, and a server-rendered choice would be stale until
   the next page load. The collapsed rail shows the mark alone -- see .ss-brandmark in
   store-syria-ds.css. */
/* "HAS THE MERCHANT UPLOADED A LOGO" IS NOT "IS THE SETTING NON-EMPTY", and the first draft of
   this block got that wrong -- it tested `! empty($company_logo)` and still rendered the 404.
   `get_file()` returns `url($path)` for local storage with NO existence check, so a store whose
   `logo_dark` setting names a file that was never uploaded gets a perfectly well-formed URL to
   nothing. That is exactly the dev tenant's state, and the probe caught it: all four
   combinations still showed naturalWidth 0.
   So the local file is stat-ed. Remote storage (s3 / wasabi) cannot be stat-ed from here and is
   trusted: a merchant configured for remote storage whose settings name a logo has one. */
$ssHasOwnLogo = false;
$ssBrandBust = null;
if (! empty($company_logo)) {
    $ssBrandHost = parse_url($company_logo, PHP_URL_HOST);

    if (empty($ssBrandHost) || $ssBrandHost === request()->getHost()) {
        $ssBrandFile = public_path(ltrim((string) parse_url($company_logo, PHP_URL_PATH), '/'));
        $ssHasOwnLogo = is_file($ssBrandFile);
        $ssBrandBust = $ssHasOwnLogo ? filemtime($ssBrandFile) : null;
    } else {
        $ssHasOwnLogo = true;
    }
}
@endphp
<!-- [ Pre-loader ] start -->
<div class="loader-bg">
    <div class="loader-track">
        <div class="loader-fill"></div>
    </div>
</div>

<!-- [ Pre-loader ] End -->
<!-- [ navigation menu ] start -->
@if (isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on')
<nav class="dash-sidebar light-sidebar transprent-bg">
    @else
    <nav class="dash-sidebar light-sidebar">
        @endif
        <div class="navbar-wrapper">
            <div class="m-header">
                <a href="{{ route('dashboard') }}" class="b-brand ss-brandmark"
                   aria-label="{{ __('Store Syria') }} — {{ __('Dashboard') }}">
                    @if ($ssHasOwnLogo)
                        {{-- The merchant's own upload still wins, and still busts its cache when it
                             changes -- on the file's mtime rather than on the clock. --}}
                        <img src="{{ $company_logo }}{{ $ssBrandBust ? '?v=' . $ssBrandBust : '' }}"
                             alt="" class="logo logo-lg" />
                    @else
                        <img src="{{ asset('assets/images/brand/store-syria-lockup.png') }}"
                             alt="" width="280" height="92" decoding="async"
                             class="logo logo-lg ss-brandmark__lockup ss-brandmark__lockup--light" />
                        <img src="{{ asset('assets/images/brand/store-syria-lockup-dark.png') }}"
                             alt="" width="280" height="92" decoding="async"
                             class="logo logo-lg ss-brandmark__lockup ss-brandmark__lockup--dark" />
                        <img src="{{ asset('assets/images/brand/store-syria-mark.png') }}"
                             alt="" width="40" height="56" decoding="async"
                             class="ss-brandmark__mark" />
                    @endif
                </a>
            </div>

            <div class="navbar-content">
                {{-- P2-01-11. Both of these sit OUTSIDE ul.dash-navbar on
                     purpose: anything inside it that is not a `.dash-item`
                     would be stepped over by the sidebar search, and anything
                     that IS one would be treated as a navigation entry. --}}
                @include('partision.workspace-chip')

                <div class="d-flex align-items-center sidebar-input position-relative">
                    <i class="ti ti-search"></i>
                    {{-- AN EXPLICIT `aria-label` ALONGSIDE THE PLACEHOLDER, and the reason is narrower than
                         it first looked -- MEASURED IN A BROWSER, which refuted my first justification.

                         An audit of this phase called this field nameless, and I repeated that. It was
                         NOT. A live probe asserted that removing `aria-label` would stop the engine
                         resolving a name, and that assertion FAILED in both Arabic and English: Chromium
                         computes a name from `placeholder` for a text input, which is step 2(f) of the
                         accname fallback and correct behaviour rather than a browser bug.

                         SO THIS IS A ROBUSTNESS FIX, NOT THE REPAIR OF A NAMELESS CONTROL, and the two
                         things it actually buys are both driven in
                         `tools/check-sidebar-search-name-live.cjs` (15 checks, AR/RTL and EN/LTR):

                           (1) the name is EXPLICIT and wins the precedence order, so it no longer
                               depends on a fallback that older assistive technology implements
                               inconsistently -- proven by setting the placeholder to a DIFFERENT string
                               and watching the computed name still follow the label;
                           (2) the name PERSISTS WHILE THE USER TYPES, where a placeholder is visually
                               replaced by the user's own text -- the moment they most need to confirm
                               what they are typing into.

                         The visible placeholder stays, because it still helps sighted users. --}}
                    <input type="text" class="form-control" id="sideBarsearch"
                        aria-label="{{ __('Search here...') }}" placeholder="{{ __('Search here...') }}">
                    {{-- The collapsed rail is NOT new code. public/assets/js/dash.js:71
                         has listened for #vertical-nav-toggle since before this
                         phase, and style.css / style-dark.css / style-rtl.css
                         carry 38 `.minimenu` rules each. The trigger was simply
                         never rendered in any view, so the whole feature was
                         unreachable. This renders it; store-syria-ds.js
                         remembers the state. --}}
                    {{-- The two labels are rendered server-side so they are
                         TRANSLATED. store-syria-nav.js swaps between them as
                         the state changes; a label built in JavaScript would
                         be English for every Arabic merchant. --}}
                    {{-- ti-layout-sidebar, not ti-layout-sidebar-left-collapse:
                         the latter is NOT in this build of the icon font
                         (public/assets/fonts/tabler-icons.min.css has no
                         `.ti-layout-sidebar-left-collapse:before`), so it
                         rendered a correctly-sized, correctly-positioned,
                         completely EMPTY 30x30 box. A probe reported it
                         visible; only the screenshot showed it was blank. --}}
                    <button type="button" id="vertical-nav-toggle" class="ss-rail-toggle"
                            aria-pressed="false" title="{{ __('Collapse menu') }}"
                            data-ss-collapse="{{ __('Collapse menu') }}"
                            data-ss-expand="{{ __('Expand menu') }}">
                        <span class="ss-visually-hidden">{{ __('Collapse menu') }}</span>
                        <i class="ti ti-layout-sidebar" aria-hidden="true"></i>
                    </button>
                </div>
                <ul class="dash-navbar @if(module_is_active('SidebarCustomization')) collapse-sidebar @endif">
                    @if(module_is_active('SidebarCustomization'))
                    {!! getSideMenu() !!}
                    @else
                    {!! getMenu() !!}
                    @endif
                </ul>
            </div>
        </div>
    </nav>
    <!-- [ navigation menu ] end -->
    <script>
    function removeCollapseClass() {
        var navbar = document.querySelector('.dash-navbar');
        if (navbar && navbar.classList.contains('collapse-sidebar')) {
            navbar.classList.remove('collapse-sidebar');
        }
    }

    function checkScreenWidth() {
        if (window.innerWidth <= 1024) {
            removeCollapseClass();
        }
    }

    window.addEventListener('load', checkScreenWidth);
    window.addEventListener('resize', checkScreenWidth);
    </script>