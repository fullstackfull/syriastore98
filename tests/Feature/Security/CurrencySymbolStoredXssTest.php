<?php

namespace Tests\Feature\Security;

use App\Models\CurrencyRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\DetectsActiveMarkup;
use Tests\Support\Security\MinimalRows;
use Tests\Support\Security\ReadsDataTableCells;

/**
 * P1-SEC-80, round 4 -- the store's currency SYMBOL was free text from the request, and money is printed raw in the lists and at the till.
 * ============================================================================================================================================
 *
 * The currency tab of the Settings screen posts `<symbol>-<code>`. `SettingController::currencySettings` checked the CODE against the store's own currency rows and stored whatever stood
 * before the first hyphen as the symbol. The four money formatters in `app/Helper/helper.php` put that symbol in front of (or behind) every amount, and an amount is printed raw in more
 * places than a census keeps: the product list's `price` and the order list's `final_price` are raw DataTable columns, the till's cart row is HTML concatenated in a controller, and the
 * till's script writes the variant price from a JSON answer with `.html()`.
 *
 * So a member of staff who may save settings stored markup as the symbol, and the owner's product list and till carried it as live markup (executed before the fix: three of the cases below
 * failed). The same person can already reach the owner's pages through the store's custom script, which is a recorded decision of the owner's; this door was not a feature of anything.
 *
 * Two layers. The handler takes the symbol from the store's own currency row and never from the request. And the value is cleaned where it is READ (`App\Support\Money\CurrencySymbol`),
 * which is the control: other handlers write every posted name into `settings`, and a row stored before the rule existed is still in the table.
 */
class CurrencySymbolStoredXssTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use MinimalRows;
    use ReadsDataTableCells;
    use RefreshDatabase;

    /** No hyphen in it: the handler splits the posted value at every `-`. The oracle's image rule needs no marker. */
    private const PAYLOAD = '<img src=x onerror=XSS5>';

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    /** @return array{0:\App\Models\User,1:\App\Models\Store,2:\App\Models\User} the owner, the store, and a member of staff who holds `Manage Setting` */
    private function ownerStoreAndSettingsStaff(): array
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Setting');
        // a symbol that is not the default, so that "the row's symbol" and "the fallback" cannot be mistaken for each other
        CurrencyRate::create(['store_id' => $store->id, 'currency_code' => 'USD', 'currency_name' => 'US Dollar', 'symbol' => 'US$', 'rate_per_usd' => 1, 'is_active' => 1, 'created_by' => $owner->id]);

        return [$owner, $store, $staff];
    }

    private function saveCurrency(\App\Models\User $writer, string $symbol)
    {
        return $this->actingAs($writer)->post('/currency-settings', ['defult_currancy' => $symbol . '-USD', 'site_currency_symbol_position' => 'pre']);
    }

    private function writeRawSetting(\App\Models\User $owner, string $name, string $value): void
    {
        DB::table('settings')->insert(['name' => $name, 'value' => $value, 'store_id' => $owner->current_store, 'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function hostileSymbolAlreadyStored(\App\Models\User $owner): void
    {
        $this->writeRawSetting($owner, 'defult_currancy_symbol', self::PAYLOAD);
        $this->writeRawSetting($owner, 'CURRENCY', self::PAYLOAD);
    }

    private function product(\App\Models\Store $store): Product
    {
        $product = $this->minimalRow(Product::class, [
            'store_id' => $store->id, 'name' => 'Plain', 'slug' => 'plain', 'price' => 10, 'status' => 1, 'variant_product' => 0, 'product_type' => null, 'product_stock' => 5, 'tax_id' => 0,
        ]);
        $this->minimalRow(ProductVariant::class, ['product_id' => $product->id, 'store_id' => $store->id, 'variant' => 'red', 'sku' => 'S1', 'price' => 10, 'stock' => 5]);

        return $product;
    }

    /** @var array<int,true> owners who already hold the list's permission (granting it twice is a duplicate row) */
    private array $mayListProducts = [];

    private function priceCellOfTheProductList(\App\Models\User $owner): string
    {
        if (! isset($this->mayListProducts[$owner->id])) {
            $this->grantPermission($owner, 'Manage Products');
            $this->mayListProducts[$owner->id] = true;
        }
        $envelope = $this->listEnvelope($owner, '/product');
        $this->assertSame(1, (int) $envelope['recordsTotal'], 'premise: the list holds the one product, or an empty list passes for the wrong reason');

        return (string) $envelope['data'][0]['price'];
    }

    // ------------------------------------------------------------------ premises

    public function test_the_oracle_sees_the_payload_when_it_is_printed_raw(): void
    {
        $this->assertContains('an image with a handler', $this->activeMarkupIn('<td>' . self::PAYLOAD . '10.00</td>'), 'premise: the oracle finds the payload when it is printed raw');
        $this->assertSame([], $this->activeMarkupIn('<td>' . e(self::PAYLOAD) . '10.00</td>'), 'premise: and does not find it escaped');
    }

    /** What the real form posts is the currency row's own symbol. It reached the price cell before the fix and reaches it now: the pipeline is connected, and the honest flow is unchanged. */
    public function test_a_symbol_the_settings_screen_saves_is_what_the_product_list_prints(): void
    {
        [$owner, $store, $staff] = $this->ownerStoreAndSettingsStaff();
        $this->product($store);

        $this->saveCurrency($staff, 'US$')->assertRedirect();

        $this->assertNull(session('error'), 'premise: the handler accepted the save');
        $this->assertSame('US$10.00', $this->priceCellOfTheProductList($owner), 'premise: a symbol saved on the Settings screen is what the list prints in front of the amount');
    }

    // ------------------------------------------------------------------ the first layer: the request does not choose the symbol

    public function test_the_settings_handler_takes_the_symbol_from_the_currency_row_and_not_from_the_request(): void
    {
        [$owner, $store, $staff] = $this->ownerStoreAndSettingsStaff();
        $this->product($store);

        $this->saveCurrency($staff, self::PAYLOAD)->assertRedirect();

        $this->assertNull(session('error'), 'premise: the save was accepted (the code is the store\'s own), so what follows is about the symbol and not about a refusal');
        $stored = DB::table('settings')->where('store_id', $store->id)->pluck('value', 'name')->all();
        $this->assertSame('US$', $stored['defult_currancy_symbol'] ?? null, 'the symbol stored is the store\'s own currency row\'s');
        $this->assertSame('US$', $stored['CURRENCY'] ?? null);
        $this->assertNotContains(self::PAYLOAD, array_values($stored), 'the request chose the store\'s currency symbol');
        $this->assertSame('US$10.00', $this->priceCellOfTheProductList($owner));
    }

    /** The currency row is a stored value as well (the admin API writes it, ten characters at most): what the handler copies from it is cleaned on the way. */
    public function test_a_currency_rows_own_symbol_is_cleaned_when_the_handler_stores_it(): void
    {
        [$owner, $store, $staff] = $this->ownerStoreAndSettingsStaff();
        CurrencyRate::where('store_id', $store->id)->where('currency_code', 'USD')->firstOrFail()->update(['symbol' => '<i>US$']);

        $this->saveCurrency($staff, '<i>US$')->assertRedirect();

        $this->assertNull(session('error'), 'premise: the save was accepted');
        $this->assertSame('iUS$', DB::table('settings')->where('store_id', $store->id)->where('name', 'defult_currancy_symbol')->value('value'), 'the handler stored a currency row\'s symbol with markup characters in it');
    }

    // ------------------------------------------------------------------ the second layer, which is the control: a hostile value already in the table

    public function test_a_hostile_symbol_in_the_table_is_not_markup_on_the_product_list(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->product($store);
        $this->hostileSymbolAlreadyStored($owner);

        $cell = $this->priceCellOfTheProductList($owner);

        $this->assertSame([], $this->activeMarkupIn($cell), 'the product list printed the stored currency symbol as markup');
        $this->assertStringEndsWith('10.00', $cell, 'the amount is still printed');
        $this->assertStringNotContainsString('<', $cell);
    }

    public function test_a_hostile_symbol_in_the_table_is_not_markup_in_the_tills_cart_row(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->grantPermission($owner, 'Create Pos');
        $product = $this->product($store);
        $this->hostileSymbolAlreadyStored($owner);

        $response = $this->actingAs($owner)->withHeaders(self::AJAX)->getJson('/addToCart/' . $product->id . '/pos_' . $store->id);

        $response->assertOk();
        $row = (string) $response->json('carthtml');
        $this->assertStringContainsString('10.00', $row, 'premise: the row was built and carries the price');
        $this->assertSame([], $this->activeMarkupIn($row), 'the till\'s cart row printed the stored currency symbol as markup');
    }

    /** The till's script writes this answer's `price` into the page with `.html()`. */
    public function test_a_hostile_symbol_in_the_table_is_not_markup_in_the_variant_price_the_till_writes(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $product = $this->product($store);
        $this->hostileSymbolAlreadyStored($owner);

        $response = $this->actingAs($owner)->withHeaders(self::AJAX)
            ->getJson('/get-products-variant-quantity?' . http_build_query(['product_id' => $product->id, 'variants' => 'red', 'session_key' => 'pos_' . $store->id]));

        $response->assertOk();
        $this->assertTrue((bool) $response->json('status'), 'premise: the variant was found, so the price is the formatted one');
        $price = (string) $response->json('price');
        $this->assertMatchesRegularExpression('/\d/', $price, 'premise: the answer carries an amount');
        $this->assertSame([], $this->activeMarkupIn('<span>Price : ' . $price . '</span>'), 'the variant price the till writes with .html() carried the stored currency symbol as markup');
        $this->assertStringNotContainsString('<', $price);
    }

    /** An order keeps its OWN snapshot of the symbol, and the order list's amount is a raw column: a snapshot taken while a hostile symbol was stored is a stored value too. */
    public function test_a_hostile_symbol_snapshotted_on_an_order_is_not_markup_on_the_order_list(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->grantPermission($owner, 'Manage Order');
        $order = $this->makeOrder($store, ['final_price' => 25]);
        $order->currency_symbol = self::PAYLOAD;
        $order->save();
        $this->assertSame(self::PAYLOAD, DB::table('orders')->where('id', $order->id)->value('currency_symbol'), 'premise: the order row holds the hostile snapshot');

        $formatted = order_currency_format_with_sym(25, $order->id);
        $this->assertStringContainsString('25.00', $formatted, 'premise: the order\'s formatter printed the amount');
        $this->assertSame([], $this->activeMarkupIn('<td>' . $formatted . '</td>'), 'the order formatter printed the order\'s snapshot of the symbol as markup');

        $envelope = $this->listEnvelope($owner, '/order');
        $this->assertSame(1, (int) $envelope['recordsTotal'], 'premise: the list holds the order');
        $this->assertStringContainsString('25.00', (string) $envelope['data'][0]['final_price'], 'premise: the amount cell is the formatted amount');
        $this->assertStringNotContainsString('<img', (string) $envelope['data'][0]['final_price'], 'the order list printed the order\'s snapshot of the symbol as markup');
    }

    /** A new order takes its snapshot through the settings reader, so a hostile row is not copied into `orders` either. */
    public function test_a_new_order_does_not_snapshot_a_hostile_symbol(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->hostileSymbolAlreadyStored($owner);

        $order = $this->makeOrder($store, ['final_price' => 25]);

        $snapshot = (string) DB::table('orders')->where('id', $order->id)->value('currency_symbol');
        $this->assertNotSame('', $snapshot, 'premise: the order took a snapshot');
        $this->assertStringNotContainsString('<', $snapshot, 'a new order copied a hostile currency symbol into its own row');
    }

    /** One display format prints the currency's CODE where the symbol would stand. The code is a stored value too, and other handlers can write it by name. */
    public function test_a_hostile_code_printed_in_the_symbols_place_is_not_markup_on_the_product_list(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->product($store);
        $this->writeRawSetting($owner, 'site_currency_symbol_name', 'name');
        $this->writeRawSetting($owner, 'defult_currancy', 'KWD');
        $this->writeRawSetting($owner, 'defult_currancy_symbol', 'KD');
        $this->assertSame('KWD10.00', $this->priceCellOfTheProductList($owner), 'premise: with this format the list prints the code, not the symbol');

        \App\Models\Setting::where('store_id', $store->id)->where('name', 'defult_currancy')->firstOrFail()->update(['value' => self::PAYLOAD]);

        $cell = $this->priceCellOfTheProductList($owner);
        $this->assertSame([], $this->activeMarkupIn($cell), 'the product list printed the stored currency code as markup');
        $this->assertStringNotContainsString('<', $cell);
        $this->assertStringEndsWith('10.00', $cell);
    }

    /** The platform's own formatter (plans and platform pages) reads the operator's rows; a row stamped with the operator's id is a stored value like any other (P1-SEC-67c found a way to plant one). */
    public function test_the_platforms_own_formatter_cleans_the_symbol_too(): void
    {
        $operator = $this->makePlatformAdmin();
        \Illuminate\Support\Facades\Cache::forget('super_admin_details');
        $row = \App\Models\Setting::create(['name' => 'defult_currancy_symbol', 'value' => 'X$', 'store_id' => $operator->current_store, 'created_by' => $operator->id]);
        $this->assertSame('X$10.00', default_currency_format_with_sym(10), 'premise: the platform\'s formatter prints the operator\'s stored symbol');

        $row->update(['value' => self::PAYLOAD]);

        $formatted = default_currency_format_with_sym(10);
        $this->assertStringNotContainsString('<', $formatted, 'the platform\'s formatter printed the stored symbol as it is stored');
        $this->assertStringEndsWith('10.00', $formatted);
    }

    // ------------------------------------------------------------------ the reader: what it cleans and what it leaves alone

    public function test_the_settings_reader_cleans_a_symbol_and_leaves_everything_else_as_it_is_stored(): void
    {
        [$owner, $store] = $this->ownerStoreAndSettingsStaff();
        $this->hostileSymbolAlreadyStored($owner);
        $this->writeRawSetting($owner, 'footer_note', '<b>Thank you</b>');

        $this->assertStringNotContainsString('<', (string) Utility::GetValueByName('CURRENCY', $store->id), 'the reader handed out a stored symbol with markup in it');
        $this->assertStringNotContainsString('<', (string) Utility::GetValueByName('defult_currancy_symbol', $store->id));
        $this->assertSame('<b>Thank you</b>', Utility::GetValueByName('footer_note', $store->id), 'a setting that is not a symbol or a code is returned as it is stored');
        $this->assertNull(Utility::GetValueByName('currency_symbol', $store->id), 'a symbol that is not stored stays absent, so that a caller\'s `??` fallback still applies');
    }

    public function test_the_plain_formatters_clean_the_symbol_too(): void
    {
        [$owner] = $this->ownerStoreAndSettingsStaff();
        $this->hostileSymbolAlreadyStored($owner);
        $this->actingAs($owner);

        $this->assertStringNotContainsString('<', SetNumberFormat(10), 'SetNumberFormat() printed the stored symbol as it is stored');
        $this->assertStringContainsString('10.00', SetNumberFormat(10));
        $this->assertStringNotContainsString('<', (string) GetCurrency(), 'GetCurrency() handed out the stored symbol as it is stored');
    }
}
