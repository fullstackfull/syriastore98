@extends('front_end.layouts.app')

@section('page-title')
{{ __('Track Order Page') }}
@endsection

@section('content')
    @if ($themeSettings['track_banner_status'] && $themeSettings['track_banner_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center"
        style="background-image: url('{{ get_file($themeSettings['track_banner_image'] ?? '') }}');">
        <div class="md:container w-full mx-auto px-4">
            <div class="text-center relative z-[2]">
                <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">
                    {{ $themeSettings['track_banner_title'] ?? '' }}</h2>
            </div>
        </div>
    </section>
    @endif
    @if (!empty($order))
        @if($order->delivered_status != 2)
        <section class="order-track-page lg:py-20 py-10">
            <div class="md:container w-full mx-auto px-4">
                <div class="account-info flex flex-col md:flex-row md:items-center mb-5 p-4 rounded-lg lg:gap-10 gap-5">
                    <div class="profile-img md:w-32 md:h-32 w-24 h-24 rounded-full overflow-hidden">
                        <img src="{{ get_file($customer->profile_image ?? 'storage/uploads/customerprofile/avatar.png') }}" alt="profile-img" class="w-full h-full object-cover">
                    </div>
                    <div class="flex-1">
                        <h3 class="text-2xl font-bold mb-4">{{ !empty($order_detail['delivery_informations']['name']) ? $order_detail['delivery_informations']['name'] : $customer->first_name }}</h3>
                        <div class="mb-3 flex items-center gap-4 flex-wrap">
                            <div class="flex items-center">
                                <i class="fa-solid fa-envelope text-gray-900 me-2"></i>
                                <a href="mailto:{{ $order_detail['delivery_informations']['email'] }}">{{ $order_detail['delivery_informations']['email'] }}</a>
                            </div>
                            <div class="flex items-center">
                                <i class="fa-solid fa-phone text-gray-900 me-2"></i>
                                <a href="tel:+{{ $order_detail['delivery_informations']['phone'] }}">+{{ $order_detail['delivery_informations']['phone'] }}</a>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 flex-wrap">
                            <div class="gap-3 rounded-lg flex items-center">
                                <span class="flex items-center justify-center text-primary">
                                    <i class="fas fa-hourglass-half text-xl"></i> <!-- Pending -->
                                </span>
                                <div class="flex flex-col gap-2">
                                    <span> {{ date('Y-m-d ', strtotime($order->order_date)) }} <strong class="block font-medium">{{ __('Pending') }}</strong></span>
                                </div>
                            </div>
                            <div class="gap-3 rounded-lg flex items-center">
                                <span class="flex items-center justify-center text-primary">
                                    <i class="fas fa-check-circle text-xl"></i> <!-- Confirm -->
                                </span>
                                <div class="flex flex-col gap-2">
                                    <span> 
                                        @if(in_array($order->delivered_status, [1, 4, 5, 6]))
                                            {{ !empty($order->confirmed_date) ? date('Y-m-d ', strtotime($order->confirmed_date)) : date('Y-m-d ', strtotime($order->picked_date)) }}
                                            @if(empty($order->picked_date))
                                                {{ !empty($order->shipped_date)  ? date('Y-m-d ', strtotime($order->shipped_date)) : date('Y-m-d ', strtotime($order->delivery_date)) }}
                                            @endif
                                        @endif <strong class="block font-medium">{{ __('Confirm') }}</strong></span>
                                </div>
                            </div>
                            <div class="gap-3 rounded-lg flex items-center">
                                <span class="flex items-center justify-center text-primary">
                                    <i class="fas fa-box text-xl"></i> <!-- Picked Up -->
                                </span>
                                <div class="flex flex-col gap-2">
                                    <span>  
                                        @if (in_array($order->delivered_status, [1, 5, 6]))
                                            {{ !empty($order->picked_date) ? date('Y-m-d ', strtotime($order->picked_date)) : date('Y-m-d ', strtotime($order->shipped_date)) }}
                                            @if(empty($order->shipped_date))
                                                {{ !empty($order->shipped_date)  ? date('Y-m-d ', strtotime($order->shipped_date)) : date('Y-m-d ', strtotime($order->delivery_date)) }}
                                            @endif
                                        @endif <strong class="block font-medium">{{ __('Picked Up') }}</strong></span>
                                </div>
                            </div>
                            <div class="gap-3 rounded-lg flex items-center">
                                <span class="flex items-center justify-center text-primary">
                                    <i class="fas fa-truck text-xl"></i> <!-- Shipped -->
                                </span>
                                <div class="flex flex-col gap-2">
                                    <span> 
                                        @if (in_array($order->delivered_status, [1, 6]))
                                            {{ !empty($order->shipped_date) ? date('Y-m-d ', strtotime($order->shipped_date)) : date('Y-m-d ', strtotime($order->delivery_date)) }}
                                        @endif <strong class="block font-medium">{{ __('Shipped') }}</strong></span>
                                </div>
                            </div>
                            <div class="gap-3 rounded-lg flex items-center">
                                <span class="flex items-center justify-center text-primary">
                                    <i class="fas fa-box-open text-xl"></i> <!-- Delivered -->
                                </span>
                                <div class="flex flex-col gap-2">
                                    <span>
                                        @if (in_array($order->delivered_status, [1]))
                                            {{  date('Y-m-d ', strtotime($order->delivery_date)) }}
                                        @endif <strong class="block font-medium">{{ __('Delivered') }}</strong></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="progressbar-wrapper p-4 md:mt-6 mt-5 rounded-lg">
                    <span class="mb-3 block">{{ $themeSettings['track_form_title'] ?? '' }}</span>
                    <h2 class="md:text-xl text-base font-semibold mb-5">{{ __('Order Number') }}:  {{ $order->product_order_id }}</h2>
                    <ul id="progressbar" class="flex flex-nowrap overflow-auto md:pb-0 pb-2">
                        <li class="text-base w-full flex flex-col items-center relative gap-2.5 list-none active">
                            <div
                                class="progressbar-icon flex items-center justify-center rounded-full p-3 bg-gray-200 w-10 h-10 relative z-[1]">
                                <i class="fas fa-hourglass-start text-black text-lg"></i>
                            </div>
                            <span class="mx-3 md:text-base text-sm font-semibold">{{ __('Pending') }}</span>
                        </li>
                        <li class="text-base w-full flex flex-col items-center relative gap-2.5 list-none @if (in_array($order->delivered_status, [1, 4, 5, 6])) active @endif">
                            <div
                                class="progressbar-icon flex items-center justify-center rounded-full p-3 bg-gray-200 w-10 h-10 relative z-[1]">
                                <i class="fas fa-check-circle text-black text-lg"></i>
                            </div>
                            <span class="mx-3 md:text-base text-sm font-semibold">{{ __('Confirmed') }}</span>
                        </li>
                        <li class="text-base w-full flex flex-col items-center relative gap-2.5 list-none @if (in_array($order->delivered_status, [1, 5, 6])) active @endif">
                            <div
                                class="progressbar-icon flex items-center justify-center rounded-full p-3 bg-gray-200 w-10 h-10 relative z-[1]">
                                <i class="fas fa-box text-black text-lg"></i>
                            </div>
                            <span class="mx-3 md:text-base text-sm font-semibold">{{ __('Picked Up') }}</span>
                        </li>
                        <li class="text-base w-full flex flex-col items-center relative gap-2.5 list-none @if (in_array($order->delivered_status, [1, 6])) active @endif">
                            <div
                                class="progressbar-icon flex items-center justify-center rounded-full p-3 bg-gray-200 w-10 h-10 relative z-[1]">
                                <i class="fas fa-truck text-black text-lg"></i>
                            </div>
                            <span class="mx-3 md:text-base text-sm font-semibold">{{ __('Shipped') }}</span>
                        </li>
                        <li class="text-base w-full flex flex-col items-center relative gap-2.5 list-none @if (in_array($order->delivered_status, [1])) active @endif">
                            <div
                                class="progressbar-icon flex items-center justify-center rounded-full p-3 bg-gray-200 w-10 h-10 relative z-[1]">
                                <i class="fas fa-box-open text-black text-lg"></i>
                            </div>
                            <span class="mx-3 md:text-base text-sm font-semibold">{{ __('Delivered') }}</span>
                        </li>
                    </ul>
                    <div class="flex sm:justify-end justify-center mt-6">
                        <div class="flex">
                            <a href="{{ $url ?? '#' }}" class="btn-primary">{{ __('view order') }}
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        @else
            @if ($themeSettings['track_cancel_status'] && $themeSettings['track_cancel_status'] == '1')
                <section class="py-12 md:py-20">
                    <div class="md:container w-full mx-auto px-4">
                        <div class="max-w-3xl mx-auto">
                            <div id="not-found-state" class="bg-white p-8 rounded-lg shadow-sm text-center">
                                <div class="mb-6">
                                    <div
                                        class="inline-flex items-center justify-center bg-red-100 text-red-500 h-16 w-16 rounded-full mx-auto">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                            class="h-8 w-8">
                                            <circle cx="12" cy="12" r="10" />
                                            <line x1="12" x2="12" y1="8" y2="12" />
                                            <line x1="12" x2="12.01" y1="16" y2="16" />
                                        </svg>
                                    </div>
                                </div>
                                <h2 class="font-heading font-bold text-xl mb-4">
                                {{ $themeSettings['track_cancel_title'] ?? __('Your order is Cancel') }}</h2>
                                <p class="text-gray-600 mb-6">
                                {{ $themeSettings['track_cancel_desc'] ?? __("This order has been canceled.") }}
                                </p>
                                <a href="{{ route('page.product-list', $store->slug) }}"
                                    class="bg-primary hover:bg-primary-dark text-white font-medium py-2 px-6 rounded-md transition">
                                    {{ $themeSettings['track_cancel_button'] ?? __('Continue shopping') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </section>
            @endif
        @endif
    @else
        @if ($themeSettings['track_error_status'] && $themeSettings['track_error_status'] == '1')
            <section class="py-12 md:py-20">
                <div class="md:container w-full mx-auto px-4">
                    <div class="max-w-3xl mx-auto">
                        <div id="not-found-state" class="bg-white p-8 rounded-lg shadow-sm text-center">
                            <div class="mb-6">
                                <div
                                    class="inline-flex items-center justify-center bg-red-100 text-red-500 h-16 w-16 rounded-full mx-auto">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="h-8 w-8">
                                        <circle cx="12" cy="12" r="10" />
                                        <line x1="12" x2="12" y1="8" y2="12" />
                                        <line x1="12" x2="12.01" y1="16" y2="16" />
                                    </svg>
                                </div>
                            </div>
                            <h2 class="font-heading font-bold text-xl mb-4">
                                {{ $themeSettings['track_error_title'] ?? __('Order Not Found') }}</h2>
                            <p class="text-gray-600 mb-6">
                                {{ $themeSettings['track_error_desc'] ?? __("We couldn't find an order matching the information you provided. Please check your order number and email address, and try again.")}}
                            </p>
                            <a href="{{ route('track.order', $store->slug) }}"
                                class="bg-primary hover:bg-primary-dark text-white font-medium py-2 px-6 rounded-md transition">
                                {{ $themeSettings['track_error_button'] ?? __('Try Again') }}
                            </a>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    @endif
@endsection