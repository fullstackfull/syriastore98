@extends('layouts.app')

@section('page-title', __('Coupon'))

@section('action-button')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        {{-- Phase 2.5 / primary-action-named-only-by-title. This export anchor had the same defect
             as the create button below -- named only by `title`, which Bootstrap moves to
             `data-bs-original-title` and blanks when dash.js builds its Tooltip on load, leaving
             the Tabler private-use glyph U+EDE9 as the computed accessible name.

             It stays ICON-ONLY and is shaped exactly like the already-shipped order/index and
             order/grid export, so it takes an `aria-label` instead of a visible label. Three
             reasons: the design system's hierarchy rule wants ONE primary action with the
             utilities outlined, and two brand-filled buttons side by side means no primary action
             at all; the order list already ships Export this way; and `__('Export')` in Arabic is
             `الصادرات` -- "exports" in the economic sense, a pre-existing mistranslation that is
             tolerable in a hover tooltip but would become a wrong visible word on every Arabic
             merchant's screen, and retranslating strings is outside this finding.

             `.export-btn` and `filename` are LOAD-BEARING and invisible: public/js/loader.js binds
             `.export-btn` with a delegated handler that preventDefaults, fetches the href as a
             blob and names the download `getAttribute('filename') + '.xlsx'`. Rename the class and
             the control still looks and navigates fine while the download stops; drop the
             attribute and the file lands as Export.xlsx. `me-1` is kept because the wrapper above
             carries no `gap-*` class, so it is the only separation between these two controls --
             and it is the LOGICAL edge, mirrored to `margin-left` in public/assets/css/
             style-rtl.css and rtl-style-dark.css, so it flips correctly in Arabic.

             This anchor is deliberately OUTSIDE any @permission: its gate is server-side on
             fileExport(), owned by tests/Feature/Security/CouponExportPermissionTest.php. Adding a
             view-level gate would be a behaviour change dressed as consistency. --}}
        <a class="ss-btn ss-btn--sm ss-btn--icon ss-btn--secondary export-btn me-1" href="{{ route('coupon.export') }}" aria-label="{{ __('Export') }}" data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Export') }}" filename="{{ __('Coupon') }}">
            <i class="ti ti-file-export" aria-hidden="true"></i>
        </a>
        @permission('Create Coupon')
            {{-- The visible label is this anchor's own `title`, "Create Coupon" -- NOT its
                 `data-title`, which is the modal's heading ("Add Coupon") and stays as it is.
                 `.add_coupon` is kept: measured bound nowhere, but keeping it removes a variable
                 from the change. No `aria-label` beside the visible text (WCAG 2.5.3). --}}
            <a href="#" class="ss-btn ss-btn--primary ss-btn--sm add_coupon" data-ajax-popup="true" data-size="lg"
                data-title="{{ __('Add Coupon') }}"
                data-url="{{ route('coupon.create') }}"
                data-bs-placement="top" data-bs-toggle="tooltip" title="{{ __('Create Coupon') }}">
                <i class="ti ti-plus" aria-hidden="true"></i>
                <span>{{ __('Create Coupon') }}</span>
            </a>

        @endpermission
    </div>
@endsection

@section('breadcrumb')

    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Coupon') }}</li>
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
    $(document).on('click', '.code', function () {
        var type = $(this).val();
        $('#code_text').addClass('col-md-12').removeClass('col-md-8');
        $('#autogerate_button').addClass('d-none');
        if (type == 'auto') {
            $('#code_text').addClass('col-md-8').removeClass('col-md-12');
            $('#autogerate_button').removeClass('d-none');
        }
    });

    $(document).on('click', '#code-generate', function () {
        var length = 10;
        var result = '';
        var characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        var charactersLength = characters.length;
        for (var i = 0; i < length; i++) {
            result += characters.charAt(Math.floor(Math.random() * charactersLength));
        }
        $('#auto-code').val(result);
    });
</script>

@endpush

