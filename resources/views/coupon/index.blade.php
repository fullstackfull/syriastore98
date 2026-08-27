@extends('layouts.app')

@section('page-title', __('Coupon'))

@section('action-button')
    <div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a class="btn btn-sm btn-primary btn-icon btn-badge me-1 export-btn" href="{{ route('coupon.export') }}" data-bs-toggle="tooltip" title="{{ __('Export') }}" filename="{{ __('Coupon') }}">
            <i  class="ti ti-file-export"></i>
        </a>
        @permission('Create Coupon')
            <a href="#" class="btn btn-sm btn-primary btn-badge add_coupon" data-ajax-popup="true" data-size="lg"
                data-title="{{ __('Add Coupon') }}"
                data-url="{{ route('coupon.create') }}"
                data-bs-toggle="tooltip" title="{{ __('Create Coupon') }}">
                <i class="ti ti-plus"></i>
            </a>

        @endpermission
    </div>
@endsection

@section('breadcrumb')

    <li class="breadcrumb-item">{{ __('Coupon') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-xl-12">
    <x-datatable :dataTable="$dataTable" />
    </div>
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

