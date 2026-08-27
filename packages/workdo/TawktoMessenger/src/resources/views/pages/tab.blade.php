<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'tawktomessenger_setting' ? 'active' : '' }}"
        data-url="{{ route('tawktomessenger.form') }}" data-tab="tawktomessenger_setting" id="pills-tawktomessenger-tab"
        data-bs-toggle="pill" data-bs-target="#eco-tawk-to-setting" type="button" role="tab"
        aria-controls="pills-tawktomessenger" aria-selected="false">
        {{ __('Manage Tawk.to') }}
    </a>
</li>
