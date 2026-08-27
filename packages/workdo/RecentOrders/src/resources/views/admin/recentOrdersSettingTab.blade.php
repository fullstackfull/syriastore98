<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a href="#recent_orders_settings"
        class="nav-link btn-sm f-w-600 @if(isset($app_setting_tab) && ($app_setting_tab == 'pills-recent_orders-tab')) active @endif"
        id="pills-recent_orders-tab" data-bs-toggle="pill" data-bs-target="#pills-recent_orders" type="button"
        role="tab" aria-controls="pills-recent_orders" aria-selected="false">
        {{ __('Recent Order Setting') }}
    </a>
</li>
