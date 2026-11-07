@extends('layouts.app')
@section('page-title')
    {{__('Custom Domain')}}
@endsection
@section('title')
    <div class="d-inline-block">
        <h5 class="h4 d-inline-block text-white font-weight-bold mb-0 ">{{__('Domain')}}</h5>
    </div>
@endsection
@section('breadcrumb')
    <li class="ss-breadcrumb__item"><a class="ss-breadcrumb__link" href="{{ route('stores.index') }}">{{ __('Users') }}</a></li>
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Custom Domain') }}</li>
@endsection
@section('action-button')
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        <a href="{{ route('store.subdomain') }}" class="btn btn-sm btn-primary btn-icon m-1" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Sub Domain') }}">{{ __('Sub Domain') }}
        </a>
        <a href="{{ route('stores.index') }}" class="btn btn-sm btn-primary btn-icon m-1" data-bs-toggle="tooltip"
            data-bs-placement="top" title="{{ __('Users') }}">{{ __('Users') }}
        </a>
        <a href="#" class="btn btn-sm btn-primary btn-icon m-1" data-ajax-popup="true" data-size="md" data-title="{{ __('Create New User') }}"
            data-url="{{ route('store.user.create') }}" data-toggle="tooltip" title="{{ __('Create New User') }}">
            <i class="ti ti-plus"></i>
        </a>
    </div>
@endsection
@section('filter')
@endsection
@push('css-page')
@endpush
@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body table-border-style ss-dt-card-body">
                {{--
                    THIS IS THE SCREEN'S ONLY INSTRUCTIONS, AND THEY RENDERED AT 12px.

                    The markup was `<h6 class="text-muted text-sm my-2">` on a ~300-character
                    paragraph. Two things were wrong with it and they compound:

                      * `.text-sm` is `font-size: 0.75rem !important` (style.css:15112) and `.h6` is
                        `font-size: 0.9375rem` (:263). The `!important` wins, so the `.h6` was INERT
                        and the merchant read setup instructions at 12px.
                      * it is a PARAGRAPH wearing a HEADING element, so a screen reader announces a
                        level-6 heading and a sighted reader gets heading semantics for prose.

                    `<x-ss.help>` exists for exactly this -- short, task-oriented guidance beside the
                    thing it is about -- and carries its own readable size, colour and spacing from
                    the design system rather than three competing utility classes.
                --}}
                <x-ss.help class="my-2">
                    {{__('If you\'re using cPanel or Plesk then you need to manually add below custom domain in your server with the same root directory as the script\'s installation. and user need to point their custom domain A record with your server IP '.$serverIp.'')}}
                </x-ss.help>
                <div class="table-responsive">
                    <table class="table mb-0 dataTable">
                        <thead>
                            <tr>
                                <th>{{ __('Custom Domain Name')}}</th>
                                <th>{{ __('Store Name')}}</th>
                                <th>{{ __('Email')}}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stores as $store)
                                <tr>
                                    <td>
                                        {{$store->domains}}
                                    </td>
                                    <td>
                                        {{$store->name}}
                                    </td>
                                    <td>
                                        {{($store->email)}}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
