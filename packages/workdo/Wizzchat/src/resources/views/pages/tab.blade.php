<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'wizzchat_setting' ? 'active' : '' }}"
        data-url="{{ route('Wizzchat.form') }}" data-tab="wizzchat_setting" id="pills-wizzchat-tab" data-bs-toggle="pill"
        data-bs-target="#eco-wizzchat" type="button" role="tab" aria-controls="pills-wizzchat" aria-selected="false">
        {{ __('Manage Wizzchat') }}
    </a>
</li>
