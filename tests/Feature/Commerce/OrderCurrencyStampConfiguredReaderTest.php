<?php

namespace Tests\Feature\Commerce;

use App\Models\Store;
use App\Support\Money\OrderCurrencyStamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `OrderCurrencyStamp::configuredCodeFor()` — reading a NAMED store's configured currency.
 * =================================================================================================
 *
 * The method's own docblock states why it is a raw scoped query rather than
 * `Utility::GetValueByName()`: that helper falls through on a miss to `Utility::Setting()`, which
 * ignores its store-id argument whenever `Auth::check()` is true and reads the VIEWER's store
 * instead -- the defect `DocumentDirection::ownRow()` already exists to avoid, measured here on an
 * order document taking the reader's logo.
 *
 * ITS CALLER CANNOT REACH THE EDGES. `TopSellersByRevenue::compute()` refuses a store id of 0,
 * `''`, `null` or a negative before `primaryCurrency()` runs, so a mutation that deletes this
 * method's own guard survives every test that drives the dashboard -- measured. The guard is
 * defence for the NEXT caller, and `OperatorStoreSelection::NO_STORE` is literally `0`, so "no
 * tenant chosen" is a spelling a future caller will plausibly pass. This file drives the method
 * directly, because nothing else can.
 */
class OrderCurrencyStampConfiguredReaderTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_a_store_id_of_zero_is_refused_rather_than_resolved(): void
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'defult_currancy', 'EUR');

        /* `empty(0)` is true, so a helper handed 0 resolves the session's store or, failing that,
           reads with no predicate at all and folds every tenant's settings into one array. No
           tenant means no answer, not somebody else's answer. */
        $this->assertNull(OrderCurrencyStamp::configuredCodeFor(0),
            'a store id of 0 -- which is what OperatorStoreSelection::NO_STORE is -- was resolved '
            . "to some store's currency instead of being refused");
        $this->assertNull(OrderCurrencyStamp::configuredCodeFor(-1));
    }

    public function test_a_store_with_nothing_configured_answers_null_not_a_default(): void
    {
        [, $store] = $this->makeTenant();

        /* `codeFor()` must answer USD here, because an order has to be stamped with something.
           This reader must answer null, because a FIGURE needs to know the difference between a
           denomination the merchant chose and one that was inferred. */
        $this->assertNull(OrderCurrencyStamp::configuredCodeFor((int) $store->id));
        $this->assertSame('USD', OrderCurrencyStamp::codeFor($store->id));
    }

    public function test_it_reads_the_named_store_and_not_another(): void
    {
        [, $ours] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setting($theirs, 'defult_currancy', 'EUR');

        $this->assertNull(OrderCurrencyStamp::configuredCodeFor((int) $ours->id),
            "another tenant's configured currency was returned for a store that configured none");

        $this->setting($ours, 'defult_currancy', 'SYP');

        $this->assertSame('SYP', OrderCurrencyStamp::configuredCodeFor((int) $ours->id));
        $this->assertSame('EUR', OrderCurrencyStamp::configuredCodeFor((int) $theirs->id));
    }

    public function test_the_fallback_key_is_read_when_the_primary_one_is_absent(): void
    {
        [, $store] = $this->makeTenant();

        /* `codeFor()`'s chain is `defult_currancy` then `currency`, and a reader that walked only
           the first called a store configured under the second UNCONFIGURED -- the ANA-TOP part 3
           defect. */
        $this->setting($store, 'currency', 'GBP');

        $this->assertSame('GBP', OrderCurrencyStamp::configuredCodeFor((int) $store->id));
    }

    public function test_the_primary_key_wins_over_the_fallback(): void
    {
        [, $store] = $this->makeTenant();

        $this->setting($store, 'currency', 'GBP');
        $this->setting($store, 'defult_currancy', 'EUR');

        $this->assertSame('EUR', OrderCurrencyStamp::configuredCodeFor((int) $store->id),
            'the chain order is defult_currancy THEN currency, as codeFor() has always walked it');
    }

    public function test_duplicate_rows_resolve_to_the_newest_which_is_what_every_reader_serves(): void
    {
        [, $store] = $this->makeTenant();

        /* `settings` has no unique index; `Setting::putForStore()` documents the duplicate
           population P1-PLT-07 created, and that readers serve the NEWEST row while
           `updateOrCreate` addresses the oldest. A bare `->value('value')` scans ascending. */
        $this->setting($store, 'defult_currancy', 'GBP');
        $this->setting($store, 'defult_currancy', 'EUR');

        $this->assertSame('EUR', OrderCurrencyStamp::configuredCodeFor((int) $store->id),
            'this reader took the oldest duplicate while every other screen takes the newest, so '
            . 'one store would see its money denominated two ways');
    }

    public function test_a_row_holding_an_empty_string_is_not_mistaken_for_a_configured_currency(): void
    {
        [, $store] = $this->makeTenant();

        $this->setting($store, 'defult_currancy', '');

        /* `??` tests null only, so the empty string IS returned -- deliberately, because
           `codeFor()` has always returned it and changing that would alter what three agreeing
           writers stamp. The caller is what must not treat it as a currency:
           `CurrencySymbol::code('')` is `''`, which `primaryCurrency()` reads as unconfigured. */
        $this->assertSame('', OrderCurrencyStamp::configuredCodeFor((int) $store->id));
        $this->assertSame('', \App\Support\Money\CurrencySymbol::code(
            (string) OrderCurrencyStamp::configuredCodeFor((int) $store->id)
        ));
    }

    private function setting(Store $store, string $name, string $value): void
    {
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => $name, 'value' => $value,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
