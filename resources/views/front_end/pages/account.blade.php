@extends('front_end.layouts.app')

@section('page-title')
{{ __('Account Page') }}
@endsection

@section('content')
 <main>
    @if ($themeSettings['myaccount_status'] && $themeSettings['myaccount_status'] == '1')
    <!-- Common Banner Section -->
    <section class="banner-section relative lg:py-16 py-10 bg-cover bg-center" style="background-image: url('{{ get_file($themeSettings['myaccount_image'] ?? '') }}');">
      <div class="md:container w-full mx-auto px-4">
        <div class="text-center relative z-[2]">
          <h2 class="text-[26px] sm:text-4xl lg:text-5xl font-bold mb-4 capitalize">{{ $themeSettings['myaccount_title'] ?? __('My account') }}</h2>
        </div>
      </div>
    </section>
    @endif
    
    
    <section class="lg:py-20 py-10">
       <div class="md:container w-full mx-auto px-4">
           <div class="flex flex-col lg:flex-row lg:gap-8 gap-6">
               @include('front_end.common.account-tab')

               <!-- Main Content -->
               <div class="lg:w-3/4">
                   @if ($themeSettings['account_dashboard_status'] && $themeSettings['account_dashboard_status'] == '1')
                   <!-- Dashboard Section -->
                   <div id="dashboard" class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
                       <h2 class="font-heading font-bold text-xl md:text-2xl md:mb-6 mb-4">{{ $themeSettings['account_dashboard_title'] ?? '' }}</h2>

                        <div class="text-gray-600 md:mb-6 mb-4 flex flex-wrap items-center gap-1">
                            <span>{{ $themeSettings['account_dashboard_greeting'] ?? '' }}</span>
                            <span class="font-semibold">{{ $customer_name ?? '' }}</span>
                            <span>( {{ __('not') }}</span>
                            <span class="font-semibold">{{ $customer_name ?? '' }}</span>
                            <span>?</span>
                            <form method="POST" action="{{ route('customer.logout', $slug) }}" class="inline">@csrf
                                <a href="javascript:;" onclick="event.preventDefault(); this.closest('form').submit();" class="text-primary hover:underline">
                                    {{ __('Log out') }}
                                </a>
                            </form>
                            <span>)</span>
                        </div>

                       <p class="text-gray-600 mb-6">{!! $themeSettings['account_dashboard_description'] ?? '' !!}</p>

                       <!-- Dashboard Cards -->
                       <!-- Font Awesome CDN (add in <head> if not included already) -->
                       <link rel="stylesheet"
                           href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

                       <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            @if ($themeSettings['account_dashboard_order'] && $themeSettings['account_dashboard_order'] == '1')
                           <!-- Orders Card -->
                           <div
                               class="border rounded-lg p-4 flex flex-col items-center text-center hover:border-primary transition">
                               <div
                                   class="bg-primary md:h-12 md:w-12 h-10 w-10 rounded-full flex items-center justify-center mb-3">
                                   <i class="fas fa-box-open text-white"></i>
                               </div>
                               <h3 class="font-heading font-semibold mb-1">{{ $themeSettings['account_dashboard_order_title'] ?? '' }}</h3>
                               <p class="text-sm text-gray-500 mb-3">{{ $themeSettings['account_dashboard_order_desc'] ?? '' }}</p>
                               <a href="{{ route('order', $store->slug) }}" class="text-primary text-sm hover:underline mt-auto">{{ $themeSettings['account_dashboard_order_btn'] ?? '' }}</a>
                           </div>
                            @endif
                           @if ($themeSettings['account_dashboard_address'] && $themeSettings['account_dashboard_address'] == '1')
                           <!-- Addresses Card -->
                           <div
                               class="border rounded-lg p-4 flex flex-col items-center text-center hover:border-primary transition">
                               <div
                                   class="bg-primary md:h-12 md:w-12 h-10 w-10 rounded-full flex items-center justify-center mb-3">
                                   <i class="fas fa-map-marker-alt text-white"></i>
                               </div>
                               <h3 class="font-heading font-semibold mb-1">{{ $themeSettings['account_dashboard_address_title'] ?? '' }}</h3>
                               <p class="text-sm text-gray-500 mb-3">{{ $themeSettings['account_dashboard_address_desc'] ?? '' }}</p>
                               <a href="{{ route('address', $store->slug) }}" class="text-primary text-sm hover:underline mt-auto">{{ $themeSettings['account_dashboard_address_btn'] ?? '' }}</a>
                           </div>
                            @endif
                           @if ($themeSettings['account_dashboard_wishlist'] && $themeSettings['account_dashboard_wishlist'] == '1')
                           <!-- Wishlist Card -->
                           <div
                               class="border rounded-lg p-4 flex flex-col items-center text-center hover:border-primary transition">
                               <div
                                   class="bg-primary md:h-12 md:w-12 h-10 w-10 rounded-full flex items-center justify-center mb-3">
                                   <i class="fas fa-heart text-white"></i>
                               </div>
                               <h3 class="font-heading font-semibold mb-1">{{ $themeSettings['account_dashboard_wishlist_title'] ?? '' }}</h3>
                               <p class="text-sm text-gray-500 mb-3">{{ $themeSettings['account_dashboard_wishlist_desc'] ?? '' }}</p>
                               <a href="{{ route('wishlist', $store->slug) }}" class="text-primary text-sm hover:underline mt-auto">{{ $themeSettings['account_dashboard_wishlist_btn'] ?? '' }}</a>
                           </div>
                            @endif
                           @if ($themeSettings['account_dashboard_detail'] && $themeSettings['account_dashboard_detail'] == '1')
                           <!-- Account Card -->
                           <div
                               class="border rounded-lg p-4 flex flex-col items-center text-center hover:border-primary transition">
                               <div
                                   class="bg-primary md:h-12 md:w-12 h-10 w-10 rounded-full flex items-center justify-center mb-3">
                                   <i class="fas fa-user-circle text-white"></i>
                               </div>
                               <h3 class="font-heading font-semibold mb-1">{{ $themeSettings['account_dashboard_detail_title'] ?? '' }}</h3>
                               <p class="text-sm text-gray-500 mb-3">{{ $themeSettings['account_dashboard_detail_desc'] ?? '' }}</p>
                               <a href="{{ route('my-account.index', $store->slug) }}" class="text-primary text-sm hover:underline mt-auto">{{ $themeSettings['account_dashboard_detail_btn'] ?? '' }}</a>
                           </div>
                           @endif
                       </div>

                   </div>
                    @endif

                    @if ($themeSettings['account_recent_order_status'] && $themeSettings['account_recent_order_status'] == '1')
                   <!-- Recent Orders -->
                   <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
                       <div class="flex items-center justify-between md:mb-6 mb-4 gap-4 flex-wrap">
                           <h2 class="font-heading font-bold text-xl">{{ $themeSettings['account_recent_order_title'] ?? '' }}</h2>
                           <a href="{{ route('order', $store->slug) }}" class="text-primary hover:underline text-sm">{{ $themeSettings['account_recent_order_view_all'] ?? '' }}</a>
                       </div>

                       <!-- Orders Table -->
                       <div class="overflow-x-auto">
                           <table class="md:w-full min-w-[570px]">
                               <thead class="ltr:text-left rtl:text-right bg-primary/10 border border-b">
                                   <tr>
                                       <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Order') }}</th>
                                       <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Date') }}</th>
                                       <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Status') }}</th>
                                       <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Total') }}</th>
                                       <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Actions') }}</th>
                                   </tr>
                               </thead>
                               <tbody class="divide-y">
                                @foreach ($recent_orders as $order)
                                   <tr>
                                       <td class="py-3 px-4 text-sm">{{ '#' .$order->product_order_id }}</td>
                                       <td class="py-3 px-4 text-sm">{{ \App\Models\Utility::dateFormat($order->order_date) }}</td>
                                       <td class="py-3 px-4">
                                           <span
                                               class="inline-block bg-green-100 text-green-600 px-2 py-1 rounded border border-green-600 text-xs font-medium">
                                               {{ getOrderStatusLabel($order->delivered_status)  }}
                                           </span>
                                       </td>
                                       <td class="py-3 px-4 text-sm font-medium">{{ currency_format_with_sym(($order->final_price ?? 0), $store->id) ?? SetNumberFormat($order->final_price) }}</td>
                                       <td class="py-3 px-4">
                                           <div class="flex gap-2">
                                               <a href="{{ route('order.details', [$store->slug, encrypt($order->id ?? '')]) }}"
                                                   class="text-primary hover:underline text-sm">{{ $themeSettings['account_recent_order_action'] ?? '' }}</a>
                                           </div>
                                       </td>
                                   </tr>
                                   @endforeach
                               </tbody>
                           </table>
                       </div>
                   </div>
                    @endif
                   @if ($themeSettings['account_detail_status'] && $themeSettings['account_detail_status'] == '1')
                   <!-- Account Details -->
                   <div id="account-details" class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
                       <h2 class="font-heading font-bold text-xl md:mb-6 mb-4">{{ $themeSettings['account_detail_title'] ?? '' }}</h2>

                       <form method="POST" action="{{ route('my-account.store', $store->slug) }}" >
                        @csrf
                        <input type="hidden" name="customer_id" value="{{ $customer->id ?? '' }}" />
                           <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4 md:mb-6 mb-4">
                               <div>
                                   <label for="first-name" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_first_name'] ?? '' }} <span
                                           class="text-red-500">*</span></label>
                                   <input type="text" name="first_name" id="first-name" value="{{ $customer->first_name ?? 'John'}}" class="form-input ltr:text-left rtl:text-right" required />
                               </div>
                               <div>
                                   <label for="last-name" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_last_name'] ?? '' }} <span
                                           class="text-red-500">*</span></label>
                                   <input type="text" name="last_name" id="last-name" value="{{ $customer->last_name ?? 'Doe' }}" class="form-input ltr:text-left rtl:text-right" required />
                               </div>
                               <div>
                                   <label for="email" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_email'] ?? '' }} <span
                                           class="text-red-500">*</span></label>
                                   <input type="email" name="email" id="email" value="{{ $customer->email ?? 'john.doe@example.com' }}" class="form-input ltr:text-left rtl:text-right"
                                       required />
                               </div>
                               <div>
                                   <label for="phone" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_phone'] ?? '' }}</label>
                                   <input type="tel" name="mobile" id="phone" value="{{ $customer->mobile ?? '(555) 123-4567' }}" class="form-input ltr:text-left rtl:text-right" />
                               </div>
                           </div>

                           <h3 class="font-heading font-semibold text-lg mb-4 border-b pb-2">{{ $themeSettings['account_detail_pwd_change'] ?? '' }}</h3>
                           <p class="text-sm text-gray-500 mb-4">{!! $themeSettings['account_detail_pwd_note'] ?? '' !!}</p>

                           <div class="grid grid-cols-1 md:grid-cols-2 md:gap-6 gap-4 md:mb-6 mb-4">
                               <div>
                                   <label for="current-password" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_pwd_current'] ?? '' }}</label>
                                   <input type="password" name="old_password" id="current-password" class="form-input ltr:text-left rtl:text-right" />
                               </div>
                               <div>
                                   <label for="new-password" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_pwd_new'] ?? '' }}</label>
                                   <input type="password" name="new_password" id="new-password" class="form-input ltr:text-left rtl:text-right" />
                               </div>
                               <div>
                                   <label for="confirm-password" class="block mb-2 font-medium md:text-base text-sm">{{ $themeSettings['account_detail_pwd_confirm'] ?? '' }}</label>
                                   <input type="password" name="new_password_confirmation" id="confirm-password" class="form-input ltr:text-left rtl:text-right" />
                               </div>
                           </div>

                           <div class="flex justify-start">
                               <button type="submit" class="btn-primary">
                                   {{ $themeSettings['account_detail_save'] ?? '' }}
                               </button>
                           </div>
                       </form>
                   </div>
                    @endif
                       @include('front_end.hooks.my_account_tab_form')
               </div>
           </div>
       </div>
   </section>
    
  </main>
@endsection