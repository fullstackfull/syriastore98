@extends('layouts.app')
@section('page-title')
    {{ __('Campaigns') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Campaigns') }}</li>
@endsection

@section('action-button')
    <div class="col-xl-12 col-lg-12 col-md-12 d-flex align-items-center justify-content-between justify-content-md-end"
        data-bs-placement="top">
        <a href="#" data-size="lg" data-url="{{ route('campaigns.create') }}" data-ajax-popup="true"
            data-bs-toggle="tooltip" title="{{ __('Create') }}" data-title="{{ __('Create New Campaigns') }}"
            class="btn btn-sm btn-primary">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endsection
@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xl-3">
                    <div class="card sticky-top" style="top:30px">
                        <div class="list-group list-group-flush" id="useradd-sidenav">
                            @include('campaigns::marketplace_setup')
                        </div>
                    </div>
                </div>

                <div class="col-xl-9">
                    <div class="card">
                        <div class="card-header">
                            <h5>{{ __('Campaigns Category') }}</h5>
                        </div>
                        <div class="card-body row">
                            <div class="col-xl-3">
                                <div class="card">
                                    <div class="text-end">
                                        <div class="btn-group card-option">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
