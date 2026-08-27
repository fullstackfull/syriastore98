@extends('layouts.app')

@section('page-title', __('Plan Coupon'))

@section('action-button')
    @permission('Create Coupon')
    <div class="text-end d-flex all-button-box justify-content-end">
        <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add Coupon')}}"
            data-url="{{ route('plan-coupon.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Coupon') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
    @endpermission
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Plan Coupon') }}</li>
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
        $(document).on('click', '#code-generate', function() {
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
