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
        {{-- Phase 2.5 / primary-action-named-only-by-title. This anchor's only name was `title`,
             which Bootstrap 5.1.3 MOVES to `data-bs-original-title` and blanks when dash.js builds
             a Tooltip for it on load; its own `aria-label` fallback does not fire, because 5.1.3
             tests the UNTRIMMED textContent, which on a multi-line anchor is truthy whitespace.
             What remained was an unnamed control around an unhidden icon font, so the accessible
             name computed as the Tabler private-use glyph U+EB0B. Fixed the way the three shipped
             precedents do it (category/index, attributes/index, attribute_option/index): a visible
             translated <span>, `aria-hidden` on the <i>, and NO `aria-label` -- a control named by
             its own visible text must not also carry one, because the two can drift (WCAG 2.5.3).

             `.add_attribute` is KEPT because `public/css/custom.css:1518` and its mirror
             `public/css/rtl-custom.css:1530` both style it, and to match attributes/index -- NOT
             because any JavaScript binds it. Nothing does: `grep -rn add_attribute public/
             --include=*.js` and the same over packages/ are both empty. That rule is overridden
             only by link order (both selectors are 0,1,0; store-syria-ds.css links after
             custom.css in layouts/app.blade.php, and `.ss-btn` resets font-family), so the link
             order is load-bearing.

             The Bootstrap `btn` classes go because `.ss-btn` is an explicit metrics reset for
             exactly this reason -- an element carrying both renders as neither.

             RESIDUAL, DISCLOSED: with ImportExport active, this screen's two module buttons above
             still have this same defect. They are vendored and out of bounds, so this screen is
             PARTIALLY closed, not clean. --}}
        <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_attribute" data-ajax-popup="true" data-size="md"
            data-title="{{ __('Add Brand') }}" data-url="{{ route('product-brand.create') }}"
            data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Add Brand') }}">
            <i class="ti ti-plus" aria-hidden="true"></i>
            <span>{{ __('Add Brand') }}</span>
        </a>
    </div>
    @endpermission

@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Brand') }}</li>
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
