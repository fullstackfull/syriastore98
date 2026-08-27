<li class="nav-item col-xxl-2 col-xl-3 col-md-4 col-sm-6  col-12 text-center" role="presentation">
    <a class="nav-link btn-sm f-w-600 {{ $activeTab == 'frequently_product_setting' ? 'active' : '' }}" data-url="{{ route('frequently-product-setting.form') }}" data-tab="frequently_product_setting" id="pills-frequently_product-tab" data-bs-toggle="pill" data-bs-target="#eco-frequently_product" type="button" role="tab" aria-controls="pills-frequently_product" aria-selected="false">
    {{ __('Manage Frequently Product') }}
    </a>
</li>