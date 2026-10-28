@extends('layouts.app')

@section('page-title', __('Order'))

@section('action-button')
<div class="text-end">
    {{-- ✅ زر إضافة طلبية جديدة (الإجراء الأساسي) --}}
        <a href="{{ route('order.create') }}" class="btn btn-sm btn-primary me-2">
            <i class="ti ti-plus"></i> {{ __('Create Order') }}
        </a>


    {{-- الأزرار الموجودة مسبقاً --}}
        <a class="btn btn-sm btn-primary btn-icon export-btn" href="{{ route('order.export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Order') }}">
            <i class="ti ti-file-export"></i>
        </a>

    <a href="{{ route('order.grid') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}" class="btn btn-sm btn-primary btn-icon">
        <i class="ti ti-layout-grid"></i>
    </a>
</div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Order') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            {{-- P2-04-13 / WS E9 — the operational "needs attention" centre for orders.

                 A SECOND CALLER of the component the dashboard already uses, not a new one. The
                 block, its accent bar, its heading and its per-row action link are
                 `components/ss/attention.blade.php` and `attention-item.blade.php`, written and
                 tested under P2-02.

                 EVERY COUNT LINKS TO A LIST THAT REALLY CONTAINS IT. The hrefs carry the same
                 predicate `OrderController::orderAttentionCounts()` counted with — see that
                 method for the two mismatches measurement turned up, one of which would have sent
                 a merchant to a list of the wrong column entirely.

                 NO EMPTY STATE, BY THE COMPONENT'S OWN CONTRACT: "a permanent '0 items need
                 attention' panel teaches merchants to stop looking at this part of the screen".
                 So the whole section is absent when all three counts are zero, and each row is
                 absent when its own count is.

                 The page already requires `Manage Order` to reach (OrderController::index()), so
                 the links need no further gate: anyone who can see this block can open what it
                 points at. --}}
            @php
                $ssAttentionTotal = ($ssAttentionPending ?? 0)
                    + ($ssAttentionReturns ?? 0)
                    + ($ssAttentionUnpaid ?? 0);
            @endphp

            @if ($ssAttentionTotal > 0)
                <x-ss.attention :title="__('Needs attention')"
                                :severity="($ssAttentionReturns ?? 0) > 0 ? 'warning' : 'brand'"
                                class="mb-4">

                    @if (($ssAttentionPending ?? 0) > 0)
                        <x-ss.attention-item
                            :count="$ssAttentionPending"
                            :text="trans_choice('order is waiting to be confirmed|orders are waiting to be confirmed', $ssAttentionPending)"
                            :href="route('order.index', ['ss_status' => \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING])"
                            :action="__('Show them')" />
                    @endif

                    @if (($ssAttentionReturns ?? 0) > 0)
                        <x-ss.attention-item
                            :count="$ssAttentionReturns"
                            :text="trans_choice('return is waiting for a decision|returns are waiting for a decision', $ssAttentionReturns)"
                            :href="route('order.index', ['ss_return' => 1])"
                            :action="__('Show them')" />
                    @endif

                    @if (($ssAttentionUnpaid ?? 0) > 0)
                        <x-ss.attention-item
                            :count="$ssAttentionUnpaid"
                            :text="trans_choice('order is still unpaid|orders are still unpaid', $ssAttentionUnpaid)"
                            :href="route('order.index', ['ss_payment' => 'Unpaid'])"
                            :action="__('Show them')" />
                    @endif
                </x-ss.attention>
            @endif

            {{-- P2-04-8. Above the table, because it changes what the table shows. The same
                 GET-form mechanism the product list uses — see that partial for why it needs
                 no JavaScript. --}}
            @include('order.partials.filters')

            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>

    {{--
        P2-04-5. ONE panel for the whole list, filled per row.

        One element rather than one per row: the body is replaced on each open,
        so N rows do not mean N panels in the DOM, and the focus-return
        bookkeeping in store-syria-ds.js keys on the panel id.

        The loading and error strings are passed as data attributes because the
        script that writes them has no access to `__()`. Without them it falls
        back to English, which is the one thing an Arabic merchant must not see.
    --}}
    <x-ss.panel id="ss-order-preview-panel"
                :title="__('Order preview')"
                :loading-text="__('Loading order…')"
                :error-text="__('This order could not be loaded.')" />
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
                context: this,
                success: function (response) {
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