@extends('layouts.app')

@section('page-title')
    {{ __('Pre Order Setting') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item" aria-current="page">
        {{ __('Pre Order Setting') }}</li>
@endsection

@section('action-button')
    <div class="text-end">

    </div>
@endsection
@push('css')
    <style>
        .pre-order-card .pre-order-setting .card {
            box-shadow: rgba(0, 0, 0, 0) 0px 10px 50px;
            margin-bottom: 0px;
        }
        .pre-order-card .pre-order-setting .card .card-body #pre-order-setting-table_wrapper .dataTable-top {
            padding: 0px 0;
        }
    </style>
@endpush
@section('content')
    <div class="row">
        <div class="col-xl-3">
            <div class="card sticky-top " style="top:60px">
                <div class="list-group list-group-flush addon-set-tab" id="useradd-sidenav">
                    <ul class="nav nav-pills flex-column w-100 gap-1" id="pills-tab" role="tablist">
                        <li class="nav-item " role="presentation">
                            <a href="#Pre_Order"
                                class="nav-link @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-tab')) active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-pre-order-tab" data-bs-toggle="pill" data-bs-target="#pills-pre-order" type="button"
                                role="tab" aria-controls="pills-pre-order" aria-selected="true">
                                {{ __('Pre Order Settings') }}
                            </a>

                        </li>
                        <li class="nav-item " role="presentation">
                            <a href="#Pre_Order_Setting"
                                class="nav-link @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-setting-tab')) active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-pre-order-setting-tab" data-bs-toggle="pill" data-bs-target="#pills-pre-order-setting" type="button"
                                role="tab" aria-controls="pills-pre-order-setting" aria-selected="true">
                                {{ __('Pre Order Product Settings') }}
                            </a>

                        </li>
                        <li class="nav-item " role="presentation">
                            <a href="#Pre_Order_notification_Setting"
                                class="nav-link @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-notification-setting-tab')) active @endif list-group-item list-group-item-action border-0 rounded-1 text-center f-w-600"
                                id="pills-pre-order-notification-setting-tab" data-bs-toggle="pill" data-bs-target="#pills-pre-order-notification-setting" type="button"
                                role="tab" aria-controls="pills-pre-order-notification-setting" aria-selected="true">
                                {{ __('Pre Order Notification Settings') }}
                            </a>

                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-xl-9">
            <div class="tab-content" id="pills-tabContent">
                <div class="tab-pane fade @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-tab')) show active @endif" id="pills-pre-order" role="tabpanel"
                    aria-labelledby="pills-pre-order-tab">
                    <div id="Pre_Order">
                        <div class="col-md-12">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <div class="row align-items-center">
                                        <div class="col-12 d-flex justify-content-between align-items-center">
                                            <h5 class="state-set">{{ __('Pre Order Settings') }}</h5>
                                        </div>
                                    </div>
                                </div>
                                <div class="row g-0">
                                    {{ Form::model($pre_order_detail, ['route' => ['preorders.update', $pre_order_detail->id ?? 0], 'method' => 'PUT']) }}
                                        @csrf
                                        <div class="card-body p-4">
                                            <div class="form-group col-lg-2 col-md-4 col-sm-6">
                                                <div class="custom-control form-switch p-0">
                                                    {!! Form::hidden('enable_pre_order', 'off') !!}
                                                    <label class="form-check-label mb-2"
                                                        for="enable_pre_order">{{ __('Enable Pre Order') }}</label><br>
                                                    <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                                        data-onstyle="primary" name="enable_pre_order" id="enable_pre_order"
                                                        {{ (isset($pre_order_detail['enable_pre_order']) && ($pre_order_detail['enable_pre_order'] == 'on')) ? 'checked="checked"' : '' }}>
                                                </div>
                                            </div>
                                            <div class="form-group col-md-12">
                                                {{Form::label('name',__('Pre Order Button Title'),array('class'=>'form-label'))}}
                                                {{Form::text('name',null,array('class'=>'form-control','placeholder'=>__('Enter Pre Order Button Title'), 'required' => 'required'))}}
                                            </div>
                                            <div class="form-group col-md-12">
                                                {{Form::label('message',__('Pre Order Message'),array('class'=>'form-label'))}}
                                                {{Form::text('message',null,array('class'=>'form-control','placeholder'=>__('Enter Pre Order Message')))}}
                                            </div>
                                        </div>
                                        <div class="card-footer d-flex justify-content-end flex-wrap ">
                                            {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary']) }}
                                        </div>
                                    {{ Form::close() }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-setting-tab')) show active @endif" id="pills-pre-order-setting" role="tabpanel"
                    aria-labelledby="pills-pre-order-setting-tab">
                    <div id="Pre_Order_Setting">
                        <div class="col-md-12">
                            <div class="card pre-order-card mb-3">
                                <div class="card-header">
                                    <div class="row align-items-center">
                                        <div class="col-12 d-flex justify-content-between align-items-center">
                                            <h5 class="state-set">{{ __('Pre Order Settings') }}</h5>
                                            <div class="d-flex flex-wrap justify-content-lg-end drp-languages mb-2">
                                                @permission('Create Pre Order Setting')
                                                <div class="ms-3">
                                                    <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Create Pre Order Setting')}}"
                                                        data-url="{{ route('preordersetting.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Pre Order Setting') }}">
                                                        <i class="ti ti-plus"></i>
                                                    </a>
                                                </div>
                                                @endpermission
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row pre-order-setting g-0">
                                    <x-datatable :dataTable="$dataTable" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade @if(isset($pre_order_active_tab) && ($pre_order_active_tab == 'pills-pre-order-notification-setting-tab')) show active @endif" id="pills-pre-order-notification-setting" role="tabpanel" aria-labelledby="pills-pre-order-notification-setting-tab">
                    <div id="Pre_Order_notification_Setting">
                        <div class="card">
                            {{ Form::model($currEmailTempLang, ['route' => ['update.preorder.email.settings', $currEmailTempLang->parent_id], 'method' => 'PUT']) }}
                            @csrf
                            <input type="hidden" name="app_setting_tab" value="pills-partial_payment-tab">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class=""> {{ __('Pre Order Notification Settings') }} </h5>
                                <div class="d-flex flex-wrap justify-content-lg-end drp-languages">
                                    <div class="custom-control custom-switch mb-2">
                                        <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class=""
                                            name="pre_order_notification_email_enabled" id="pre_order_notification_email_enabled"
                                            {{ $emailTemplate->template->is_active == '1' ? 'checked="checked"' : '' }}>
                                        <label class="custom-control-label" for="pre_order_notification_email_enabled"></label>
                                    </div>
                                    <div class="d-flex flex-wrap justify-content-lg-end">
                                        <ul class="list-unstyled my-0">
                                            <li class="dropdown dash-h-item drp-language ">
                                                <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-info rounded-1"
                                                    data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false"
                                                    aria-expanded="false" id="dropdownLanguage">
                                                    <span
                                                        class="drp-text hide-mob payment-language">{{ Str::upper($currEmailTempLang->language) }}</span>
                                                    <i class="ti ti-chevron-down drp-arrow nocolor email_arrow"></i>
                                                </a>
                                                <div class="dropdown-menu dash-h-dropdown dropdown-menu-end" aria-labelledby="dropdownLanguage">
                                                    @foreach ($languages as $key => $lang)
                                                        <a href="{{ route('manage.preorder.email.language', [$emailTemplate->id, $key]) }}"
                                                            class="dropdown-item {{ $currEmailTempLang->language == $key ? 'text-primary' : '' }}"
                                                            data-lang-key="{{ $key }}">{{ Str::upper($lang) }}</a>
                                                    @endforeach
                                                </div>
                                            </li>
                                        </ul>
                                        <ul class="list-unstyled my-0">
                                            <li class="dropdown dash-h-item drp-language ">
                                                <a class="dash-head-link dropdown-toggle arrow-none me-0 bg-warning rounded-1" data-bs-toggle="dropdown"
                                                    href="#" role="button" aria-haspopup="false" aria-expanded="false"
                                                    id="dropdownLanguage" style="text-wrap: auto;">
                                                    <span
                                                        class="drp-text hide-mob">{{ __('Template: ') }}{{ $emailTemplate->name }}</span>
                                                    <i class="ti ti-chevron-down drp-arrow nocolor"></i>
                                                </a>
                                                <div class="dropdown-menu dash-h-dropdown dropdown-menu-end"
                                                    aria-labelledby="dropdownLanguage">
                                                    @foreach ($emailTemplates as $EmailTemplate)
                                                        <a href="{{ route('manage.preorder.email.language', [$EmailTemplate->id,Request::segment(4) ? Request::segment(4) : auth()->user()->language]) }}"
                                                            class="dropdown-item {{$EmailTemplate->name == $emailTemplate->name ? 'text-primary' : '' }}">{{ $EmailTemplate->name }}</a>
                                                    @endforeach
                                                </div>
                                            </li>
                                    
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-4">
                                <div class="row mt-2">
                                    <div class="col-xl-12">
                                        <div class="row">
                                            <div class="col-lg-12 col-md-12 col-sm-12">
                                                <div class="card">
                                                    <div class="card-body">
                                                        <div class="row text-xs">
                                                            <div class="col-md-6">
                                                                <p class="mb-1">{{ __('Product Name') }} : <span
                                                                        class="pull-right text-primary">{product_name}</span></p>
                                                                <p class="mb-1">{{ __('Order ID') }} : <span
                                                                        class="pull-right text-primary">{order_id}</span></p>
                                                                <p class="mb-1">{{ __('Order Link') }} : <span
                                                                        class="pull-right text-primary">{order_link}</span></p>
                                                                <p class="mb-1">{{ __('Order Status') }} : <span
                                                                        class="pull-right text-primary">{order_status}</span></p>
                                                                <p class="mb-1">{{ __('Order date') }} : <span
                                                                        class="pull-right text-primary">{order_date}</span></p>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <p class="mb-1">{{ __('Payment Status') }} : <span
                                                                        class="pull-right text-primary">{payment_status}</span></p>
                                                                <p class="mb-1">{{ __('Owner Name') }} : <span
                                                                        class="pull-right text-primary">{owner_name}</span></p>
                                                                <p class="mb-1">{{ __('Customer Name') }} : <span
                                                                        class="pull-right text-primary">{customer_name}</span></p>
                                                                <p class="mb-1">{{ __('App Name') }} : <span
                                                                        class="pull-right text-primary">{app_name}</span></p>
                                                                <p class="mb-1">{{ __('App Url') }} : <span
                                                                        class="pull-right text-primary">{app_url}</span></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="form-group col-6">
                                                {{ Form::label('subject', __('Subject'), ['class' => 'col-form-label text-dark']) }}
                                                {{ Form::text('subject', null, ['class' => 'form-control font-style', 'required' => 'required']) }}
                                            </div>
                                            <div class="form-group col-md-6">
                                                {{ Form::label('from', __('From'), ['class' => 'col-form-label text-dark']) }}
                                                {{ Form::text('from', $emailTemplate->from, ['class' => 'form-control font-style', 'required' => 'required']) }}
                                            </div>
                                            <div class="form-group col-12">
                                                {{ Form::label('content', __('Email Message'), ['class' => 'col-form-label text-dark']) }}
                                                {{ Form::textarea('content', $currEmailTempLang->content, ['class' => 'summernote-simple payment-email-language', 'required' => 'required']) }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-end flex-wrap ">
                                {{ Form::hidden('language', null, ['class' => 'language-code']) }}
                                {{ Form::submit(__('Save Changes'), ['class' => 'btn btn-xs btn-primary']) }}
                            </div>
                            {{ Form::close() }}
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
@endsection

@push('custom-script')
@endpush