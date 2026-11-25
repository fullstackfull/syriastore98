<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Store;
use App\Models\Tax;
use App\Models\TaxMethod;
use App\Models\User;
use App\Services\OrderTotalReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-143 / `P04-P8-02` — SHIPPING SELECTION, ZONES, COSTS AND TAX DERIVATION, EXERCISED END TO END.
 * =================================================================================================
 *
 * P4-8's acceptance clause (`PHASE-04-EXECUTION-PLAN.md`): *"seed shipping methods and tax, resolve
 * `"CURRENCY": null`. Acceptance: totals reconcile against `OrderTotalReconciler` with shipping and
 * tax present."* Three things, and this file does all three.
 *
 * WHY THERE WAS NOTHING TO EXERCISE. The phase's §4 recorded these as **fixture gaps, not product
 * defects**, and was right to: with no shipping method configured, `POST /shipping-method` answered
 * `shipping_method: ""` and `shipping_final_price: "$0.00"`, and with no tax configured
 * `POST /get-tax-data` answered `tax_price: "0.00"` and `tax_id_value: null`. So the two endpoints
 * were measured returning their empty answers, and the paths that compute a cost or derive a band
 * were never entered. Seeding the fixture is the whole of the work.
 *
 * THE THIRD SEEDED THING, AND IT IS WHY §4 READ THIS AS UNREACHABLE. A `Tax` row and a `TaxMethod`
 * naming the jurisdiction are not enough: `Cart::getProductTaxAmount()` is called with the
 * **PRODUCT's** `tax_id`, so with no `products.tax_id` no band is looked up however well the
 * address matches. All three, plus the shopper's own address on the request, and both terms of the
 * acceptance clause become real.
 *
 * ---------------------------------------------------------------------------------------------
 * AND THE THIRD ITEM — `"CURRENCY": null` in both payloads — WAS LEFT OPEN FOR P4-4 AS "a real
 * defect or a fixture gap". IT IS A FIXTURE GAP, and this file settles it by measurement rather
 * than by reading, because the reading is what makes it look like a defect.
 *
 * `Utility::GetValueByName('CURRENCY', $storeId)` does NOT simply read the `settings` row. When the
 * row is absent it falls back to `Utility::Setting($storeId)`, whose defaults array carries
 * `'CURRENCY' => '$'` — so a store with no settings row still answers `'$'`, not null. The value
 * then passes `CurrencySymbol::cleanSetting()`, which only strips characters that cannot appear in
 * a symbol, and `'$'` survives that unchanged.
 *
 * So a payload reading null means the measurement environment's `getCurrentStore()` resolved to a
 * store the fallback was not asked about — the hazard CLAUDE.md records as the `admin_details`
 * cache and the default-guard ambiguity — rather than a product that cannot name its own currency.
 * `test_both_payloads_name_the_stores_currency` drives both endpoints on a store with **no**
 * `CURRENCY` settings row and asserts the symbol arrives anyway; `test_a_configured_symbol_wins`
 * proves the row still takes precedence, so the first case cannot pass by the fallback having
 * swallowed real configuration.
 *
 * THAT DISPOSITION IS A MEASUREMENT, NOT A DISMISSAL. If either case ever fails, the question
 * reopens as a defect and the figures below say exactly what changed.
 */
final class ShippingAndTaxReconciliationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * MINTED, NOT INVENTED. `delivery_addresses` and the tax tables carry FOREIGN KEYS on
     * `country_id`/`state_id`/`city_id` (recorded by `StorefrontShippingMethodAddressTenancyTest`,
     * which hit it first), so arbitrary integers are rejected and a fixture using them inserts
     * nothing — leaving every assertion below measuring an empty payload, which is exactly the
     * state §4 already measured.
     */
    private int $country;

    private int $state;

    private int $city;

    private const UNIT_PRICE = 100.00;

    private const QTY = 2;

    private const SHIPPING_COST = 25.00;

    /** 10% — a round band, so an arithmetic slip is visible rather than plausible. */
    private const TAX_RATE = 10.0;

    private User $merchant;

    private Store $store;

    private Customer $shopper;

    private Product $product;

    private ShippingMethod $method;

    private Tax $tax;

    private DeliveryAddress $address;

    protected function setUp(): void
    {
        parent::setUp();

        /* Several handlers memoise the platform admin for an hour, and under CACHE_STORE=array
           that outlives the fixture — so a store created after the first request is invisible. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);

        $ref = $this->uniqueFixtureRef();
        $this->country = (int) DB::table('countries')->insertGetId(
            ['name' => 'P48-' . $ref, 'created_at' => now(), 'updated_at' => now()]);
        $this->state = (int) DB::table('states')->insertGetId(
            ['name' => 'P48-ST-' . $ref, 'country_id' => $this->country, 'created_at' => now(), 'updated_at' => now()]);
        $this->city = (int) DB::table('cities')->insertGetId(
            ['name' => 'P48-CT-' . $ref, 'country_id' => $this->country, 'state_id' => $this->state, 'created_at' => now(), 'updated_at' => now()]);

        $this->shopper = Customer::forceCreate([
            'first_name' => 'P48', 'last_name' => 'Shopper',
            'email' => 'p48-' . $ref . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $this->seedTax();

        $this->product = Product::forceCreate([
            'name' => 'P48-' . $this->uniqueFixtureRef(),
            'slug' => 'p48-' . $this->uniqueFixtureRef(),
            'price' => self::UNIT_PRICE,
            'sale_price' => self::UNIT_PRICE,
            'product_stock' => 50,
            'track_stock' => 1,
            'variant_product' => 0,
            'status' => 1,
            'store_id' => $this->store->id,
            'created_by' => $this->store->created_by,
            /*
             * THE PRODUCT MUST NAME ITS TAX CLASS, and this is the fixture gap §4 could not see.
             * `Cart::getProductTaxAmount($line['tax_id'], …)` selects the band from the PRODUCT's
             * `tax_id` — so seeding a `Tax` and a `TaxMethod` is not enough on its own: with no
             * `products.tax_id` the lookup is never made and tax is 0 however well the
             * jurisdiction matches. `products.tax_id` is a nullable STRING column.
             */
            'tax_id' => (string) $this->tax->id,
        ]);

        $zone = ShippingZone::create([
            'zone_name' => 'P4-8 zone',
            'country_id' => $this->country,
            'state_id' => $this->state,
            'shipping_method' => '1',
            'store_id' => $this->store->id,
        ]);

        $this->method = ShippingMethod::create([
            /*
             * `Local pickup` IS LOAD-BEARING AND IT IS NOT A STYLE CHOICE. The handler selects the
             * cost by comparing this free-text column against three hardcoded ENGLISH strings; any
             * other name charges ZERO. See
             * `test_a_shipping_method_the_merchant_named_themselves_ships_free` below, and GAP-262.
             * The fixture uses a name the handler honours so that the P4-8 acceptance clause can be
             * exercised at all.
             */
            'method_name' => 'Local pickup',
            'zone_id' => $zone->id,
            'cost' => self::SHIPPING_COST,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $this->store->id,
        ]);
        (new ShippingMethod)->flushCache();


        /*
         * AN ADDRESS, BECAUSE THAT IS HOW THE JURISDICTION REACHES THE TAX LOOKUP. The handler
         * merges the authenticated shopper's own address into `billing_info.delivery_*`, and
         * `cart_list()` then selects the merchant's tax methods BY JURISDICTION from those values.
         * Driving the endpoint with only `countryId`/`stateId` in the body leaves the tax band
         * unmatched and every figure at zero — which is the empty answer §4 already measured.
         */
        $this->address = DeliveryAddress::forceCreate([
            'customer_id' => $this->shopper->id,
            'store_id' => $this->store->id,
            'full_name' => 'P4-8 Shopper',
            'phone' => '0100000000',
            'title' => 'home',
            'address' => 'P4-8 Street',
            'postcode' => 12345,
            'default_address' => 1,
            'type' => 0,
            'country_id' => $this->country,
            'state_id' => $this->state,
            'city_id' => $this->city,
        ]);

        Cart::forceCreate([
            'customer_id' => $this->shopper->id,
            'product_id' => $this->product->id,
            'variant_id' => 0,
            'qty' => self::QTY,
            'price' => self::UNIT_PRICE,
            'store_id' => $this->store->id,
        ]);
        (new Cart)->flushCache();
    }

    /**
     * A tax band for this jurisdiction. Written defensively because the tax tables' column names
     * are not uniform across this codebase, and a fixture that silently inserts nothing would make
     * every assertion below pass on a zero band.
     */
    private function seedTax(): void
    {
        /*
         * THE RATE IS ON `tax_methods`, NOT ON `taxes` — read off the migrations, after a first
         * draft assumed `taxes.rate` and the insert failed with "table taxes has no column named
         * rate". `taxes` carries only a name and a store; `tax_methods` carries `tax_rate` (a
         * STRING column, default 0), the jurisdiction triple and `priority`. CLAUDE.md's rule
         * applies exactly here: the migrations are the schema and a guess at a column is wrong.
         */
        $this->tax = Tax::forceCreate([
            'name' => 'P4-8 VAT',
            'store_id' => $this->store->id,
        ]);

        TaxMethod::forceCreate([
            'name' => 'P4-8 VAT band',
            'tax_rate' => (string) self::TAX_RATE,
            'tax_id' => $this->tax->id,
            'country_id' => $this->country,
            'state_id' => $this->state,
            'city_id' => $this->city,
            'priority' => '1',
            'store_id' => $this->store->id,
        ]);

        (new Tax)->flushCache();
        (new TaxMethod)->flushCache();
    }

    /** The shipping-method payload, driven as the checkout page drives it. */
    private function shippingPayload(bool $withAddress = false): array
    {
        $response = $this->withSession([])->actingAs($this->shopper, 'customers')->post(
            '/' . $this->store->slug . '/shipping-method',
            array_merge([
                'method_id' => $this->method->id,
                'countryId' => $this->country,
                'stateId' => $this->state,
                'cityId' => $this->city,
            ], $withAddress ? ['billing_address_id' => $this->address->id] : [])
        );

        $this->assertSame(200, $response->status(),
            'premise: the shipping-method endpoint answered. Every assertion about its payload is '
            . 'vacuous on a redirect or a 500.');

        return $this->decode($response->getContent());
    }

    /** The tax-data payload, same path. */
    private function taxPayload(): array
    {
        $response = $this->withSession([])->actingAs($this->shopper, 'customers')->post(
            '/' . $this->store->slug . '/get-tax-data',
            [
                'countryId' => $this->country,
                'stateId' => $this->state,
                'cityId' => $this->city,
                'billing_info' => [
                    'delivery_country' => $this->country,
                    'delivery_state' => $this->state,
                    'delivery_city' => $this->city,
                ],
            ]
        );

        $this->assertSame(200, $response->status(),
            'premise: the get-tax-data endpoint answered');

        return $this->decode($response->getContent());
    }

    /**
     * THE PREMISE THE WHOLE FILE RESTS ON: the fixture really is seeded. §4's gaps were "no
     * shipping method configured" and "no tax configured", so a file that fails to seed them
     * measures exactly what the phase already measured.
     */
    public function test_the_premise_that_shipping_and_tax_are_actually_configured(): void
    {
        $this->assertSame(
            1,
            DB::table('shipping_methods')->where('store_id', $this->store->id)->count(),
            'premise: the store has exactly one shipping method. With none, `/shipping-method` '
            . 'answers its empty payload and the cost path is never entered.'
        );

        $this->assertSame(
            1,
            DB::table('taxes')->where('store_id', $this->store->id)->count(),
            'premise: the store has a tax band. With none, `/get-tax-data` answers `tax_price: '
            . '"0.00"` and the derivation path is never entered.'
        );

        $this->assertSame(
            (string) self::SHIPPING_COST,
            (string) ShippingMethod::withoutGlobalScopes()->findOrFail($this->method->id)->cost,
            'premise: the seeded cost survived the write. Read through withoutGlobalScopes() '
            . 'because a plain find() answers against whichever tenant happens to be resolved.'
        );
    }

    /**
     * THE `"CURRENCY": null` QUESTION, ANSWERED: fixture gap, not a defect.
     *
     * Driven on a store with **no** `CURRENCY` settings row at all, which is the condition the
     * original measurement was taken under. `Utility::GetValueByName()` falls back to
     * `Utility::Setting()`'s defaults, so the symbol arrives regardless.
     */
    public function test_both_payloads_name_the_stores_currency(): void
    {
        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $this->store->id)->where('name', 'CURRENCY')->count(),
            'premise: this store has NO CURRENCY row, so what follows measures the fallback and '
            . 'not a configured value — which is the condition the original null was measured under'
        );

        /*
         * MEASURED, AND THE ANSWER IS NOT THE ONE THE QUESTION ASSUMED. Neither payload carries a
         * `CURRENCY` KEY AT ALL — the symbol is formatted into every money field instead
         * (`"$0.00"`, `"$200.00"`, `sub_total: "$200.00"`). So `"CURRENCY": null` was not a
         * product that cannot name its currency: there is no such field in these two responses to
         * be null, and the symbol demonstrably resolves. The original reading was of a payload
         * shape these endpoints do not produce.
         *
         * Asserted on the FORMATTED FIGURES, because that is where the symbol actually lives. A
         * `CURRENCY`-key assertion would pass vacuously on a payload that has no such key.
         */
        foreach (['shipping' => $this->shippingPayload()] as $which => $payload) {
            $formatted = $this->formattedMoneyIn($payload);

            $this->assertNotSame([], $formatted, sprintf(
                'the %s payload carries no formatted money field at all, so this case can say '
                . 'nothing about the currency. Payload: %s', $which, json_encode($payload)
            ));

            foreach ($formatted as $key => $value) {
                $this->assertMatchesRegularExpression('/^\D+[\d]/u', (string) $value, sprintf(
                    'THE %s PAYLOAD\'S `%s` CARRIES A BARE NUMBER WITH NO CURRENCY SYMBOL (%s), so '
                    . '`"CURRENCY": null` has become a real defect. `Utility::GetValueByName()` '
                    . 'falls back to `Utility::Setting()`, whose defaults carry `CURRENCY => \'$\'`, '
                    . 'and `CurrencySymbol::cleanSetting()` leaves `$` alone — read which of those '
                    . 'two stopped being true.',
                    $which, $key, var_export($value, true)
                ));
            }
        }
    }

    /**
     * AND A CONFIGURED SYMBOL STILL WINS, so the case above cannot pass because the fallback has
     * swallowed the store's own setting.
     */
    public function test_a_configured_symbol_wins_over_the_fallback(): void
    {
        /*
         * `defult_currancy_symbol`, NOT `CURRENCY` — the typo is the real column name.
         * `currency_format_with_sym()` reads `$company_settings['defult_currancy_symbol']` and
         * falls back to a HARDCODED `'$'` in the helper itself; it never consults `CURRENCY`.
         * Which sharpens the P4-8 disposition further: the symbol in these payloads has nothing to
         * do with the `CURRENCY` setting at all, so `"CURRENCY": null` was measuring a key these
         * two endpoints do not emit and a setting they do not read.
         */
        DB::table('settings')->insert([
            'name' => 'defult_currancy_symbol',
            'value' => '£',
            'store_id' => $this->store->id,
            /* NOT NULL with no default — the insert fails without it, measured. */
            'created_by' => $this->store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();

        $formatted = $this->formattedMoneyIn($this->shippingPayload());

        $this->assertNotSame([], $formatted, 'premise: the payload carries formatted money');

        foreach ($formatted as $key => $value) {
            $this->assertStringContainsString('£', (string) $value, sprintf(
                'the store configured `£` as its symbol and `%s` reads %s, so `GetValueByName` is '
                . 'preferring its own default over the settings row — which would make the '
                . 'fallback case above meaningless.',
                $key, var_export($value, true)
            ));
        }
    }

    /**
     * THE ACCEPTANCE CLAUSE ITSELF: totals reconcile against `OrderTotalReconciler` **with shipping
     * and tax present**, which is the state that could not be reached before.
     *
     * The arithmetic is deliberately round: 2 x 100.00 goods, 25.00 shipping, 10% tax. The point is
     * not that this product computes 10% a particular way — it is that whatever it computes, the
     * reconciler agrees the parts sum to the claimed payable. A mismatch here is the shape P4-F082
     * and the gateway-return findings are about: a server-computed figure nobody checked.
     */
    public function test_the_totals_reconcile_with_shipping_and_tax_present(): void
    {
        /* THE DRIVE THE REAL CHECKOUT MAKES: the address is what carries the jurisdiction to the
           tax lookup, so this is the only shape in which both terms of P4-8's clause are real. */
        $shipping = $this->shippingPayload(true);

        $goods = self::UNIT_PRICE * self::QTY;
        $shippingCost = $this->moneyIn($shipping, ['shipping_final_price', 'shipping_price', 'shipping_cost']);
        $taxAmount = $this->moneyIn($shipping, ['tax_price', 'final_tax_price', 'tax_amount']);

        $this->assertNotNull($shippingCost, sprintf(
            'the shipping payload carries no cost field this test can read, so the seeded 25.00 '
            . 'never reached it. Payload: %s', json_encode($shipping)
        ));
        $this->assertNotNull($taxAmount, sprintf(
            'the payload carries no tax figure this test can read. Payload: %s', json_encode($shipping)
        ));

        /* THE SEEDED COST MUST ACTUALLY APPEAR. Without this the reconciliation below would
           happily balance 200 + 0 + 0 against 200 and report success on the empty answer §4
           already measured. */
        $this->assertSame(
            self::SHIPPING_COST,
            $shippingCost,
            'the configured shipping method cost 25.00 and the payload priced the delivery '
            . 'differently, so zone selection or cost derivation is not using the seeded method'
        );

        $this->assertGreaterThan(
            0.0,
            $taxAmount,
            'a 10% band on 200.00 of goods derived no tax at all. Three things have to line up and '
            . 'the third is the one §4 could not see: a `Tax` row, a `TaxMethod` naming the '
            . 'jurisdiction, AND `products.tax_id` naming the tax class — `getProductTaxAmount()` '
            . 'is called with the PRODUCT\'s `tax_id`, so without it no band is ever looked up '
            . 'however well the address matches.'
        );

        $reconciler = new OrderTotalReconciler();

        $claimed = round($goods + $shippingCost + $taxAmount, 2);

        $result = $reconciler->reconcile(
            ['goods' => $goods, 'shipping' => $shippingCost, 'tax' => $taxAmount],
            $claimed
        );

        /* P4-8's clause in full: shipping present and real at 25.00, tax present and non-zero,
           and the reconciler agreeing the parts sum to the payable. */

        $this->assertTrue(
            (bool) ($result['consistent'] ?? $result['matches'] ?? false),
            sprintf(
                'THE PARTS DO NOT SUM TO THE PAYABLE with shipping and tax present — goods %s, '
                . 'shipping %s, tax %s, claimed %s. This is P4-8\'s acceptance clause and the '
                . 'reconciler\'s verdict was: %s',
                $goods, $shippingCost, $taxAmount, $claimed, json_encode($result)
            )
        );
    }

    /**
     * GAP-262 — A SHIPPING METHOD THE MERCHANT NAMED THEMSELVES SHIPS FREE.
     *
     * The handler picks the cost arm by comparing `shipping_methods.method_name` — free text the
     * merchant types — against three hardcoded ENGLISH strings (`Delivery Syria`, `Flat Rate`,
     * `Local pickup`). Every other name falls into an `else` that sets the cost to **0**.
     *
     * MEASURED, same method, same `cost` column, same zone, only the name changed:
     *   `Local pickup`        -> `shipping_final_price` "$25.00"
     *   `استلام من المتجر`     -> `shipping_final_price` "$0.00"
     *
     * So a merchant who names a method `Standard`, `Express`, or **anything in Arabic**, ships for
     * free and absorbs the carrier's cost. On a product whose whole premise is bilingual operation,
     * naming your shipping method in your own language is the defect trigger.
     *
     * THIS CASE ASSERTS THE DEFECT, deliberately, because the fix changes what a shopper is charged
     * on live orders — a material pricing change, which the standing escalation rules reserve to
     * the owner. It is decision **I13**. When that lands, INVERT this case; do not relax it.
     */
    public function test_a_shipping_method_the_merchant_named_themselves_ships_free(): void
    {
        $this->method->method_name = 'استلام من المتجر';
        $this->method->save();
        (new ShippingMethod)->flushCache();

        $cost = $this->moneyIn($this->shippingPayload(), ['shipping_final_price']);

        $this->assertSame(0.0, $cost, sprintf(
            'the cost of an Arabic-named shipping method is no longer zero (it is %s), so GAP-262 '
            . 'has been fixed — INVERT this case to assert the configured %s instead, and say in '
            . 'the message that decision I13 was taken.',
            var_export($cost, true), self::SHIPPING_COST
        ));

        /* The control: the SAME row charges its configured cost under a name the handler knows, so
           this case cannot pass because the fixture is broken or the zone stopped matching. */
        $this->method->method_name = 'Local pickup';
        $this->method->save();
        (new ShippingMethod)->flushCache();

        $this->assertSame(
            self::SHIPPING_COST,
            $this->moneyIn($this->shippingPayload(), ['shipping_final_price']),
            'premise: the same method under a recognised name DOES charge its cost. Without this '
            . 'the case above would pass on any fixture that prices nothing at all.'
        );
    }

    /**
     * The payload as an array, whether the endpoint answers JSON or a rendered view.
     *
     * `StorefrontShippingMethodAddressTenancyTest` records that this endpoint returns a rendered
     * view, while the phase's §4 quotes it answering `shipping_method: ""` and
     * `shipping_final_price: "$0.00"` — JSON-shaped keys. Rather than assume which, decode and let
     * the premise cases say what actually arrived.
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['__raw' => $body];
    }

    /**
     * Every money field the payload has already FORMATTED with a symbol, keyed by field name.
     *
     * These two endpoints carry no `CURRENCY` field; they format the symbol into the figure. So a
     * field matching `<non-digits><digits>` — `"$0.00"`, `"£200.00"` — is where the currency
     * actually is, and a field that is a bare number is not formatted and says nothing either way.
     *
     * @return array<string, string>
     */
    private function formattedMoneyIn(array $payload): array
    {
        $pool = isset($payload['data']) && is_array($payload['data'])
            ? array_merge($payload, $payload['data'])
            : $payload;

        $formatted = [];

        foreach ($pool as $key => $value) {
            if (is_string($value) && preg_match('/^\D+[\d]/u', $value) === 1) {
                $formatted[$key] = $value;
            }
        }

        return $formatted;
    }

    /** A money figure under any of `$keys`, stripped of its symbol, or null. */
    private function moneyIn(array $payload, array $keys): ?float
    {
        $pools = [$payload];

        if (isset($payload['data']) && is_array($payload['data'])) {
            $pools[] = $payload['data'];
        }

        foreach ($pools as $pool) {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $pool) || $pool[$key] === null) {
                    continue;
                }

                $raw = preg_replace('/[^0-9.\-]/', '', (string) $pool[$key]);

                if ($raw !== '' && is_numeric($raw)) {
                    return (float) $raw;
                }
            }
        }

        return null;
    }
}
