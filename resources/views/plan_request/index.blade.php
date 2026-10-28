@extends('layouts.app')

@section('page-title', __('Plan Request'))

@section('action-button')

@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Plan Request') }}</li>
@endsection

@push('css')
@endpush

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

@push('custom-script')
@endpush