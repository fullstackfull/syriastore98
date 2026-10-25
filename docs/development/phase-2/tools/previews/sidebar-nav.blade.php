@extends('layouts.app')

{{--
    Preview for P2-01-10: the sidebar navigation, rendered from menuTree() data
    through resources/views/partision/menu-tree.blade.php.

    It reproduces the real chrome the nav sits in -- `nav.dash-sidebar` >
    `.navbar-wrapper` > `.navbar-content` > `ul.dash-navbar` -- from
    partision/sidebar.blade.php, so the HOST's own sidebar CSS applies. The
    point is to see the markup the refactor emits under the real stylesheets,
    including the submenu nesting the inline sidebar search depends on.

    THE `transprent-bg` BRANCH IS NOT COSMETIC. partision/sidebar.blade.php:15
    picks between two navs on `$setting['cust_theme_bg']`, and that setting
    defaults to 'on' in four places (Utility.php:44, helper.php:1106 and 1181,
    DefaultSetting.php:32, plus app.blade.php:17 when the stored value is
    empty). The first version of this preview hard-coded the `else` branch --
    no `transprent-bg` -- and so rendered `.dash-sidebar`'s own
    `background: #1c232f` (style.css) behind `.dash-sidebar .dash-link`'s
    `color: #333333` (custom.css): 1.25:1, which looked like a defect in this
    slice and was not. Both branches are rendered now, and the off branch is
    kept because it is reachable from the settings UI
    (SettingController.php:487) and really does measure 1.25:1 -- recorded as
    a pre-existing host-chrome finding, not introduced here.

    Not routed, not a merchant screen.
--}}

@section('page-title', 'Sidebar navigation')
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush
@section('breadcrumb')
    <li class="breadcrumb-item">Sidebar navigation</li>
@endsection

@section('content')
{{-- The page background the real sidebar sits on: --bs-body-bg, #f8f9fd in
     light mode. The nav is inside the content area here rather than in the
     page shell, so without this the transparent branch would be measured
     against the content card instead of the page. --}}
<div style="background: var(--bs-body-bg); padding: 16px;">
{{-- Mirrors partision/sidebar.blade.php:15-19 exactly. --}}
@if ($custThemeBg === 'on')
<nav class="dash-sidebar light-sidebar transprent-bg" style="position: static; width: 280px;">
@else
<nav class="dash-sidebar light-sidebar" style="position: static; width: 280px;">
@endif
    <div class="navbar-wrapper">
        <div class="navbar-content">
            <div class="d-flex align-items-center sidebar-input position-relative">
                <i class="ti ti-search"></i>
                <input type="text" class="form-control" id="sideBarsearch" placeholder="{{ __('Search here...') }}">
            </div>
            <ul class="dash-navbar">
                @include('partision.menu-tree', ['nodes' => $nodes])
            </ul>
        </div>
    </div>
</nav>
</div>
@endsection
