<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripsSourceComments;

/**
 * GAP-193 / `CUST-005` — ARE STOREFRONT REVIEWS GATED ON A REAL PURCHASE? AND ARE THEY CONFINED?
 * =================================================================================================
 *
 * `CUST-005` (`DECISION-ID-REGISTER.md:551`) asks for **verified reviews**, questions and wishlists.
 * The gap register records it **"Half-tracked. Product questions appear once in the matrix; verified
 * reviews appear zero times."** Next action: *"Assess whether storefront reviews are gated on a real
 * purchase; Phase 03 is declared engineering-complete."*
 *
 * TWO SEPARATE QUESTIONS, AND THEY GET DIFFERENT ANSWERS AND DIFFERENT DISPOSITIONS.
 *
 * -------------------------------------------------------------------------------------------------
 * 1. THE PURCHASE GATE: **ABSENT.** Measured, and reported rather than built.
 * -------------------------------------------------------------------------------------------------
 * There are four writers of the `testimonials` table and **not one of them consults `orders`,
 * `order_details`, `product_json` or anything else that records a purchase.** Driven below: a
 * customer who has bought nothing publishes a live 5-star review, `status = 1`, no moderation.
 *
 * So a rating is "what signed-in shoppers said", not "what buyers said", and nothing on the
 * storefront distinguishes the two — there is no `verified` column on the table
 * (`2023_12_26_095726_create_testimonials_table.php:14-28`) for a badge to read from.
 *
 * **NOT BUILT HERE, deliberately.** Whether a review requires a purchase is a PRODUCT RULE: it
 * denies a journey that works today, it needs a `verified` flag or a gate decision (refuse, or
 * publish unverified-and-labelled), and Phase 03 is declared engineering-complete, so adding one is
 * a scope question and not a bug fix. The brief for this row says to say so and stop, and that is
 * what the cases below do — they PIN the absence so the row cannot go stale silently.
 *
 * -------------------------------------------------------------------------------------------------
 * 2. THE TENANCY QUESTION: **A REAL DEFECT, AND IT IS ENGINEERING.** Fixed here.
 * -------------------------------------------------------------------------------------------------
 * `Testimonial` carries **no `StoreScope`** and is deliberately absent from `config/tenancy.php`'s
 * `scoped_models`, so every read and write of it is unpredicated unless a handler says otherwise.
 *
 * The three API writers all scope their `product_id` to the store in the path —
 * `ApiController::add_review`, `::product_rating` and `DashboardController::AddReview` each do
 * `Product::where('id', …)->where('store_id', $store->id)`. **The two merchant-panel writers do
 * not.** `TestimonialController::store()` and `::update()` took `product_id` and `category_id`
 * straight from request input and stamped `store_id = getCurrentStore()` — so a merchant could
 * write a row that says *"this is store A's review, of store B's product"*.
 *
 * AND THAT ROW IS READ. `ApiController::product_detail():5834` and `::product_detail_guest():5991`
 * both do `Testimonial::where('product_id', $id)->get()` with **no store predicate** — and their
 * shared docblock states the reason explicitly:
 *
 *     "the gallery and the review loop are inside `if (!empty($product))`, so once the product must
 *      be this store's they are dependent lookups on a **store-verified parent**."
 *
 * That reasoning is correct **and it rests on a premise the writer did not guarantee** — exactly the
 * shape `GAP-279` records for `MerchantPermissions`' NULL-store arm. A planted row satisfies
 * `product_id = <B's product>`, so it is served on B's product-detail page to B's own shoppers.
 *
 * `Testimonial::AvregeRating()` has the same unpredicated shape (`Testimonial::where('product_id',
 * $product_id)`) and **writes** `products.average_rating`, so once anyone rates B's product the
 * planted score is folded into B's displayed average.
 *
 * **FIXED AT THE WRITER**, for the same reason `GAP-279` was: the reader's premise is restored where
 * it was broken, and the readers' own docblock argues — persuasively, with a measured precedent
 * about `product_variants.store_id` drifting — that a second predicate on a NULLABLE `store_id`
 * column would be strictly worse. Those two readers are in `ApiController.php`, which this
 * workstream must not edit; they need no change under this fix, and that is stated as a conclusion
 * rather than assumed.
 *
 * @see \App\Http\Controllers\TestimonialController::store()
 * @see \Tests\Feature\Security\Gap279NullStoreRoleWriterTest  the same reader-premise shape
 */
final class Gap193ReviewPurchaseGateAndTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /*
    |--------------------------------------------------------------------------
    | fixture
    |--------------------------------------------------------------------------
    */

    /** @return array{0: User, 1: \App\Models\Store, 2: Category, 3: Product} */
    private function tenantWithAProduct(string $tag): array
    {
        [$merchant, $store] = $this->makeTenant();

        /* NO `created_by`: `categories` has no such column -- measured, "table categories has no
           column named created_by". The migrations are not the schema and a column's existence is
           checked rather than assumed (CLAUDE.md §1). */
        $category = Category::forceCreate([
            'name' => $tag . '-cat', 'slug' => $tag . '-cat-' . uniqid(),
            'store_id' => $store->id,
        ]);

        $product = Product::forceCreate([
            'name' => $tag . '-product', 'slug' => $tag . '-' . uniqid(),
            'price' => 100.00, 'sale_price' => 100.00,
            'product_stock' => 10, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'stock_status' => 'in_stock', 'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        return [User::find($merchant->id), $store, $category, $product];
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

    /** `Create Testimonial` / `Edit Testimonial` — the names the panel writers gate on. */
    private function permit(User $merchant, array $names): void
    {
        foreach ($names as $name) {
            $this->grantPermission($merchant, $name);
        }
        $merchant->flushCache();
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — THE PURCHASE GATE, measured on the real storefront door
    |--------------------------------------------------------------------------
    */

    /**
     * **MEASURED: a shopper who has bought NOTHING publishes a live review.**
     *
     * Driven through `POST api/{slug}/add-review`, the storefront door, with the shopper's own
     * bearer token — the safe `custom.auth` branch a real shopper takes. The premise assertion
     * proves the account has no order at all, because "there is no purchase gate" is satisfied
     * either way if the fixture happens to have bought something.
     *
     * NOT A DEFECT TO FIX HERE: see the class docblock. This case exists so that `CUST-005`'s
     * status is a measurement rather than an inference, and so that the day a gate IS added this
     * test fails and the row is looked at.
     */
    public function test_a_customer_who_has_bought_nothing_can_publish_a_review(): void
    {
        [, $store, $category, $product] = $this->tenantWithAProduct('g193');
        $shopper = $this->shopper($store, 'g193');

        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('customer_id', $shopper->id)->count(),
            'premise: this shopper has never ordered anything. Without this the case is satisfied '
            . 'by a fixture that happens to have bought the product.'
        );

        $token = $shopper->createToken('g193')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $store->slug . '/add-review', [
                'category_id' => $category->id, 'product_id' => $product->id,
                'rating_no' => 5, 'title' => 'Never bought it', 'description' => 'Five stars.',
                'status' => 1,
            ]);

        $this->assertNotSame(404, $response->status(),
            'premise: api/{slug}/add-review exists. A row assertion is satisfied by a 404.');
        $this->assertSame(200, $response->status(), 'premise: the door answered. Body: '
            . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'), sprintf(
            'premise: and it SUCCEEDED -- ApiResponser::error() answers HTTP 200 with status 0, so '
            . 'the status code alone cannot tell a refusal from an acceptance. Body: %s',
            $response->getContent()
        ));

        $row = DB::table('testimonials')->where('product_id', $product->id)->first();

        $this->assertNotNull($row, 'GAP-193: a purchase gate now exists on `add-review` -- a '
            . 'shopper with no order was refused. That is CUST-005 arriving; it is a PRODUCT rule '
            . 'and a Phase 03 scope change, so confirm it was an owner decision, then INVERT this '
            . 'case and update the register.');

        $this->assertSame(5, (int) $row->rating_no);
        $this->assertSame(1, (int) $row->status,
            'and it is PUBLISHED immediately -- `status` is forced to 1 by the handler, so there '
            . 'is no moderation queue between an unverified review and the storefront either.');
    }

    /**
     * AND THERE IS NOWHERE TO RECORD "VERIFIED" EVEN IF SOMEBODY WANTED TO.
     *
     * `CUST-005` asks for *verified* reviews. The table has no column for it, so a badge has
     * nothing to read and a gate has nothing to write. Asserted against the live schema rather
     * than the migration, because CLAUDE.md records that the migrations are not the schema.
     */
    public function test_the_reviews_table_has_no_verified_column_for_cust_005_to_use(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('testimonials');

        $this->assertNotSame([], $columns, 'premise: the table exists and was read.');

        foreach (['verified', 'is_verified', 'verified_purchase', 'order_id'] as $candidate) {
            $this->assertNotContains($candidate, $columns, sprintf(
                '`testimonials.%s` now exists, so CUST-005 has somewhere to record a verified '
                . 'purchase. Update GAP-193 rather than this test.', $candidate
            ));
        }
    }

    /**
     * THE CENSUS: no review writer consults a purchase. Keyed on the handlers, comment-stripped.
     *
     * COMMENT-STRIPPED AND SCOPED, because this file and those handlers both discuss purchases at
     * length — CLAUDE.md §3 records eight occasions where a census was satisfied or broken by prose
     * about the thing it measures. The needles are the tables and relations a purchase gate would
     * have to touch.
     */
    public function test_no_review_writer_consults_an_order(): void
    {
        $writers = [
            'app/Http/Controllers/TestimonialController.php',
            'app/Http/Controllers/Api/ApiController.php',
            'app/Http/Controllers/Api/DashboardController.php',
        ];

        foreach ($writers as $path) {
            $source = $this->sourceWithoutComments($path);

            /* REACHABILITY: a stripper that returned '' would make every assertion below pass. */
            $this->assertStringContainsString('Testimonial', $source, sprintf(
                'premise: %s still writes or reads the reviews table, so this census is looking at '
                . 'the right file.', basename($path)
            ));
        }

        /*
         * The writers are read as BLOCKS, not whole files: `ApiController` and
         * `DashboardController` are thousands of lines and both legitimately mention orders
         * elsewhere, so a whole-file search would answer "does this file mention orders" instead of
         * "does the review writer check one".
         */
        $blocks = [
            'TestimonialController::store' => $this->methodBody(
                'app/Http/Controllers/TestimonialController.php', 'store'),
            'ApiController::add_review' => $this->methodBody(
                'app/Http/Controllers/Api/ApiController.php', 'add_review'),
            'ApiController::product_rating' => $this->methodBody(
                'app/Http/Controllers/Api/ApiController.php', 'product_rating'),
            'DashboardController::AddReview' => $this->methodBody(
                'app/Http/Controllers/Api/DashboardController.php', 'AddReview'),
        ];

        foreach ($blocks as $name => $body) {
            $this->assertNotSame('', trim($body), "premise: {$name}'s body was located and is not "
                . 'empty. A census over an empty string passes vacuously.');
            $this->assertStringContainsString('Testimonial', $body,
                "premise: {$name} really is a review writer.");

            foreach (['Order::', 'order_details', 'product_json', 'hasPurchased', 'OrderDetail'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, sprintf(
                    'GAP-193: %s now consults `%s`, so a purchase gate exists where the register '
                    . 'records none. That is CUST-005 arriving -- a product rule and a Phase 03 '
                    . 'scope change. Update the register rather than this census.',
                    $name, $needle
                ));
            }
        }
    }

    /**
     * One method body, brace-matched from its declaration.
     *
     * BRACE-MATCHED AND NOT "DECLARATION TO END OF FILE", which is the mistake `GAP-277`'s seeder
     * census made and had corrected in review: reading to the end of a 3,400-line file makes the
     * census answer a question about the whole file.
     */
    private function methodBody(string $relativePath, string $method): string
    {
        $source = $this->sourceWithoutComments($relativePath);
        $at = strpos($source, 'function ' . $method . '(');

        if ($at === false) {
            return '';
        }

        $open = strpos($source, '{', $at);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — THE TENANCY DEFECT: a review of ANOTHER store's product
    |--------------------------------------------------------------------------
    */

    /**
     * **THE FINDING: a merchant writes a review onto a COMPETITOR'S product, and it is served on
     * that competitor's product-detail page.**
     *
     * `POST /testimonial` as merchant A, with merchant B's `product_id` and `category_id`. The row
     * is stamped `store_id = A` (correct) while naming B's product — and
     * `ApiController::product_detail()` reads reviews by `product_id` ALONE, resting on the product
     * being store-verified, which makes the planted row B's.
     *
     * The assertion is on the ROW, written so it states the answer either way, and the sibling case
     * below drives the read so the consequence is measured rather than argued.
     */
    public function test_a_merchant_cannot_write_a_review_onto_another_stores_product(): void
    {
        [$attacker, , , ] = $this->tenantWithAProduct('g193-a');
        [, $victimStore, $victimCategory, $victimProduct] = $this->tenantWithAProduct('g193-b');

        $this->permit($attacker, ['Create Testimonial']);

        $this->assertNotSame((int) $attacker->current_store, (int) $victimStore->id,
            'premise: two different tenants.');
        $this->assertTrue($attacker->fresh()->isAbleTo('Create Testimonial'),
            'premise: the attacker holds the permission the writer gates on, so a refusal below is '
            . 'the STORE check and not the permission one.');

        $response = $this->actingAs($attacker)->post('/testimonial', [
            'category_id' => $victimCategory->id,
            'product_id' => $victimProduct->id,
            'rating_no' => 1,
            'title' => 'Planted by a competitor',
            'description' => 'This product is terrible.',
            'status' => 1,
        ]);

        $this->assertNotSame(404, $response->status(),
            'premise: POST /testimonial is a real route. A row assertion is satisfied by a 404.');

        /* `withoutGlobalScopes()` as a matter of discipline: `Testimonial` carries no scope today,
           but an absence assertion that relies on that is one config change from being vacuous. */
        $planted = Testimonial::withoutGlobalScopes()
            ->where('product_id', $victimProduct->id)->first();

        $this->assertNull($planted, sprintf(
            'GAP-193 TENANCY DEFECT REPRODUCES: merchant A wrote a review naming store B\'s '
            . 'product. The row is store_id=%s, product_id=%s (B\'s product, store %s), and '
            . '`ApiController::product_detail():5834` reads reviews by product_id alone -- its own '
            . 'docblock says the reviews are safe because they are "dependent lookups on a '
            . 'store-verified parent", a premise this writer breaks. The remedy is at the WRITER: '
            . 'scope `product_id` to the caller\'s store, as the three API writers already do.',
            var_export($planted->store_id ?? null, true),
            var_export($planted->product_id ?? null, true),
            var_export($victimProduct->store_id, true)
        ));
    }

    /**
     * THE CONSEQUENCE, DRIVEN: does a planted row actually reach the victim's product page?
     *
     * This case plants the row DIRECTLY (`forceCreate`), not through the route, and that is
     * deliberate — it measures the READER, which this workstream must not change, and it must keep
     * measuring it after the writer is fixed. Without this, "the writer refuses" would be the only
     * evidence and the reader's exposure would be an argument rather than a measurement.
     *
     * It is therefore a **known-bad pin on the reader**: it asserts that a planted row IS served,
     * because that is true today and is the reason the writer fix matters. If
     * `ApiController::product_detail()` ever gains its own `store_id` predicate this case fails,
     * which is the right outcome — invert it and record that the defence is now doubled.
     */
    public function test_known_bad_a_planted_review_is_served_on_the_victims_product_page(): void
    {
        [$attacker, $attackerStore, , ] = $this->tenantWithAProduct('g193-c');
        [, $victimStore, $victimCategory, $victimProduct] = $this->tenantWithAProduct('g193-d');

        $planted = Testimonial::forceCreate([
            'category_id' => $victimCategory->id,
            'product_id' => $victimProduct->id,
            'rating_no' => 1,
            'title' => 'Planted directly',
            'description' => 'Served on the wrong storefront.',
            'status' => 1,
            'store_id' => $attackerStore->id,
        ]);
        (new Testimonial)->flushCache();

        $this->assertSame((int) $attackerStore->id, (int) $planted->store_id,
            'premise: the row belongs to the ATTACKER\'S store and names the VICTIM\'S product -- '
            . 'the exact shape the panel writer could produce.');

        $victimShopper = $this->shopper($victimStore, 'g193-d');
        $token = $victimShopper->createToken('g193')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/' . $victimStore->slug . '/product-detail', [
                'id' => $victimProduct->id, 'product_id' => $victimProduct->id,
            ]);

        $this->assertNotSame(404, $response->status(), 'premise: product-detail exists.');
        $this->assertSame(200, $response->status(), 'premise: it answered. Body: '
            . substr((string) $response->getContent(), 0, 400));

        $reviews = (array) ($response->json('data.product_Review') ?? []);
        $titles = array_column($reviews, 'title');

        $this->assertContains('Planted directly', $titles, sprintf(
            'THE READER IS NOW CONFINED: a review row belonging to another store no longer '
            . 'appears on this store\'s product-detail response. That is a second line of defence '
            . 'arriving in `ApiController::product_detail()` -- an improvement. INVERT this case '
            . 'and record it. Reviews seen: %s', json_encode($titles)
        ));
    }

    /**
     * THE CONTROL: the writer must still work for the merchant's OWN product.
     *
     * A guard that refuses every `product_id` passes the finding case above and breaks the
     * testimonial form — which this project treats as the defect and not the fix.
     */
    public function test_the_control_a_merchant_can_still_review_their_own_product(): void
    {
        [$merchant, $store, $category, $product] = $this->tenantWithAProduct('g193-own');
        $this->permit($merchant, ['Create Testimonial']);

        $response = $this->actingAs($merchant)->post('/testimonial', [
            'category_id' => $category->id,
            'product_id' => $product->id,
            'rating_no' => 4,
            'title' => 'Our own product',
            'description' => 'This is fine.',
            'status' => 1,
        ]);

        $this->assertNotSame(404, $response->status());

        $row = Testimonial::withoutGlobalScopes()->where('product_id', $product->id)->first();

        $this->assertNotNull($row, sprintf(
            'control: a merchant must still be able to write a testimonial for their OWN product. '
            . 'Flashed error: %s', var_export(session('error'), true)
        ));
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame((int) $category->id, (int) $row->category_id,
            'control: and the category is kept, not blanked by an over-eager guard.');
    }

    /**
     * THE PRODUCT ARM AT THE `store()` DOOR, PINNED ON ITS OWN -- and a surviving mutant is why.
     *
     * The headline `store()` case posts a foreign product AND a foreign category, so the CATEGORY
     * arm alone satisfies it: deleting the `product_id` arm SURVIVED it, measured. The `update()`
     * door was already pinned independently because its case keeps the attacker's own category --
     * `store()` needed the same shape. This posts the caller's OWN category with the victim's
     * product, which is also the more realistic tamper: the category dropdown is populated from the
     * caller's own store (`TestimonialController::get_product()` is scoped), so `product_id` is the
     * field an attacker actually has to forge.
     */
    public function test_a_merchant_cannot_attach_a_review_to_another_stores_product_under_their_own_category(): void
    {
        [$attacker, $attackerStore, $ownCategory, ] = $this->tenantWithAProduct('g193-k');
        [, , , $victimProduct] = $this->tenantWithAProduct('g193-l');

        $this->permit($attacker, ['Create Testimonial']);

        $this->assertSame((int) $attackerStore->id, (int) $ownCategory->store_id,
            'premise: the category is the CALLER\'S, so only the product arm can refuse this.');
        $this->assertNotSame((int) $attackerStore->id, (int) $victimProduct->store_id,
            'premise: and the product is the other tenant\'s.');

        $this->actingAs($attacker)->post('/testimonial', [
            'category_id' => $ownCategory->id,
            'product_id' => $victimProduct->id,
            'rating_no' => 1, 'title' => 'Foreign product only', 'description' => 'x', 'status' => 1,
        ]);

        $this->assertNull(
            Testimonial::withoutGlobalScopes()->where('product_id', $victimProduct->id)->first(),
            'GAP-193 PRODUCT ARM at `store()`: a review was attached to another store\'s product '
            . 'while naming the caller\'s own category, so the category arm could not refuse it. '
            . 'This is the row `ApiController::product_detail()` would serve on the victim\'s page.'
        );
    }

    /**
     * THE CATEGORY ARM, PINNED ON ITS OWN -- and a mutation is why this case exists.
     *
     * The two finding cases above post a foreign PRODUCT *and* a foreign CATEGORY together, so
     * either arm of the guard alone satisfies them: dropping the `category_id` check survived them
     * both, measured. `testimonials.category_id` carries a real foreign key to `categories`
     * (`2023_12_26_095726_create_testimonials_table.php:27`) and `Testimonial::MainCategoryData()`
     * joins on it, so a row filed under another tenant's category is a cross-tenant reference in its
     * own right. This case posts a foreign category with THIS store's own product, so only the
     * category arm can refuse it.
     */
    public function test_a_merchant_cannot_file_a_review_under_another_stores_category(): void
    {
        [$attacker, $attackerStore, , $ownProduct] = $this->tenantWithAProduct('g193-h');
        [, , $victimCategory, ] = $this->tenantWithAProduct('g193-i');

        $this->permit($attacker, ['Create Testimonial']);

        $this->assertNotSame((int) $attackerStore->id, (int) $victimCategory->store_id,
            'premise: the category belongs to the OTHER tenant.');

        $this->actingAs($attacker)->post('/testimonial', [
            'category_id' => $victimCategory->id,
            'product_id' => $ownProduct->id,          // OUR product, so only the category is foreign
            'rating_no' => 3, 'title' => 'Foreign category', 'description' => 'x', 'status' => 1,
        ]);

        $this->assertNull(
            Testimonial::withoutGlobalScopes()->where('category_id', $victimCategory->id)->first(),
            'GAP-193 CATEGORY ARM: a review was filed under another store\'s category. '
            . '`testimonials.category_id` has a real FK to `categories` and '
            . '`Testimonial::MainCategoryData()` joins on it, so this is a cross-tenant reference '
            . 'even when the product is ours.'
        );
    }

    /**
     * THE CONTROL THAT STOPS THE GUARD OVERREACHING: a testimonial naming NO product still saves.
     *
     * `product_id` and `category_id` are both commented OUT of both validators
     * (`TestimonialController::store()`, `::update()`), so a general store testimonial with neither
     * is an ordinary save that the form supports. A guard that required them -- or that treated an
     * empty id as foreign -- would deny a working journey, which this project treats as the defect
     * and not the fix. Measured: without this case, deleting the `empty($posted)` short-circuit
     * survives every other case in this file.
     */
    public function test_the_control_a_testimonial_naming_no_product_still_saves(): void
    {
        [$merchant, $store, , ] = $this->tenantWithAProduct('g193-j');
        $this->permit($merchant, ['Create Testimonial']);

        $this->actingAs($merchant)->post('/testimonial', [
            'rating_no' => 5, 'title' => 'About the shop', 'description' => 'Great service.',
            'status' => 1, 'username' => 'A shopper',
        ]);

        $row = Testimonial::withoutGlobalScopes()->where('title', 'About the shop')->first();

        $this->assertNotNull($row, sprintf(
            'control: a testimonial with no product and no category must still save -- both fields '
            . 'are optional in the validator and a general store testimonial is a supported '
            . 'journey. Flashed: %s', var_export(session('error'), true)
        ));
        $this->assertSame((int) $store->id, (int) $row->store_id);
    }

    /**
     * THE SIBLING DOOR: `update()` writes `product_id` too.
     *
     * `update()` has an ownership guard on the ROW (`callerOwnsTestimonial`), so a merchant can only
     * edit their own review — but it then writes `$Testimonial->product_id = $request->product_id`
     * with no store check, so a merchant can take their OWN review and REPOINT it at a competitor's
     * product. Same injection, different door, and the row-ownership guard cannot see it because
     * the row really is theirs.
     */
    public function test_a_merchant_cannot_repoint_their_own_review_at_another_stores_product(): void
    {
        [$attacker, $attackerStore, $attackerCategory, $attackerProduct] = $this->tenantWithAProduct('g193-e');
        [, , , $victimProduct] = $this->tenantWithAProduct('g193-f');

        $this->permit($attacker, ['Edit Testimonial']);

        $own = Testimonial::forceCreate([
            'category_id' => $attackerCategory->id, 'product_id' => $attackerProduct->id,
            'rating_no' => 5, 'title' => 'Mine', 'description' => 'Mine.',
            'status' => 1, 'store_id' => $attackerStore->id,
        ]);
        (new Testimonial)->flushCache();

        $this->assertTrue($attacker->fresh()->isAbleTo('Edit Testimonial'),
            'premise: the attacker holds `Edit Testimonial`, and the row is their own, so '
            . '`callerOwnsTestimonial()` admits them -- which is correct and is why the row guard '
            . 'cannot close this.');

        $this->actingAs($attacker)->put('/testimonial/' . $own->id, [
            'category_id' => $attackerCategory->id,
            'product_id' => $victimProduct->id,
            'rating_no' => 1,
            'title' => 'Repointed',
            'description' => 'Now on their product.',
            'status' => 1,
        ]);

        $this->assertSame(
            (int) $attackerProduct->id,
            (int) Testimonial::withoutGlobalScopes()->findOrFail($own->id)->product_id,
            'GAP-193 AT THE SIBLING DOOR: `update()` repointed a review at another store\'s '
            . 'product. The row-ownership guard admits this by design -- the row IS the caller\'s '
            . '-- so the product id needs its own store predicate, exactly as in `store()`.'
        );
    }

    /**
     * THE CONTROL FOR THAT DOOR: an ordinary edit of one's own review must still work.
     */
    public function test_the_control_a_merchant_can_still_edit_their_own_review(): void
    {
        [$merchant, $store, $category, $product] = $this->tenantWithAProduct('g193-g');
        $this->permit($merchant, ['Edit Testimonial']);

        $own = Testimonial::forceCreate([
            'category_id' => $category->id, 'product_id' => $product->id,
            'rating_no' => 5, 'title' => 'Before', 'description' => 'Before.',
            'status' => 1, 'store_id' => $store->id,
        ]);
        (new Testimonial)->flushCache();

        $this->actingAs($merchant)->put('/testimonial/' . $own->id, [
            'category_id' => $category->id,
            'product_id' => $product->id,
            'rating_no' => 3,
            'title' => 'After',
            'description' => 'After.',
            'status' => 1,
        ]);

        $fresh = Testimonial::withoutGlobalScopes()->findOrFail($own->id);

        $this->assertSame('After', (string) $fresh->title, sprintf(
            'control: an ordinary edit of the merchant\'s own review must still land. Flashed: %s',
            var_export(session('error'), true)
        ));
        $this->assertSame((int) $product->id, (int) $fresh->product_id,
            'control: and the product it names is unchanged.');
    }
}
