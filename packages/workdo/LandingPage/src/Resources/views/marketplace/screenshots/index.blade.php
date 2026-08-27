@extends('layouts.app')

@section('page-title')
    {{ __('Landing Page') }}
@endsection

@section('page-breadcrumb')
    {{__('Landing Page')}}
@endsection

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
                    <div class="row align-items-center">
                        <div class="col">
                            <h5>{{ __('Upload Screenshots') }}</h5>
                        </div>
                        <div id="p1" class="col-auto text-end text-primary h3">
                            <a image-url="{{ get_file('packages/workdo/LandingPage/src/Resources/assets/infoimages/screenshots.png') }}"
                                data-url="{{ route('info.image.view',['marketplace','screenshots']) }}" class="view-images pt-2">
                                <i class="ti ti-info-circle pointer"></i>
                            </a>
                        </div>
                        <div class="col-auto justify-content-end d-flex">
                            <a href="javascript::void(0)" data-size="lg" data-url="{{ route('marketplace_screenshots_create',$slug) }}" data-ajax-popup="true" data-bs-toggle="tooltip" title="{{__('Create')}}" data-title="{{__('Add Screenshot')}}" class="btn btn-sm btn-primary btn-badge">
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
                                @if (is_array($screenshots) || is_object($screenshots))
                                    @php
                                        $no = 1;
                                    @endphp
                                    @foreach ($screenshots as $key => $value)
                                        <tr>
                                            <td>{{ $no++ }}</td>
                                            <td>{{ $value['screenshots_heading'] }}</td>
                                            <td>
                                                <span class="d-flex gap-1 justify-content-end">
                                                    <a href="#" class="btn btn-sm btn-badge btn-info" data-url="{{ route('marketplace_screenshots_edit',[$slug , $key]) }}" data-ajax-popup="true" data-title="{{__('Edit Screenshot')}}" data-size="lg" data-bs-toggle="tooltip"  title="{{__('Edit')}}" data-original-title="{{__('Edit')}}">
                                                        <i class="ti ti-pencil"></i>
                                                    </a>
                                                    {!! Form::open(['method' => 'GET', 'route' => ['marketplace_screenshots_delete', [$slug , $key]],'id'=>'delete-form-'.$key]) !!}
                                                        <a href="#" class="btn btn-sm btn-badge btn-danger show_confirm" data-bs-toggle="tooltip"data-confirm="{{ __('Are You Sure?') }}"
                                                        data-text="{{ __('This action can not be undone. Do you want to continue?') }}" data-text-yes="{{ __('Yes') }}" data-text-no="{{ __('No') }}"  title="{{__('Delete')}}" data-original-title="{{__('Delete')}}" data-confirm-yes="{{'delete-form-'.$key}}">
                                                            <i class="ti ti-trash"></i>
                                                        </a>
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
