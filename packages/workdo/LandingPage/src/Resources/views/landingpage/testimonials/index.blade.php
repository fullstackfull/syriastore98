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
                                        <h5>{{ __('Testimonials') }}</h5>
                                    </div>
                                </div>
                            </div>

                            {{ Form::open(array('route' => 'testimonials.store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
                                @csrf
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('testimonials_heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('testimonials_heading',$settings['testimonials_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'testimonials_heading']) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('testimonials_description', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('testimonials_description', $settings['testimonials_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'testimonials_description']) }}
                                                @error('testimonials_description')
                                                <span class="invalid-testimonials_description" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-12">
                                            <div class="form-group">
                                                {{ Form::label('testimonials_long_description', __('Long Description'), ['class' => 'form-label']) }}
                                                {{ Form::textarea('testimonials_long_description', $settings['testimonials_long_description'], ['class' => 'form-control', 'placeholder' => __('Enter Long Description'), 'id' => 'testimonials_long_description']) }}
                                                @error('testimonials_long_description')
                                                <span class="invalid-mail_port" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer text-end">
                                    <button class="btn btn-print-invoice btn-badge btn-primary mr-2" type="submit">{{ __('Save Changes') }}</button>
                                </div>
                            {{ Form::close() }}
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <div class="row align-items-center">
                                    <div class="col-lg-9 col-md-9 col-sm-9 col-9">
                                        <h5>{{ __('Menu Bar') }}</h5>
                                    </div>
                                    <div class="col-lg-3 col-md-3 col-sm-3 col-3 justify-content-end d-flex">
                                        <a href="javascript::void(0)" data-size="lg" data-url="{{ route('testimonials_create') }}" data-ajax-popup="true"  data-bs-toggle="tooltip" title="{{__('Add Testimonial')}}" data-title="{{__('Create Testimonial')}}"  class="btn btn-sm btn-badge btn-primary">
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
                                            @if (is_array($testimonials) || is_object($testimonials))
                                                @php
                                                    $no = 1;
                                                @endphp
                                                @foreach ($testimonials as $key => $value)
                                                    <tr>
                                                        <td>{{ $no }}</td>
                                                        <td>{{ $value['testimonials_title'] }}</td>
                                                        <td>
                                                            <span class="d-flex gap-1 justify-content-end">
                                                                <button class="btn btn-badge btn-sm btn-info" data-url="{{ route('testimonials_edit',$key) }}" data-size="lg" data-ajax-popup="true" data-title="{{ __('Edit Testimonial') }}" data-bs-toggle="tooltip" title="{{ __('Edit') }}">
                                                                    <i class="ti ti-pencil"></i>
                                                                </button>
                                                                {!! Form::open(['method' => 'GET', 'route' => ['testimonials_delete', $key], 'class' => 'd-inline']) !!}
                                                                <button type="button" class="btn btn-sm btn-danger btn-badge show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
                                                                data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{ __('Delete') }}">
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