@extends('layouts.app')

@section('page-title')
    {{ __('Finance') }}
@endsection

@section('breadcrumb')
    <li aria-current="page" class="ss-breadcrumb__item">
        {{ __('Finance') }}
    </li>
@endsection

@push('styles')
<style>
    .finance-tabs .nav-link {
        padding: 0.5rem 1.25rem !important;
        font-size: 0.9rem !important;
        font-weight: 600 !important;
        color: #6c757d !important;
        border-radius: 0.5rem !important;
        transition: all 0.2s ease-in-out !important;
        margin: 0 0.25rem !important;
    }
    .finance-tabs .nav-link:hover {
        background-color: #f8f9fa !important;
        color: #495057 !important;
        transform: translateY(-1px);
    }
    .finance-tabs .nav-link.active {
        background-color: #0d6efd !important;
        color: #ffffff !important;
        box-shadow: 0 4px 6px rgba(13, 110, 253, 0.25) !important;
    }
    .finance-tabs .nav-link i {
        font-size: 1rem !important;
    }
    /* تنسيق خاص لحقل البحث الذكي */
    .smart-search-label {
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
</style>
@endpush

@section('content')

{{-- الإحصائيات والأزرار العلوية --}}
{{-- P2.5-WAVE-24: two scoped hooks, so the phone rules do not have to target `.d-flex.gap-2` or
     `.col-xl.col-md-6`, which appear all over the admin. MEASURED at 320x568 before them: these
     four buttons stacked one per line at four different widths (199, 148, 170, 206) for 196px, and
     the five stat cards below them stacked at 102px each, putting the first ledger row at y=2131
     on a 568px screen. --}}
<div class="row ss-finance-overview">
    <div class="d-flex gap-2 mb-3 flex-wrap ss-finance-actions">
        
        {{-- ✅ زر طلب السحب --}}
        @if(auth()->user()->isAbleTo('Request Finance Withdrawal'))
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#withdrawalModal">
            <i class="ti ti-wallet me-1"></i> {{ __('Request Withdrawal') }}
        </button>
        @endif

        {{-- ✅ زر إضافة الرصيد --}}
        @if(auth()->user()->isAbleTo('Add Finance Balance'))
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addBalanceModal">
            <i class="ti ti-plus me-1"></i> {{ __('Add Balance') }}
        </button>
        @endif

        {{-- ✅ زر خصم الرصيد --}}
        @if(auth()->user()->isAbleTo('Deduct Finance Balance'))
        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deductBalanceModal">
            <i class="ti ti-minus me-1"></i> {{ __('Deduct Balance') }}
        </button>
        @endif

        {{-- ✅ رابط طلبات السحب --}}
        @if(auth()->user()->isAbleTo('View Finance Withdrawal Requests'))
        <a href="{{ route('finance.withdrawals') }}" class="btn btn-dark">
            <i class="ti ti-list me-1"></i> {{ __('Withdrawal Requests') }}
        </a>
        @endif

    </div>
    
    {{-- بطاقات الإحصائيات --}}
    {{-- D2 + GAP-222: THE LABELS SAY WHAT THE FIGURES ARE, AND THE FIGURES SAY WHICH MONEY THEY ARE IN.
         All five are columns of the SAME `store_wallets` row and were all printed as bare decimals. The
         first is owner decision D2's third figure: `available_balance` is a RUNNING balance -- what the
         merchant may withdraw now. It has no period and is not revenue; a merchant who withdraws their own
         earnings would otherwise read that sales had fallen.

         THE CURRENCY IS THE WALLET'S, NOT THE PAGE'S. `store_wallets.currency_code` is written once when
         the wallet is provisioned, from `defult_currancy`, and nothing rewrites it when that setting later
         changes -- so a store that switched currency keeps a wallet holding the old one. Nothing here
         converts: when the two disagree the hint says so and both codes stand.

         ONE GRID, NOT FIVE CARDS. `<x-ss.stat>` is what gives a figure a stated period, a visible caveat
         and the bidi isolates that keep `amount CODE` together on an Arabic page, and `.ss-stat` needs its
         `.ss-stats` parent for the grid and hairlines. The caveat is carried once, by the tile it qualifies,
         rather than repeated five times on one card. --}}
    <div class="col-12 mb-4">
        <x-ss.stats :label="__('Wallet')">
            <x-ss.stat :label="__('Available to withdraw')"
                   :value="$walletFigure['value']"
                   :period="$walletFigure['period']"
                   :hint="$walletFigure['hint']" />

            @foreach ($walletFigure['siblings'] as $siblingLabel => $siblingValue)
                <x-ss.stat :label="__($siblingLabel)"
                       :value="$siblingValue"
                       :period="$walletFigure['period']" />
            @endforeach
        </x-ss.stats>
    </div>
</div>

{{-- P5-16 / D1 + D2 -- the two LEDGER figures, each with its period stated and its limits visible.
     `$ledgerFigures` is built by App\Support\Metrics\FinanceLedgerFigures from the metric layer over
     the period `LedgerCoverage` can vouch for: `store_order_financials` has no backfill, so an earlier
     window would read zero for months that simply have no record, and a merchant would read that as
     a business that stopped. The start date is stated instead. `value` of null renders as "Not
     available" -- never as a zero. --}}
<div class="row mb-4">
    <div class="col-12">
        <x-ss.stats :label="__('Financial ledger')">
            <x-ss.stat :label="__('Payments received')"
                   :value="$ledgerFigures['payments']['value']"
                   :period="$ledgerFigures['payments']['period']"
                   :hint="$ledgerFigures['payments']['hint']" />

            <x-ss.stat :label="__('Estimated gross profit')"
                   :value="$ledgerFigures['profit']['value']"
                   :period="$ledgerFigures['profit']['period']"
                   :hint="$ledgerFigures['profit']['hint']" />
        </x-ss.stats>
    </div>
</div>

{{-- إحصائيات المخزون --}}
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted">{{ __('Inventory Units') }}</div>
                <h4 class="mb-0">{{ number_format($inventoryUnits) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted">{{ __('Inventory Capital') }}</div>
                <h4 class="mb-0">{{ number_format($inventoryCost, 2) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted">{{ __('Inventory Retail Value') }}</div>
                <h4 class="mb-0">{{ number_format($inventoryRetailValue, 2) }}</h4>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <div class="text-muted">{{ __('Potential Profit') }}</div>
                {{-- THE TONE IS ON THE SPAN, NOT ON THE <h4>, AND THAT IS NOT A STYLE CHOICE.
                     `style-dark.css:29442` and `rtl-style-dark.css:29398` declare
                     `h1,h2,h3,h4,h5,h6 { color: var(--bs-white) !important; }`. An !important on
                     an element selector beats an ordinary class rule, so with the tone on the
                     <h4> this figure rendered WHITE in dark mode -- measured in Chromium at
                     rgb(255,255,255), 14.25:1, with `.ss-money--credit` matched and overridden.
                     Readable, but the sign was no longer in the colour, and the repair was a
                     silent no-op here while the light theme looked fixed. The two light sheets
                     carry no such rule, which is why only dark mode was affected.
                     A <span> is not a heading, so the host rule cannot reach it and the design
                     system wins with no !important of its own. --}}
                <h4 class="mb-0">
                    <span class="ss-money {{ $inventoryPotentialProfit >= 0 ? 'ss-money--credit' : 'ss-money--debit' }}">
                        {{ number_format($inventoryPotentialProfit, 2) }}
                    </span>
                </h4>
            </div>
        </div>
    </div>
</div>

{{-- P5-10 / ANA-006 -- "do NOT claim precise profitability when cost or fee data is missing".

     `products.cost_price` is `decimal(15,2) NOT NULL DEFAULT 0.00`, so a cost nobody entered
     reads 0.00 and is indistinguishable from a product that genuinely cost nothing. Every such
     product hands its WHOLE retail value to Potential Profit with nothing subtracted.

     The numbers are MEASURED in the same pass that builds the tiles, so this names how many
     products and exactly how much is exposed instead of saying "some data may be missing", and
     it disappears by itself once cost is filled in. `ss-attention` states the reason a caveat
     must not be permanent furniture; the same reasoning applies here.

     `.ss-help` is used rather than a hand-rolled box because it is built from DS tokens
     (`--ss-info-bg` / `--ss-info-fg`, ratcheted by CI's token-contrast gate) and its accent uses
     `border-inline-start`, so it flips correctly in Arabic RTL without a second rule. --}}
@if (($inventoryUncostedProducts ?? 0) > 0)
    <div class="row mb-4">
        <div class="col-12">
            <div class="ss-help">
                <p class="ss-help__title">{{ __('Potential Profit is not precise') }}</p>
                {{ __('Cost is not recorded for :uncosted of :valued in-stock products. Those products contribute :amount of retail value with no cost subtracted, so Potential Profit is overstated by up to that amount.', [
                    'uncosted' => number_format($inventoryUncostedProducts),
                    'valued'   => number_format($inventoryValuedProducts),
                    'amount'   => number_format($inventoryUncostedRetail, 2),
                ]) }}
            </div>
        </div>
    </div>
@endif

{{-- نظام Tabs المحسن --}}
<div class="card">
    {{-- `bg-white` REMOVED (P2.5-WAVE-31). Bootstrap's utility is `!important` and has no dark
                             arm, so this header rendered 1145x55 of PURE WHITE on the dark page -- measured
                             lum 1.00. `.card-header`'s own `var(--bs-card-cap-bg)` already follows the theme,
                             which is why removing the utility is the fix and an override would not be. --}}
                        <div class="card-header border-bottom-0 pt-3 pb-0">
        <ul class="nav nav-pills finance-tabs justify-content-center gap-2" id="financeTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" 
                        id="transactions-tab" 
                        data-bs-toggle="pill" 
                        data-bs-target="#transactions" 
                        type="button" 
                        role="tab" 
                        aria-controls="transactions" 
                        aria-selected="true">
                    <i class="ti ti-exchange me-2"></i>
                    {{ __('Financial Transactions') }}
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" 
                        id="orders-tab" 
                        data-bs-toggle="pill" 
                        data-bs-target="#orders" 
                        type="button" 
                        role="tab" 
                        aria-controls="orders" 
                        aria-selected="false">
                    <i class="ti ti-shopping-cart me-2"></i>
                    {{ __('Order Financial Records') }}
                </button>
            </li>
        </ul>
    </div>

    <div class="card-body pt-3">
        <div class="tab-content" id="financeTabsContent">
            
            {{-- Tab 1: Financial Transactions --}}
            <div class="tab-pane fade show active" id="transactions" role="tabpanel" aria-labelledby="transactions-tab">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-3">{{ __('Financial Transactions') }}</h5>
                        
                        {{-- 🔍 نموذج البحث الذكي + الفلاتر القديمة --}}
                        <form method="GET" action="{{ route('finance.index') }}" id="smartSearchForm" class="mb-4">
                            
                            {{-- الصف الأول: البحث الذكي --}}
                            <div class="row align-items-end g-3 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold text-muted smart-search-label" for="searchField">
                                        <i class="ti ti-filter me-1"></i> {{ __('Search By Field') }}
                                    </label>
                                    <select name="search_field" id="searchField" class="form-select">
                                        <option value="">{{ __('Select a field...') }}</option>
                                        <option value="id" {{ request('search_field') == 'id' ? 'selected' : '' }}>{{ __('ID') }}</option>
                                        <option value="created_at" {{ request('search_field') == 'created_at' ? 'selected' : '' }}>{{ __('Date') }}</option>
                                        <option value="type" {{ request('search_field') == 'type' ? 'selected' : '' }}>{{ __('Type') }}</option>
                                        <option value="description" {{ request('search_field') == 'description' ? 'selected' : '' }}>{{ __('Description') }}</option>
                                        <option value="currency_code" {{ request('search_field') == 'currency_code' ? 'selected' : '' }}>{{ __('Currency') }}</option>
                                        <option value="credit" {{ request('search_field') == 'credit' ? 'selected' : '' }}>{{ __('Credit') }}</option>
                                        <option value="debit" {{ request('search_field') == 'debit' ? 'selected' : '' }}>{{ __('Debit') }}</option>
                                        <option value="balance_before" {{ request('search_field') == 'balance_before' ? 'selected' : '' }}>{{ __('Balance Before') }}</option>
                                        <option value="balance_after" {{ request('search_field') == 'balance_after' ? 'selected' : '' }}>{{ __('Balance After') }}</option>
                                        <option value="status" {{ request('search_field') == 'status' ? 'selected' : '' }}>{{ __('Status') }}</option>
                                    </select>
                                </div>

                                <div class="col-md-7" id="searchValueContainer" style="display: {{ request('search_field') ? 'block' : 'none' }};">
                                    <label class="form-label fw-bold text-muted smart-search-label" for="searchValue">
                                        <i class="ti ti-pencil me-1"></i> {{ __('Search Value') }}
                                    </label>
                                    <div id="searchInputContainer">
                                        <!-- يتم ملؤه بواسطة JavaScript -->
                                    </div>
                                </div>

                                <div class="col-md-2" id="searchButtonContainer" style="display: {{ request('search_field') ? 'block' : 'none' }};">
                                    <button type="submit" class="btn btn-primary w-100 d-flex align-items-center justify-content-center" style="height: 42px;">
                                        <i class="ti ti-search" style="font-size: 1.2rem; color: #ffffff;"></i>
                                    </button>
                                </div>
                            </div>

                            {{-- الصف الثاني: الفلاتر القديمة (محفوظة كما هي) --}}
                            <div class="row align-items-end g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold" for="financeFromDate">{{ __('From Date') }}</label>
                                    <input type="date" name="from_date" id="financeFromDate" class="form-control" value="{{ request('from_date') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold" for="financeToDate">{{ __('To Date') }}</label>
                                    <input type="date" name="to_date" id="financeToDate" class="form-control" value="{{ request('to_date') }}">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold" for="financeType">{{ __('Type') }}</label>
                                    <select name="type" id="financeType" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="sale" {{ request('type') == 'sale' ? 'selected' : '' }}>{{ __('Sale') }}</option>
                                        <option value="manual_deposit" {{ request('type') == 'manual_deposit' ? 'selected' : '' }}>{{ __('Deposit') }}</option>
                                        <option value="manual_withdrawal" {{ request('type') == 'manual_withdrawal' ? 'selected' : '' }}>{{ __('Withdrawal') }}</option>
                                        <option value="refund" {{ request('type') == 'refund' ? 'selected' : '' }}>{{ __('Refund') }}</option>
                                        <option value="commission" {{ request('type') == 'commission' ? 'selected' : '' }}>{{ __('Commission') }}</option>
                                        <option value="adjustment" {{ request('type') == 'adjustment' ? 'selected' : '' }}>{{ __('Adjustment') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold" for="financeStatus">{{ __('Status') }}</label>
                                    <select name="status" id="financeStatus" class="form-control">
                                        <option value="">{{ __('All') }}</option>
                                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>{{ __('Completed') }}</option>
                                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>{{ __('Pending') }}</option>
                                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>{{ __('Cancelled') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="ti ti-filter me-1"></i> {{ __('Filter') }}
                                    </button>
                                </div>
                            </div>
                            
                            {{-- زر إعادة التعيين --}}
                            @if(request('search_field') || request('search_value') || request('from_date') || request('to_date') || request('type') || request('status'))
                                <div class="mt-3">
                                    <a href="{{ route('finance.index') }}" class="btn btn-light btn-sm">
                                        <i class="ti ti-x me-1"></i> {{ __('Reset All Filters') }}
                                    </a>
                                </div>
                            @endif
                        </form>

                        {{-- جدول الحركات المالية --}}
                        <div class="table-responsive mt-2">
                            <table class="table ss-table ss-table--stack align-middle">
                                <thead>
                                    <tr>
                                        <th class="text-nowrap">{{ __('ID') }}</th>
                                        <th class="text-nowrap">{{ __('Date') }}</th>
                                        <th>{{ __('Type') }}</th>
                                        <th>{{ __('Description') }}</th>
                                        <th class="text-nowrap">{{ __('Currency') }}</th>
                                        <th class="text-end">{{ __('Credit') }}</th>
                                        <th class="text-end">{{ __('Debit') }}</th>
                                        <th class="text-end">{{ __('Balance Before') }}</th>
                                        <th class="text-end">{{ __('Balance After') }}</th>
                                        <th class="text-center">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($transactions as $transaction)
                                        <tr>
                                            <td class="text-nowrap fw-bold text-muted">#{{ $transaction->id }}</td>
                                            <td class="text-nowrap">{{ optional($transaction->created_at)->format('Y-m-d H:i') }}</td>
                                            <td>
                                                {{-- A TYPE, not a status, so this is the DS chip and not x-ss.status-badge: status-badge.blade.php:3-5 states the dot IS the status signal. The old `badge bg-light text-dark border` measured 1.09:1 in dark mode -- `.bg-light` keeps its near-white #f8f9fa fill while the shipped A-05 block lifts `.text-dark` to #eeeff1 outside `.modal-content`. `.ss-chip` keeps the border (store-syria-ds.css:2058) at 8.12:1 light / 8.98:1 dark. --}}
                                                <x-ss.chip :interactive="false"
                                                    :label="ucwords(str_replace('_', ' ', $transaction->type))" />
                                            </td>
                                            <td>
                                                {{ $transaction->description ?? '-' }}
                                                @if($transaction->reference_type && $transaction->reference_id)
                                                    <div class="small text-muted mt-1">
                                                        <i class="ti ti-link me-1"></i>{{ $transaction->reference_type }} #{{ $transaction->reference_id }}
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="text-nowrap">
                                                @if(!empty($transaction->currency_code))
                                                    <span class="text-primary fw-semibold">
                                                        {{ $transaction->currency_code }}
                                                    </span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if($transaction->direction == 'credit')
                                                    <span class="ss-money ss-money--credit fw-bold">+{{ number_format($transaction->amount, 2) }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if($transaction->direction == 'debit')
                                                    <span class="ss-money ss-money--debit fw-bold">-{{ number_format($transaction->amount, 2) }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-muted"><span class="ss-money">{{ number_format($transaction->balance_before, 2) }}</span></td>
                                            <td class="text-end fw-bold"><span class="ss-money">{{ number_format($transaction->balance_after, 2) }}</span></td>
                                            <td class="text-center">
                                                @if($transaction->status == 'completed')
                                                    <x-ss.status-badge tone="success" :label="__('Completed')" />
                                                @elseif($transaction->status == 'pending')
                                                    <x-ss.status-badge tone="warning" :label="__('Pending')" />
                                                @else
                                                    <x-ss.status-badge tone="danger" :label="__('Cancelled')" />
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="10" class="text-center py-4 text-muted">
                                                <i class="ti ti-inbox fs-1 d-block mb-2 opacity-50"></i>
                                                {{ __('No financial transactions found.') }}
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">{{ $transactions->withQueryString()->links() }}</div>
                    </div>
                </div>
            </div>

            {{-- Tab 2: Order Financial Records --}}
            <div class="tab-pane fade" id="orders" role="tabpanel" aria-labelledby="orders-tab">
                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h5 class="card-title mb-3">{{ __('Order Financial Records') }}</h5>

                        {{-- P5-10 / ANA-006, the SECOND profit figure on this screen. The `Profit`
                             column below prints `store_order_financials.gross_profit` raw, and
                             `product_cost` is `decimal(15,2) NOT NULL DEFAULT '0.00'` -- so a row
                             whose cost was never captured reports the WHOLE order value as margin,
                             to two decimal places, as though it were measured.

                             The row does print its own `Cost` of 0.00 alongside, but nothing says
                             why it is zero and nothing adds the rows up, so the column still reads
                             as a precise margin. Counted over the store's whole ledger, not the
                             visible page -- see the controller for why. --}}
                        @if (($orderFinancialsWithoutCost ?? 0) > 0)
                            <div class="ss-help mb-3">
                                <p class="ss-help__title">{{ __('Some of these margins cannot be computed') }}</p>
                                {{-- "discounted product subtotal", NOT "order value", and the
                                     difference is the whole point of the block. `gross_profit` is
                                     `(product_amount - discount_amount) - product_cost`
                                     (`OrderFinancialService.php:82-83`), so with no cost it is the
                                     product subtotal after discount — NOT the order total, which is
                                     the adjacent `Total` column (`customer_total`, tax and shipping
                                     included, :481). On a screen whose purpose is to stop a figure
                                     claiming more than it can, the caveat must not overstate either.
                                     Caught in independent review. --}}
                                {{ __('Cost is not recorded for :uncosted of :total orders in this ledger. Those rows show the discounted product subtotal as margin, because there is no cost to subtract from it.', [
                                    'uncosted' => number_format($orderFinancialsWithoutCost),
                                    'total'    => number_format($orderFinancialsTotal),
                                ]) }}
                            </div>
                        @endif

                        <div class="table-responsive">
                            <table class="table ss-table ss-table--stack align-middle">
                                <thead>
                                    <tr>
                                        <th>{{ __('Order') }}</th>
                                        <th>{{ __('Products') }}</th>
                                        <th>{{ __('Discount') }}</th>
                                        <th>{{ __('Tax') }}</th>
                                        <th>{{ __('Shipping') }}</th>
                                        <th>{{ __('Total') }}</th>
                                        <th>{{ __('Cost') }}</th>
                                        <th>{{ __('Profit') }}</th>
                                        <th>{{ __('Paid') }}</th>
                                        <th>{{ __('Remaining') }}</th>
                                        <th>{{ __('Payment Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($orderFinancials as $financial)
                                        <tr>
                                            <td>#{{ $financial->order_id }}</td>
                                            <td><span class="ss-money">{{ number_format($financial->product_amount, 2) }}</span></td>
                                            <td class="ss-money ss-money--debit">-{{ number_format($financial->discount_amount, 2) }}</td>
                                            <td><span class="ss-money">{{ number_format($financial->tax_amount, 2) }}</span></td>
                                            <td><span class="ss-money">{{ number_format($financial->shipping_amount, 2) }}</span></td>
                                            <td><strong class="ss-money">{{ number_format($financial->customer_total, 2) }}</strong></td>
                                            <td><span class="ss-money">{{ number_format($financial->product_cost, 2) }}</span></td>
                                            <td>
                                                @if($financial->gross_profit >= 0)
                                                    <span class="ss-money ss-money--credit fw-bold">{{ number_format($financial->gross_profit, 2) }}</span>
                                                @else
                                                    <span class="ss-money ss-money--debit fw-bold">{{ number_format($financial->gross_profit, 2) }}</span>
                                                @endif
                                            </td>
                                            <td><span class="ss-money">{{ number_format($financial->paid_amount, 2) }}</span></td>
                                            <td><span class="ss-money">{{ number_format($financial->remaining_amount, 2) }}</span></td>
                                            <td>
                                                {{-- ✅ حماية زر تحديث حالة الدفع --}}
                                                @if(auth()->user()->isAbleTo('Edit Finance Payment Status'))
                                                    <form method="POST" action="{{ route('finance.order-financial.status', $financial->id) }}">
                                                        @csrf
                                                        <div class="d-flex gap-2 align-items-center">
                                                            {{-- P2.5 -- AN UNNAMED SELECT, AND THE FALSE EVIDENCE IS WHY IT SURVIVED.
                                                                 This control had no `<label>`, no `aria-label` and no text, so its
                                                                 accessible name was the empty string -- a combobox announced as
                                                                 "combobox" and nothing else, once per row, in a table where every
                                                                 row looks the same and the control CHANGES WHETHER MONEY IS
                                                                 RECORDED AS RECEIVED.

                                                                 It went unfound through a whole capture wave because this surface's
                                                                 four captures were 16x16 blank tiles: the form lives in the
                                                                 `#orders` tab-pane (:402), which was never opened, so the harness
                                                                 measured a 0x0 rect, wrote valid-but-empty PNGs, and reported
                                                                 `combos=4`. The screenshot SUCCEEDED, so no premise fired. Opening
                                                                 the tab found this on the first real run.

                                                                 The name identifies the ORDER, because a screen-reader user moving
                                                                 by column meets this select with no row context. It does NOT carry
                                                                 the current status: the selected option already announces that, and
                                                                 a name repeating it would disagree with itself the moment the
                                                                 merchant changes the selection. --}}
                                                            <select name="payment_status"
                                                                aria-label="{{ __('Payment status for order :order', ['order' => '#' . $financial->order_id]) }}"
                                                                class="form-control form-control-sm" style="min-width: 130px;">
                                                                {{-- P5-1 -- A REVERSED ROW MUST SHOW WHAT IT IS.

                                                                     F124 and F127 write `cancelled` and `refunded` into this
                                                                     column. With only these two options, NEITHER carried
                                                                     `selected` on such a row, so the browser displayed the
                                                                     first one -- the merchant was told a refunded order was
                                                                     "Not Received", and any click submitted `unpaid`.

                                                                     The terminal state is rendered as a DISABLED option, which
                                                                     is deliberate in both directions: selected, so the true
                                                                     state is what the merchant reads; disabled, so it cannot be
                                                                     CHOSEN. Setting `cancelled` or `refunded` by hand would
                                                                     fabricate a reversal, and the validator refuses them for
                                                                     the same reason -- they are written by
                                                                     OrderEntitlementReversal, never typed.

                                                                     paid/unpaid stay selectable, so the escape hatch
                                                                     syncFromOrder's docblock describes is not removed. --}}
                                                                @if(in_array($financial->payment_status, ['cancelled', 'refunded'], true))
                                                                    <option value="{{ $financial->payment_status }}" selected disabled>
                                                                        {{ $financial->payment_status === 'cancelled' ? __('Cancelled') : __('Refunded') }}
                                                                    </option>
                                                                @endif
                                                                <option value="unpaid" {{ $financial->payment_status === 'unpaid' ? 'selected' : '' }}>{{ __('Not Received') }}</option>
                                                                <option value="paid" {{ $financial->payment_status === 'paid' ? 'selected' : '' }}>{{ __('Received') }}</option>
                                                            </select>
                                                            {{-- P4-F128 -- the reason, required by the handler.

                                                                 This adjustment moves the merchant's entitlement and the
                                                                 wallet WITHOUT touching the customer's settlement, so after
                                                                 it the two deliberately disagree. Whether that is correct
                                                                 (cash arrived outside the gateway) or a mistake is a fact
                                                                 only the person clicking holds, and it is unrecoverable
                                                                 afterwards. It goes into an AuditLog row, not a new column.

                                                                 The label is visually hidden rather than absent: the input
                                                                 sits in a dense table cell where a visible label would wrap
                                                                 the row, but a screen-reader user still needs to be told
                                                                 what this box is and that it names an order. --}}
                                                            <label for="finance-reason-{{ $financial->id }}" class="visually-hidden">
                                                                {{ __('Reason for changing the payment status of order :order', ['order' => '#' . $financial->order_id]) }}
                                                            </label>
                                                            <input type="text"
                                                                id="finance-reason-{{ $financial->id }}"
                                                                name="reason"
                                                                required
                                                                maxlength="255"
                                                                class="form-control form-control-sm"
                                                                style="min-width: 160px;"
                                                                placeholder="{{ __('Reason (required)') }}">
                                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Update') }}</button>
                                                        </div>
                                                        {{-- A server-side refusal on this form is a 302 back to this page. Without
                                                             this block the merchant gets a silent reload and no idea why: the
                                                             browser's `required` catches the ordinary case, but the encoding rule
                                                             and anyone who defeats the attribute land here. --}}
                                                        @error('reason')
                                                            <div class="text-danger small mt-1" role="alert">{{ $message }}</div>
                                                        @enderror
                                                    </form>
                                                @else
                                                    {{-- P5-1 -- FOUR STATES, NOT TWO. The @else arm used to label every
                                                         non-paid row "Not Received" in red, so a REFUNDED order -- one the
                                                         customer was paid back for -- read as money the merchant was still
                                                         waiting on. That is not a nuance, it is the opposite of what
                                                         happened. A reader without the edit permission saw it too. --}}
                                                    @if($financial->payment_status === 'paid')
                                                        <x-ss.status-badge tone="success" :label="__('Received')" />
                                                    @elseif($financial->payment_status === 'refunded')
                                                        <x-ss.status-badge tone="info" :label="__('Refunded')" />
                                                    @elseif($financial->payment_status === 'cancelled')
                                                        <x-ss.status-badge tone="warning" :label="__('Cancelled')" />
                                                    @else
                                                        <x-ss.status-badge tone="danger" :label="__('Not Received')" />
                                                    @endif
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="11" class="text-center py-4">{{ __('No order financial records found.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3">{{ $orderFinancials->withQueryString()->links() }}</div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

{{-- ==================== MODALS ==================== All three carry data-ss-theme="light" on their .modal-content, and it is load-bearing rather than tidy: the host keeps --bs-modal-bg #ffffff even in dark mode (pinned in BOTH dark sheets by DarkModalSurfaceContrastTest::test_the_host_still_carries_the_three_rules_this_slice_exists_to_beat), so a design-system token inside one resolves its DARK value onto white. store-syria-ds.css:3902-3905 restyles .invalid-feedback unconfined and dark --ss-danger is #f3a9a4 = 1.91:1 on white, while the light --ss-red-700 #b3261e is 6.54:1 and the focus ring goes from #68cbe1 1.87:1 to #4f77c0 4.44:1. Same idiom and same measured reason as category/create:49, users/create:23, attributes/create:19. The maxlength values below are copied from the server rules (StoreFinanceController 671/791/916), not chosen. --}}

{{-- ✅ Modal: Add Balance --}}
@if(auth()->user()->isAbleTo('Add Finance Balance'))
<div class="modal fade" id="addBalanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" data-ss-theme="light">
            <form method="POST" action="{{ route('finance.add.balance') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add Balance') }}</h5>
                    {{-- P2.5 -- THE CLOSE CONTROL HAD NO NAME. Bootstrap's `.btn-close` paints its
                         glyph with a background-image SVG and carries no text, so the accessible
                         name was the empty string: the harness reported `button[button]` nameless
                         four times per modal, three modals, every combination. `aria-label="Close"`
                         is what layouts/app.blade.php:720 already does for the shell's own
                         dialogs -- this follows it rather than inventing a second convention. --}}
                    <button type="button" aria-label="{{ __('Close') }}" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="addBalanceAmount">{{ __('Amount') }}</label><span class="ss-label__required" aria-hidden="true">*</span>
                        <input type="number" name="amount" id="addBalanceAmount" class="form-control" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="addBalanceDescription">{{ __('Reason / Description') }}</label>
                        <textarea name="description" id="addBalanceDescription" class="form-control" rows="3" maxlength="255" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-success">{{ __('Add Balance') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- ✅ Modal: Deduct Balance --}}
@if(auth()->user()->isAbleTo('Deduct Finance Balance'))
<div class="modal fade" id="deductBalanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" data-ss-theme="light">
            <form method="POST" action="{{ route('finance.deduct.balance') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Deduct Balance') }}</h5>
                    <button type="button" aria-label="{{ __('Close') }}" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        {{ __('Available balance:') }} <strong>{{ number_format($wallet->available_balance, 2) }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="deductBalanceAmount">{{ __('Amount') }}</label>
                        <input type="number" name="amount" id="deductBalanceAmount" class="form-control" min="0.01" max="{{ $wallet->available_balance }}" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="deductBalanceDescription">{{ __('Reason / Description') }}</label>
                        <textarea name="description" id="deductBalanceDescription" class="form-control" rows="3" maxlength="255" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-danger">{{ __('Deduct Balance') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

{{-- ✅ Modal: Withdrawal Request --}}
@if(auth()->user()->isAbleTo('Request Finance Withdrawal'))
<div class="modal fade" id="withdrawalModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" data-ss-theme="light">
            <form method="POST" action="{{ route('finance.withdrawal.request') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Request Withdrawal') }}</h5>
                    <button type="button" aria-label="{{ __('Close') }}" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        {{ __('Available Balance') }}: <strong>{{ number_format($wallet->available_balance, 2) }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="withdrawalAmount">{{ __('Amount') }}</label>
                        <input type="number" name="amount" id="withdrawalAmount" class="form-control" min="0.01" max="{{ $wallet->available_balance }}" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="withdrawalPaymentMethod">{{ __('Payment Method') }}</label>
                        <select name="payment_method" id="withdrawalPaymentMethod" class="form-control" required>
                            <option value="">{{ __('Select') }}</option>
                            <option value="bank">{{ __('Bank Transfer') }}</option>
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="withdrawalPaymentDetails">{{ __('Payment Details') }}</label>
                        <textarea name="payment_details" id="withdrawalPaymentDetails" class="form-control" rows="3" maxlength="2000" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="withdrawalNote">{{ __('Note') }}</label>
                        <textarea name="store_note" id="withdrawalNote" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Submit Withdrawal Request') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchField = document.getElementById('searchField');
    const searchValueContainer = document.getElementById('searchValueContainer');
    const searchInputContainer = document.getElementById('searchInputContainer');
    const searchButtonContainer = document.getElementById('searchButtonContainer');

    @php
        $currentSearchValue = request('search_value', '');
        // P1-SEC-80. The search field is written below with innerHTML, and `search_value` is the REQUEST's: a link carrying `?search_field=description&search_value="><img ...>` fired this
        // script's change handler on load and put the value into the markup unescaped. The script gets TWO copies: the raw one, which is only ever COMPARED (the select templates), and an
        // HTML-escaped one for every `value="..."` it writes.
        $currentSearchValue = is_scalar($currentSearchValue) ? (string) $currentSearchValue : '';
        $currentSearchValueHtml = e($currentSearchValue);
    @endphp
    const currentSearchValue = {!! json_encode($currentSearchValue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    const currentSearchValueHtml = {!! json_encode($currentSearchValueHtml, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};

    searchField.addEventListener('change', function() {
        const selectedField = this.value;

        if (!selectedField) {
            searchValueContainer.style.display = 'none';
            searchButtonContainer.style.display = 'none';
            return;
        }

        searchValueContainer.style.display = 'block';
        searchButtonContainer.style.display = 'block';
        {{-- Seven mutually exclusive branches write into #searchInputContainer, which the line below clears first, so only one control named `search_value` is ever in the DOM. Each template therefore carries the SAME literal id="searchValue" -- the target of the label on the Search Value column -- and seven copies in this source are not a duplicate-id bug. FinanceControlLabellingTest asserts the two counts stay equal, so an eighth branch cannot be added without one. --}}
        searchInputContainer.innerHTML = '';

        if (selectedField === 'id') {
            searchInputContainer.innerHTML = `<input type="number" name="search_value" id="searchValue" class="form-control" placeholder="{{ __('Enter Transaction ID...') }}" value="${currentSearchValueHtml || ''}" min="1">`;
        } else if (selectedField === 'created_at') {
            searchInputContainer.innerHTML = `<input type="date" name="search_value" id="searchValue" class="form-control" value="${currentSearchValueHtml || ''}">`;
        } else if (selectedField === 'type') {
            searchInputContainer.innerHTML = `
                <select name="search_value" id="searchValue" class="form-select">
                    <option value="">{{ __('All Types') }}</option>
                    <option value="sale" ${currentSearchValue === 'sale' ? 'selected' : ''}>{{ __('Sale') }}</option>
                    <option value="manual_deposit" ${currentSearchValue === 'manual_deposit' ? 'selected' : ''}>{{ __('Deposit') }}</option>
                    <option value="manual_withdrawal" ${currentSearchValue === 'manual_withdrawal' ? 'selected' : ''}>{{ __('Withdrawal') }}</option>
                    <option value="order_paid" ${currentSearchValue === 'order_paid' ? 'selected' : ''}>{{ __('Order Paid') }}</option>
                    <option value="order_payment_reversed" ${currentSearchValue === 'order_payment_reversed' ? 'selected' : ''}>{{ __('Order Payment Reversed') }}</option>
                    <option value="order_cancelled" ${currentSearchValue === 'order_cancelled' ? 'selected' : ''}>{{ __('Order Cancelled') }}</option>
                    <option value="order_refunded" ${currentSearchValue === 'order_refunded' ? 'selected' : ''}>{{ __('Order Refunded') }}</option>
                    <option value="withdrawal_request" ${currentSearchValue === 'withdrawal_request' ? 'selected' : ''}>{{ __('Withdrawal Request') }}</option>
                    <option value="withdrawal_paid" ${currentSearchValue === 'withdrawal_paid' ? 'selected' : ''}>{{ __('Withdrawal Paid') }}</option>
                    <option value="withdrawal_refund" ${currentSearchValue === 'withdrawal_refund' ? 'selected' : ''}>{{ __('Withdrawal Refund') }}</option>
                    <option value="commission" ${currentSearchValue === 'commission' ? 'selected' : ''}>{{ __('Commission') }}</option>
                    <option value="adjustment" ${currentSearchValue === 'adjustment' ? 'selected' : ''}>{{ __('Adjustment') }}</option>
                </select>`;
        } else if (selectedField === 'description') {
            searchInputContainer.innerHTML = `<input type="text" name="search_value" id="searchValue" class="form-control" placeholder="{{ __('Enter description...') }}" value="${currentSearchValueHtml || ''}">`;
        } else if (selectedField === 'currency_code') {
            searchInputContainer.innerHTML = `<input type="text" name="search_value" id="searchValue" class="form-control" placeholder="{{ __('e.g., USD, SYP, EUR') }}" value="${currentSearchValueHtml || ''}" style="text-transform: uppercase;">`;
        } else if (selectedField === 'credit' || selectedField === 'debit' || selectedField === 'balance_before' || selectedField === 'balance_after') {
            searchInputContainer.innerHTML = `<input type="number" name="search_value" id="searchValue" class="form-control" placeholder="{{ __('Enter amount...') }}" value="${currentSearchValueHtml || ''}" min="0" step="0.01">`;
        } else if (selectedField === 'status') {
            searchInputContainer.innerHTML = `
                <select name="search_value" id="searchValue" class="form-select">
                    <option value="">{{ __('All Statuses') }}</option>
                    <option value="completed" ${currentSearchValue === 'completed' ? 'selected' : ''}>{{ __('Completed') }}</option>
                    <option value="pending" ${currentSearchValue === 'pending' ? 'selected' : ''}>{{ __('Pending') }}</option>
                    <option value="cancelled" ${currentSearchValue === 'cancelled' ? 'selected' : ''}>{{ __('Cancelled') }}</option>
                </select>`;
        }
    });

    if (searchField.value) {
        searchField.dispatchEvent(new Event('change'));
    }
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('search_field') || urlParams.has('search_value') || urlParams.has('from_date') || urlParams.has('to_date') || urlParams.has('type') || urlParams.has('status')) {
        const transactionsTab = new bootstrap.Tab(document.querySelector('#transactions-tab'));
        transactionsTab.show();
    }
});
</script>
@endpush