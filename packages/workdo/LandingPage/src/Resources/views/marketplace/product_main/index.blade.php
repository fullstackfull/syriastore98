@php
    $old_path = url('/packages/workdo/' . $slug . '/src/marketplace/');
@endphp
@extends('layouts.app')

@section('page-title')
    {{ __('MarketPlace') }}
@endsection

@section('page-breadcrumb')
    {{ __('MarketPlace') }}
@endsection

@push('scripts')
    <script>
        document.getElementById('product_main_banner').onchange = function() {
            var src = URL.createObjectURL(this.files[0])
            document.getElementById('image').src = src
        }
    </script>
@endpush
@section('content')
    <div class="row">
        <div class="col-sm-12">
            @include('landing-page::marketplace.modules')
            <div class="row">
                <div class="col-xl-3">
                    <div class="card sticky-top" style="top:30px">
                        <div class="list-group list-group-flush" id="useradd-sidenav">
                            @include('landing-page::marketplace.tab')
                        </div>
                    </div>
                </div>
                <div class="col-xl-9">
                    {{--  Start for all settings tab --}}
                    <div class="card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col">
                                    <h5>{{ __('Marketplace Home') }}</h5>
                                </div>
                                <div id="p1" class="col-auto text-end text-primary h3">
                                    <a image-url="{{ get_file('packages/workdo/LandingPage/src/Resources/assets/infoimages/product_main.png') }}"
                                        data-url="{{ route('info.image.view', ['marketplace', 'product_main']) }}"
                                        class="view-images pt-2">
                                        <i class="ti ti-info-circle pointer"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        {{ Form::open(['route' => ['product_main_store', $slug], 'method' => 'post', 'enctype' => 'multipart/form-data']) }}
                        <div class="card-body">
                            @csrf
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="form-group">
                                        {{ Form::label('product_main_heading', __('Heading'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_main_heading', $settings['product_main_heading'] ?? Module_Alias_Name($slug), ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'product_main_heading']) }}
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="form-group">
                                        {{ Form::label('product_main_description', __('Description'), ['class' => 'form-label']) }}
                                        {{ Form::textarea('product_main_description', $settings['product_main_description'], ['class' => 'summernote form-control', 'placeholder' => __('Enter Description'), 'id' => 'product_main_description', 'required' => 'required']) }}
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <div class="form-group">
                                        {{ Form::label('product_main_demo_link', __('Live Demo button Link'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_main_demo_link', $settings['product_main_demo_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'product_main_demo_link']) }}
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        {{ Form::label('product_main_demo_button_text', __('Live Demo Button Text'), ['class' => 'form-label']) }}
                                        {{ Form::text('product_main_demo_button_text', $settings['product_main_demo_button_text'], ['class' => 'form-control', 'placeholder' => __('Enter Button Text'), 'id' => 'product_main_demo_button_text']) }}
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        {{ Form::label('product_main_banner', __('Banner'), ['class' => 'form-label']) }}
                                        <div class="mt-4">
                                            <img id="image" style="width: 100% !important; max-width: 75% !important;"
                                                src="{{ check_file($settings['product_main_banner']) ? get_file($settings['product_main_banner']) : $old_path . '/image1.png' }}"
                                                class="big-logo">
                                        </div>
                                        <div class="choose-files mt-5">
                                            <label for="product_main_banner">
                                                <div class="bg-primary btn-badge" style="cursor: pointer;">
                                                    <i class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                </div>
                                                <input type="file" name="product_main_banner" id="product_main_banner"
                                                    class="form-control choose_file_custom"
                                                    data-filename="product_main_banner">
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer text-end">
                            <input class="btn btn-print-invoice btn-badge btn-primary mr-2" type="submit"
                                value="{{ __('Save Changes') }}">
                        </div>
                        {{ Form::close() }}
                    </div>
                    {{--  End for all settings tab --}}
                </div>
            </div>
        </div>
    </div>
@endsection

@push('css')
    <link href="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.css') }}" rel="stylesheet">
@endpush

@push('scripts')
    <script src="{{ asset('assets/js/plugins/summernote-0.8.18-dist/summernote-lite.min.js') }}"></script>
@endpush