@extends('layouts.app')

@section('page-title', __('Comments'))

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Comments') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
@push('scripts')
@endpush
