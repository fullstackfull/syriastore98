
@extends('layouts.app')

@section('page-title')
    {{ __('Orders') }}
@endsection

@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('customer.index') }}">{{__('Customer')}}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Orders') }}</li>
@endsection
@section('action-button')
{{-- P2.5 customer-orders-export-dead-cdn -- THE EXPORT CONTROL IS DEFERRED, NOT REPAIRED.

     WAS an icon-only anchor with class `csv`, bound by the script block at the foot of this
     file. That block sourced table2excel from rawgit.com, a domain retired in October 2019
     which answers 404 with a plain-text shutdown notice, so the plugin was never defined --
     and the handler's destructive step ran FIRST: `$('.ignore').remove()` stripped the Action
     column's header and every row's View link and Delete form, the table2excel call then threw
     a TypeError, and the `window.location.reload()` meant to restore the page sat behind the
     throw and never ran. One click on this control emptied the row actions until the merchant
     reloaded by hand; simple-datatables returns the live `tr` from `rows().render()`, so the
     6-cell rows survived paging too.

     WHY THE ONE-LINE SWAP TO the vendored copy in public/js/ WAS NOT TAKEN. `#pc-dt-export`
     below is the ONLY place in this repository where that selector resolves -- customer/index
     and customer/grid render cards and contain zero `<table>`, and the WooCommerce twin aims at
     `#pc-dt-export` while its own table is `#woocom-customer-table`. So pointing this at a
     working plugin would be the FIRST activation of the path repo-wide, and what it would
     deliver is wrong: the table carries `dataTable`, public/js/custom.js builds a
     simpleDatatables instance on it and discards the handle, perPage is 10, and paginate()
     empties the tbody down to the visible page -- so the file would hold 10 of N orders, named
     Order_<n>.xlsx, with nothing to say it was truncated. A silently truncated orders export is
     worse than a visibly broken button. Exporting the whole list needs a server-side route and
     belongs to Phase 04; the vendored plugin also emits vnd.ms-excel HTML under an .xlsx name,
     which Excel and LibreOffice both warn about. --}}
@endsection

@section('filter')
@endsection
@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table mb-0 dataTable" id="pc-dt-export">
                            <thead>
                                <tr>
                                    <th>{{ __('Orders') }}</th>
                                    <th>{{ __('Date') }}</th>
                                    <th>{{ __('Name') }}</th>
                                    <th>{{ __('Value') }}</th>
                                    <th>{{ __('Payment Type') }}</th>
                                    <th>{{ __('Status') }}</th>
                                    <th class="ignore">{{ __('Action') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr>
                                        <td scope="row">
                                            <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
                                                class="btn btn-primary btn-sm text-sm"
                                                data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}">
                                                <span class="btn-inner--icon"></span>
                                                <span class="btn-inner--text">#{{ $order->product_order_id }}</span>
                                            </a>
                                        </td>
                                        <td class="order">
                                            <span
                                                class="h6 text-sm font-weight-bold mb-0">{{ \App\Models\Utility::dateFormat($order->created_at) }}</span>
                                        </td>
                                        <td>
                                            @if ($order->customer_id == 0)
                                                {{ __('Guest') }}
                                            @elseif ($order->customer_id != 0 && !empty($order->CustomerData->name))
                                                {{ $order->CustomerData->name }}
                                            @else
                                                {{ __('Guest') }}
                                            @endif
                                        </td>
                                        <td>
                                            <span>{{ currency_format_with_sym( ($order->final_price ?? 0), getCurrentStore()) ?? SetNumberFormat($order->final_price) }}</span>
                                        </td>
                                        <td>
                                            {{-- F150 -- ONE RESOLVER INSTEAD OF A 27-BRANCH CHAIN WITH NO @else.
                                                 An unmatched payment type rendered an EMPTY CELL here, which is
                                                 worse than a dash: the column simply looked blank, with nothing
                                                 to say whether the order had no payment method or the page did
                                                 not recognise it.

                                                 MEASURED against the whole vocabulary -- the 52 gateway ids, the
                                                 44 storefront paymentList names and the two stored literals: 52
                                                 of the 71 values rendered blank here, POS among them, which for
                                                 a shop using point of sale is most of its orders. Two branches
                                                 were also unreachable by any writer: `'Mercado'` where the
                                                 registry id is `mercado`, and `'DPO'` where it is `dpopay`.

                                                 The dash is the same absence the order list shows, so the two
                                                 merchant-facing surfaces now agree. --}}
                                            {{ \App\Payments\PaymentTypeLabel::for($order->payment_type) ?? '-' }}
                                        </td>
                                        <td>
                                            {{-- P2.5 customer-orders-status-chip-contrast.

                                                 WAS eight inert `<button type="button" class="btn btn-sm btn-* btn-icon
                                                 badge-same">` chips. TWO DEFECTS, and only one of them was colour.

                                                 CONTRAST (WCAG 1.4.3, 4.5:1 for 0.875rem/500 text), measured over the
                                                 vendored card background -- #ffffff light, #292A33 dark:
                                                   btn-info     Pending    2.52:1 both themes (#35abb6 on #e8f8ff)
                                                   btn-success  Delivered  2.50:1 light -- --third-color is declared only
                                                                           under .theme-1..theme-10 and the default body
                                                                           class is `custom-color`, so the fill is invalid
                                                                           and the green sits on the white card
                                                   btn-warning  Confirmed  1.68:1 light
                                                   btn-secondary Picked Up 3.44:1 light / 2.31:1 dark
                                                   btn-soft-dark Shipped   1.10:1 dark -- no such rule exists outside
                                                                           :hover, so it inherits .btn's own #293240
                                                   bg-danger    Cancel,    4.26:1 light / 1.03:1 dark, and its colour is
                                                                Return     !important, so it could not be fixed by ADDING
                                                                           a class beside it
                                                   btn-dark     Pre Order  1.09:1 dark
                                                 All eight failed in at least one theme; none passed both.

                                                 ROLE (WCAG 4.1.2). None of the eight carried type="submit", an onclick,
                                                 a form attribute, a data-bs-* action or a wrapping form, `.btn-icon` is
                                                 declared in no stylesheet and `badge-same` is bound by no script -- so a
                                                 screen reader announced eight "button"s per row that do nothing, and a
                                                 keyboard user tabbed through eight dead stops per order. No stylesheet at
                                                 any specificity can repair that; the markup had to change.

                                                 The tone comes from App\Support\OrderStatusTone, which the order list,
                                                 the kanban and the order detail page already use -- NOT a fresh inline
                                                 map: two private maps are exactly how the dashboard and the order list
                                                 came to disagree about four of the nine statuses. Its tones are opaque
                                                 hexes rather than alpha tints, so the card colour cannot enter the
                                                 calculation: 7.10-9.81:1 across all ten tone x theme pairs.

                                                 UNCHANGED ON PURPOSE: every condition, every label and every date column,
                                                 so `delivered_status` 7 and any unknown value still render an EMPTY cell
                                                 and the Picked Up branch still reads `pickedup_date` (a column that does
                                                 not exist -- it is `picked_date` -- so it renders the epoch). Both are
                                                 real, both are pre-existing, and both have their own ledger row: closing
                                                 them here would make this diff something other than this finding. No
                                                 stylesheet is touched either, so `.badge-same` survives in custom.css AND
                                                 rtl-custom.css for the three vendored DataTables that still emit it. --}}
                                            @php $ssTone = \App\Support\OrderStatusTone::for((int) $order->delivered_status); @endphp
                                        @if ($order->delivered_status == 0)
                                            @php $ssLabel = __('Pending') . ' : ' . \App\Models\Utility::dateFormat($order->order_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 1)
                                            @php $ssLabel = __('Delivered') . ' : ' . \App\Models\Utility::dateFormat($order->delivery_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 4)
                                            @php $ssLabel = __('Confirmed') . ' : ' . \App\Models\Utility::dateFormat($order->confirmed_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 5)
                                            @php $ssLabel = __('Picked Up') . ' : ' . \App\Models\Utility::dateFormat($order->pickedup_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 6)
                                            @php $ssLabel = __('Shipped') . ' : ' . \App\Models\Utility::dateFormat($order->shipped_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 2)
                                            @php $ssLabel = __('Cancel') . ' : ' . \App\Models\Utility::dateFormat($order->cancel_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 3)
                                            @php $ssLabel = __('Return') . ' : ' . \App\Models\Utility::dateFormat($order->return_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @elseif ($order->delivered_status == 8)
                                            @php $ssLabel = __('Pre Order') . ' : ' . \App\Models\Utility::dateFormat($order->order_date); @endphp
                                            <x-ss.status-badge :tone="$ssTone" :label="$ssLabel" />
                                        @endif
                                        </td>
                                        <td class="text-center ignore">
                                            <div class="d-flex">
                                                    {{-- P2.5 -- the SAME DEFECT FAMILY, seven lines from the chip. This icon-only link
                                                         carried `btn-info`: #35abb6 on the OPAQUE #e8f8ff, 2.52:1 in BOTH
                                                         themes, against SC 1.4.11's 3:1 minimum for an icon that carries the
                                                         control's whole meaning. `ss-btn--ghost` is --ss-fg-muted: 6.42:1 on
                                                         the light card, 6.18:1 on the dark one. The icon is aria-hidden
                                                         because Chromium folds the icon font's generated glyph into the
                                                         accessible name, which named this control with a private-use
                                                         codepoint. The three dead Bootstrap 4 attributes (data-toggle,
                                                         data-original-title, data-tooltip) are dropped: BS5 reads none of
                                                         them, and `title` + data-bs-toggle are what render the tooltip. --}}
                                                    <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--ghost me-2" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Show') }}">
                                                        <i class="ti ti-eye" aria-hidden="true"></i>
                                                    </a>
                                                {!! Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']) !!}
                                                {{-- P2.5 -- `btn-danger` is declared with the SAME two values as `bg-danger`
                                                     in all four host sheets (#d80027 on rgba(255,174,189,.41)): 1.03:1 on
                                                     the dark card, on the row's DESTRUCTIVE control, which made it the
                                                     worst reading left in this file once the chip was fixed.
                                                     `ss-btn--danger-quiet` is --ss-danger: 6.54:1 light, 7.48:1 dark.
                                                     `show_confirm` and all four data-* strings stay, and the control stays
                                                     inside Form::open -- that is what arms the confirmation and what
                                                     submits the DELETE. Shape copied from category/action.blade.php. --}}
                                                <button type="button" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--danger-quiet show_confirm" data-bs-toggle="tooltip" data-bs-placement="top" data-confirm="{{ __('Are You Sure?') }}"
                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                    <i class="ti ti-trash" aria-hidden="true"></i>
                                                </button>
                                                {!! Form::close() !!}
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

