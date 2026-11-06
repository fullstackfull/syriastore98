@extends('layouts.app')

@section('page-title', __('Order'))

@section('action-button')
<div class="text-end d-flex flex-wrap gap-2 justify-content-end">
    <a class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary export-btn" href="{{ route('order.export') }}" aria-label="{{ __('Export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Order') }}">
        <i class="ti ti-file-export" aria-hidden="true"></i>
    </a>
    <a href="{{ route('order.index') }}" aria-label="{{ __('List View') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary">
        <i class="ti ti-list" aria-hidden="true"></i>
    </a>
    {{-- P2-04-12: the board's entry point. The route existed from P2-04-7 with nothing linking
         to it, so the board was reachable only by typing the URL -- every one of that slice's
         twelve tests drove `route('order.kanban')` directly and so none of them could notice.
         `request()->query()` carries the active filters across, which is the same contract
         `kanban.blade.php`'s own link back to this list keeps. Classed like its three siblings
         rather than converted alone -- and D-25 Step 2 then converted all four together, which is
         what "as one unit" was waiting for. --}}
    <a href="{{ route('order.kanban', request()->query()) }}" aria-label="{{ __('Board View') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Board View') }}" class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary">
        <i class="ti ti-layout-kanban" aria-hidden="true"></i>
    </a>
</div>
@endsection

@section('breadcrumb')
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Order') }}</li>
@endsection

@section('content')
<div class="row mb-4 d-flex order-card-wrp">
    @foreach ($orders as $order)
    @php
        /* THE STATUS LABEL. It is rendered as a labelled design-system badge in the card header
           below, so the status is now VISIBLE TEXT. It used to be the hue of an icon and nothing
           else: Cancel was `ti ti-check text-danger` and Comfirmed `ti ti-check text-primary` --
           the SAME glyph, separated by a colour utility, which is WCAG 1.4.1. On the `theme-5`
           preset the merchant's own --theme-color is #b9406b, 5.5 hue-degrees from text-danger,
           so the two were not merely colour-dependent but the same colour; and measured in the
           running dark admin the Comfirmed glyph was #335DA8 on the #292A33 card = 2.22:1, under
           WCAG 1.4.11's 3:1 as well.

           THE LAST THREE BRANCHES ARE NEW AND WERE NOT OPTIONAL. delivered_status 7
           (Partially Paid) and 8 (Pre Order) -- both real values the host stores in this column --
           and a bare 3 with no return_status of 1, 2 or 3 had NO branch, so all three fell
           through to the initialiser above and were labelled "Pending". A tooltip nobody triggers
           hides a wrong label; a badge shows it. Every mapping here is pinned by
           Tests\Unit\Ui\OrderGridStatusLabelTest, which EVALUATES this block rather than
           reading it, because the mapping is the whole risk of making the label visible.

           THE THREE RETURN SUB-STATES KEEP THREE DISTINCT LABELS. They share ONE tone (danger,
           from App\Support\OrderStatusTone, the same table the order list and the board already
           agree on) and that is deliberate -- but the Approve/Cancel buttons below render only for
           return_status == 1, so one shared LABEL would leave a card reading "Return" with two
           approve buttons on it and no way to tell it from a settled return.

           `$btn_class` IS GONE. It existed only to colour the glyph in the anchor the badge
           replaces, and nothing else in this view read it. The tone comes from OrderStatusTone now.

           `Comfirmed` is the host's own misspelling and tests/Feature/ApiContract/
           OrderStatusTextTest pins it for the API. Correcting it HERE only would make the web and
           the API disagree, so it stays until all four surfaces change together. */
        $title = __('Pending');
        if ($order->delivered_status == 0) {
            $title = __('Pending');
        } elseif ($order->delivered_status == 1) {
            $title = __('Delivered');
        } elseif ($order->delivered_status == 2) {
            $title = __('Cancel');
        } elseif ($order->delivered_status == 4) {
            $title = __('Comfirmed');
        } elseif ($order->delivered_status == 5) {
            $title = __('Picked Up');
        } elseif ($order->delivered_status == 6) {
            $title = __('Shipped');
        } elseif ($order->delivered_status == 3 && $order->return_status == 1) {
            $title = __('Return request processing');
        } elseif ($order->delivered_status == 3 && $order->return_status == 2) {
            $title = __('Return');
        } elseif ($order->delivered_status == 3 && $order->return_status == 3) {
            $title = __('Return cancelled');
        } elseif ($order->delivered_status == 3) {
            $title = __('Return');
        } elseif ($order->delivered_status == 7) {
            $title = __('Partially Paid');
        } elseif ($order->delivered_status == 8) {
            $title = __('Pre Order');
        }
    @endphp
    <div class="col-xxl-3 col-xl-4 col-sm-6 col-12 d-flex">
        <div class="card user-card order-card border mb-0">
            {{--
                P2.5-PKG-C. THE HIERARCHY, which this card did not have. Earlier slices fixed real
                things here -- the status is a design-system badge, the money carries `.ss-num`,
                the date was added, an unclosed `<span>` was closed -- but the ORDER was still the
                least prominent thing on an order card:

                  * `#P25PROBE-41`, the order's own reference, sat in the FOOTER as a filled
                    `btn btn-primary` pill. The most identifying fact about an order, rendered as
                    a button and placed last. Exactly the shape just corrected on the sibling
                    customer card, where the person's NAME was a `badge bg-primary`.
                  * the CUSTOMER'S name was the de-facto title, so an order card was titled by
                    someone else's identity;
                  * `<b>Price : </b>` and `<b>Date : </b>` put the labels at the same weight as
                    their values, so the two things a merchant scans for -- how much, and when --
                    competed with the words "Price" and "Date";
                  * `user-img-wrp` again named a wrapper for an avatar that does not exist, and
                    `align-items-center` centred the one-line badge against a three-line block.

                NOW: the order reference is the title and keeps its link (same route, same
                `Crypt::encrypt($order->id)`), the status sits beside it, the customer is meta, the
                price is the one figure and the date is meta beside it. Same grammar as the
                customer grid card -- identity, then the figure that carries value, then the
                actions -- so the two card views can be read the same way.

                THE TWO BOOTSTRAP REGION CLASSES ARE KEPT DELIBERATELY. `OrderGridStatusLabelTest`
                finds the header and the body by searching this file for their opening div class
                attributes, then asserts the status badge is rendered inside the header.

                AND THIS COMMENT MUST NOT SPELL THOSE TWO MARKERS OUT, which the first draft did:
                writing them here put an earlier match in the file than the real elements, the test
                extracted a few characters of prose as "the header", and the badge assertion failed
                against it. Documenting a marker inside the file it marks is enough to break it.

                THE MONEY EXPRESSION IS BYTE-IDENTICAL. `order_currency_format_with_sym(...)` and
                its fallback are untouched; only the type treatment around them changed. This is a
                presentation change and nothing else.
            --}}
            <div class="card-header p-3 border-bottom h-100">
                <div class="d-flex align-items-start justify-content-between gap-2">
                    {{-- `dir="ltr"` IS LOAD-BEARING, AND THE ARABIC CAPTURE IS WHAT CAUGHT IT.
                         Rendered RTL, this read `P25PROBE-41#` -- the `#` reordered to the far
                         end of the run. A `#` is a BIDI-NEUTRAL character, so in a right-to-left
                         paragraph a leading neutral before a Latin identifier is resolved to the
                         other side of it. An order reference is a Latin identifier and must read
                         the same in both directions.
                         The price beside it was already correct for exactly this reason: `.ss-num`
                         carries `unicode-bidi: isolate`, which is why `$10.00` kept its symbol on
                         the left. Same hazard, same remedy, and the same one applied to the
                         customer card's email address. --}}
                    <a class="ss-card__title text-truncate" dir="ltr"
                       href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
                       data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}">#{{ $order->product_order_id }}</a>
                    {!! \App\Support\OrderStatusTone::badge((int) $order->delivered_status, $title) !!}
                </div>

                <span class="ss-card__meta d-block text-truncate mt-1">
                    @if ($order->is_guest == 1)
                        {{ __('Guest') }}
                    @elseif ($order->customer_id != 0)
                        {{ (!empty($order->CustomerData->name) ? $order->CustomerData->name : '') }}@if (!empty($order->CustomerData->mobile)) <span dir="ltr">{{ $order->CustomerData->mobile }}</span>@endif
                    @else
                        {{ __('Walk-in-customer') }}
                    @endif
                </span>

                <div class="d-flex align-items-end justify-content-between gap-3 mt-3">
                    <div class="min-w-0">
                        {{-- `.ss-num` STAYS A NESTED SPAN rather than being merged onto the
                             outer element. Two ratchets assert the figure that way -- the source
                             one in OrderGridCardContentTest and, more valuably, the RENDERED one
                             in OrderGridCardRendersTheDateTest, which drives the real page. The
                             nesting costs one element and keeps both checks exactly as they were
                             written; merging the class would have meant editing two guards to suit
                             a styling choice. --}}
                        <span class="ss-stat__value d-block ss-order-card__money"><span class="ss-num">{{ order_currency_format_with_sym(($order->final_price ?? 0), $order->id) ?? SetNumberFormat($order->final_price) }}</span></span>
                        <span class="ss-stat__label">{{ __('Price') }}</span>
                    </div>
                    {{-- THE LABEL IS VISUALLY HIDDEN, NOT DELETED. A date in a card corner is
                         self-evident to a sighted merchant, and `<b>Date : </b>` at body weight
                         competed with the value it introduced -- so the visible label goes. But a
                         screen reader would then announce a bare "Nov 6, 2026" with nothing
                         saying what it dates, so the word stays in the accessibility tree.
                         Removing the label outright would have traded one audience's clarity for
                         another's. --}}
                    <span class="ss-card__meta ss-order-card__date text-end"><span class="visually-hidden">{{ __('Date') }}</span><span class="ss-num">{{ \App\DataTables\OrderDataTable::dateOrDash($order->order_date) }}</span></span>
                </div>
            </div>
            <div class="card-body p-3 gap-1 d-flex align-items-center justify-content-end">
                <div class="bottom-icons d-flex flex-wrap align-items-center">
                    @if ($order->delivered_status == 3 && $order->return_status == 1)
                        <a href="#" class="btn btn-sm btn-primary return_request me-2" data-id="{{ $order->id }}" data-status="2" data-bs-toggle="tooltip"
                            title="{{ __('Approve') }}"
                            aria-label="{{ __('Approve the return of order :id', ['id' => $order->product_order_id]) }}">
                            <i class="ti ti-check" aria-hidden="true"></i>
                        </a>
                        <a href="#" class="btn btn-sm btn-danger return_request me-2" data-id="{{ $order->id }}" data-status="3" data-bs-toggle="tooltip"
                            title="{{ __('Cancel') }}"
                            aria-label="{{ __('Reject the return of order :id', ['id' => $order->product_order_id]) }}">
                            <i class="ti ti-circle-x" aria-hidden="true"></i>
                        </a>
                    @endif
                    <a href="javascript:void(0)"
                        data-url="{{ route('order.order_view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" data-size="lg"
                        data-ajax-popup="true" data-title="{{ __('Order') }}    #{{ $order->product_order_id }}"
                        class="btn btn-sm border me-2" data-bs-toggle="tooltip"
                        title="{{ __('Show') }}"
                        aria-label="{{ __('Show order :id', ['id' => $order->product_order_id]) }}">
                        <i class="ti ti-eye" aria-hidden="true"></i>
                    </a>
                    <a href="{{ route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}"
                        class="btn btn-sm border me-2" data-bs-toggle="tooltip"
                        title="{{ __('Edit') }}"
                        aria-label="{{ __('Edit order :id', ['id' => $order->product_order_id]) }}">
                        <i class="ti ti-pencil" aria-hidden="true"></i>
                    </a>
                    {!! Form::open(['method' => 'DELETE', 'route' => ['order.destroy', $order->id], 'class' => 'd-inline']) !!}
                    {{-- `aria-label` MUST STAY LAST ON THIS BUTTON, AFTER `data-confirm`.
                         Tests\Unit\Ui\ConfirmationDialogContractTest scans every merchant view
                         with /<[^>]*\bshow_confirm\b[^>]*>/. The aria-label below contains
                         `['id' => ...]`, i.e. a `>`, so moving it ahead of `data-confirm` makes
                         that character class stop mid-attribute and the test then reports this
                         button as carrying no translated confirmation message -- which is false.
                         A `>` inside a Blade expression silently truncating a `[^>]*` scanner has
                         already cost this repo two missed fields (see
                         Tests\Unit\Ui\SettingsCredentialExposureTest). Pinned directly by
                         Tests\Unit\Ui\OrderGridRowActionNamesTest, which fails at this cause
                         instead of letting it surface as a false i18n failure. --}}
                    <button type="button" class="btn btn-sm border show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"
                        title="{{ __('Delete') }}"
                        aria-label="{{ __('Delete order :id', ['id' => $order->product_order_id]) }}">
                        <i class="ti ti-trash text-danger" aria-hidden="true"></i>
                    </button>
                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>
{!! $orders->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
    <script>
        $(document).on('click', '.code', function() {
            var type = $(this).val();
            $('#code_text').addClass('col-md-12').removeClass('col-md-8');
            $('#autogerate_button').addClass('d-none');
            if (type == 'auto') {
                $('#code_text').addClass('col-md-8').removeClass('col-md-12');
                $('#autogerate_button').removeClass('d-none');
            }
        });

        $(document).on('click', '.return_request', function() {
            var id = $(this).attr('data-id');
            var status = $(this).attr('data-status');
            var data = {
                id: id,
                status: status
            }
            $.ajax({
                url: '{{ route('order.return.request') }}',
                method: 'POST',
                data: data,
                context:this,
                success: function (response)
                {
                    $('#loader').fadeOut();
                    if(response.status == 'error') {
                        show_toastr('{{ __('Error') }}', response.message, 'error')
                    } else {
                        show_toastr('{{ __('Success') }}', response.message, 'success')
                        $(this).parent().find('.return_request').remove();
                    }
                },
                error: function (xhr) {
                    /*
                     * P1-INV-15 (F). There was NO error: handler, so every non-2xx answer was a
                     * SILENT no-op: the spinner stayed up and the merchant was told nothing. That
                     * matters now because the return endpoints legitimately answer 403 (P1-INV-02
                     * added the ownership and permission check) and 419 on an expired CSRF token,
                     * and a refusal the user cannot see reads as a broken button.
                     */
                    $('#loader').fadeOut();
                    var message = (xhr.responseJSON && xhr.responseJSON.message)
                        ? xhr.responseJSON.message
                        : (xhr.status === 403
                            ? '{{ __('You are not allowed to do this.') }}'
                            : (xhr.status === 419
                                ? '{{ __('Your session has expired. Please reload the page.') }}'
                                : '{{ __('Something went wrong. Please try again.') }}'));
                    show_toastr('{{ __('Error') }}', message, 'error')
                }
            });
        });

        $(document).on('click', '#code-generate', function() {
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
@endpush