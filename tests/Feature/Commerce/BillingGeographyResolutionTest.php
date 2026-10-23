<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\BillingGeography;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P1-ORD-15 — a posted city, state or country must never reach a foreign-key column raw.
 * =============================================================================================
 *
 * THE ROW'S FIRST ACCEPTANCE CRITERION IS A QUESTION, not a change: what does the client actually
 * send for `billing_state` / `billing_country` -- ids or names? The answer decides which of the
 * next two criteria applies. It is **ids**, established from both repositories and recorded in
 * `App\Services\BillingGeography`'s docblock, with the deciding evidence being
 * `OrderBillingDetail::BillingState()` -- `hasOne(State::class, 'id', 'state')` -- so even the
 * STRING columns `order_billing_details.state` / `.country` / `.city` hold ids and only
 * `Order::order_detail()` turns them into names.
 *
 * SO THERE WERE TWO DEFECTS, not the one the row names, and the row named the rarer of the two:
 *
 *   1. THE LOOKUP, which is the one that happens on every order. Twenty of the twenty-four sites
 *      ran `City::where('name', <a city id>)`, which can never match, so each order INSERTED a
 *      fresh `cities` row whose name is a number -- and `order_billing_details.city` then points
 *      at that junk row, which is what a merchant's order detail renders through
 *      `BillingCity->name`. No foreign key is violated there, because the state and country values
 *      ARE valid ids, which is why it accumulated silently rather than erroring.
 *   2. THE INSERT, which is the one the row measured. `state_id` and `country_id` took the posted
 *      value verbatim, so a caller posting NAMES -- which the public `place-order` route accepts,
 *      since nothing validates these three fields -- violates the foreign key. On SQLite the
 *      insert is refused and the handler 500s AFTER `$order->save()`.
 *
 * Both are closed by one resolver. These tests drive the resolver directly for the branch
 * arithmetic and drive `place-order` end to end for the two cases that used to crash or litter,
 * because a service test alone would not prove the twenty-four call sites were rewired.
 *
 * `City` IS `Cachable`, so every row assertion here reads `DB::table('cities')`. An Eloquent
 * `City::find()` in a test answers from the model cache under `CACHE_STORE=array` and has already
 * produced a false reading in this project twice.
 */
class BillingGeographyResolutionTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    // =========================================================================================
    //  Fixtures
    // =========================================================================================

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'ord15-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->seedGeography();

        return [$merchant, $store];
    }

    /** Syria(101) -> Damascus(1) and Idlib(2); cities Damascus(1) in state 1, Harem(2) in state 2. */
    private function seedGeography(): void
    {
        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 2, 'name' => 'Idlib', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 2, 'name' => 'Harem', 'country_id' => 101, 'state_id' => 2, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function cityRows(): int
    {
        return (int) DB::table('cities')->count();
    }

    private function cityRow($id)
    {
        return DB::table('cities')->where('id', $id)->first();
    }

    private function productIn($store, float $price, int $stock = 50): Product
    {
        return Product::withoutEvents(fn () => Product::forceCreate([
            'name' => 'Widget', 'slug' => 'w-' . uniqid(),
            'price' => $price, 'sale_price' => $price,
            'product_stock' => $stock, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Api', 'last_name' => 'Shopper',
            'email' => 'ord15-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** `billing_postecode` is misspelled in the handler, and every `delivery_*` key is required. */
    private function billing(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Api', 'lastname' => 'Shopper', 'email' => 'api@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 101, 'billing_state' => 1,
            'billing_city' => 1, 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 101, 'delivery_state' => 1,
            'delivery_city' => 1,
        ], $overrides);
    }

    private function placeOrder(Customer $shopper, $store, array $billing)
    {
        $product = $this->productIn($store, 100.00);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        return $this->withHeaders(['Authorization' => 'Bearer ' . $shopper->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/place-order', [
                'customer_id' => $shopper->id,
                'payment_type' => 'cod',
                'billing_info' => $billing,
            ]);
    }

    // =========================================================================================
    //  THE PREMISE. Three facts the rest of this file reasons from, asserted so a schema or
    //  relation change cannot make the tests below pass for the wrong reason.
    // =========================================================================================

    /**
     * `cities.state_id` and `cities.country_id` are foreign keys and the connection enforces them.
     * Without this, "no foreign-key violation" is satisfied by an engine that never checks.
     */
    public function test_the_city_foreign_keys_are_enforced_on_this_connection(): void
    {
        $this->seedGeography();

        $threw = false;

        try {
            DB::table('cities')->insert([
                'name' => 'Nowhere', 'state_id' => 999999, 'country_id' => 999999,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw,
            'This connection does not enforce the cities foreign keys, so every "no violation" '
            . 'assertion in this file is vacuous. The MySQL side is measured separately by '
            . 'tests/Support/Security/bin/verify-ord15-billing-geography-on-mysql.php.');
    }

    /**
     * `order_billing_details.state` is joined to `states.id`, which is the evidence that the posted
     * values are IDS -- the whole basis for choosing acceptance criterion 3 over criterion 2.
     */
    public function test_the_order_billing_detail_columns_are_joined_as_ids(): void
    {
        $relations = [
            'BillingCountry' => [\App\Models\Country::class, 'country'],
            'BillingState' => [\App\Models\State::class, 'state'],
            'BillingCity' => [\App\Models\City::class, 'city'],
            'DeliveryCountry' => [\App\Models\Country::class, 'delivery_country'],
            'DeliveryState' => [\App\Models\State::class, 'delivery_state'],
            'DeliveryCity' => [\App\Models\City::class, 'delivery_city'],
        ];

        $detail = new \App\Models\OrderBillingDetail();

        foreach ($relations as $method => [$related, $localKey]) {
            $this->assertTrue(method_exists($detail, $method), "OrderBillingDetail::{$method}() is gone.");

            $relation = $detail->{$method}();

            $this->assertInstanceOf($related, $relation->getRelated(),
                "OrderBillingDetail::{$method}() no longer points at {$related}.");
            $this->assertSame($localKey, $relation->getLocalKeyName(),
                "OrderBillingDetail::{$method}() no longer reads order_billing_details.{$localKey}, "
                . 'so the id-vs-name conclusion P1-ORD-15 rests on needs re-deriving.');
        }
    }

    /** The storefront select really is built id-keyed, which is where "the client posts ids" comes from. */
    public function test_the_storefront_country_select_is_keyed_by_id(): void
    {
        $this->seedGeography();

        $options = \App\Models\Country::orderBy('name', 'ASC')->pluck('name', 'id')->toArray();

        $this->assertArrayHasKey(101, $options,
            'Country::pluck(name, id) no longer keys by id, so the storefront select no longer '
            . 'posts country IDS and this package\'s premise is wrong.');
        $this->assertSame('Syria', $options[101]);
    }

    // =========================================================================================
    //  THE RESOLVER
    // =========================================================================================

    /** Criterion 3: an id is a lookup, not an insert. This is the live storefront's shape. */
    public function test_a_posted_city_id_resolves_to_itself_and_creates_nothing(): void
    {
        $this->seedGeography();
        $before = $this->cityRows();

        $this->assertSame(1, BillingGeography::resolveCityId(1, 1, 101));
        $this->assertSame(1, BillingGeography::resolveCityId('1', '1', '101'));

        $this->assertSame($before, $this->cityRows(),
            'A posted city id still inserts a city -- the lookup is not id-consistent.');
    }

    /** Criterion 2: a posted NAME is resolved to ids, and does not reach the foreign key. */
    public function test_posted_names_resolve_to_ids(): void
    {
        $this->seedGeography();
        $before = $this->cityRows();

        $this->assertSame(1, BillingGeography::resolveCityId('Damascus', 'Damascus', 'Syria'));

        $this->assertSame($before, $this->cityRows(),
            'A city posted by name that already exists must be found, not duplicated.');
    }

    /**
     * THE CENTRAL CASE: a city that does not exist, with names for the state and country. This is
     * the input that used to be refused by SQLite with a foreign-key violation after the order had
     * already been written.
     */
    public function test_an_unknown_city_is_created_with_resolved_ids_never_the_posted_names(): void
    {
        $this->seedGeography();

        $id = BillingGeography::resolveCityId('Jaramana', 'Damascus', 'Syria');

        $row = $this->cityRow($id);

        $this->assertNotNull($row, 'No city row was created.');
        $this->assertSame('Jaramana', $row->name);
        $this->assertSame(1, (int) $row->state_id, 'states.id for "Damascus" was not resolved.');
        $this->assertSame(101, (int) $row->country_id, 'countries.id for "Syria" was not resolved.');
    }

    /**
     * AND THE ONE THAT MATTERS MOST, because 0 is what MySQL's non-strict connection would have
     * stored: an unresolvable state or country is NULL. Not 0 -- `countries.id = 0` exists nowhere,
     * so a 0 is a row pointing at a country that does not exist -- and not the posted string.
     */
    public function test_an_unresolvable_state_or_country_is_stored_as_null(): void
    {
        $this->seedGeography();

        $id = BillingGeography::resolveCityId('Atlantis', 'Nowhere Province', 'Freedonia');

        $row = $this->cityRow($id);

        $this->assertNotNull($row, 'No city row was created.');
        $this->assertSame('Atlantis', $row->name);
        $this->assertNull($row->state_id, 'An unresolvable state must be NULL, never 0 and never the posted name.');
        $this->assertNull($row->country_id, 'An unresolvable country must be NULL, never 0 and never the posted name.');
    }

    /** A resolved state knows its own country, so a missing country is filled from it rather than nulled. */
    public function test_a_missing_country_is_taken_from_the_resolved_state(): void
    {
        $this->seedGeography();

        $row = $this->cityRow(BillingGeography::resolveCityId('Jaramana', 1, ''));

        $this->assertSame(1, (int) $row->state_id);
        $this->assertSame(101, (int) $row->country_id,
            'The country was left NULL although the posted state resolves and names its country.');
    }

    /** Two cities of the same name in different states must not be confused. */
    public function test_a_posted_name_is_resolved_within_the_posted_state(): void
    {
        $this->seedGeography();
        DB::table('cities')->insert([
            'id' => 3, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $before = $this->cityRows();

        $this->assertSame(1, BillingGeography::resolveCityId('Damascus', 1, 101), 'state 1 should win for state 1');
        $this->assertSame(3, BillingGeography::resolveCityId('Damascus', 2, 101), 'state 2 should win for state 2');

        $this->assertSame($before, $this->cityRows(), 'Neither lookup may insert.');
    }

    /** No city posted is still 0, which every caller already treats as "none". */
    public function test_an_absent_city_is_zero_and_creates_nothing(): void
    {
        $this->seedGeography();
        $before = $this->cityRows();

        foreach ([null, '', ' ', '0', 0, [], new \stdClass()] as $value) {
            $this->assertSame(0, BillingGeography::resolveCityId($value, 1, 101),
                'An absent city must resolve to 0: ' . var_export(is_object($value) ? 'object' : $value, true));
        }

        $this->assertSame($before, $this->cityRows());
    }

    /**
     * THE ID PARSE MUST BE EXACT, in both directions, and only one direction is obvious.
     *
     * '1abc' is the direction everyone checks: MySQL coerces it to 1 in `WHERE id = ?`, so it must
     * not be read as city 1 here either. `is_numeric` happens to agree -- it rejects '1abc' -- which
     * is why a mutant swapping FILTER_VALIDATE_INT for `is_numeric` SURVIVED the first version of
     * this test. The separating inputs are the ones `is_numeric` ACCEPTS and an integer parse does
     * not: '1.0' and '1e2' are not integers, and `(int) '1e2'` is 100, so under `is_numeric` a
     * posted '1e2' would silently resolve to city 100 -- a row the caller never named.
     */
    public function test_only_an_exact_integer_is_read_as_an_id(): void
    {
        $this->seedGeography();

        // The MySQL-coercion direction.
        $id = BillingGeography::resolveCityId('1abc', 1, 101);
        $this->assertNotSame(1, $id, "'1abc' resolved to city 1 -- the parse coerces like MySQL does.");
        $this->assertSame('1abc', $this->cityRow($id)->name);

        // The is_numeric direction: a decimal and an exponent are NAMES, not ids.
        DB::table('cities')->insertOrIgnore([
            'id' => 100, 'name' => 'Decoy', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['1.0' => 1, '1e2' => 100] as $posted => $wouldBe) {
            $resolved = BillingGeography::resolveCityId((string) $posted, 1, 101);

            $this->assertNotSame($wouldBe, $resolved,
                "'{$posted}' resolved to city {$wouldBe}: the id parse accepts a non-integer "
                . 'spelling, which is what is_numeric does and FILTER_VALIDATE_INT does not.');
            $this->assertSame((string) $posted, $this->cityRow($resolved)->name);
        }

        // And a surrounding-space spelling IS still that id -- the parse trims, it does not refuse.
        $this->assertSame(1, BillingGeography::resolveCityId(' 1 ', 1, 101));
    }

    /** `cities.name` is a VARCHAR(255); a longer value is truncated rather than left to the engine. */
    public function test_an_overlong_city_name_is_capped_at_the_column_width(): void
    {
        $this->seedGeography();

        $row = $this->cityRow(BillingGeography::resolveCityId(str_repeat('x', 400), 1, 101));

        $this->assertSame(255, mb_strlen($row->name));
    }

    /** `resolveIds()` never creates: an address records where someone lives, it is not an authority. */
    public function test_resolve_ids_never_creates_a_city(): void
    {
        $this->seedGeography();
        $before = $this->cityRows();

        $resolved = BillingGeography::resolveIds('Atlantis', 'Nowhere', 'Freedonia');

        $this->assertSame(['city_id' => null, 'state_id' => null, 'country_id' => null], $resolved);
        $this->assertSame($before, $this->cityRows(), 'resolveIds() inserted a city.');

        $this->assertSame(
            ['city_id' => 1, 'state_id' => 1, 'country_id' => 101],
            BillingGeography::resolveIds(1, 1, 101)
        );
        $this->assertSame(
            ['city_id' => 1, 'state_id' => 1, 'country_id' => 101],
            BillingGeography::resolveIds('Damascus', 'Damascus', 'Syria')
        );
    }

    // =========================================================================================
    //  END TO END, because rewiring twenty-four call sites is the other half of the change
    // =========================================================================================

    /**
     * The row's first required test. A city NAME that does not exist used to make `place_order`
     * 500 AFTER `$order->save()` -- the shopper's order written, the response an error.
     */
    public function test_place_order_accepts_an_unknown_city_name_without_a_foreign_key_violation(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);

        $response = $this->placeOrder($shopper, $store, $this->billing([
            'billing_city' => 'Jaramana', 'delivery_city' => 'Jaramana',
        ]));

        $this->assertNotSame(500, $response->getStatusCode(),
            'place-order crashed on an unknown city name: ' . substr((string) $response->getContent(), 0, 400));
        $this->assertSame(1, (int) $response->json('status'),
            'place-order refused the order: ' . substr((string) $response->getContent(), 0, 400));

        $created = DB::table('cities')->where('name', 'Jaramana')->first();

        $this->assertNotNull($created, 'The city was not created.');
        $this->assertSame(1, (int) $created->state_id);
        $this->assertSame(101, (int) $created->country_id);

        $this->assertSame(0, (int) DB::table('cities')->where('state_id', 0)->orWhere('country_id', 0)->count(),
            'A city was stored with zero-valued geography.');

        $detail = DB::table('order_billing_details')->orderByDesc('id')->first();
        $this->assertSame((int) $created->id, (int) $detail->city,
            'order_billing_details.city does not point at the resolved city.');
    }

    /**
     * The defect that happens on EVERY order, which the row did not name: a posted city ID used to
     * miss the name-keyed lookup and insert a city called "1".
     */
    public function test_place_order_with_a_posted_city_id_creates_no_junk_city(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $before = $this->cityRows();

        $response = $this->placeOrder($shopper, $store, $this->billing());

        $this->assertSame(1, (int) $response->json('status'),
            'place-order refused the order: ' . substr((string) $response->getContent(), 0, 400));

        $this->assertSame($before, $this->cityRows(),
            'A posted city id still inserts a city row named after that id.');

        $detail = DB::table('order_billing_details')->orderByDesc('id')->first();
        $this->assertSame(1, (int) $detail->city, 'order_billing_details.city is not the posted city id.');
        $this->assertSame(1, (int) $detail->state, 'order_billing_details.state is no longer the posted state id.');
        $this->assertSame(101, (int) $detail->country, 'order_billing_details.country is no longer the posted country id.');
    }

    /**
     * THE CLIENT CONTRACT, END TO END, and the defect nobody had named: `place_order` resolved the
     * city id and then DISCARDED it, writing the raw posted value into all six
     * `order_billing_details` geography columns. Those columns are joined to `cities.id`,
     * `states.id` and `countries.id`, and `/api/admin/vieworder` -> `Order::order_detail()` renders
     * them through those relations for the shipped admin app -- so a posted NAME was stored and
     * then displayed as an EMPTY field to the merchant.
     *
     * This asserts the whole chain: names in, ids stored, names out.
     */
    public function test_place_order_stores_ids_so_the_admin_order_detail_renders_names(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);

        $response = $this->placeOrder($shopper, $store, $this->billing([
            'billing_city' => 'Jaramana', 'billing_state' => 'Damascus', 'billing_country' => 'Syria',
            'delivery_city' => 'Harem', 'delivery_state' => 'Idlib', 'delivery_country' => 'Syria',
        ]));

        $this->assertSame(1, (int) $response->json('status'),
            'place-order refused the order: ' . substr((string) $response->getContent(), 0, 400));

        $detail = DB::table('order_billing_details')->orderByDesc('id')->first();
        $created = DB::table('cities')->where('name', 'Jaramana')->first();

        $this->assertNotNull($created, 'The billing city was not created.');
        $this->assertSame((int) $created->id, (int) $detail->city, 'order_billing_details.city is not the resolved id.');
        $this->assertSame(1, (int) $detail->state, 'order_billing_details.state is not the resolved states.id.');
        $this->assertSame(101, (int) $detail->country, 'order_billing_details.country is not the resolved countries.id.');
        $this->assertSame(2, (int) $detail->delivery_city, 'delivery_city is not the existing city "Harem".');
        $this->assertSame(2, (int) $detail->delivery_state, 'delivery_state is not the resolved states.id for "Idlib".');
        $this->assertSame(101, (int) $detail->delivery_country);

        // What the merchant actually sees, through the relations the admin app's payload uses.
        $rendered = \App\Models\Order::order_detail((int) $detail->order_id);

        $this->assertSame('Jaramana', $rendered['billing_informations']['city'],
            'The admin payload shows no city, which is what storing a name in an id column does.');
        $this->assertSame('Damascus', $rendered['billing_informations']['state']);
        $this->assertSame('Syria', $rendered['billing_informations']['country']);
        $this->assertSame('Harem', $rendered['delivery_informations']['city']);
        $this->assertSame('Idlib', $rendered['delivery_informations']['state']);
    }

    // =========================================================================================
    //  THE RATCHET: a twenty-fifth copy of the block cannot appear unseen
    // =========================================================================================

    /**
     * Keyed on the ASSIGNMENT, not on a handler name or a uri: the census that produced this
     * package found 24 sites where the ledger row named 3, because the row was keyed on the two
     * files somebody had read. Comment lines are stripped first -- a docblock quoting the forbidden
     * spelling has made a ratchet in this project satisfiable by prose before.
     */
    public function test_no_order_writer_assigns_a_request_value_to_a_city_foreign_key(): void
    {
        $scanned = 0;
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));
            $scanned++;

            foreach (['state_id', 'country_id', 'city_id'] as $column) {
                if (preg_match('/->' . $column . '\s*=\s*\$billing\s*\[/', $source)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file) . ' -> ' . $column;
                }
            }
        }

        $this->assertGreaterThan(100, $scanned,
            'The scan read almost nothing, so a green result here means nothing (census-failure '
            . 'mode: a vacuous sweep).');

        $this->assertSame([], $offenders,
            "A request value is assigned straight into a foreign-key column. Resolve it through "
            . "App\\Services\\BillingGeography instead:\n  " . implode("\n  ", $offenders));
    }

    /**
     * And the other half of the same defect: a city looked up by NAME from a request value, when
     * every shipped client posts an id.
     */
    public function test_no_order_writer_looks_a_city_up_by_name_from_request_input(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(app_path()) as $file) {
            $source = $this->withoutComments((string) file_get_contents($file));

            if (preg_match('/City::where\(\s*[\'"]name[\'"]\s*,\s*\$billing\s*\[/', $source)) {
                $offenders[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame([], $offenders,
            "A city is looked up by name from request input, which can never match a posted id:\n  "
            . implode("\n  ", $offenders));
    }

    /** The resolver is reachable from every file that used to hold the block. */
    public function test_every_order_writer_that_held_the_block_now_calls_the_resolver(): void
    {
        $expected = [
            'app/Http/Controllers/Api/ApiController.php' => 2,
            'app/Http/Controllers/AuthorizeNetPaymnetController.php' => 2,
            'app/Http/Controllers/CinetPayController.php' => 2,
            'app/Http/Controllers/EasebuzzController.php' => 2,
            'app/Http/Controllers/KhaltiPaymnetController.php' => 2,
            'app/Http/Controllers/NMIPayController.php' => 2,
            'app/Http/Controllers/PaiementProPaymentController.php' => 2,
            'app/Http/Controllers/PayUPaymentController.php' => 2,
            'app/Http/Controllers/PaymentController.php' => 2,
            'app/Services/PaymentService.php' => 2,
        ];

        foreach ($expected as $path => $atLeast) {
            $full = base_path($path);

            $this->assertFileExists($full);

            $calls = preg_match_all(
                '/BillingGeography::resolve(CityId|Billing|Ids)\s*\(/',
                $this->withoutComments((string) file_get_contents($full))
            );

            $this->assertGreaterThanOrEqual($atLeast, $calls,
                "{$path} holds fewer resolver calls than the blocks it used to carry, so one of "
                . 'them was lost or re-inlined.');
        }
    }

    /** @return array<int, string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Line and block comments removed, so prose cannot satisfy or trip a source ratchet. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
