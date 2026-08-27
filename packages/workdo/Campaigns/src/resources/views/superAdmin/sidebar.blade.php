@extends('layouts.app')
@section('page-title')
    {{ __('Landing Page') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Landing Page') }}</li>
@endsection

@php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $selectedType = $settings['product_campaign_type'] ?? null;
@endphp

@section('content')
    <div class="row">
        <div class="col-sm-12">
            <div class="row">
                <div class="col-xl-3">
                    <div class="card sticky-top" style="top:30px">
                        <div class="list-group list-group-flush" id="useradd-sidenav">
                            @include('landing-page::layouts.tab')
                        </div>
                    </div>
                </div>
                <div class="col-xl-9">
                    {{--  Start for all settings tab --}}
                    <div class="card">
                        {{ Form::open(['route' => 'campaigns.system.setup.store', 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-header">
                            <div class="row d-flex">
                                <div class="col-6">
                                    <h5 class="mt-2">{{ __('Campagins') }}</h5>
                                </div>
                                <div class="col switch-width text-end">
                                    <div class="form-group mb-0">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary"
                                                class="" name="product_campaign" id="product_campaign"
                                                {{ isset($settings['product_campaign']) && $settings['product_campaign'] == 'on' ? 'checked="checked"' : '' }}>
                                            <label class="custom-control-label" for="product_campaign"></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        {{--  Start for all settings tab --}}
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('product_campaign_title', __('Title'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_campaign_title', isset($settings['product_campaign_title']) ? $settings['product_campaign_title'] : '', ['class' => 'form-control ', 'placeholder' => __('Enter Title'), 'id' => 'product_campaign_title']) }}
                                        @error('mail_host')
                                            <span class="invalid-mail_driver" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('product_campaign_heading', __('Heading'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_campaign_heading', isset($settings['product_campaign_heading']) ? $settings['product_campaign_heading'] : '', ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'product_campaign_heading']) }}
                                        @error('mail_host')
                                            <span class="invalid-mail_driver" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-group">
                                        {{ Form::label('product_campaign_description', __('Description'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_campaign_description', isset($settings['product_campaign_description']) ? $settings['product_campaign_description'] : '', ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'product_campaign_description']) }}
                                        @error('mail_port')
                                            <span class="invalid-mail_port" role="alert">
                                                <strong class="text-danger">{{ $message }}</strong>
                                            </span>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <input class="btn btn-print-invoice btn-badge btn-primary mr-2" type="submit" value="{{ __('Save Changes') }}">
                        </div>
                        {{ Form::close() }}
                        {{--  End for all settings tab --}}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection