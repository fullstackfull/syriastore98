@extends('layouts.app')
@section('page-title')
    {{ __('Landing Page') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{__('Landing Page')}}</li>
@endsection

@php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $logo= get_file('storage/uploads/landing_page_image');
@endphp

@push('custom-script')
    <script src="{{ asset('packages/workdo/LandingPage/src/Resources/assets/js/plugins/tinymce.min.js')}}" referrerpolicy="origin"></script>
@endpush

@section('breadcrumb')
    <li class="breadcrumb-item">{{__('Landing Page')}}</li>
@endsection

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
                            <div class="card-header">
                                <div class="row">
                                    <div class="col-lg-10 col-md-10 col-sm-10">
                                        <h5>{{ __('Feature Section') }}</h5>
                                    </div>
                                </div>
                            </div>
                            {{ Form::open(array('route' => 'features.store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
                                @csrf
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('feature_title', __('Title'), ['class' => 'form-label']) }}
                                                {{ Form::text('feature_title',$settings['feature_title'], ['class' => 'form-control ', 'placeholder' => __('Enter Title'), 'id' => 'feature_title']) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('feature_heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('feature_heading',$settings['feature_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'feature_heading']) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('feature_description', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('feature_description', $settings['feature_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'feature_description']) }}
                                                @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('feature_buy_now_link', __('Buy Now Link'), ['class' => 'form-label']) }}
                                                {{ Form::text('feature_buy_now_link', $settings['feature_buy_now_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'feature_buy_now_link']) }}
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
                                    <button class="btn btn-print-invoice btn-primary btn-badge mr-2" type="submit">{{ __('Save Changes') }}</button>
                                </div>
                            {{ Form::close() }}
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-9">
                                        <h5>{{ __('Menu Bar') }}</h5> 
                                    </div>
                                    <div class="col-3 all-button-box justify-content-end d-flex">
                                        <a href="#" data-size="lg" data-url="{{ route('feature_create') }}" data-ajax-popup="true" data-bs-toggle="tooltip" title="{{__('Add Menu')}}" data-title="{{__('Create Menu')}}" class="btn btn-sm btn-primary btn-badge">
                                            <i class="ti ti-plus"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>{{__('No')}}</th>
                                                <th>{{__('Name')}}</th>
                                                <th class="text-end">{{__('Action')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @if (is_array($feature_of_features) || is_object($feature_of_features))
                                            @php
                                                $ff_no = 1
                                            @endphp
                                                @foreach ($feature_of_features as $key => $value)
                                                    <tr>
                                                        <td>{{ $ff_no++ }}</td>
                                                        <td>{{ $value['feature_heading'] }}</td>
                                                        <td>
                                                            <span class="d-flex gap-1 justify-content-end">
                                                                <button class="btn btn-sm btn-info btn-badge"
                                                                data-url="{{ route('feature_edit',$key) }}" data-size="lg"
                                                                data-ajax-popup="true" data-title="{{ __('Edit Feature') }}" data-bs-toggle="tooltip"
                                                                title="{{ __('Edit') }}">
                                                                <i class="ti ti-pencil"></i>
                                                                </button>
                                                                {!! Form::open(['method' => 'GET', 'route' => ['feature_delete', $key], 'class' => 'd-inline']) !!}
                                                                <button type="button" class="btn btn-sm btn-danger show_confirm btn-badge" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                                                                title="{{ __('Delete') }}">
                                                                <i class="ti ti-trash"></i>
                                                                </button>
                                                                {!! Form::close() !!}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="row">
                                    <div class="col-lg-10 col-md-10 col-sm-10">
                                        <h5>{{ __('Feature') }}</h5>
                                    </div>
                                </div>
                            </div>
                            {{ Form::open(array('route' => 'feature_highlight_create', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
                                @csrf
                                <div class="card-body">
                                    <div class="row">

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('highlight_feature_heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('highlight_feature_heading', $settings['highlight_feature_heading'], ['class' => 'form-control', 'placeholder' => __('Enter Heading'), 'id' => 'highlight_feature_heading']) }}
                                                @error('highlight_feature_heading')
                                                    <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('highlight_feature_heading', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('highlight_feature_description', $settings['highlight_feature_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'highlight_feature_description']) }}
                                                @error('highlight_feature_description')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('highlight_feature_image', __('Logo'), ['class' => 'form-label']) }}
                                                <div class="mt-4">
                                                    <img id="image1" src="{{ check_file($settings['highlight_feature_image']) ? get_file($settings['highlight_feature_image']) : $logo.'/'. $settings['highlight_feature_image'] }}"
                                                        class="big-logo" style="width: 100% !important; max-width: 75% !important;">
                                                </div>
                                                <div class="choose-files mt-3">
                                                    <label for="highlight_feature_image">
                                                        <div class="btn-badge bg-primary dark_logo_update" style="cursor: pointer;"> <i class="ti ti-upload px-1">
                                                            </i>{{ __('Choose File Here') }}
                                                        </div>
                                                        <input type="file" name="highlight_feature_image" id="highlight_feature_image" class="form-control file" data-filename="highlight_feature_image">
                                                    </label>
                                                </div>
                                                @error('highlight_feature_image')
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
                                <div class="card-footer text-end">
                                    <input class="btn btn-print-invoice btn-badge btn-primary mr-2" type="submit" value="{{ __('Save Changes') }}">
                                </div>
                            {{ Form::close() }}
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-lg-9 col-md-9 col-sm-9  col-9">
                                        <h5>{{ __('Feature Block') }}</h5>
                                    </div>
                                    <div class="col-lg-3 col-md-3 col-sm-3 col-3 d-flex justify-content-end d-flex">
                                        <a href="#" data-size="lg" data-url="{{ route('features_create') }}" data-ajax-popup="true"  data-bs-toggle="tooltip" title="{{ __('Add Feature Block') }}" data-title="{{__('Create Feature Block')}}"  class="btn btn-sm btn-badge btn-primary">
                                            <i class="ti ti-plus"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">

                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>{{__('No')}}</th>
                                                <th>{{__('Name')}}</th>
                                                <th class="text-end">{{__('Action')}}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @if (is_array($feature_of_features) || is_object($feature_of_features))
                                            @php
                                                $of_no = 1;
                                            @endphp
                                                @foreach ($other_features as $key => $value)
                                                    <tr>
                                                        <td>{{ $of_no++ }}</td>
                                                        <td>{{ $value['other_features_heading'] }}</td>
                                                        <td>
                                                            <span class="d-flex gap-1 justify-content-end">
                                                                <button class="btn btn-sm btn-info btn-badge"
                                                                data-url="{{ route('features_edit',$key) }}" data-size="lg"
                                                                data-ajax-popup="true" data-title="{{ __('Edit Feature Block') }}" data-bs-toggle="tooltip"
                                                                title="{{ __('Edit') }}">
                                                                <i class="ti ti-pencil"></i>
                                                                </button>
                                                                {!! Form::open(['method' => 'GET', 'route' => ['features_delete', $key], 'class' => 'd-inline']) !!}
                                                                <button type="button" class="btn btn-sm btn-danger show_confirm btn-badge" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}" 
                                                                title="{{ __('Delete') }}">
                                                                <i class="ti ti-trash"></i>
                                                                </button>
                                                                {!! Form::close() !!}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            @endif
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    {{--  End for all settings tab --}}
                </div>
            </div>
        </div>
    </div>
@endsection
