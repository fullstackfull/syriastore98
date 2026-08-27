<li>
    <a href="{{ route('my-account.index', $store->slug) }}?#tab-9" data-scroll="tab-9" class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
        <div class="flex items-center gap-3">
            <i class="fas fa-users text-gray-500"></i>
            <span>{{ __('Affiliate Link') }}</span>
        </div>
    </a>
</li>

<li>
    <a href="{{ route('my-account.index', $store->slug) }}?#tab-10" data-scroll="tab-10" class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
        <div class="flex items-center gap-3">
            <i class="fas fa-chart-line text-gray-500"></i>
            <span>{{ __('Affiliate Transactions') }}</span>
        </div>
    </a>
</li>

<li>
    <a href="{{ route('my-account.index', $store->slug) }}?#tab-11" data-scroll="tab-11" class="block py-2 px-3 rounded-md hover:bg-primary/10 transition-all duration-300">
        <div class="flex items-center gap-3">
            <i class="fas fa-money-check-alt text-gray-500"></i>
            <span>{{ __('Payouts') }}</span>
        </div>
    </a>
</li>