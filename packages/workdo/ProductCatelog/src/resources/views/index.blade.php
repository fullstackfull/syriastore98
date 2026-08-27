@extends('layouts.app')

@section('page-title', __('Product Catelog'))

@section('action-button')

@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Product Catelog') }}</li>
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
        $(document).on('click', '.mail_confirm', function (e) {
            var form = $(this).closest("form");
            const swalWithBootstrapButtons = Swal.mixin({
                customClass: {
                    confirmButton: 'btn btn-success',
                    cancelButton: 'btn btn-danger'
                },
                buttonsStyling: false
            })
            swalWithBootstrapButtons.fire({
                title: '{{ __('Are you sure?') }}',
                text: '{{ __("Are you sure you want to send the catalog by mail?") }}',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '{{ __("Yes") }}',
                cancelButtonText: '{{ __("No") }}',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            })
        });
    </script>
@endpush
