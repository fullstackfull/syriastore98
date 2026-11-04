@extends('layouts.app')

@section('page-title', __('Dashboard'))

{{-- P2-02: opt into the Store Syria design system. @stack('css') is the last
     stylesheet position in <head> (layouts/app.blade.php:176), so nothing
     below needs !important and no other screen is affected. --}}
@push('css')
    @include('layouts.includes.store-syria-ds')
@endpush

@section('action-button')
    <div class="ss-scope ss-page-header__actions justify-content-md-end justify-content-center">
        @permission('Create Product')
            <a href="{{ route('product.create') }}" class="ss-btn ss-btn--primary ss-btn--sm">
                <i class="ti ti-plus" aria-hidden="true"></i>
                <span>{{ __('Add Product') }}</span>
            </a>
        @endpermission
        @permission('Manage Order')
            <a href="{{ route('order.index') }}" class="ss-btn ss-btn--secondary ss-btn--sm">
                <i class="ti ti-shopping-cart" aria-hidden="true"></i>
                <span>{{ __('Orders') }}</span>
            </a>
        @endpermission
    </div>
@endsection

@section('breadcrumb')
@endsection

{{-- WS C — "STORE OVERVIEW", the master prompt's first dashboard bullet, and the one region
     the page genuinely lacked.

     WHY HERE AND NOT AS A CARD. The store's name already appears twice further down — on the
     theme card's badge and as the QR card's heading — and this slice has just deleted a
     duplicate order list for being a duplicate. A third copy of the name in a card of its own
     would be the same mistake. What was missing is not the NAME, it is the page saying WHICH
     STORE this dashboard is about, at the top, before any figure: a merchant with two stores
     reads these numbers without ever being told whose they are.

     So it goes in the page header, next to "Dashboard", which is where
     `x-ss.page-header`'s own docblock has said the store name belongs since P2-01-11.

     THE STOREFRONT LINK IS `rel="noopener"` and opens in a new tab: it leaves the admin for the
     public site, and `target="_blank"` without `noopener` hands the opened page a handle on this
     one. --}}
@section('page-subtitle')
    {{ $store->name ?? __('No store selected') }}
    @isset($theme_url)
        <a href="{{ $theme_url }}" class="ss-link" target="_blank" rel="noopener noreferrer">
            {{ __('View storefront') }}
        </a>
    @endisset
@endsection

@php
    /* The nine inline SVG icon strings that used to live here fed the eight
       <x-dashboard-card> tiles that P2-02 replaced. They were 9 KB of markup
       in a @php block and nothing references them any more. */
    $setting = getAdminAllSetting();
@endphp

@section('content')
    <div class="row">
        <!-- [ sample-page ] start -->
        <div class="col-12">
            <div class="row mb-4 dash-order-status g-4">
                {{-- P2-02 replaces eight equally-weighted count tiles with a
                     worklist, a boxless stat grid and a status breakdown. The
                     eight tiles reported the same six status counts the
                     breakdown now does, with no period on any of them and no
                     way to open the list a count referred to. See
                     docs/development/phase-2/design/P2-DESIGN-FOUNDATION.md §3a.

                     THAT WAS TRUE UNTIL WS C AND IS NOT ANY MORE, so the claim
                     is corrected rather than left to mislead. P2-02 left
                     everything below this block untouched — which is how the
                     page came to show the SAME order list twice, once gated on
                     `Manage Order` by the region above and once not gated at
                     all by the markup below. WS C deleted that duplicate,
                     gated the two "By Sales" cards and the latest-products
                     list on the same permissions the region above uses, and
                     gave the period tabs ids that do not collide. The theme
                     card, the storage card and the chart are unchanged in
                     content; the storage card's WORDING changed, because it
                     was stating a quota it did not have. --}}
                <div class="col-12">
                    @include('dashboard.overview')
                </div>
                <div class="col-12 admin-cards">
                    <div class="row g-4">
                        <div class="col-xxl-4 col-12 theme-card">
                            <div class="dashboard-theme-card  dashboard-card">
                                <div class="theme-image buisness-img">
                                    <span class="card-badge btn btn-sm btn-primary btn-badge">{{ ucfirst($store->name ?? '')
                                                                            }}</span>
                                    <img src="{{ asset('themes/' . APP_THEME() . '/theme_img/img_1.png') }}"
                                        alt="theme-image">
                                </div>
                                <div class="theme-card-button">
                                    <a class="btn btn-outline-primary btn-badge h-100 d-flex align-items-center justify-content-center"
                                        href="{{ route('theme.pages', APP_THEME()) }}">
                                        {{ __('Customize') }}
                                    </a>
                                    <a class="btn btn-primary d-flex align-items-center justify-content-center"
                                        href="{{ route('theme.index') }}">
                                        {{ __('Manage Themes') }}
                                    </a>
                                </div>
                            </div>
                        </div>
                        {{-- WS C. GATED, AND THE GATE IS THE POINT.

                             Both cards report SUM(orders.final_price) grouped by category and by
                             brand -- the same money the overview region above withholds from a
                             principal without `Manage Order`. `/dashboard` carries no permission
                             middleware, so every member of the tenant reached this markup: a
                             staff member whose role grants only Manage Blog was shown the
                             overview WITHOUT "Order value" and then these two cards WITH the
                             store's order revenue, broken down, a few hundred pixels lower.

                             One definition answers both now
                             (App\Support\MerchantDashboardAbilities), so the page cannot
                             disagree with itself about who may see order money again. --}}
                        @if (\App\Support\MerchantDashboardAbilities::mayManageOrders())
                        <div class="col-xxl-4 col-12">
                            <div class="dashboard-card">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                                    <h4 class="m-0">{{ __('Top Category') }}</h4>
                                    <p class="m-0">{{ __('By Sales') }}</p>
                                </div>
                                <div class="dash-category-tab" id="useradd-sidenav">
                                    <ul class="nav nav-pills w-100 pro-cat row store-setting-tab" id="pills-cat-tab"
                                        role="tablist">
                                        <li class="nav-item  col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#all-category-order" class="nav-link btn-sm f-w-600 active"
                                                id="all-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#category-tab-data" type="button" role="tab"
                                                aria-controls="category-tab-data" aria-selected="true">
                                                {{ __('All') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-category-order" class="nav-link btn-sm f-w-600"
                                                id="today-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#category-tab-data" type="button" role="tab"
                                                aria-controls="category-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Today') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-category-order" class="nav-link btn-sm f-w-600"
                                                id="week-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#category-tab-data" type="button" role="tab"
                                                aria-controls="category-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Week') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-category-order" class="nav-link btn-sm f-w-600"
                                                id="month-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#category-tab-data" type="button" role="tab"
                                                aria-controls="category-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Month') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-category-order" class="nav-link btn-sm f-w-600"
                                                id="year-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#category-tab-data" type="button" role="tab"
                                                aria-controls="category-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Year') }}
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="category-tab-data" class="tab-pane fade show active" role="tabpanel"
                                             aria-labelledby="all-category-tab"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-xxl-4 col-12">
                            <div class="dashboard-card">
                                <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
                                    <h4 class="m-0">{{ __('Top Brand') }}</h4>
                                    <p class="m-0">{{ __('By Sales') }}</p>
                                </div>
                                <div class="dash-brand-tab" id="brand-sidenav">
                                    <ul class="nav nav-pills w-100 pro-cat row store-setting-tab" id="pills-brand-tab"
                                        role="tablist">
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#all-brand-order" class="nav-link btn-sm f-w-600 active"
                                                id="all-brand-tab" data-bs-toggle="pill"
                                                data-bs-target="#brand-tab-data" type="button" role="tab"
                                                aria-controls="brand-tab-data" aria-selected="true">
                                                {{ __('All') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-brand-order" class="nav-link btn-sm f-w-600"
                                                id="today-brand-tab" data-bs-toggle="pill"
                                                data-bs-target="#brand-tab-data" type="button" role="tab"
                                                aria-controls="brand-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Today') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-brand-order" class="nav-link btn-sm f-w-600"
                                                id="week-brand-tab" data-bs-toggle="pill"
                                                data-bs-target="#brand-tab-data" type="button" role="tab"
                                                aria-controls="brand-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Week') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-brand-order" class="nav-link btn-sm f-w-600"
                                                id="month-brand-tab" data-bs-toggle="pill"
                                                data-bs-target="#brand-tab-data" type="button" role="tab"
                                                aria-controls="brand-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Month') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-brand-order" class="nav-link btn-sm f-w-600"
                                                id="year-brand-tab" data-bs-toggle="pill"
                                                data-bs-target="#brand-tab-data" type="button" role="tab"
                                                aria-controls="brand-tab-data" aria-selected="false" tabindex="-1">
                                                {{ __('Year') }}
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="brand-tab-data" class="tab-pane fade show active" role="tabpanel"
                                             aria-labelledby="all-brand-tab"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            <div class="row g-4 admin-cards">
                <div class="col-xxl-4 col-12">
                    <div class="row g-4">
                        <div class="col-12">
                            <div class="dashboard-card">
                                <div class="card-content-info d-flex gap-3 flex-wrap flex-column flex-sm-row">
                                    <div
                                        class="d-flex align-items-center align-items-sm-start gap-3 flex-1 flex-column flex-sm-row">
                                        <a href="{{ !empty(auth()->user()->profile_image) && file_exists(auth()->user()->profile_image) ? get_file(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                            target="_blank" class="user-img">
                                            <img src="{{ !empty(auth()->user()->profile_image) && file_exists(auth()->user()->profile_image) ? get_file(auth()->user()->profile_image) : asset(Storage::url('uploads/profile/avatar.png')) }}"
                                                alt="user-image">
                                        </a>
                                        <div class="user-info">
                                            <h4 class="mb-2">{{ __(auth()->user()->name) }}
                                            </h4>
                                            <p>{{ __('Have a nice day! Did you know that you can quickly add your favorite product or category to the theme?') }}
                                            </p>
                                            <div
                                                class="d-flex align-items-center gap-2 flex-wrap justify-content-center justify-content-sm-start">
                                                <div class="dropdown quick-add-btn">
                                                    <a class="btn btn-primary btn-sm btn-q-add btn-badge gap-1"
                                                        data-bs-toggle="dropdown" href="#" role="button"
                                                        aria-haspopup="false" aria-expanded="false">
                                                        <i class="ti ti-plus"></i>
                                                        <span>{{ __('Quick Add') }}</span>
                                                    </a>
                                                    <div class="dropdown-menu">
                                                        <a href="{{ route('product.create') }}" data-size="lg"
                                                            data-title="{{ __('Add Product') }}"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span>{{ __('Add New Product') }}</span></a>
                                                        <a href="#" data-size="md" data-url="{{ route('taxes.create') }}"
                                                            data-ajax-popup="true" data-title="{{ __('Create Tax') }}"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span>{{__('Add New Tax') }}</span></a>
                                                        <a href="#" data-size="md" data-url="{{ route('category.create') }}"
                                                            data-ajax-popup="true"
                                                            data-title="{{ __('Create Main Category') }}"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top"><span>{{ __('Add New Main Category') }}</span></a>
                                                        <a href="#" data-size="md" data-url="{{ route('coupon.create') }}"
                                                            data-ajax-popup="true" data-title="{{ __('Create Coupon') }}"
                                                            class="dropdown-item text-wrap"
                                                            data-bs-placement="top "><span>{{__('Add New Coupon') }}</span></a>
                                                    </div>
                                                </div>
                                                <a href="#" id="socialShareButton"
                                                    class="socialShareButton btn btn-sm btn-outline-primary btn-badge">
                                                    <i class="ti ti-share"></i>
                                                </a>
                                                <a href="#" class="btn btn-outline-primary btn-sm cp_link btn-badge"
                                                    data-link="{{ $theme_url }}" data-bs-toggle="tooltip"
                                                    data-bs-placement="top" title="{{ __('Click to copy link') }}">
                                                    {{ __('Theme Link') }}
                                                    <i class="ms-1" data-feather="copy"></i>
                                                </a>

                                                <div id="sharingButtonsContainer" class="sharingButtonsContainer"
                                                    style="display: none;">
                                                    <div
                                                        class="Demo1 d-flex align-items-center justify-content-center mb-5 hidden">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="qr-code-wrp">
                                        <h5 class="text-capitalize text-center">{{ $store->name }}</h5>
                                        <div class="code-img">
                                            {!! QrCode::generate($theme_url) !!}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="dashboard-card">
                                @php
                                    /* WS C — THE CARD USED TO STATE A QUOTA IT DID NOT HAVE.
                                     *
                                     * MEASURED IN THE BROWSER, not reasoned about: the dev
                                     * fixture's plan is on WorkDo's UNLIMITED sentinel and the
                                     * card rendered
                                     *
                                     *     Storage Status (MB / -1MB)
                                     *
                                     * — an empty used figure and a NEGATIVE quota — above a donut
                                     * reading 0% Used. Three statements, none of them true.
                                     *
                                     * `-1` IS "UNLIMITED" and this codebase already knows it:
                                     * `Utility::updateStorageLimit()` refuses an upload when
                                     * `$plan->storage_limit <= $total_storage && $plan->storage_limit != -1`
                                     * (Utility.php:812), so -1 is the value that never refuses.
                                     * A plain `?? 0` turned that into a quota of zero already
                                     * exceeded; `> 0` alone would turn it into "no limit
                                     * recorded", which is a DIFFERENT statement from "no limit".
                                     *
                                     * Three states, three sentences:
                                     *   quota      ->  "12MB / 500MB"      + the percentage donut
                                     *   unlimited  ->  "12 MB used of unlimited storage", no donut
                                     *                  (a percentage of unlimited is not a number)
                                     *   unrecorded ->  "12 MB used", no donut
                                     *
                                     * The donut itself only renders for the first, because
                                     * HomeController:401-408 leaves `$storage_limit` at 0 for the
                                     * other two — so showing it there is showing a 0% that means
                                     * "not computed". */
                                    $ssStorageUsed      = (float) ($users->storage_limit ?? 0);
                                    $ssStorageQuota     = (float) ($plan->storage_limit ?? 0);
                                    $ssStorageUnlimited = $plan !== null && (int) $ssStorageQuota === -1;
                                    $ssStorageKnown     = $plan !== null && $ssStorageQuota > 0;
                                @endphp
                                <div class="card-header">
                                    {{-- `@else(` WOULD HAVE EATEN THIS. Blade's statement pattern
                                         treats the parenthesised group after a directive as its
                                         expression, so `@else({{ … }})` compiles to a bare `else:`
                                         and DISCARDS the text -- measured: the <small> rendered
                                         empty. Hence the explicit variable. --}}
                                    @php
                                        if ($ssStorageKnown) {
                                            $ssStorageNote = $ssStorageUsed . 'MB / ' . $ssStorageQuota . 'MB';
                                        } elseif ($ssStorageUnlimited) {
                                            $ssStorageNote = __(':used MB used of unlimited storage', ['used' => $ssStorageUsed]);
                                        } else {
                                            $ssStorageNote = __(':used MB used', ['used' => $ssStorageUsed]);
                                        }
                                    @endphp
                                    <h4>{{ __('Storage Status') }}<small>({{ $ssStorageNote }})</small></h4>
                                </div>
                                <div class="card-body">
                                    @if ($ssStorageKnown)
                                        <div id="device-chart"></div>
                                    @elseif ($ssStorageUnlimited)
                                        <p class="m-0 text-muted">{{ __('This plan includes unlimited storage, so there is no percentage to show.') }}</p>
                                    @else
                                        <p class="m-0 text-muted">{{ __('This plan records no storage limit, so there is no percentage to show.') }}</p>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- WS C: gated on `Manage Products` for the same reason the two sales
                     cards above are gated on `Manage Order` -- the overview region withholds
                     its product figures and its best-selling list from a principal without
                     that permission, and this list (newest products, WITH prices) sat below
                     it ungated. Not deleted: newest-first is a different question from
                     best-selling and no other region on the page answers it. --}}
                @if (\App\Support\MerchantDashboardAbilities::mayManageProducts())
                {{-- latest product --}}
                <div class="col-xxl-4 col-12">
                    <div class="overflow-auto dashboard-card">
                        <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                            <h4 class="m-0">{{ __('Latest Products') }}</h4>
                            @if ($latests && count($latests) > 0)
                                <a class="text-primary" href="{{ route('product.index') }}">{{ __('View All') }}</a>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table dataTable">
                                <thead>
                                    <tr>
                                        <th>
                                            {{ __('Image') }}
                                        </th>
                                        <th scope="col" class="sort" data-sort="name">
                                            {{ __('Product') }}
                                        </th>

                                        <th scope="col" class="sort text-right" data-sort="completion">
                                            {{ __('Price') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if (isset($latests) && count($latests) > 0)
                                        @foreach ($latests as $latest)
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <a href="{{ route('product.index') }}" target="_blank"
                                                            class="rounded-1 border overflow-hidden border border-primary border-2 rounded">
                                                            @if ($latest->cover_image_path)
                                                                <img src="{{ get_file($latest->cover_image_path) }}" width="100"
                                                                    class="cover_img1" alt="images">
                                                            @endif
                                                        </a>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <h6 class="m-0">{{ $latest->name }}
                                                        </h6>
                                                    </div>
                                                </td>
                                                @if ($latest->variant_product == 0)

                                                                        <td>
                                                                            <h6 class="m-0">
                                                                                {{ currency_format_with_sym($latest->final_price ?? 0, getCurrentStore()) ??
                                                    SetNumberFormat($latest->final_price) }}
                                                                            </h6>
                                                                        </td>
                                                @else

                                                    <td>
                                                        <h6 class="m-0">{{ __('In Variant') }}</h6>
                                                    </td>
                                                @endif
                                            </tr>
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('custom-script')
    @if (auth()->user()->type != 'superadmin')
        <script>
            $(document).ready(function () {
                $('.cp_link').on('click', function () {
                    var value = $(this).attr('data-link');
                    var $temp = $("<input>");
                    $("body").append($temp);
                    $temp.val(value).select();
                    document.execCommand("copy");
                    $temp.remove();
                    show_toastr('Success', '{{ __("Link copied") }}', 'success')
                });
            });
        </script>
    @endif
    <script>

        // new-chart-js-start
        $(document).ready(function () {
            var options = {
                series: [{{ round($storage_limit, 2) }}],
                chart: {
                    height: 550,
                    type: 'radialBar',
                    offsetY: -20,
                    sparkline: {
                        enabled: true
                    },
                    toolbar: {
                        show: false
                    },
                },
                plotOptions: {
                    radialBar: {
                        startAngle: -90,
                        endAngle: 90,
                        track: {
                            background: "var(--third-color)",
                            strokeWidth: '97%',
                            margin: 5, // margin is in pixels
                        },
                        dataLabels: {
                            name: {
                                show: true,
                                fontSize: '16px',
                            },
                            value: {
                                offsetY: -50,
                                fontSize: '20px'
                            }
                        }
                    }
                },
                grid: {
                    padding: {
                        top: -10
                    }
                },
                colors: ["var(--theme-color)"],
                labels: [@json(__('Used'))],
                responsive: [{
                    breakpoint: 600,
                    options: {
                        chart: {
                            height: 300,
                        },
                        plotOptions: {
                            radialBar: {
                                startAngle: -90,
                                endAngle: 90,
                                size: '70%',
                            }
                        },
                        dataLabels: {
                            value: {
                                offsetY: -40,
                                fontSize: '18px',
                            },
                        }
                    }
                },
                {
                    breakpoint: 1024,
                    options: {
                        chart: {
                            height: 450,
                        },
                        plotOptions: {
                            radialBar: {
                                size: '80%',
                            }
                        },
                        dataLabels: {
                            value: {
                                offsetY: -45,
                                fontSize: '18px',
                            },
                        }
                    }
                }
                ]
            };

            var ssStorageChartEl = document.querySelector("#device-chart");
            if (ssStorageChartEl) {
                var chart = new ApexCharts(ssStorageChartEl, options);
                chart.render();
            }
        });


        // Assuming this value is injected from your backend
        var defaultTimezone = "{{ $setting['defult_timezone'] ?? 'Asia/Kolkata' }}"; // Example: -5 for EST

        // Function to get the current hour in the specified timezone
        function getHourInTimezone(timezone) {
            var date = new Date();
            var options = {
                timeZone: timezone,
                hour: '2-digit',
                hour12: false,
            };
            var formatter = new Intl.DateTimeFormat([], options);
            var formattedTime = formatter.formatToParts(date);
            var hour = formattedTime.find(part => part.type === 'hour').value;
            return parseInt(hour, 10);
        }

        // Get the current hour in the specified timezone
        var curHr = getHourInTimezone(defaultTimezone);

        // Set greeting based on the calculated hour
        var target = document.getElementById("greetings");
        if (target && curHr < 12) {
            target.innerHTML = "{{ __('Good Morning,') }}";
        } else if (target && curHr < 17) {
            target.innerHTML = "{{ __('Good Afternoon,') }}";
        } else {
            if (target) {
                target.innerHTML = "{{ __('Good Evening,') }}";
            }
        }
    </script>
    <script>
        $(document).on('click', '.code', function () {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '#code-generate', function () {
            var length = 10;
            var result = '';
            var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            var charactersLength = characters.length;
            for (var i = 0; i < length; i++) {
                result += characters.charAt(Math.floor(Math.random() * charactersLength));
            }
            $('#auto-code').val(result);
        });
    </script>
    <script>
        function add_more_customer_choice_option(i, name) {
            $('#customer_choice_options').append(
                '<div class="form-group"><input type="hidden" name="choice_no[]" value="' + i + '">' +
                '<label for="choice_attributes">' + name + ':</label>' +
                '<input type="text" class="form-control variant_choice" name="choice_options_' + i +
                '[]" __="{{ __('Enter choice values') }}"  data-role="tagsinput" id="variant_tag' + i +
                '" onchange="update_sku($(this).val())">' +
                '</div>');
            comman_function();
        }

        $(document).ready(function () {
            var customURL = {!! json_encode($theme_url) !!};
            console.log('customURL', customURL);
            $('.Demo1').socialSharingPlugin({
                url: customURL,
                title: $('meta[property="og:title"]').attr('content'),
                description: $('meta[property="og:description"]').attr('content'),
                img: $('meta[property="og:image"]').attr('content'),
                enable: ['whatsapp', 'facebook', 'twitter', 'pinterest', 'instagram']
            });

            $('.socialShareButton').click(function (e) {
                e.preventDefault();
                $('.sharingButtonsContainer').toggle();
            });

            // Fetch order details for "Today" tab
            fetchOrderDetails('all-category', 'category');
            fetchOrderDetails('all-category', 'brand');

            // Add event listener for tab changes
            $('.dash-category-tab .nav-pills a[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                // Get the ID of the active tab
                var tabId = $(e.target).attr('href');
                $('#loader').fadeIn();
                // Fetch order details for "Today" tab
                fetchOrderDetails(tabId, 'category');


            });

            // Add event listener for tab changes
            $('.dash-brand-tab .nav-pills a[data-bs-toggle="pill"]').on('shown.bs.tab', function (e) {
                // Get the ID of the active tab
                var tabId = $(e.target).attr('href');
                $('#loader').fadeIn();
                // Fetch order details for "Today" tab
                fetchOrderDetails(tabId, 'brand');


            });

            // Function to fetch order details based on tab ID
            function fetchOrderDetails(tabId, type) {
                // Make an AJAX request to fetch order details
                $.ajax({
                    url: "{{ route('top.brand.category.chart') }}", // Replace with your Laravel route
                    type: 'POST',
                    data: {
                        tabId: tabId,
                        type: type
                    },
                    success: function (response) {
                        // Update UI with fetched order details
                        // For example, you can update a div with the fetched data
                        $('#' + type + '-tab-data').html('');
                        $('#' + type + '-tab-data').html(response.html);
                        $('#loader').fadeOut();
                    },
                    error: function (xhr, status, error) {
                        {{-- bysales-ajax-loader-stuck. The overlay is raised on every tab change
                             but used to be released ONLY from success:, so any non-2xx or network
                             failure stranded #loader — position:fixed, z-index:9999999, no
                             pointer-events escape and no dismiss control (public/css/loader.css)
                             — over a dashboard the merchant could no longer click.

                             An ABORT is not a failure: it is the merchant navigating away, and
                             public/js/loader.js raises the same overlay on beforeunload, so
                             releasing it here would tear down the navigation scrim and park a red
                             alert on a page that is leaving. 'error', 'timeout' and 'parsererror'
                             fall through, which is the point of this handler.

                             Wording is status-aware and reuses the three keys already translated
                             in BOTH resources/lang/en.json and ar.json, matching the house
                             precedent in resources/views/order/index.blade.php. No bespoke string:
                             __() falls back to the key, and this view is scanned by no
                             ArabicUiCoverageTest::SURFACES glob, so an untranslated sentence here
                             would be announced in English by role="alert" to an Arabic
                             screen-reader user and nothing in CI would notice (F259).

                             xhr.responseJSON.message is deliberately NOT passed through: the
                             precedent feeds it to a transient toast, whereas this alert persists
                             in the card, and on 419 Laravel's body is "CSRF token mismatch.".

                             Contrast measured with the WCAG 2.x formula, not assumed:
                             --ss-danger-fg on --ss-danger-bg is #8c1d17 on #fdeeee = 8.09:1 light
                             and #f3a9a4 on #2c1413 = 9.06:1 dark. Both clear AA and AAA, in both
                             directions. .ss-alert__body is what escapes the host dark sheets'
                             p:not([class^="ss-"]) { color:#808191 !important }, and
                             .ss-alert__content carries min-width:0 so a long Arabic sentence
                             cannot overflow the flex line. --}}
                        if (status === 'abort') {
                            return;
                        }
                        $('#loader').fadeOut();
                        var message = (xhr.status === 403)
                            ? @json(__('You are not allowed to do this.'))
                            : ((xhr.status === 419 || xhr.status === 401)
                                ? @json(__('Your session has expired. Please reload the page.'))
                                : @json(__('Something went wrong. Please try again.')));
                        $('#' + type + '-tab-data')
                            .empty()
                            .append($('<div/>', {
                                'class': 'ss-alert ss-alert--danger',
                                role: 'alert'
                            }).append($('<div/>', { 'class': 'ss-alert__content' })
                                .append($('<p/>', { 'class': 'ss-alert__body' }).text(message))));
                        console.error(error);
                    }
                });
            }
        });
    </script>
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-DN7D163CD8"></script>
    <script>
        window.dataLayer = window.dataLayer || [];

        function gtag() {
            dataLayer.push(arguments);
        }
        gtag('js', new Date());
        gtag('config', 'G-DN7D163CD8');
    </script>
@endpush