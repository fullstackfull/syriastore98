@extends('layouts.app')

@section('page-title')
{{ __('Notification Templates') }}
@endsection

@section('breadcrumb')
<li class="breadcrumb-item" aria-current="page">{{ __('Notification Templates') }}</li>
@endsection

@section('content')
<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body table-border-style">
                <div class="table-responsive notification-table">
                    <table class="table mb-0 dataTable">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Module') }}</th>
                                <th class="text-end">{{ __('Action') }}</th>
                            </tr>
                        </thead>
                        @php
                        $languages = App\Models\Utility::languages();
                        @endphp
                        <tbody>
                            @foreach ($notifications as $key => $notification)
                            @php
                            $languages = App\Models\Utility::languages();
                            @endphp
                            <tr>
                                <td>{{ $notification->action }}</td>
                                <td class="text-capitalize">{{ Module_Alias_Name($notification->module) }}
                                </td>
                                <td class="text-end">
                                    <a title="{{ __('Show') }}" href="{{ route('notification-template.show', [$notification->id, 1]) }}" class="btn btn-sm btn-icon btn-warning btn-badge me-2" data-bs-placement="top" data-bs-toggle="tooltip">
                                        <i class="ti ti-eye f-20"></i>
                                    </a>
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