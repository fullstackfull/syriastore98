@extends('layouts.app')
@section('page-title')
    {{ __('Landing Page') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item">{{__('Landing Page')}}</li>
@endsection

@php
    $settings = \Workdo\LandingPage\Entities\LandingPageSetting::settings();
    $logo=get_file('storage/uploads/landing_page_image');
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
                                        <h5>{{ __('Discover') }}</h5>
                                    </div>
                                </div>
                            </div>

                            {{ Form::open(array('route' => 'discover.store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
                                @csrf
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('discover_heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('discover_heading',$settings['discover_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'discover_heading']) }}
                                                @error('mail_host')
                                                    <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('discover_description', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('discover_description', $settings['discover_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'discover_description']) }}
                                                @error('mail_port')
                                                    <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('discover_live_demo_link', __('Live Demo Link'), ['class' => 'form-label']) }}
                                                {{ Form::text('discover_live_demo_link', $settings['discover_live_demo_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'discover_live_demo_link']) }}
                                                @error('discover_live_demo_link')
                                                    <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('discover_buy_now_link', __('Buy Now Link'), ['class' => 'form-label']) }}
                                                {{ Form::text('discover_buy_now_link', $settings['discover_buy_now_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'discover_buy_now_link']) }}
                                                @error('discover_buy_now_link')
                                                    <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer text-end">
                                    <button class="btn btn-print-invoice btn-badge btn-primary mr-2" type="submit" >{{ __('Save Changes') }}</button>
                                </div>
                            {{ Form::close() }}
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-9">
                                        <h5>{{ __('Menu Bar') }}</h5>
                                    </div>
                                    <div class="col-3 justify-content-end d-flex">
                                        <a href="javascript::void(0);" class="btn btn-sm btn-primary btn-badge" data-size="lg" data-url="{{ route('discover_create') }}" data-ajax-popup="true"  data-bs-toggle="tooltip" data-title="{{__('Discover Feature Create')}}" title="{{__('Add Discover Feature')}}" >
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
                                           @if (is_array($discover_of_features) || is_object($discover_of_features))
                                            @php
                                                $no = 1
                                            @endphp
                                                @foreach ($discover_of_features as $key => $value)
                                                    <tr>
                                                        <td>{{ $no++ }}</td>
                                                        <td>{{ $value['discover_heading'] }}</td>
                                                        <td>
                                                            <span  class="d-flex gap-1 justify-content-end">
                                                                <button class="btn btn-sm btn-info btn-badge"
                                                                data-url="{{ route('discover_edit',$key) }}"  data-size="lg"
                                                                    data-ajax-popup="true" data-title="{{ __('Edit Discover Feature') }}" data-bs-toggle="tooltip"
                                                                    title="{{ __('Edit') }}">
                                                                    <i class="ti ti-pencil"></i>
                                                                </button>
                                                                {!! Form::open(['method' => 'GET', 'route' => ['discover_delete', $key], 'class' => 'd-inline']) !!}
                                                                <button type="button" class="btn btn-sm btn-danger btn-badge show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
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