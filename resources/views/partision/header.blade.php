@php
$displaylang = App\Models\Utility::languages();
if (auth()->user() && auth()->user()->language) {
$currentLanguage = auth()->user() ? auth()->user()->language : 'en';
} else {
$currentLanguage = Cookie::get('LANGUAGE');
if (empty($currentLanguage)) {
$currentLanguage = auth()->user() ? auth()->user()->language : 'en';
}
}
$store = getStoreById(getCurrentStore());
$theme_url = App\Http\Controllers\HomeController::getThemeUrl($store);
@endphp

@if (isset($setting['cust_theme_bg']) && $setting['cust_theme_bg'] == 'on')
<header class="dash-header transprent-bg">
    @else
    <header class="dash-header">
        @endif
        <div class="header-wrapper">
                <ul class="list-unstyled gap-2">
                    <li class="dash-h-item mob-hamburger">
                        <a href="#!" class="dash-head-link" id="mobile-collapse">
                            <div class="hamburger hamburger--arrowturn">
                                <div class="hamburger-box">
                                    <div class="hamburger-inner"></div>
                                </div>
                            </div>
                        </a>
                    </li>
                    <li class="dropdown dash-h-item drp-company">
                        <a class="dash-head-link dropdown-toggle arrow-none me-0" data-bs-toggle="dropdown" href="#"
                            role="button" aria-haspopup="false" aria-expanded="false">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <span class="hide-mob">
                                @if (!Auth::guest())
                                {{ __('Hi, ') }}{{ !empty(Auth::user()) ? Auth::user()->name : '' }}!
                                @else
                                {{ __('Guest') }}
                                @endif
                            </span>
                            <i class="ti ti-chevron-down drp-arrow nocolor hide-mob"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown">

                            <a href="{{ route('profile') }}" class="dropdown-item">
                                <i class="ti ti-user"></i>
                                <span>{{ __(' Profile') }}</span>
                            </a>
                            <form method="POST" action="{{ route('logout') }}" id="form_logout">
                                <a href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();"
                                    class="dropdown-item">
                                    <i class="ti ti-power"></i>
                                    @csrf
                                    {{ __(' Log Out') }}
                                </a>
                            </form>
                        </div>
                    </li>
                    @impersonating($guard = null)
                    <li class="dropdown dash-h-item">
                        <a class="dropdown-item dash-head-link dropdown-toggle arrow-none  bg-danger"
                            href="{{ route('exit.admin') }}"><i class="ti ti-ban"></i>
                            {{ __('Exit Admin Login') }}
                        </a>
                    </li>
                    @endImpersonating
                </ul>
            <div class="dash-center-drp">
                <ul class="list-unstyled exit-company-btn">                    
                </ul>
            </div>
            <div class="dash-right-drp">
                <ul class="list-unstyled header-icon-list">                    
                    @if (auth()->user() && auth()->user()->type == 'super admin')
                    <li class="web-browse-icon">
                        <a href="{{ url('config-cache') }}" data-bs-toggle="tooltip"
                        title="{{ __('Clear Cache') }}" class="dash-head-link cust-btn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16">
                                <path id="_74846e5be5db5b666d3893933be03656"
                                    data-name="74846e5be5db5b666d3893933be03656"
                                    d="M7.719,8.911H8.9V10.1H7.719v1.185H6.539V10.1H5.36V8.911h1.18V7.726h1.18ZM5.36,13.652h1.18v1.185H5.36v1.185H4.18V14.837H3V13.652H4.18V12.467H5.36Zm13.626-2.763H10.138V10.3a1.182,1.182,0,0,1,1.18-1.185h2.36V2h1.77V9.111h2.36a1.182,1.182,0,0,1,1.18,1.185ZM18.4,18H16.044a9.259,9.259,0,0,0,.582-2.963.59.59,0,1,0-1.18,0A7.69,7.69,0,0,1,14.755,18H12.5a9.259,9.259,0,0,0,.582-2.963.59.59,0,1,0-1.18,0A7.69,7.69,0,0,1,11.216,18H8.958a22.825,22.825,0,0,0,1.163-5.926H18.99A19.124,19.124,0,0,1,18.4,18Z"
                                    transform="translate(-3 -2)" fill="#060606"></path>
                            </svg>
                        </a>
                    </li>
                    @endif

                    {{--
                        P2-06-3 -- THE NOTIFICATION BELL.

                        OUTSIDE THE `type == 'admin'` BLOCK BELOW, AND THAT IS THE POINT.
                        It was first placed inside it, which meant the one principal the
                        per-type authorisation was built to serve correctly -- a STAFF member
                        holding `Manage Order` -- had no link to `/notifications` anywhere in
                        the application, and neither did the operator. Staff `users.type` is
                        their ROLE NAME (`UserController::store`: `$user->type = $role_r->name`),
                        never the literal `'admin'`, so that conditional is owner-only. Found by
                        the independent review.

                        A LINK, NOT A DROPDOWN, and that is a decision rather than a shortcut.
                        The feed endpoint exists and the badge is filled from it, but the bell
                        navigates to the full list: a dropdown would be a second surface showing
                        the same eight rows with its own focus trap, its own RTL placement and
                        its own dark-mode contrast to verify, for a screen a merchant visits in
                        order to clear it. One surface, checked once.

                        NO PERMISSION CHECK HERE. Authorisation is by notification TYPE inside
                        the handler, so a staff member who may read none of them gets a bell
                        with no badge and a page that says, in words, that notifications exist
                        which their role does not include -- which is honest, where an empty
                        list would be a lie. The count the badge shows is already filtered to
                        what the caller may read.

                        `hidden` until the fetch answers: a badge that renders 0 and then
                        becomes 3 flashes on every page load, and one that renders 0 forever is
                        what a blocked script leaves behind.
                    --}}
                    <li class="dash-h-item">
                        <a href="{{ route('merchant.notifications') }}"
                           class="dash-head-link h-100"
                           data-ss-notification-bell
                           data-ss-feed-url="{{ route('merchant.notifications.feed') }}"
                           data-bs-toggle="tooltip"
                           title="{{ __('Notifications') }}">
                            <i class="ti ti-bell" aria-hidden="true"></i>
                            <span class="ss-bell__count" data-ss-notification-count hidden></span>
                            <span class="visually-hidden" data-ss-notification-label>{{ __('Notifications') }}</span>
                        </a>
                    </li>

                    @if (auth()->user() && auth()->user()->type == 'admin')
                    <li class="web-browse-icon">
                        {{-- `rel="noopener noreferrer"`: this opens the merchant's own storefront
                             in a new tab, and without it that tab gets a `window.opener` handle on
                             the ADMIN page and can navigate it. It is the only `target="_blank"` in
                             this layout, so every admin screen carried it. Found while auditing the
                             order detail page's four. --}}
                        <a href="{{ $theme_url ?? '#' }}" target="_blank" rel="noopener noreferrer" data-bs-toggle="tooltip"
                        title="{{ __('Store Link') }}" class="dash-head-link cust-btn">
                           <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:36:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:37:6" data-component-name="path" d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline data-replit-metadata="client/src/components/icons/CustomIcons.tsx:38:6" data-component-name="polyline" points="9,22 9,12 15,12 15,22"></polyline><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:39:6" data-component-name="path" d="m15 7 3 3-3 3M8 13l-3-3 3-3"></path></svg>
                        </a>
                    </li>
                    <li class="web-browse-icon">
                        <a href="{{ route('pos.index') }}" data-bs-toggle="tooltip"
                        title="{{ __('POS') }}" class="dash-head-link cust-btn h-100 ">
                           <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:46:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-5 h-5 mr-3 text-primary"><rect data-replit-metadata="client/src/components/icons/CustomIcons.tsx:47:6" data-component-name="rect" x="2" y="4" width="20" height="16" rx="2"></rect><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:48:6" data-component-name="path" d="M7 15h10M7 11h4"></path><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:49:6" data-component-name="circle" cx="18" cy="8" r="2"></circle></svg>
                        </a>
                    </li>
                    
                    <li class="dropdown quick-add-btn header-quick-add">
                        <a class="dash-head-link dropdown-toggle arrow-none h-100 btn-q-add"
                        title="{{ __('Quick Add') }}" data-bs-toggle="dropdown" href="#"
                            role="button" aria-haspopup="false" aria-expanded="false">
                            <i class="ti ti-plus"></i>
                            <span class="text-store">{{ __('Quick Add') }}</span>
                        </a>
                        <div class="dropdown-menu">
                            <a href="{{ route('product.create') }}" data-size="lg" data-title="{{ __('Add Product') }}"
                                class="dropdown-item text-wrap"
                                data-bs-placement="top "><span>{{ __('Add New Product') }}</span></a>
                            <a href="#" data-size="md" data-url="{{ route('taxes.create') }}" data-ajax-popup="true"
                                data-title="{{ __('Create Tax') }}" class="dropdown-item text-wrap"
                                data-bs-placement="top "><span>{{ __('Add New Tax') }}</span></a>
                            <a href="#" data-size="md" data-url="{{ route('category.create') }}"
                                data-ajax-popup="true" data-title="{{ __('Create Category') }}"
                                class="dropdown-item text-wrap"
                                data-bs-placement="top"><span>{{ __('Add New Category') }}</span></a>
                            <a href="#" data-size="md" data-url="{{ route('coupon.create') }}" data-ajax-popup="true"
                                data-title="{{ __('Create Coupon') }}" class="dropdown-item text-wrap"
                                data-bs-placement="top "><span>{{ __('Add New Coupon') }}</span></a>
                        </div>
                    </li>
                    
                 
                    @endif

                    @if (auth()->user() && auth()->user()->type == 'admin')
                    <li class="dash-h-item drp-language menu-lnk has-item">
                        @php
                        $activeStore = getCurrentStore();
                        $store = \App\Models\Store::find($activeStore);
                        $stores = auth()->user()->stores;
                        @endphp
                        <a class="dash-head-link arrow-none me-0 h-100 megamenu-btn"
                            data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false"
                            data-bs-placement="bottom" data-bs-original-title="Select Store">
                            <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:24:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:25:6" data-component-name="circle" cx="13.5" cy="6.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:26:6" data-component-name="circle" cx="17.5" cy="10.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:27:6" data-component-name="circle" cx="8.5" cy="7.5" r=".5"></circle><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:28:6" data-component-name="circle" cx="6.5" cy="12.5" r=".5"></circle><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:29:6" data-component-name="path" d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"></path></svg>
                            <span class="hide-mob">{{ ucfirst($store->name ?? '') }}</span>
                            <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                        </a>
                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end px-2">
                            <input type="text" id="searchInput" class="form-control mb-2"
                                placeholder="{{ __('Search...') }}">
                            <div id="storeList" style="max-height: 200px; overflow-y: auto;">
                                @if (auth()->user()->type == 'admin')
                                @foreach ($stores as $store)
                                @if ($store->is_active)
                                {{-- P2-SEC-F12: switching the tenant pointer is a POST with a CSRF
                                     token, not a GET link. The current store stays a plain <a>
                                     because it changes nothing. --}}
                                @if ($activeStore == $store->id)
                                <a href="#" class="dropdown-item">
                                    <i class="ti ti-checks text-primary"></i>
                                    {{ ucfirst($store->name) }}
                                </a>
                                @else
                                <form method="POST" action="{{ route('change.store', $store->id) }}" class="m-0">
                                    @csrf
                                    <button type="submit" class="dropdown-item">
                                        {{ ucfirst($store->name) }}
                                    </button>
                                </form>
                                @endif
                                @else
                                <a href="#!" class="dropdown-item">
                                    <i class="ti ti-lock"></i>
                                    <span>{{ $store->name }}</span>
                                    @if (isset(auth()->user()->type))
                                    @if (auth()->user()->type == 'admin')
                                    <span class="badge bg-dark">{{ __(auth()->user()->type) }}</span>
                                    @else
                                    <span class="badge bg-dark">{{ __('Shared') }}</span>
                                    @endif
                                    @endif
                                </a>
                                @endif
                                @endforeach
                                @else
                                @foreach ($user->stores as $store)
                                @if ($store->is_active)
                                <a href="#" class="dropdown-item">
                                    @if ($activeStore == $store->id)
                                    <i class="ti ti-checks text-primary"></i>
                                    @endif
                                    {{ ucfirst($store->name) }}
                                </a>
                                @endif
                                @endforeach
                                @endif
                            </div>
                        <a href="{{ route('stores.create') }}"
                            class="dropdown-item border-top py-1 text-primary d-flex align-items-center gap-1"
                            ><i class="ti ti-circle-plus"></i>
                            <span class="create-store"> {{ __('Create New Store') }}</span>
                        </a>
                        </div>

                    </li>
                    @endif

                    <li class="dropdown dash-h-item drp-language">
                        <a class="dash-head-link dropdown-toggle  arrow-none me-0 h-100" data-bs-toggle="dropdown"
                            href="#" role="button" aria-haspopup="false" aria-expanded="false">
                            <svg data-replit-metadata="client/src/components/icons/CustomIcons.tsx:14:4" data-component-name="svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#060606" stroke-width="2" class="w-4 h-4"><circle data-replit-metadata="client/src/components/icons/CustomIcons.tsx:15:6" data-component-name="circle" cx="12" cy="12" r="10"></circle><line data-replit-metadata="client/src/components/icons/CustomIcons.tsx:16:6" data-component-name="line" x1="2" y1="12" x2="22" y2="12"></line><path data-replit-metadata="client/src/components/icons/CustomIcons.tsx:17:6" data-component-name="path" d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>
                            <span>{{ Str::upper($currentLanguage) }}</span>
                            <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                        </a>

                        <div class="dropdown-menu dash-h-dropdown dropdown-menu-end">
                            @foreach ($displaylang as $key => $lang)
                            @if(isset($setting['disable_lang']) && str_contains($setting['disable_lang'], $key))
                            @unset($key)
                            @continue
                            @endif
                            <a href="{{ route('change.language', $key) }}"
                                class="dropdown-item {{ $currentLanguage == $key ? 'text-primary' : '' }}">
                                <span>{{ Str::ucfirst($lang) }}</span>
                            </a>
                            @endforeach
                            @if (auth()->user() && auth()->user()->type == 'super admin')
                            <a href="{{ route('manage.language', [auth()->user()->language]) }}"
                                class="dropdown-item border-top py-1 text-primary">{{ __('Manage Languages') }}
                            </a>
                            @endif
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </header>