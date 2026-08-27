@extends('layouts.app')

@section('page-title', __('Product Bar Code'))

@section('action-button')
@permission('Create Product Barcode')
<div class=" text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md"
        data-title="{{ __('Barcode Setting') }}"
        data-url="{{ route('barcode-type') }}"
        data-bs-toggle="tooltip" title="{{ __('Bar Code Setting') }}">
        <i class="ti ti-settings"></i>
    </a>
</div>
@endpermission

@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Bar Code') }}</li>
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

</script>
@endpush
