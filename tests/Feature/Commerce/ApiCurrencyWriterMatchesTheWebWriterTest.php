<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * FIN-CUR — CHANGING A STORE'S CURRENCY DOES TWO DIFFERENT THINGS DEPENDING ON WHICH CLIENT ASKS.
 * =============================================================================================
 *
 * There are two currency writers in this application and they do not agree.
 *
 * `SettingController::currencySettings()` — the WEB screen — carries five controls, three of them
 * added as fixes on this branch:
 *
 *   1. `OperatorCatalogueWrite::refuses()`      the platform operator may not rewrite a merchant's
 *                                               price list (F206, owner decision 2026-10-30)
 *   2. the currency must exist as an ACTIVE `currency_rates` row for that store
 *   3. a non-USD currency must have `rate_per_usd > 1`      (P1-SEC-80 round 4)
 *   4. the stored SYMBOL comes from that row, not from the request   (P1-SEC-80 round 4)
 *   5. when the currency really changes, `StoreCurrencyConversionService::convertStoreCurrentValues()`
 *      converts every product price, every variant price, the wallet's five balances AND the
 *      wallet's own `currency_code`, and the pending COD orders, inside one transaction
 *
 * `Api\DashboardController::updateCurrencyApi()` — the route the ADMIN FLUTTER APP calls — carries
 * NONE of them. It writes five `settings` rows from the request and returns 200.
 *
 * SO THE SAME ACTION, THROUGH THE APP INSTEAD OF THE BROWSER, LEAVES EVERY PRICE AND EVERY WALLET
 * BALANCE AT THE OLD CURRENCY WHILE EVERY LABEL SAYS THE NEW ONE. A store on USD with a 100.00
 * product and a 500.00 wallet balance that switches to SYP through the app still holds 100.00 and
 * 500.00 — now meaning SYP. The wallet's `currency_code` column still says USD, so the ledger and
 * the wallet disagree in currency, which is the defect as it was first recorded.
 *
 * WHAT THIS SUITE DOES NOT DO, AND WILL NOT. It does not repair a store whose currency was already
 * changed through the API. Rewriting balances that are already wrong is a modification of
 * historical financial data and is the owner's, not engineering's; a read-only reconciliation
 * query is reported to them instead. This suite makes the two writers agree from now on.
 */
final class ApiCurrencyWriterMatchesTheWebWriterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/api/admin/updateCurrencyApi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('currency_rates'),
            '`currency_rates` is missing from a database built by this repository\'s migrations. '
            . 'GATE F declared it on 2026-11-02 against a restored copy of production, so this '
            . 'means the migration has been reverted or renamed. Do NOT answer it with a '
            . 'Schema::create() here: that is not a discoverable migration and would make this '
            . 'suite green against a fabricated money schema.'
        );
    }

    /** A merchant who may administer their store's settings, with a store on USD. */
    private function arrange(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);

        $this->grantPermission($merchant, 'Manage Setting');

        $this->setting($store->id, 'defult_currancy', 'USD', (int) $merchant->id);
        $this->setting($store->id, 'CURRENCY_NAME', 'USD', (int) $merchant->id);
        $this->setting($store->id, 'defult_currancy_symbol', '$', (int) $merchant->id);
        $this->setting($store->id, 'CURRENCY', '$', (int) $merchant->id);

        return [User::find($merchant->id), $store];
    }

    /** `settings.created_by` is NOT NULL, so a fixture row needs an author like a real one. */
    private function setting(int $storeId, string $name, string $value, ?int $author = null): void
    {
        Setting::withoutGlobalScopes()->updateOrCreate(
            ['name' => $name, 'store_id' => $storeId],
            ['value' => $value, 'name' => $name, 'store_id' => $storeId, 'created_by' => $author ?? 1]
        );
    }

    private function read(int $storeId, string $name): ?string
    {
        $row = Setting::withoutGlobalScopes()->where('store_id', $storeId)->where('name', $name)->first();

        return $row === null ? null : (string) $row->value;
    }

    /** An active rate row, which the web writer requires and the API writer does not. */
    private function rate(int $storeId, string $code, float $perUsd, string $symbol, int $active = 1): int
    {
        return (int) DB::table('currency_rates')->insertGetId([
            'store_id' => $storeId, 'currency_code' => $code, 'currency_name' => $code,
            'symbol' => $symbol, 'rate_per_usd' => $perUsd, 'is_active' => $active,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn($store, User $owner, float $price): int
    {
        return (int) Product::forceCreate([
            'name' => 'Currency probe ' . $this->uniqueFixtureRef(),
            'slug' => 'cur-' . $this->uniqueFixtureRef(),
            'price' => $price, 'sale_price' => $price, 'cost_price' => $price / 2,
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $owner->id,
        ])->id;
    }

    private function wallet(int $storeId, string $code, float $balance): int
    {
        return (int) DB::table('store_wallets')->insertGetId([
            'store_id' => $storeId, 'currency_code' => $code,
            'available_balance' => $balance, 'pending_balance' => 0,
            'withdrawal_pending_balance' => 0, 'total_income' => $balance, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sendChange(User $caller, array $payload)
    {
        $token = $caller->createToken('auth_token')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->withHeader('Accept', 'application/json')
            ->postJson(self::URI, $payload);
    }

    // ---------------------------------------------------------------------------------------
    //  1. THE SYMBOL (P1-SEC-80 round 4, on the web only)
    // ---------------------------------------------------------------------------------------

    public function test_the_stored_symbol_comes_from_the_stores_own_rate_row_and_not_from_the_request(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 13000, 'ل.س');

        $response = $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => '€',   // not this store's SYP symbol
        ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'PREMISE: the endpoint raised a server error, so nothing below is measuring its '
            . 'behaviour. Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame('ل.س', $this->read($store->id, 'CURRENCY'),
            'The API stored the SYMBOL the request supplied instead of the one on the store\'s own '
            . 'active `currency_rates` row. The web writer takes it from that row -- P1-SEC-80 '
            . 'round 4 -- so through the app a merchant can label SYP amounts with any symbol.');
        $this->assertSame('ل.س', $this->read($store->id, 'defult_currancy_symbol'));
    }

    /**
     * AND IT IS CLEANED ON THE WAY THROUGH, because a stored symbol is merchant-written text and a
     * formatted amount is printed raw in more places than a census can keep (P1-SEC-80 round 4).
     *
     * ASSERTED ON WHAT `clean()` ACTUALLY PROMISES, and the first version of this case asserted
     * more. `CurrencySymbol::clean()` removes the characters a tag, an attribute value or a script
     * string cannot do without -- `< > " ' ` \` and control characters -- and caps the length. It
     * does NOT remove words, so `'<script>alert(1)</script>ل.س'` cleans to
     * `'scriptalert(1)/scriptل.س'`: inert text, which is the control, while `alert(1)` is still
     * spelled in it. Measured here before the assertion was written.
     */
    public function test_a_symbol_carrying_markup_is_cleaned_before_it_is_stored(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 13000, '<script>alert(1)</script>ل.س');

        $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        foreach (['CURRENCY', 'defult_currancy_symbol'] as $name) {
            $stored = (string) $this->read($store->id, $name);

            $this->assertNotSame('', $stored, "PREMISE: `{$name}` was not written at all.");

            foreach (['<', '>', '"', "'", '`', '\\'] as $character) {
                $this->assertStringNotContainsString($character, $stored,
                    "`{$name}` was stored still carrying `{$character}`. The symbol is prefixed or "
                    . 'suffixed onto an amount that several screens print raw, so a character that '
                    . 'can open a tag or break out of an attribute must not survive the write.');
            }
        }
    }

    // ---------------------------------------------------------------------------------------
    //  2 and 3. THE RATE MUST EXIST, AND BE USABLE
    // ---------------------------------------------------------------------------------------

    public function test_a_currency_with_no_rate_row_for_this_store_is_refused(): void
    {
        [$merchant, $store] = $this->arrange();

        $response = $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'JPY',
            'defult_currancy_symbol' => '¥',
        ]);

        $this->assertSame('USD', $this->read($store->id, 'defult_currancy'),
            'The API accepted a currency the store has no active `currency_rates` row for. Nothing '
            . 'can convert an amount into it -- `StoreCurrencyConversionService::getRate()` throws '
            . 'for exactly this -- and the web writer refuses it by name.');
        /*
         * 422 EXACTLY, not merely "not 200" -- WRITTEN BECAUSE A MUTATION SURVIVED. Removing the
         * missing-rate branch makes the next line dereference a null `$rate`, which Laravel's
         * handler turns into a 500 that the controller's own catch returns. That is also "not 200"
         * and also leaves the setting alone, so the weaker assertion passed against a crash. The
         * difference matters to the Flutter app, which shows the caller a message for a 422 and a
         * failure for a 500.
         */
        $this->assertSame(422, $response->getStatusCode(),
            'The API did not REFUSE a currency it cannot convert into; it answered '
            . $response->getStatusCode() . '. Body: ' . substr((string) $response->getContent(), 0, 200));
    }

    public function test_a_non_usd_currency_whose_rate_is_not_usable_is_refused(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 1, 'ل.س');     // the placeholder rate the web writer refuses

        $response = $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        $this->assertSame('USD', $this->read($store->id, 'defult_currancy'),
            'The API accepted a non-USD currency whose `rate_per_usd` is not usable. The web '
            . 'writer refuses it (P1-SEC-80 round 4) because converting at 1:1 silently restates '
            . 'every price in the store as though the two currencies were worth the same.');
        $this->assertSame(422, $response->getStatusCode(),
            'An unusable rate is refused, but not as a refusal the caller can act on: the API '
            . 'answered ' . $response->getStatusCode() . '.');
    }

    /**
     * THE CURRENCY BEING LEFT NEEDS A USABLE RATE TOO, because the conversion divides by it.
     *
     * A store sitting on SYP whose SYP rate row has been deactivated cannot be converted OUT of
     * SYP: `getRate()` throws for a currency it cannot find. Refusing before the conversion starts
     * is the difference between a 422 that names the missing currency and a 500 raised half way
     * through a transaction -- or, worse, the new label written over unconverted amounts.
     */
    public function test_a_store_whose_current_currency_has_no_usable_rate_is_refused(): void
    {
        [$merchant, $store] = $this->arrange();

        $this->setting($store->id, 'defult_currancy', 'SYP', (int) $merchant->id);
        $this->rate($store->id, 'SYP', 13000, 'ل.س', 0);          // deactivated
        $this->rate($store->id, 'USD', 1, '$');

        $product = $this->productIn($store, $merchant, 1300000.00);

        $response = $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'USD',
            'defult_currancy_symbol' => '$',
        ]);

        $this->assertSame(422, $response->getStatusCode(),
            'The API did not refuse a conversion it cannot perform. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame('SYP', $this->read($store->id, 'defult_currancy'),
            'The currency label changed although nothing could be converted.');
        $this->assertSame(1300000.0, (float) Product::withoutGlobalScopes()->find($product)->price,
            'A price was changed by a request that was refused.');
    }

    // ---------------------------------------------------------------------------------------
    //  5. THE CONVERSION ITSELF -- the defect as first recorded
    // ---------------------------------------------------------------------------------------

    public function test_a_currency_change_through_the_api_converts_the_catalogue_and_the_wallet(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 13000, 'ل.س');

        $product = $this->productIn($store, $merchant, 100.00);
        $this->wallet($store->id, 'USD', 500.00);

        $response = $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        $this->assertSame(200, $response->getStatusCode(),
            'PREMISE: the currency change was refused, so nothing below measures the conversion. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame('SYP', $this->read($store->id, 'defult_currancy'),
            'PREMISE: the currency setting did not change at all.');

        $wallet = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertSame('SYP', (string) $wallet->currency_code,
            'The wallet still records the OLD currency after the store changed currency through '
            . 'the API. The ledger and the wallet now disagree about what the balance is '
            . 'denominated in, which is the defect this suite was opened for.');
        $this->assertSame(6500000.0, (float) $wallet->available_balance,
            'The wallet balance was not converted: 500.00 USD at 13000 per USD is 6,500,000.00 SYP. '
            . 'The web writer converts it; through the API the number stayed and only its meaning '
            . 'changed.');

        $this->assertSame(1300000.0, (float) Product::withoutGlobalScopes()->find($product)->price,
            'The product price was not converted: 100.00 USD at 13000 per USD is 1,300,000.00 SYP.');
    }

    /** AND A CHANGE TO THE SAME CURRENCY CONVERTS NOTHING, which is the web writer's own rule. */
    public function test_restating_the_same_currency_does_not_convert_anything(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'USD', 1, '$');

        $product = $this->productIn($store, $merchant, 100.00);
        $this->wallet($store->id, 'USD', 500.00);

        $this->sendChange($merchant, [
            'store_id' => $store->id,
            'defult_currancy' => 'USD',
            'defult_currancy_symbol' => '$',
        ]);

        $this->assertSame(100.0, (float) Product::withoutGlobalScopes()->find($product)->price,
            'Re-saving the SAME currency converted the catalogue. The web writer only converts '
            . 'when `$oldCurrency !== $newCurrency`, and a settings save that silently restates '
            . 'every price is worse than one that does nothing.');
        $this->assertSame(500.0, (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance'));
    }

    // ---------------------------------------------------------------------------------------
    //  1 (F206). THE OPERATOR DOES NOT REWRITE A MERCHANT'S PRICE LIST
    // ---------------------------------------------------------------------------------------

    /**
     * MEASURED, AND NOT THE OUTCOME I EXPECTED WHEN I WROTE IT. The first version of this docblock
     * said the case would pass before the fix for the wrong reason -- that today the API converts
     * nothing, so the operator rewrites no price. Half of that is right: the price assertion does
     * pass. The SECOND assertion fails. The operator really can change a merchant store's currency
     * SETTING through this route today, which relabels every price in that store without
     * converting any of them -- a worse outcome than the one F206 was written about, reached by a
     * principal F206 names.
     *
     * It is reachable because `resolveOwnedStore()` drops its `created_by` predicate for the
     * operator, so an operator may name ANY store's id here, and `StoreSettingsAccess::permits()`
     * admits them. The web currency tab refuses them by name.
     */
    public function test_the_platform_operator_cannot_convert_a_merchants_catalogue_through_the_api(): void
    {
        [$merchant, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 13000, 'ل.س');

        $product = $this->productIn($store, $merchant, 100.00);
        $this->wallet($store->id, 'USD', 500.00);

        $operator = $this->makePlatformAdmin();

        $this->sendChange(User::find($operator->id), [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        $this->assertSame(100.0, (float) Product::withoutGlobalScopes()->find($product)->price,
            'The platform operator converted a MERCHANT\'s product prices through the admin API. '
            . 'F206 (owner decision 2026-10-30) says the operator reads a merchant\'s catalogue '
            . 'and does not write it, and the web currency writer refuses them by name.');
        $this->assertSame('USD', $this->read($store->id, 'defult_currancy'),
            'The operator changed a merchant store\'s currency setting through the API.');
    }

    // ---------------------------------------------------------------------------------------
    //  CONTROLS
    // ---------------------------------------------------------------------------------------

    public function test_a_merchant_from_another_tenant_cannot_change_this_stores_currency(): void
    {
        [, $store] = $this->arrange();
        $this->rate($store->id, 'SYP', 13000, 'ل.س');

        [$rival] = $this->makeTenant();
        $this->grantPermission($rival, 'Manage Setting');

        $this->sendChange(User::find($rival->id), [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        $this->assertSame('USD', $this->read($store->id, 'defult_currancy'),
            'Another tenant changed this store\'s currency. `resolveOwnedStore()` is supposed to '
            . 'refuse that, and this control is what says it still does.');
    }

    public function test_a_merchant_without_the_settings_permission_is_refused(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->setting($store->id, 'defult_currancy', 'USD', (int) $merchant->id);
        $this->rate($store->id, 'SYP', 13000, 'ل.س');

        $staff = $this->makeStaff(User::find($merchant->id), ['current_store' => $store->id]);

        $this->sendChange(User::find($staff->id), [
            'store_id' => $store->id,
            'defult_currancy' => 'SYP',
            'defult_currancy_symbol' => 'ل.س',
        ]);

        $this->assertSame('USD', $this->read($store->id, 'defult_currancy'),
            'A staff member with no settings permission changed the store currency.');
    }
}
