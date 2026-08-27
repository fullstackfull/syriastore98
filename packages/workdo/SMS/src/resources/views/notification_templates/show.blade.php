@extends('layouts.app')

@section('page-title')
{{ __('Notification Templates') }}
@endsection

@section('breadcrumb')
<li class="breadcrumb-item" aria-current="page">{{ __('Notification Templates') }}</li>
@endsection
@section('content')
<div class="row">
        <div class="col-md-12 col-12">
            <div class="card">
                <div class="card-header card-body">
                    <h5></h5>
                    <div class="row text-xs row-gap">

                        <h6 class="font-weight-bold mb-1">{{ __('Variables') }}</h6>
                        @php
                            $variables = json_decode($currTempLang->variables);
                        @endphp
                        @if (!empty($variables) > 0)
                            @foreach ($variables as $key => $var)
                                <div class="col-md-6 col-12">
                                    <p class="mb-0">{{ __($key) }} :
                                    <span
                                    class="pull-right text-primary">{{ '{' . $var . '}' }}</span> </p>
                                   
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12">
            <h5></h5>
            <div class="row">
                <div class="col-sm-3 col-md-3 col-lg-3 col-xl-3 ">
                    <div class="card sticky-top language-sidebar">
                        <div class="list-group list-group-flush" id="useradd-sidenav">
                            @foreach ($languages as $key => $lang)
                                <a class="list-group-item list-group-item-action  {{ $currTempLang->lang == $key ? 'active' : '' }}"
                                    href="{{ route('manage.notification.language', [$notification->id, $key]) }}">
                                    {{ Str::ucfirst($lang) }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div class="col-lg-9 col-md-9 col-sm-9">
                    <div class="card p-3">

                        {{ Form::model($currTempLang, ['route' => ['store.notification.language', $currTempLang->parent_id], 'method' => 'POST']) }}
                        <div class="row">
                            <div class="form-group col-12">
                                {{ Form::label('name', __('Name'), ['class' => 'col-form-label pt-0 text-dark']) }}
                                {{ Form::text('name', $notification->action, ['class' => 'form-control font-style', 'disabled' => 'disabled']) }}
                            </div>
                            <div class="form-group col-12">
                                {{ Form::label('content', __('Notification Message'), ['class' => 'col-form-label pt-0 text-dark']) }}
                                {{ Form::textarea('content', $currTempLang->content, ['class' => 'form-control font-style', 'required' => 'required']) }}
                            </div>
                            <div class="col-md-12 text-end mb-3">
                                {{ Form::hidden('lang', null) }}
                                {{ Form::hidden('module', $notification->module) }}
                                {{ Form::hidden('variables', $currTempLang->variables) }}
                                <input type="submit" value="{{ __('Save') }}"
                                    class="btn btn-print-invoice btn-badge btn-primary m-r-10">
                            </div>
                        </div>
                        {{ Form::close() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
