@extends('layouts.app')
@section('page-title', __('Helpdesk'))
@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Ticket Create') }}</li>
@endsection
@push('css')
<style>
    .category_badge{
        min-width: 124px !important;
    }
    .status_badge{
        min-width: 96px !important;
    }
</style>
@endpush
@section('action-button')
    <div class="d-flex float-end">
        <div class="me-2 justify-content-md-end">
            <div class="col-auto pe-0">
                <select class="form-select btn-badge" id="projects"
                    onchange="this.options[this.selectedIndex].value && (window.location = this.options[this.selectedIndex].value);"
                    style="width: 200px;">
                    <option value="{{ route('helpdesk-tickets.search') }}">{{ __('All Tickets') }}</option>
                    @foreach ($ticketStatus as $ticketStatus)
                        <option value="{{ route('helpdesk-tickets.search', $ticketStatus->id) }}"
                            @if ($ticketStatus->id == $status) selected @endif>
                            {{ $ticketStatus->status_name }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="me-2 justify-content-md-end">
            @permission('Create Helpdesk Ticket')
                <a class="btn btn-sm btn-badge btn-primary mt-1" href="{{ route('helpdesk.ticket.create') }}" data-bs-toggle="tooltip" title="{{ __('Add Helpdesk Ticket') }}">
                    <i class="ti ti-plus"></i>
                </a>
            @endpermission
        </div>
    </div>
@endsection

@section('content')
    <div class="row">
        <div class="col-xl-12">
            <x-datatable :dataTable="$dataTable" />
        </div>
    </div>
@endsection
