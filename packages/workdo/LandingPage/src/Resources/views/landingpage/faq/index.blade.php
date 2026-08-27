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
                            {{ Form::open(array('route' => 'faq.store', 'method'=>'post', 'enctype' => "multipart/form-data")) }}
                            @csrf
                                <div class="card-header">
                                    <div class="row align-items-center">
                                        <div class="col-6">
                                            <h5 class="mb-2">{{ __('FAQ') }}</h5>
                                        </div>
                                        <div class="col switch-width text-end">
                                            <div class="form-group mb-0">
                                                <div class="custom-control custom-switch">
                                                    <input type="checkbox" data-toggle="switchbutton" data-onstyle="primary" class="" name="faq_status"
                                                        id="faq_status"  {{ $settings['faq_status'] == 'on' ? 'checked="checked"' : '' }}>
                                                    <label class="custom-control-label" for="faq_status"></label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <div class="row">

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('faq_title', __('Title'), ['class' => 'form-label']) }}
                                                {{ Form::text('faq_title',$settings['faq_title'], ['class' => 'form-control ', 'placeholder' => __('Enter Title'), 'id' => 'faq_title']) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('faq_heading', __('Heading'), ['class' => 'form-label']) }}
                                                {{ Form::text('faq_heading',$settings['faq_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'faq_heading']) }}
                                                @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                @enderror
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-group">
                                                {{ Form::label('faq_description', __('Description'), ['class' => 'form-label']) }}
                                                {{ Form::text('faq_description', $settings['faq_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'faq_description']) }}
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
                                        <a href="javascript::void(0);" data-size="lg" data-url="{{ route('faq_create') }}" data-ajax-popup="true"  data-bs-toggle="tooltip" title="{{__('Add Faq')}}" data-title="{{__('Create Faq')}}"  class="btn btn-sm btn-primary btn-badge">
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
                                           @if (is_array($faqs) || is_object($faqs))
                                            @php
                                                $no = 1
                                            @endphp
                                                @foreach ($faqs as $key => $value)
                                                    <tr>
                                                        <td>{{ $no++ }}</td>
                                                        <td>{{ $value['faq_questions'] }}</td>
                                                        <td>
                                                            <span class="d-flex gap-1 justify-content-end">
                                                                <button class="btn btn-sm btn-badge btn-info"
                                                                data-url="{{ route('faq_edit',$key) }}"  data-size="lg"
                                                                    data-ajax-popup="true" data-title="{{ __('Edit Faq') }}" data-bs-toggle="tooltip"
                                                                    title="{{ __('Edit') }}">
                                                                    <i class="ti ti-pencil"></i>
                                                                </button>
                                                                {!! Form::open(['method' => 'GET', 'route' => ['faq_delete', $key], 'class' => 'd-inline']) !!}
                                                                <button type="button" class="btn btn-badge btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="{{ __('Are You Sure?') }}"
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



