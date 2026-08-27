@extends('layouts.app')

@section('page-title', __('Newsletters'))


@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Newsletters') }}</li>
@endsection

@section('action-button')
    @php
        $module = \App\Facades\ModuleFacade::find('Subscribe');
        $plan = null;
        if (\Auth::check()) {
            $user = \Auth::user();
            if ($user && $user->plan_id) {
                $plan = \App\Models\Plan::find($user->plan_id);
            }
        }
    @endphp

    @if(isset($module) && $module->isEnabled() && $plan && isset($plan->modules) && strpos($plan->modules, 'Subscribe') !== false)
        <div class="text-end">
            <a class="btn btn-sm btn-primary btn-icon export-btn btn-badge mr-1" href="{{ route('newsletter.export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Newsletter') }}">
                <i class="ti ti-file-export"></i>
            </a>
        </div>
    @endif

@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

