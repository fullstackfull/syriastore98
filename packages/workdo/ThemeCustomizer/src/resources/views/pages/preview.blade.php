@extends('layouts.app')

@section('page-title', __('Theme Customize'))

@section('breadcrumb')
<li class="breadcrumb-item"><a href="{{ route('theme.index') }}">{{ __('Themes') }}</a></li>
 <li class="breadcrumb-item"><a href="{{ route('theme.pages', $theme) }}">{{ __('Pages') }}</a></li>
 <li class="breadcrumb-item">{{ __('Change Style') }}</li>
@endsection


@section('content')
<div class="card customize-card">
    <div class="card-header p-3 d-flex justify-content-between align-items-center">
        <div>
            <h2 class="h3">{{ $theme }}</h2>
            <span>{{ __('Organize and adjust all style settings about') }} {{ $theme }}.</span>
        </div>
      
    </div>
    <div class="row pb-xl-3 pe-xl-3">
        {{ Form::open(['route' => ['themecustomizer.settings'], 'method' => 'POST', 'enctype' => 'multipart/form-data']) }}
        <input type="hidden" name="theme_id" value="{{ $theme }}">
        <div class="card-body d-flex justify-content-between p-3">
            <div class="col-xl-3">
                <div class="row row-gap-2">
                    <div class="form-group col-sm-6">
                        {!! Form::label('primary_color', __('Primary Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--primary-color]', $colors['--primary-color'] ? $colors['--primary-color'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'primary_color'
                        ]) !!}
                        @error('class_json[--primary-color]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('primary_dark_color', __('Primary Dark Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--primary-dark]', $colors['--primary-dark'] ? $colors['--primary-dark'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'primary_dark_color'
                        ]) !!}
                        @error('class_json[--primary-dark]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('primary_light_color', __('Primary Light Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--primary-light]', $colors['--primary-light'] ? $colors['--primary-light'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'primary_light_color'
                        ]) !!}
                        @error('class_json[--primary-light]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('accent_color', __('Accent Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--accent-color]', $colors['--accent-color'] ? $colors['--accent-color'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'accent_color'
                        ]) !!}
                        @error('class_json[--accent-color]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('accent_dark_color', __('Accent Dark Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--accent-dark]', $colors['--accent-dark'] ? $colors['--accent-dark'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'accent_dark_color'
                        ]) !!}
                        @error('class_json[--accent-dark]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('accent_light_color', __('Accent Light Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--accent-light]', $colors['--accent-light'] ? $colors['--accent-light'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'accent_light_color'
                        ]) !!}
                        @error('class_json[--accent-light]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>

                    <div class="form-group col-sm-6">
                        {!! Form::label('bg_light', __('Backgroud Light Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--bg-light]', $colors['--bg-light'] ? $colors['--bg-light'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'bg_light'
                        ]) !!}
                        @error('class_json[--bg-light]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                    
                    <div class="form-group col-sm-6">

                        {!! Form::label('border_color', __('Border Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--border-color]', $colors['--border-color'] ? $colors['--border-color'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'border_color'
                        ]) !!}
                        @error('class_json[--border]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                    <div class="form-group col-sm-6">

                        {!! Form::label('blade_color', __('Black Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--black]', $colors['--black'] ? $colors['--black'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'blade_color'
                        ]) !!}
                        @error('class_json[--black]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror

                    </div>
                    <div class="form-group col-sm-6">

                        {!! Form::label('white_color', __('White Color'), ['class' => 'form-label']) !!}
                        {!! Form::color('class_json[--white]', $colors['--white'] ? $colors['--white'] : null, [
                            'class' => 'form-control',
                            'placeholder' => 'Enter Here...',
                            'id' => 'white_color'
                        ]) !!}
                        @error('class_json[--white]')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror

                    </div>
                    <div class="form-group col-md-12">
                        <input type="checkbox" class="form-check-input" name="is_publish"
                            id="is_publish_theme_css"
                            {{ isset($isPublish) && $isPublish == 'on' ? 'checked="checked"' : '' }}>
                        {!! Form::label(__('is_publish_theme_css'), __('Are You Sure Want To Publish ?'), ['class' => 'form-label']) !!}

                        @error('is_publish')
                            <span class="invalid-quick_button_title" role="alert">
                                <strong class="text-danger">{{ $message }}</strong>
                            </span>
                        @enderror
                    </div>
                </div>
            </div>
            <div class="col-xl-9">
                <div class="p-3 h-100">
                    <iframe src="{{ route('theme-preview-content', $theme) }}" loading="lazy" style="width: 100%;  border: 1px solid black; height: 100%;" id="customize-theme"></iframe>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end flex-wrap">
            <input type="submit" value="{{ __('Save Changes') }}"
                class="btn-submit btn bg-success">
        </div>
        {{ Form::close() }}        
    </div>
</div>
@endsection

@push('scripts')
<script>
        $(document).ready(function() {
            // Select the iframe
            var iframe = $('#customize-theme');

            // Function to update the CSS variable in the iframe
            function updateIframeCssVariable(variable, value) {
                var iframeDocument = iframe[0].contentDocument || iframe[0].contentWindow.document;
                // Update the CSS variable in the iframe's root element
                iframeDocument.documentElement.style.setProperty(variable, value);
            }
            // Event listener for theme color changes
            $('input[type="color"]').on('input change', function() {
                // Correcting the variable name format
                var variableName = $(this).attr('name').replace('class_json[', '--').replace(']', '');
                variableName = variableName.replace(/^----/, '--');
                var colorValue = $(this).val();
                console.log('variableName', variableName);
                console.log('colorValue', colorValue);
                // Update the iframe with the new color value
                updateIframeCssVariable(variableName, colorValue);
            });
        });
    </script>
@endpush