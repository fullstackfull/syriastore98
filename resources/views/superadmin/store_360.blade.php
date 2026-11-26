{{--
    ADM-002 / GAP-232 — STORE 360°: ONE STORE.
    =============================================================================================

    THE RECOVERED DECISION, VERBATIM: `مساحة Store 360° لمراجعة حالة كل متجر وإدارته من أنظمته
    الأصلية` — a Store 360° space to review the state of each store AND MANAGE IT FROM ITS OWN
    SYSTEMS. The last clause is why this screen ends in a list of doors rather than in a form: the
    state is read here, and management happens in the merchant's own screens, through the routes
    that already exist with the gates they already have.

    `@extends` + `@section`, NEVER the `<x-*-layout>` slot shape — `layouts/app.blade.php` holds
    zero `$slot` references, so the component shape renders perfect chrome, HTTP 200 and an EMPTY
    content area. `tests/Unit/Ui/SlotLayoutViewsRenderTest` fails on it.

    NOTHING HERE COMPUTES, COUNTS, SUMS OR FORMATS. Every figure comes from
    `App\Support\Operator\StoreThreeSixty::forStore()`.

    THE MONEY ROWS ARE NEVER ADDED TOGETHER, and this file must not be "improved" by totalling
    them: the amounts are in different currencies and converting them would need the exchange rate
    on each order's own date, which this schema does not record. One row per currency, no total,
    exactly as `platform/analytics` does it.

    BILINGUAL BY CONSTRUCTION: every string this file WRITES is `__()`, and direction comes from
    the layout's own `dir` -- there is no hard-coded direction and no directional margin here.
    `StoreThreeSixtyMessagesAreDeclaredTest` asserts every one of those keys is declared in
    `en.json` AND `ar.json` with real Arabic in the value, because `__()` returns its key and an
    undeclared sentence renders in English on the Arabic page.

    THE TWO THINGS THAT ARE NOT `__()` ARE DATA, AND SAYING SO BEATS AN OVERCLAIM. A support
    bucket's label is `support_tickets.status` as the database holds it -- the set is
    `SupportTicket::STATUSES`, which the model declares in English, and inventing Arabic for a free
    string column the vendored modules also write would be guessing at data rather than translating
    an interface. The money cells come from `MoneyText::amount()`, already formatted and already
    wrapped in a bidi isolate by the metric layer, which is what stops a negative rendering as
    "USD 20.00-" on an Arabic page.
--}}
@extends('layouts.app')

@section('page-title')
    {{ $facts['identity']['name'] }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item">
        <a class="ss-breadcrumb__link" href="{{ route('platform.stores.index') }}">{{ __('Store 360') }}</a>
    </li>
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ $facts['identity']['name'] }}
    </li>
@endsection

@section('content')
{{-- IDENTITY. What this store is, and who answers for it. --}}
<div class="row mb-4">
    <div class="col-12">
        <x-ss.card :title="__('This store')">
            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('What this store is: its address, theme, language and whether its storefront is live.') }}
                    </caption>
                    <tbody>
                        <tr>
                            <th scope="row">{{ __('Address') }}</th>
                            <td>{{ $facts['identity']['slug'] }}</td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Storefront') }}</th>
                            <td>
                                @if ($facts['identity']['is_active'])
                                    <x-ss.status-badge tone="success" :label="__('Active')" />
                                @else
                                    <x-ss.status-badge tone="neutral" :label="__('Inactive')" />
                                @endif
                            </td>
                        </tr>
                        <tr>
                            {{-- `Storefront theme`, not `Theme`: the vendored Arabic for
                                 the bare word is `الموضوع`, which means a topic. --}}
                            <th scope="row">{{ __('Storefront theme') }}</th>
                            <td>
                                {{ $facts['identity']['theme'] !== '' ? $facts['identity']['theme'] : __('Not set') }}
                                @if ($facts['identity']['theme'] === '')
                                    {{-- NOT COSMETIC. `config('theme.active')` is `stylique`, so a
                                         store with an empty `theme_id` renders STYLIQUE's views and
                                         not the fallback's — an operator reading "not set" as "the
                                         default" would be wrong about which four copies of
                                         `front_end/layouts/app.blade.php` are in play. --}}
                                    <span class="ss-field__hint d-block">
                                        {{ __('A store with no theme recorded still renders a theme: the platform default is used.') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Language') }}</th>
                            <td>{{ $facts['identity']['language'] !== '' ? $facts['identity']['language'] : __('Not set') }}</td>
                        </tr>
                        <tr>
                            <th scope="row">{{ __('Owner') }}</th>
                            <td>
                                @if ($facts['identity']['owner'] === null)
                                    <x-ss.status-badge tone="danger" :label="__('Owner record missing')" />
                                    <span class="ss-field__hint d-block">
                                        {{ __('This store has no owner account. Nothing on this screen can be managed from the merchant\'s own systems until that is resolved.') }}
                                    </span>
                                @else
                                    {{ $facts['identity']['owner']['name'] }}
                                    <span class="ss-field__hint d-block">{{ $facts['identity']['owner']['email'] }}</span>
                                    @unless ($facts['identity']['owner']['login_enabled'])
                                        <x-ss.status-badge tone="warning" :label="__('Sign-in disabled')" />
                                    @endunless
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-ss.card>
    </div>
</div>

{{-- SUBSCRIPTION. The store's SaaS state, which hangs off the OWNER and not off the store row. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Subscription') }}</h2>
        <p class="ss-muted">
            {{ __('A plan belongs to the merchant, not to one store. A merchant who owns several stores has one subscription covering all of them, so this block can read the same on two different stores.') }}
        </p>

        <x-ss.stats :label="__('Subscription')">
            <x-ss.stat :label="__('Plan')"
                       :value="$facts['subscription']['plan_name'] !== null && $facts['subscription']['plan_name'] !== '' ? $facts['subscription']['plan_name'] : __('None recorded')"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Subscription ends')"
                       :value="$facts['subscription']['expires_on'] ?: __('No end date recorded')"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Plan payments for this store')"
                       :value="$facts['subscription']['plan_orders']"
                       :period="__('All time')"
                       :href="route('plan.index')" />
            <x-ss.stat :label="__('Refunded plan payments')"
                       :value="$facts['subscription']['plan_orders_refunded']"
                       :period="__('All time')" />
            <x-ss.stat :label="__('Plan requests from this owner')"
                       :value="$facts['subscription']['owner_plan_requests']"
                       :period="__('All time')"
                       :href="route('plan-request.index')" />
        </x-ss.stats>

        <p class="ss-stat__hint">
            {{ __('Plan requests are recorded against the merchant and carry no store, so this figure covers every store the owner has.') }}
        </p>
    </div>
</div>

{{-- ORDERS. Lifecycle only; the money question is answered by its own block below. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Orders') }}</h2>

        <x-ss.stats :label="__('Orders')">
            <x-ss.stat :label="__('Orders in this store')"
                       :value="$facts['orders']['total']"
                       :period="__('All time')" />
            @foreach ($facts['orders']['by_status'] as $bucket)
                <x-ss.stat :label="$bucket['label']"
                           :value="$bucket['orders']"
                           :period="__('All time')" />
            @endforeach
        </x-ss.stats>

        @if ($facts['orders']['unclassified'] > 0)
            {{-- A NON-ZERO HERE IS A FINDING, NOT PADDING. The buckets above are the nine states
                 `App\Support\Api\OrderStatusContract` declares; anything else is a row whose
                 lifecycle column holds NULL or a value no screen in the product can name. It is
                 rendered rather than folded into a bucket because folding it is how it would
                 never be fixed. --}}
            <x-ss.attention severity="warning" :title="__('Orders in an unrecognised state')">
                <x-ss.attention-item :count="$facts['orders']['unclassified']"
                                     :text="__('orders in this store hold a status the product cannot name. They are counted in the total above and in none of the states.')" />
            </x-ss.attention>
        @endif
    </div>
</div>

{{-- MONEY. One row per currency and no total. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Order value') }}</h2>
        <p class="ss-muted">
            {{ __('What shoppers ordered from this store, one row per currency. The rows are not added together: they are different currencies and this schema records no exchange rate for an order\'s own date.') }}
        </p>

        @if ($facts['money']['rows'] === [])
            <p>{{ __('Not available') }}</p>
        @else
            <div class="table-responsive">
                <table class="table">
                    <caption class="ss-visually-hidden">
                        {{ __('Order value in this store, one row per currency. The rows are not added together.') }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('Currency') }}</th>
                            <th scope="col">{{ __('Ordered') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($facts['money']['rows'] as $row)
                            <tr>
                                <th scope="row">{{ $row['currency'] }}</th>
                                <td>{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- THE METRIC'S OWN CAVEATS, never a sentence this screen invented. --}}
        @foreach ($facts['money']['caveats'] as $caveat)
            <p class="ss-stat__hint">{{ $caveat }}</p>
        @endforeach
    </div>
</div>

{{-- CATALOGUE AND SHOPPERS. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Catalogue and shoppers') }}</h2>

        <x-ss.stats :label="__('Catalogue and shoppers')">
            <x-ss.stat :label="__('Products')"
                       :value="$facts['catalogue']['products']"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Published products')"
                       :value="$facts['catalogue']['active']"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Out of stock')"
                       :value="$facts['catalogue']['out_of_stock']"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Categories')"
                       :value="$facts['catalogue']['categories']"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Shoppers registered')"
                       :value="$facts['shoppers']['customers']"
                       :period="__('Now')" />
            <x-ss.stat :label="__('Carts left open')"
                       :value="$facts['shoppers']['open_carts']"
                       :period="__('Now')" />
        </x-ss.stats>

        <p class="ss-stat__hint">
            {{ __('Only a product whose stock is tracked can be out of stock. A product that does not track stock is counted in neither figure.') }}
        </p>
    </div>
</div>

{{-- SUPPORT. --}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-3">{{ __('Support') }}</h2>

        <x-ss.stats :label="__('Support')">
            <x-ss.stat :label="__('Tickets in this store')"
                       :value="$facts['support']['tickets']"
                       :period="__('All time')" />
            @foreach ($facts['support']['by_status'] as $bucket)
                <x-ss.stat :label="$bucket['status']"
                           :value="$bucket['tickets']"
                           :period="__('All time')" />
            @endforeach
        </x-ss.stats>

        @if ($facts['support']['unclassified'] > 0)
            <x-ss.attention severity="warning" :title="__('Tickets in an unrecognised state')">
                <x-ss.attention-item :count="$facts['support']['unclassified']"
                                     :text="__('tickets in this store hold a status the product cannot set. They are counted in the total above and in none of the states.')" />
            </x-ss.attention>
        @endif
    </div>
</div>

{{--
    THE DOORS — the `من أنظمته الأصلية` half of ADM-002.

    EVERY ENTRY IS AN EXISTING ROUTE WITH ITS EXISTING GATE. Nothing here is a new capability:
    `login.with.admin` still runs `$this->authorize('impersonate', $user)`, and `plan.upgrade`
    still refuses a non-operator in its own handler. What the 360° adds is that they are all in one
    place, for one store, beside the state that made the operator want them.
--}}
<div class="row mb-4">
    <div class="col-12">
        <h2 class="h5 mb-1">{{ __('Manage this store from its own systems') }}</h2>
        <p class="ss-muted">
            {{ __('These open the merchant\'s own screens. Nothing on this page edits the store itself, so what you change is changed where the merchant would change it.') }}
        </p>

        @if ($facts['doors'] === [])
            <x-ss.empty :title="__('No systems to open')"
                        :body="__('Every door needs an owner account, and this store has none recorded.')"
                        icon="door-off" />
        @else
            <ul class="list-unstyled mb-0">
                @foreach ($facts['doors'] as $door)
                    <li class="mb-3">
                        <a class="ss-link" href="{{ $door['url'] }}">{{ $door['label'] }}</a>
                        <span class="ss-field__hint d-block">{{ $door['hint'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
@endsection
