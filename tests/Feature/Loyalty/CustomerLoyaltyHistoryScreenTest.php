<?php

namespace Tests\Feature\Loyalty;

use App\Models\Customer;
use App\Support\Loyalty\LoyaltyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `MKT-005` — THE MERCHANT-FACING «سجل حركات», ON THE SCREEN, IN BOTH DIRECTIONS.
 * =================================================================================================
 *
 * A log nobody can read is not a transaction log. `CustomerController::show()` is where a merchant
 * already goes to answer a question about one customer, so the history is rendered there, behind the
 * `Show Customer` permission and the store check that screen already carries.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SCREEN IS READ-ONLY, AND THAT IS ASSERTED
 * -------------------------------------------------------------------------------------------------
 *
 * {@see self::test_opening_the_screen_changes_no_balance_and_writes_no_movement} drives it. Points
 * are money-adjacent; this slice has no authority over them.
 *
 * -------------------------------------------------------------------------------------------------
 * BOTH LANGUAGES, AND WHY A RENDER TEST IS NOT ENOUGH ON ITS OWN
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md requires every UI change to be checked in Arabic (RTL) and English (LTR). A render test
 * can only show that the Arabic page renders — it CANNOT show that the words on it are Arabic,
 * because `__()` falls back to the English string with no error and no log line. So the two
 * mechanisms are split, deliberately:
 *
 *   * `LoyaltyStringsAreTranslatedTest` censuses the KEYS against `resources/lang/ar.json`;
 *   * this file asserts the Arabic page RENDERS, carries `dir="rtl"`, and shows the translated
 *     heading rather than the English one — which is what catches a key that is in the catalogue
 *     but not reaching the page.
 *
 * -------------------------------------------------------------------------------------------------
 * ASSERTIONS ARE AGAINST THE RAW BODY, NOT `strip_tags()`
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md: the merchant layout inlines scripts containing bare `<` comparisons, and `strip_tags()`
 * reads each as a tag opening and discards everything to the next `>` — measured at **80,007 bytes
 * becoming 18,679** on `/finance`, taking a whole rendered block with it. It silently inverts an
 * ABSENCE assertion. Whitespace is collapsed instead.
 */
final class CustomerLoyaltyHistoryScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function giveThisSchemaTheVendoredColumn(): void
    {
        if (! Schema::hasColumn('customers', 'total_club_point')) {
            Schema::table('customers', function ($table) {
                $table->float('total_club_point', 15, 2)->default(0);
            });
        }
    }

    private function customerIn(int $storeId, string $email): int
    {
        return (int) DB::table('customers')->insertGetId([
            'first_name' => 'Nadia',
            'last_name' => 'Haddad',
            'email' => $email,
            'password' => bcrypt('x'),
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function flat(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    /**
     * Lay this store out right-to-left.
     *
     * **MEASURED, AND IT COST A FALSE READING.** The first draft of the two Arabic cases below set
     * `app()->setLocale('ar')` and asserted `dir="rtl"`. The page came back `lang="en" dir="ltr"`
     * with an ARABIC `<title>` — two separate mistakes in one failure:
     *
     * 1. An HTTP request re-bootstraps the container and `App\Http\Middleware\SetLocate` sets the
     *    locale itself from `Session::get('LANGUAGE')` first, so a locale set on the test's own
     *    container is discarded. `withSession(['LANGUAGE' => 'ar'])` is the convention
     *    (`LynomiaRenderedLegibilityTest`).
     * 2. **DIRECTION IS NOT THE LOCALE HERE.** `layouts/app.blade.php` reads
     *    `$setting['SITE_RTL'] == 'on'`, a PER-STORE SETTING. `App\Services\DocumentDirection`'s
     *    docblock states the asymmetry deliberately: *"the whole application keys direction on that
     *    per-store flag, NOT on the chosen language"*, and an Arabic store with `SITE_RTL` off is a
     *    mismatch reported to the owner rather than reinterpreted. So an Arabic-language assertion
     *    and an RTL-layout assertion are two different fixtures, and a test that sets one and
     *    asserts the other measures nothing — which is precisely what the `<bdi>` case did when it
     *    PASSED on the English page.
     *
     * `created_by` IS IN THE PAYLOAD because the column is NOT NULL and this call really does insert:
     * the first version assumed `makeTenant()` had already seeded a `SITE_RTL` row for the store and
     * used a bare `updateOrInsert`, which fell through to an insert and failed the constraint. The
     * settings table is also read through a cache, which is flushed so the request does not see the
     * pre-update row (CLAUDE.md records three false readings from exactly that).
     */
    private function layOutThisStoreRightToLeft(int $storeId, int $ownerId): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'SITE_RTL', 'store_id' => $storeId],
            ['value' => 'on', 'created_by' => $ownerId, 'updated_at' => now(), 'created_at' => now()]
        );

        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame(
            'on',
            DB::table('settings')->where('name', 'SITE_RTL')->where('store_id', $storeId)->value('value'),
            'premise: the RTL flag really is on for this store, so a missing `dir="rtl"` below is '
            . 'the layout and not the fixture.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — the substrate is absent: the honest empty state, not a lie
    |--------------------------------------------------------------------------
    */

    /**
     * With no vendored column there is no balance, and the screen SAYS SO.
     *
     * An empty table would read as *this customer has never earned a point*, which is a different
     * and false statement. This is the state of every environment that has not installed
     * `RewardClubPoint`, which is this whole suite.
     */
    public function test_without_the_module_the_screen_says_the_programme_is_not_installed(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: the vendored column is absent here (GAP-191).');

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'nomodule' . uniqid() . '@example.test');

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('Loyalty points history', $body,
            'the card itself must be on the page even when the programme is absent — otherwise a '
            . 'merchant cannot tell the feature exists.');

        $this->assertStringContainsString(
            'loyalty points programme is not installed',
            $body,
            'and it must name the reason. CLAUDE.md records the "fallback that never fires" shape: '
            . 'a surface that reports 0 instead of saying it cannot see.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the substrate is present: the movements are on the page
    |--------------------------------------------------------------------------
    */

    public function test_the_recorded_movements_are_rendered_with_their_balance_and_order(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'history' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 110.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0,
            source: 'test', orderId: 4242,
        );

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('Earned', $body, 'the movement kind, translated.');
        $this->assertStringContainsString('+10.00', $body,
            'the SIGNED change. An unsigned figure cannot tell an award from a redemption.');
        $this->assertStringContainsString('110.00', $body, 'the balance after the movement.');
        $this->assertStringContainsString('#4242', $body, 'and what caused it.');
        $this->assertStringContainsString('Balance matches the history', $body,
            'the reconciliation verdict: the chain ends at the live balance.');
    }

    /**
     * A drifted balance is REPORTED on the screen and not corrected.
     *
     * This is the case the whole reconciliation exists for, and the one where a well-meaning
     * implementation would quietly "fix" a customer's points.
     */
    public function test_a_drifted_balance_is_reported_on_the_screen(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'drift' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 999.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0, source: 'test',
        );

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('Needs review', $body);
        $this->assertStringContainsString('something changed these points without being recorded', $body);
        $this->assertStringContainsString('999.00', $body,
            'and the SPENDABLE balance is still the one shown, because that is what the customer '
            . 'can actually use.');
    }

    /**
     * A customer the ledger has never seen is NOT reported as a problem.
     *
     * THE DAY-ONE CASE: every customer of a store that installs this ledger today has a balance and
     * no history. A screen that flagged them all would make the control worthless on its first day.
     */
    public function test_a_balance_that_predates_the_ledger_is_explained_and_not_flagged(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'predates' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 250.0]);
        (new Customer)->flushCache();

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('250.00', $body, 'the balance is shown.');
        $this->assertStringContainsString('earned before the points history began', $body);
        $this->assertStringNotContainsString('Needs review', $body,
            'and it is NOT a finding. Reporting it as one is how a control gets switched off.');
        $this->assertStringContainsString('No points movements recorded yet', $body);
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — it writes nothing
    |--------------------------------------------------------------------------
    */

    public function test_opening_the_screen_changes_no_balance_and_writes_no_movement(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'readonly' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 999.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0, source: 'test',
        );

        $this->actingAs($merchant)->get('/customer/' . $customerId)->assertOk();

        $this->assertSame(
            999.0,
            (float) DB::table('customers')->where('id', $customerId)->value('total_club_point'),
            'the drifted balance must be untouched by a page view.'
        );

        $this->assertSame(
            1,
            DB::table('loyalty_point_transactions')->count(),
            'and no compensating row may be invented by rendering a screen.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — tenancy and authorization
    |--------------------------------------------------------------------------
    */

    /**
     * Another store's movements for the SAME customer id are not on this merchant's page.
     *
     * `customers.id` values are reused across tenants in a shared database, and
     * `loyalty_point_transactions` is absent from `config/tenancy.php`'s `scoped_models` — so no
     * global scope filters it for any principal and the handler's own predicate is the only thing
     * between the two stores.
     */
    public function test_another_stores_movements_are_not_listed_on_this_merchants_page(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'tenant' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 110.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0,
            source: 'test', orderId: 1111,
        );

        /* The same customer id, in a store this merchant has nothing to do with. */
        [, $otherStore] = $this->makeTenant();
        LoyaltyLedger::record(
            storeId: (int) $otherStore->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 77.0, balanceBefore: 0.0, balanceAfter: 77.0,
            source: 'test', orderId: 9999,
        );

        $response = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('#1111', $body,
            'premise: this store\'s own movement really rendered, so the absence below is the '
            . 'predicate working and not an empty card.');

        $this->assertStringNotContainsString('#9999', $body,
            'another store\'s loyalty movement was listed on this merchant\'s customer page.');

        $this->assertStringNotContainsString('+77.00', $body);
    }

    /**
     * **THE OPERATOR CASE, AND IT IS THE ONE THAT CAN FAIL.**
     *
     * MEASURED: a mutation replacing the screen's `$customerStoreId` with
     * `getCurrentStore() ?? $customerStoreId` SURVIVED the merchant case above — because there the
     * signed-in merchant's resolved store IS the customer's store, so the mutant is inert. CLAUDE.md
     * records that shape repeatedly ("the store under test was store 1, owned by user 1, so four
     * wrong-folder mutants survived").
     *
     * The operator separates them, exactly as F169 found for the orders half of this same screen.
     * `getCurrentStore()` returns the **hardcoded literal 1** for a `super admin` with an empty
     * `current_store` (`helper.php:888-889`), and `CustomerController::show()` deliberately SKIPS
     * the customer-store check for the operator. So a `getCurrentStore()` predicate would render a
     * store-N customer's page showing STORE 1's loyalty movements — a fabrication, not a refusal,
     * which is the trap F169's own note names.
     */
    public function test_the_operator_sees_the_customers_own_store_and_not_store_one(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        /*
         * TWO TENANTS, AND THE SECOND IS THE ONE UNDER TEST. The first `makeTenant()` in a fresh
         * database produces store id 1 — so the first draft of this case asserted "not 1" against a
         * store that WAS 1 and failed its own premise. That is CLAUDE.md's
         * `aStoreWhoseIdIsNobodyElses()` lesson: build the store under test so its id is neither 1
         * nor its owner's, or "their store" and "the operator's default" are one number and the
         * mutant has nowhere wrong to land.
         */
        $this->makeTenant();
        [, $store] = $this->makeTenant();
        $this->assertNotSame(1, (int) $store->id,
            'premise: the customer\'s store is not 1, so "their store" and "the operator\'s '
            . 'default" are two different numbers and a mutant has somewhere wrong to land.');

        $customerId = $this->customerIn((int) $store->id, 'operator' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 110.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0,
            source: 'test', orderId: 1111,
        );

        /* A movement for the SAME customer id in STORE 1 — where `getCurrentStore()` would point. */
        LoyaltyLedger::record(
            storeId: 1, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 55.0, balanceBefore: 0.0, balanceAfter: 55.0,
            source: 'test', orderId: 9999,
        );

        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator);
        $this->assertSame(1, (int) getCurrentStore(),
            'premise: the operator really resolves to the literal 1, so the absence below is the '
            . 'predicate working and not a helper that answered null.');

        $response = $this->actingAs($operator)->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('#1111', $body,
            'premise: the customer\'s own store\'s movement really rendered for the operator.');

        $this->assertStringNotContainsString('#9999', $body,
            'store 1\'s movement was shown on a store-' . $store->id . ' customer\'s page, which '
            . 'is the `getCurrentStore()` fabrication F169 records.');
        $this->assertStringNotContainsString('+55.00', $body);
    }

    /**
     * No `Show Customer`, no history — because the screen itself refuses first.
     *
     * The premise leg is what makes this non-vacuous: CLAUDE.md records a handler-level check behind
     * an aborting gate being invisible to an HTTP test, and a refusal asserted without its reason
     * being indistinguishable from a missing row.
     */
    public function test_a_merchant_without_the_permission_sees_no_history(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $customerId = $this->customerIn((int) $store->id, 'noperm' . uniqid() . '@example.test');

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0,
            source: 'test', orderId: 1212,
        );

        $refused = $this->actingAs($merchant)->get('/customer/' . $customerId);

        $this->assertStringNotContainsString('#1212',
            $this->flat((string) $refused->getContent()));
        $this->assertStringNotContainsString('Loyalty points history',
            $this->flat((string) $refused->getContent()));

        /* THE CONTROL: the same principal, the same customer, with the permission granted. */
        $this->grantPermission($merchant, 'Show Customer');

        $allowed = $this->actingAs($merchant)->get('/customer/' . $customerId);
        $allowed->assertOk();

        $this->assertStringContainsString('#1212', $this->flat((string) $allowed->getContent()),
            'premise: the fixture IS reachable with the permission, so the refusal above is the '
            . 'gate and not a broken fixture.');
    }

    /*
    |--------------------------------------------------------------------------
    | 5 — both directions
    |--------------------------------------------------------------------------
    */

    public function test_the_screen_renders_in_arabic_with_arabic_words_in_it(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'arabic' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 110.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0, source: 'test',
        );

        /* Language and direction are TWO fixtures here — see `layOutThisStoreRightToLeft()`. */
        $this->layOutThisStoreRightToLeft((int) $store->id, (int) $merchant->id);

        $response = $this->actingAs($merchant)
            ->withSession(['LANGUAGE' => 'ar'])
            ->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('dir="rtl"', $body,
            'premise: the Arabic page really is right-to-left, so this is the RTL direction.');

        $this->assertStringContainsString(
            'سجل حركات نقاط الولاء',
            $body,
            'the heading must be the TRANSLATED one. `__()` falls back to the English string with '
            . 'no error, so an Arabic page full of English words is an HTTP 200 — which is exactly '
            . 'what this assertion exists to catch and what a render-only test cannot.'
        );

        $this->assertStringNotContainsString(
            'Loyalty points history',
            $body,
            'and the ENGLISH heading must be gone. Without this leg the case passes on a page that '
            . 'renders both, which is what a half-applied translation looks like.'
        );

        $this->assertStringContainsString('مكتسبة', $body, 'and the movement kind is translated too.');
    }

    public function test_the_screen_renders_in_english_left_to_right(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'english' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 110.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::EARN,
            delta: 10.0, balanceBefore: 100.0, balanceAfter: 110.0, source: 'test',
        );

        $response = $this->actingAs($merchant)
            ->withSession(['LANGUAGE' => 'en'])
            ->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('dir="ltr"', $body,
            'premise: this really is the left-to-right page, so the English strings below are the '
            . 'LTR direction and not a locale that failed to apply.');
        $this->assertStringNotContainsString('dir="rtl"', $body);
        $this->assertStringContainsString('Loyalty points history', $body);
        $this->assertStringContainsString('Earned', $body);
    }

    /**
     * EVERY SIGNED FIGURE IS BIDI-ISOLATED.
     *
     * A points movement is the worst case in the product for this: in an RTL paragraph an unisolated
     * `-490` can render with the minus at the wrong end, which INVERTS the meaning of the row. The
     * project's convention is `<bdi>` on the run rather than `dir="ltr"` on the block, because the
     * attribute flips the block's inherited `text-align: start` and splits one cell's alignment from
     * its neighbour's (`customer/grid.blade.php` records that measurement).
     */
    public function test_a_negative_movement_is_bidi_isolated_in_arabic(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Show Customer');
        $customerId = $this->customerIn((int) $store->id, 'bidi' . uniqid() . '@example.test');

        DB::table('customers')->where('id', $customerId)->update(['total_club_point' => 10.0]);
        (new Customer)->flushCache();

        LoyaltyLedger::record(
            storeId: (int) $store->id, customerId: $customerId, kind: LoyaltyLedger::REDEEM,
            delta: -490.0, balanceBefore: 500.0, balanceAfter: 10.0, source: 'test',
        );

        $this->layOutThisStoreRightToLeft((int) $store->id, (int) $merchant->id);

        $response = $this->actingAs($merchant)
            ->withSession(['LANGUAGE' => 'ar'])
            ->get('/customer/' . $customerId);
        $response->assertOk();

        $body = $this->flat((string) $response->getContent());

        $this->assertStringContainsString('dir="rtl"', $body,
            'premise: this is the RTL page. Without this leg the case passes on the English page, '
            . 'where bidi isolation is invisible — which is how the first draft of it passed.');
        $this->assertStringContainsString('-490.00', $body, 'premise: the figure is on the page.');

        $this->assertStringContainsString(
            '<bdi>-490.00</bdi>',
            $body,
            'the signed figure must be inside a `<bdi>`. Unisolated, a leading minus in an RTL '
            . 'context can render at the other end of the number and read as a positive.'
        );
    }
}
