<div class="role-permission-table align-middle p-3" id="useradd-sidenav">
    <ul class="nav nav-pills  row store-setting-tab" id="pills-tab" role="tablist">
        @foreach ($role->permissions()->pluck('name') as $index => $permission)
            <li class="nav-item col-2 text-center {{ $index >= 11 ? 'd-none' : '' }}">
                <a class="btn btn-badge bg-primary btn-sm f-w-600" href="#">{{ $permission }}</a>
            </li>
        @endforeach
        @if ($role->permissions->count() > 10)
        <li class="nav-item show-more col-2 text-center text-primary d-inline-block  mt-2">
            <a href="#">{{ __('Show More') }}</a> </li>
        <li class="nav-item show-less col-2 text-center text-primary d-inline-block  mt-2 d-none">
            <a href="#" >{{ __('Show Less') }}</a>
        </li>
         
        @endif
    </ul>
</div>