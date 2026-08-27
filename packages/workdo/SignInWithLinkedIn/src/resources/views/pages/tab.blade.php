
<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'linkedin_signin_setting' ? 'active' : '' }}"
        data-url="{{ route('linkedin-login.form') }}" data-tab="linkedin_signin_setting" id="pills-linkedin_signin-tab"
        data-bs-toggle="pill" data-bs-target="#eco-linkedin_setting" type="button" role="tab"
        aria-controls="pills-linkedin_signin" aria-selected="false">
        {{ __('Manage LinkedIn') }}
    </a>
</li>
