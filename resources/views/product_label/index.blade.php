@extends('layouts.app')

@section('page-title', __('Product Label'))

@section('action-button')
    @permission('Create Product Label')
    <div class=" text-end  gap-1 d-flex all-button-box justify-content-md-end justify-content-center">
        @if (module_is_active('ImportExport'))
            @permission('label import')
                @include('import-export::import.label_import', ['module' => 'label'])
            @endpermission
            @permission('label export')
                @include('import-export::export.label_export', ['module' => 'label'])
            @endpermission
        @endif
        <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Label') }}" data-url="{{ route('product-label.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add Label') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Label') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
@push('scripts')
    <script>
        $(document).on('change', '.status-index', function() {
            var status = $(this).prop('checked') == true ? 1 : 0;
            var id = $(this).data('id');
            $.ajax({
                type: "GET",
                dataType: "json",
                url: "{{ route('product-label.status') }}",
                data: {
                    'status': status,
                    'id': id
                },
                success: function(data) {
                    $('#loader').fadeOut();
                    if (data.status != 'success') {
                        show_toastr('Error', data.message, 'error');
                    } else {
                        show_toastr('Success', data.message, 'success');
                    }
                }
            });
        });
    </script>
@endpush
