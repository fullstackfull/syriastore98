<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\Setting;
use App\Services\OrderEntitlementReversal;
use App\Services\StoreWalletProvisioning;
use Illuminate\Support\Facades\DB;

class OrderFinancialService
{
    /**
     * إنشاء السجل المالي للطلب.
     */
    public function createForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {

            /*
             * P4-F032 -- THIS WAS `firstOrFail()`, AND IT LOST MONEY.
             * =====================================================================================
             *
             * A store whose `store_wallets` row did not exist threw `ModelNotFoundException` from
             * here into `OrderObserver::created()`'s catch. The order committed, no
             * `store_order_financials` row was written at all, and nothing on any path ever
             * revisited it: the merchant's credit for that order was gone permanently. Two store
             * writers created stores in exactly that state -- `RegisteredUserController::store` and
             * `DashboardController::createStoreApi` -- while `StoreController` provisioned one.
             *
             * THE PROVISIONING IS NOT `firstOrCreate`, AND THE REASON IS MEASURED. Laravel's
             * `firstOrCreate()` recovers from a lost race with a PLAIN consistent read, which under
             * REPEATABLE READ is served from the snapshot this transaction took at its first read
             * -- and the read is already behind us by the time any order gets here. Measured on
             * MariaDB 10.11.14/InnoDB: in exactly this shape it RE-THROWS, so the obvious fix would
             * have left the defect in place while looking repaired. `StoreWalletProvisioning` uses
             * a locking re-read instead, which is a current read and does see the winner's row.
             * Evidence: tests/Support/Security/bin/verify-store-wallet-provisioning-on-mysql.php.
             *
             * THE LOCK IS NOT LOST IN THE MOVE. `ensure()` opens with the same
             * `lockForUpdate()->first()` this line used to take, so the balance mutation below is
             * serialised exactly as before.
             *
             * THIS CREDITS NO PAST ORDER. A wallet appearing now does not and must not reach back:
             * the orders that already lost their credit are a separate, approval-gated,
             * read-only reconciliation question.
             */
            $wallet = app(StoreWalletProvisioning::class)->ensure(
                (int) $order->store_id,
                $this->storeCurrencyCode($order)
            );

            /*
             * منع إنشاء السجل مرتين لنفس الطلب
             */
            $exists = DB::table('store_order_financials')
                ->where('order_id', $order->id)
                ->where('store_id', $order->store_id)
                ->exists();

            if ($exists) {
                return;
            }

            $productAmount = (float) ($order->product_price ?? 0);
            $discountAmount = (float) ($order->coupon_price ?? 0);
            $taxAmount = (float) ($order->tax_price ?? 0);
            $shippingAmount = (float) ($order->delivery_price ?? 0);
            $customerTotal = (float) ($order->final_price ?? 0);

            /*
             * Snapshot تكلفة المنتجات وقت الطلب
             */
            $productCost = $this->calculateProductCost($order);

            /*
             * الربح من المنتجات فقط حاليًا.
             */
            $grossProfit =
                ($productAmount - $discountAmount) - $productCost;

            $paymentStatus = $this->normalizePaymentStatus(
                $order->payment_status
            );

            $paidAmount = $paymentStatus === 'paid'
                ? $customerTotal
                : 0;

            $remainingAmount = max(
                0,
                $customerTotal - $paidAmount
            );

            /*
             * Snapshot عملة الطلب وقت إنشائه.
             */
            $currencyCode = Setting::where(
                    'store_id',
                    $order->store_id
                )
                ->where('name', 'defult_currancy')
                ->value('value')
                ?? $wallet->currency_code
                ?? 'USD';

            $currencySymbol = Setting::where(
                    'store_id',
                    $order->store_id
                )
                ->where('name', 'defult_currancy_symbol')
                ->value('value')
                ?? '$';

            /*
             * إنشاء السجل المالي التاريخي.
             */
            DB::table('store_order_financials')->insert([
                'store_id' => $order->store_id,
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,

                'currency_code' => $currencyCode,
                'currency_symbol' => $currencySymbol,

                'product_amount' => $productAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,

                'customer_total' => $customerTotal,

                'product_cost' => $productCost,
                'gross_profit' => $grossProfit,

                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,

                'paid_at' => $paymentStatus === 'paid'
                    ? now()
                    : null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * إذا الطلب مدفوع من البداية:
             * نحرك القيمة فعليًا إلى المحفظة.
             */
            if ($paymentStatus === 'paid') {

                $walletCurrency = $wallet->currency_code
                    ?? $currencyCode;

                /*
                 * إذا لسبب ما عملة الطلب مختلفة
                 * عن عملة المحفظة، نحول قبل الإضافة.
                 */
                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $customerTotal,
                    $currencyCode,
                    $walletCurrency,
                    (int) $order->store_id
                );

                $before = (float) $wallet->available_balance;
                $after = $before + $walletAmount;

                $wallet->available_balance = $after;

                $wallet->total_income =
                    (float) $wallet->total_income
                    + $walletAmount;

                $wallet->save();

                /*
                 * حركة Cash فعلية.
                 */
                StoreWalletTransaction::create([
                    'store_id' => $order->store_id,
                    'wallet_id' => $wallet->id,

                    'currency_code' => $walletCurrency,

                    'type' => 'order_paid',
                    'direction' => 'credit',

                    'amount' => $walletAmount,

                    'balance_before' => $before,
                    'balance_after' => $after,

                    'reference_type' => 'order',
                    'reference_id' => $order->id,

                    'description' =>
                        'Payment received for order #' .
                        $order->product_order_id,

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            }
        });
    }


    /**
     * The store's configured currency, or null when it has never set one.
     *
     * Lifted out so it can be read BEFORE the wallet exists. The `$currencyCode` computation
     * further down falls back to `$wallet->currency_code`, which is unavailable at provisioning
     * time by definition -- so a wallet this service opens is denominated in the store's own
     * currency, and the order that opened it needs no conversion. Returning null rather than
     * 'USD' keeps that fallback decision in `StoreWalletProvisioning`, where it is documented.
     */
    private function storeCurrencyCode(Order $order): ?string
    {
        $value = Setting::where('store_id', $order->store_id)
            ->where('name', 'defult_currancy')
            ->value('value');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }


    /**
     * حساب تكلفة المنتجات وقت إنشاء الطلب.
     */
    private function calculateProductCost(Order $order): float
    {
        $items = json_decode($order->product_json);

        if (empty($items) || !is_iterable($items)) {
            return 0;
        }

        $totalCost = 0;

        foreach ($items as $item) {

            $productId = $item->product_id ?? null;
            $qty = (float) ($item->qty ?? 0);

            if (!$productId || $qty <= 0) {
                continue;
            }

            /*
             * P1-SEC-44 -- THE PRODUCT MUST BELONG TO THE ORDER'S OWN STORE.
             *
             * This was `Product::find($productId)`, and `Product` is NOT in
             * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
             * short-circuits and that call added NO predicate for any principal -- not a
             * customer, not a merchant, not the operator. `$productId` comes out of
             * `orders.product_json`, which is caller-influenced: this repository already records
             * that a cart line naming another merchant's product reaches an order (the S19/S32
             * cross-tenant stock drain).
             *
             * So another store's confidential `cost_price` was multiplied by the quantity the
             * ordering store chose and written into THEIR `store_order_financials.product_cost`
             * and `gross_profit`, which their own finance screen displays. Divide by the quantity
             * and you have a competitor's exact unit cost.
             *
             * MEASURED before the fix: store B's product at `cost_price` 77.00, a store A order
             * naming it with qty 2 -> store A's row carried `product_cost = 154`,
             * `gross_profit = 46`.
             *
             * [CORRECTED 2026-11-02, owner Decision 4. THE PARAGRAPH BELOW IS WRONG AND IS KEPT
             * SO THE CORRECTION HAS SOMETHING TO POINT AT.]
             *
             *     > DOUBLY CREATED BY GATE F, which is why nothing saw it. Until 2026-11-02 the
             *     > persistence threw (`store_order_financials` was declared by no migration)
             *     > into `OrderObserver::created()`'s catch, AND `$product->cost_price` was null
             *     > because `products.cost_price` was undeclared too -- `?? 0` made every cost
             *     > zero. Both became real on the same day.
             *
             * THIS REPOSITORY'S OWN MEASURED PRODUCTION DUMP REFUTES BOTH HALVES, and
             * `docs/GATE-F-VERIFIED-SCHEMA.md` says so in a table headed "The three claims the
             * dump refuted": `store_order_financials` holds 92 live P&L rows, "the service does
             * not throw. The subsystem is running, and has been"; and `products.cost_price`
             * "exists, decimal(15,2) NOT NULL DEFAULT 0.00, populated on all 45 products". Three
             * of those 92 rows carry a NON-ZERO `product_cost`, written by the unscoped lookup
             * this comment sits above.
             *
             * SO THE INFERENCE WAS THE ONE CLAUDE.md §1 EXISTS TO STOP -- "The migrations are not
             * the schema ... the restored dump refuted it" -- and it was made anyway, in the
             * comment on the fix for the defect it concerns.
             *
             * WHAT IS TRUE: no test produces a non-zero `product_cost` through this service, which
             * is why the SUITE saw nothing. That is a statement about the suite, and it stands.
             *
             * AND THE HISTORICAL QUESTION IS NOW ANSWERED, against an isolated restore rather than
             * by assumption: NONE of the 92 rows carries a foreign store's product cost. The only
             * cross-store product references in the data (row 37, order 110 -> products 5 and 6,
             * owned by store 3) sit on a row whose `product_cost` is 0.00, because those products
             * carry no cost themselves. The mechanism was present in real data and had no payload.
             * Reproducible: tests/Support/Security/bin/verify-p1sec44-reconciliation-on-mysql.php.
             * That evidence is a point-in-time snapshot, and `DEPLOYED` is NO -- production still
             * runs the unscoped lookup -- so the window is open until this fix ships.
             *
             * A FOREIGN LINE IS EXCLUDED AND LOGGED, not costed at zero. Both answers give a wrong
             * figure, because the ORDER is wrong -- but a zero silently inflates gross profit,
             * while a warning names the order and the product so the real defect is findable.
             */
            $product = Product::withoutGlobalScopes()
                ->where('id', $productId)
                ->where('store_id', $order->store_id)
                ->first();

            if (!$product) {
                \Log::warning('OrderFinancialService: an order line names a product that is not '
                    . 'this order\'s own, so it is excluded from product_cost. The order itself is '
                    . 'the defect -- see P1-SEC-44.', [
                        'order_id' => $order->id,
                        'order_store_id' => $order->store_id,
                        'product_id' => $productId,
                    ]);

                continue;
            }

            $unitCost = (float) ($product->cost_price ?? 0);

            $totalCost += ($unitCost * $qty);
        }

        return round($totalCost, 2);
    }


    /**
     * مزامنة الحالة المالية مع payment_status بالـOrder.
     */
    public function syncFromOrder(Order $order): void
    {
        $financial = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->where('store_id', $order->store_id)
            ->first();

        if (!$financial) {
            return;
        }

        /*
         * P4-F124 -- A TERMINAL REVERSAL IS NOT UNDONE BY A LATER SETTLEMENT CHANGE.
         * =========================================================================================
         *
         * Once `OrderEntitlementReversal` has moved this row to `cancelled` or `refunded`, the
         * merchant's entitlement has been reversed and a wallet transaction records it. This method
         * must not then move the row back.
         *
         * WITHOUT THIS GUARD THE RECORD IS ERASED, and it is erased silently. The method ends in an
         * UNCONDITIONAL `update(['payment_status' => $orderStatus])`, so ANY later movement of the
         * ORDER's own payment status -- a merchant correcting it, a late gateway callback arriving
         * after a cancellation, a staff member fixing a typo -- rewrote `cancelled` back to `paid`
         * or `unpaid`. The wallet stayed debited and the ledger stopped saying why. Measured: both
         * directions reproduce, and they are pinned by
         * `CancellationLedgerSemanticsTest::test_a_later_payment_status_change_does_not_erase_the_cancellation`
         * and `::test_a_late_paid_does_not_resurrect_a_cancelled_entitlement`.
         *
         * THE `paid` DIRECTION IS THE DANGEROUS ONE. A late `Paid` on a cancelled order would reach
         * `markFinancialAsPaid()` and CREDIT the merchant a second time for an order that was
         * cancelled and already reversed.
         *
         * This is the owner's principle 2 one layer down: "Cancellation does not erase evidence of a
         * captured payment." The evidence lives on this row, and this is what stops a routine status
         * edit destroying it.
         *
         * IT IS NOT A DEAD END FOR LEGITIMATE CORRECTIONS. A merchant who genuinely needs to move a
         * reversed order's entitlement does it through the finance surface, which acts on the
         * financial record deliberately and with its own authorization -- not as a side effect of
         * editing the customer's payment status.
         */
        if (in_array((string) $financial->payment_status, [
            OrderEntitlementReversal::REASON_CANCELLED,
            OrderEntitlementReversal::REASON_REFUNDED,
        ], true)) {
            return;
        }

        $orderStatus = $this->normalizePaymentStatus(
            $order->payment_status
        );

        /*
         * أصبح Paid.
         */
        if (
            $orderStatus === 'paid' &&
            $financial->payment_status !== 'paid'
        ) {
            $this->markFinancialAsPaid(
                (int) $financial->id
            );

            return;
        }

        /*
         * كان Paid ولم يعد Paid.
         */
        if (
            $orderStatus !== 'paid' &&
            $financial->payment_status === 'paid'
        ) {
            $this->markFinancialAsUnpaid(
                (int) $financial->id
            );

            return;
        }

        /*
         * تحديث الحالة فقط بدون حركة مالية.
         */
        DB::table('store_order_financials')
            ->where('id', $financial->id)
            ->update([
                'payment_status' => $orderStatus,
                'updated_at' => now(),
            ]);
    }


    /**
     * تحويل سجل مالي إلى Paid.
     */
    public function markFinancialAsPaid(
        int $financialId
    ): void {
        DB::transaction(function () use ($financialId) {

            $financial = DB::table(
                'store_order_financials'
            )
                ->where('id', $financialId)
                ->lockForUpdate()
                ->first();

            if (
                !$financial ||
                $financial->payment_status === 'paid'
            ) {
                return;
            }

            /*
             * A REVERSED ENTITLEMENT IS NOT RE-CREDITED. GAP-257, and the guard this one is a copy
             * of lives one caller up.
             * =====================================================================================
             *
             * P4-F124 put exactly this check in {@see self::syncFromOrder()}, whose docblock names
             * the danger: *"A late `Paid` on a cancelled order would reach `markFinancialAsPaid()`
             * and CREDIT the merchant a second time for an order that was cancelled and already
             * reversed."* It was right about the destination and it was placed in the CALLER, and
             * this function has two:
             *
             *     self::syncFromOrder():344                      -- had the check
             *     StoreFinanceController:527                      -- calls this directly
             *
             * so the control was one caller deep and the other caller is the FINANCE SURFACE --
             * the very door `syncFromOrder()`'s docblock nominates as the safe route for a
             * deliberate correction. MEASURED through that real door, with a merchant holding
             * `Edit Finance Payment Status` on a 250.00 cancelled order: wallet 0.00 -> **250.00**,
             * the financial row `cancelled` -> **paid**, and the ledger
             * `[credit:order_paid, debit:order_cancelled, credit:order_paid]` -- TWO credits
             * against ONE debit, money held for goods that were never sold, and nothing left on the
             * row saying the entitlement had ever been reversed. `refunded` behaves identically.
             *
             * IT IS LIVE RATHER THAN LATENT, and the screen renders the control for exactly these
             * rows: `StoreFinanceController:572-577` records that *"the screen's select offers only
             * unpaid/paid, so on a cancelled row NEITHER option carries `selected`"*. That
             * paragraph then reasons -- correctly -- that the `unpaid` direction is a no-op on such
             * a row, because {@see self::markFinancialAsUnpaid()} writes nothing unless the row is
             * exactly `paid`. The `paid` direction is the one nobody checked, and the two were
             * ASYMMETRIC in the dangerous direction.
             *
             * SO THIS IS SYMMETRY, NOT A NEW RULE, and that is the whole argument for taking it
             * here rather than escalating it. `markFinancialAsUnpaid()` already declines on a
             * terminal row and already does so silently, with the door's own audit entry recording
             * the state that RESULTED rather than the one requested (P4-F128). This makes the credit
             * decline the same way, so both directions answer a terminal row identically and the
             * audit trail keeps telling the truth about both. The only behaviour removed is a
             * second credit.
             *
             * NO CAPABILITY IS LOST, AND THAT IS WHY THIS IS TAKEN RATHER THAN ESCALATED. The
             * deliberate route the docblock above describes ALREADY EXISTS and is a different door:
             * `StoreFinanceController::addBalance()` (`:1169`), gated on its own permission
             * `Add Finance Balance`, validating an explicit `amount` and REQUIRING a `description`.
             * That is what "acts on the financial record deliberately and with its own
             * authorization" means. What this guard removes is not that route -- it is a silent,
             * full-`customer_total`, unamounted duplicate of it reached through a `<select>` that
             * renders on terminal rows and whose own handler's comment believed the click was a
             * no-op. A merchant who genuinely must restore a reversed entitlement still can, by
             * naming the figure and the reason.
             *
             * WHAT IT DOES **NOT** DECIDE, so GAP-257's own two dispositions stay the owner's:
             * nothing here reads `orders.delivered_status`, so a RETURNED-but-unrefunded order's
             * `Paid` still credits -- measured as a RESTORED single credit rather than a double one
             * (`TerminalEntitlementSecondCreditTest::test_measured_a_returned_order_*`), which is
             * why it is a rule question and not this defect. And the "deliberate correction through
             * the finance surface" that `syncFromOrder()` promises **does not exist**: this closes
             * the accidental route, it does not build the deliberate one. Reported as an owner item
             * rather than invented here.
             *
             * `syncFromOrder()`'s OWN GUARD STAYS. It is not redundant: it also stops that method's
             * closing unconditional `update(['payment_status' => $orderStatus])` from overwriting a
             * terminal row with `unpaid` or `pending`, which is a different loss and is not reached
             * through this function at all.
             *
             * `Log::warning` rather than silence ALONE, so a merchant's refused correction is
             * reconstructible: the door's audit row records the resulting state, and this records
             * that a credit was attempted and on which row.
             */
            if (in_array((string) $financial->payment_status, [
                OrderEntitlementReversal::REASON_CANCELLED,
                OrderEntitlementReversal::REASON_REFUNDED,
            ], true)) {
                \Log::warning('Refused to credit a financial record whose entitlement was reversed.', [
                    'financial_id' => (int) $financial->id,
                    'order_id' => (int) $financial->order_id,
                    'store_id' => (int) $financial->store_id,
                    'entitlement_state' => (string) $financial->payment_status,
                ]);

                return;
            }

            $wallet = StoreWallet::where(
                    'id',
                    $financial->wallet_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * المبلغ الأصلي يظل بعملة الـOrder.
             */
            $originalAmount =
                (float) $financial->customer_total;

            $financialCurrency =
                $financial->currency_code
                ?? 'USD';

            $walletCurrency =
                $wallet->currency_code
                ?? 'USD';

            /*
             * نحول المبلغ لعملة المحفظة الحالية فقط.
             */
            $walletAmount = app(
                StoreCurrencyConversionService::class
            )->convertAmount(
                $originalAmount,
                $financialCurrency,
                $walletCurrency,
                (int) $financial->store_id
            );

            $before = (float) $wallet->available_balance;
            $after = $before + $walletAmount;

            $wallet->available_balance = $after;

            $wallet->total_income =
                (float) $wallet->total_income
                + $walletAmount;

            $wallet->save();

            /*
             * السجل التاريخي يبقى بقيمته الأصلية.
             */
            DB::table('store_order_financials')
                ->where('id', $financial->id)
                ->update([
                    'payment_status' => 'paid',
                    'paid_amount' => $originalAmount,
                    'remaining_amount' => 0,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
             * الحركة تسجل القيمة التي دخلت
             * للمحفظة فعلًا وبعملة المحفظة.
             */
            StoreWalletTransaction::create([
                'store_id' => $financial->store_id,
                'wallet_id' => $wallet->id,

                'currency_code' => $walletCurrency,

                'type' => 'order_paid',
                'direction' => 'credit',

                'amount' => $walletAmount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'order',
                'reference_id' => $financial->order_id,

                'description' =>
                    'Order #' .
                    $financial->order_id .
                    ' payment received',

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });
    }


    /**
     * إلغاء Paid وإرجاع المبلغ من المحفظة.
     */
    public function markFinancialAsUnpaid(
        int $financialId
    ): void {
        DB::transaction(function () use ($financialId) {

            $financial = DB::table(
                'store_order_financials'
            )
                ->where('id', $financialId)
                ->lockForUpdate()
                ->first();

            if (
                !$financial ||
                $financial->payment_status !== 'paid'
            ) {
                return;
            }

            $wallet = StoreWallet::where(
                    'id',
                    $financial->wallet_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * نجيب آخر حركة قبض فعلية لهذا الطلب.
             */
            $paidTransaction =
                StoreWalletTransaction::where(
                    'store_id',
                    $financial->store_id
                )
                ->where(
                    'reference_type',
                    'order'
                )
                ->where(
                    'reference_id',
                    $financial->order_id
                )
                ->where(
                    'type',
                    'order_paid'
                )
                ->where(
                    'status',
                    'completed'
                )
                ->latest('id')
                ->first();

            $walletCurrency =
                $wallet->currency_code
                ?? 'USD';

            /*
             * إذا لقينا حركة القبض الأصلية،
             * نبدأ من المبلغ الذي دخل للمحفظة.
             */
            if ($paidTransaction) {

                $paidTransactionCurrency =
                    $paidTransaction->currency_code
                    ?? $walletCurrency;

                $paidTransactionAmount =
                    (float) $paidTransaction->amount;

                /*
                 * إذا عملة المحفظة تغيرت بعد القبض،
                 * نحول القيمة إلى عملة المحفظة الحالية.
                 */
                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $paidTransactionAmount,
                    $paidTransactionCurrency,
                    $walletCurrency,
                    (int) $financial->store_id
                );

            } else {

                /*
                 * Fallback للسجلات القديمة التي
                 * ما عندها order_paid transaction.
                 */
                $financialCurrency =
                    $financial->currency_code
                    ?? 'USD';

                $originalPaidAmount =
                    (float) $financial->paid_amount;

                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $originalPaidAmount,
                    $financialCurrency,
                    $walletCurrency,
                    (int) $financial->store_id
                );
            }

            $before = (float) $wallet->available_balance;
            $after = $before - $walletAmount;

            $wallet->available_balance = $after;

            $wallet->total_income = max(
                0,
                (float) $wallet->total_income
                - $walletAmount
            );

            $wallet->save();

            /*
             * السجل الأصلي يرجع Unpaid.
             */
            DB::table('store_order_financials')
                ->where('id', $financial->id)
                ->update([
                    'payment_status' => 'unpaid',

                    'paid_amount' => 0,

                    'remaining_amount' =>
                        $financial->customer_total,

                    'paid_at' => null,

                    'updated_at' => now(),
                ]);

            /*
             * حركة عكس الدفع.
             */
            StoreWalletTransaction::create([
                'store_id' => $financial->store_id,
                'wallet_id' => $wallet->id,

                'currency_code' => $walletCurrency,

                'type' => 'order_payment_reversed',
                'direction' => 'debit',

                'amount' => $walletAmount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'order',
                'reference_id' => $financial->order_id,

                'description' =>
                    'Order #' .
                    $financial->order_id .
                    ' payment reversed',

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });
    }


    /**
     * توحيد أسماء حالات الدفع.
     */
    /**
     * Free-text `orders.payment_status` -> the six states a financial record uses.
     *
     * EVERY ARM BELOW IS A VALUE THIS CODEBASE REALLY WRITES TO `orders.payment_status`, and the
     * list was measured rather than imagined. Enumerating the order-write sites gives exactly
     * five distinct spellings, case aside: Paid/paid, success, succeeded/Succeeded, Pending,
     * Unpaid. Values that look like they belong here and deliberately do NOT are on other
     * tables: Approved, Rejected and Failed are `plan_orders.payment_status` (the SaaS plan
     * rail -- BankTransferController and SSLCommerzPaymentController write PlanOrder, not
     * Order), and 'On Hold' / 'Pending payment' belong to the PartialPayments package's own
     * models. An order never carries any of them, so admitting them here would be guessing at
     * a vocabulary this column does not use.
     *
     * 'succeeded' WAS MISSING, AND IT IS THE MOST COMMON SPELLING OF ALL. Twenty order-write
     * sites use it -- Stripe, Mercado Pago, Skrill, Midtrans, PayTabs, PayTR, Paytm,
     * Flutterwave, CoinGate, NMI, SenangPay, PayU, Powertranz, PayNow and MyFatoorah among them
     * -- against four for 'success'. It fell through to `default => 'unpaid'`, so an order paid
     * through any of those gateways was recorded with payment_status 'unpaid', paid_amount 0,
     * remaining_amount equal to the full total and paid_at null, and the merchant's wallet was
     * never credited for it. That is the defect this arm fixes, and nothing else changes: the
     * other arms are untouched, so authorized, pending, failed, cancelled, partially paid and
     * refunded transactions classify exactly as they did before.
     *
     * THE DEFAULT STAYS 'unpaid' ON PURPOSE. It conflates "explicitly not paid" with "I do not
     * recognise this", and for a FINANCIAL record that is the right conservative answer: never
     * claim money arrived on the strength of a word nobody taught this method. (The Lynomia
     * commerce API needs the two told apart and keeps its own map for that reason; the two are
     * held in agreement by a test rather than by hope.)
     */
    private function normalizePaymentStatus(
        $status
    ): string {
        $status = strtolower(
            trim((string) $status)
        );

        return match ($status) {

            'paid',
            'success',
            'succeeded',
            'completed' => 'paid',

            'pending',
            'processing' => 'pending',

            'partial',
            'partially_paid' => 'partially_paid',

            'refund',
            'refunded' => 'refunded',

            'cancel',
            'cancelled',
            'canceled' => 'cancelled',

            default => 'unpaid',
        };
    }
}