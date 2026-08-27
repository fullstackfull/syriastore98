@extends('layouts.app')

@section('page-title', __('Pages'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Pages') }}</li>
@endsection

@section('action-button')
    @permission('Create Page')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="lg" data-title="{{__('Add Page')}}"
            data-url="{{ route('pages.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Page') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
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
        $(document).on('change', '.page-toggle', function () {
            const pageId = $(this).data('page-id');
            const isActivated = $(this).prop('checked');

            $.ajax({
                type: 'POST',
                url: '{{ route('update-page-status') }}',
                data: {
                    pageId: pageId,
                    isActivated: isActivated,
                    _token: '{{ csrf_token() }}',
                },
                    success: function (data) {
                        $('#loader').fadeOut();
                        show_toastr('{{ __('Success') }}',
                        '{{ __('Status Updated Successfully!') }}', 'success');
                    },
                    error: function (xhr, status, error) {
                        $('#loader').fadeOut();
                    },
                });
            });
        });
    </script>
@endpush
