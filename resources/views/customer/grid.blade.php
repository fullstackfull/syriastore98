@extends('layouts.app')

@section('page-title')
{{ __('Customer') }}
@endsection

@section('breadcrumb')
<li aria-current="page" class="ss-breadcrumb__item">{{ __('Customer') }}</li>
@endsection

@section('action-button')
<div class="text-end">
    @permission('Manage Customer')
    <a href="#" class="btn btn-sm btn-primary btn-icon csv" title="{{ __('Export') }}" data-bs-toggle="tooltip"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Export') }}" aria-label="{{ __('Export') }}">
        <i class="ti ti-file-export"></i>
    </a>
    @endpermission
    <a href="{{ route('customer.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon " aria-label="{{ __('List View') }}">
        <i class="ti ti-list"></i>
    </a>
</div>
@endsection

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="mt-2 " id="multiCollapseExample1">
            <div class="card">
                <div class="card-body">
                    <div class="row align-items-center m-0 gap-1 justify-content-end">
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12">
                            <div class="btn-box">
                                {{ Form::select('field_name', $customer_field, isset($_GET['field_name'])?$_GET['field_name']:null, ['class' => 'form-control', 'id' => 'customer_field', 'placeholder' => __('Select Customer Fields')]) }}
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 select-container d-none">
                            <div class="btn-box" id="select-container">
                                {{ Form::hidden('select_value', null, ['id' => 'select-value']) }}
                            </div>
                        </div>
                        <div class="col-xl-3 col-lg-3 col-md-6 col-sm-12 col-12 text-field d-none">
                            <div class="btn-box" id="text-field">
                                {{ Form::hidden('text_value', null, ['id' => 'text-value']) }}
                            </div>
                        </div>
                        <div class="m-0 p-0 col-auto">
                            <div class="row">
                                <div class="m-0 p-0 col-auto">
                                    <a class="btn btn-sm btn-primary" data-bs-toggle="tooltip"
                                        title="{{ __('Apply') }}" id="apply-button"
                                        data-original-title="{{ __('apply') }}" aria-label="{{ __('Apply') }}">
                                        <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                    </a>
                                    <a href="#!" class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
                                        title="{{ __('Reset') }}" id="clearfilter"
                                        data-original-title="{{ __('Reset') }}" aria-label="{{ __('Reset') }}">
                                        <span class="btn-inner--icon"><i
                                                class="ti ti-trash-off text-white-off "></i></span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row row-gap-2 mb-2">
    @foreach ($customers as $customer)
    <div class="col-xxl-3 col-xl-4 col-sm-6 col-12">
        <div class="card user-card">
            {{--
                P2.5-PKG-C. REDESIGNED COMPOSITION, not a recolour.

                WHAT WAS HERE, and why a CSS patch was the wrong fix:

                  * THE CUSTOMER'S NAME WAS A `badge bg-primary` -- identity rendered as a status
                    pill. A badge's semantics are a status or a count; a person's name is the
                    card's primary content and belongs in its title. It also MEASURED 2.09:1 in
                    dark mode (rgb(120,148,198) on rgb(51,93,168)), below even large-text AA, so
                    the one thing a merchant scans for was the least legible thing on the card.
                  * INSIDE that blue badge sat `<a class="bg-success">` -- a second, GREEN fill
                    nested in the first. Two competing backgrounds on one element.
                  * FOUR `.text-dark` spans, i.e. literally "dark text", on a page that has a dark
                    theme. `.text-dark` appears nowhere in the design system; it is raw Bootstrap.
                  * `border border-bottom` together, so the header took a full border AND a second
                    bottom one.
                  * `user-img-wrp` -- a wrapper named for an avatar, containing no avatar.
                  * NO HIERARCHY: name, email, order count and last-active all rendered as `<b>`
                    at one size, so nothing was primary and the count -- the only number on the
                    card -- read as another label.

                WHAT IT IS NOW. Identity first (avatar + name + email), then the one figure that
                carries value, then the actions. The avatar's slot is finally filled, with initials.

                THE FIGURE USES THE DESIGN SYSTEM'S OWN `.ss-stat__value/label/period`, deliberately
                rather than a new class: they already carry tabular figures, `unicode-bidi:
                isolate` (a Latin digit run beside Arabic text reorders without it) and
                `overflow-wrap: break-word` rather than `anywhere`, all of which were measured for
                the dashboard. `.ss-stat` ITSELF is not used -- that is the page-level grid whose
                own comment says "measures get no card", and this one is in a card.

                AND THE PERIOD IS STATED, because the design system requires it: "Every cell states
                its own period. A count with no period is the commonest way a dashboard misleads."
                `Ordercount()` is lifetime, so the period is "All time" -- which is also now true
                in the sense that matters, since F-C-07 scoped it to this store.
            --}}
            <div class="ss-card__header">
                <div class="d-flex align-items-center gap-3">
                    <span class="ss-avatar ss-avatar--lg" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($customer->first_name ?? '', 0, 1) . \Illuminate\Support\Str::substr($customer->last_name ?? '', 0, 1)) }}</span>
                    <div class="flex-grow-1 min-w-0">
                        <a class="ss-card__title d-block text-truncate text-capitalize"
                           href="{{ route('customer.timeline', $customer->id) }}">{{ $customer->first_name }} {{ $customer->last_name }}</a>
                        <span class="ss-card__meta d-block text-truncate" dir="ltr">{{ $customer->email }}</span>
                    </div>
                </div>
            </div>
            <div class="ss-card__body">
                <div class="d-flex align-items-start justify-content-between gap-3">
                    <div class="min-w-0">
                        {{-- NOT A LINK, and that is a correction made from the rendered capture.
                             As an `<a>` the figure took the link colour and overrode
                             `.ss-stat__value`'s `--ss-fg`, so the NAME and the NUMBER rendered in
                             the same blue and competed for primacy -- two bold things, which is
                             one too many. It was also a duplicate affordance: the cart action in
                             the footer below already goes to `customer.show`. Plain text restores
                             the strong neutral, and leaves the name as the single interactive
                             element on the card. --}}
                        <span class="ss-stat__value d-block">{{ $customer->Ordercount() }}</span>
                        <span class="ss-stat__label">{{ __('Orders') }}</span>
                        <span class="ss-stat__period">{{ __('All time') }}</span>
                    </div>
                    @if ($customer && $customer->last_active)
                        @php
                            $active = \Carbon\Carbon::parse($customer->last_active);
                        @endphp
                        <div class="text-end min-w-0">
                            <span class="ss-card__meta d-block">{{ __('Last active') }}</span>
                            <span class="ss-card__meta d-block">{{ $active->translatedFormat('d M Y') }}</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="ss-card__footer ss-card__footer--between">
                <div class="bottom-icons d-flex flex-wrap align-items-center justify-content-between">
                    <div class="customer-grid-btn">
                    @if (($customer && $customer->id) || $activityLogEntry)
                        <a href="{{ route('customer.timeline', $customer->id) }}"
                            class="btn btn-sm btn-icon border me-2"
                            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Show') }}">
                            <i class="ti ti-eye"></i>
                        </a>
                    @endif
                    @permission('Show Customer')
                        <a href="{{ route('customer.show', $customer->id) }}"
                            class="btn btn-sm border me-2" data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Cart') }}">
                            <i class="ti ti-shopping-cart"></i>
                        </a>
                    @endpermission
                    @if (module_is_active('RewardClubPoint'))
                        @include('reward-club-point::admin.clubPointHistoryBtn', ['customerId' => $customer->id])
                    @endif
                    </div>
                    <div class="customer-grid-swich-btn">
                    @permission('Status Customer')
                        @if ($customer->regiester_date != null)
                            <div class="form-check form-switch">
                                <input class="form-check-input page-checkbox " id="{{ $customer->id }}"
                                    aria-label="{{ __('Account status for :name', ['name' => $customer->name]) }}"
                                    type="checkbox" name="page_active" data-onstyle="success"
                                    data-offstyle="danger" data-toggle="toggle" data-on="off"
                                    data-off="on"
                                    @if ($customer->status == 1) checked="checked" @endif />
                            </div>
                        @endif
                    @endpermission
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>
{!! $customers->links('layouts.global-pagination') !!}
@endsection

@push('custom-script')
    <script type="text/javascript">
        $(document).ready(function() {
            $(document).on('change', '.page-checkbox', function() {
                var status = $(this).prop('checked') == true ? 1 : 0;
                var customer_id = $(this).attr('id');

                $.ajax({
                    // P2-05-1. POST, not GET -- the SECOND caller of this route.
                    // The index view was the one somebody was looking at; this grid view calls
                    // the same route and would have gone on sending a GET. That is the F14
                    // lesson in this phase's ledger, which is why the callers were censused by
                    // route NAME across every view rather than fixed where they were noticed.
                    type: "POST",
                    dataType: "json",
                    url: "{{ route('update.customer.status') }}",
                    data: {
                        'status': status,
                        'customer_id': customer_id
                    },
                    success: function(data) {
                        $('#loader').fadeOut();
                        if (data.success) {
                            show_toastr('Success', data.success, 'success');
                        } else {
                            show_toastr('Error', "{{ __('Something went wrong.') }}", 'error');
                        }
                    },
                });
            })
        })
    </script>
    <script src="{{ asset('js/jquery.table2excel.js') }}"></script>
    <script>
        const d = new Date();
        let seconds = d.getSeconds();
        $(document).on('click', '.csv', function() {
            $('.ignore').remove();
            $("#customer-table").table2excel({
                filename: "Customer_" + seconds
            });
            window.location.reload();
        })
    </script>

    <script>
        $(document).ready(function() {

            $('#customer_field').on('change', function() {
                var selectedValue = $(this).val();
                var data = {
                    customer_field: selectedValue,
                }
                $.ajax({
                    url: '{{ route('customer.filter') }}',
                    method: 'GET',
                    data: data,
                    context: this,
                    success: function(response) {
                        $('#loader').fadeOut();                       

                        if (response.condition && response.condition.length > 0) {
                            $('.select-container').removeClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').removeClass('d-none');
                            $('#text-field').empty();
                            var selectBox = $('<select name="selected_name" class="form-control">');
                            var TextField = $('<input>');

                            $.each(response.condition, function(index, option) {
                                var optionElement = $('<option>');
                                optionElement.val(option);
                                optionElement.text(option);
                                selectBox.append(optionElement);
                            });

                            $('#select-container').append(selectBox);

                            var inputType = response.field_type;

                            if (inputType === 'text' || inputType === 'number' || inputType === 'email' || inputType === 'date') {
                                var inputElement = $('<input name="text_field" class="form-control">');

                                inputElement.attr('type', inputType);
                                $('#text-field').append(inputElement);

                                // Set the values of the hidden input fields
                                $('#select-value').val(selectBox.val());
                                $('#text-value').val(inputElement.val());
                            } else {
                                $('.text-field').addClass('d-none');
                            }
                        } else {
                            $('.select-container').addClass('d-none');
                            $('#select-container').empty();
                            $('.text-field').addClass('d-none');
                            $('#text-field').empty();
                        }
                    }
                });
            });

            $('#frm_submit').on('submit', function(event) {
                event.preventDefault();
                applyFilter();
            });

            $('#apply-button').on('click', function(event) {
                event.preventDefault();
                applyFilter();
            });

            // Function to apply the filter
            function applyFilter() {
                var selectedValue = $('#customer_field').val();
                var select = $('#select-container select').val();
                var TextValue = $('#text-field input').val();

                var data = {
                    'text_field': TextValue,
                    'selected_name': select,
                    'customer_field': selectedValue,
                }

                $.ajax({
                    url: '{{route('customer.filter.data')}}',
                    type: 'GET',
                    data: data,
                    context: this,
                    success: function (data) {
                        $('#loader').fadeOut();
                        $('#service-filter-data').html('');
                        $('#service-filter-data').html(data);
                    }
                });
            }
        });
    </script>
@endpush
