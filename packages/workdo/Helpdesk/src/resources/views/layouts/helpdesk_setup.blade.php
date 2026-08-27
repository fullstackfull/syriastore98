<div class="card sticky-top" style="top:30px">
    <div class="list-group list-group-flush" id="useradd-sidenav">
        @permission('Manage Helpdesk Category')
            <a href="{{ route('helpdesk.category') }}"
                class="list-group-item list-group-item-action border-0 d-flex align-center justify-content-between gap-1 text-break {{ Request::route()->getName() == 'helpdesk.category' ? 'active' : '' }}">{{ __('Category') }}
                <div class="float-end"><i class="ti ti-chevron-right"></i></div>
            </a>
        @endpermission
        @permission('Manage Helpdesk Category')
            <a href="{{ route('helpdesk.status') }}"
                class="list-group-item list-group-item-action border-0 d-flex align-center justify-content-between gap-1 text-break {{ Request::route()->getName() == 'helpdesk.status' ? 'active' : '' }}">{{ __('Helpdesk Status') }}
                <div class="float-end"><i class="ti ti-chevron-right"></i></div>
            </a>
        @endpermission
    </div>
</div>
