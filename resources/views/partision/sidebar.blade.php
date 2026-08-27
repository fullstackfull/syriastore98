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
                <div class="d-flex align-items-center sidebar-input position-relative">
                    <i class="ti ti-search"></i>
                    <input type="text" class="form-control" id="sideBarsearch" placeholder="{{ __('Search here...') }}">
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