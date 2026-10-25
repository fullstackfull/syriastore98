@extends('layouts.app')

@section('page-title', __('Product Brand'))

@section('action-button')
    @permission('Create Product Brand')
    <div class=" text-end  gap-2 d-flex all-button-box justify-content-md-end justify-content-end">
        @if (module_is_active('ImportExport'))
            @permission('brand import')
                @include('import-export::import.brand_import', ['module' => 'brand'])
            @endpermission
            @permission('brand export')
                @include('import-export::export.brand_export', ['module' => 'brand'])
            @endpermission
        @endif
        <a href="#" class="btn btn-sm btn-primary add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Brand') }}" data-url="{{ route('product-brand.create') }}" data-bs-toggle="tooltip"
            title="{{ __('Add Brand') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Brand') }}</li>
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
                // P2-05-1. POST, not GET: this route writes, and a state-changing GET is
                // reachable from a third-party <img src> and by link prefetching. The route
                // NAME is unchanged, so the verb is the only thing new here.
                //
                // THE CSRF TOKEN NEEDS NO LINE. `public/js/custom.js` sets
                // `$.ajaxSetup({headers: {'X-CSRF-TOKEN': ...}})` for every jQuery call, which
                // is how every other POST in this codebase gets its token. An explicit header
                // here was written first and then removed: it was redundant, and MEASURED so --
                // deleting it did not change the live check, while reverting the verb failed
                // four of its cases. Enforcement itself is asserted, not assumed: the live
                // check sends a tokenless `fetch` and requires 419.
                type: "POST",
                dataType: "json",
                url: "{{ route('product-brand.status') }}",
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

        $(document).on('change', '.popular-index', function() {
            var is_popular = $(this).prop('checked') == true ? 1 : 0;
            var id = $(this).data('id');
            $.ajax({
                // P2-05-1. POST with the token: this route writes, and a state-changing GET
                // is reachable from a third-party <img src> and by link prefetching. The route
                // NAME is unchanged, so only the verb and the token are new here.
                type: "POST",
                dataType: "json",
                url: "{{ route('product-brand.popular') }}",
                data: {
                    'is_popular': is_popular,
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
