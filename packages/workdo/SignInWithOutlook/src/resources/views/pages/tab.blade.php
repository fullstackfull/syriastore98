<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'outlook_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('outlook-login.form') }}" data-tab="outlook_signin_setting" id="pills-outlook_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-outlook_setting" type="button" role="tab"
        aria-controls="pills-outlook_signin" aria-selected="false">
        {{ __('Manage Outlook') }}
    </a>
</li>
