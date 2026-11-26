<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\StripsSourceComments;

/**
 * GAP-244 / `P03-F117` — WHAT THE STOREFRONT PRODUCT PAYLOAD ACTUALLY CONTAINS. A MEASUREMENT.
 * =================================================================================================
 *
 * THE ROW'S OWN POINT: `GAP-233` added `where('store_id', $store->id)` to the product lookup in
 * `product-detail` / `product-detail-guest` and closed the CROSS-TENANT read — and the row records
 * that the disclosure survives it, because **`$store` comes from the route slug**. The slice's own
 * helper states the rule it then relies on: *"a `where('store_id', $store->id)` built from the slug
 * is not a tenant check"*. So the predicate makes the handler self-consistent; it does not decide
 * who may ask.
 *
 * AND THE ROW'S CONFIDENCE NOTE IS THE GAP THIS FILE FILLS, verbatim: *"`cost_price` presence read
 * from the migration, not measured on a response."* Everything below is measured on a real response
 * from a real route.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A PREDICATE AT THE CALLER WAS NEVER GOING TO BE ENOUGH
 * -------------------------------------------------------------------------------------------------
 * `Product` and `Category` carry `StoreScope` through `BelongsToStore` and are **absent from
 * `config/tenancy.php`'s `scoped_models`** — measured here, not quoted — so `StoreScope::isActiveFor()`
 * short-circuits and the scope adds NO predicate for any principal. `getGlobalScopes()` still answers
 * `[StoreScope]` for both, which is why checking *that* asks the wrong question.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE PRINCIPALS, AND THE SHARPEST ONE IS NOT THE SHOPPER
 * -------------------------------------------------------------------------------------------------
 * `custom.auth` logs a token's owner into whichever guard matches the TOKENABLE CLASS, so a
 * merchant's and a driver's token pass it with `auth('customers')` null. A customer-token probe
 * therefore cannot establish the reach of this surface. All three are driven, and the case that
 * matters commercially is **a merchant of store B reading store A's `cost_price`** — a competitor's
 * margin, reachable with a legitimately obtained token and a guessed slug.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES **NOT** DO, AND WHY
 * -------------------------------------------------------------------------------------------------
 * It changes no payload. The row reserves that to the owner — *"a contract change for a
 * shipped-app-adjacent surface, so it needs the owner's call on the payload, not an engineering
 * guess"* — and `StorefrontProductDetailTenancyTest` already says the same in its own words, that
 * narrowing the same-store payload during a tenancy fix would be *"indistinguishable from a
 * regression"*. CLAUDE.md §2 reserves public API compatibility to the owner outright.
 *
 * So this file PINS the measured behaviour, states the figures the decision needs, and records two
 * corrections to the remedy the row proposes — both of which make the proposed fix worse than it
 * sounds, and neither of which is visible without measuring. See the two `known_bad` cases and
 * `test_what_the_owner_decision_needs_to_know`.
 *
 * **WHEN THE OWNER DECIDES, INVERT THE `known_bad` CASES. DO NOT RELAX THEM.**
 *
 * @see \Tests\Feature\Security\StorefrontProductDetailTenancyTest  the GAP-233 tenancy slice
 */
final class Gap244StorefrontProductPayloadTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** A figure no fixture would produce by accident, so finding it in a payload is unambiguous. */
    private const COST_PRICE = '42.42';

    /*
    |--------------------------------------------------------------------------
    | fixture — the GAP-233 shape, which is already proven to drive these routes
    |--------------------------------------------------------------------------
    */

    private function productIn($store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap244-' . uniqid(),
            'description' => 'x',
            'detail' => 'GAP244-INTERNAL-DETAIL',
            'product_stock' => 7,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => self::COST_PRICE,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /** @return array<string,string> label => bearer token, all three tokenable classes */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gap244-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gap244-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        return [
            'customer' => $customer->createToken('t')->plainTextToken,
            'merchant' => $merchant->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ];
    }

    private function detail($store, string $path, $productId, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, ['id' => $productId]);
    }

    /** @return array<string,array<int,string>> both twins, so neither can be narrowed alone */
    public static function bothRoutes(): array
    {
        return [
            'authenticated' => ['product-detail'],
            'guest' => ['product-detail-guest'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | premises
    |--------------------------------------------------------------------------
    */

    /**
     * THE PREMISE THE ROW RESTS ON, MEASURED RATHER THAN QUOTED: neither model is scoped.
     *
     * `getGlobalScopes()` is the wrong question and this asserts the right one — membership of
     * `config/tenancy.php`'s `scoped_models`, which is what `StoreScope::isActiveFor()` reads.
     */
    public function test_premise_product_and_category_are_not_in_scoped_models(): void
    {
        $scoped = (array) config('tenancy.scoped_models');

        $this->assertNotSame([], $scoped, 'premise: the allowlist was read at all.');

        $this->assertNotContains(Product::class, $scoped,
            '`Product` is now in `scoped_models`, so `StoreScope` filters it and the premise of '
            . 'this whole file has changed. Re-measure GAP-244 rather than relaxing it.');
        $this->assertNotContains(\App\Models\Category::class, $scoped,
            '`Category` is now in `scoped_models`.');

        /* AND THE SCOPE IS STILL REGISTERED, which is the trap: checking `getGlobalScopes()` says
           "scoped" while the scope adds no predicate. Both halves asserted so the distinction
           cannot quietly collapse. */
        $this->assertArrayHasKey(
            \App\Scopes\StoreScope::class,
            (new Product)->getGlobalScopes(),
            'premise: `Product` still CARRIES StoreScope while being absent from the allowlist -- '
            . 'the pair of facts that makes `getGlobalScopes()` the wrong question.'
        );
    }

    public function test_premise_the_product_model_declares_no_hidden_attributes(): void
    {
        $this->assertSame([], (new Product)->getHidden(),
            '`Product` now hides attributes, so `toArray()` no longer emits every column. That is '
            . 'the GAP-244 remedy arriving -- confirm it was an owner decision, then INVERT the '
            . '`known_bad` cases in this file.');

        $this->assertTrue(Schema::hasColumn('products', 'cost_price'),
            'premise: the column exists on this schema, so a payload that omits it would be '
            . 'omitting something real.');
    }

    /*
    |--------------------------------------------------------------------------
    | THE MEASUREMENT the row asked for
    |--------------------------------------------------------------------------
    */

    /**
     * **KNOWN BAD, AND THE ROW'S UNMEASURED CLAIM NOW MEASURED: `products.cost_price` — the
     * merchant's margin — is served in the storefront payload, to all three token classes, for a
     * store the caller has nothing to do with.**
     *
     * The row rated this *"certain on the code path; `cost_price` presence read from the migration,
     * not measured on a response."* It is now read off a real response from a real route, with a
     * value, for a customer, a merchant and a driver of a DIFFERENT store.
     *
     * THE COMMERCIALLY SHARP CASE IS THE MERCHANT, not the shopper: store B's owner holds a
     * legitimate token, needs only store A's slug — which is public, it is in A's storefront URL —
     * and reads A's unit cost on every product. That is a competitor's margin.
     *
     * @dataProvider bothRoutes
     */
    public function test_known_bad_cost_price_is_served_to_any_token_holder_of_any_store(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $productA = $this->productIn($storeA, 'GAP244-A-PRODUCT');

        /* A SECOND, UNRELATED TENANT, whose tokens are the ones driven. The caller is not a
           customer of store A, not its staff, and not its driver. */
        [$merchantB, $storeB] = $this->makeTenant();

        foreach ($this->threeTokensFor($merchantB, $storeB) as $label => $bearer) {
            $response = $this->detail($storeA, $path, $productA->id, $bearer);

            $this->assertNotSame(404, $response->status(),
                "premise: {$path} exists. A payload assertion is satisfied by a 404.");
            $this->assertSame(200, $response->status(), sprintf(
                'premise: %s answered 200 for a %s token of ANOTHER store -- which is itself the '
                . 'reach being measured. Body: %s', $path, $label,
                substr((string) $response->getContent(), 0, 300)
            ));

            $info = (array) data_get($response->json(), 'data.product_info', []);

            $this->assertNotSame([], $info, sprintf(
                'premise: %s returned a product_info block for a %s token. Body: %s',
                $path, $label, substr((string) $response->getContent(), 0, 300)
            ));

            $this->assertArrayHasKey('cost_price', $info, sprintf(
                'GAP-244 IS FIXED for %s on %s: `cost_price` is no longer in the storefront '
                . 'payload. Confirm the owner approved the contract change, then INVERT this case. '
                . 'Keys present: %s', $label, $path, implode(', ', array_keys($info))
            ));

            $this->assertSame(self::COST_PRICE, (string) $info['cost_price'], sprintf(
                'GAP-244, MEASURED ON A RESPONSE: %s served store A\'s unit cost (%s) to a %s '
                . 'token belonging to store B. The row had this from the migration only.',
                $path, self::COST_PRICE, $label
            ));
        }
    }

    /**
     * **KNOWN BAD: the reviewer e-mail in the payload is READ FROM THE WRONG TABLE, and the row's
     * proposed remedy would make the disclosure WORSE rather than better.**
     *
     * Driven through the REAL WRITER and then the REAL READER, because the mis-join is only visible
     * when both halves are the shipped code:
     *
     *   `POST api/{slug}/product-rating` stores `$review->user_id = $customer->id` -- a **customers**
     *   id (`ApiController::product_rating`, and `::add_review` does the same).
     *   `Testimonial::UserData()` is `hasOne(User::class, 'id', 'user_id')` -- it joins **`users`**.
     *
     * So the address printed as `user_email` is whatever `users` row happens to carry that id. It is
     * not the reviewer's. This case seeds a `User` whose id collides with the reviewing customer's
     * and asserts that the response carries the **USER's** address and NOT the customer's own.
     *
     * **THE CORRECTION TO THE ROW'S REMEDY, which is why this is measured rather than assumed.** The
     * row offers *"either drop `user_email` or source it from the `customers` row the writer actually
     * references"*. The second option is NOT the safer one: sourcing it from `customers` would print
     * **the shopper's own e-mail address on a public product page**, which is worse for the data
     * subject than printing a merchant's. Dropping the field is the only option of the two that
     * reduces disclosure. Recorded for the owner's decision rather than chosen here.
     */
    public function test_known_bad_the_reviewer_email_comes_from_the_wrong_table(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 'GAP244-REVIEWED');

        $shopper = Customer::forceCreate([
            'first_name' => 'Gap244', 'last_name' => 'Shopper',
            'email' => 'gap244-THE-REVIEWERS-OWN-ADDRESS@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        /*
         * NO ID IS FORCED, AND THE FIRST DRAFT OF THIS CASE TRIED TO. It created a spare `users`
         * row and moved its id onto the shopper's -- which failed with
         * `UNIQUE constraint failed: users.id`, because **the collision already exists**: `users`
         * and `customers` are separate id sequences starting at 1, so the first shopper of the
         * first store has `customers.id = 1` and `users.id = 1` is that store's own merchant. The
         * contrivance was not only unnecessary, it was impossible -- and the measurement is
         * STRONGER without it, because it shows the mis-join is the ORDINARY case rather than a
         * constructed one.
         */
        $shopperToken = $shopper->createToken('t')->plainTextToken;

        /* THE REAL WRITER. It derives `user_id` from the authenticated principal -- a Customer -- so
           the stored value is a customers id by construction, not by fixture choice. */
        $write = $this->withHeaders(['Authorization' => 'Bearer ' . $shopperToken])
            ->postJson('/api/' . $store->slug . '/product-rating', [
                'id' => $product->id, 'user_id' => $shopper->id,
                'rating_no' => 5, 'title' => 'GAP244 Title', 'description' => 'GAP244 Body',
            ]);

        $this->assertSame(1, (int) $write->json('status'), sprintf(
            'premise: the real writer accepted the review. Body: %s', $write->getContent()));

        $stored = Testimonial::withoutGlobalScopes()->where('product_id', $product->id)->firstOrFail();

        $this->assertSame((int) $shopper->id, (int) $stored->user_id,
            'premise: the writer stored the CUSTOMERS id in `testimonials.user_id`. That is the '
            . 'whole mechanism -- the reader then joins `users` on it.');

        /* THE COLLIDING `users` ROW, read rather than created: whoever happens to hold that id. */
        $collidingUser = User::withoutGlobalScopes()->find($stored->user_id);

        $this->assertNotNull($collidingUser, sprintf(
            'premise: a `users` row really holds id %s, so the mis-join has something to find. '
            . 'Without one the reader answers an empty string and this case measures nothing.',
            var_export($stored->user_id, true)
        ));
        $this->assertNotSame(
            (string) $shopper->email,
            (string) $collidingUser->email,
            'premise: that users row is NOT the shopper -- different tables, different people.'
        );

        $response = $this->detail($store, 'product-detail', $product->id, $shopperToken);

        $this->assertSame(200, $response->status(), 'premise: the reader answered.');

        $reviews = (array) data_get($response->json(), 'data.product_Review', []);
        $emails = array_filter(array_column($reviews, 'user_email'));

        $this->assertContains(
            (string) $collidingUser->email,
            $emails,
            sprintf('GAP-244 THE MIS-JOIN IS FIXED: `user_email` no longer comes from `users` keyed '
                . 'on a customers id. Confirm which table it now reads and whether that was an '
                . 'owner decision, then INVERT this case -- and note that sourcing it from '
                . '`customers` publishes the SHOPPER\'s address, which is not an improvement. '
                . 'Emails seen: %s', json_encode(array_values($emails)))
        );

        $this->assertNotContains(
            'gap244-THE-REVIEWERS-OWN-ADDRESS@example.test',
            $emails,
            'the payload now carries the REVIEWING CUSTOMER\'s own address. That is the row\'s '
            . '"source it from the customers row" option, and it publishes a shopper\'s e-mail on '
            . 'a public product page -- a WIDER disclosure than the one GAP-244 records. It needs '
            . 'the owner\'s decision, not a silent change.'
        );
    }

    /**
     * AND THE COLUMN DEFAULT POINTS AT USER 1, WHICH IS THE PLATFORM OPERATOR.
     *
     * `testimonials.user_id` is `integer('user_id')->default(1)`
     * (`2024_03_19_104643_add_user_id_column_in_testimonials_table.php`), so any writer that omits
     * it -- a seeder, an importer, a partial write -- produces a review attributed to **user id 1**,
     * and in this product user 1 is the platform operator. Measured end to end: the operator's
     * account address is served in a public storefront payload.
     */
    public function test_known_bad_a_default_user_id_discloses_the_platform_operators_address(): void
    {
        /* FIRST, so it takes id 1 -- which is the column default and, in this product, the
           operator. Asserted rather than assumed. */
        $operator = $this->makePlatformAdmin(['email' => 'gap244-THE-OPERATOR@example.test']);

        $this->assertSame(1, (int) $operator->id,
            'premise: the operator is user id 1, which is what `testimonials.user_id` defaults to. '
            . 'Without this the case measures nothing about the default.');

        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 'GAP244-DEFAULTED-REVIEW');

        /* `user_id` OMITTED, so the column default applies. That is the state under measurement. */
        Testimonial::forceCreate([
            'product_id' => $product->id,
            'category_id' => null,
            'username' => 'Anonymous',
            'title' => 'GAP244 Defaulted',
            'description' => 'GAP244 Defaulted Body',
            'rating_no' => 4,
            'status' => 1,
            'store_id' => $store->id,
        ]);
        (new Testimonial)->flushCache();

        $stored = Testimonial::withoutGlobalScopes()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(1, (int) $stored->user_id,
            'premise: omitting `user_id` really does store 1.');

        [$merchantB, $storeB] = $this->makeTenant();
        $bearer = $merchantB->createToken('t')->plainTextToken;

        $response = $this->detail($store, 'product-detail', $product->id, $bearer);

        $this->assertSame(200, $response->status(), 'premise: the reader answered.');

        $emails = array_filter(array_column(
            (array) data_get($response->json(), 'data.product_Review', []), 'user_email'
        ));

        $this->assertContains('gap244-THE-OPERATOR@example.test', $emails, sprintf(
            'GAP-244 IS FIXED for the defaulted case: the platform operator\'s address is no '
            . 'longer served for a review with a defaulted `user_id`. INVERT this case and record '
            . 'what changed -- the column default, the join, or the field. Emails seen: %s',
            json_encode(array_values($emails))
        ));
    }

    /**
     * THE "NOT A SECOND SITE" CLAIM, ASSERTED RATHER THAN LEFT IN PROSE.
     *
     * `Product::ProductPrice()` performs its own unscoped `Product::find($productId)`, so a
     * predicate at a caller does not close it -- a real pattern, established elsewhere in this
     * project. It does not apply to THIS surface, and that is worth a test rather than a sentence:
     * if somebody later prices the detail payload through `ProductPrice()`, the unscoped `find()`
     * arrives on an endpoint any token holder can reach with any slug, and this case fires.
     *
     * COMMENT-STRIPPED AND BRACE-MATCHED TO EACH HANDLER BODY. Stripped because this file and that
     * handler both discuss `ProductPrice()` at length -- CLAUDE.md §3 records eight occasions where
     * a census was satisfied or broken by prose about the thing it measures. Brace-matched because
     * reading from a declaration to the end of an 11,000-line controller answers a question about
     * the whole file, which is the mistake `GAP-277`'s seeder census made.
     */
    public function test_neither_detail_handler_prices_through_the_unscoped_product_price_helper(): void
    {
        foreach (['product_detail', 'product_detail_guest'] as $method) {
            $body = $this->methodBody('app/Http/Controllers/Api/ApiController.php', $method);

            $this->assertNotSame('', trim($body), sprintf(
                'premise: %s()\'s body was located and is not empty. A census over an empty '
                . 'string passes vacuously.', $method
            ));
            $this->assertStringContainsString('product_Review', $body, sprintf(
                'premise: %s() really is the handler this file measures.', $method
            ));

            $this->assertStringNotContainsString('ProductPrice(', $body, sprintf(
                '%s() now prices through `Product::ProductPrice()`, whose own '
                . '`Product::find($productId)` is UNSCOPED -- so the store predicate GAP-233 added '
                . 'to this handler no longer bounds what it prices. Re-measure GAP-244.', $method
            ));
        }
    }

    /**
     * One method body, brace-matched from its declaration, with comments stripped first.
     *
     * The same helper `Gap193ReviewPurchaseGateAndTenancyTest` uses, for the same reasons.
     */
    private function methodBody(string $relativePath, string $method): string
    {
        $stripped = $this->sourceWithoutComments($relativePath);

        $at = strpos($stripped, 'function ' . $method . '(');

        if ($at === false) {
            return '';
        }

        $open = strpos($stripped, '{', $at);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($stripped);

        for ($i = $open; $i < $length; $i++) {
            if ($stripped[$i] === '{') {
                $depth++;
            } elseif ($stripped[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($stripped, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }

    /*
    |--------------------------------------------------------------------------
    | what the owner's decision needs, stated as figures rather than prose
    |--------------------------------------------------------------------------
    */

    /**
     * THE FIELD LIST, MEASURED, so the decision is made against what the endpoint really emits
     * rather than against the migration.
     *
     * -------------------------------------------------------------------------------------------
     * THREE MEASURED CORRECTIONS TO THE REMEDY THE ROW NAMES. None is visible without looking.
     * -------------------------------------------------------------------------------------------
     *
     * **1. `$hidden` ON `Product` IS THE WRONG MECHANISM AND WOULD BREAK THE MERCHANT'S OWN APP.**
     * The row says *"`$hidden` on `Product` for `cost_price` at minimum"*. But `$hidden` applies to
     * EVERY `toArray()` of the model, and the merchant's own admin API deliberately serves this
     * field: `Api\DashboardController::ProductList` assigns
     * `$product->cost_price = $formatNumber($product->getRawOriginal('cost_price'))` on every row
     * of the merchant's product list, and `::ViewProduct` formats it for a caller holding
     * `Manage Products`. Hiding it on the model would empty both. The exposure is per-SURFACE, so
     * the remedy has to be per-surface too.
     *
     * **2. THIS REPOSITORY HAS ALREADY DECIDED THIS EXACT QUESTION, ON THE ADMIN API, AND THE
     * PRECEDENT IS `unset()` AT THE HANDLER.** `ViewProduct` gates on `Manage Products` and, for a
     * caller without it, does `unset($productArray['cost_price'])`. Its docblock records the
     * reasoning in terms this decision needs, and two clauses matter here:
     *
     *     "THE FIELD IS OMITTED RATHER THAN THE REQUEST REFUSED, deliberately. Adding a 403 here
     *      would change who may call `ViewProduct` at all, and the shipped Flutter app calls it --
     *      CLAUDE.md requires its contract to be preserved. Dropping one key narrows the
     *      disclosure without narrowing access, and the client reads `cost_price` with a
     *      null-safe default."
     *
     * So the shipped client is ALREADY KNOWN to tolerate the key's absence, by this project's own
     * prior finding rather than by my guess -- which is the fact that makes the storefront
     * narrowing low-risk, and it is the strongest single input to the decision.
     *
     * **AND THE STOREFRONT CASE HAS NO "WHO MAY SEE IT" AXIS AT ALL.** On the admin API the field
     * is legitimate for the merchant who owns the product, so a permission gate is the right shape.
     * On `product-detail` there is no principal for whom a competitor's unit cost is legitimate:
     * the reach is "any token holder who knows a public slug". That is an argument for omitting it
     * unconditionally here, and it is still the owner's to make, because it changes a shipped
     * payload.
     *
     * **3. "SOURCE `user_email` FROM THE `customers` ROW" IS NOT THE SAFER OPTION.** The row offers
     * dropping the field OR sourcing it from `customers`. The second would print **the shopper's
     * own e-mail on a public product page** -- a WIDER disclosure than the mis-joined account's,
     * and about a data subject who never chose to publish it. Of the two offered options only
     * DROPPING reduces disclosure. Measured in
     * `test_known_bad_the_reviewer_email_comes_from_the_wrong_table()`, which asserts both
     * directions so neither can change silently.
     *
     * -------------------------------------------------------------------------------------------
     * TWO SECOND-SITE HYPOTHESES CHECKED AND CLEAN, recorded so nobody re-checks them
     * -------------------------------------------------------------------------------------------
     *   - `Product::ProductPrice()` does perform its own unscoped `Product::find($productId)`, so a
     *     predicate at a caller does not close it -- but **`product_detail` and its guest twin do
     *     not call it**: measured, zero occurrences in either handler body. Its callers are the
     *     cart readers and two helpers, which pass an already-resolved `$product->id`. Not a
     *     second site on THIS surface.
     *   - `product_image` is read as `ProductImage::where('product_id', $id)` with no store
     *     predicate, which is the same dependent-lookup shape that `GAP-193` broke for reviews.
     *     It is CLOSED at the writer: `ProductImage::saving` runs
     *     `StoredPathPolicy::productImage()`, which derives the permitted folder from
     *     **the PRODUCT's** `store_id`, not the writer's -- so an image naming a foreign product
     *     cannot be stored under the attacker's own folder.
     *
     * This case asserts only that the payload is a superset of the fields the row names. It prints
     * the full key list in its failure message, which is the artefact the owner decision needs, and
     * it will fail the moment the payload is narrowed -- which is the point.
     */
    public function test_what_the_owner_decision_needs_to_know(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store, 'GAP244-FIELD-LIST');

        [$merchantB, $storeB] = $this->makeTenant();
        $bearer = $merchantB->createToken('t')->plainTextToken;

        $info = (array) data_get(
            $this->detail($store, 'product-detail', $product->id, $bearer)->json(),
            'data.product_info', []
        );

        $this->assertNotSame([], $info, 'premise: a payload was returned.');

        /* THE THREE THE ROW NAMES. `detail` is the merchant's internal copy, `created_by` names the
           owning account, and `cost_price` is the margin. */
        foreach (['cost_price', 'created_by', 'detail', 'store_id'] as $field) {
            $this->assertArrayHasKey($field, $info, sprintf(
                '`%s` is no longer emitted. If the payload was narrowed, confirm it was an owner '
                . 'decision and INVERT the known_bad cases. Full key list: %s',
                $field, implode(', ', array_keys($info))
            ));
        }

        /*
         * A FIGURE FOR THE DECISION, not an assertion about a number: how many columns of the
         * merchant's product row the public payload carries. Asserted loosely, because the exact
         * count moves with every migration and pinning it would turn an unrelated schema change
         * into a red test in this file -- the trap CLAUDE.md records for count-based ratchets.
         */
        $this->assertGreaterThan(20, count($info), sprintf(
            'the payload now carries %d product columns (it carried far more when GAP-244 was '
            . 'measured). If that is a deliberate narrowing, this file needs re-measuring. '
            . 'Keys: %s', count($info), implode(', ', array_keys($info))
        ));
    }
}
