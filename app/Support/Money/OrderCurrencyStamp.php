<?php

namespace App\Support\Money;

use App\Models\Utility;

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

    /** @param int|string|null $storeId null lets `GetValueByName()` resolve the request's store, as the hook did */
    public static function codeFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy', $storeId)
            ?? Utility::GetValueByName('currency', $storeId)
            ?? self::DEFAULT_CODE);
    }

    /** @param int|string|null $storeId */
    public static function symbolFor($storeId): string
    {
        return (string) (Utility::GetValueByName('defult_currancy_symbol', $storeId)
            ?? Utility::GetValueByName('currency_symbol', $storeId)
            ?? self::DEFAULT_SYMBOL);
    }
}
