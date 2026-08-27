@extends('layouts.app')

@section('page-title', __('Theme Customize'))

@section('breadcrumb')
<li class="breadcrumb-item"><a href="{{ route('theme.index') }}">{{ __('Themes') }}</a></li>
<li class="breadcrumb-item"><a href="{{ route('theme.pages', $theme) }}">{{ __('Pages') }}</a></li>
<li class="breadcrumb-item">{{ __('Customize') }}</li>
@endsection

@push('css')
<style>
    /* Icon picker improvements */
    .icon-dropdown {
        border-radius: 4px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        z-index: 1050;
    }
    
    .icon-item {
        cursor: pointer;
        padding: 10px;
        border-radius: 4px;
    }
    
    .icon-item:hover {
        background-color: #f8f9fa;
    }
    
    /* Image upload styling */
    .choose-files img {
        border-radius: 4px;
        border: 1px solid #e9ecef;
        padding: 3px;
    }
    
    /* Repeater items */
    [data-repeater-item] {
        padding: 15px;
        margin-bottom: 10px;
        border: 1px solid #e9ecef;
        border-radius: 4px;
    }
</style>
@endpush

@section('content')
<div class="card">
    <div class="card-header p-3">
        <h2 class="h3">{{ ucfirst($theme) }}</h2>
        <span>{{ __('Organize and adjust all settings about') }} {{ $theme }}.</span>
    </div>
    <div class="card-body p-3">
        <div class="row row-gap-2">
            <div class="col-xxl-2 col-sm-6 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Jump To Page') }}</h4>
                    </div>
                    <div class="card-body setting-tab p-3">
                        <ul class="nav nav-pills flex-column gap-1">
                            @foreach ($page_json as $page)
                            <li class="nav-item"><a href="{{ route('theme.customize', [$theme, $page['slug']]) }}"
                                    class="nav-link {{ $page['slug'] == $slug ? 'active' : '' }}">{{ $page['title'] }}</a>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-xxl-2 col-sm-6 col-12">
                <div class="card mb-0">
                    <div class="card-header p-3">
                        <h4 class="mb-0">{{ __('Jump To Settings') }}</h4>
                    </div>
                    <div class="card-body setting-tab p-3">
                        <ul class="nav nav-pills flex-column gap-1">
                            @if (isset($theme_json['slug']) && $slug == $theme_json['slug'])
                            @foreach ($theme_json['sections'] as $json_setting)
                            <li class="nav-item"><a
                                    href="{{ route('theme.customize', [$theme, $theme_json['slug'], $json_setting['slug']]) }}"
                                    class="nav-link {{ $json_setting['slug'] == $sub_slug ? 'active' : '' }}">{{ $json_setting['title'] }}</a>
                            </li>
                            @endforeach
                            @endif
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-xxl-8 col-12">
                @if (isset($theme_json['slug']) && $theme_json['slug'] == $slug)
                {!! Form::open(['route' => ['theme.customize.update', $theme], 'enctype' => 'multipart/form-data', 'id'
                => 'setting-form']) !!}
                @csrf
                <div class="card mb-0" id="settings-card">
                    <div class="card-header p-3 d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">{{ $theme_json['title'] }} {{ _('Settings') }}</h4>
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                    </div>
                    <div class="card-body p-3">
                        <p class="text-muted mb-4">{{ $theme_json['detail'] }}</p>
                        
                        <div class="settings-container">
                            @foreach ($theme_json['sections'] as $json_setting)
                            @if ($json_setting['slug'] == $sub_slug)
                            @foreach ($json_setting['settings'] as $key => $fields)
                            <div class="setting-item mb-4">
                                @switch($fields['type'])
                                @case('switch')
                                <div class="d-flex flex-column">
                                    <div class="form-group mb-2">
                                        <div class="section-title mb-2">
                                            <h4 class="h5">{{ $json_setting['title'] }}</h4>
                                        </div>
                                        <div class="form-group-wrp d-flex gap-3">
                                            <div class="form-check radio-btn p-0 m-0 d-flex align-items-center gap-2">
                                                <input class="m-0" type="radio"
                                                    id="{{ $json_setting['key'] . '_' . $fields['key'] }}"
                                                    name="{{ $json_setting['key'] . '_' . $fields['key'] }}"
                                                    class="custom-control-input" value="1"
                                                    @if(isset($settings[$json_setting['key'] . '_' . $fields['key']]) &&
                                                    $settings[$json_setting['key'] . '_' . $fields['key']]=='1' )
                                                    {{ 'checked' }} @elseif ($fields['value']=='1' ) {{ 'checked' }} @endif>
                                                <label for="{{ $json_setting['key'] . '_' . $fields['key'] }}">{{ __('On') }}
                                                </label>
                                            </div>
                                            <div class="form-check radio-btn p-0 m-0 d-flex align-items-center gap-2">
                                                <input class="m-0" type="radio"
                                                    id="{{ $json_setting['key'] . '_' . $fields['key'] }}2"
                                                    name="{{ $json_setting['key'] . '_' . $fields['key'] }}"
                                                    class="custom-control-input" value="0"
                                                    {{ isset($settings[$json_setting['key'] . '_' . $fields['key']]) && $settings[$json_setting['key'] . '_' . $fields['key']] == '0' ? 'checked' : '' }}>
                                                <label for="{{ $json_setting['key'] . '_' . $fields['key'] }}2">{{ __('Off') }}
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @break

                            @case('textarea')
                            <div class="form-group mb-2">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                'class' => 'form-label',
                                ]) !!}
                                {!! Form::textarea( $json_setting['key'] . '_' . $fields['key'],
                                isset($settings[$json_setting['key'] . '_' . $fields['key']]) ?
                                $settings[$json_setting['key'] . '_' . $fields['key']] : $fields['value'], [ 'id' =>
                                $json_setting['key'] . '_' . $fields['key'], 'class' => 'form-control summernote-simple
                                h-auto',
                                'rows' => $fields['rows'] ? $fields['rows'] : 5, 'placeholder' =>
                                $fields['placeholder'], ], ) !!}
                            </div>
                            @break

                            @case('text')
                            <div class="form-group mb-2">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                'class' => 'form-label',
                                ]) !!}
                                {!! Form::text(
                                $json_setting['key'] . '_' . $fields['key'],
                                isset($settings[$json_setting['key'] . '_' . $fields['key']])
                                ? $settings[$json_setting['key'] . '_' . $fields['key']]
                                : $fields['value'],
                                [
                                'id' => $json_setting['key'] . '_' . $fields['key'],
                                'class' => 'form-control',
                                'placeholder' => $fields['placeholder'] ?? __('Enetr here...'),
                                ],
                                ) !!}
                            </div>
                            @break

                            @case('image')
                            <div class="form-group">
                                <label class="form-label mb-2">{{ $fields['label'] }}</label>
                                <div class="choose-files mt-2">
                                    <img src="{{ isset($settings[$json_setting['key'] . '_' . $fields['key']])
                                    ? get_file($settings[$json_setting['key'] . '_' . $fields['key']])
                                    : get_file($fields['value']) }}" width="100" height="100" class="me-2">
                                    <label for="{{ $json_setting['key'] . '_' . $fields['key'] }}">
                                        <div class="bg-primary">
                                            <i class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                        </div>
                                        <input type="file" class="form-control file"
                                            name="{{ $json_setting['key'] }}_{{ $fields['key'] }}"
                                            id="{{ $json_setting['key'] . '_' . $fields['key'] }}"
                                            data-filename="{{ $settings[$json_setting['key'] . '_' . $fields['key']] ?? '' }}">
                                    </label>
                                </div>
                                <input type="hidden" name="slug" value="{{ $json_setting['key'] }}">
                            </div>
                            @break

                            @case('menu')
                            @php
                            $menus = getWebNavMenu();
                            @endphp
                            <div class="form-group mb-0">
                                <label for="{{ $json_setting['key'] . '_' . $fields['key'] }}"
                                    class="form-label">{{ $fields['label'] }}</label>
                                <select id="{{ $json_setting['key'] . '_' . $fields['key'] }}" class="form-control"
                                    name="{{ $json_setting['key'] . '_' . $fields['key'] }}">
                                    @foreach ($menus as $menu)
                                    <option value="{{ $menu->id }}" @if (isset($settings[$json_setting['key'] . '_' .
                                        $fields['key']]) && $settings[$json_setting['key'] . '_' .
                                        $fields['key']]==$menu->id) {{ 'selected' }} @endif>
                                        {{ $menu->name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            @break

                            @case('icon')
                            <div class="form-group mb-0">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                'class' => 'form-label',
                                ]) !!}
                                <div class="form-group">
                                    <div class="mb-2 input-group">
                                        <div class="input-group-prepend">
                                            <div class="input-group-text iconpicker-component">
                                                <i
                                                    class="@if (isset($settings[$json_setting['key'] . '_' . $fields['key']]))  {{ $settings[$json_setting['key'] . '_' . $fields['key']] }} @else {{ $fields['class'] }} @endif"></i>
                                            </div>
                                            {!! Form::button(
                                            Form::hidden(
                                            $json_setting['key'] . '_' . $fields['key'],
                                            isset($settings[$json_setting['key'] . '_' . $fields['key']])
                                            ? $settings[$json_setting['key'] . '_' . $fields['key']]
                                            : $fields['class'],
                                            ['id' => ''],
                                            ),
                                            [
                                            'class' => 'icp icp-dd btn bg-whight btn-outline-light text-dark
                                            dropdown-toggle',
                                            'data-placement' => 'bottomLeft',
                                            'data-selected' => $fields['class'],
                                            'data-bs-toggle' => 'dropdown',

                                            ],
                                            ) !!}
                                            <div class="dropdown-menu"></div>
                                        </div>
                                        {!! Form::text(
                                        $json_setting['key'] . '_' . $fields['key'] . '_title',
                                        isset($settings[$json_setting['key'] . '_' . $fields['key'] . '_title'])
                                        ? $settings[$json_setting['key'] . '_' . $fields['key'] . '_title']
                                        : $fields['value'],
                                        [
                                        'id' => $json_setting['key'] . '_' . $fields['key'],
                                        'class' => 'form-control',
                                        'placeholder' => $fields['placeholder'] ?? __('Enetr here...'),
                                        ],
                                        ) !!}
                                    </div>
                                </div>
                                @if (isset($fields['text']))
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'] . '_text',
                                $fields['label'] . ' ' . __('Text'), [
                                'class' => 'form-label',
                                ]) !!}
                                {!! Form::text(
                                $json_setting['key'] . '_' . $fields['key'] . '_text',
                                isset($settings[$json_setting['key'] . '_' . $fields['key'] . '_text'])
                                ? $settings[$json_setting['key'] . '_' . $fields['key'] . '_text']
                                : $fields['text'],
                                ['id' => '', 'class' => 'form-control'],
                                ) !!}
                                @endif
                            </div>
                            @break

                            @case('brand_carousel')
                            <div class="repeater1">
                                <div data-repeater-list="{{ $json_setting['key'] . '_' . $fields['key'] }}">
                                    <div data-repeater-item>
                                        <div class="row align-items-center">
                                            <div class="col-10">
                                                <div class="form-group">
                                                    <div class="choose-files mt-3">
                                                        <label for="image">
                                                            <div class="bg-primary ">
                                                                <i
                                                                    class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                            </div>
                                                            <input type="file" class="form-control file" name="image"
                                                                id="image" data-filename="image">
                                                        </label>
                                                        <img src="" width="100" height="100">

                                                        <input type="hidden" name="image" class="selected-files">
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-center col-2">
                                                <div class="action-btn">
                                                    <a href="#" class="btn btn-sm bg-danger" data-repeater-delete>
                                                        <i class="text-white ti ti-trash"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <p id="repeaters-data" data-json='{!! isset($settings[$json_setting['key'] . '_' .
                                        $fields['key']]) ? $settings[$json_setting['key'] . '_' . $fields['key']] : ""
                                        !!}' class="d-none">

                                    </p>
                                    <button type="button" class="btn btn-outline-primary align-items-center"
                                        data-repeater-create>
                                        <i class="ti ti-plus me-1"></i>
                                        <span>{{ __('Add More') }}</span>
                                    </button>
                                </div>
                            </div>
                            @break

                            @case('slider')
                            @php
                                if(in_array('menu', $fields['fields'])) {
                                    $menus = getWebNavMenu();
                                }
                            @endphp

                            <div class="repeater-slider">
                                <div data-repeater-list="{{ $json_setting['key'] . '_' . $fields['key'] }}">
                                    <div data-repeater-item>
                                        <div class="d-flex gap-3 flex-wrap flex-sm-nowrap justify-content-between align-items-center">
                                            @if(in_array('image', $fields['fields']))
                                            <div class="form-group mb-0 w-100">
                                                <div class="choose-files mt-3">
                                                    <label for="image">
                                                        <div class="bg-primary ">
                                                            <i class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" class="form-control file" name="image"
                                                            id="image" data-filename="image">
                                                    </label>
                                                    <img src="" width="100" height="100">
                                                    <input type="hidden" name="image" class="selected-files">
                                                </div>
                                            </div>
                                            @endif
                                            @if(in_array('background_image', $fields['fields']))
                                            <div class="form-group mb-0 w-100">
                                                <div class="choose-files mt-3">
                                                    <label for="background_image">
                                                        <div class="bg-primary ">
                                                            <i class="ti ti-upload px-1"></i>{{ __('Choose file here') }}
                                                        </div>
                                                        <input type="file" class="form-control file" name="background_image"
                                                            id="background_image" data-filename="background_image">
                                                    </label>
                                                    <img src="" width="100" height="100">
                                                    <input type="hidden" name="background_image" class="selected-files">
                                                </div>
                                            </div>
                                            @endif
                                            @if(in_array('title', $fields['fields']) || in_array('small_text',
                                            $fields['fields']) || in_array('big_text', $fields['fields'])
                                            || in_array('content', $fields['fields']) || in_array('button_text',
                                            $fields['fields']) || in_array('button_link', $fields['fields'])
                                            || in_array('menu', $fields['fields']) || in_array('link', $fields['fields']))
                                            <div class="form-group-wrp d-flex flex-column gap-3">
                                                @if(in_array('title', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-1', __('Title'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::text('title', null, [
                                                    'id' => 'form-repeater-1-1',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('small_text', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-1', __('Small Text'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::text('small_text', null, [
                                                    'id' => 'form-repeater-1-1',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('big_text', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-2', __('Big Text'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::text('big_text', null, [
                                                    'id' => 'form-repeater-1-2',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('content', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-6', __('Content'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::textarea('content', null, [
                                                    'id' => 'form-repeater-1-2',
                                                    'class' => 'form-control',
                                                    'rows' => '3',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('button_text', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-3', __('Button text'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::text('button_text', null, [
                                                    'id' => 'form-repeater-1-3',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('button_link', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-4', __('Button Link'), ['class' => 'form-label']) !!}
                                                    <div class="link-type-container" id="staticLinkContainer">
                                                        <select name="button_link" class="form-control" id="form-repeater-1-4">
                                                            @foreach(getPredefinedLinks() as $link)
                                                                <option value="{{ $link['url'] }}">{{ $link['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                </div>
                                                @endif
                                                @if(in_array('menu', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    <label for="form-repeater-1-5"
                                                        class="form-label">{{ __('Menu') }}</label>
                                                    <select id="form-repeater-1-5" class="form-control" name="menu">
                                                        @foreach ($menus as $menu)
                                                        <option value="{{ $menu->id }}">
                                                            {{ $menu->name }}
                                                        </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                @endif
                                                @if(in_array('link', $fields['fields']))
                                                <div class="form-group mb-0">
                                                    {!! Form::label('form-repeater-1-6', __('Link'),
                                                    ['class' => 'form-label']) !!}
                                                    {!! Form::text('link', null, [
                                                    'id' => 'form-repeater-1-6',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('Enter here...'),
                                                    ]) !!}
                                                </div>
                                                @endif
                                                @if(in_array('icon', $fields['fields']))
                                                <div class="form-group mb-0 position-relative">
                                                    <label class="form-label icon-field-label">{{ __('Icon') }}</label>
                                                    <div class="input-group">
                                                        {!! Form::text('icon', null, [
                                                        'class' => 'form-control icon-input',
                                                        'placeholder' => __('Choose icon...'),
                                                        ]) !!}
                                                        <span class="input-group-text icon-picker-button" style="cursor: pointer;">
                                                            <i class="fas fa-icons"></i>
                                                        </span>
                                                    </div>
                                                    <div class="icon-preview mt-2" style="font-size: 24px;">
                                                        <i class=""></i>
                                                    </div>
                                                </div>
                                                @endif
                                                
                                                @if(in_array('summernote', $fields['fields']))
                                                    <div class="form-group mb-0">
                                                        {!! Form::label('form-repeater-1-7', __('Content'),
                                                        ['class' => 'form-label']) !!}
                                                        {{-- {!! Form::text('summernote', null, [
                                                        'id' => 'form-repeater-1-7',
                                                        'class' => 'form-control summernote-simple',
                                                        'placeholder' => __('Enter here...'),
                                                        ]) !!} --}}
                                                        {!! Form::textarea('summernote', null, [
                                                            'class' => 'form-control summernote-simple',
                                                            'placeholder' => __('Enter here...'),
                                                        ]) !!}
                                                    </div>
                                                @endif
                                            </div>
                                            @endif
                                            <div class="action-btn">
                                                <button type="button" class="btn btn-sm bg-danger" data-repeater-delete>
                                                    <i class="text-white ti ti-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-0">
                                <p id="repeaters-data"
                                        data-json='{!! $settings[$json_setting["key"] . "_" . $fields["key"]] ?? "" !!}'
                                        class="d-none">
                                        </p>
                                <button type="button" class="btn btn-outline-primary align-items-center" data-repeater-create>
                                        <i class="ti ti-plus me-1"></i>
                                        <span>{{ __('Add More') }}</span>
                                    </button>
                                </div>
                            </div>
                            @break

                            @case('select_category')
                            <div class="form-group mb-2">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                'class' => 'form-label',
                                ]) !!}
                                @php $is_multiple = ($fields['multiple']) ? '[]' : ''; @endphp
                                {!! Form::select(
                                $json_setting['key'] . '_' . $fields['key'] . $is_multiple,
                                $categories,
                                isset($settings[$json_setting['key'] . '_' . $fields['key']])
                                ? explode(',', $settings[$json_setting['key'] . '_' . $fields['key']])
                                : [],
                                [
                                'class' => 'form-control select2',
                                'id' => $json_setting['key'] . '_' . $fields['key'],
                                'multiple' => $fields['multiple'],
                                'data-placeholder' => __('Select an option'),
                                ],
                                ) !!}
                                @if ($fields['multiple'])
                                <small
                                    class="form-text text-muted">{{ __('Leave blank for show all active categories.') }}</small>
                                @endif
                            </div>
                            @break

                            @case('meta_keywords')
                            <div class="form-group mb-0">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                'class' => 'form-label',
                                ]) !!}
                                @php $is_multiple = ($fields['multiple']) ? '[]' : ''; @endphp
                                {!! Form::select(
                                $json_setting['key'] . '_' . $fields['key'] . $is_multiple,
                                $map_area_meta_keywords,
                                array_keys($map_area_meta_keywords),
                                [
                                'class' => 'form-control select2',
                                'data-tags' => 'true',
                                'id' => $json_setting['key'] . '_' . $fields['key'],
                                'multiple' => $fields['multiple'],
                                'data-placeholder' => __('Select an option'),
                                ],
                                ) !!}
                            </div>
                            @break
                            @case('date')
                            <div class="form-group mb-0">
                                {!! Form::label($json_setting['key'] . '_' . $fields['key'], $fields['label'], [
                                    'class' => 'form-label',
                                ]) !!}

                                {!! Form::date(
                                    $json_setting['key'] . '_' . $fields['key'],
                                    isset($settings[$json_setting['key'] . '_' . $fields['key']])
                                        ? \Carbon\Carbon::createFromTimestampMs($settings[$json_setting['key'] . '_' . $fields['key']])
                                            ->format(
                                                \App\Models\Utility::dateFormat('date_format')
                                            )  
                                        : null,
                                    ['class' => 'form-control date-input', 'placeholder' => 'Select Date']
                                ) !!}
                            </div>
                            @break

                            @case('announce_title')
                            <div class="repeater">
                                <div data-repeater-list="{{ $json_setting['key'] . '_' . $fields['key'] }}">
                                    <div data-repeater-item>
                                        <div class="row align-items-center">
                                            <div class="col-10">

                                                <div class="row">
                                                    <div class="col-6">
                                                        <div class="form-group">
                                                            {!! Form::label('form-repeater-1-1', 'Title', ['class'
                                                            => 'form-label']) !!}
                                                            {!! Form::text('title', null, [
                                                            'id' => 'form-repeater-1-1',
                                                            'class' => 'form-control',
                                                            'placeholder' => __('Enter Text'),
                                                            ]) !!}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="text-center col-2">
                                                <div class="action-btn me-2">
                                                    <button type="button" class="btn btn-sm bg-danger"
                                                        data-repeater-delete>
                                                        <i class="text-white ti ti-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <hr>
                                    </div>
                                </div>
                                <div class="mb-0">
                                    <p id="repeater-data" data-json='{!! isset($settings[$json_setting['key'] . '_' .
                                        $fields['key']]) ? $settings[$json_setting['key'] . '_' . $fields['key']] : ""
                                        !!}' class="d-none"> {!! isset($settings['announce_bar_repeater']) ?
                                        $settings['announce_bar_repeater'] : '' !!} </p>
                                    <button type="button" class="btn btn-outline-primary align-items-center"
                                        data-repeater-create>
                                        <i class="ti ti-plus me-1"></i>
                                        <span>{{ __('Add More') }}</span>
                                    </button>
                                </div>
                            </div>
                            @break

                            @default
                            @endswitch
                            @endforeach
                            @endif
                            @endforeach
                        </div>
                    </div>
                    <div class="card-footer p-3 d-flex justify-content-between">
                        <a href="{{ route('theme.pages', $theme) }}" class="btn btn-light">{{ __('Back') }}</a>
                        {!! Form::button(__('Save'), ['type' => 'submit', 'class' => 'btn btn-primary']) !!}
                    </div>
                </div>
                {!! Form::close() !!}
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
    @include('theme_customize.customize-script')
@endpush