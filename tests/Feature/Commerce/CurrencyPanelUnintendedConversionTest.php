<?php

namespace Tests\Feature\Commerce;

use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-291 — A SAVE INTENDED FOR THE DECIMAL SEPARATOR MUST NOT CONVERT THE CATALOGUE.
 * =================================================================================================
 *
 * THE CHAIN, read and then driven. `setting/currency_setting.blade.php` is ONE form
 * (`Form::open` … `Form::close`) holding four controls: `currency_format`, `defult_currancy`, and
 * the two separators. So a Save of ANY of them posts `defult_currancy` as well. The currency select
 * lists only `->where('is_active', 1)` rates and marks with `=== $c->currency_code`; if the stored
 * `defult_currancy` matches no active row exactly, NO option is marked, the browser submits the
 * first one, and `SettingController::currencySettings()` → `StoreCurrencyChange::apply()` →
 * `StoreCurrencyConversionService::convertStoreCurrentValues()` rewrites every product price, sale
 * price and cost price, every variant price, the store wallet, and every undelivered COD order.
 *
 * TWO WAYS IN, BOTH DRIVEN BELOW, AND THE SECOND IS NOT IN THE ROW:
 *
 *   (a) THE STORED CURRENCY IS NOT IN THE ACTIVE SET — a legacy lower-case or space-padded value,
 *       or a currency whose rate row has since been deactivated. Nothing is marked; the browser
 *       submits the first active currency; the catalogue is converted to a currency the merchant
 *       never chose.
 *
 *   (b) `defult_currancy` IS ABSENT FROM THE POST ENTIRELY. `currencySettings()`'s `else` limb
 *       sets it to `'USD'` and carries on, so a request that says nothing about the currency
 *       converts the store TO USD. Any client posting only the separator fields does this — and so
 *       does a form whose select was disabled or removed.
 *
 * THE FIX MAKES THE NO-OP CASE A NO-OP. It decides nothing about whether a conversion should
 * happen: the panel marks what is stored (so an untouched select submits what is already there and
 * `apply()` sees no change), and the handler stops inventing a currency when the field is absent.
 * A merchant who deliberately picks a different currency still converts exactly as before —
 * asserted by a control, because a change that blocked a legitimate conversion would be the worse
 * defect and is the owner's call, not mine.
 *
 * ONE CLAUSE OF THE ROW IS DECLINED, and the grounds are the guard's own docblock. The row says to
 * "extend `prepare()`'s guard to the USD case". That guard refuses a change when the currency being
 * LEFT has no active rate, and it skips USD deliberately: `getRate()` answers 1.0 for USD WITHOUT
 * reading the table, so there is no missing rate to refuse and nothing can throw mid-conversion —
 * which is the single failure the guard exists to prevent. Extending it would refuse a legitimate
 * USD→X change for no safety gain. The real defect on a USD store is the same one as everywhere
 * else: the panel submitting a currency nobody chose. That is what is fixed.
 *
 * EXACTLY WHICH SAVES CAN CONVERT, enumerated from the callers of
 * `StoreCurrencyChange::apply()` / `convertStoreCurrentValues()` rather than from the screens:
 * there are TWO, and only one had the absent-field hole.
 *
 *   `SettingController::currencySettings()`  POST /currency-settings (web). The panel is one form,
 *       so a Save of the decimal format or either separator posts the currency select too. Its
 *       `else` limb invented `'USD'` for an absent field. BOTH ways in, fixed here.
 *
 *   `Api\DashboardController::updateCurrencyApi` (`:10627`). Validates `defult_currancy` as
 *       **required**, so it cannot invent one, and it carries no separator controls -- the panel
 *       shape that makes (a) reachable does not exist on this door. Checked, and deliberately not
 *       changed: the hole was web-only.
 *
 * Nothing else in `app/` reaches a conversion. `TopSellersByRevenue` only mentions it in a comment.
 *
 * EVERY ASSERTION READS THE RAW ROW. `Product` and `Setting` are `Cachable` and the suite runs
 * under `CACHE_STORE=array`.
 *
 * @see \App\Http\Controllers\SettingController::currencySettings()
 * @see \App\Services\StoreCurrencyChange::apply()
 */
class CurrencyPanelUnintendedConversionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PRICE = 100.00;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('currency_rates'),
            '`currency_rates` is missing from a database built by this repository\'s migrations, so '
            . 'every assertion here would be vacuous. GATE F declared it on 2026-11-02; do NOT '
            . 'answer this with a Schema::create().');
    }

    /**
     * A merchant who may administer settings, a store whose stored currency is `$storedCurrency`,
     * one product at 100.00, and the active rate rows named in `$activeRates`.
     *
     * @param  array<string, array{0: float, 1: string}>  $activeRates  code => [rate_per_usd, symbol]
     * @return array{0: User, 1: \App\Models\Store, 2: Product}
     */
    private function arrange(string $storedCurrency, array $activeRates, array $inactiveRates = []): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);
        $this->grantPermission($merchant, 'Manage Setting');

        $this->setting($store->id, 'defult_currancy', $storedCurrency, (int) $merchant->id);
        $this->setting($store->id, 'CURRENCY_NAME', $storedCurrency, (int) $merchant->id);
        $this->setting($store->id, 'currency_format', '2', (int) $merchant->id);

        foreach ($activeRates as $code => [$perUsd, $symbol]) {
            $this->rate($store->id, (string) $code, $perUsd, $symbol, 1, (int) $merchant->id);
        }
        foreach ($inactiveRates as $code => [$perUsd, $symbol]) {
            $this->rate($store->id, (string) $code, $perUsd, $symbol, 0, (int) $merchant->id);
        }

        $product = Product::forceCreate([
            'name' => 'cv-' . uniqid(), 'slug' => 'cv-' . uniqid(),
            'price' => self::PRICE, 'sale_price' => self::PRICE, 'cost_price' => self::PRICE,
            'product_stock' => 10, 'track_stock' => 1, 'variant_product' => 0, 'status' => 1,
            'store_id' => $store->id, 'created_by' => (int) $merchant->id,
        ]);

        return [User::find($merchant->id), $store, $product];
    }

    private function setting(int $storeId, string $name, string $value, ?int $author = null): void
    {
        Setting::withoutGlobalScopes()->updateOrCreate(
            ['name' => $name, 'store_id' => $storeId],
            ['value' => $value, 'name' => $name, 'store_id' => $storeId, 'created_by' => $author ?? 1]
        );
    }

    private function rate(int $storeId, string $code, float $perUsd, string $symbol, int $active, int $author): void
    {
        DB::table('currency_rates')->insert([
            'store_id' => $storeId, 'currency_code' => $code, 'currency_name' => $code,
            'symbol' => $symbol, 'rate_per_usd' => $perUsd, 'is_active' => $active,
            'created_by' => $author, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function priceOf(Product $product): float
    {
        return (float) DB::table('products')->where('id', $product->id)->value('price');
    }

    private function storedCurrency(int $storeId): ?string
    {
        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $storeId)->where('name', 'defult_currancy')->first();

        return $row === null ? null : (string) $row->value;
    }

    /** The Currency tab's rendered markup, which its own AJAX endpoint returns as JSON. */
    private function currencySelect(User $merchant): string
    {
        $html = (string) ($this->actingAs($merchant)
            ->post(route('setting.form'), ['tab_type' => 'currency_setting'])
            ->json('data.content') ?? '');

        if (! preg_match('/<select[^>]*name="defult_currancy".*?<\/select>/s', $html, $m)) {
            return '';
        }

        return $m[0];
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — without these, "nothing was converted" is also what a broken fixture produces
    |--------------------------------------------------------------------------
    */

    /** PREMISE: a DELIBERATE currency change really does rewrite the catalogue. */
    public function test_premise_a_deliberate_currency_change_converts_the_catalogue(): void
    {
        [$merchant, $store, $product] = $this->arrange('USD', ['USD' => [1.0, '$'], 'EUR' => [2.0, '€']]);

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'defult_currancy' => '€-EUR',
            'currency_format' => '2',
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ]);

        $this->assertSame('EUR', $this->storedCurrency($store->id),
            'the deliberate change did not even store the new currency, so this suite is not '
            . 'driving the conversion path at all');
        $this->assertNotSame(self::PRICE, $this->priceOf($product),
            'THE PREMISE FAILED: a deliberate currency change no longer converts product prices, '
            . 'so every "nothing was converted" assertion below would pass for the wrong reason.');
    }

    /*
    |--------------------------------------------------------------------------
    | (a) THE STORED CURRENCY IS NOT IN THE ACTIVE SET
    |--------------------------------------------------------------------------
    */

    /**
     * A LEGACY LOWER-CASE STORED VALUE. The panel marks nothing and the first active option wins.
     *
     * `apply()` normalises both sides with `strtoupper(trim())`, so a lower-case stored value is
     * NOT itself a conversion — submitting `SYP` against a stored `syp` is correctly a no-op. The
     * defect is that the panel cannot mark it, so the browser submits a DIFFERENT currency.
     */
    public function test_a_lower_case_stored_currency_is_still_marked_in_the_panel(): void
    {
        [$merchant, , ] = $this->arrange('syp', ['EUR' => [2.0, '€'], 'SYP' => [10.0, 'SYP']]);

        $select = $this->currencySelect($merchant);

        $this->assertNotSame('', $select, 'the currency select is not on the rendered tab');

        $this->assertMatchesRegularExpression('/<option[^>]*value="[^"]*-SYP"[^>]*\bselected\b/', $select,
            'GAP-291: the panel marks NO option for a stored `syp`, so the browser submits the '
            . 'first active currency instead and a Save of the separator converts the whole '
            . 'catalogue to it. Select was: ' . $select);
    }

    /** And the same when the stored currency's rate row has been DEACTIVATED. */
    public function test_a_stored_currency_whose_rate_is_inactive_is_still_marked(): void
    {
        [$merchant, , ] = $this->arrange('SYP', ['EUR' => [2.0, '€']], ['SYP' => [10.0, 'SYP']]);

        $select = $this->currencySelect($merchant);

        $this->assertNotSame('', $select, 'the currency select is not on the rendered tab');

        $this->assertMatchesRegularExpression('/<option[^>]*value="[^"]*-SYP"[^>]*\bselected\b/', $select,
            'GAP-291: the store is on SYP and the panel does not offer or mark it, because the '
            . 'select lists only is_active=1 rows. The browser then submits EUR and a separator '
            . 'Save converts the catalogue. Select was: ' . $select);
    }

    /*
    |--------------------------------------------------------------------------
    | (b) `defult_currancy` ABSENT — the limb the row does not name
    |--------------------------------------------------------------------------
    */

    /**
     * THE REPRODUCTION THAT NEEDS NO BROWSER AT ALL.
     *
     * `currencySettings()`'s `else` limb sets an absent `defult_currancy` to `'USD'`. So a POST
     * carrying only the separator fields — exactly what a Save of that control would send if the
     * select were disabled, removed, or simply not submitted — converts the store to USD.
     */
    public function test_a_save_that_names_no_currency_does_not_convert_the_catalogue(): void
    {
        [$merchant, $store, $product] = $this->arrange('SYP', ['USD' => [1.0, '$'], 'SYP' => [10.0, 'SYP']]);

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'decimal_separator' => 'comma',
            'thousand_separator' => 'dot',
        ]);

        $this->assertSame(self::PRICE, $this->priceOf($product),
            'GAP-291: a Save that said NOTHING about the currency converted the catalogue. '
            . '`currencySettings()` defaults an absent `defult_currancy` to USD and carries on '
            . 'into StoreCurrencyChange::apply().');

        $this->assertSame('SYP', $this->storedCurrency($store->id),
            'GAP-291: the store\'s currency was changed by a request that did not name one.');
    }

    /** And the separator the merchant actually asked for is still saved. */
    public function test_a_save_that_names_no_currency_still_saves_the_separator(): void
    {
        [$merchant, $store, ] = $this->arrange('SYP', ['USD' => [1.0, '$'], 'SYP' => [10.0, 'SYP']]);

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'decimal_separator' => 'comma',
            'thousand_separator' => 'dot',
        ]);

        $row = Setting::withoutGlobalScopes()
            ->where('store_id', $store->id)->where('name', 'decimal_separator')->first();

        $this->assertNotNull($row,
            'THE CONTROL BROKE: refusing to invent a currency must not refuse the save the '
            . 'merchant actually made. The separator is what they came to change.');
        $this->assertSame('comma', (string) $row->value);
    }

    /*
    |--------------------------------------------------------------------------
    | THE CONTROL — a deliberate change must still work, including out of USD
    |--------------------------------------------------------------------------
    */

    /**
     * LEAVING USD STILL WORKS, which is the clause of the row I decline.
     *
     * `prepare()` skips its "the currency being left needs a usable rate" guard for USD on purpose:
     * `getRate()` answers 1.0 for USD without reading the table, so nothing can throw mid-way.
     * Extending the guard — as the row prescribes — would refuse this legitimate change for no
     * safety gain. Asserted so that extension cannot be made silently.
     */
    public function test_a_deliberate_change_out_of_usd_is_not_refused(): void
    {
        [$merchant, $store, $product] = $this->arrange('USD', ['USD' => [1.0, '$'], 'SYP' => [10.0, 'SYP']]);

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'defult_currancy' => 'SYP-SYP',
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ]);

        $this->assertSame('SYP', $this->storedCurrency($store->id),
            'a deliberate change OUT OF USD is now refused. If `prepare()`\'s guard was extended '
            . 'to USD, that is the clause GAP-291 prescribes and this case explains why it should '
            . 'not be: there is no missing rate to refuse, and this blocks a legitimate change.');
        $this->assertNotSame(self::PRICE, $this->priceOf($product),
            'the deliberate change stored the label but did not convert the catalogue, which '
            . 'would leave prices in the old currency under a new name');
    }

    /** And submitting the SAME currency, in any case, converts nothing. */
    public function test_resubmitting_the_same_currency_converts_nothing(): void
    {
        [$merchant, $store, $product] = $this->arrange('syp', ['SYP' => [10.0, 'SYP']]);

        $this->actingAs($merchant)->post(route('currency.settings'), [
            'defult_currancy' => 'SYP-SYP',
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ]);

        $this->assertSame(self::PRICE, $this->priceOf($product),
            'resubmitting the stored currency in a different case converted the catalogue. '
            . '`apply()` normalises both sides with strtoupper(trim()), so this must be a no-op.');
    }
}
