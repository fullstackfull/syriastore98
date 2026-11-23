<?php

namespace App\Support\Money;

use App\Models\Utility;
use Illuminate\Support\Facades\DB;

/**
 * P5-R14 — WHICH CURRENCY AN ORDER IS STAMPED WITH, DECIDED IN ONE PLACE.
 * =================================================================================================
 *
 * `orders.currency_code` and `orders.currency_symbol` are a SNAPSHOT: what the store traded in
 * when the order was placed. Every per-currency money figure groups by the code, and
 * `MetricResult::total()` refuses a result that holds more than one — so two writers disagreeing
 * about one store's currency does not produce a slightly wrong total, it produces two totals or
 * none.
 *
 * They disagreed. The model's `creating` hook, `OrderController` and the admin API read the
 * store's `defult_currancy` — the CODE. `Api\ApiController::place_order()` and `::ordersave()`
 * read `CURRENCY`, which `Utility::Setting()` ships as `'$'`: the SYMBOL. Measured 2026-11-22, a
 * store trading in Syrian pounds had its storefront-API orders recorded as `$`
 * (`OrderCurrencyCodeAgreesAcrossWritersTest`). The symbol column had the mirror-image fault: those
 * two read a setting named `currency_symbol`, which nothing writes.
 *
 * THIS IS THE MODEL HOOK'S OWN CHAIN, MOVED HERE UNCHANGED — the same settings, in the same
 * order, with the same operators — so that the hook's behaviour does not move by one character
 * and the two outliers come to agree with it rather than with a third rule.
 *
 * `??` AND NOT `?:`, DELIBERATELY KEPT. A setting row that exists and holds an empty string is
 * returned as the empty string, exactly as the hook returned it. Treating that as "unset" would be
 * a more defensible rule and is a DIFFERENT change: it would alter what three already-agreeing
 * writers record, in the one direction this fix exists to prevent — writers drifting apart.
 *
 * NOT CLEANED HERE, because it already is: `Utility::GetValueByName()` passes every currency
 * setting through `CurrencySymbol::cleanSetting()` where it is read (P1-SEC-80).
 *
 * WHAT THIS DOES NOT DO: touch an order that is already written. Rewriting a recorded currency is a
 * change to historical financial data, and that is the owner's decision (`D-N34`).
 */
final class OrderCurrencyStamp
{
    /** The product-wide fallbacks the model hook has always used. */
    public const DEFAULT_CODE = 'USD';

    public const DEFAULT_SYMBOL = '$';

    /**
     * The code to STAMP on a new order of this store.
     *
     * UNCHANGED, AND DELIBERATELY NOT ROUTED THROUGH `configuredCodeFor()` BELOW. The review of
     * ANA-TOP part 3 measured why: `Utility::GetValueByName($name, $storeId)` falls through on a
     * miss to `Utility::Setting()`, which IGNORES its store-id argument whenever `Auth::check()`
     * is true and reads `getCurrentStore()` instead (`Utility.php:139-149`). For the three writers
     * that call this with their OWN store that is harmless -- the fallback resolves the same
     * store. Changing it would change which currency future orders are stamped with, which is a
     * business rule and a change to recorded financial data, so it is recorded as a finding
     * rather than altered here, with its reachability, as `GAP-230` in
     * `docs/development/MASTER-PLAN-GAP-REGISTER.md`.
     *
     * @param int|string|null $storeId null lets `GetValueByName()` resolve the request's store, as the hook did
     */
    public static function codeFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy', $storeId)
            ?? Utility::GetValueByName('currency', $storeId)
            ?? self::DEFAULT_CODE);
    }

    /**
     * The code a NAMED store is configured to trade in, or null when nothing is -- READ WITH AN
     * EXPLICIT TENANT PREDICATE, which is the whole point of this method existing.
     *
     * WHY IT IS RAW AND NOT `GetValueByName()`. This repository has already measured the damage
     * and already chose this answer once: `DocumentDirection::ownRow()` reads a named store's
     * `settings` row with a raw scoped query, and the census note at
     * `RawQueryTenancyInventoryTest` records the reason verbatim -- `GetValueByName` "falls
     * through on a miss to `Utility::Setting()`, which IGNORES its store id argument whenever
     * Auth::check() is true and reads the VIEWER's store, so an order document took the reader's
     * logo and direction for any store with no row of its own". The first draft of this method
     * used `GetValueByName` and so would have taken the VIEWER's currency for any reported store
     * with no row of its own -- the identical defect, on a money label. Latent rather than live,
     * because the one caller happens to pass `getCurrentStore()`; written correctly anyway,
     * because "safe only while nobody adds a second caller" is not a property worth shipping.
     *
     * THE CHAIN IS `codeFor()`'s CHAIN: `defult_currancy`, then `currency`. That second key is
     * the defect part 3 set out to fix -- the previous reader looked at only the first, so a store
     * configured under the fallback was called unconfigured and its ranking was denominated in
     * whichever currency happened to be busiest.
     *
     * NEWEST ROW, NOT OLDEST. `settings` has no unique index and really does carry duplicate
     * `(name, store_id)` pairs (`Setting::putForStore()` documents the population P1-PLT-07
     * created). Every other reader takes the newest, because `pluck('value','name')` lets a later
     * key overwrite an earlier one; a bare `->value('value')` scans ascending and takes the
     * OLDEST. Part 1's query did exactly that, so on a duplicated store this label could disagree
     * with every other screen. `orderByDesc('id')` makes it agree.
     *
     * REFUSES A STORE ID OF 0 OR LESS, and that early return is BELT-AND-BRACES rather than the
     * control -- a mutation deleting it survives, measured. The control is the predicate: with an
     * explicit `where('store_id', 0)` no row matches and the answer is null anyway. The guard
     * exists because the first draft of this method used `GetValueByName`, where `empty(0)` is
     * true and 0 resolved the session's store or ran with no predicate at all, and because
     * `OperatorStoreSelection::NO_STORE` is literally `0` -- so a future caller WILL pass it, and
     * an explicit refusal is clearer to read than a predicate that happens to match nothing.
     * `OrderCurrencyStampConfiguredReaderTest` pins the BEHAVIOUR (null for 0 and for a negative),
     * which is what a caller depends on; it cannot pin the guard, and says so.
     */
    public static function configuredCodeFor(int $storeId): ?string
    {
        if ($storeId <= 0) {
            return null;
        }

        foreach (['defult_currancy', 'currency'] as $name) {
            $value = DB::table('settings')
                ->where('store_id', $storeId)
                ->where('name', $name)
                ->orderByDesc('id')
                ->value('value');

            if ($value !== null) {
                /* The sanitisation `GetValueByName()` applies at the point of read (P1-SEC-80).
                   Both keys are in `CurrencySymbol::SETTING_NAMES`, so this is the same cleaning
                   the helper would have done. */
                return (string) CurrencySymbol::cleanSetting($name, $value);
            }
        }

        return null;
    }

    /** @param int|string|null $storeId */
    public static function symbolFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy_symbol', $storeId)
            ?? Utility::GetValueByName('currency_symbol', $storeId)
            ?? self::DEFAULT_SYMBOL);
    }
}
