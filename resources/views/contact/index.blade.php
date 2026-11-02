@extends('layouts.app')

@section('page-title', __('Contact-us'))

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Contact-us') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

