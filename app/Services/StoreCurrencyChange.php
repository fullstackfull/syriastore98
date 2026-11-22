<?php

namespace App\Services;

use App\Models\CurrencyRate;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money\CurrencySymbol;

/**
 * FIN-CUR — THE RULES FOR CHANGING A STORE'S CURRENCY, IN ONE PLACE.
 * =================================================================================================
 *
 * There are two currency writers in this application, and until this existed they did not agree.
 *
 *   `SettingController::currencySettings()`              the merchant web screen
 *   `Api\DashboardController::updateCurrencyApi()`       the route the admin Flutter app calls
 *
 * The web screen had grown five controls, three of them security fixes on this branch. The API
 * writer had none of them: it wrote five `settings` rows from the request and answered 200. So the
 * SAME ACTION, taken through the app instead of the browser, left every product price and every
 * wallet balance at the OLD currency while every label said the new one — and the wallet's own
 * `currency_code` column still named the old currency, which is how the ledger and the wallet came
 * to disagree about what a balance is denominated in. MEASURED through both routes
 * (`ApiCurrencyWriterMatchesTheWebWriterTest`): five of its eight cases were red.
 *
 * THE FIVE RULES, each one already the owner's decision or an executed fix:
 *
 *   1. THE PLATFORM OPERATOR IS REFUSED. Changing a currency rewrites every price in the store, and
 *      F206 (owner decision, 2026-10-30) is that the operator reads a merchant's catalogue and does
 *      not write it. This is the rule the API most needed: `resolveOwnedStore()` drops its
 *      `created_by` predicate for the operator, so an operator may name ANY store's id there, and
 *      before this they could change a merchant's currency setting — relabelling every price in a
 *      store they do not own, without converting any of them.
 *   2. THE CURRENCY MUST HAVE AN ACTIVE `currency_rates` ROW FOR THIS STORE. Without one nothing can
 *      convert into it: `StoreCurrencyConversionService::getRate()` throws for exactly this case.
 *   3. A NON-USD CURRENCY'S RATE MUST BE USABLE (`rate_per_usd > 1`). P1-SEC-80 round 4: converting
 *      at 1:1 silently restates every price as though the two currencies were worth the same.
 *   4. THE STORED SYMBOL COMES FROM THAT ROW, NOT FROM THE REQUEST. Also P1-SEC-80 round 4, and the
 *      API writer took `defult_currancy_symbol` straight from the caller.
 *   5. THE CONVERSION RUNS ONLY WHEN THE CURRENCY REALLY CHANGES, which is the web writer's own
 *      `$oldCurrency !== $newCurrency` test. A settings save that silently restates every price is
 *      worse than one that does nothing.
 *
 * WHAT THIS DOES NOT DO, AND MUST NOT. It does not repair a store whose currency was already
 * changed through the API. Those balances and prices are wrong now, and rewriting historical
 * financial data is the owner's decision, not engineering's — a read-only reconciliation is
 * reported to them instead. This makes the two writers agree from here on.
 *
 * ONE DIFFERENCE BETWEEN THE CALLERS IS DELIBERATE AND IS NOT HIDDEN. The API writer wraps the
 * conversion AND its five-setting write in one transaction, so a failure cannot leave converted
 * prices under the old label. The web screen writes a whole settings form, not five rows, so it
 * keeps its own existing transaction semantics; `convertStoreCurrentValues()` is transactional on
 * its own either way.
 */
final class StoreCurrencyChange
{
    /** Why a change was refused. The caller turns this into its own refusal shape. */
    public const REFUSED_OPERATOR = 'operator-may-not-write-the-catalogue';
    public const REFUSED_NO_RATE = 'no-active-rate-for-this-store';
    public const REFUSED_UNUSABLE_RATE = 'rate-is-not-usable';

    /**
     * May this actor change this store to this currency, and with what symbol?
     *
     * @return array{ok: bool, reason?: string, message?: string, currency?: string, symbol?: string, from?: string}
     */
    public static function prepare(?User $actor, int $storeId, ?string $requestedCode): array
    {
        if (OperatorCatalogueWrite::refuses($actor)) {
            return [
                'ok' => false,
                'reason' => self::REFUSED_OPERATOR,
                'message' => OperatorCatalogueWrite::message(),
            ];
        }

        $code = strtoupper(trim((string) $requestedCode));

        $rate = $code === '' ? null : CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $code)
            ->where('is_active', 1)
            ->first();

        if ($rate === null) {
            return [
                'ok' => false,
                'reason' => self::REFUSED_NO_RATE,
                'message' => __('Selected currency is not available for this store.'),
            ];
        }

        if ($code !== 'USD' && (float) $rate->rate_per_usd <= 1) {
            return [
                'ok' => false,
                'reason' => self::REFUSED_UNUSABLE_RATE,
                'message' => __('Please set a valid exchange rate before changing the store currency.'),
            ];
        }

        $from = self::currentCurrency($storeId);

        /*
         * AND THE CURRENCY BEING LEFT NEEDS A USABLE RATE TOO, because the conversion divides by
         * it. `getRate()` answers 1.0 for USD without reading the table and THROWS for anything
         * else it cannot find, so a store sitting on SYP whose SYP rate row has been deactivated
         * cannot be converted out of SYP. Caught HERE rather than left to the conversion, because
         * a throw mid-way is the one outcome that must not happen: the caller would answer 500
         * having already converted some rows, or -- worse -- write the new label over unconverted
         * amounts. The web screen does not make this check and would answer 500; this is the one
         * place the API is deliberately stricter, and the message names which currency is missing.
         */
        if ($from !== $code && $from !== 'USD') {
            $leaving = CurrencyRate::where('store_id', $storeId)
                ->where('currency_code', $from)
                ->where('is_active', 1)
                ->first();

            if ($leaving === null || (float) $leaving->rate_per_usd <= 0) {
                return [
                    'ok' => false,
                    'reason' => self::REFUSED_NO_RATE,
                    'message' => __('Exchange rate not found for currency') . ': ' . $from,
                ];
            }
        }

        return [
            'ok' => true,
            'currency' => $code,
            // `clean()`, not the raw column: a stored symbol is merchant-written text and
            // CurrencySymbolStoredXssTest pins that it is cleaned before it is stored again.
            'symbol' => CurrencySymbol::clean($rate->symbol),
            'from' => $from,
        ];
    }

    /**
     * The store's currency BEFORE this change.
     *
     * `withoutGlobalScopes()` because `Setting` is scoped and this is asked while resolving a store
     * the caller named explicitly -- the admin API's store comes from the request and is authorised
     * separately, so the scope's idea of "the current tenant" is not the question being asked.
     */
    public static function currentCurrency(int $storeId): string
    {
        $value = Setting::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value');

        $value = strtoupper(trim((string) $value));

        return $value === '' ? 'USD' : $value;
    }

    /**
     * Convert the store's stored amounts, when and only when the currency really changes.
     *
     * Delegates to the service the web screen already uses, which converts every product price,
     * every variant price, the wallet's five balances AND its `currency_code`, and the pending COD
     * orders, inside one transaction. Its missing-rate exception is deliberately NOT caught here:
     * the caller decides what a 422 looks like, and swallowing it would write the new label over
     * unconverted amounts, which is the defect this whole change exists to close.
     */
    public static function apply(int $storeId, string $from, string $to): bool
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));

        if ($from === $to) {
            return false;
        }

        app(StoreCurrencyConversionService::class)->convertStoreCurrentValues($storeId, $from, $to);

        return true;
    }
}
