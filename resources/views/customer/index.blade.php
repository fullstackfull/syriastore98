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
        <a href="{{ route('customer.grid') }}" data-bs-toggle="tooltip" data-bs-original-title="{{ __('Grid View') }}" class="btn btn-sm btn-primary btn-icon ">
            <i class="ti ti-layout-grid"></i>
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
                                            title="{{ __('Apply') }}" id="applyfilter"
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

        <div class="col-md-12">
            <x-datatable :dataTable="$dataTable" />
        </div>

    </div>
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
