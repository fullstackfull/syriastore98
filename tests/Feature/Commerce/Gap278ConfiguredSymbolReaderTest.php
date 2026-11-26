<?php

namespace Tests\Feature\Commerce;

use App\Models\Store;
use App\Support\Money\OrderCurrencyStamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-278 / `P01-B2` — `OrderCurrencyStamp::configuredSymbolFor()`, THE SYMBOL OF A **NAMED** STORE.
 * =================================================================================================
 *
 * THE DEFECT THIS READER EXISTS FOR, found by the GAP-143 agent reviewing its own fix and OLDER than
 * it. With no symbol row of its own, a store's checkout reported the **neighbour merchant's** symbol
 * to a viewer holding a panel session for that neighbour. `Utility::GetValueByName($name, $storeId)`
 * reads the named store's rows, but on a MISS falls through to `Utility::Setting($storeId)`, which
 * IGNORES its store-id argument whenever `Auth::check()` is true and reads `getCurrentStore()`
 * instead (`app/Models/Utility.php:206-236` → `:138-150`). Storefront routes sit in the `web` group
 * with a session cookie path of `/`, so a merchant with a live panel login is a populated web-guard
 * principal on every storefront request — the dual-session hazard CLAUDE.md §1 warns about, landing
 * on a money label.
 *
 * THIS IS THE THIRD TIME THE REPOSITORY HAS ANSWERED THIS QUESTION THE SAME WAY, and the method is
 * deliberately the same shape as the other two: `DocumentDirection::ownRow()` (the order document's
 * direction and logo) and `OrderCurrencyStamp::configuredCodeFor()` (the reporting currency) are
 * both a raw query with an explicit `store_id` predicate, for the reason recorded verbatim in
 * `RawQueryTenancyInventoryTest`'s census note. A fourth spelling would be a fourth thing to keep in
 * step.
 *
 * WHAT IT IS **NOT** ALLOWED TO CHANGE: `symbolFor()`. That method is what the FIVE order writers
 * stamp `orders.currency_symbol` from — the model's `creating` hook plus
 * `ApiController::place_order`/`::ordersave`, `DashboardController::createOrderViaApi` and
 * `OrderController`'s manual order — and a stamped currency is recorded financial data whose
 * rewriting is the owner's decision (`D-N34`). Every one of those five was read before this change
 * and none of them moved. `tests/Feature/Commerce/OrderCurrencyCodeAgreesAcrossWritersTest` is the
 * file that would notice if they had.
 *
 * WHY THE EDGES ARE DRIVEN HERE AND NOT THROUGH A CALLER. Exactly as for `configuredCodeFor()`: the
 * two `CartController` sites pass `$store->id` off a route-resolved store, so a store id of 0 or a
 * negative is unreachable from them and a mutation deleting the guard survives everything that
 * drives the checkout. `OperatorStoreSelection::NO_STORE` is literally `0`, so "no tenant chosen" is
 * a spelling a future caller will plausibly pass, and this file is the only thing that can see it.
 *
 * @see \App\Support\Money\OrderCurrencyStamp::configuredSymbolFor()
 * @see \Tests\Feature\Commerce\OrderCurrencyStampConfiguredReaderTest  the same file for the CODE
 */
class Gap278ConfiguredSymbolReaderTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE CASE THE WHOLE ROW IS ABOUT, driven on the reader rather than through HTTP.
     *
     * TWO STORES WHOSE CONFIGURED SYMBOLS DIFFER, which is what separates the three candidate
     * behaviours. "Read the named store" answers `£` and `€`; "read the viewer's store" answers `€`
     * twice; "always answer the default" answers `$` twice. A fixture where only one store is
     * configured cannot tell the first from the third.
     */
    public function test_it_reads_the_named_store_and_not_another(): void
    {
        [, $ours] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->setting($theirs, 'defult_currancy_symbol', '€');

        $this->assertNull(
            OrderCurrencyStamp::configuredSymbolFor((int) $ours->id),
            "another tenant's configured symbol was returned for a store that configured none — "
            . 'which is GAP-278 exactly. A NULL answer is what lets the caller apply its own '
            . 'default rather than a neighbour\'s currency.'
        );

        $this->setting($ours, 'defult_currancy_symbol', '£');

        $this->assertSame('£', OrderCurrencyStamp::configuredSymbolFor((int) $ours->id));
        $this->assertSame('€', OrderCurrencyStamp::configuredSymbolFor((int) $theirs->id),
            'and the neighbour still gets their own — a predicate that refuses everybody would '
            . 'pass the assertion above and break both stores.');
    }

    /**
     * AND IT IS NOT FOOLED BY A POPULATED WEB GUARD, which is the actual mechanism of the finding.
     *
     * The assertion above holds for a helper that merely has no session to read. This one holds the
     * session the defect needs: a merchant signed into the NEIGHBOUR's panel, with the default guard
     * on `web`, so `Auth::check()` is true and `getCurrentStore()` answers for the other tenant.
     * That is the premise `Utility::Setting()` branches on.
     */
    public function test_a_viewer_holding_another_tenants_panel_session_does_not_change_the_answer(): void
    {
        [, $ours] = $this->makeTenant();
        [$neighbourOwner, $theirs] = $this->makeTenant();

        $this->setting($theirs, 'defult_currancy_symbol', '€');

        $this->actingAs($neighbourOwner, 'web');
        $this->app['auth']->shouldUse('web');

        $this->assertTrue(\Illuminate\Support\Facades\Auth::check(),
            'premise: the web guard is populated. `Utility::Setting()` ignores its store-id '
            . 'argument on exactly this condition, so without it the case measures nothing.');
        $this->assertSame((int) $theirs->id, (int) getCurrentStore(),
            'premise: and `getCurrentStore()` answers for the OTHER tenant — the whole condition '
            . 'of the fall-through.');

        $this->assertNull(
            OrderCurrencyStamp::configuredSymbolFor((int) $ours->id),
            'the viewer\'s own store symbol leaked into the answer for a different store. This is '
            . 'the GAP-278 mechanism, and it is why this reader is a raw scoped query and not '
            . '`Utility::GetValueByName()`.'
        );

        /* THE CONTROL: the same session, asking about the viewer's OWN store, must still answer. */
        $this->assertSame('€', OrderCurrencyStamp::configuredSymbolFor((int) $theirs->id));

        /*
         * THE RESIDUAL, MEASURED HERE RATHER THAN REASONED ABOUT IN A REPORT. `symbolFor()` is
         * deliberately untouched, so under this exact session it STILL answers the neighbour's `€`
         * for our store — and `symbolFor()` is what the five order writers stamp
         * `orders.currency_symbol` from. So after GAP-278 the checkout LABEL is this store's own
         * and the STAMP on an order placed in that same session is still the viewer's: the two can
         * now disagree, where before they agreed on the wrong answer.
         *
         * THAT IS THE SCOPE THE ROW CHOSE, not an oversight. Changing what is stamped alters
         * recorded financial data, which is the owner's decision (`D-N34`), and the stamp's own
         * fall-through is already tracked as `GAP-230`. Pinning it here means a future change to
         * `symbolFor()` reddens a test that explains the trade instead of silently moving a money
         * column — INVERT this assertion if GAP-230 is closed, do not relax it.
         */
        $this->assertSame(
            '€',
            OrderCurrencyStamp::symbolFor($ours->id),
            'GAP-230 appears to be closed: `symbolFor()` no longer takes the viewer\'s store for a '
            . 'store with no row of its own. That is an improvement — but five ORDER writers stamp '
            . '`orders.currency_symbol` from this method, so confirm the change was intended as a '
            . 'change to recorded financial data (owner decision D-N34) and then invert this '
            . 'assertion.'
        );
    }

    public function test_a_store_id_of_zero_is_refused_rather_than_resolved(): void
    {
        [, $store] = $this->makeTenant();
        $this->setting($store, 'defult_currancy_symbol', '€');

        /* `empty(0)` is true, so a helper handed 0 resolves the session's store or, failing that,
           reads with no predicate at all and folds every tenant's settings into one array. No
           tenant means no answer, not somebody else's answer. */
        $this->assertNull(OrderCurrencyStamp::configuredSymbolFor(0),
            'a store id of 0 -- which is what OperatorStoreSelection::NO_STORE is -- was resolved '
            . "to some store's symbol instead of being refused");
        $this->assertNull(OrderCurrencyStamp::configuredSymbolFor(-1));
    }

    public function test_a_store_with_nothing_configured_answers_null_not_a_default(): void
    {
        [, $store] = $this->makeTenant();

        /* `symbolFor()` must answer `$` here, because an order has to be STAMPED with something.
           This reader must answer null, because its caller needs to tell "the merchant chose this"
           from "nobody chose anything" before deciding what to print. */
        $this->assertNull(OrderCurrencyStamp::configuredSymbolFor((int) $store->id));
        $this->assertSame('$', OrderCurrencyStamp::symbolFor($store->id),
            'and `symbolFor()` is UNCHANGED: the order writers still get a value to stamp.');
    }

    public function test_the_fallback_key_is_read_when_the_primary_one_is_absent(): void
    {
        [, $store] = $this->makeTenant();

        /* `symbolFor()`'s chain is `defult_currancy_symbol` then `currency_symbol`, and this reader
           walks the SAME two in the SAME order. A reader that walked only the first would call a
           store configured under the second unconfigured — the ANA-TOP part 3 defect, on the code
           half of the same class. */
        $this->setting($store, 'currency_symbol', '₪');

        $this->assertSame('₪', OrderCurrencyStamp::configuredSymbolFor((int) $store->id));
    }

    public function test_the_primary_key_wins_over_the_fallback(): void
    {
        [, $store] = $this->makeTenant();

        $this->setting($store, 'currency_symbol', '₪');
        $this->setting($store, 'defult_currancy_symbol', '€');

        $this->assertSame('€', OrderCurrencyStamp::configuredSymbolFor((int) $store->id),
            'the chain order is defult_currancy_symbol THEN currency_symbol, as symbolFor() has '
            . 'always walked it. `defult_currancy_symbol` is the name the merchant\'s own settings '
            . 'screen writes; `currency_symbol` is written by nothing shipped, which is why it is '
            . 'second.');
    }

    public function test_duplicate_rows_resolve_to_the_newest_which_is_what_every_reader_serves(): void
    {
        [, $store] = $this->makeTenant();

        /* `settings` has no unique index; `Setting::putForStore()` documents the duplicate
           population P1-PLT-07 created, and that readers serve the NEWEST row while
           `updateOrCreate` addresses the oldest. A bare `->value('value')` scans ASCENDING and
           takes the oldest, so without `orderByDesc('id')` this label would disagree with every
           other screen on a duplicated store. */
        $this->setting($store, 'defult_currancy_symbol', '₪');
        $this->setting($store, 'defult_currancy_symbol', '€');

        $this->assertSame('€', OrderCurrencyStamp::configuredSymbolFor((int) $store->id),
            'this reader took the oldest duplicate while every other screen takes the newest, so '
            . "one store's prices would carry two different symbols");
    }

    /**
     * THE SANITISER IS STILL APPLIED, which is not optional for this value.
     *
     * The currency tab stored whatever stood before the first `-` of its posted value as the
     * symbol, so a `Manage Setting` holder stored MARKUP (P1-SEC-80 round 4), and the four
     * `*_currency_format_with_sym` helpers prefix the stored symbol onto amounts that print raw. A
     * reader that bypasses `Utility::GetValueByName()` bypasses the cleaning it applies at the
     * point of read, so this method has to do it itself — both names are in
     * `CurrencySymbol::SETTING_NAMES`, so it is the SAME cleaning and not a second rule.
     */
    public function test_a_stored_symbol_is_cleaned_the_way_the_helper_would_have_cleaned_it(): void
    {
        [, $store] = $this->makeTenant();

        $this->setting($store, 'defult_currancy_symbol', '<img src=x onerror=alert(1)>€');

        $answer = (string) OrderCurrencyStamp::configuredSymbolFor((int) $store->id);

        $this->assertStringNotContainsString('<', $answer,
            'the raw stored value reached the caller. These two names are in '
            . 'CurrencySymbol::SETTING_NAMES precisely so that every reader of them cleans, and '
            . 'this one does not go through Utility::GetValueByName().');
        $this->assertSame(
            \App\Support\Money\CurrencySymbol::cleanSetting('defult_currancy_symbol', '<img src=x onerror=alert(1)>€'),
            $answer,
            'and it is the HELPER\'S OWN cleaning, not a second rule that could drift from it.'
        );
    }

    public function test_a_row_holding_an_empty_string_is_not_mistaken_for_a_configured_symbol(): void
    {
        [, $store] = $this->makeTenant();

        $this->setting($store, 'defult_currancy_symbol', '');

        /* `!== null` tests null only, so the empty string IS returned — the same decision
           `configuredCodeFor()` records, and for the same reason: `symbolFor()` has always
           returned it, and treating a present-but-empty row as "unset" would be a DIFFERENT change
           that also moves what a configured-but-empty store's checkout prints.

           THE CALLERS USE `??`, NOT `?:`, and that is deliberate rather than an oversight: the two
           checkout sites therefore pass an empty row through as the empty string, which is exactly
           what `symbolFor()` gave them before this change. `?:` would have been a quieter second
           behaviour change riding along with a tenancy fix. */
        $this->assertSame('', OrderCurrencyStamp::configuredSymbolFor((int) $store->id));
        $this->assertSame('', OrderCurrencyStamp::symbolFor($store->id),
            'and the unchanged `symbolFor()` agrees, which is why `??` at the call sites preserves '
            . 'the previous answer for this state exactly.');
    }

    private function setting(Store $store, string $name, string $value): void
    {
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => $name, 'value' => $value,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
    }
}
