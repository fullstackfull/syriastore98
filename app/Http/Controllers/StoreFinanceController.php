<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\StoreWithdrawal;
use App\Services\OrderFinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreFinanceController extends Controller
{
    /**
     * Authorize a finance action.
     *
     * Routed through the `finance` gate (AuthServiceProvider), NOT through
     * User::isAbleTo() directly. The difference is the whole point:
     *
     *   isAbleTo('Add Finance Balance')  -- false for EVERY user on a database
     *                                       built from source, because
     *                                       Utility::addNewData() never creates
     *                                       any permission row containing
     *                                       "Finance", and Laratrust has no
     *                                       super-admin bypass.
     *   Gate::allows('finance', $p)      -- true for a super admin regardless of
     *                                       seeding, and the normal Laratrust
     *                                       answer for everyone else.
     *
     * Verified by execution on a fresh sqlite schema: isAbleTo() returned false
     * for a super admin on all six finance names while this gate returned true.
     */
    protected function canFinance(string $permission): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('finance', $permission);
    }

    /**
     * THE PERMISSIONS A WITHDRAWAL STATE CHANGE REQUIRES -- all of them.
     *
     * NEW-withdrawal-view-permission-authorises-state-change. approveWithdrawal,
     * rejectWithdrawal and payWithdrawal were each gated on the single
     * permission 'View Finance Withdrawal Requests', in the route file AND here
     * in the controller. That is a VIEW permission authorising three
     * state-changing, money-moving actions: whoever could READ the withdrawal
     * list could APPROVE and PAY. Read must not imply write.
     *
     * 'Edit Finance Payment Status' is added rather than a new name because a
     * name absent from Utility::addNewData() cannot exist as a ROW, and this
     * controller is the exact place that lesson was learned: all six finance
     * names were gated on while none was seeded, so every finance mutator was
     * 403 for every user including the platform operator. A seventh unseeded
     * name would recreate that lockout for delegated users. Of the six seeded
     * names (Utility.php:2881-2886) it is the only one denoting a change to a
     * finance record's payment state, which is precisely what approving,
     * rejecting and paying a withdrawal is.
     *
     * A dedicated 'Approve Finance Withdrawal' permission remains the clean
     * split and is reported as an owner decision, because it needs a seeder
     * entry in a file this workstream does not own.
     *
     * ONE SOURCE OF TRUTH. routes/web.php builds its `finance.permission:`
     * parameter list from this constant, so the route-level guard and the
     * controller-level guard cannot drift apart -- which is how approve/reject/
     * pay came to have the route guard and the controller guard disagree in the
     * first place.
     */
    public const WITHDRAWAL_REVIEW_PERMISSIONS = [
        'View Finance Withdrawal Requests',
        'Edit Finance Payment Status',
    ];

    /**
     * ALL-OF. Returns false on the first permission the caller lacks.
     *
     * Deliberately not a loose `||`: the whole defect being repaired is a single
     * weak permission standing in for a stronger one.
     */
    protected function canFinanceAll(array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->canFinance($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tenant whose finance records this request may touch.
     *
     * Returned separately from the authorization decision so that "you may not
     * do this" (403) stays distinguishable from "no store is selected", which
     * previously surfaced as a confusing ModelNotFound 404 from a
     * `where('store_id', null)` lookup.
     */
    protected function financeStoreId()
    {
        $storeId = getCurrentStore();

        return empty($storeId) ? null : $storeId;
    }

    /**
     * JSON refusal for the /api/admin/wallet/* endpoints, which reach this
     * controller through `custom.auth` and must never emit an HTML redirect.
     */
    protected function financeApiDenied(string $message, int $status = 403)
    {
        return response()->json([
            'status'  => 0,
            'message' => 'fail',
            'data'    => ['message' => $message],
        ], $status);
    }

    public function updateFinancialPaymentStatus(Request $request, $id)
    {
        if (!$this->canFinance('Edit Finance Payment Status')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'payment_status' => 'required|in:paid,unpaid',
        ]);

        $storeId = getCurrentStore();

        $financial = DB::table('store_order_financials')
            ->where('id', $id)
            ->where('store_id', $storeId)
            ->first();

        if (!$financial) {
            return back()->with(
                'error',
                __('Financial record not found.')
            );
        }

        try {

            if ($request->payment_status === 'paid') {

                app(OrderFinancialService::class)
                    ->markFinancialAsPaid((int) $financial->id);

            } else {

                app(OrderFinancialService::class)
                    ->markFinancialAsUnpaid((int) $financial->id);
            }

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Financial payment status updated successfully.')
        );
    }


    public function index(Request $request)
    {
        if (!$this->canFinance('View Finance Dashboard')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = getCurrentStore();

        $currencyCode = Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD';

        $wallet = StoreWallet::where(
            'store_id',
            $storeId
        )->first();

        if (!$wallet) {

            $wallet = StoreWallet::create([
                'store_id' => $storeId,
                'currency_code' => $currencyCode,
                'available_balance' => 0,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]);
        }

        $query = StoreWalletTransaction::where(
            'store_id',
            $storeId
        );

        // ==========================================
        // 🔍 معالجة البحث الذكي حسب الحقل المحدد
        // ==========================================
        if ($request->filled('search_field') && $request->filled('search_value')) {
            $field = $request->search_field;
            $value = $request->search_value;

            switch ($field) {
                case 'id':
                    $query->where('id', $value);
                    break;

                case 'created_at':
                    $query->whereDate('created_at', $value);
                    break;

                case 'type':
                    $query->where('type', $value);
                    break;

                case 'description':
                    $query->where('description', 'LIKE', "%{$value}%");
                    break;

                case 'currency_code':
                    $query->where('currency_code', 'LIKE', '%' . strtoupper($value) . '%');
                    break;

                case 'credit':
                    $query->where('direction', 'credit')
                          ->where('amount', $value);
                    break;

                case 'debit':
                    $query->where('direction', 'debit')
                          ->where('amount', $value);
                    break;

                case 'balance_before':
                    $query->where('balance_before', $value);
                    break;

                case 'balance_after':
                    $query->where('balance_after', $value);
                    break;

                case 'status':
                    $query->where('status', $value);
                    break;
            }
        }

        // ==========================================
        // 📅 معالجة الفلاتر التقليدية (التاريخ، النوع، الحالة)
        // ==========================================
        if ($request->filled('from_date')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->from_date
            );
        }

        if ($request->filled('to_date')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->to_date
            );
        }

        if ($request->filled('type')) {
            $query->where(
                'type',
                $request->type
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        $transactions = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $orderFinancials = DB::table(
            'store_order_financials'
        )
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(
                15,
                ['*'],
                'orders_page'
            )
            ->withQueryString();

        /*
         |--------------------------------------------------------------------------
         | Inventory
         |--------------------------------------------------------------------------
         */

        $products = Product::where(
            'store_id',
            $storeId
        )->get();

        $inventoryUnits = 0;
        $inventoryCost = 0;
        $inventoryRetailValue = 0;

        foreach ($products as $product) {

            $costPrice =
                (float) ($product->cost_price ?? 0);

            if ($product->variant_product == 1) {

                $variants = ProductVariant::where(
                        'product_id',
                        $product->id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->get();

                foreach ($variants as $variant) {

                    $stock =
                        (int) ($variant->stock ?? 0);

                    if ($stock <= 0) {
                        continue;
                    }

                    $inventoryUnits += $stock;

                    $inventoryCost +=
                        ($costPrice * $stock);

                    $sellingPrice = (float) (
                        $variant->variation_price
                        ?? $variant->price
                        ?? 0
                    );

                    $inventoryRetailValue +=
                        ($sellingPrice * $stock);
                }

            } else {

                $stock =
                    (int) ($product->product_stock ?? 0);

                if ($stock <= 0) {
                    continue;
                }

                $inventoryUnits += $stock;

                $inventoryCost +=
                    ($costPrice * $stock);

                $sellingPrice = (float) (
                    $product->sale_price
                    ?? $product->price
                    ?? 0
                );

                $inventoryRetailValue +=
                    ($sellingPrice * $stock);
            }
        }

        $inventoryCost =
            round($inventoryCost, 2);

        $inventoryRetailValue =
            round($inventoryRetailValue, 2);

        $inventoryPotentialProfit = round(
            $inventoryRetailValue - $inventoryCost,
            2
        );

        return view(
            'finance.index',
            compact(
                'wallet',
                'transactions',
                'orderFinancials',
                'inventoryUnits',
                'inventoryCost',
                'inventoryRetailValue',
                'inventoryPotentialProfit'
            )
        );
    }


    public function addBalance(Request $request)
    {
        if (!$this->canFinance('Add Finance Balance')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }


        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' =>
                'required|string|max:255',
        ]);

        $storeId = getCurrentStore();

        DB::transaction(function () use (
            $request,
            $storeId
        ) {

            $wallet = StoreWallet::where(
                    'store_id',
                    $storeId
                )
                ->lockForUpdate()
                ->firstOrFail();

            $currencyCode =
                $wallet->currency_code
                ?? 'USD';

            $before =
                (float) $wallet->available_balance;

            $amount =
                (float) $request->amount;

            $after = $before + $amount;

            $wallet->available_balance = $after;

            $wallet->total_income =
                (float) $wallet->total_income
                + $amount;

            $wallet->save();

            StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,

                'type' => 'manual_deposit',
                'direction' => 'credit',

                'amount' => $amount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'manual',
                'reference_id' => null,

                'description' =>
                    $request->description,

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Balance added successfully.')
            );
    }


    public function deductBalance(Request $request)
    {
        if (!$this->canFinance('Deduct Finance Balance')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' =>
                'required|string|max:255',
        ]);

        $storeId = getCurrentStore();

        try {

            DB::transaction(function () use (
                $request,
                $storeId
            ) {

                $wallet = StoreWallet::where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $before =
                    (float) $wallet->available_balance;

                $amount =
                    (float) $request->amount;

                if ($amount > $before) {
                    throw new \Exception(
                        __('Insufficient wallet balance.')
                    );
                }

                $after = $before - $amount;

                $wallet->available_balance = $after;

                $wallet->total_withdrawn =
                    (float) $wallet->total_withdrawn
                    + $amount;

                $wallet->save();

                StoreWalletTransaction::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $currencyCode,

                    'type' => 'manual_withdrawal',
                    'direction' => 'debit',

                    'amount' => $amount,

                    'balance_before' => $before,
                    'balance_after' => $after,

                    'reference_type' => 'manual',
                    'reference_id' => null,

                    'description' =>
                        $request->description,

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            return redirect()
                ->route('finance.index')
                ->with(
                    'error',
                    $e->getMessage()
                );
        }

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Balance deducted successfully.')
            );
    }


    public function requestWithdrawal(Request $request)
    {
        if (!$this->canFinance('Request Finance Withdrawal')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' =>
                'required|numeric|min:0.01',
            'payment_method' =>
                'required|string|max:100',
            'payment_details' =>
                'required|string|max:2000',
            'store_note' =>
                'nullable|string|max:500',
        ]);

        $storeId = getCurrentStore();

        try {

            DB::transaction(function () use (
                $request,
                $storeId
            ) {

                $wallet = StoreWallet::where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $request->amount;

                if (
                    $amount >
                    (float) $wallet->available_balance
                ) {
                    throw new \Exception(
                        __('Insufficient available balance.')
                    );
                }

                $before =
                    (float) $wallet->available_balance;

                $wallet->available_balance -=
                    $amount;

                $wallet->withdrawal_pending_balance +=
                    $amount;

                $wallet->save();

                $withdrawal = StoreWithdrawal::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,

                    'amount' => $amount,

                    'status' => 'pending',

                    'payment_method' =>
                        $request->payment_method,

                    'payment_details' =>
                        $request->payment_details,

                    'store_note' =>
                        $request->store_note,

                    'requested_by' =>
                        auth()->id(),

                    'requested_at' => now(),
                ]);

                StoreWalletTransaction::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $currencyCode,

                    'type' => 'withdrawal_request',
                    'direction' => 'debit',

                    'amount' => $amount,

                    'balance_before' => $before,
                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' => 'withdrawal',
                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Withdrawal request #' .
                        $withdrawal->id,

                    'status' => 'pending',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            return redirect()
                ->route('finance.index')
                ->with(
                    'error',
                    $e->getMessage()
                );
        }

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Withdrawal request submitted successfully.')
            );
    }


    public function withdrawals()
    {
        if (!$this->canFinance('View Finance Withdrawal Requests')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $storeId = getCurrentStore();

        $withdrawals = StoreWithdrawal::with(
                'wallet'
            )
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(25);

        return view(
            'finance.withdrawals',
            compact('withdrawals')
        );
    }


    public function approveWithdrawal($id)
    {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        try {

            DB::transaction(function () use (
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $withdrawal->status !== 'pending'
                ) {
                    throw new \Exception(
                        'Withdrawal request is not pending.'
                    );
                }

                $withdrawal->status = 'approved';
                $withdrawal->reviewed_by =
                    auth()->id();
                $withdrawal->reviewed_at = now();

                $withdrawal->save();

                StoreWalletTransaction::where(
                        'store_id',
                        $storeId
                    )
                    ->where(
                        'reference_type',
                        'withdrawal'
                    )
                    ->where(
                        'reference_id',
                        $withdrawal->id
                    )
                    ->where(
                        'type',
                        'withdrawal_request'
                    )
                    ->update([
                        'status' => 'completed',
                        'updated_at' => now(),
                    ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal approved.')
        );
    }


    public function rejectWithdrawal(
        Request $request,
        $id
    ) {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        $request->validate([
            'admin_note' =>
                'nullable|string|max:500',
        ]);

        try {

            DB::transaction(function () use (
                $request,
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    !in_array(
                        $withdrawal->status,
                        ['pending', 'approved']
                    )
                ) {
                    throw new \Exception(
                        'This withdrawal cannot be rejected.'
                    );
                }

                $wallet = StoreWallet::where(
                        'id',
                        $withdrawal->wallet_id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $withdrawal->amount;

                if (
                    (float) $wallet
                        ->withdrawal_pending_balance
                    < $amount
                ) {
                    throw new \Exception(
                        'Invalid withdrawal pending balance.'
                    );
                }

                $before =
                    (float) $wallet->available_balance;

                $wallet
                    ->withdrawal_pending_balance -=
                    $amount;

                $wallet->available_balance +=
                    $amount;

                $wallet->save();

                $withdrawal->status = 'rejected';

                $withdrawal->admin_note =
                    $request->admin_note;

                $withdrawal->reviewed_by =
                    auth()->id();

                $withdrawal->reviewed_at = now();

                $withdrawal->save();

                StoreWalletTransaction::create([
                    'store_id' =>
                        $withdrawal->store_id,

                    'wallet_id' =>
                        $wallet->id,

                    'currency_code' =>
                        $currencyCode,

                    'type' =>
                        'withdrawal_refund',

                    'direction' => 'credit',

                    'amount' => $amount,

                    'balance_before' => $before,

                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' =>
                        'withdrawal',

                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Rejected withdrawal #' .
                        $withdrawal->id .
                        ' returned to wallet',

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);

                StoreWalletTransaction::where(
                        'store_id',
                        $storeId
                    )
                    ->where(
                        'reference_type',
                        'withdrawal'
                    )
                    ->where(
                        'reference_id',
                        $withdrawal->id
                    )
                    ->where(
                        'type',
                        'withdrawal_request'
                    )
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now(),
                    ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal rejected and balance returned.')
        );
    }


    public function payWithdrawal($id)
    {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        try {

            DB::transaction(function () use (
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $withdrawal->status !== 'approved'
                ) {
                    throw new \Exception(
                        'Withdrawal must be approved first.'
                    );
                }

                $wallet = StoreWallet::where(
                        'id',
                        $withdrawal->wallet_id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $withdrawal->amount;

                if (
                    (float) $wallet
                        ->withdrawal_pending_balance
                    < $amount
                ) {
                    throw new \Exception(
                        'Invalid withdrawal pending balance.'
                    );
                }

                $wallet
                    ->withdrawal_pending_balance -=
                    $amount;

                $wallet->total_withdrawn =
                    (float) $wallet->total_withdrawn
                    + $amount;

                $wallet->save();

                $withdrawal->status = 'paid';
                $withdrawal->paid_at = now();

                $withdrawal->save();

                StoreWalletTransaction::create([
                    'store_id' =>
                        $withdrawal->store_id,

                    'wallet_id' =>
                        $wallet->id,

                    'currency_code' =>
                        $currencyCode,

                    'type' => 'withdrawal_paid',

                    'direction' => 'debit',

                    'amount' => $amount,

                    /*
                     * available balance لم يتغير هون
                     * لأنه انحجز من وقت الطلب.
                     */
                    'balance_before' =>
                        $wallet->available_balance,

                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' =>
                        'withdrawal',

                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Withdrawal #' .
                        $withdrawal->id .
                        ' paid',

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal marked as paid.')
        );
    }
    
    ///api
    
    
    
public function walletTransactionsApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        // /api/admin/wallet/transactions reaches this method through
        // `custom.auth` only -- api.php applies no permission middleware -- so
        // before this check any authenticated token holder could read any
        // store's wallet ledger that they owned. The ownership test below
        // bounded WHICH store; nothing bounded WHETHER the caller may read
        // wallet data at all.
        if (!$this->canFinance('View Finance Dashboard')) {
            return $this->financeApiDenied(
                'Permission denied. You do not have permission to view wallet transactions.'
            );
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $wallet = \App\Models\StoreWallet::where('store_id', $storeId)->first();

        if (!$wallet) {
            $wallet = \App\Models\StoreWallet::create([
                'store_id' => $storeId,
                'currency_code' => 'USD',
                'available_balance' => 0,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]);
        }

        $currencyCode = $wallet->currency_code ?? 'USD';

        $currencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

        $query = \App\Models\StoreWalletTransaction::where('store_id', $storeId);

        if ($request->filled('search_field') && $request->filled('search_value')) {
            $field = $request->search_field;
            $value = $request->search_value;

            switch ($field) {
                case 'id':
                    $query->where('id', $value);
                    break;

                case 'type':
                    $query->where('type', $value);
                    break;

                case 'description':
                    $query->where('description', 'LIKE', "%{$value}%");
                    break;

                case 'status':
                    $query->where('status', $value);
                    break;
            }
        }

        if ($request->filled('from_date')) {
            $validator = \Validator::make(['from_date' => $request->from_date], [
                'from_date' => 'required|date|date_format:Y-m-d'
            ]);
            
            if (!$validator->fails()) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
        }

        if ($request->filled('to_date')) {
            $validator = \Validator::make(['to_date' => $request->to_date], [
                'to_date' => 'required|date|date_format:Y-m-d'
            ]);
            
            if (!$validator->fails()) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'status' => 1,
            'message' => 'Wallet data retrieved successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'available_balance' => number_format((float) $wallet->available_balance, 2),
                'currency_code' => $currencyCode,          // ✅ كود العملة الحالي
                'currency_symbol' => $currencySymbol,      // ✅ رمز العملة الديناميكي والصحيح
                'filters_applied' => [
                    'from_date' => $request->from_date ?? null,
                    'to_date' => $request->to_date ?? null,
                    'type' => $request->type ?? null,
                    'search_field' => $request->search_field ?? null,
                    'search_value' => $request->search_value ?? null,
                ],
                'transactions' => $transactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'direction' => $transaction->direction,
                                                'currency_code' => $transaction->currency_code,
                        'amount' => number_format((float) $transaction->amount, 2),
                        'balance_before' => number_format((float) $transaction->balance_before, 2),
                        'balance_after' => number_format((float) $transaction->balance_after, 2),
                        'description' => $transaction->description,
                        'status' => $transaction->status,
                        'created_at' => $transaction->created_at->toDateTimeString(),
                    ];
                }),
                'pagination' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'has_more' => $transactions->hasMorePages(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Wallet Transactions Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve wallet data: ' . $e->getMessage()
            ]
        ], 500);
    }
}    
    
    public function addBalanceApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        if (!$this->canFinance('Add Finance Balance')) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Permission denied. You do not have permission to add balance.']
            ], 403);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        \Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ])->validate();

        $result = DB::transaction(function () use ($request, $storeId, $adminUser) {
            
            $wallet = \App\Models\StoreWallet::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $currencyCode = \App\Models\Setting::where('store_id', $storeId)
                    ->where('name', 'defult_currancy')
                    ->value('value') ?? 'USD';

                $wallet = \App\Models\StoreWallet::create([
                    'store_id' => $storeId,
                    'currency_code' => $currencyCode,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'withdrawal_pending_balance' => 0,
                    'total_income' => 0,
                    'total_withdrawn' => 0,
                ]);
            }

            $currencyCode = $wallet->currency_code ?? 'USD';
            $before = (float) $wallet->available_balance;
            $amount = (float) $request->amount;
            $after = $before + $amount;

            $wallet->available_balance = $after;
            $wallet->total_income = (float) $wallet->total_income + $amount;
            $wallet->save();

            $transaction = \App\Models\StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,
                'type' => 'manual_deposit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $request->description,
                'status' => 'completed',
                'created_by' => $adminUser->id,
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Balance added successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'wallet' => [
                    'id' => $result['wallet']->id,
                    'available_balance' => number_format((float) $result['wallet']->available_balance, 2),
                    'total_income' => number_format((float) $result['wallet']->total_income, 2),
                    'currency_code' => $result['wallet']->currency_code ?? 'USD',
                ],
                'transaction' => [
                    'id' => $result['transaction']->id,
                    'type' => $result['transaction']->type,
                    'direction' => $result['transaction']->direction,
                    'amount' => number_format((float) $result['transaction']->amount, 2),
                    'balance_before' => number_format((float) $result['transaction']->balance_before, 2),
                    'balance_after' => number_format((float) $result['transaction']->balance_after, 2),
                    'description' => $result['transaction']->description,
                    'status' => $result['transaction']->status,
                    'created_at' => $result['transaction']->created_at->toDateTimeString(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Add Balance Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to add balance: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function deductBalanceApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        // THE EMPTY GAP. addBalanceApi checks 'Add Finance Balance' at the
        // equivalent point; these three blank lines are where the matching
        // check for the DEDUCT endpoint should always have been. Deducting is
        // the more dangerous of the two -- it removes merchant funds -- and it
        // was the one left unguarded.
        if (!$this->canFinance('Deduct Finance Balance')) {
            return $this->financeApiDenied(
                'Permission denied. You do not have permission to deduct balance.'
            );
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ]);

        $result = DB::transaction(function () use ($request, $storeId, $adminUser) {
            
            $wallet = \App\Models\StoreWallet::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new \Exception(__('Wallet not found for this store.'));
            }

            $currencyCode = $wallet->currency_code ?? 'USD';
            $before = (float) $wallet->available_balance;
            $amount = (float) $request->amount;

            if ($amount > $before) {
                throw new \Exception(__('Insufficient wallet balance.'));
            }

            $after = $before - $amount;

            $wallet->available_balance = $after;
            $wallet->total_withdrawn = (float) $wallet->total_withdrawn + $amount;
            $wallet->save();

            $transaction = \App\Models\StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,
                'type' => 'manual_withdrawal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $request->description,
                'status' => 'completed',
                'created_by' => $adminUser->id,
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Balance deducted successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'wallet' => [
                    'id' => $result['wallet']->id,
                    'available_balance' => number_format((float) $result['wallet']->available_balance, 2),
                    'total_withdrawn' => number_format((float) $result['wallet']->total_withdrawn, 2),
                    'currency_code' => $result['wallet']->currency_code ?? 'USD',
                ],
                'transaction' => [
                    'id' => $result['transaction']->id,
                    'type' => $result['transaction']->type,
                    'direction' => $result['transaction']->direction,
                    'amount' => number_format((float) $result['transaction']->amount, 2),
                    'balance_before' => number_format((float) $result['transaction']->balance_before, 2),
                    'balance_after' => number_format((float) $result['transaction']->balance_after, 2),
                    'description' => $result['transaction']->description,
                    'status' => $result['transaction']->status,
                    'created_at' => $result['transaction']->created_at->toDateTimeString(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Deduct Balance Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to deduct balance: ' . $e->getMessage()
            ]
        ], 500);
    }
}}