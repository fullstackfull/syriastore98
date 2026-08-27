<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'recently_viewed_setting' ? 'active' : '' }}" data-url="{{ route('recently-viewed.form') }}" data-tab="recently_viewed_setting" id="pills-recently_viewed-tab" data-bs-toggle="pill" data-bs-target="#eco-recently_viewed" type="button" role="tab" aria-controls="pills-recently_viewed" aria-selected="false">
    {{ __('Manage Recently Viewed') }}
    </a>
</li>
