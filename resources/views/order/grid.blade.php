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
            <div class="card-header p-3  border-bottom h-100">
                <div class="user-img-wrp d-flex gap-3 align-items-center">
                    {{-- THE STATUS, as the design system's badge: a label a merchant can read,
                         plus a tone, plus a dot. It replaces a 45x45 box holding an inert
                         `<a href="#">` that no handler in the admin was bound to and that no
                         dropdown enclosed -- a status label wearing a control's clothes, which is
                         the same defect the order LIST had and settled by using spans. The badge
                         is a `<span>`, so nothing focusable is reintroduced here.

                         IT IS THE FIRST CHILD OF `.user-img-wrp` ON PURPOSE. The second child's
                         first span is never closed before the row's `</div>`, so anything placed
                         after it is auto-closed INSIDE that span and inherits the dark-mode
                         override P2.5-A-05 puts on it. Asserted by OrderGridStatusLabelTest.

                         `.ss-badge` is unscoped in store-syria-ds.css and its tone tokens are
                         declared on `:root`, so this needs no `.ss-scope` wrapper; the sheet is
                         linked by layouts/app.blade.php on every page. DsBadge escapes the label,
                         which is why the unescaped echo is correct here. --}}
                    {!! \App\Support\OrderStatusTone::badge((int) $order->delivered_status, $title) !!}
                    <div class="user-content">
                        <span class="text-dark text-md">
                            @if ($order->is_guest == 1) 
                                <b>{{ __('Guest') }}</b>
                            @elseif ($order->customer_id != 0) 
                                <b>{{ (!empty($order->CustomerData->name) ? $order->CustomerData->name : '') }}</b>
                                <br>
                                {{ (!empty($order->CustomerData->mobile) ? $order->CustomerData->mobile : '') }}
                            @else 
                                <b>{{__('Walk-in-customer')}}</b>
                            @endif
                        </span>
                        <br>
                        {{-- P2.5-DESIGN-C. THE `<span>` THAT STOOD HERE WAS NEVER CLOSED, and the
                             previous slice had to design around it rather than fix it: the status
                             badge is placed as the first child of `.user-img-wrp` precisely because
                             anything after this span is auto-closed INSIDE it by the parser and
                             inherits the dark-mode colour override on `.text-dark`.
                             CONFIRMED IN THE RAW SERVER BYTES, not only in the source: the HTTP
                             response for a card ends `$10.00` and then `</div>` with no `</span>`
                             between them. Chromium recovers, so the DOM is not mis-nested -- which
                             is exactly why it survived. It is closed now, and the badge's placement
                             keeps its own reason (it reads first, before the customer).

                             THE MONEY IS A FIGURE, NOT A SENTENCE. `Price : $10.00` was a label,
                             a colon and a value in one body-text run. `.ss-num` is the design
                             system's tabular-figure treatment -- equal-width digits so a column of
                             cards aligns, and `unicode-bidi: isolate` so the figure stays one
                             left-to-right run inside an Arabic line. The LIST has the same defect
                             (OrderDataTable renders `final_price` as bare text with no wrapper and
                             no class); this fixes the grid, and the list is recorded as its own
                             slice rather than widened into here. --}}
                        <span class="text-dark text-md ss-order-card__money">
                            <b>{{ __('Price') }} : </b>
                            <span class="ss-num">{{ order_currency_format_with_sym(($order->final_price ?? 0), $order->id) ?? SetNumberFormat($order->final_price) }}</span>
                        </span>
                        <br>
                        {{-- THE DATE, which this card did not show at all. Both siblings do: the
                             LIST has a `Date` column (`OrderDataTable::getColumns()`, rendered by
                             `self::dateOrDash($item->order_date)`) and the board card carries one.
                             An order grid with no date cannot be scanned.
                             `dateOrDash()` IS THE EXISTING HELPER AND IS REUSED RATHER THAN
                             REIMPLEMENTED: it renders a bare `-` for a missing date instead of the
                             epoch, which is F19 -- a defect this project has already fixed once and
                             must not reintroduce on a second screen. --}}
                        <span class="text-dark text-md ss-order-card__date">
                            <b>{{ __('Date') }} : </b>
                            <span class="ss-num">{{ \App\DataTables\OrderDataTable::dateOrDash($order->order_date) }}</span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="card-body p-3 gap-1 d-flex align-items-center justify-content-between">
            <h4 class="mb-0"><a href="{{route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) }}" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="{{ __('Invoice ID') }}">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">#{{ $order->product_order_id }}</span>
                            </a></h4>
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