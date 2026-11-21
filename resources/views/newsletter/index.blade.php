@extends('layouts.app')

@section('page-title', __('Newsletters'))


@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">{{ __('Newsletters') }}</li>
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

    {{-- MKT-001. The analytics link sits OUTSIDE the Subscribe module/plan condition below: it
         links to a reports screen and has nothing to do with whether this store has the newsletter
         module or a plan that includes it. One flex wrapper keeps both controls on a single row,
         and the export keeps its own gate exactly as it was. --}}
    <div class="text-end d-flex all-button-box justify-content-md-end justify-content-center">
        @include('marketing._analytics_link')
        @if(isset($module) && $module->isEnabled() && $plan && isset($plan->modules) && strpos($plan->modules, 'Subscribe') !== false)
            <a class="btn btn-sm btn-primary btn-icon export-btn btn-badge mr-1" href="{{ route('newsletter.export') }}" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ __('Export') }}" filename="{{ __('Newsletter') }}" aria-label="{{ __('Export') }}">
                <i class="ti ti-file-export"></i>
            </a>
        @endif
    </div>

@endsection

@section('content')
    {{-- MKT-002. The advanced-filter bar, from ONE shared component: it reads this screen's filters
         from `TableFilterMemory::vocabularyFor()` and their wording from `headingFor()` /
         `optionsFor()`, so the controls here and the receipt inside the bar cannot disagree. --}}
    <x-ss.marketing-filters screen="newsletter" route="newsletter.index" :label="__('Filter subscribers')" />

    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection

