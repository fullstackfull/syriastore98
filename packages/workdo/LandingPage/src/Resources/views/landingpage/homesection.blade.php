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
<script src="{{ asset('assets/js/jquery.repeater.min.js') }}"></script>
<script>
    $(document).ready(function() {
        $('#imageUploadForm').repeater({
            show: function() {
                $(this).slideDown();
            },
            hide: function(deleteElement) {
                if (confirm('Are you sure you want to delete this element?')) {
                    $(this).slideUp(deleteElement);
                }
            },
        });
    });

    function updateImagePreview(inputElement) {
        var imageElement = inputElement.parentElement.parentElement.querySelector('img');
        if (inputElement.files.length > 0) {
            imageElement.src = window.URL.createObjectURL(inputElement.files[0]);
        } else {
            imageElement.src = '{{ $logo . '/placeholder.png' }}';
        }
    }
    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('click', function(event) {
            if (event.target && event.target.classList.contains('delete-repeater-item')) {
                event.preventDefault();
                var repeaterItem = event.target.closest('[data-repeater-item]');
                if (repeaterItem) {
                    repeaterItem.remove();
                }
            }
        });
    });
</script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButtons = document.querySelectorAll('.delete-button');
        const imageContainer = document.getElementById('imageContainer');
        const imageNamesInput = document.getElementById('imageNames');
        let deletedImageNames = [];

        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const imageToDelete = button.getAttribute('data-image');
                button.closest('.card').remove();
                const currentImageNames = imageNamesInput.value.split(',');
                const updatedImageNames = currentImageNames.filter(name => name !==
                    imageToDelete);
                imageNamesInput.value = updatedImageNames.join(',');
                deletedImageNames.push(imageToDelete);
            });
        });
    });
</script>
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
                    <div class="card">
                        <div class="card-header">
                            <div class="row">
                                <div class="col-lg-10 col-md-10 col-sm-10">
                                    <h5>{{ __('Home Section') }}</h5>
                                </div>
                            </div>
                        </div>

                        {{ Form::open(array('route' => 'homesection.store', 'method'=>'post', 'enctype' => "multipart/form-data",'id' => 'imageUploadForm')) }}
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_offer_text', __('Offer Text'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_offer_text', $settings['home_offer_text'], ['class' => 'form-control', 'placeholder' => __('70% Special Offer'), 'id' => 'home_offer_text']) }}
                                            @error('mail_driver')
                                                <span class="invalid-mail_driver" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_title', __('Title'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_title',$settings['home_title'], ['class' => 'form-control ', 'placeholder' => __('Enter Title'), 'id' => 'home_title']) }}
                                            @error('mail_host')
                                            <span class="invalid-mail_driver" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_heading', __('Heading'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_heading',$settings['home_heading'], ['class' => 'form-control ', 'placeholder' => __('Enter Heading'), 'id' => 'home_heading']) }}
                                            @error('mail_host')
                                                <span class="invalid-mail_driver" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_trusted_by', __('Trusted by'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_trusted_by', $settings['home_trusted_by'], ['class' => 'form-control', 'placeholder' => __('1,000+ customers'), 'id' => 'home_trusted_by']) }}
                                            @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-12">
                                        <div class="form-group">
                                            {{ Form::label('home_description', __('Description'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_description', $settings['home_description'], ['class' => 'form-control', 'placeholder' => __('Enter Description'), 'id' => 'home_description']) }}
                                            @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_live_demo_link', __('Live Demo Link'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_live_demo_link', $settings['home_live_demo_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'home_live_demo_link']) }}
                                            @error('mail_port')
                                                <span class="invalid-mail_port" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_buy_now_link', __('Buy Now Link'), ['class' => 'form-label']) }}
                                            {{ Form::text('home_buy_now_link', $settings['home_buy_now_link'], ['class' => 'form-control', 'placeholder' => __('Enter Link'), 'id' => 'home_buy_now_link']) }}
                                            @error('mail_port')
                                            <span class="invalid-mail_port" role="alert">
                                                    <strong class="text-danger">{{ $message }}</strong>
                                                </span>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {{ Form::label('home_banner', __('Banner'), ['class' => 'form-label']) }}
                                            <div class="mt-4">
                                                <img id="image" src="{{ check_file($settings['home_banner']) ? get_file($settings['home_banner']) : $logo.'/'. $settings['home_banner'] }}"
                                                    class="big-logo" style="width: 100% !important; max-width: 75% !important;">
                                            </div>
                                            <div class="choose-files mt-5">
                                                <label for="home_banner">
                                                    <div class="bg-primary btn-badge company_logo_update" style="cursor: pointer;">
                                                        <i class="ti ti-upload px-1"></i>{{ __('Choose File Here') }}
                                                    </div>
                                                    <input type="file" name="home_banner" id="home_banner" class="form-control file" data-filename="home_banner">
                                                </label>
                                            </div>
                                            @error('home_banner')
                                                <div class="row">
                                                    <span class="invalid-logo" role="alert">
                                                        <strong class="text-danger">{{ $message }}</strong>
                                                    </span>
                                                </div>
                                            @enderror
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="row">
                                            <div class="col-7 form-group text-left">
                                                {{ Form::label('home_logo', __('Site Logo'), ['class' => 'form-label m-1']) }}
                                            </div>
                                            <div class="col-5 text-end">
                                                <button class="btn btn-sm btn-primary btn-badge btn-icon m-1 " data-repeater-create type="button" data-bs-toggle="tooltip" title="{{__('Add Site Logo')}}"><i class="ti ti-plus"></i></button>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-12">
                                                <div data-repeater-list="home_logo">
                                                    <div data-repeater-item class="text-end">
                                                        <div class="card mb-3 border shadow-none product_Image" >
                                                            <div class="px-2 py-2">
                                                                <div class="row align-items-center">
                                                                    <div class="col">
                                                                        <input type="file" class="form-control" id="home_logo" name="home_logo" accept="image/*" onchange="updateImagePreview(this)">
                                                                    </div>
                                                                    <div style="width: 20%; margin-left: 0px;">
                                                                        <p class="card-text small text-muted w-100">
                                                                            <img class="rounded" src="{{ $logo.'/placeholder.png' }}" alt="Image placeholder" data-dz-thumbnail="">
                                                                        </p>
                                                                    </div>
                                                                    <div class="col-auto actions">
                                                                        <a data-repeater-delete href="javascript:void(0)" class="btn btn-sm btn-icon btn-danger repeater-action-btn" data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                                                            <i class="ti ti-trash"></i>
                                                                        </a>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                @if($settings['home_logo'] !="")
                                                <div id="imageContainer">
                                                    @foreach (explode(',', $settings['home_logo']) as $k => $home_logo)
                                                        <div class="card mb-3 border shadow-none product_Image">
                                                            <div class="px-2 py-2">
                                                                <div class="row align-items-center">
                                                                    <div class="col-10">
                                                                        <p class="card-text small text-muted">
                                                                            <img class="rounded w-75" src="{{ check_file($home_logo) ? get_file($home_logo) : asset('storage/uploads/landing_page_image/'.$home_logo) }}" width="70px" alt="Image placeholder" data-dz-thumbnail="">
                                                                        </p>
                                                                    </div>
                                                                    <div class="col-2">
                                                                        <span class="d-flex gap-1 justify-content-end">
                                                                            <a class="action-item btn btn-badge btn-sm btn-icon btn-info" href="javascript::void(0)" download="" data-toggle="tooltip" data-original-title="{{ __('Download') }}" data-bs-toggle="tooltip" title="{{__('Download')}}">
                                                                                <i class="ti ti-download"></i>
                                                                            </a>
                                                                            <a href="javascript::void(0)" class="btn-badge btn btn-sm btn-danger delete-button" data-image="{{ $home_logo }}" data-bs-toggle="tooltip" title="{{__('Delete')}}">
                                                                                <i class="ti ti-trash"></i>
                                                                            </a>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                                @endif
                                            </div>
                                        <input type="hidden" class="form-control" id="imageNames" name="savedlogo" value="{{ $settings['home_logo'] }}">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-end">
                                <input class="btn btn-print-invoice btn-primary mr-2 btn-badge" type="submit" value="{{ __('Save Changes') }}">
                            </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection