@extends('layouts.app')
@section('page-title', __('Menus'))
@section('breadcrumb')
<li class="breadcrumb-item"><a href="{{ route('menus.index') }}">{{ __('Menus') }}</a></li>
<li class="breadcrumb-item">{{ $menu->name }}</li>
@endsection

@push('css')
<link rel="stylesheet" href="{{ asset('assets/css/jquery.nestable.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/menu.css') }}">
<style>
    .menu-item-bar {
        /* background: #eee; */
        padding: 5px 10px;
        border: 1px solid #d7d7d7;
        margin-bottom: 5px;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 15px !important;
    }

    .menu-item-bar .according-delete-input {
        padding: 0 !important;
    }

    #serialize_output {
        display: none;
    }

    body.dragging,
    body.dragging * {
        cursor: move !important;
    }

    .dragged {
        position: absolute;
        z-index: 1;
    }

    ol.example li.placeholder {
        position: relative;
    }

    ol.example li.placeholder:before {
        position: absolute;
    }

    #menuitem {
        list-style: none;
    }

    ol, li, ul {
        list-style: none;
    }

    .ui-state-highlight {
        background-color: transparent !important;
        /* Remove the background color */
        border: none !important;
        /* Remove the border */
        height: 0 !important;
        /* Optional: Remove height */
    }

    /* Styled collapse section only inside #menuitems */
    #menuitems .collapse {
        background: #ffffff;
        /* White background */
        border: 1px solid #ddd;
        /* Light border */
        border-radius: 8px;
        /* Rounded corners */
        padding: 15px;
        margin-top: 8px;
        /* Add some space between items */
        box-shadow: 0px 2px 4px rgba(0, 0, 0, 0.1);
        /* Soft shadow */
    }

    /* Improve form-group layout only inside #menuitems */
    #menuitems .collapse .form-group {
        background: #f8f9fa;
        /* Light gray background */
        padding: 15px;
        border-radius: 5px;
    }

    /* Checkbox Styling only inside #menuitems */
    #menuitems .collapse .custom-checkbox .custom-control-label {
        font-weight: 600;
        color: #333;
        cursor: pointer;
    }
    #menu-selector{
        width: 500px;
    }

</style>
@endpush

@section('action-button')
@permission('Create Menu')
<div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
    <a href="#" class="btn btn-sm btn-primary" data-ajax-popup="true" data-size="md" data-title="{{__('Add Menus')}}"
        data-url="{{ route('menus.create') }}" data-toggle="tooltip" title="{{ __('Create Menus') }}">
        <i class="ti ti-plus"></i>
    </a>
</div>
@endpermission
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <div class="card">
                <div class="card-body">
                    <div class="container-fluid">
                        <div class="row" id="main-row">
                            <div class="col-xxl-3 col-lg-5 col-md-6 cat-form menu-tab-view" id="accordionExample ">
                                <h5>{{ __('Add Menu Items') }}</h5>
                                <div class="accordion accordion-flush">
                                    <div class="accordion-item card">
                                        <h2 class="accordion-header" id="COD">
                                            <button class="accordion-button according-delete-input" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#categories-list"
                                                aria-expanded="false" aria-controls="categories-list">
                                                <span class="d-flex align-items-center">
                                                    {{ __('Categories') }}
                                                </span>
                                            </button>
                                        </h2>
                                        <div id="categories-list" class="accordion-collapse collapse"
                                            data-bs-parent="#accordionExample">
                                            <div class="accordion-body">
                                                @foreach ($categories as $id => $cat)
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="form-check-input category-item"
                                                            name="category_id" id="categoryCheck{{ $id }}" value="{{ $id }}">
                                                        <label class="form-check-label"
                                                            for="categoryCheck{{ $id }}">{{ $cat }}</label>
                                                    </div>
                                                @endforeach
                                                <div class="cat-btn mt-2 d-flex justify-content-between">
                                                    <div class="custom-control custom-checkbox d-flex">
                                                        <input class="form-check-input" type="checkbox" value="on"
                                                            name="" id="select-all-categories">
                                                        {!! Form::label('select-all-categories', __('Select All'), [
                                                            'class' => 'custom-control-label btn btn-sm btn-outline-info d-flex align-items-center mx-1 flex-1',
                                                        ]) !!}
                                                    </div>
                                                    @permission('Create Menu')
                                                    <button type="button" class="pull-right btn btn-primary btn-sm"
                                                        id="add-categories">{{ __('Add to Menu') }}</button>
                                                    @endpermission
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion accordion-flush" id="">
                                    <div id="" class="accordion-item card ">
                                        <h2 class="accordion-header" id="COD">
                                            <button class="accordion-button according-delete-input" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#pages-list"
                                                aria-controls="pages-list" aria-expanded="false">
                                                <span class="d-flex align-items-center">
                                                    {{ __('Pages') }}
                                                </span>
                                            </button>
                                        </h2>
                                        <div id="pages-list" class="accordion-collapse collapse"
                                            data-bs-parent="#accordionExample">
                                            <div class="accordion-body">
                                                @foreach ($pages as $page_id => $page)
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="form-check-input page-item" id="pageCheck{{ $page_id }}" value="{{ $page_id }}">
                                                        <label class="form-check-label" for="pageCheck{{ $page_id }}">{{ $page }}</label>
                                                    </div>
                                                @endforeach
                                                <div class="mt-2 d-flex justify-content-between">
                                                    <div class="custom-control custom-checkbox d-flex">
                                                        {!! Form::checkbox(null, null, null, ['id' => 'select-all-pages', 'class' => 'form-check-input']) !!}
                                                        {!! Form::label('select-all-pages', __('Select All'), [
                                                            'class' => 'custom-control-label btn btn-sm btn-outline-info d-flex align-items-center mx-1 flex-1',
                                                        ]) !!}
                                                    </div>
                                                    @permission('Create Menu')
                                                    <button type="button" id="add-pages"
                                                        class="pull-right btn btn-primary btn-sm">{{ __('Add to Menu') }}</button>
                                                    @endpermission
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion accordion-flush">
                                    <div class="accordion-item card">
                                        <h2 class="accordion-header" id="COD">
                                            <button class="accordion-button according-delete-input" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#brands-list"
                                                aria-expanded="false" aria-controls="brands-list">
                                                <span class="d-flex align-items-center">
                                                    {{ __('Brand') }}
                                                </span>
                                            </button>
                                        </h2>
                                        <div id="brands-list" class="accordion-collapse collapse"
                                            data-bs-parent="#accordionExample">
                                            <div class="accordion-body">
                                                @foreach ($brands as $brand_id => $brand)
                                                    <div class="custom-control custom-checkbox">
                                                        {!! Form::checkbox('select-brand[]', $brand_id, null, [
                                                            'id' => 'brandCheck' . $brand_id,
                                                            'class' => 'form-check-input brand-item',
                                                        ]) !!}
                                                        {!! Form::label('brandCheck' . $brand_id, $brand, ['class' => 'custom-control-label ms-1']) !!}
                                                    </div>
                                                @endforeach
                                                <div class="brand-btn mt-2 d-flex justify-content-between">
                                                    <div class="custom-control custom-checkbox d-flex">
                                                        <input class="form-check-input" type="checkbox" value="on"
                                                            name="" id="select-all-brands">
                                                        {!! Form::label('select-all-brands', __('Select All'), [
                                                            'class' => 'custom-control-label btn btn-sm btn-outline-info d-flex align-items-center mx-1 flex-1',
                                                        ]) !!}
                                                    </div>
                                                    @permission('Create Menu')
                                                    <button type="button" class="pull-right btn btn-primary btn-sm"
                                                        id="add-brands">{{ __('Add to Menu') }}</button>
                                                    @endpermission
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="accordion accordion-flush" id="">
                                    <div id="" class="accordion-item card ">
                                        <h2 class="accordion-header">
                                            <button class="accordion-button according-delete-input" type="button"
                                                data-bs-toggle="collapse" data-bs-target="#custom-links"
                                                aria-controls="custom-links" aria-expanded="false">
                                                <span class="d-flex align-items-center">
                                                    {{ __('Custom Links ') }}
                                                </span>
                                            </button>
                                        </h2>
                                        <div id="custom-links" class="accordion-collapse collapse show"
                                            data-bs-parent="#accordionExample">
                                            <div class="accordion-body">
                                                <div class="">
                                                    <label for="url" class="mb-2">{{ __('URL') }}</label>
                                                    <input type="url" id="url" class="form-control" placeholder="https://">
                                                </div>
                                                <div class="">
                                                    <label for="linktext" class="mb-2">{{ __('Link Text') }}</label>
                                                    <input type="text" id="linktext" class="form-control" placeholder="e.g.: Home">
                                                </div>
                                            </div>
                                            @permission('Create Menu')
                                            <div class="accordion-body d-flex justify-content-between">
                                                {!! Form::button(__('Add to Menu'), ['id' => 'add-custom-link', 'class' => 'pull-right btn btn-primary btn-sm']) !!}
                                            </div>
                                            @endpermission
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-xxl-9 col-lg-7 col-md-6 cat-view">
                                @if ($menu == '')
                                    <h5>{{ __('Create New Menu') }}</h5>
                                    {!! Form::open(['route' => 'menus.index', 'data-validate', 'novalidate']) !!}
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="form-group">
                                                {!! Form::label('menu_name', __('Name'), ['class' => 'col-form-label']) !!} <span class="validation-required">*</span>
                                                {!! Form::text('name', null, [
                                                    'id' => 'menu_name',
                                                    'class' => 'form-control',
                                                    'placeholder' => __('e.g.: Header'),
                                                ]) !!}
                                                {!! Form::label('pageCheck' . $page_id, $page, ['class' => 'custom-control-label']) !!}
                                            </div>
                                         
                                            <div class="mt-2 d-flex justify-content-between">
                                                <div class="custom-control custom-checkbox d-inline">
                                                    {!! Form::checkbox(null, null, null, ['id' => 'select-all-pages', 'class' => 'form-check-input']) !!}
                                                    {!! Form::label('select-all-pages', __('Select All'), [
                                                    'class' => 'custom-control-label btn btn-sm btn-outline-info',
                                                    ]) !!}
                                                </div>
                                                @permission('Create Menu')
                                                <button type="button" id="add-pages"
                                                    class="pull-right btn btn-primary btn-sm">{{ __('Add to Menu') }}</button>
                                                @endpermission
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion accordion-flush">
                                <div class="accordion-item card">
                                    <h2 class="accordion-header" id="COD">
                                        <button class="accordion-button according-delete-input" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#brands-list"
                                            aria-expanded="false" aria-controls="brands-list">
                                            <span class="d-flex align-items-center">
                                                {{ __('Brand') }}
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="brands-list" class="accordion-collapse collapse"
                                        data-bs-parent="#accordionExample">
                                        <div class="accordion-body">
                                            @foreach ($brands as $brand_id => $brand)
                                            <div class="custom-control custom-checkbox">
                                                {!! Form::checkbox('select-brand[]', $brand_id, null, [
                                                'id' => 'brandCheck' . $brand_id,
                                                'class' => 'form-check-input',
                                                ]) !!}
                                                {!! Form::label('brandCheck' . $brand_id, $brand, ['class' => 'custom-control-label ms-1']) !!}
                                            </div>
                                            @endforeach
                                            <div class="brand-btn mt-2 d-flex justify-content-between">
                                                <div class="custom-control custom-checkbox d-inline">
                                                    <input class="form-check-input" type="checkbox" value="on"
                                                        name="" id="select-all-brands">
                                                    {!! Form::label('select-all-brands', __('Select All'), [
                                                    'class' => 'custom-control-label btn btn-sm btn-outline-info',
                                                    ]) !!}
                                                </div>
                                                @permission('Create Menu')
                                                <button type="button" class="pull-right btn btn-primary btn-sm"
                                                    id="add-brands">{{ __('Add to Menu') }}</button>
                                                @endpermission
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion accordion-flush" id="">
                                <div id="" class="accordion-item card ">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button according-delete-input" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#custom-links"
                                            aria-controls="custom-links" aria-expanded="false">
                                            <span class="d-flex align-items-center">
                                                {{ __('Custom Links ') }}
                                            </span>
                                        </button>
                                    </h2>
                                    <div id="custom-links" class="accordion-collapse collapse show"
                                        data-bs-parent="#accordionExample">
                                        <div class="accordion-body">
                                            <div class="">
                                                {!! Form::label('url', __('URL'), ['class' => 'col-form-label']) !!} <span class="validation-required">*</span>
                                                {!! Form::url(null, null, ['id' => 'url', 'class' => 'form-control', 'placeholder' => 'https://']) !!}
                                            </div>
                                            <div class="">
                                                {!! Form::label('linktext', __('Link Text'), ['class' => 'col-form-label']) !!} <span class="validation-required">*</span>
                                                {!! Form::text(null, null, ['id' => 'linktext', 'class' => 'form-control', 'placeholder' => __('e.g.: Home')]) !!}
                                            </div>
                                        </div>
                                        @permission('Create Menu')
                                        <div class="accordion-body d-flex justify-content-between">
                                            {!! Form::button(__('Add to Menu'), ['id' => 'add-custom-link', 'class' => 'pull-right btn btn-primary btn-sm']) !!}
                                        </div>
                                        @endpermission
                                    </div>
                                </div>
                            </div>
                        </div>
                        @endif

                        <div class="col-sm-9 cat-view">
                            @if ($menu == '')
                            <h5>{{ __('Create New Menu') }}</h5>
                            {!! Form::open(['route' => 'menus.index', 'data-validate', 'novalidate']) !!}
                            <div class="row">
                                <div class="col-6">
                                    <div class="form-group">
                                        {!! Form::label('menu_name', __('Name'), ['class' => 'col-form-label']) !!} <span class="validation-required">*</span>
                                        {!! Form::text('name', null, [
                                        'id' => 'menu_name',
                                        'class' => 'form-control',
                                        'placeholder' => __('e.g.: Header'),
                                        ]) !!}
                                    </div>
                                </div>
                                <div class="text-right col-12">
                                    @permission('Create Menu')
                                    {!! Form::button(__('Save'), ['type' => 'submit', 'class' => 'btn btn-sm btn-primary']) !!}
                                    @endpermission
                                </div>
                            </div>
                            {!! Form::close() !!}
                            @else
                            <h4><span>{{ __('Menu Structure') }}</span></h4>
                            <div class="form-group">
                                {!! Form::label('menu-name', __('Menu Name'), ['class' => 'form-label']) !!}
                                {!! Form::text('name', $menu->name ?? '', ['class' => 'form-control', 'required', 'id'
                                => 'menu-name']) !!}
                            </div>
                            <div id="menu-content">
                                <div class="menu-accordion-wrp">
                                    <p>{{ __('Select categories, pages or add custom links to menus.') }}</p>
                                    @if (count($menuItems) > 0)
                                    <div class="dd" id="nestable">
                                        <ol class="dd-list">                                       
                                            @foreach ($menuItems as $key => $item)
                                                @include('menu.item-nest', ['item' => $item, 'key' => $key])
                                            @endforeach
                                        </ol>
                                    </div>
                                    @endif
                                </div>
                            </div>
                            @endif
                            <div class="d-flex gap-2 justify-content-end">
                                @permission('Delete Menu')
                                {!! Form::open(['method' => 'DELETE', 'route' => ['menus.destroy', $menu->id],'class'=>'mb-0']) !!}
                                <button type="button" class="btn btn-sm btn-danger show_confirm" data-confirm="{{ __('Are You Sure?') }}"
                                    data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}">
                                    {{ __('Delete Menu') }}
                                </button>
                                {!! Form::close() !!}
                                @endpermission
                                <button type="button" id="saveMenu"
                                    class="btn btn-sm btn-primary">{{ __('Save Menu') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection


@push('scripts')
    @include('menu.script')
@endpush