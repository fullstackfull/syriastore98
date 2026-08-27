@extends('layouts.app')

@section('page-title')
{{ __('Customer') }}
@endsection

@section('breadcrumb')
<li class="breadcrumb-item" aria-current="page">{{ __('Customer') }}</li>
@endsection

@section('action-button')
<div class="text-end">
    @permission('Manage Customer')
    <a href="#" class="btn btn-sm btn-primary btn-icon csv" title="{{ __('Export') }}" data-bs-toggle="tooltip"
        data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Export') }}">
        <i class="ti ti-file-export"></i>
    </a>
    @endpermission
    <a href="{{ route('customer.index') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('List View') }}" class="btn btn-sm btn-primary btn-icon ">
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
                                        data-original-title="{{ __('apply') }}">
                                        <span class="btn-inner--icon"><i class="ti ti-search"></i></span>
                                    </a>
                                    <a href="#!" class="btn btn-sm btn-danger " data-bs-toggle="tooltip"
                                        title="{{ __('Reset') }}" id="clearfilter"
                                        data-original-title="{{ __('Reset') }}">
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
            <div class="card-header p-3 border border-bottom h-100">
                <div class="user-img-wrp d-flex align-items-center">
                    <div class="user-content d-flex flex-column align-items-start gap-3">
                        <span class="badge bg-primary p-2 px-3">
                            <a class="bg-success" href="{{ route('customer.timeline', $customer->id) }}">
                                <span class="btn-inner--text text-capitalize"><b>{{ $customer->first_name }} {{ $customer->last_name }}</b></span>
                            </a>
                        </span>
                        <div class="customer-grid-detail d-flex flex-column gap-2">
                        <span class="text-dark text-md d-block"><b>{{ __('Total Order :') }}</b>
                        <a href="{{ route('customer.show', $customer->id) }}">{{ $customer->Ordercount() }}</a>
                        </span>
                        <span class="text-dark text-md d-block"><b>{{$customer->email  }} </b></span>
                        @if ($customer && $customer->last_active)
                        @php
                            $active = \Carbon\Carbon::parse($customer->last_active);
                        @endphp
                            <span class="text-dark text-md d-block"><b>{{ __('Last Active :') }} </b>{{ $active->format('F d, Y') }}</span>
                        @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-3  text-center">
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
                    type: "GET",
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
