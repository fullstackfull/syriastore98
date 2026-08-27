@php
    $user_id = auth()->user()->id ?? auth()->user()->id ?? 0;
    $settings = getAdminAllSetting($user_id ,$store->id);
    $currency = isset($settings['CURRENCY']) ? $settings['CURRENCY'] : '$';
    $companyName = auth()->guard('customers')->user() ? auth()->guard('customers')->user()->first_name : '';
    $transactions = \Workdo\ProductAffiliate\app\Models\AffiliateTransaction::where('affiliate_code' , auth()->guard('customers')->user()->affiliate_code)->get();
    $transactionsOrder = \Workdo\ProductAffiliate\app\Models\TransactionOrder::where('req_user_id',auth()->guard('customers')->user()->id)->get();
    $paidAmount = $transactionsOrder->where('status' , 2)->sum('req_amount');
    $paymentRequest = \Workdo\ProductAffiliate\app\Models\TransactionOrder::where('status' , 1)->where('req_user_id',auth()->guard('customers')->user()->id)->first();
@endphp

@push('page-script')
    <script>
        $('.cp_link').on('click', function() {
            var value = $(this).attr('data-link');
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val(value).select();
            document.execCommand("copy");
            $temp.remove();
            show_toastr('Success','Link Copy on Clipboard', 'success');
        });

        $('.tab-link').on('click', function() {
            var tabId = $(this).data('tab');
            $('.tab-content').addClass('d-none');
            $('#' + tabId).removeClass('d-none');

            $('.tab-link').removeClass('active');
            $(this).addClass('active');
        });
    </script>
@endpush

<!-- Affiliate Link Tab -->
<div id="tab-9" class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
    <div class="flex items-center justify-between md:mb-6 mb-4 gap-4 flex-wrap">
        <h2 class="font-heading font-bold text-xl">{{ __('Affiliate Link') }}</h2>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 md:gap-6 gap-4 md:mb-6 mb-4">
        <!-- Information Section -->
        <div>
            <h3 class="font-heading font-semibold text-lg mb-4 border-b pb-2">{{ __('Earn Commission') }}</h3>
            <p class="text-sm text-gray-500 mb-4">
                {{ __('Refer ') . $companyName . __(' and earn ') . $currency . (isset($setting) ? $setting->minimum_threshold_amount : '') . __(' per paid signup!') }}
            </p>
            @if(isset($setting) && $setting->guideline)
                <div class="text-sm text-gray-600">
                    {!! $setting->guideline !!}
                </div>
            @endif
        </div>

        <!-- Affiliate Link Section -->
        <div>
            <label for="affiliate-link" class="block mb-2 font-medium md:text-base text-sm">{{ __('Your Affiliate Link') }}</label>
            <div class="flex">
                <input type="text" 
                       id="affiliate-link"
                       value="{{ route('customer.register', ['storeSlug' => $slug, 'ref_id' => auth()->guard('customers')->user()->affiliate_code]) }}" 
                       class="form-input ltr:text-left rtl:text-right flex-1 rounded-r-none" 
                       readonly>
                <button class="cp_link btn-primary rounded-l-none"
                        data-link="{{ route('customer.register', ['storeSlug' => $slug, 'ref_id' => auth()->guard('customers')->user()->affiliate_code]) }}"
                        title="{{ __('Copy Link') }}">
                    {{ __('Copy') }}
                </button>
            </div>
        </div>
    </div>

    @if(isset($setting) && $setting->is_enable == 0 || !isset($setting))
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <p class="text-red-700 text-sm font-medium">
                {{ __('Note: Admin has disabled the Affiliate link.') }}
            </p>
        </div>
    @endif
</div>

<!-- Affiliate Transactions Tab -->
<div id="tab-10" class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm mb-6">
    <div class="flex items-center justify-between md:mb-6 mb-4 gap-4 flex-wrap">
        <h2 class="font-heading font-bold text-xl">{{ __('Affiliate Transactions') }}</h2>
    </div>
    
    <div class="overflow-x-auto">
        <table class="md:w-full min-w-[570px]">
            <thead class="ltr:text-left rtl:text-right bg-primary/10 border border-b">
                <tr>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">#</th>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Customer Name') }}</th>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Order ID') }}</th>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Order Price') }}</th>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Commission (%)') }}</th>
                    <th class="py-3 px-4 text-sm font-semibold text-gray-700">{{ __('Commission Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse($transactions as $key => $transaction)
                    <tr>
                        <td class="py-3 px-4 text-sm">{{ ++$key }}</td>
                        <td class="py-3 px-4 text-sm">
                            {{ !empty($transaction->getUser) ? $transaction->getUser->name : '-' }}
                        </td>
                        <td class="py-3 px-4 text-sm">
                            {{ !empty($transaction->getOrder) ? $transaction->getOrder->product_order_id : '-' }}
                        </td>
                        <td class="py-3 px-4 text-sm font-medium">{{ $currency . $transaction->final_price }}</td>
                        <td class="py-3 px-4 text-sm">{{ $transaction->commission }}%</td>
                        <td class="py-3 px-4 text-sm font-medium">
                            {{ $currency . ($transaction->final_price * $transaction->commission) / 100 }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-gray-500">
                            {{ __('No transactions found') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Payouts Tab -->
<div id="tab-11" class="bg-gray-50 border md:p-6 p-4 rounded-lg shadow-sm">
    <div class="flex items-center justify-between md:mb-6 mb-4 gap-4 flex-wrap">
        <h2 class="font-heading font-bold text-xl">{{ __('Payouts') }}</h2>
        
        @if (auth()->guard('customers')->user()->commission_amount > $paidAmount)
            @if ($paymentRequest == null)
                <a href="#" 
                   data-url="{{ route('request.amount.sent', [\Illuminate\Support\Facades\Crypt::encrypt(auth()->guard('customers')->user()->id)]) }}" 
                   data-size="md" 
                   data-ajax-popup="true" 
                   class="btn-primary text-sm"
                   data-title="{{ __('Send Request') }}">
                    {{ __('Request Payout') }}
                </a>
            @else
                <a href="{{ route('request.amount.cancel', auth()->guard('customers')->user()->id) }}" 
                   class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                    {{ __('Cancel Request') }}
                </a>
            @endif
        @endif
    </div>

    <!-- Payout Summary -->
    <div class="grid grid-cols-1 md:grid-cols-3 md:gap-6 gap-4 md:mb-6 mb-4">
        <div class="bg-white border rounded-lg p-4">
            <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Total Earned') }}</h3>
            <p class="text-2xl font-bold text-gray-900">
                {{ $currency . auth()->guard('customers')->user()->commission_amount }}
            </p>
        </div>

        <div class="bg-white border rounded-lg p-4">
            <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Paid Amount') }}</h3>
            <p class="text-2xl font-bold text-green-600">{{ $currency . $paidAmount }}</p>
        </div>

        <div class="bg-white border rounded-lg p-4">
            <h3 class="text-sm font-medium text-gray-500 mb-1">{{ __('Pending') }}</h3>
            <p class="text-2xl font-bold text-orange-600">
                {{ $currency . (auth()->guard('customers')->user()->commission_amount - $paidAmount) }}
            </p>
        </div>
    </div>

    @if($paymentRequest)
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
            <p class="text-yellow-700 text-sm">
                {{ __('You have a pending payout request. Please wait for admin approval.') }}
            </p>
        </div>
    @endif
</div>