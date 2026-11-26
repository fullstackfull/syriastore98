<?php

namespace Tests\Feature\Commerce;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Testimonial;
use App\Support\Commerce\VerifiedPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CUST-005` — VERIFIED REVIEWS. THE HALF THE MATRIX NEVER CARRIED.
 * =================================================================================================
 *
 * `DECISION-ID-REGISTER.md` §4.3 records `CUST-005` («التقييمات الموثقة والأسئلة والمفضلة») as
 * **half-tracked**: *"product questions appear once in the matrix; **verified reviews appear 0×**"*.
 * `GAP-312` then measured why — the fact was not merely ungated but **unrecordable**: no `verified`,
 * `is_verified`, `verified_purchase` or `order_id` column existed on `testimonials`.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT IS BUILT, AND THE LINE THIS TEST HOLDS
 * -------------------------------------------------------------------------------------------------
 *
 * Built: `App\Support\Commerce\VerifiedPurchase` (the fact), two nullable columns (the record), two
 * storefront writers that stamp them, and `product_detail` / `product_detail_guest` emitting
 * `verified_purchase` per review (the badge's data).
 *
 * **NOT built, deliberately: the GATE.** `Gap193ReviewPurchaseGateAndTenancyTest` pins its absence,
 * and owner decision `J19` owns the rule. So `test_a_review_from_a_customer_who_bought_nothing_is_
 * still_accepted_and_recorded_as_unverified` below is as load-bearing as the positive case: it is
 * the assertion that this change DENIED NO JOURNEY. If somebody later turns this into a gate, that
 * case goes red and `J19` gets looked at.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FOUR WAYS A TEST OF THIS COULD PASS FOR THE WRONG REASON, AND WHAT IS DONE ABOUT EACH
 * -------------------------------------------------------------------------------------------------
 *
 * 1. **`ApiResponser::error()` ANSWERS HTTP 200** with `{status: 0}` (`CLAUDE.md` §1). A 200 is not
 *    an acceptance. Every door case asserts `status === 1` as a PREMISE before it looks at a row.
 *
 * 2. **`Testimonial` IS `Cachable` AND CARRIES NO `StoreScope`** — it is in `CLAUDE.md` §1's "no
 *    scope at all" row. So every row assertion here reads `DB::table('testimonials')`, never
 *    `Testimonial::find()`: a scoped read would answer against whichever tenant happened to
 *    resolve, and `CLAUDE.md` records a cart test that reported a deleted row the raw table still
 *    held.
 *
 * 3. **`Order` CARRIES NO SCOPE EITHER**, which is the whole point of the cross-tenant case. A
 *    `VerifiedPurchase` with no `store_id` predicate would pass every other case in this file.
 *
 * 4. **AN "IT IS NULL" ASSERTION IS SATISFIED BY A COLUMN THAT DOES NOT EXIST** — `DB::table()`
 *    returns a row object without the key and `$row->verified_purchase ?? null` is null either
 *    way. {@see self::test_the_schema_can_record_a_verified_purchase} is the premise for the whole
 *    file and asserts both columns against the LIVE schema, which is `CLAUDE.md` §1's rule
 *    ("the migrations are not the schema").
 *
 * @see \App\Support\Commerce\VerifiedPurchase
 * @see \Tests\Feature\Security\Gap193ReviewPurchaseGateAndTenancyTest  pins the GATE's absence
 */
final class VerifiedPurchaseReviewTest extends SecurityTestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | fixture
    |--------------------------------------------------------------------------
    */

    /** @return array{0: \App\Models\Store, 1: Category, 2: Product} */
    private function tenantWithAProduct(string $tag): array
    {
        [$merchant, $store] = $this->makeTenant();

        /* No `created_by`: `categories` has no such column — measured by the sibling GAP-193 test. */
        $category = Category::forceCreate([
            'name' => $tag . '-cat', 'slug' => $tag . '-cat-' . uniqid(),
            'store_id' => $store->id,
        ]);

        $product = Product::forceCreate([
            'name' => $tag . '-product', 'slug' => $tag . '-' . uniqid(),
            'price' => 100.00, 'sale_price' => 100.00,
            'product_stock' => 10, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock', 'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        return [$store, $category, $product];
    }

    private function shopper($store, string $tag): Customer
    {
        return Customer::forceCreate([
            'first_name' => $tag, 'last_name' => 'Shopper',
            'email' => $tag . '-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * A SIGNED-IN purchase of one product: `is_guest = 0`, which is the clause that matters.
     *
     * Written through `SecurityTestCase::makeOrder()` so the `currency_code` / `currency_symbol`
     * skip guard applies — `App\Models\Order`'s `creating` hook writes both and no migration
     * declares them, so a bare `Order::create()` here would fail on a from-zero schema.
     */
    private function purchase($store, Customer $shopper, array $productIds, array $attributes = []): Order
    {
        $lines = [];
        foreach ($productIds as $id) {
            $lines[] = ['product_id' => $id, 'qty' => 1, 'price' => 100];
        }

        return $this->makeOrder($store, array_merge([
            'customer_id' => $shopper->id,
            'is_guest' => 0,
            'product_json' => json_encode($lines),
            'product_id' => implode(',', $productIds),
        ], $attributes));
    }

    /** The row as the TABLE holds it. Never `Testimonial::find()` — see the class docblock, trap 2. */
    private function reviewRow(int $productId): ?object
    {
        return DB::table('testimonials')->where('product_id', $productId)->first();
    }

    /** POST the storefront rating door as this shopper's own bearer token. */
    private function postRating($store, Customer $shopper, Product $product, string $path = 'product-rating'): \Illuminate\Testing\TestResponse
    {
        $token = $shopper->createToken('vp')->plainTextToken;

        $payload = $path === 'product-rating'
            ? ['id' => $product->id, 'user_id' => $shopper->id, 'rating_no' => 5,
               'title' => 'Bought it', 'description' => 'Five stars.']
            : ['product_id' => $product->id, 'category_id' => $product->category_id, 'rating_no' => 5,
               'title' => 'Bought it', 'description' => 'Five stars.', 'status' => 1];

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/' . $path, $payload);

        $this->assertNotSame(404, $response->status(), sprintf(
            'premise: api/{slug}/%s exists. A row assertion is satisfied by a 404.', $path
        ));
        $this->assertSame(1, (int) $response->json('status'), sprintf(
            'premise: the door ACCEPTED the review. ApiResponser::error() answers HTTP 200 with '
            . 'status 0, so the status code alone cannot tell a refusal from an acceptance. Body: %s',
            $response->getContent()
        ));

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | 0 — the premise for everything below
    |--------------------------------------------------------------------------
    */

    /**
     * `GAP-312`: *"the schema has no home for the fact."* It does now, and this asserts it against
     * the LIVE schema rather than against the migration file.
     *
     * Trap 4 of the class docblock: without this, every `assertNull` in this file is satisfied by a
     * column that was never created.
     */
    public function test_the_schema_can_record_a_verified_purchase(): void
    {
        foreach (['verified_purchase', 'verified_order_id'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('testimonials', $column),
                sprintf(
                    'testimonials.%s must exist, or GAP-312 is still open and every "it is NULL" '
                    . 'assertion in this file passes vacuously.', $column
                )
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — the storefront doors record the fact
    |--------------------------------------------------------------------------
    */

    /** A shopper who bought the product, signed in, is recorded as verified against that order. */
    public function test_a_review_from_a_real_buyer_is_recorded_as_verified(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-buy');
        $shopper = $this->shopper($store, 'vp-buy');
        $order = $this->purchase($store, $shopper, [$product->id]);

        $this->postRating($store, $shopper, $product);

        $row = $this->reviewRow($product->id);

        $this->assertNotNull($row, 'premise: the review was written at all.');
        $this->assertSame(1, (int) $row->verified_purchase,
            'a signed-in customer who bought this product must be recorded as verified.');
        $this->assertSame((int) $order->id, (int) $row->verified_order_id,
            'and the recorded evidence must be the order that actually contains the product.');
    }

    /** The second storefront door, `add-review`, records it the same way. One behaviour, two doors. */
    public function test_the_add_review_door_records_the_fact_too(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-add');
        $shopper = $this->shopper($store, 'vp-add');
        $order = $this->purchase($store, $shopper, [$product->id]);

        $this->postRating($store, $shopper, $product, 'add-review');

        $row = $this->reviewRow($product->id);

        $this->assertNotNull($row, 'premise: the review was written at all.');
        $this->assertSame(1, (int) $row->verified_purchase,
            'add-review is the second writer of this table and must not disagree with product-rating.');
        $this->assertSame((int) $order->id, (int) $row->verified_order_id);
    }

    /**
     * **THE CASE THAT PROVES NO JOURNEY WAS DENIED.**
     *
     * A customer with zero orders still publishes a review, still gets `status 1`, and the row is
     * recorded as `verified_purchase = 0` rather than refused. This change adds a FACT, not a gate;
     * the gate is `J19`. If this case ever goes red, somebody has introduced the owner's decision
     * without the owner.
     */
    public function test_a_review_from_a_customer_who_bought_nothing_is_still_accepted_and_recorded_as_unverified(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-none');
        $shopper = $this->shopper($store, 'vp-none');

        $this->assertSame(0, Order::withoutGlobalScopes()->where('customer_id', $shopper->id)->count(),
            'premise: this shopper has never ordered anything.');

        $this->postRating($store, $shopper, $product);

        $row = $this->reviewRow($product->id);

        $this->assertNotNull($row, 'THE REVIEW MUST STILL BE PUBLISHED. This change is not a gate.');
        $this->assertSame(1, (int) $row->status, 'and still published live, exactly as before.');
        /*
         * `assertNotNull` FIRST, and this caught itself on the fail-first run. `(int) null` is 0,
         * so `assertSame(0, (int) $row->verified_purchase)` ALONE passed before the writer existed —
         * trap 4 of the class docblock, biting the case written to describe it. NULL means nobody
         * looked; 0 means somebody looked and found nothing. The distinction is the whole point of
         * the column being nullable, so it is asserted and not cast away.
         */
        $this->assertNotNull($row->verified_purchase,
            'the writer must have ASSESSED this review. A null here is the pre-change behaviour and '
            . 'casts to 0, so this case would pass without the writer existing at all.');
        $this->assertSame(0, (int) $row->verified_purchase,
            'assessed and found wanting is 0, NOT null: null means nobody looked.');
        $this->assertNull($row->verified_order_id);
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — the clauses of the predicate, each on its own
    |--------------------------------------------------------------------------
    */

    /**
     * **TENANCY. `Order` carries no `StoreScope` and is absent from `scoped_models`**, so a
     * `VerifiedPurchase` without an explicit `store_id` would read another store's order book and
     * every other case in this file would still pass.
     */
    public function test_an_order_in_another_store_does_not_verify_a_review_in_this_one(): void
    {
        [$storeA, , $productA] = $this->tenantWithAProduct('vp-ta');
        [$storeB, , $productB] = $this->tenantWithAProduct('vp-tb');

        $shopper = $this->shopper($storeA, 'vp-ta');

        /* The SAME customer id, a real signed-in order, in store B — and naming store A's product
           id as well, so the line match cannot be what refuses it. Only `store_id` can. */
        $this->purchase($storeB, $shopper, [$productA->id, $productB->id]);

        $this->assertNull(
            VerifiedPurchase::orderIdFor($shopper->id, $productA->id, $storeA->id),
            'an order belonging to store B must not verify a purchase in store A. `Order` has NO '
            . 'global scope, so the explicit store predicate is the whole control.'
        );
        $this->assertNotNull(
            VerifiedPurchase::orderIdFor($shopper->id, $productA->id, $storeB->id),
            'premise: the order really does contain that product — so the case above measured the '
            . 'store predicate and not a line-match failure.'
        );
    }

    /**
     * **A GUEST ORDER VERIFIES NOBODY**, and this is a security clause, not a policy one.
     *
     * `GAP-214`: a guest checkout adopts the `Customer` id found from the billing e-mail the buyer
     * TYPED, "with no proof of ownership". So a stranger could type a victim's address, buy, and
     * have the victim's account — or their own review — read as verified.
     */
    public function test_a_guest_order_does_not_verify_the_customer_it_names(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-guest');
        $shopper = $this->shopper($store, 'vp-guest');

        $this->purchase($store, $shopper, [$product->id], ['is_guest' => 1]);

        $this->assertNull(
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'is_guest = 1 means the customer id on the order is an unproven claim (GAP-214).'
        );
    }

    /** A cancelled or returned order is not a purchase. `delivered_status` 2 and 3. */
    public function test_a_cancelled_or_returned_order_does_not_verify(): void
    {
        foreach ([2 => 'cancelled', 3 => 'return'] as $status => $name) {
            [$store, , $product] = $this->tenantWithAProduct('vp-w' . $status);
            $shopper = $this->shopper($store, 'vp-w' . $status);

            $this->purchase($store, $shopper, [$product->id], ['delivered_status' => $status]);

            $this->assertNull(
                VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
                sprintf('delivered_status %d (%s) withdraws the purchase.', $status, $name)
            );
        }
    }

    /**
     * An UNPAID, uncancelled order DOES verify — the clause `payment_status` is deliberately absent
     * from, because `P04-COD-01` records that no COD order can ever be settled through the approved
     * mechanism, so requiring `Paid` would mark every COD buyer unverified for good.
     *
     * This case exists so that the decision is a measurement rather than an omission: it is the one
     * clause a reasonable owner might want tightened, and tightening it turns this case red.
     */
    public function test_an_unpaid_uncancelled_order_still_verifies_because_cod_can_never_be_settled(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-cod');
        $shopper = $this->shopper($store, 'vp-cod');

        $order = $this->purchase($store, $shopper, [$product->id], [
            'payment_type' => 'cod', 'payment_status' => 'Unpaid', 'delivered_status' => 0,
        ]);

        $this->assertSame(
            (int) $order->id,
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'a COD order is a real purchase. P04-COD-01: `cod_collection_events` has no migration, '
            . 'so a Paid predicate would exclude every COD buyer permanently.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — the line match, in each shape the column really carries
    |--------------------------------------------------------------------------
    */

    /** `product_json` empty, the comma-joined `orders.product_id` carrying the truth. */
    public function test_the_comma_joined_product_id_column_is_a_fallback(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-csv');
        $shopper = $this->shopper($store, 'vp-csv');

        $order = $this->purchase($store, $shopper, [$product->id], [
            'product_json' => '[]',
            'product_id' => '9991,' . $product->id . ',9992',
        ]);

        $this->assertSame(
            (int) $order->id,
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'the CSV column is the fallback for a row whose json is absent or unreadable.'
        );
    }

    /** And it must compare as integers, not as substrings: `12` is not in `112,1234`. */
    public function test_the_comma_joined_column_does_not_substring_match(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-sub');
        $shopper = $this->shopper($store, 'vp-sub');

        $this->purchase($store, $shopper, [$product->id], [
            'product_json' => '[]',
            'product_id' => ((int) $product->id) . '00,' . ((int) $product->id) . '99',
        ]);

        $this->assertNull(
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'a product whose id is a PREFIX of the ids in the CSV was not bought.'
        );
    }

    /**
     * The single-line shape: `product_json` holding one line as an OBJECT, not a list of one.
     *
     * `App\Models\Order::orderData()` unwraps exactly this (`isset($products['id']) ||
     * isset($products['product_id'])`), so it is a shape the column really carries.
     */
    public function test_a_single_line_product_json_object_is_read(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-one');
        $shopper = $this->shopper($store, 'vp-one');

        $order = $this->purchase($store, $shopper, [$product->id], [
            'product_json' => json_encode(['product_id' => $product->id, 'qty' => 1]),
            'product_id' => '',
        ]);

        $this->assertSame(
            (int) $order->id,
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id)
        );
    }

    /** A line keyed `id` rather than `product_id` — the other spelling `orderData()` accepts. */
    public function test_a_line_keyed_id_rather_than_product_id_is_read(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-key');
        $shopper = $this->shopper($store, 'vp-key');

        $order = $this->purchase($store, $shopper, [$product->id], [
            'product_json' => json_encode([['id' => $product->id, 'qty' => 2]]),
            'product_id' => '',
        ]);

        $this->assertSame(
            (int) $order->id,
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id)
        );
    }

    /**
     * THE EARLIEST qualifying order is the one recorded, so a later purchase cannot change what an
     * already-published review says it was verified by.
     */
    public function test_the_earliest_qualifying_order_is_the_evidence(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-first');
        $shopper = $this->shopper($store, 'vp-first');

        $first = $this->purchase($store, $shopper, [$product->id]);
        $second = $this->purchase($store, $shopper, [$product->id]);

        $this->assertNotSame((int) $first->id, (int) $second->id, 'premise: two distinct orders.');
        $this->assertSame(
            (int) $first->id,
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'stability: the recorded evidence must not move when the shopper buys again.'
        );
    }

    /**
     * **A TRAILING-JUNK ID MUST NOT BE COERCED INTO THE REAL ONE.**
     *
     * Written because a mutation survived without it. Replacing the `filter_var` with a plain
     * `(int)` cast left all sixteen cases green: `(int) 'abc'` is 0 and `(int) '-1'` is -1, so the
     * generic junk case below refuses them for the WRONG reason — the `<= 0` guard, not the strict
     * parse. Only an id that a loose cast turns INTO A REAL ONE separates the two, which is exactly
     * the shape `ReviewTargetOwnership` records MySQL coercing in a `WHERE` (`'5abc'` -> 5).
     *
     * The premise assertion is load-bearing: without it, three `assertNull`s are satisfied by a
     * fixture whose ids never matched anything.
     */
    public function test_an_id_with_trailing_junk_is_not_coerced_into_the_real_one(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-coerce');
        $shopper = $this->shopper($store, 'vp-coerce');
        $this->purchase($store, $shopper, [$product->id]);

        $this->assertNotNull(
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, $store->id),
            'premise: the CLEAN ids resolve, so the three refusals below measure the parse.'
        );

        $this->assertNull(
            VerifiedPurchase::orderIdFor(((int) $shopper->id) . 'abc', $product->id, $store->id),
            'a customer id a loose cast would turn into the real one must be refused.'
        );
        $this->assertNull(
            VerifiedPurchase::orderIdFor($shopper->id, ((int) $product->id) . 'abc', $store->id),
            'likewise the product id.'
        );
        $this->assertNull(
            VerifiedPurchase::orderIdFor($shopper->id, $product->id, ((int) $store->id) . 'abc'),
            'likewise the store id — and this one is a tenancy boundary, not a tidiness point.'
        );
    }

    /** Junk and zero ids answer null rather than matching a guest row's `customer_id = 0`. */
    public function test_junk_identifiers_answer_null(): void
    {
        [$store, , $product] = $this->tenantWithAProduct('vp-junk');
        $shopper = $this->shopper($store, 'vp-junk');
        $this->purchase($store, $shopper, [$product->id]);

        foreach ([null, 0, '0', '', 'abc', '5abc', -1] as $bad) {
            $this->assertNull(
                VerifiedPurchase::orderIdFor($bad, $product->id, $store->id),
                sprintf('customer id %s must not resolve.', var_export($bad, true))
            );
            $this->assertNull(
                VerifiedPurchase::orderIdFor($shopper->id, $bad, $store->id),
                sprintf('product id %s must not resolve.', var_export($bad, true))
            );
            $this->assertNull(
                VerifiedPurchase::orderIdFor($shopper->id, $product->id, $bad),
                sprintf('store id %s must not resolve.', var_export($bad, true))
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — the badge's data reaches the storefront API
    |--------------------------------------------------------------------------
    */

    /** `product_detail` must emit the flag per review, or no client can render a badge. */
    public function test_product_detail_emits_the_verified_flag_per_review(): void
    {
        [$store, $category, $product] = $this->tenantWithAProduct('vp-api');
        $buyer = $this->shopper($store, 'vp-api-buyer');
        $nonBuyer = $this->shopper($store, 'vp-api-other');

        $this->purchase($store, $buyer, [$product->id]);

        /* Written through the door, so the emitted value is the one the writer recorded. */
        $this->postRating($store, $buyer, $product);

        Testimonial::forceCreate([
            'user_id' => $nonBuyer->id, 'category_id' => $category->id,
            'product_id' => $product->id, 'rating_no' => 1, 'title' => 'Never bought',
            'description' => 'One star.', 'status' => 1, 'store_id' => $store->id,
            'verified_purchase' => 0,
        ]);

        $token = $buyer->createToken('vp-read')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/product-detail', ['id' => $product->id]);

        $this->assertSame(1, (int) $response->json('status'),
            'premise: product-detail answered. Body: ' . $response->getContent());

        $reviews = $response->json('data.product_Review');

        $this->assertIsArray($reviews, 'premise: the review array is present.');
        $this->assertCount(2, $reviews, 'premise: both reviews are served.');

        $flags = [];
        foreach ($reviews as $review) {
            $this->assertArrayHasKey('verified_purchase', $review,
                'every review must carry the flag, or a client cannot tell "unverified" from '
                . '"this build does not report it".');
            $flags[(string) $review['title']] = $review['verified_purchase'];
        }

        $this->assertTrue($flags['Bought it'], 'the buyer\'s review is verified.');
        $this->assertFalse($flags['Never bought'], 'the non-buyer\'s is not.');
    }

    /**
     * **THE MERCHANT MUST BE ABLE TO SEE IT, AND ALL THREE STATES MUST LOOK DIFFERENT.**
     *
     * The review list is the only surface in the application where a human reads reviews one at a
     * time, so it is where the badge earns anything. Driven through the DataTable class with its own
     * closures, the pattern `PanelListStoredXssTest` uses — the merchant panel's list is served by
     * ajax and the rendering lives in the column closure, not in the Blade.
     *
     * The third state is the one worth a case of its own: `null` must NOT read as "no purchase
     * found". That would publish, to the person deciding whether to delete a review, a negative
     * finding nobody ever made — of every review written before the column existed.
     */
    public function test_the_merchant_review_list_shows_all_three_verification_states_distinctly(): void
    {
        [$store, $category, $product] = $this->tenantWithAProduct('vp-dt');
        $merchant = \App\Models\User::find($store->created_by);

        $rows = [
            'verified-row' => 1,
            'unverified-row' => 0,
            'unassessed-row' => null,
        ];

        foreach ($rows as $title => $state) {
            Testimonial::forceCreate([
                'user_id' => $merchant->id, 'category_id' => $category->id,
                'product_id' => $product->id, 'rating_no' => 4, 'title' => $title,
                'description' => $title, 'username' => $title, 'status' => 1,
                'store_id' => $store->id, 'verified_purchase' => $state,
            ]);
        }

        $this->actingAs($merchant);
        request()->headers->set('X-Requested-With', 'XMLHttpRequest');
        request()->merge(['draw' => 1, 'start' => 0, 'length' => 25]);

        $table = app(\App\DataTables\TestimonialDataTable::class);
        $envelope = $table->dataTable(
            Testimonial::query()->where('store_id', $store->id)
        )->toJson()->getData(true);

        $this->assertArrayHasKey('data', $envelope, 'premise: the list rendered.');
        $this->assertCount(3, $envelope['data'], 'premise: all three rows are in the list.');

        $cells = [];
        foreach ($envelope['data'] as $row) {
            $this->assertArrayHasKey('verified_purchase', $row,
                'the column must reach the merchant, or the recorded fact is invisible to the only '
                . 'person who reads reviews one at a time.');
            $cells[(string) $row['description']] = (string) $row['verified_purchase'];
        }

        $this->assertStringContainsString('ss-badge--success', $cells['verified-row'],
            'a verified purchase is the design system\'s success badge.');
        $this->assertStringContainsString(__('Verified purchase'), $cells['verified-row']);

        $this->assertStringContainsString('ss-badge--warning', $cells['unverified-row']);
        $this->assertStringContainsString(__('No purchase found'), $cells['unverified-row']);

        /* THE THIRD STATE. Not a badge of any tone, and not the words of a finding. */
        $this->assertStringNotContainsString('ss-badge', $cells['unassessed-row'],
            'an UNASSESSED review must not wear a verdict badge: null means nobody looked, and '
            . 'every review written before this column existed is null.');
        $this->assertStringNotContainsString(__('No purchase found'), $cells['unassessed-row'],
            'and it must not be WORDED as a negative finding either.');

        /*
         * COLOUR IS NOT THE ONLY CHANNEL (WCAG 1.4.1). `DsBadge` guarantees the dot, and P2-05-4
         * records a hand-built span that failed the live contrast check in all four LTR/RTL x
         * light/dark configurations for want of it. Asserted rather than trusted, because this
         * column could be rewritten inline by anyone.
         */
        foreach (['verified-row', 'unverified-row'] as $withBadge) {
            $this->assertStringContainsString('ss-badge__dot', $cells[$withBadge],
                'the badge needs its second channel: colour alone must not carry the meaning.');
        }
    }

    /**
     * **EVERY NEW USER-VISIBLE STRING HAS A REAL ARABIC ROW.**
     *
     * `CLAUDE.md` §6: the product is bilingual Arabic (RTL) and English (LTR), and any UI change
     * must be checked in both directions. The five pre-existing segment-rule labels all carry
     * Arabic rows, so an English-only addition beside them would render English on an Arabic screen
     * — a defect that is invisible to every behavioural test in this file.
     *
     * Asserted on `\p{Arabic}` rather than on mere presence, because a row whose value is a copy of
     * its English key satisfies "has a translation" and shows English anyway;
     * `ArabicInventoryRatchetTest` carries the same instrument for the whole file and names its
     * dispositioned exceptions, and nothing here may join that set silently.
     */
    public function test_every_new_user_visible_string_is_translated(): void
    {
        $ar = json_decode(
            (string) file_get_contents(base_path('resources/lang/ar.json')), true, 512, JSON_THROW_ON_ERROR
        );

        $this->assertIsArray($ar);
        $this->assertGreaterThan(4000, count($ar),
            'premise: ar.json was read whole. A short read makes every assertion below vacuous.');

        $strings = [
            /* the merchant review list's column and its three cell states */
            'Verified',
            'Verified purchase',
            'No purchase found',
            'This review was not checked against a purchase.',
            'This reviewer bought this product in an order placed from their own account.',
            "No completed order from this customer's own account contains this product.",
            /* MKT-005's segment rule label, offered in the same change */
            'Has at least this many loyalty points',
        ];

        foreach ($strings as $english) {
            $this->assertArrayHasKey($english, $ar, sprintf(
                'no Arabic row for %s, so an Arabic merchant reads English on this screen.', $english
            ));
            $this->assertSame(1, preg_match('/\p{Arabic}/u', (string) $ar[$english]), sprintf(
                'the Arabic row for %s carries no Arabic script, which renders as English.', $english
            ));
        }
    }

    /**
     * A MERCHANT-PANEL review is left NULL, not 0.
     *
     * `testimonials.user_id` holds ids from TWO different tables depending on which writer created
     * the row (recorded at `ApiController`'s own review reader, and `GAP-335` turns on it). On a
     * panel row it is a `users` id, so "did this customer buy it" is not a question that can be
     * asked — and NULL is this schema's word for *nobody assessed it*. Writing 0 there would
     * publish a negative finding nobody made.
     */
    public function test_a_merchant_panel_review_is_left_unassessed_rather_than_unverified(): void
    {
        [$store, $category, $product] = $this->tenantWithAProduct('vp-panel');

        Testimonial::forceCreate([
            'user_id' => $store->created_by, 'category_id' => $category->id,
            'product_id' => $product->id, 'rating_no' => 5, 'title' => 'Panel row',
            'description' => 'Written in the merchant panel.', 'status' => 1,
            'store_id' => $store->id,
        ]);

        $row = $this->reviewRow($product->id);

        $this->assertNotNull($row, 'premise: the row exists.');
        $this->assertNull($row->verified_purchase,
            'a row whose reviewer is a `users` id must stay unassessed, not be declared unverified.');
    }
}
