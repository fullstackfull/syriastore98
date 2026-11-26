<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Order;
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
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-143 / `P04-P8-02` — THE ACCEPTANCE CLAUSE ON A **STORED ORDER**, AND THE `CURRENCY` VERDICT.
 * =================================================================================================
 *
 * P4-8's acceptance clause, verbatim (`PHASE-04-EXECUTION-PLAN.md` §6): *"seed shipping methods and
 * tax, resolve `"CURRENCY": null`. Acceptance: totals reconcile against `OrderTotalReconciler` with
 * shipping and tax present."*
 *
 * WHY A SECOND FILE BESIDE `ShippingAndTaxReconciliationTest`, AND WHAT IT ADDS.
 * -------------------------------------------------------------------------------------------------
 * That file seeds the fixture and drives `POST {slug}/shipping-method`, which was the missing half
 * of §4's measurement environment. Two things it does not do, both of them load-bearing for this
 * requirement:
 *
 *   1. **Its reconciliation never touches an order.** It computes
 *      `$claimed = round($goods + $shippingCost + $taxAmount, 2)` in the test and then asks the
 *      reconciler whether those same three parts sum to it. They do, necessarily — the assertion
 *      cannot fail for any figures the endpoint returns, which is failure mode 1 of the skill's
 *      list ("the test cannot tell the two readings apart"). The clause is about a TOTAL: this file
 *      reconciles the `orders` row the shipped checkout actually wrote, whose `final_price` is
 *      built from a different base than its own components (`PaymentService.php:3112` on the
 *      signed-in path, `:3042` for a guest: `$total_sub_price + $delivery_price`) and therefore
 *      CAN disagree with them.
 *   2. **It never reaches the two payloads the `CURRENCY` question is about** — see the block below.
 *
 * ---------------------------------------------------------------------------------------------
 * THE `CURRENCY` QUESTION: **A DEFECT IN THE CODE, NOT AN ARTEFACT OF THE FIXTURE.**
 * ---------------------------------------------------------------------------------------------
 * §4 recorded `"CURRENCY": null` *"in both payloads"* and left it for P4-4 to classify. Exactly two
 * storefront endpoints emit a `CURRENCY` key, and they are the pair:
 *
 *     POST {slug}/get-shipping-data   CartController::get_shipping_data   (:1056, :1062)
 *     POST {slug}/get-tax-data        CartController::get_tax_data        (:1164, :1198, :1220, :1236)
 *
 * Both fill it from `Utility::GetValueByName('defult_currancy_symbol', $store->id)`
 * (`CartController.php:851` and `:1084`). That lookup reads the store's `settings` rows and, on a
 * miss, falls through to `Utility::Setting()`'s defaults array — which carries `CURRENCY => '$'` and
 * `CURRENCY_NAME => 'USD'` but **has no `defult_currancy_symbol` key at all**
 * (`app/Models/Utility.php:35-131`; the `CURRENCY` default is :40). So with no row, the answer is `null`.
 *
 * AND THE ROW IS ABSENT ON THE ONBOARDING PATHS THAT MATTER. `defaultSetting()` seeds
 * `CURRENCY_NAME`, `CURRENCYCURRENCY`, `currency_format` and `defult_currancy` — not
 * `defult_currancy_symbol` (`app/Helper/helper.php:1679-1684`; the same absence is recorded at
 * `app/Services/StoreSettingsAccess.php:75-81`). Of the four store-creation paths only
 * `StoreController` seeds the symbol, in a first-party block that runs *after* `defaultSetting()`
 * (`StoreController.php:155`, `:312`). The other three do not:
 *
 *     RegisteredUserController.php:194 / :327   merchant self-registration
 *     Api\DashboardController.php:9819          the admin app's create-store call
 *
 * So a store created by registration — the ordinary way a merchant arrives — serves `CURRENCY: null`
 * from both endpoints for the whole life of the store, while every money figure on the same page
 * reads `$…` because `currency_format_with_sym()` carries its OWN hardcoded `'$'` fallback
 * (`app/Helper/helper.php:1739` sets `'$'`, `:1755-1757` overrides it only when the row
 * exists). That divergence is the defect: one response, two answers
 * about the same store's currency.
 *
 * MEASURED BEFORE THE FIX, on a store built exactly as `RegisteredUserController` builds one:
 * `get-shipping-data` -> `{"CURRENCY":null,"shipping_method":[…]}` and `get-tax-data` ->
 * `{"sale_price":"220.00","tax_price":"22.00","tax_id_value":"1","final_total_amount":"242.00",
 * "CURRENCY":null}`. Two of this file's cases failed on exactly that and now pass.
 *
 * ---------------------------------------------------------------------------------------------
 * THE FIX TAKEN, AND THE TWO THAT WERE NOT
 * ---------------------------------------------------------------------------------------------
 * Both reads now call `App\Support\Money\OrderCurrencyStamp::symbolFor($store->id)` — the chain
 * P5-R14 made the single place that decides a store's symbol (`defult_currancy_symbol`, then
 * `currency_symbol`, then `'$'`). **It changes nothing but the value of these two JSON fields:** no
 * amount, no stored order, no payment, no stock. A store that has configured a symbol keeps
 * reporting it — `test_a_configured_symbol_still_wins_in_both_payloads` is the control, and it is
 * the case a hard-coded `'$'` fails.
 *
 * TWO ALTERNATIVES WERE REJECTED BY MEASUREMENT, NOT BY TASTE:
 *   (a) teaching `Utility::Setting()`'s `$defaults` the name would change what EVERY
 *       `GetValueByName('defult_currancy_symbol')` caller resolves — `OrderCurrencyStamp::symbolFor()`
 *       among them, where it would short-circuit the `currency_symbol` link and so alter the symbol
 *       STAMPED ON NEW ORDERS. That is recorded financial data and the owner's (`D-N34`'s family).
 *   (b) reading `'CURRENCY'` instead, which has a default and is what
 *       `AccountProfileController::change_refund_cart:2007` reads, would resolve `'$'` here too —
 *       and would IGNORE a merchant who changed their symbol through the admin app, which writes
 *       `defult_currancy_symbol` only (`Api\DashboardController.php:10628`). Mutation M5 of this
 *       file's harness is exactly that alternative, and it reddens the configured-symbol control.
 *
 * ONE LINE EACH, DELIBERATELY: `docs/security/P01-OBSERVABILITY-INVENTORY.md` anchors this file at
 * `:1581` and `:1784`, so a comment added above either read would shift them and redden
 * `SecurityLogInventoryTest` (CLAUDE.md §6b). The reasoning lives here instead.
 *
 * WHAT THE NULL WAS AND WAS NOT COSTING, stated so the fix is not oversold: both consumers of
 * `response.CURRENCY` for these two endpoints are **commented out** in
 * `public/assets/js/front-theme.js:492` and `:695`, so the shipped storefront does not print it
 * today. The live consumer of a `CURRENCY` key, `front_end/pages/refund_order.blade.php:323-346`,
 * reads a THIRD endpoint that resolves the defaulted `'CURRENCY'` name and was never null. So this
 * was a latent contract defect — a field whose whole purpose is to name the currency, answering
 * nothing — rather than a `null` on a shopper's screen. It is fixed because the next consumer to be
 * uncommented would print `null` beside a price.
 *
 * AND ONE DOCUMENT IS NOW STALE BECAUSE OF THIS, said here because this file may not edit it:
 * `ShippingAndTaxReconciliationTest`'s docblock classifies `"CURRENCY": null` as a **fixture gap**
 * on the reasoning that the lookup falls back to `Utility::Setting()`'s `CURRENCY => '$'` and that
 * *"neither payload carries a `CURRENCY` key at all"*. Both halves are wrong, and the second
 * explains the first: the handlers read `defult_currancy_symbol`, not `CURRENCY`, and the file
 * drives `shipping-method` — which indeed has no such key — while the two endpoints that do emit
 * one are `get-shipping-data` and `get-tax-data`. Its own `taxPayload()` helper is never used by
 * that case, although the case is named *"both payloads"*. Its assertions remain correct and green;
 * only the disposition in the prose needs correcting by whoever owns it.
 *
 * ---------------------------------------------------------------------------------------------
 * EVIDENCE
 * ---------------------------------------------------------------------------------------------
 * 14 cases. Two of them failed against shipped code and the figures are quoted above. **13
 * mutations applied one at a time, 13 caught**, each verified APPLIED by checksum and restored by
 * checksum (the harness fails loudly when a search string does not occur the expected number of
 * times — the defect the skill records as reporting "survived"). Three survived their first round
 * and all three were closed by finding the input that separates the two behaviours rather than by
 * weakening the mutation:
 *
 *   zone country predicate deleted   survived while the control also nulled `state_id`
 *   zone STORE predicate deleted     survived until a neighbour held a zone in the same
 *                                    jurisdiction, seeded BEFORE this store's (`->first()` has no
 *                                    `orderBy`, so the lowest id wins)
 *   method STORE predicate deleted   survived until a foreign method named THIS store's zone
 */
final class Gap143ShippingTaxOrderTotalsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const UNIT_PRICE = 100.00;

    private const QTY = 2;

    private const SHIPPING_COST = 25.00;

    /** 10% — round, so an arithmetic slip reads as one rather than as plausible. */
    private const TAX_RATE = 10.0;

    private User $merchant;

    private Store $store;

    private Customer $shopper;

    private Product $product;

    private Tax $tax;

    private ShippingZone $zone;

    private ShippingMethod $method;

    private DeliveryAddress $address;

    /** ANOTHER TENANT, holding a zone in the SAME jurisdiction. See the cross-tenant case. */
    private Store $neighbourStore;

    private ShippingMethod $neighbourMethod;

    /** A row of the NEIGHBOUR's naming THIS store's zone -- see the comment where it is seeded. */
    private ShippingMethod $foreignMethodOnOurZone;

    private int $country;

    private int $state;

    private int $city;

    /** A second jurisdiction, so "the zone matched" is separable from "a zone exists". */
    private int $otherCountry;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        /* Several handlers memoise the platform admin for an hour and CACHE_STORE=array outlives
           the fixture, so a store created after the first request would be invisible. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');

        $ref = $this->uniqueFixtureRef();

        /*
         * THE STORE UNDER TEST IS NEITHER STORE 1 NOR ITS OWNER'S USER ID (CLAUDE.md's TST-01).
         * `getCurrentStore()` hands some principals the hard-coded literal 1, and
         * `Utility::Setting()`'s unauthenticated fallback reads `created_by = 1, store_id = 1` --
         * so on a fixture where "the route's store", "store 1" and "its owner's user id" are ONE
         * number, a handler that resolved the wrong one of the three would still answer correctly
         * and every mutant of the lookup would survive.
         */
        $decoyMerchant = $this->makeMerchant();
        $this->neighbourStore = $this->makeStore($decoyMerchant);   // store 1, owned by user 1
        $this->makeMerchant();                       // a user who owns nothing

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;

        $this->assertGreaterThan(1, (int) $this->store->id,
            'premise: the store under test is not store 1');
        $this->assertNotSame((int) $this->store->id, (int) $this->store->created_by,
            'premise: the store id is not its owner\'s user id either');

        /*
         * `plans.shipping_method = 'on'` IS THE GATE ON THE WHOLE REQUIREMENT. With it off,
         * `PaymentController::processShipping()` returns `delivery_price = 0` without ever calling
         * `get_shipping_method()` (`PaymentController.php:2949`), and `get_shipping_data()` returns
         * its empty `shipping_method: ""` answer — which is precisely the state §4 measured.
         */
        $planId = (int) DB::table('plans')->insertGetId([
            'name' => 'gap143-' . $ref,
            'price' => 0,
            'themes' => '[]',
            'storage_limit' => '100',
            'shipping_method' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->merchant->plan_id = $planId;
        $this->merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $this->merchant->id,
                'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        /* MINTED, NOT INVENTED: `delivery_addresses` and the tax tables carry foreign keys on the
           jurisdiction columns, so arbitrary integers insert nothing and every figure below would
           be measured on an empty payload. */
        $this->country = (int) DB::table('countries')->insertGetId(
            ['name' => 'G143-' . $ref, 'created_at' => now(), 'updated_at' => now()]);
        $this->state = (int) DB::table('states')->insertGetId(
            ['name' => 'G143-ST-' . $ref, 'country_id' => $this->country, 'created_at' => now(), 'updated_at' => now()]);
        $this->city = (int) DB::table('cities')->insertGetId(
            ['name' => 'G143-CT-' . $ref, 'country_id' => $this->country, 'state_id' => $this->state,
             'created_at' => now(), 'updated_at' => now()]);
        $this->otherCountry = (int) DB::table('countries')->insertGetId(
            ['name' => 'G143-OTHER-' . $ref, 'created_at' => now(), 'updated_at' => now()]);

        /*
         * ANOTHER TENANT'S ZONE AND METHOD, IN THE SAME COUNTRY AND STATE. `ShippingZone` and
         * `ShippingMethod` are both ABSENT from `config/tenancy.php`'s `scoped_models`, so the
         * explicit `where('store_id', …)` in the lookup is the ONLY thing separating the two
         * stores' shipping configuration. Without a neighbour that matches the same jurisdiction, a
         * mutant that deletes that predicate changes nothing a test can see — measured: it survived
         * this file's first mutation round.
         *
         * SEEDED **BEFORE** THIS STORE'S OWN ZONE, AND THAT ORDER IS THE WHOLE POINT. The lookup
         * ends in `->first()` with no `orderBy`, so with the store predicate deleted it answers
         * with the LOWEST matching id. Seeded after ours, the neighbour's row is never the first
         * one and the mutant survives a second time — measured, in the round after the one above.
         */
        $neighbourZone = ShippingZone::create([
            'zone_name' => 'G143 neighbour zone',
            'country_id' => $this->country,
            'state_id' => $this->state,
            'shipping_method' => '1',
            'store_id' => $this->neighbourStore->id,
        ]);

        $this->neighbourMethod = ShippingMethod::create([
            'method_name' => 'Local pickup',
            'zone_id' => $neighbourZone->id,
            'cost' => 999.00,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $this->neighbourStore->id,
        ]);
        (new ShippingZone)->flushCache();
        (new ShippingMethod)->flushCache();

        $this->shopper = Customer::forceCreate([
            'first_name' => 'G143', 'last_name' => 'Shopper',
            'email' => 'gap143-' . $ref . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        /* THE RATE LIVES ON `tax_methods`, NOT ON `taxes`: `taxes` carries only a name and a store.
           Read off the migrations rather than guessed — CLAUDE.md's "the migrations are not the
           schema, and a migration's wording is not its column". */
        $this->tax = Tax::forceCreate(['name' => 'G143 VAT', 'store_id' => $this->store->id]);

        TaxMethod::forceCreate([
            'name' => 'G143 VAT band',
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

        /* `products.tax_id` IS THE THIRD SEEDED THING, and without it no band is ever looked up:
           `Cart::getProductTaxAmount()` is called with the PRODUCT's tax class, so a Tax row and a
           matching TaxMethod are not enough on their own. */
        $this->product = Product::forceCreate([
            'name' => 'G143 product',
            'slug' => 'gap143-' . $ref,
            'price' => self::UNIT_PRICE,
            'sale_price' => self::UNIT_PRICE,
            'product_stock' => 50,
            'track_stock' => 1,
            'variant_product' => 0,
            'status' => 1,
            'stock_status' => 'in_stock',
            'store_id' => $this->store->id,
            'created_by' => $this->store->created_by,
            'tax_id' => (string) $this->tax->id,
        ]);

        $this->zone = ShippingZone::create([
            'zone_name' => 'G143 zone',
            'country_id' => $this->country,
            'state_id' => $this->state,
            'shipping_method' => '1',
            'store_id' => $this->store->id,
        ]);

        /*
         * `Local pickup` IS LOAD-BEARING. `get_shipping_method()` selects the cost arm by comparing
         * this free-text column with three hardcoded ENGLISH names and charges 0 for every other
         * value (GAP-262, pinned in `ShippingAndTaxReconciliationTest`). A name the handler honours
         * is what makes the clause exercisable at all.
         */
        $this->method = ShippingMethod::create([
            'method_name' => 'Local pickup',
            'zone_id' => $this->zone->id,
            'cost' => self::SHIPPING_COST,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $this->store->id,
        ]);
        /*
         * AND A FOREIGN METHOD POINTING AT **THIS** STORE'S ZONE — the row that separates the
         * METHOD lookup's own store predicate from the zone lookup's.
         *
         * Nothing scopes `shipping_methods.zone_id`: it is an unconstrained integer on a model with
         * no `StoreScope`, so a row of store B naming store A's zone is insertable and a merchant
         * editing a method can produce one. With this row absent, deleting the store predicate from
         * `ShippingMethod::where('zone_id', …)` changes nothing a test can see — measured, it
         * survived this file's third mutation round. The two predicates are DEFENCE IN DEPTH and
         * each needs its own separating input.
         */
        $this->foreignMethodOnOurZone = ShippingMethod::create([
            'method_name' => 'Local pickup',
            'zone_id' => $this->zone->id,
            'cost' => 777.00,
            'calculation_type' => 'fixed',
            'shipping_requires' => '1',
            'store_id' => $this->neighbourStore->id,
        ]);
        (new ShippingMethod)->flushCache();

        $this->address = DeliveryAddress::forceCreate([
            'customer_id' => $this->shopper->id,
            'store_id' => $this->store->id,
            'full_name' => 'G143 Shopper',
            'phone' => '0100000000',
            'title' => 'home',
            'address' => 'G143 Street',
            'postcode' => 12345,
            'default_address' => 1,
            'type' => 0,
            'country_id' => $this->country,
            'state_id' => $this->state,
            'city_id' => $this->city,
        ]);

        $this->fillBasket();
    }

    private function fillBasket(): void
    {
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

    /*
    |--------------------------------------------------------------------------
    | PREMISES — §4's gaps were "no shipping method configured" and "no tax
    | configured". A file that fails to seed them re-measures the empty answer.
    |--------------------------------------------------------------------------
    */

    public function test_premise_the_fixture_really_carries_a_zone_a_cost_a_band_and_a_plan(): void
    {
        $this->assertSame(1, DB::table('shipping_zones')->where('store_id', $this->store->id)->count(),
            'premise: one shipping zone for this store');

        $this->assertSame(
            (string) self::SHIPPING_COST,
            (string) ShippingMethod::withoutGlobalScopes()->findOrFail($this->method->id)->cost,
            'premise: the seeded cost survived the write. Read through withoutGlobalScopes() '
            . 'because a plain find() answers against whichever tenant happens to be resolved.'
        );

        $this->assertSame((int) $this->zone->id,
            (int) ShippingMethod::withoutGlobalScopes()->findOrFail($this->method->id)->zone_id,
            'premise: the method belongs to the seeded zone, which is what the selection endpoint '
            . 'resolves from the shopper\'s address');

        $this->assertSame(1, DB::table('tax_methods')->where('store_id', $this->store->id)->count(),
            'premise: one tax band naming this jurisdiction');

        $this->assertSame((string) $this->tax->id,
            (string) Product::withoutGlobalScopes()->findOrFail($this->product->id)->tax_id,
            'premise: the product names its tax class. Without it getProductTaxAmount() looks up '
            . 'no band however well the address matches, and every tax figure below is 0.');

        $this->assertSame('on',
            (string) DB::table('plans')->where('id', $this->merchant->plan_id)->value('shipping_method'),
            'premise: the store\'s plan has the shipping engine ON. With it off, processShipping() '
            . 'returns 0 without ever pricing a method and the clause cannot be exercised.');

        $this->assertSame(1, Cart::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->where('customer_id', $this->shopper->id)->count(),
            'premise: the shopper holds a basket. Asserted through withoutGlobalScopes() because '
            . 'Cart IS one of the genuinely scoped models, so a plain count() answers against '
            . 'whichever tenant happens to be resolved.');
    }

    /*
    |--------------------------------------------------------------------------
    | SHIPPING SELECTION AND THE ZONE
    |--------------------------------------------------------------------------
    */

    /**
     * THE ZONE IS WHAT OFFERS THE METHOD, and `get-shipping-data` is where that happens:
     * `ShippingZone::where('store_id', …)->where('country_id', …)->where('state_id', …)` resolved
     * from the SHOPPER'S OWN stored address, then every `ShippingMethod` of that zone.
     */
    public function test_the_zone_offers_the_seeded_method_for_the_shoppers_address(): void
    {
        $payload = $this->shippingDataPayload();

        $this->assertIsArray($payload['shipping_method'] ?? null, sprintf(
            'the selection endpoint offered no method list at all, so zone resolution never '
            . 'succeeded. Payload: %s', json_encode($payload)
        ));

        $offered = array_column($payload['shipping_method'], 'id');

        $this->assertContains((int) $this->method->id, array_map('intval', $offered), sprintf(
            'the shopper\'s address is in the seeded zone and the zone\'s only method was not '
            . 'offered. Payload: %s', json_encode($payload)
        ));
    }

    /**
     * AND THE ZONE IS LOAD-BEARING RATHER THAN DECORATIVE — the negative controls the case above
     * needs, one per predicate.
     *
     * THE STATE IS DELIBERATELY LEFT MATCHING WHILE THE COUNTRY MOVES, and the reverse in the
     * second leg. The first draft moved the country AND nulled the state, and a mutant that
     * DELETED the country predicate altogether **survived** it: with `state_id` null,
     * `where('state_id', <int>)` matches no row either way, so the two behaviours could not
     * disagree. That is the skill's "the test cannot tell the two readings apart", caught here by
     * running the mutant rather than by reasoning. Each leg below now differs from the fixture in
     * exactly ONE column, so exactly one predicate is what refuses it.
     */
    public function test_a_method_whose_zone_is_another_jurisdiction_is_not_offered(): void
    {
        /* LEG 1 — the country differs, the state still matches. Only the country predicate refuses. */
        $this->zone->country_id = $this->otherCountry;
        $this->zone->state_id = $this->state;
        $this->zone->save();
        (new ShippingZone)->flushCache();

        $this->assertNotContains((int) $this->method->id, $this->offeredMethodIds(), sprintf(
            'a zone in a DIFFERENT COUNTRY still offered its method to this shopper — and its '
            . 'state does match, so the country predicate is the only thing that could have '
            . 'refused it. Zone country %d, shopper country %d.',
            $this->otherCountry, $this->country
        ));

        /* LEG 2 — the country matches, the state differs. Only the state predicate refuses. */
        $otherState = (int) DB::table('states')->insertGetId([
            'name' => 'G143-ST2-' . $this->uniqueFixtureRef(),
            'country_id' => $this->country,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zone->country_id = $this->country;
        $this->zone->state_id = $otherState;
        $this->zone->save();
        (new ShippingZone)->flushCache();

        $this->assertNotContains((int) $this->method->id, $this->offeredMethodIds(), sprintf(
            'a zone in a DIFFERENT STATE of the same country still offered its method, so the '
            . 'state predicate is not refusing it. Zone state %d, shopper state %d.',
            $otherState, $this->state
        ));

        /* THE CONTROL: put the zone back and the same method is offered again, so neither leg can
           pass because the fixture, the route or the basket stopped working. */
        $this->zone->country_id = $this->country;
        $this->zone->state_id = $this->state;
        $this->zone->save();
        (new ShippingZone)->flushCache();

        $this->assertContains((int) $this->method->id, $this->offeredMethodIds(),
            'premise: the SAME method in the SAME zone IS offered once the jurisdiction matches '
            . 'again. Without this both legs above would pass on a handler that offers nothing.');
    }

    /**
     * AND THE OFFER IS THIS STORE'S, NOT THE NEIGHBOUR'S. `ShippingZone` and `ShippingMethod` are
     * both absent from `config/tenancy.php`'s `scoped_models`, so the handler's own
     * `where('store_id', …)` is the whole of the separation — and the neighbour's zone names the
     * SAME country and state, so nothing but that predicate can refuse it.
     *
     * The neighbour's method costs 999.00, which is also why this matters beyond disclosure: the
     * same `method_id` is posted back to `shipping-method`, and a cost is what the order writer
     * charges.
     */
    public function test_another_tenants_zone_is_never_offered_in_this_store(): void
    {
        $this->assertSame(
            1,
            DB::table('shipping_zones')->where('store_id', $this->neighbourStore->id)
                ->where('country_id', $this->country)->where('state_id', $this->state)->count(),
            'premise: the neighbour really does hold a zone in this shopper\'s own jurisdiction. '
            . 'Without it the assertion below cannot fail however the lookup is written.'
        );

        $offered = $this->offeredMethodIds();

        $this->assertContains((int) $this->method->id, $offered,
            'premise: this store\'s own method is offered, so the list is not simply empty');

        $this->assertNotContains((int) $this->neighbourMethod->id, $offered, sprintf(
            'ANOTHER TENANT\'S SHIPPING METHOD WAS OFFERED at this store\'s checkout. Its zone '
            . 'names the same country and state, so the store predicate on the ZONE lookup is the '
            . 'only thing that can refuse it. Offered ids: %s; the neighbour\'s is %d at a cost of '
            . '999.00.', json_encode($offered), $this->neighbourMethod->id
        ));

        $this->assertNotContains((int) $this->foreignMethodOnOurZone->id, $offered, sprintf(
            'A SHIPPING METHOD BELONGING TO ANOTHER STORE, NAMING THIS STORE\'S OWN ZONE, WAS '
            . 'OFFERED. The zone lookup cannot refuse this one -- the zone really is this '
            . 'store\'s -- so the store predicate on the METHOD lookup is what has gone. Offered '
            . 'ids: %s; the foreign row is %d at a cost of 777.00.',
            json_encode($offered), $this->foreignMethodOnOurZone->id
        ));
    }

    /** @return array<int, int> */
    private function offeredMethodIds(): array
    {
        $payload = $this->shippingDataPayload();

        return is_array($payload['shipping_method'] ?? null)
            ? array_map('intval', array_column($payload['shipping_method'], 'id'))
            : [];
    }

    /**
     * SELECTION PRICES THE DELIVERY, and the figure the order writer reads is `total_final_price`
     * (`PaymentService.php:2731`, `PaymentController.php:2962`) — not `shipping_final_price`, which
     * the same payload carries pre-formatted with a symbol.
     */
    public function test_selecting_the_method_prices_the_delivery_and_derives_the_tax(): void
    {
        $payload = $this->shippingMethodPayload();

        $this->assertEqualsWithDelta(self::SHIPPING_COST, (float) ($payload['total_final_price'] ?? -1), 0.0001,
            sprintf('the configured 25.00 is not the figure the order writer will read as '
                . 'delivery_price. Payload: %s', json_encode($payload)));

        $this->assertGreaterThan(0.0, (float) ($payload['final_tax_price'] ?? 0), sprintf(
            'a 10%% band on %s of goods derived no tax, so the derivation path was not entered. '
            . 'Payload: %s', self::UNIT_PRICE * self::QTY, json_encode($payload)
        ));
    }

    /** TAX DERIVATION AT ITS OWN ENDPOINT, by jurisdiction, for the signed-in shopper. */
    public function test_get_tax_data_derives_the_band_for_the_shoppers_jurisdiction(): void
    {
        $payload = $this->taxDataPayload();

        $this->assertArrayHasKey('tax_price', $payload, sprintf(
            'the tax endpoint returned no tax figure at all. Payload: %s', json_encode($payload)));

        $this->assertGreaterThan(0.0, (float) $payload['tax_price'], sprintf(
            'the band matching this shopper\'s country/state/city derived 0.00. Payload: %s',
            json_encode($payload)
        ));

        $this->assertSame((string) $this->tax->id, (string) ($payload['tax_id_value'] ?? ''), sprintf(
            'the derived band is not the seeded tax class, so the figure above came from '
            . 'somewhere else. Payload: %s', json_encode($payload)
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | THE ACCEPTANCE CLAUSE: a STORED total, reconciled
    |--------------------------------------------------------------------------
    */

    /**
     * "Totals reconcile against `OrderTotalReconciler` with shipping and tax present."
     *
     * Driven through the shipped web checkout (`POST {slug}/process-order`, `payment_type=cod`) so
     * that the figures reconciled are the ones the database holds, not ones this test computed.
     * The component map is the writer's own (`PaymentService.php:3155-3163`), which is what makes
     * this the clause rather than a restatement of addition.
     */
    public function test_the_stored_order_totals_reconcile_with_shipping_and_tax_present(): void
    {
        $order = $this->checkout();

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order row');

        /* BOTH TERMS PRESENT, asserted before the reconciliation. Without these the sum below
           balances 200 + 0 + 0 against 200 and reports success on §4's empty answer. */
        $this->assertEqualsWithDelta(self::SHIPPING_COST, (float) $order->delivery_price, 0.0001,
            'orders.delivery_price did not take the selected method\'s cost, so "shipping '
            . 'present" is not true of this row: ' . var_export($order->delivery_price, true));

        $this->assertGreaterThan(0.0, (float) $order->tax_price,
            'orders.tax_price is 0, so "tax present" is not true of this row');

        $result = (new OrderTotalReconciler())->reconcile(
            [
                'product_price' => $order->product_price,
                'tax_price' => $order->tax_price,
                'delivery_price' => $order->delivery_price,
                'coupon_price' => $order->coupon_price,
            ],
            $order->final_price
        );

        $this->assertTrue($result['matches'], sprintf(
            'THE STORED TOTAL DOES NOT RECONCILE AGAINST ITS OWN COMPONENTS with shipping and tax '
            . 'present. This is P4-8\'s acceptance clause, measured on the row the shipped checkout '
            . 'wrote. product_price %s, tax_price %s, delivery_price %s, coupon_price %s, '
            . 'final_price %s. The reconciler said: %s. Note the writer builds final_price from '
            . '`$total_sub_price + $delivery_price` (PaymentService.php:3112 on this path, :3042 for a '
            . 'guest) and its own '
            . 'warnIfInconsistent() call is WARN-ONLY, so a mismatch here is logged in production '
            . 'and nothing refuses it.',
            var_export($order->product_price, true), var_export($order->tax_price, true),
            var_export($order->delivery_price, true), var_export($order->coupon_price, true),
            var_export($order->final_price, true), json_encode($result)
        ));
    }

    /**
     * THE RECONCILIATION ABOVE CAN FAIL — otherwise it is a restatement of addition rather than a
     * check. The same reconciler, the same component names, one component moved by 1.00.
     */
    public function test_premise_the_reconciler_refuses_a_total_that_does_not_add_up(): void
    {
        $order = $this->checkout();

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order row');

        $result = (new OrderTotalReconciler())->reconcile(
            [
                'product_price' => $order->product_price,
                'tax_price' => $order->tax_price,
                'delivery_price' => (float) $order->delivery_price + 1.00,
                'coupon_price' => $order->coupon_price,
            ],
            $order->final_price
        );

        $this->assertFalse($result['matches'],
            'the reconciler accepted a component map that is 1.00 away from the claimed total, so '
            . 'the acceptance case above proves nothing. Verdict: ' . json_encode($result));
    }

    /**
     * AND THE ORDER REALLY IS THE ONE THE CLAUSE MEANS: a storefront order, for this store, for
     * this shopper, holding the seeded line.
     *
     * `orders.product_price` IS NOT THE 200.00 OF GOODS, and that is measured rather than tolerated:
     * it is `cart_list`'s `total_final_price`, which is **tax-inclusive** because
     * `Product::ProductPrice()` adds the exclusive band to the unit price
     * (`app/Models/Product.php:418-440`). 2 x (100.00 + 10%) = 220.00. The 200.00 of goods is
     * carried separately, as the cart's `original_price`.
     */
    public function test_premise_the_stored_order_is_this_stores_storefront_order(): void
    {
        $order = $this->checkout();

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order row');
        $this->assertSame((int) $this->store->id, (int) $order->store_id, 'the order is this store\'s');
        $this->assertSame((int) $this->shopper->id, (int) $order->customer_id,
            'the order is the signed-in shopper\'s');
        $this->assertEqualsWithDelta(
            self::UNIT_PRICE * self::QTY * (1 + self::TAX_RATE / 100),
            (float) $order->product_price,
            0.0001,
            'orders.product_price is not the tax-INCLUSIVE 220.00 this basket prices to, so the '
            . 'figures reconciled above are not this basket\'s: '
            . var_export($order->product_price, true)
        );
    }

    /**
     * GAP-143's MEASUREMENT THAT IS NOT THE ACCEPTANCE CLAUSE, AND MUST NOT BE READ AS ONE:
     * **the band is applied twice to what the shopper is charged**, and the row still reconciles.
     *
     * MEASURED on this fixture through `POST {slug}/process-order`, `payment_type=cod`:
     *
     *     cart original_price            200.00   2 x 100.00 of goods
     *     cart total_final_price         220.00   Product::ProductPrice() added the 10% band
     *     cart tax_price                  22.00   10% applied AGAIN, to the 220.00
     *     cart total_sub_price           242.00   220.00 + 22.00
     *     orders.product_price           220.00
     *     orders.tax_price                22.00
     *     orders.delivery_price           25.00
     *     orders.final_price             267.00   = total_sub_price + delivery_price
     *
     * A single application of the merchant's own 10% band on 200.00 of goods plus 25.00 of carriage
     * is **245.00**. The charge is 267.00 — 22.00 of tax the merchant never configured, collected
     * by the COD driver.
     *
     * THIS IS THE **SAME** ARITHMETIC AS WORK-QUEUE ITEM S21.4 AND A **DIFFERENT, UNMEASURED
     * CONSEQUENCE OF IT.** `P01-SECURITY-WORK-QUEUE.md:1246-1247` records the double count in
     * `total_sub_price` on the API's `place_order`, where `orders.tax_price` is written **0** so the
     * stored components do NOT add up — which is what
     * `OrderTotalCouponTest::test_known_bad_a_taxed_order_stores_components_that_do_not_add_up`
     * pins. The WEB cod writer is the opposite shape: `PaymentService::getProductStatus()` writes
     * the real 22.00 into `orders.tax_price` and builds `final_price` from `total_sub_price`
     * (`PaymentService.php:3039`, `:3112`), so the components DO add up and the double count is
     * **charged**. No existing pin can see that, because every one of them is an "adds up" check.
     *
     * NOT FIXED HERE, AND NOT FIXABLE HERE: it changes what every taxed order is charged, which
     * CLAUDE.md §2 reserves to the owner ("material changes to prices/payments"). The work queue
     * already records it as an order-calculation change needing approval. This case exists so the
     * figure stops being invisible: if it is ever fixed, this case fails with the new total and the
     * fixer inverts it rather than relaxing it.
     */
    public function test_the_charged_total_applies_the_band_twice_which_is_an_owner_decision(): void
    {
        $order = $this->checkout();

        $this->assertNotNull($order, 'premise: the cod checkout wrote an order row');

        $goods = self::UNIT_PRICE * self::QTY;
        $singleBand = $goods * self::TAX_RATE / 100;
        $correct = $goods + $singleBand + self::SHIPPING_COST;
        $charged = $goods + (2 * $singleBand) + ($singleBand * self::TAX_RATE / 100) + self::SHIPPING_COST;

        /* 200 + 20 + 2 + 20 + 25: the second application lands on 220.00, not on 200.00, so it is
           22.00 rather than 20.00. Spelled out so the figure is derived and not copied. */
        $this->assertEqualsWithDelta(267.00, $charged, 0.0001,
            'premise: this case\'s own arithmetic says 267.00 is the double-banded figure');

        $this->assertEqualsWithDelta($charged, (float) $order->final_price, 0.0001, sprintf(
            'THE CHARGED TOTAL IS NO LONGER THE DOUBLE-BANDED %s. It is %s. If it is now %s, the '
            . 'single-band total, then work-queue item S21.4 has been fixed on the WEB writer: '
            . 'invert this case, and say which owner decision authorised it. If it is some third '
            . 'figure, something else moved and this is the place to find out what.',
            number_format($charged, 2), var_export($order->final_price, true),
            number_format($correct, 2)
        ));

        /* AND THE ROW STILL RECONCILES while being wrong, which is the whole reason this needs its
           own case beside the acceptance clause. */
        $this->assertTrue((new OrderTotalReconciler())->reconcile([
            'product_price' => $order->product_price,
            'tax_price' => $order->tax_price,
            'delivery_price' => $order->delivery_price,
            'coupon_price' => $order->coupon_price,
        ], $order->final_price)['matches'],
            'the double-banded row stopped reconciling, which would make the acceptance case above '
            . 'and this one disagree about the same order');
    }

    /*
    |--------------------------------------------------------------------------
    | THE `CURRENCY` QUESTION
    |--------------------------------------------------------------------------
    */

    /**
     * THE PREMISE OF THE WHOLE `CURRENCY` QUESTION, STATED SO IT CANNOT BE MISREAD AGAIN: the two
     * endpoints read a setting name that has **no product-wide default**, on a store provisioned
     * exactly as merchant self-registration provisions one.
     *
     * `defaultSetting()` is run here with the same arguments `RegisteredUserController.php:225`
     * passes. It seeds four currency names; `defult_currancy_symbol` is not among them.
     */
    public function test_premise_registration_seeds_four_currency_names_and_not_the_symbol(): void
    {
        defaultSetting($this->store->id, 'admin', $this->merchant);
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        $seeded = DB::table('settings')->where('store_id', $this->store->id)
            ->whereIn('name', ['CURRENCY_NAME', 'CURRENCYCURRENCY', 'currency_format', 'defult_currancy'])
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(
            ['CURRENCYCURRENCY', 'CURRENCY_NAME', 'currency_format', 'defult_currancy'],
            $seeded,
            'premise: defaultSetting() ran and seeded its four currency names. Without this every '
            . 'case in this section would be measuring a store that was never provisioned at all.'
        );

        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $this->store->id)
                ->where('name', 'defult_currancy_symbol')->count(),
            'premise: and it did NOT seed `defult_currancy_symbol`, which is the name both '
            . 'endpoints read. Only StoreController seeds it, in a block that runs AFTER '
            . 'defaultSetting() (StoreController.php:155, :312).'
        );

        $this->assertArrayNotHasKey(
            'defult_currancy_symbol',
            \App\Models\Utility::Setting($this->store->id),
            'premise: and `Utility::Setting()` has no default for the name either — it carries '
            . '`CURRENCY` and `CURRENCY_NAME` and not this one (app/Models/Utility.php:35-130). '
            . 'THIS is the whole mechanism: with no row and no default, GetValueByName() answers '
            . 'null. If this key ever appears, the defect is fixed at the defaults array instead '
            . 'and the two cases below hold for a different reason — say which in the message.'
        );
    }

    /**
     * THE ANSWER TO §4's QUESTION, AS A CONTRACT RATHER THAN AS A NULL: **both payloads name the
     * store's currency**, on a store with no `defult_currancy_symbol` row.
     *
     * FAILED BEFORE THE FIX, both routes, with `CURRENCY` null — which is the measurement that
     * classifies `"CURRENCY": null` as a code defect rather than a fixture artefact. The fixture
     * here is the ordinary one: the case above proves the absent row is what registration leaves
     * behind, not something this test did.
     */
    public function test_both_payloads_name_the_stores_currency_with_no_symbol_row(): void
    {
        defaultSetting($this->store->id, 'admin', $this->merchant);
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        foreach (['get-shipping-data' => $this->shippingDataPayload(),
                  'get-tax-data' => $this->taxDataPayload()] as $route => $payload) {
            $this->assertArrayHasKey('CURRENCY', $payload, sprintf(
                'premise: `%s` emits a CURRENCY key at all. Without this the assertion below '
                . 'passes vacuously on a payload that has no such field — and "there is no such '
                . 'field" is exactly how this question was previously answered. Payload: %s',
                $route, json_encode($payload)
            ));

            $this->assertSame('$', $payload['CURRENCY'], sprintf(
                '`%s` answered %s instead of the product-wide default symbol. A null here is '
                . 'GAP-143\'s CURRENCY defect: `CartController` reads '
                . '`defult_currancy_symbol`, for which neither the store nor '
                . '`Utility::Setting()` has a value, while every formatted figure in the same '
                . 'response reads `$` from `currency_format_with_sym()`\'s own hardcoded '
                . 'fallback. Payload: %s',
                $route, var_export($payload['CURRENCY'], true), json_encode($payload)
            ));
        }
    }

    /**
     * AND A CONFIGURED SYMBOL STILL WINS, so the case above cannot be passing because the handler
     * now hard-codes `'$'` and stopped reading the store's own setting. A mutant that returns a
     * literal passes that case and fails this one.
     */
    public function test_a_configured_symbol_still_wins_in_both_payloads(): void
    {
        DB::table('settings')->insert([
            'name' => 'defult_currancy_symbol',
            'value' => '£',
            'store_id' => $this->store->id,
            /* NOT NULL with no default. */
            'created_by' => $this->store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        foreach (['get-shipping-data' => $this->shippingDataPayload(),
                  'get-tax-data' => $this->taxDataPayload()] as $route => $payload) {
            $this->assertSame('£', $payload['CURRENCY'] ?? null, sprintf(
                '`%s` did not report the store\'s own configured symbol, so the default is being '
                . 'preferred over the setting. Payload: %s', $route, json_encode($payload)
            ));
        }
    }

    /**
     * AND THE DIVERGENCE THAT MADE THE NULL A DEFECT RATHER THAN A COSMETIC ABSENCE: in the SAME
     * state — no symbol row at all — the formatted money fields read `$…`, because
     * `currency_format_with_sym()` carries its own hardcoded fallback
     * (`app/Helper/helper.php:1739` sets `'$'`, `:1755-1757` overrides it only when the row
     * exists). One store, one checkout, two answers about its currency
     * was the finding; this case asserts they now agree.
     */
    public function test_the_currency_field_and_the_formatted_figures_agree(): void
    {
        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $this->store->id)
                ->where('name', 'defult_currancy_symbol')->count(),
            'premise: no symbol row, which is the state both halves are measured in'
        );

        $tax = $this->taxDataPayload();

        $this->assertArrayHasKey('CURRENCY', $tax, 'premise: the tax payload emits the field');

        $this->assertIsString($tax['CURRENCY'], sprintf(
            'premise: the CURRENCY field holds a symbol at all. It is %s — a null here is the '
            . 'GAP-143 defect, and `assertStringStartsWith("")` would throw rather than fail, so '
            . 'this premise is what makes the comparison below readable.',
            var_export($tax['CURRENCY'], true)
        ));
        $this->assertNotSame('', $tax['CURRENCY'], 'premise: and it is not the empty string');

        $shipping = $this->shippingMethodPayload();
        $formatted = (string) ($shipping['shipping_final_price'] ?? '');

        $this->assertMatchesRegularExpression('/^\D+\d/u', $formatted, sprintf(
            'premise: the formatted delivery figure carries a symbol, which is the half that was '
            . 'never null. Payload: %s', json_encode($shipping)
        ));

        $this->assertStringStartsWith((string) $tax['CURRENCY'], $formatted, sprintf(
            'the CURRENCY field (%s) and the symbol the same checkout formats into its figures '
            . '(%s) disagree. That divergence IS the GAP-143 finding; it is not acceptable in '
            . 'either direction.',
            var_export($tax['CURRENCY'], true), var_export($formatted, true)
        ));
    }

    /**
     * **INVERTED ON 2026-11-26 BECAUSE GAP-278 IS FIXED.** This case used to be
     * `test_known_bad_another_tenants_symbol_reaches_this_stores_checkout` and asserted the
     * neighbour's `€`; its own failure message said to invert it rather than relax it when the
     * fall-through was closed, and that is what happened. Nothing here is weakened: the fixture,
     * the dual session and every premise are byte-for-byte the old ones, and only the EXPECTED
     * symbol moved — from the neighbour's to this store's own default.
     *
     * WHAT IT RECORDED, AND IT WAS **OLDER** THAN THE GAP-143 CHANGE THAT FOUND IT: with no symbol
     * row of its own, this store's checkout reported **ANOTHER TENANT'S** currency symbol to a
     * viewer who holds a panel session for that tenant.
     *
     * THE MECHANISM. `Utility::GetValueByName($name, $storeId)` reads the named store's rows, but on
     * a MISS falls through to `Utility::Setting($storeId)`, which IGNORES its store-id argument
     * whenever `Auth::check()` is true and reads `getCurrentStore()` instead
     * (`app/Models/Utility.php:138-150`). Storefront routes sit in the `web` group with a session
     * cookie path of `/`, so a merchant holding a panel login is a populated web-guard principal on
     * every storefront request. The identical fall-through is recorded as `GAP-230` against
     * `OrderCurrencyStamp::codeFor`, and `DocumentDirection::ownRow()` plus
     * `OrderCurrencyStamp::configuredCodeFor()` were this repository's two existing remedies for it.
     *
     * MEASURED BOTH WAYS WHEN IT WAS RAISED, which is what made the attribution rather than the
     * guess: the neighbour's `€` arrived under the GAP-143 code AND under the original
     * `GetValueByName('defult_currancy_symbol', $store->id)` line, restored and re-run. So that
     * change neither caused nor cured it.
     *
     * WHICH CHANGE DID IT, as this case's old message demanded be recorded: `CartController:851`
     * and `:1084` now read `OrderCurrencyStamp::configuredSymbolFor((int) $store->id) ??
     * DEFAULT_SYMBOL` — the third copy of this repository's standing remedy, a raw query with an
     * explicit `store_id` predicate and `orderByDesc('id')`, alongside `DocumentDirection::ownRow()`
     * and `configuredCodeFor()`. `symbolFor()` is UNTOUCHED, because five ORDER writers stamp
     * `orders.currency_symbol` from it and a stamped currency is recorded financial data (`D-N34`).
     * The reader is pinned in its own right by `Gap278ConfiguredSymbolReaderTest`, including the
     * edges no caller can reach.
     *
     * MEASURED BOTH WAYS AGAIN FOR THE FIX: with the two call sites reverted to `symbolFor()` this
     * case fails with `'€'`, and with them in place it answers `'$'`.
     *
     * HOW BAD IT WAS, stated rather than implied: a store's currency symbol is printed on every
     * price of its public storefront, so this was a MISLABELLED price for one viewer, not a
     * disclosure of anything confidential.
     */
    public function test_another_tenants_symbol_does_not_reach_this_stores_checkout(): void
    {
        foreach (['defult_currancy_symbol', 'currency_symbol'] as $name) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => '€', 'store_id' => $this->neighbourStore->id,
                'created_by' => $this->neighbourStore->created_by,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        $this->assertSame(
            0,
            DB::table('settings')->where('store_id', $this->store->id)
                ->whereIn('name', ['defult_currancy_symbol', 'currency_symbol'])->count(),
            'premise: THIS store still has no symbol row, which is the only state in which the '
            . 'fall-through was reached'
        );

        $neighbourOwner = User::findOrFail($this->neighbourStore->created_by);
        $neighbourOwner->current_store = $this->neighbourStore->id;
        $neighbourOwner->save();

        /* A BROWSER HOLDING BOTH SESSIONS, which is the production shape: the default guard is the
           merchant's `web` one and the customers guard holds the shopper. */
        $this->actingAs($neighbourOwner, 'web');
        $this->actingAs($this->shopper, 'customers');
        $this->app['auth']->shouldUse('web');

        $this->assertSame((int) $this->neighbourStore->id, (int) getCurrentStore(),
            'premise: getCurrentStore() answers for the OTHER tenant. Without this the case '
            . 'measures nothing -- it is the whole condition of the fall-through.');

        /* BOTH SITES, not just the one the finding was raised on. `CartController` emits `CURRENCY`
           from two reads (`:851` in get_shipping_data and `:1084` in get_tax_data) and each was its
           own copy of the defect, so a fix applied to one and a test that drives one would leave
           the other open and look green -- the shape CLAUDE.md records for a print written twice. */
        foreach ($this->bothCurrencyPayloads() as $endpoint => $payload) {
            $this->assertSame(
                \App\Support\Money\OrderCurrencyStamp::DEFAULT_SYMBOL,
                $payload['CURRENCY'] ?? null,
                sprintf(
                    'GAP-278 AT `%s`: the neighbour tenant\'s symbol reached this store\'s '
                    . 'checkout (it answered %s). This store has no symbol row, so the only '
                    . 'correct answer is the platform default -- never the symbol of whichever '
                    . 'tenant the viewer happens to hold a panel session for. Payload: %s',
                    $endpoint, var_export($payload['CURRENCY'] ?? null, true), json_encode($payload)
                )
            );
        }
    }

    /**
     * THE OTHER HALF, AND THE ONE THAT SEPARATES A FIX FROM A HARDCODED DEFAULT: two stores whose
     * configured symbols DIFFER, with the viewer holding the neighbour's panel session.
     *
     * The case above cannot tell "read the route store's own row" from "always answer `$`" — this
     * store has no row in it, so both behaviours give `$`. Here THIS store is configured `£` and
     * the neighbour `€`, so the three candidate behaviours give three different answers: the
     * correct one `£`, the defect `€`, and a reader that lost its chain `$`. Without this case a
     * mutant returning `DEFAULT_SYMBOL` unconditionally survives, and GAP-143's own
     * `test_a_configured_symbol_still_wins_in_both_payloads` cannot see it either, because that
     * case has no competing tenant and no panel session.
     *
     * **IT IS NOT A PIN ON THE CALL-SITE SWAP, AND SAYING SO IS THE POINT — MEASURED.** With
     * `CartController:851`/`:1084` reverted to `symbolFor()` this case STILL PASSES, because when
     * the route store has a row of its own `Utility::GetValueByName()`'s PRIMARY read finds it and
     * the `Utility::Setting()` fall-through is never reached. The fall-through — and therefore the
     * defect — exists only for a store with NO row, which is why the inverted case above is the one
     * that goes red on a revert and this one does not. What this case does buy, and what nothing
     * else here buys: it kills a `configuredSymbolFor()` that answers the default unconditionally,
     * and it proves the swap did not regress a store that HAS configured a symbol — the direction a
     * tenancy fix most easily breaks.
     */
    public function test_this_stores_own_symbol_wins_over_a_neighbours_even_for_that_neighbours_merchant(): void
    {
        DB::table('settings')->insert([
            'name' => 'defult_currancy_symbol', 'value' => '€',
            'store_id' => $this->neighbourStore->id,
            'created_by' => $this->neighbourStore->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'name' => 'defult_currancy_symbol', 'value' => '£',
            'store_id' => $this->store->id,
            'created_by' => $this->store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
        Cache::flush();

        $neighbourOwner = User::findOrFail($this->neighbourStore->created_by);
        $neighbourOwner->current_store = $this->neighbourStore->id;
        $neighbourOwner->save();

        $this->actingAs($neighbourOwner, 'web');
        $this->actingAs($this->shopper, 'customers');
        $this->app['auth']->shouldUse('web');

        $this->assertSame((int) $this->neighbourStore->id, (int) getCurrentStore(),
            'premise: the viewer resolves the OTHER tenant, so a reader that consults the session '
            . 'answers `€` and one that consults the route store answers `£`.');
        $this->assertNotSame('£', '€', 'premise: the two symbols differ, which is the point');

        foreach ($this->bothCurrencyPayloads() as $endpoint => $payload) {
            $this->assertSame('£', $payload['CURRENCY'] ?? null, sprintf(
                'at `%s` the checkout reported %s where this store is configured `£`. `€` is the '
                . 'GAP-278 defect (the viewer\'s store); `$` means the reader lost its setting '
                . 'chain and is answering the default for a store that configured a symbol. '
                . 'Payload: %s',
                $endpoint, var_export($payload['CURRENCY'] ?? null, true), json_encode($payload)
            ));
        }
    }

    /**
     * The `CURRENCY` key from BOTH emitting endpoints, keyed by path so a failure names which one.
     *
     * Driven with the session the CALLER has already established — deliberately NOT through
     * `asAShopper()`, which puts the default guard back on `web` with nobody in it and would
     * dissolve the dual-session premise these two cases depend on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function bothCurrencyPayloads(): array
    {
        $shipping = $this->post('/' . $this->store->slug . '/get-shipping-data', [
            'product_id' => $this->product->id,
            'address_id' => $this->address->id,
            'countryId' => $this->country, 'stateId' => $this->state, 'cityId' => $this->city,
            'coupon_code' => '', 'total_coupon_amount' => '',
        ]);

        $tax = $this->post('/' . $this->store->slug . '/get-tax-data', [
            'product_id' => $this->product->id,
            'billing_address_id' => $this->address->id,
            'countryId' => $this->country, 'stateId' => $this->state, 'cityId' => $this->city,
            'total_coupon_amount' => '',
            'billing_info' => ['delivery_country' => $this->country,
                'delivery_state' => $this->state, 'delivery_city' => $this->city],
        ]);

        $payloads = [];

        foreach (['get-shipping-data' => $shipping, 'get-tax-data' => $tax] as $endpoint => $response) {
            $this->assertNotSame(404, $response->status(),
                "premise: {$endpoint} is a real route -- a payload assertion is satisfied by a 404");
            $this->assertSame(200, $response->status(),
                "premise: {$endpoint} answered 200; every assertion about its payload is vacuous "
                . 'on a redirect or a 500');

            $payload = $this->decode($response->getContent());

            $this->assertArrayHasKey('CURRENCY', $payload, sprintf(
                'premise: %s emitted a CURRENCY key at all. Without it `$payload[\'CURRENCY\'] ?? '
                . 'null` is null and an equality assertion would be measuring the absence of the '
                . 'field rather than its value. Payload: %s', $endpoint, json_encode($payload)
            ));

            $payloads[$endpoint] = $payload;
        }

        return $payloads;
    }

    /*
    |--------------------------------------------------------------------------
    | drivers
    |--------------------------------------------------------------------------
    */

    /**
     * A SHOPPER AS PRODUCTION HAS ONE, which is not what `actingAs($c, 'customers')` alone builds.
     *
     * `actingAs` also calls `shouldUse('customers')`, so the DEFAULT guard becomes the customers
     * guard and `Auth::check()`, `auth()->user()` and `getCurrentStore()` — all web-guard-only by
     * design — start answering for the Customer. `Utility::Setting()` branches on exactly that
     * (`app/Models/Utility.php:138-150`), and so does `StoreScope::resolveTenant()`. In production
     * the default guard is `web` with nobody in it. Putting it back is what makes the `CURRENCY`
     * measurement below a measurement of the handler rather than of the harness.
     */
    private function asAShopper(): self
    {
        $this->actingAs($this->shopper, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    /** `POST {slug}/get-shipping-data` — zone resolution and method offering. */
    private function shippingDataPayload(): array
    {
        $response = $this->asAShopper()->post(
            '/' . $this->store->slug . '/get-shipping-data',
            [
                'address_id' => $this->address->id,
                'countryId' => $this->country,
                'stateId' => $this->state,
                'cityId' => $this->city,
                'coupon_code' => '',
                'total_coupon_amount' => '',
            ]
        );

        $this->assertNotSame(404, $response->status(),
            'premise: the get-shipping-data route exists. A payload assertion is satisfied by a 404.');
        $this->assertSame(200, $response->status(),
            'premise: get-shipping-data answered 200; every assertion about its payload is vacuous '
            . 'on a redirect or a 500');

        return $this->decode($response->getContent());
    }

    /** `POST {slug}/shipping-method` — the cost of the selected method. */
    private function shippingMethodPayload(): array
    {
        $response = $this->asAShopper()->post(
            '/' . $this->store->slug . '/shipping-method',
            [
                'method_id' => $this->method->id,
                'billing_address_id' => $this->address->id,
                'countryId' => $this->country,
                'stateId' => $this->state,
                'cityId' => $this->city,
            ]
        );

        $this->assertNotSame(404, $response->status(), 'premise: the shipping-method route exists');
        $this->assertSame(200, $response->status(), 'premise: shipping-method answered 200');

        return $this->decode($response->getContent());
    }

    /** `POST {slug}/get-tax-data` — the band for the shopper's jurisdiction. */
    private function taxDataPayload(): array
    {
        $response = $this->asAShopper()->post(
            '/' . $this->store->slug . '/get-tax-data',
            [
                'product_id' => $this->product->id,
                'billing_address_id' => $this->address->id,
                'countryId' => $this->country,
                'stateId' => $this->state,
                'cityId' => $this->city,
                'total_coupon_amount' => '',
                'billing_info' => [
                    'delivery_country' => $this->country,
                    'delivery_state' => $this->state,
                    'delivery_city' => $this->city,
                ],
            ]
        );

        $this->assertNotSame(404, $response->status(), 'premise: the get-tax-data route exists');
        $this->assertSame(200, $response->status(), 'premise: get-tax-data answered 200');

        return $this->decode($response->getContent());
    }

    /** The shipped web checkout, cod, with the shipping method selected. Returns the stored order. */
    private function checkout(): ?Order
    {
        $this->asAShopper()->post(
            '/' . $this->store->slug . '/process-order',
            [
                'slug' => $this->store->slug,
                'store_id' => $this->store->id,
                'customer_id' => $this->shopper->id,
                'payment_type' => 'cod',
                'method_id' => $this->method->id,
                'billing_address_id' => $this->address->id,
                'billing_info' => [
                    'firstname' => 'G143', 'lastname' => 'Shopper',
                    'email' => $this->shopper->email,
                    'billing_user_telephone' => '0100000000',
                    'billing_address' => 'G143 Street',
                    'billing_postecode' => '12345',
                    'billing_country' => $this->country,
                    'billing_state' => $this->state,
                    'billing_city' => $this->city,
                    'billing_company_name' => '',
                    'delivery_address' => 'G143 Street',
                    'delivery_postcode' => '12345',
                    'delivery_country' => $this->country,
                    'delivery_state' => $this->state,
                    'delivery_city' => $this->city,
                ],
            ]
        );

        /* `Order` is NOT one of config/tenancy.php's scoped models, so no global scope applies --
           withoutGlobalScopes() is written anyway so that a later addition to the allowlist cannot
           silently change what this read answers. */
        return Order::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->latest('id')
            ->first();
    }

    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : ['__raw' => $body];
    }
}
