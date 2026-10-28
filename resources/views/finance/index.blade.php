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
<div class="row">
    <div class="d-flex gap-2 mb-3 flex-wrap">
        
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
    <div class="col-xl col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Available Balance') }}</h6>
                <h3 class="mb-0">{{ number_format($wallet->available_balance, 2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Pending Withdrawals') }}</h6>
                <h3 class="mb-0">{{ number_format($wallet->withdrawal_pending_balance, 2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Pending Balance') }}</h6>
                <h3 class="mb-0">{{ number_format($wallet->pending_balance, 2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Total Income') }}</h6>
                <h3 class="mb-0">{{ number_format($wallet->total_income, 2) }}</h3>
            </div>
        </div>
    </div>
    <div class="col-xl col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="text-muted mb-2">{{ __('Total Withdrawn') }}</h6>
                <h3 class="mb-0">{{ number_format($wallet->total_withdrawn, 2) }}</h3>
            </div>
        </div>
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
                <h4 class="mb-0 {{ $inventoryPotentialProfit >= 0 ? 'text-success' : 'text-danger' }}">
                    {{ number_format($inventoryPotentialProfit, 2) }}
                </h4>
            </div>
        </div>
    </div>
</div>

{{-- نظام Tabs المحسن --}}
<div class="card">
    <div class="card-header bg-white border-bottom-0 pt-3 pb-0">
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
                                    <label class="form-label fw-bold text-muted smart-search-label">
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
                                    <label class="form-label fw-bold text-muted smart-search-label">
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
                                    <label class="form-label fw-bold">{{ __('From Date') }}</label>
                                    <input type="date" name="from_date" class="form-control" value="{{ request('from_date') }}">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">{{ __('To Date') }}</label>
                                    <input type="date" name="to_date" class="form-control" value="{{ request('to_date') }}">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold">{{ __('Type') }}</label>
                                    <select name="type" class="form-control">
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
                                    <label class="form-label fw-bold">{{ __('Status') }}</label>
                                    <select name="status" class="form-control">
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
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
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
                                                <span class="badge bg-light text-dark border">
                                                    {{ ucwords(str_replace('_', ' ', $transaction->type)) }}
                                                </span>
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
                                                    <span class="text-success fw-bold">+{{ number_format($transaction->amount, 2) }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if($transaction->direction == 'debit')
                                                    <span class="text-danger fw-bold">-{{ number_format($transaction->amount, 2) }}</span>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="text-end text-muted">{{ number_format($transaction->balance_before, 2) }}</td>
                                            <td class="text-end fw-bold">{{ number_format($transaction->balance_after, 2) }}</td>
                                            <td class="text-center">
                                                @if($transaction->status == 'completed')
                                                    <span class="badge bg-success bg-opacity-10 text-success">{{ __('Completed') }}</span>
                                                @elseif($transaction->status == 'pending')
                                                    <span class="badge bg-warning bg-opacity-10 text-warning">{{ __('Pending') }}</span>
                                                @else
                                                    <span class="badge bg-danger bg-opacity-10 text-danger">{{ __('Cancelled') }}</span>
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
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
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
                                            <td>{{ number_format($financial->product_amount, 2) }}</td>
                                            <td class="text-danger">-{{ number_format($financial->discount_amount, 2) }}</td>
                                            <td>{{ number_format($financial->tax_amount, 2) }}</td>
                                            <td>{{ number_format($financial->shipping_amount, 2) }}</td>
                                            <td><strong>{{ number_format($financial->customer_total, 2) }}</strong></td>
                                            <td>{{ number_format($financial->product_cost, 2) }}</td>
                                            <td>
                                                @if($financial->gross_profit >= 0)
                                                    <span class="text-success fw-bold">{{ number_format($financial->gross_profit, 2) }}</span>
                                                @else
                                                    <span class="text-danger fw-bold">{{ number_format($financial->gross_profit, 2) }}</span>
                                                @endif
                                            </td>
                                            <td>{{ number_format($financial->paid_amount, 2) }}</td>
                                            <td>{{ number_format($financial->remaining_amount, 2) }}</td>
                                            <td>
                                                {{-- ✅ حماية زر تحديث حالة الدفع --}}
                                                @if(auth()->user()->isAbleTo('Edit Finance Payment Status'))
                                                    <form method="POST" action="{{ route('finance.order-financial.status', $financial->id) }}">
                                                        @csrf
                                                        <div class="d-flex gap-2 align-items-center">
                                                            <select name="payment_status" class="form-control form-control-sm" style="min-width: 130px;">
                                                                <option value="unpaid" {{ $financial->payment_status === 'unpaid' ? 'selected' : '' }}>{{ __('Not Received') }}</option>
                                                                <option value="paid" {{ $financial->payment_status === 'paid' ? 'selected' : '' }}>{{ __('Received') }}</option>
                                                            </select>
                                                            <button type="submit" class="btn btn-sm btn-primary">{{ __('Update') }}</button>
                                                        </div>
                                                    </form>
                                                @else
                                                    @if($financial->payment_status === 'paid')
                                                        <span class="badge bg-success bg-opacity-10 text-success">{{ __('Received') }}</span>
                                                    @else
                                                        <span class="badge bg-danger bg-opacity-10 text-danger">{{ __('Not Received') }}</span>
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

{{-- ==================== MODALS ==================== --}}

{{-- ✅ Modal: Add Balance --}}
@if(auth()->user()->isAbleTo('Add Finance Balance'))
<div class="modal fade" id="addBalanceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('finance.add.balance') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Add Balance') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">{{ __('Amount') }}</label>
                        <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Reason / Description') }}</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
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
        <div class="modal-content">
            <form method="POST" action="{{ route('finance.deduct.balance') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Deduct Balance') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        {{ __('Available balance:') }} <strong>{{ number_format($wallet->available_balance, 2) }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Amount') }}</label>
                        <input type="number" name="amount" class="form-control" min="0.01" max="{{ $wallet->available_balance }}" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Reason / Description') }}</label>
                        <textarea name="description" class="form-control" rows="3" required></textarea>
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
        <div class="modal-content">
            <form method="POST" action="{{ route('finance.withdrawal.request') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Request Withdrawal') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        {{ __('Available Balance') }}: <strong>{{ number_format($wallet->available_balance, 2) }}</strong>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Amount') }}</label>
                        <input type="number" name="amount" class="form-control" min="0.01" max="{{ $wallet->available_balance }}" step="0.01" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Payment Method') }}</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="">{{ __('Select') }}</option>
                            <option value="bank">{{ __('Bank Transfer') }}</option>
                            <option value="cash">{{ __('Cash') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Payment Details') }}</label>
                        <textarea name="payment_details" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Note') }}</label>
                        <textarea name="store_note" class="form-control" rows="2"></textarea>
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
    @endphp
    const currentSearchValue = {!! json_encode($currentSearchValue) !!};

    searchField.addEventListener('change', function() {
        const selectedField = this.value;

        if (!selectedField) {
            searchValueContainer.style.display = 'none';
            searchButtonContainer.style.display = 'none';
            return;
        }

        searchValueContainer.style.display = 'block';
        searchButtonContainer.style.display = 'block';
        searchInputContainer.innerHTML = '';

        if (selectedField === 'id') {
            searchInputContainer.innerHTML = `<input type="number" name="search_value" class="form-control" placeholder="{{ __('Enter Transaction ID...') }}" value="${currentSearchValue || ''}" min="1">`;
        } else if (selectedField === 'created_at') {
            searchInputContainer.innerHTML = `<input type="date" name="search_value" class="form-control" value="${currentSearchValue || ''}">`;
        } else if (selectedField === 'type') {
            searchInputContainer.innerHTML = `
                <select name="search_value" class="form-select">
                    <option value="">{{ __('All Types') }}</option>
                    <option value="sale" ${currentSearchValue === 'sale' ? 'selected' : ''}>{{ __('Sale') }}</option>
                    <option value="manual_deposit" ${currentSearchValue === 'manual_deposit' ? 'selected' : ''}>{{ __('Deposit') }}</option>
                    <option value="manual_withdrawal" ${currentSearchValue === 'manual_withdrawal' ? 'selected' : ''}>{{ __('Withdrawal') }}</option>
                    <option value="order_paid" ${currentSearchValue === 'order_paid' ? 'selected' : ''}>{{ __('Order Paid') }}</option>
                    <option value="order_payment_reversed" ${currentSearchValue === 'order_payment_reversed' ? 'selected' : ''}>{{ __('Order Payment Reversed') }}</option>
                    <option value="withdrawal_request" ${currentSearchValue === 'withdrawal_request' ? 'selected' : ''}>{{ __('Withdrawal Request') }}</option>
                    <option value="withdrawal_paid" ${currentSearchValue === 'withdrawal_paid' ? 'selected' : ''}>{{ __('Withdrawal Paid') }}</option>
                    <option value="withdrawal_refund" ${currentSearchValue === 'withdrawal_refund' ? 'selected' : ''}>{{ __('Withdrawal Refund') }}</option>
                    <option value="commission" ${currentSearchValue === 'commission' ? 'selected' : ''}>{{ __('Commission') }}</option>
                    <option value="adjustment" ${currentSearchValue === 'adjustment' ? 'selected' : ''}>{{ __('Adjustment') }}</option>
                </select>`;
        } else if (selectedField === 'description') {
            searchInputContainer.innerHTML = `<input type="text" name="search_value" class="form-control" placeholder="{{ __('Enter description...') }}" value="${currentSearchValue || ''}">`;
        } else if (selectedField === 'currency_code') {
            searchInputContainer.innerHTML = `<input type="text" name="search_value" class="form-control" placeholder="{{ __('e.g., USD, SYP, EUR') }}" value="${currentSearchValue || ''}" style="text-transform: uppercase;">`;
        } else if (selectedField === 'credit' || selectedField === 'debit' || selectedField === 'balance_before' || selectedField === 'balance_after') {
            searchInputContainer.innerHTML = `<input type="number" name="search_value" class="form-control" placeholder="{{ __('Enter amount...') }}" value="${currentSearchValue || ''}" min="0" step="0.01">`;
        } else if (selectedField === 'status') {
            searchInputContainer.innerHTML = `
                <select name="search_value" class="form-select">
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