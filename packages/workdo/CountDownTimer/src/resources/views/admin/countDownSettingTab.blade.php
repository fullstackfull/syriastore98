<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a href="#count_down_settings"
        class="nav-link  btn-sm f-w-600  @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-count_down-tab')) active @endif"
        id="pills-count_down-tab" data-bs-toggle="pill" data-bs-target="#pills-count_down" type="button"
        role="tab" aria-controls="pills-count_down" aria-selected="false">
        {{ __('Count-Down Timer Settings') }}
    </a>
</li>