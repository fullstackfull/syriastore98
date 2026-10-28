@extends('layouts.app')

@section('page-title', __('Notifications'))

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Notifications') }}</li>
@endsection

@section('content')
{{--
    P2-06-3 -- the merchant's notifications on the web.

    THE SAME ROWS THE APP ALREADY READS. `store_notifications` is written by
    StoreNotificationService on every order created and paid, and the shipped
    Flutter app reads them through `api/admin/notification`. Nothing in the web
    admin read them at all, so a merchant at a desk had no notifications and a
    merchant with the app had them. No schema change; the API is untouched.

    NOT A DATATABLE, deliberately. Notifications are a short reverse-chronological
    feed that a merchant scans and clears, not a dataset they sort, filter, export
    or paginate — and the `<x-datatable>` chrome (search box, length menu, export
    and reload buttons) would be four controls nobody wants on fifty rows. The
    list is capped in the controller at 50 for the same reason; the number that
    matters is the unread count at the top.

    EVERY ROW'S TEXT IS DATABASE-SOURCED and goes through Blade's escaping. That
    matters more here than it looks: `message` is built from an order reference and
    `title` from a service literal, and F5 on this phase's ledger is the same
    shape unescaped in the sidebar.
--}}
<div class="ss-scope">
    <x-ss.page-header :title="__('Notifications')"
                      :subtitle="$unread > 0
                            ? __(':n unread', ['n' => $unread])
                            : __('Nothing unread')">
        <x-slot:actions>
            @if ($unread > 0)
                {{--
                    POST, with the token. A state-changing GET is reachable from a
                    third-party <img> and by link prefetching — F12 and F14 on this
                    phase's ledger, and not repeated here.
                --}}
                <form method="POST" action="{{ route('merchant.notifications.read-all') }}"
                      class="d-inline" data-ss-mark-all>
                    @csrf
                    <button type="submit" class="ss-btn ss-btn--secondary">
                        {{ __('Mark all as read') }}
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-ss.page-header>

    @if ($noTenant)
        {{--
            NO STORE IS NOT A PERMISSIONS PROBLEM, and this branch exists because
            the controller used to answer both with the same sentence. A merchant
            whose store was deleted or deactivated was told their role did not
            include reading notifications, which is a wrong and unhelpfully
            specific explanation. Found by the independent review.
        --}}
        <x-ss.empty :title="__('No store selected')"
                    :body="__('Notifications belong to a store, and no store is currently active for your account. Choose a store, or ask the platform administrator if you expected one.')"
                    icon="building-store" />
    @elseif ($readableTypes === [])
        {{--
            NO READABLE TYPE IS NOT THE SAME FACT AS NO NOTIFICATIONS, and saying
            so is the honest wording. A staff member who may not read orders has
            notifications in their store that they are not allowed to see; telling
            them the list is empty would be a lie, and telling them a count would
            be the leak the type gate exists to prevent.
        --}}
        <x-ss.empty :title="__('No notifications you can see')"
                    :body="__('Notifications in this store are about orders, and your role does not include reading them. Ask the store owner if you need access.')"
                    icon="lock" />
    @elseif ($notifications->isEmpty())
        <x-ss.empty :title="__('No notifications yet')"
                    :body="__('New orders and payments will appear here as they happen.')"
                    icon="bell" />
    @else
        <ul class="ss-feed" role="list">
            @foreach ($notifications as $notification)
                <li class="ss-feed__item {{ $notification->is_read ? '' : 'ss-feed__item--unread' }}">
                    <div class="ss-feed__main">
                        <p class="ss-feed__title">
                            {{ $notification->title }}
                            @unless ($notification->is_read)
                                {{-- The EXISTING badge component, not a bespoke class. Its
                                     `brand` tone is already defined for both themes
                                     (`--ss-brand-subtle` / `--ss-brand`); a one-off colour pair
                                     here would be a tone nobody had checked in dark mode, which
                                     is exactly what F8 on this phase's ledger was. --}}
                                <x-ss.status-badge tone="brand" :label="__('Unread')" />
                            @endunless
                        </p>
                        <p class="ss-feed__message">{{ $notification->message }}</p>
                        @if ($notification->created_at)
                            <p class="ss-feed__when">
                                <time datetime="{{ $notification->created_at->toIso8601String() }}">
                                    {{ $notification->created_at->isoFormat('D MMM YYYY, HH:mm') }}
                                </time>
                            </p>
                        @endif
                    </div>

                    <div class="ss-feed__actions">
                        @if ($mayOpenOrders && $notification->reference_type === 'order' && $notification->reference_id)
                            {{--
                                The PREVIEW, not the full order page: it is the read-only
                                surface and its own uri is registered in
                                OrderDocumentAccessGuard, which answers again on that
                                request. Reaching this row is not authorisation for it.

                                `$mayOpenOrders` IS A SEPARATE QUESTION from being allowed to
                                read the notification. The notification gates on `Manage Order`;
                                the preview gates on `Show Order` (OrderController's
                                `mayUseOrderScreen`). A caller can hold the first and not the
                                second, and without this the button answered 403 — the preview
                                fails closed, so this is a usability fix, not a security one.
                            --}}
                            <button type="button"
                                    data-ss-panel-open="ss-order-preview-panel"
                                    data-ss-panel-url="{{ route('order.preview', $notification->reference_id) }}"
                                    class="ss-btn ss-btn--secondary">
                                {{ __('View order') }}
                            </button>
                        @endif

                        @unless ($notification->is_read)
                            <form method="POST"
                                  action="{{ route('merchant.notifications.read', $notification->id) }}"
                                  class="d-inline">
                                @csrf
                                <button type="submit" class="ss-btn ss-btn--ghost">
                                    {{ __('Mark as read') }}
                                </button>
                            </form>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($notifications->count() >= 50)
            <x-ss.help>{{ __('Showing the 50 most recent notifications.') }}</x-ss.help>
        @endif
    @endif

    {{-- ONE panel for the whole feed, filled per row — the same arrangement the
         order and product lists use, so the focus-return bookkeeping in
         store-syria-ds.js has a single id to key on. --}}
    <x-ss.panel id="ss-order-preview-panel"
                :title="__('Order preview')"
                :loading-text="__('Loading order…')"
                :error-text="__('This order could not be loaded.')" />
</div>
@endsection
