<!-- Account Sidebar -->
@if (isset($themeSettings['account_tab_status']) && $themeSettings['account_tab_status'] == '1')
<div class="lg:w-1/4">
    <div class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
        @if (isset($themeSettings['account_tab_customer_info']) && $themeSettings['account_tab_customer_info'] == '1')
        <div class="flex items-center gap-4 pb-4 border-b mb-4">
            <div
                class="bg-primary text-white h-12 w-12 rounded-full flex items-center justify-center flex-shrink-0">
                <i class="fas fa-user"></i>
            </div>
            <div>
                <h3 class="font-medium">{{ auth('customers')->user() ? auth('customers')->user()->first_name . ' ' . auth('customers')->user()->last_name : ''}}</h3>
                <p class="text-sm text-gray-500 break-all">{{ auth('customers')->user()->email ?? '' }}</p>
            </div>
        </div>
        @endif
        <ul class="space-y-1">
            @if (isset($themeSettings['account_tab_order_status']) && $themeSettings['account_tab_order_status'] == '1')
            <li>
                <a href="{{ route('order', $store->slug) }}"
                    class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-box text-gray-500"></i>
                        <span>{{ $themeSettings['account_tab_order_title'] ?? '' }}</span>
                    </div>
                </a>
            </li>
            @endif
            @if (isset($themeSettings['account_tab_address_status']) && $themeSettings['account_tab_address_status'] == '1')
            <li>
                <a href="{{ route('address', $store->slug) }}"
                    class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-map-marker-alt text-gray-500"></i>
                        <span>{{ $themeSettings['account_tab_address_title'] ?? '' }}</span>
                    </div>
                </a>
            </li>
            @endif
            @if (isset($themeSettings['account_tab_wishlist_status']) && $themeSettings['account_tab_wishlist_status'] == '1')
            <li>
                <a href="{{ route('wishlist', $store->slug) }}"
                    class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-heart text-gray-500"></i>
                        <span>{{ $themeSettings['account_tab_wishlist_title'] ?? '' }}</span>
                    </div>
                </a>
            </li>
            @endif
            @if (isset($themeSettings['account_tab_detail_status']) && $themeSettings['account_tab_detail_status'] == '1')
            <li>
                <a href="{{ route('my-account.index', $store->slug) }}"
                    class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-user text-gray-500"></i>
                        <span>{{ $themeSettings['account_tab_detail_title'] ?? '' }}</span>
                    </div>
                </a>
            </li>
            @endif
            @if (isset($themeSettings['account_tab_support_ticket_status']) && $themeSettings['account_tab_support_ticket_status'] == '1')
            <li>
                <a href="{{ route('support.ticket', $store->slug) }}"
                    class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-ticket text-gray-500"></i>
                        <span>{{ $themeSettings['account_tab_support_ticket_title'] ?? '' }}</span>
                    </div>
                </a>
            </li>
            @endif
            @if (isset($themeSettings['account_tab_logout_status']) && $themeSettings['account_tab_logout_status'] == '1' && auth('customers')->user())
            <li>
                <form method="POST" action="{{ route('customer.logout',$slug) }}" id="form_logout">
                    @csrf
                    <a href="javascript::void(0);" onclick="event.preventDefault(); this.closest('form').submit();" class="block py-2 px-3 rounded-md hover:bg-primary/10 text-red-600 transition">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>{{ $themeSettings['account_tab_logout_title'] ?? '' }}</span>
                        </div>
                    </a>
                </form>
            </li>
            @endif
              @include('front_end.hooks.my_account_tab')
        </ul>
    </div>
</div>
@endif