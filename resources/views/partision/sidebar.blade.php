@php
$logo = asset(Storage::url('uploads/logo/'));
$company_logo = \App\Models\Utility::GetLogo();
$company_logo = get_file($company_logo);
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
                <a href="{{ route('dashboard') }}" class="b-brand">
                    <!-- ========   change your logo hear   ============ -->
                    <img src="{{ isset($company_logo) && !empty($company_logo) ? $company_logo . '?timestamp=' . time() : $logo . '/logo-dark.svg' . '?timestamp=' . time() }}"
                        alt="" class="logo logo-lg" />
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