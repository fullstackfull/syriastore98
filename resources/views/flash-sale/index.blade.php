@extends('layouts.app')

@section('page-title', __('Flash Sale'))

@section('action-button')
    @permission('Create Flash Sale')
        <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
            <a href="#" class="btn btn-sm btn-primary btn-badge mr-1" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Flash Sale')}}"
                data-url="{{ route('flash-sale.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Flash Sale') }}">
                <i class="ti ti-plus"></i>
            </a>
        </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Flash Sale') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

    @push('custom-script')

    <script>
        $(document).ready(function () {
            $(document).on('change', '.flashsale-toggle', function () {
                const flashsaleId = $(this).data('flashsale-id');
                const isActivated = $(this).prop('checked');

                $.ajax({
                    type: 'POST',
                    url: '{{ route('update-flashsale-status') }}',
                    data: {
                        flashsaleId: flashsaleId,
                        isActivated: isActivated,
                        _token: '{{ csrf_token() }}',
                    },
                    success: function (data) {
                        $('#loader').fadeOut();
                        if (data.status == true) {
                            show_toastr("Success", data.message, "success");
                        } else {
                            show_toastr("Error", data.message, "error");
                        }
                    },
                    error: function (xhr, status, error) {
                        $('#loader').fadeOut();
                        console.error(xhr.responseText);
                    },
                });
            });
        });
    </script>
    @endpush
