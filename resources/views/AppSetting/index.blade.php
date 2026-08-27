@extends('layouts.app')

@section('page-title', __('Store Settings'))

@section('action-button')
<!-- Search Input -->
<div class="store-setting-search d-flex justify-content-end">
    <input type="text" id="tab-search" class="form-control" style="max-width: 300px;"
        placeholder="{{ __('Search...') }}">
</div>
@endsection

@section('breadcrumb')
<li class="breadcrumb-item">{{ __('Store Settings') }}</li>
@endsection

@section('content')
<div class="row">
    <!-- Content here -->
    <div class="col-xl-12">
        <div class="card">
            <div class="list-group list-group-flush app-seeting-tab" id="useradd-sidenav">
                <ul class="nav nav-pills w-100  row store-setting-tab" id="pills-tab" role="tablist">
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
                        <a href="#Theme_Setting"
                            class="nav-link @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-home-tab')) active show @endif btn-sm f-w-600"
                            id="pills-home-tab" data-bs-toggle="pill" data-bs-target="#pills-home" type="button"
                            role="tab" aria-controls="pills-home" aria-selected="true">
                            {{ __('Store Settings') }}
                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#SEO_Setting"
                            class="nav-link btn-sm f-w-600 @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-seo-tab')) active show @endif"
                            id="pills-seo-tab" data-bs-toggle="pill" data-bs-target="#pills-seo" type="button"
                            role="tab" aria-controls="pills-seo" aria-selected="true">
                            {{ __('SEO Settings') }}
                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#custom_Setting"
                            class="nav-link @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-custom-tab')) active show @endif btn-sm f-w-600"
                            id="pills-custom-tab" data-bs-toggle="pill" data-bs-target="#pills-custom" type="button"
                            role="tab" aria-controls="pills-custom" aria-selected="true">
                            {{ __('Custom Settings') }}
                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#checkout_Setting"
                            class="nav-link @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-checkout-tab')) active show @endif btn-sm f-w-600"
                            id="pills-checkout-tab" data-bs-toggle="pill" data-bs-target="#pills-checkout" type="button"
                            role="tab" aria-controls="pills-checkout" aria-selected="true">
                            {{ __('Checkout Settings') }}
                        </a>

                    </li>
                    <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6 col-12 text-center" role="presentation">
                        <a href="#shippinglabel_Setting"
                            class="nav-link @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-shippinglabel-tab')) active show @endif btn-sm f-w-600"
                            id="pills-shippinglabel-tab" data-bs-toggle="pill" data-bs-target="#pills-shippinglabel" type="button"
                            role="tab" aria-controls="pills-shippinglabel" aria-selected="true">
                            {{ __('Shipping Label Settings') }}
                        </a>

                    </li>
                    @stack('appSettingTab')
                </ul>
            </div>
        </div>
    </div>

    <div class="col-xl-12">
        <div class="tab-content store-tab-content" id="pills-tabContent">
            <!-- Tab panes -->
            <div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-home-tab')) show active @endif"
                id="pills-home" role="tabpanel" aria-labelledby="pills-home-tab">
                <div id="Theme_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> {{ __('Store Settings') }} </h5>
                        </div>
                        {{ Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-body p-4 store-setting-tab">
                            <input type="hidden" name="app_setting_tab" value="pills-home-tab">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="row ">
                                        <div class="form-group col-md-3">
                                            {{ Form::label('theme_name', __('Store Name'), ['class' => 'form-label']) }}
                                            {!! Form::text('theme_name', $store->name, ['class' => 'form-control',
                                            'placeholder' => __('Store Name')]) !!}
                                            @error('theme_name')
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    {{ $message }}
                                                </strong>
                                            </span>
                                            @enderror
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('email', __('Store Email'), ['class' => 'form-label']) }}
                                            {!! Form::text('email', $store->email, ['class' => 'form-control',
                                            'placeholder' => __('Store Email')]) !!}
                                            @error('email')
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    {{ $message }}
                                                </strong>
                                            </span>
                                            @enderror
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('store_slug', __('Store Slug'), ['class' => 'form-label']) }}
                                            {!! Form::text('store_slug', $store->slug, ['class' => 'form-control',
                                            'placeholder' => __('Store Slug')]) !!}
                                            @error('store_slug')
                                            <span class="invalid-store_slug" role="alert">
                                                <strong class="text-danger">
                                                    {{ $message }}
                                                </strong>
                                            </span>
                                            @enderror
                                        </div>
                                        <div class="form-group col-md-3">
                                            {{ Form::label('languages-dropdown', __('Store Language'), ['class' => 'form-label']) }}
                                            {!! Form::select('default_language', $languages, $store->default_language, [
                                            'class' => 'form-control',
                                            'data-role' => 'tagsinput',
                                            'id' => 'languages-dropdown',
                                            ]) !!}
                                            @error('default_language')
                                            <span class="invalid-store_name" role="alert">
                                                <strong class="text-danger">
                                                    {{ $message }}
                                                </strong>
                                            </span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="row">
                                        @stack('appSettingEnableBtn')
                                    </div>
                                </div>
                               
                                <div class="col-lg-3 col-sm-6 col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>{{ __('Invoice Logo') }}</h5>
                                        </div>
                                        <div class="card-body pt-0">
                                            <div class=" setting-card">
                                                <div class="logo-content mt-4">
                                                    <a href="{{ !empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png' }}"
                                                        target="_blank">
                                                        <img src="{{ !empty($invoice_logo) ? $invoice_logo : $profile . '/logo.png' }}"
                                                            class="big-logo invoice_logo img_setting" id="invoiceLogo">
                                                    </a>
                                                </div>
                                                <div class="choose-files mt-4">
                                                    <label for="invoice_logo">
                                                        <div class=" bg-primary logo_update"> <i
                                                                class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                        </div>
                                                        <input type="file" name="invoice_logo" id="invoice_logo"
                                                            class="form-control file"
                                                            data-filename="invoice_logo_update"
                                                            onchange="document.getElementById('invoiceLogo').src = window.URL.createObjectURL(this.files[0])">
                                                    </label>
                                                </div>
                                                @error('invoice_logo')
                                                <div class="row">
                                                    <span class="invalid-invoice_logo" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                </div>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-lg-3 col-sm-6 col-md-6">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5>{{ __('Store Image') }}</h5>
                                        </div>
                                        <div class="card-body pt-0">
                                            <div class=" setting-card">
                                                <div class="logo-content mt-4">
                                                    <a href="{{ !empty($theme_image) ? $theme_image : $profile . '/logo.png' }}"
                                                        target="_blank">
                                                        <img src="{{ !empty($theme_image) ? $theme_image : $profile . '/logo.png' }}"
                                                            class="big-logo store_image img_setting" id="storeImage">
                                                    </a>
                                                </div>
                                                <div class="choose-files mt-4">
                                                    <label for="theme_image">
                                                        <div class=" bg-primary logo_update"> <i
                                                                class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                        </div>
                                                        <input type="file" class="form-control file" name="theme_image"
                                                            id="theme_image" data-filename="logo_update"
                                                            onchange="document.getElementById('storeImage').src = window.URL.createObjectURL(this.files[0])">
                                                    </label>
                                                </div>
                                                @error('theme_image')
                                                <div class="row">
                                                    <span class="invalid-logo" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                </div>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap gap-1">
                            @permission('Edit Store Setting')
                            <input type="submit" value="{{ __('Save Changes') }}"
                                class="btn-submit btn btn-primary btn-badge">
                            @endpermission
                            {!! Form::close() !!}

                            @if (\Auth::user()->type == 'admin')
                            {!! Form::open([
                            'method' => 'DELETE',
                            'route' => ['ownerstore.remove', getCurrentStore()],
                            'class' => 'd-inline',
                            ]) !!}
                            <button type="button"
                                class="btn btn-secondary btn-danger btn-sm btn-badge show_confirm danger-btn"
                                data-confirm="{{ __('Are You Sure?') }}"
                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}"
                                data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}">
                                <span class="text-black">{{ __('Delete Store') }}</span>
                            </button>
                            {!! Form::close() !!}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-seo-tab')) show active @endif"
                id="pills-seo" role="tabpanel" aria-labelledby="pills-seo-tab">
                <div id="SEO_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> {{ __('SEO Settings') }} </h5>
                        </div>
                        {{ Form::model($setting, ['route' => 'seo.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-body p-4">
                            <input type="hidden" name="app_setting_tab" value="pills-seo-tab">
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <i class="fab fa-google fs-3 me-1" aria-hidden="true"></i>
                                        {{ Form::label('google_analytic', __('Google Analytic'), ['class' => 'form-label']) }}
                                        {{ Form::text('google_analytic', null, ['class' => 'form-control', 'placeholder' => 'UA-XXXXXXXXX-X']) }}
                                        @error('google_analytic')
                                        <span class="invalid-google_analytic" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group">
                                        <i class="fab fa-facebook-f fs-3 me-1" aria-hidden="true"></i>
                                        {{ Form::label('facebook_pixel_code', __('Facebook Pixel'), ['class' => 'form-label']) }}
                                        {{ Form::text('fbpixel_code', null, ['class' => 'form-control', 'placeholder' => 'UA-0000000-0']) }}
                                        @error('facebook_pixel_code')
                                        <span class="invalid-google_analytic" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('metakeyword', __('Meta Keywords'), ['class' => 'form-label']) }}
                                        {!! Form::textarea('metakeyword', null, [
                                        'class' => 'form-control',
                                        'rows' => 3,
                                        'placeholder' => __('Meta Keyword'),
                                        ]) !!}
                                        @error('meta_keywords')
                                        <span class="invalid-about" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>

                                    <div class="form-group">
                                        {{ Form::label('metadesc', __('Meta Description'), ['class' => 'form-label']) }}
                                        {!! Form::textarea('metadesc', null, [
                                        'class' => 'form-control',
                                        'rows' => 3,
                                        'placeholder' => __('Meta Description'),
                                        ]) !!}

                                        @error('meta_description')
                                        <span class="invalid-about" role="alert">
                                            <strong class="text-danger">{{ $message }}</strong>
                                        </span>
                                        @enderror
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="form-group pt-0">
                                        <div class=" setting-card">
                                            <label for="" class="form-label">{{ __('Meta Image') }}</label>
                                            <div class="">
                                                <a href="{{ asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'themes/stylique/theme_img/img_1.png') }}"
                                                    target="_blank">
                                                    <img id="meta_image" alt="your image"
                                                        src="{{ asset(!empty($setting['metaimage']) ? $setting['metaimage'] : 'themes/stylique/theme_img/img_1.png') }}"
                                                        width="220px" class="img_setting">
                                                </a>
                                            </div>
                                            <div class="choose-files mt-3">
                                                <label for="metaimage">
                                                    <div class=" bg-primary full_logo"> <i
                                                            class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                    </div>
                                                    <input type="file" name="metaimage" id="metaimage"
                                                        class="form-control file" data-filename="metaimage"
                                                        onchange="document.getElementById('meta_image').src = window.URL.createObjectURL(this.files[0])">
                                                </label>
                                            </div>
                                            @error('metaimage')
                                            <div class="row">
                                                <span class="invalid-logo" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            </div>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap ">
                            @permission('Edit Store Setting')
                            <input type="submit" value="{{ __('Save Changes') }}"
                                class="btn-submit btn btn-primary btn-badge">
                            @endpermission
                        </div>
                        {!! Form::close() !!}
                    </div>
                </div>
            </div>
            <div class="tab-pane fade  @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-custom-tab')) show active @endif"
                id="pills-custom" role="tabpanel" aria-labelledby="pills-custom-tab">
                <div id="custom_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> {{ __('Custom Settings') }} </h5>

                        </div>
                        {{ Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-body p-4">
                            <input type="hidden" name="app_setting_tab" value="pills-custom-tab">
                            <div class="row mt-2">
                                @if ($plan && ($plan->enable_domain == 'on' || $plan->enable_subdomain == 'on'))
                                <div class="form-group col-md-6 py-4">
                                    <div class="radio-button-group row gy-2 mts">
                                        <div class="col-sm-4">
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 {{ $enable_storelink == 'on' ? 'active' : '' }}">
                                                <input type="radio" class="domain_click  radio-button"
                                                    name="enable_domain" value="enable_storelink" id="enable_storelink"
                                                    {{ $enable_storelink == 'on' ? 'checked' : '' }}>
                                                {{ __('Store Link') }}
                                            </label>
                                        </div>
                                        <div class="col-sm-4">
                                            @if ($plan && ($plan->enable_domain == 'on'))
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 {{ $enable_domain == 'on' ? 'active' : '' }}">
                                                <input type="radio" class="domain_click radio-button"
                                                    name="enable_domain" value="enable_domain" id="enable_domain"
                                                    {{ $enable_domain == 'on' ? 'checked' : '' }}>
                                                {{ __('Custom Domain') }}
                                            </label>
                                            @endif
                                        </div>
                                        <div class="col-sm-4">
                                            @if ($plan && ($plan->enable_subdomain == 'on'))
                                            <label
                                                class="btn btn-outline-primary btn-badge w-100 {{ $enable_subdomain == 'on' ? 'active' : '' }}">
                                                <input type="radio" class="domain_click radio-button"
                                                    name="enable_domain" value="enable_subdomain" id="enable_subdomain"
                                                    {{ $enable_subdomain == 'on' ? 'checked' : '' }}>
                                                {{ __('Sub Domain') }}
                                            </label>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($domainPointing == 1)
                                    <div class="text-sm mt-2" id="domainnote"
                                        style="{{ $enable_domain == 'on' ? 'display: block' : '' }}">
                                        <span><b class="text-success">{{ __('Note : Before add Custom Domain, your domain A record is pointing to our server IP :') }}{{ $serverIp }}|
                                                {{ __('Your Custom Domain IP Is This: ') }}</b></span>
                                    </div>
                                    @else
                                    <div class="text-sm mt-2" id="domainnote" style="display: none">
                                        <span><b>{{ __('Note : Before add Custom Domain, your domain A record is pointing to our server IP :') }}{{ $serverIp }}|</b>
                                            <b class="text-danger">{{ __('Your Custom Domain IP Is This: ') }}
                                                {{ $serverIp }}</b></span>
                                    </div>
                                    @endif
                                    @if ($subdomainPointing == 1)
                                    <div class="text-sm mt-2" id="subdomainnote" style="display: none">
                                        <span><b class="text-success">{{ __('Note : Before add Sub Domain, your domain A record is pointing to our server IP :') }}{{ $serverIp }}|
                                                {{ __('Your Sub Domain IP Is This: ') }}</b></span>
                                    </div>
                                    @else
                                    <div class="text-sm mt-2" id="subdomainnote" style="display: none">
                                        <span><b>{{ __('Note : Before add Sub Domain, your domain A record is pointing to our server IP :') }}{{ $serverIp }}|</b>
                                            <b class="text-danger">{{ __('Your Sub Domain IP Is This: ') }}</b></span>
                                    </div>
                                    @endif
                                </div>

                                <div class="form-group col-md-6" id="StoreLink"
                                    style="{{ $enable_storelink == 'on' ? 'display: block' : 'display: none' }}">
                                    {{ Form::label('store_link', __('Store Link'), ['class' => 'form-label']) }}
                                    <div class="input-group">
                                        <input type="text" value="{{ route('landing_page', $slug) }}" id="myInput"
                                            class="form-control d-inline-block me-2" aria-label="Recipient's username"
                                            aria-describedby="button-addon2" readonly>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-badge" type="button"
                                                onclick="myFunction()" id="button-addon2"><i class="far fa-copy"></i>
                                                {{ __('Copy Link') }}</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group col-md-6 domain"
                                    style="{{ $enable_domain == 'on' ? 'display:block' : 'display:none' }}">
                                    {{ Form::label('store_domain', __('Custom Domain'), ['class' => 'form-label']) }}
                                    {{ Form::text('domains', $domains, ['class' => 'form-control', 'placeholder' => __('xyz.com')]) }}
                                </div>
                                @if ($plan && ($plan->enable_subdomain == 'on'))
                                <div class="form-group col-md-6 sundomain"
                                    style="{{ $enable_subdomain == 'on' ? 'display:block' : 'display:none' }}">
                                    {{ Form::label('store_subdomain', __('Sub Domain'), ['class' => 'form-label']) }}
                                    <div class="input-group">
                                        {{ Form::text('subdomain', $slug, ['class' => 'form-control', 'placeholder' => __('Enter Domain'), 'readonly']) }}
                                        <div class="input-group-append">
                                            <span class="input-group-text"
                                                id="basic-addon2">.{{ $subdomain_name }}</span>
                                        </div>
                                    </div>
                                </div>
                                @endif
                                @else
                                <div class="form-group col-md-6" id="StoreLink">
                                    {{ Form::label('store_link', __('Store Link'), ['class' => 'form-label']) }}
                                    <div class="input-group">
                                        <input type="text" value="{{ route('landing_page', $slug) }}" id="myInput"
                                            class="form-control d-inline-block" aria-label="Recipient's username"
                                            aria-describedby="button-addon2" readonly>
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-primary btn-badge" type="button"
                                                onclick="myFunction()" id="button-addon2"><i class="far fa-copy"></i>
                                                {{ __('Copy Link') }}</button>
                                        </div>
                                    </div>
                                </div>
                                @endif

                                <div class="form-group col-md-6">
                                    {{ Form::label('storejs', __('Store Custom JS'), ['class' => 'form-label']) }}
                                    {{ Form::textarea('storejs', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('console.log(hello);')]) }}
                                    @error('storejs')
                                    <span class="invalid-about" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>

                                <div class="form-group col-md-6">
                                    {{ Form::label('storecss', __('Store Custom CSS'), ['class' => 'form-label']) }}
                                    {{ Form::textarea('storecss', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => __('Custom CSS')]) }}
                                    @error('storecss')
                                    <span class="invalid-about" role="alert">
                                        <strong class="text-danger">{{ $message }}</strong>
                                    </span>
                                    @enderror
                                </div>

                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap">
                            @permission('Edit Store Setting')
                            <input type="submit" value="{{ __('Save Changes') }}"
                                class="btn-submit btn btn-primary btn-badge">
                            {!! Form::close() !!}
                            @endpermission
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade  @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-checkout-tab')) show active @endif"
                id="pills-checkout" role="tabpanel" aria-labelledby="pills-checkout-tab">
                <div id="checkout_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> {{ __('Checkout Settings') }} </h5>

                        </div>
                        <div class="card-body p-4">
                            {{ Form::model($setting, ['route' => 'theme.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                            <div class="row mt-2">
                                <input type="hidden" name="app_setting_tab" value="pills-checkout-tab">
                                <div class="form-group col-lg-6 col-sm-6 col-md-6">
                                    <div class="custom-control form-switch p-0">
                                        <label class="form-check-label mb-2 text-primary"
                                            for="additional_notes">{{ __('Additional Notes') }}</label><br>
                                        <small class="mb-2 d-inline-block"> {{ __('Note') }}:
                                            {{ __('Enable the Additional Notes functionality to allow users to provide extra order-specific information on the checkout page.') }}</small><br>
                                        {!! Form::hidden('additional_notes', 'off') !!}
                                        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                            data-onstyle="primary" name="additional_notes" id="additional_notes"
                                            {{ isset($Additional_notes) && $Additional_notes == 'on' ? 'checked="checked"' : '' }}>
                                    </div>
                                </div>

                                <div class="form-group col-lg-6 col-sm-6 col-md-6">
                                    <div class="custom-control form-switch p-0 ">
                                        <label class="form-check-label mb-2 text-primary"
                                            for="is_checkout_login_required">{{ __('Is Checkout Login Required') }}</label><br>
                                        <small class="mb-2 d-inline-block"> {{ __('Note') }}:
                                            {{ __('Use the Is Checkout Required feature to prevent guest checkout, requiring users to log in before completing their purchase for added security.') }}</small><br>
                                        {!! Form::hidden('is_checkout_login_required', 'off') !!}
                                        <input type="checkbox" class="form-check-input" data-toggle="switchbutton"
                                            data-onstyle="primary" name="is_checkout_login_required"
                                            id="is_checkout_login_required"
                                            {{ isset($is_checkout_login_required) && $is_checkout_login_required == 'on' ? 'checked="checked"' : '' }}>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end flex-wrap">
                            @permission('Edit Store Setting')
                            <input type="submit" value="{{ __('Save Changes') }}"
                                class="btn-submit btn btn-primary btn-badge">
                            {!! Form::close() !!}
                            @endpermission
                        </div>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade  @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-shippinglabel-tab')) show active @endif"
                id="pills-shippinglabel" role="tabpanel" aria-labelledby="pills-shippinglabel-tab">
                <div id="shippinglabel_Setting">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center gap-3">
                            <h5 class=""> {{ __('Shipping Label Settings') }} </h5>

                        </div>
                        {{ Form::model($setting, ['route' => 'shippinglabel.settings', 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
                            <div class="card-body p-4">
                                <input type="hidden" name="app_setting_tab" value="pills-shippinglabel-tab">
                                <div class="row mt-2">
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            {{ Form::label('store_address', __('Address'), ['class' => 'form-label']) }}
                                            {{ Form::text('store_address', null, ['class' => 'form-control', 'placeholder' => 'Address']) }}
                                            @error('store_Address')
                                                <span class="invalid-store_Address" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            {{ Form::label('store_city', __('City'), ['class' => 'form-label']) }}
                                            {{ Form::text('store_city', null, ['class' => 'form-control', 'placeholder' => 'City']) }}
                                            @error('store_city')
                                                <span class="invalid-store_city" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            {{ Form::label('store_state', __('State'), ['class' => 'form-label']) }}
                                            {{ Form::text('store_state', null, ['class' => 'form-control', 'placeholder' => 'State']) }}
                                            @error('store_state')
                                                <span class="invalid-store_state" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            {{ Form::label('store_country', __('Country'), ['class' => 'form-label']) }}
                                            {{ Form::text('store_country', null, ['class' => 'form-control', 'placeholder' => 'Country']) }}
                                            @error('store_country')
                                                <span class="invalid-store_country" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-group">
                                            {{ Form::label('store_zipcode', __('Zipcode'), ['class' => 'form-label']) }}
                                            {{ Form::number('store_zipcode', null, ['class' => 'form-control', 'placeholder' => 'Zipcode']) }}
                                            @error('store_zipcode')
                                                <span class="invalid-store_zipcode" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer d-flex justify-content-end flex-wrap">
                                @permission('Edit Store Setting')
                                <input type="submit" value="{{ __('Save Changes') }}"
                                    class="btn-submit btn btn-primary btn-badge">
                                @endpermission
                            </div>
                        {!! Form::close() !!}
                    </div>
                </div>
            </div>
            @stack('appSettingTabForm')
        </div>
    </div>
</div>
@endsection

@push('custom-script')
<script>
function myFunction() {
    var copyText = document.getElementById("myInput");
    copyText.select();
    copyText.setSelectionRange(0, 99999)
    document.execCommand("copy");
    show_toastr('Success', "{{ __('Link copied') }}", 'success');
}

var scrollSpy = new bootstrap.ScrollSpy(document.body, {
    target: '#useradd-sidenav',
    offset: 300
});
</script>


<script>
$(function() {
    $('body').on('click', '.image_delete', function(e) {
        e.preventDefault();
        var id = $(this).attr('data-id');
        var data = {
            'image': id
        };
        // now make the ajax request
        $.ajax({
            type: "POST",
            dataType: "json",
            url: "#",
            data: data,
            context: this,
            success: function(data) {
                $(this).closest('.product_Image').remove();
                $('#loader').fadeOut();
                $('#Main_Page_Content_web_post').find('.submit_form').click();
            }
        });
    });
});

document.getElementById('tab-search').addEventListener('input', function() {
    var searchValue = this.value.toLowerCase();
    var navItems = document.querySelectorAll('#pills-tab .nav-item');

    navItems.forEach(function(item) {
        var tabText = item.querySelector('.nav-link').textContent.toLowerCase();
        if (tabText.includes(searchValue)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});
</script>
@endpush
                <!-- Tab content -->
