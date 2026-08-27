@php
    $modules = getshowModuleList(true);
@endphp

@section('action-button')
<!-- Search Input -->
<div class="admin-setting-search d-flex justify-content-end">
    <input type="text" id="tab-search" class="form-control btn-badge" style="max-width: 300px; background: transparent;" placeholder="{{ __('Search...') }}">
</div>
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item">{{ __('Marketplace') }}</li>
@endsection

<div class="card align-middle p-3" id="useradd-sidenav">
    <ul class="nav nav-pills row store-setting-tab" id="pills-tab" role="tablist">
        @foreach ($modules as $module)
            <li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center">
                <a class="nav-link btn-sm f-w-600 {{ ( $slug == ($module)) ? ' active' : '' }} " href="{{ route('marketplace.index', ($module)) }}">{{ $module }}</a>
            </li>
        @endforeach
    </ul>
</div>

<script>
    document.getElementById('tab-search').addEventListener('input', function () {
        var searchValue = this.value.toLowerCase();
        var navItems = document.querySelectorAll('#pills-tab .nav-item');

        navItems.forEach(function (item) {
            var tabText = item.querySelector('.nav-link').textContent.toLowerCase();
            if (tabText.includes(searchValue)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });
</script>