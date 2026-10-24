<?php

namespace App\Http\Controllers\Concerns;

/**
 * APPLYING A CART'S STOCK CHANGES, AND GIVING THEM BACK.
 * =============================================================================================
 *
 * WHY THIS IS A CONCERN AND NOT A COPY. These two methods were written for
 * PaymentController's three order writers in P01-SEC-14 and are unchanged here -- the move is
 * mechanical, the bodies are identical, and PaymentController still uses them through this
 * trait. What changes is that a FOURTH order writer can now reach them:
 * NMIPayController::getNMIProductStatus carried three raw read-modify-write decrements
 * (`$remain_stock = $Product->product_stock - $qtyy; ... ->save()`) and no give-back at all.
 *
 * That matters more there than anywhere else, for a reason specific to that handler: it is a
 * CARD CHARGE. By the time its stock loop runs, NMI has captured the money. A line that fails
 * mid-loop -- and one now can, because the product lookup there became store-scoped -- abandons
 * the checkout with the earlier lines already decremented and no order row to show for them.
 *
 * The alternative was a second copy of the same 70 lines. The project's own record of what
 * copies cost is in docs/security/P01-SECURITY-WORK-QUEUE.md S32: five gateway handlers carry
 * three read-modify-write decrements each and ZERO uses of ProductStockService, because nothing
 * shared existed to wire them to. One place is the fix for that, not an extra abstraction.
 *
 * WHAT IT DELIBERATELY DOES NOT DECIDE. Whether a shortfall should refuse the order. That is the
 * caller's `$rejectOnShortfall`, and the comment inside decrementStockOrFail() states why every
 * writer that has already taken money must pass false.
 */
trait AppliesCartStockChanges
{
    /** Atomic stock decrement, replacing the read-modify-write pattern. */
    private ?\App\Services\ProductStockService $stockService = null;

    private function stockService(): \App\Services\ProductStockService
    {
        return $this->stockService ??= new \App\Services\ProductStockService();
    }

    /**
     * Decrement stock atomically, remembering it so it can be given back.
     *
     * One copy for all three order paths in this controller
     * (getProductStatus, getWhatsappUrl, whatsapp), which each carried an
     * identical read-modify-write decrement.
     *
     * @param  array  $applied  Accumulator of applied decrements, by reference.
     * @param  string $kind     'variant' or 'product'.
     * @param  mixed  $model    The loaded ProductVariant or Product.
     * @param  mixed  $qty      The cart quantity.
     * @return array|null  null on success; a `['message' => ...]` payload when
     *                     the caller must abandon the order. Any decrements
     *                     already applied in this cart are given back first.
     */
    private function decrementStockOrFail(
        array &$applied,
        string $kind,
        $model,
        $qty,
        bool $rejectOnShortfall = true
    ): ?array {
        $result = $kind === 'variant'
            ? $this->stockService()->decrementVariantStock($model, $qty)
            : $this->stockService()->decrementProductStock($model, $qty);

        if ($result['applied']) {
            $applied[] = [
                'kind' => $kind,
                'id' => $model->id,
                'quantity' => $result['quantity'],
            ];

            return null;
        }

        if ($result['reason'] !== \App\Services\ProductStockService::INSUFFICIENT) {
            // Nothing to decrement (no quantity, or no row). Previously this
            // subtracted zero and saved, so carrying on matches the old
            // behaviour exactly.
            return null;
        }

        // PAST THE POINT OF NO RETURN.
        //
        // $rejectOnShortfall is false in every writer that has already done
        // irreversible work by the time this line runs. In those methods the cart
        // rows of the lines processed BEFORE this one have already been deleted,
        // `new Order()` has not been reached yet, and no transaction wraps any of
        // it -- and on the payment-return paths the gateway has already captured
        // the money. Returning an error there abandoned the checkout with no order
        // row, a partly destroyed cart and nothing refunded.
        //
        // The pre-existing code had no availability test at all on these paths: it
        // simply subtracted and saved, so stock could go negative. Overselling and
        // reporting it is therefore the SAME outcome the platform always had, and
        // strictly better than losing the order. The shortfall is a merchant
        // operations problem, not a reason to discard a paid basket.
        if (!$rejectOnShortfall) {
            $forced = $kind === 'variant'
                ? $this->stockService()->decrementVariantStock($model, $qty, true)
                : $this->stockService()->decrementProductStock($model, $qty, true);

            if ($forced['applied']) {
                $applied[] = [
                    'kind' => $kind,
                    'id' => $model->id,
                    'quantity' => $forced['quantity'],
                ];
            }

            \Illuminate\Support\Facades\Log::critical('Insufficient stock on an order path that cannot refuse; order kept, stock oversold', [
                'kind' => $kind,
                'model_id' => $model->id ?? null,
                'requested_quantity' => $qty,
                'outcome' => $forced['reason'] ?? null,
            ]);

            return null;
        }

        $this->restoreStockDecrements($applied);

        return ['message' => __('Insufficient stock. Please review your cart and try again.')];
    }

    /** Give back every decrement recorded by {@see decrementStockOrFail()}. */
    private function restoreStockDecrements(array $applied): void
    {
        foreach ($applied as $change) {
            if (($change['kind'] ?? null) === 'variant') {
                $this->stockService()->restoreVariantStock($change['id'], $change['quantity']);
            } else {
                $this->stockService()->restoreProductStock($change['id'], $change['quantity']);
            }
        }
    }
}
