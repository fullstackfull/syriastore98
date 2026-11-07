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
        {{-- Phase 2.5 / primary-action-named-only-by-title. Same defect and same repair as the
             brand list: the only name was a `title` that Bootstrap blanks on tooltip init, leaving
             an unnamed control whose computed name was the Tabler glyph U+EB0B. `.add_attribute`
             is kept for the mirrored cosmetic rule in public/css/custom.css:1518 and
             public/css/rtl-custom.css:1530 and to match attributes/index -- no JavaScript binds
             it. No `aria-label`: the visible <span> is the name (WCAG 2.5.3).

             RESIDUAL, DISCLOSED: with ImportExport active this screen's two module buttons above
             still carry the same defect; they are vendored, so this screen is PARTIALLY closed. --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Label') }}" data-url="{{ route('product-label.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Label') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Label') }}</span>
        </a>
    </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Label') }}</li>
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
                // P2-05-1. POST, not GET -- see product_brand/index.blade.php for why, and
                // for where the CSRF token comes from (public/js/custom.js's global
                // $.ajaxSetup, not a line here).
                type: "POST",
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
