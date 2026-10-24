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

                     Everything below this block is unchanged: the theme card,
                     top category, the chart, storage, latest products and
                     recent orders all still render exactly as before. --}}
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
                                                data-bs-target="#all-category-home" type="button" role="tab"
                                                aria-controls="all-category-home" aria-selected="true">
                                                {{ __('All') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-category-order" class="nav-link btn-sm f-w-600"
                                                id="today-category-tab" data-bs-toggle="pill"
                                                data-bs-target="#today-category" type="button" role="tab"
                                                aria-controls="today-category" aria-selected="true">
                                                {{ __('Today') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Week') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Month') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-category-order" class="nav-link btn-sm f-w-600"
                                                id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo"
                                                type="button" role="tab" aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Year') }}
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="category-tab-data"></div>
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
                                                id="all-brand-tab" data-bs-toggle="pill" data-bs-target="#all-brand"
                                                type="button" role="tab" aria-controls="all-brand" aria-selected="true">
                                                {{ __('All') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#today-brand-order" class="nav-link btn-sm f-w-600"
                                                id="today-brand-tab" data-bs-toggle="pill" data-bs-target="#today-brand"
                                                type="button" role="tab" aria-controls="today-brand" aria-selected="true">
                                                {{ __('Today') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#week-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Week') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#month-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Month') }}
                                            </a>
                                        </li>
                                        <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center"
                                            role="presentation">
                                            <a href="#year-brand-order" class="nav-link btn-sm f-w-600" id="pills-seo-tab"
                                                data-bs-toggle="pill" data-bs-target="#pills-seo" type="button" role="tab"
                                                aria-controls="pills-seo" aria-selected="true">
                                                {{ __('Year') }}
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content dash-category-tab-content mt-4">
                                        <div id="brand-tab-data"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
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
                                <div class="card-header">
                                    <h4>{{ __('Storage Status') }}<small>({{ $users->storage_limit . 'MB' }} / {{ ($plan->storage_limit ?? 0) . 'MB' }})</small></h4>
                                </div>
                                <div class="card-body">
                                    <div id="device-chart"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
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
                <div class="col-xxl-4 col-12">
                    <div class="dashboard-card">
                        <div class="d-flex justify-content-between align-items-center flex-wrap">
                            <h4>{{ __('Recent Orders') }}</h4>
                            @if ($new_orders && count($new_orders) > 0)
                                <a class="text-primary" href="{{ route('order.index') }}">{{ __('View All') }}</a>
                            @endif
                        </div>
                        <div class="table-responsive">
                            <table class="table dataTable">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('Orders') }}</th>
                                        <th scope="col" class="sort">{{ __('Date') }}</th>
                                        <th scope="col" class="sort">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @if (count($new_orders) > 0)
                                        @foreach ($new_orders as $order)
                                            @if ($order->status != 'Cancel Order')
                                                <tr>
                                                    <td>
                                                        <div class="d-flex align-items-center table-inner-text">
                                                            <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
                                                                @php $btn_class = 'bg-info';
                                                                    if (
                                                                        $order->delivered_status == 2 ||
                                                                        $order->delivered_status == 3
                                                                    ) {
                                                                        $btn_class = 'bg-danger';
                                                                    } elseif ($order->delivered_status == 1) {
                                                                        $btn_class = 'bg-success';
                                                                    } elseif ($order->delivered_status == 4) {
                                                                        $btn_class = ' bg-warning';
                                                                    } elseif ($order->delivered_status == 5) {
                                                                        $btn_class = 'bg-secondary';
                                                                    } elseif ($order->delivered_status == 6) {
                                                                        $btn_class = 'bg-dark';
                                                                } @endphp
                                                                class="btn bg-primary btn-sm text-sm btn-badge" data-bs-toggle="tooltip"
                                                                title="{{ __('Invoice ID') }}">
                                                                <span class="btn-inner--icon"></span>
                                                                <span class="btn-inner--text">#{{ $order->product_order_id }}</span>
                                                            </a>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <h6 class="m-0">
                                                            {{ \App\Models\Utility::dateFormat($order->order_date) }}
                                                        </h6>
                                                    </td>



                                                    <td class="">
                                                        @if ($order->delivered_status == 0)
                                                            <button type="button" class="btn btn-sm btn-info btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Pending') }}
                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 1)
                                                            <button type="button" class="btn btn-sm btn-success btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Delivered') }}
                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 2)
                                                            <button type="button" class="btn btn-sm btn-danger btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Cancel') }}
                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 3)
                                                            <button type="button" class="btn btn-sm btn-danger btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Return') }}
                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 4)
                                                            <button type="button" class="btn btn-sm btn-warning btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Confirmed') }}

                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 5)
                                                            <button type="button" class="btn btn-sm btn-secondary btn-icon">

                                                                <span class="btn-inner--text"> {{ __('Picked Up') }}

                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 6)
                                                            <button type="button" class="btn btn-sm btn-dark btn-icon">

                                                                <span class="btn-inner--text"> {{ __(key: 'Shipped') }}
                                                                </span>
                                                            </button>
                                                        @elseif ($order->delivered_status == 8)
                                                            <button type="button" class="btn btn-sm btn-dark btn-icon">
                                                                <span class="btn-inner--text"> {{ __('Pre Order') }}

                                                                </span>
                                                            </button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
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
                labels: ['Used'],
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

            var chart = new ApexCharts(document.querySelector("#device-chart"), options);
            chart.render();
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
                        // Handle error
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