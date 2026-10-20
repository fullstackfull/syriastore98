<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-19 / #65 -- REPRODUCTION FIRST.
 *
 * `POST /{storeSlug}/product-bid` writes all four of its columns from request input.
 * `AuctionProductController::addProductbidamount:1600-1603`:
 *
 *     $product_bid->product_id  = $request->product_id  ?? null;
 *     $product_bid->bid_amount  = $request->bid_amount  ?? null;
 *     $product_bid->customer_id = $request->customer_id ?? null;
 *     $product_bid->store_id    = $request->store_id;
 *
 * The authenticated principal is used only for a PRESENCE test at `:1582` and then discarded;
 * `$storeSlug` is used only in the failure-branch redirect. `$product` at `:1585` is assigned and
 * never read.
 *
 * Written against UNFIXED code and expected to FAIL.
 */
/**
 * THE REMOVAL CHECKS, AS MEASURED (scripted; each control deleted ALONE, suite re-run, file
 * restored byte-for-byte). 11 of 13 isolate, and FIVE OF THE ELEVEN ONLY BITE BECAUSE THE CHECK
 * ITSELF DEMANDED A TEST THAT DID NOT EXIST:
 *
 *   R1  the registration ...................... 6 fail
 *   R2  the customer_id derivation ............. 2 fail
 *   R3  the store_id derivation ................ 1 fail
 *   R4  the whole merge() ...................... 2 fail
 *   R5  the hasFile check ...................... 1 fail
 *   R6  the product-exists check ............... 1 fail   <- needed a status assertion
 *   R7  usableId strictness (filter_var) ....... 1 fail   <- needed '5.9' and '1.0'
 *   R9  the greater-than-max rule .............. 1 fail
 *   R10 usableAmount strictness ................ 1 fail
 *   R11 the signed-in-customer limb ............ 1 fail   <- needed an anonymous probe
 *   R12 the action keying ...................... 1 fail   <- needed an inertness test
 *   R8  the amount validation .................. GREEN -- SUBSUMED, and not for the reason I first
 *                                               wrote. See the note in
 *                                               test_an_unusable_bid_amount_is_refused: the merged
 *                                               NULL is rejected by the HANDLER'S own
 *                                               `bid_amount => 'required'` validator, which runs
 *                                               before any write and is engine-independent. I had
 *                                               predicted a NOT NULL / MySQL-coercion mechanism;
 *                                               measurement refuted it.
 *   R13 the product_id derivation .............. GREEN -- NOT ISOLABLE, and stated rather than
 *                                               counted. The handler writes the RAW request value
 *                                               into an `unsignedBigInteger` column, so merging the
 *                                               parsed int removes a guard/handler divergence by
 *                                               construction. But for every value `filter_var`
 *                                               ACCEPTS, the raw string and the parsed int denote
 *                                               the same row on MySQL too -- `'5'`, `'+5'`, and
 *                                               `' 5'` (which global TrimStrings has already
 *                                               trimmed). The values where they differ, `'5.9'` and
 *                                               `'5abc'`, are refused by R7 before the merge is
 *                                               reached. So it is defence in depth against a future
 *                                               weakening of R7, with no reachable case today, and
 *                                               no MySQL script is written for it -- a script for an
 *                                               unreachable path would be evidence for a mechanism
 *                                               that does not operate.
 *
 * All assertions read rows through `DB::table()`. `AuctionProductBid` is `Cachable`
 * (src/app/Models/AuctionProductBid.php:12), and an Eloquent read in a test can serve a mutated
 * in-memory instance -- which has produced a measured false reading in this codebase before.
 */
class AuctionBidForgeryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'AuctionProduct',
            'name' => 'Auction Product',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'auction-product',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AuctionProduct']);

        foreach (glob(base_path('packages/workdo/AuctionProduct/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('auction_product_bids'),
            'auction_product_bids was not created, so every assertion below would be vacuous.');
    }

    /**
     * PREMISE. If the route carried `auth` the exploit would need a different precondition, and if
     * it were not registered at all every "no row was written" assertion would pass for free.
     */
    public function test_premise_the_bid_route_is_registered_and_carries_only_web(): void
    {
        $this->enableModule();

        $route = collect(app('router')->getRoutes())->first(
            fn ($r) => $r->getName() === 'place_bid.store'
        );

        $this->assertNotNull($route, 'place_bid.store is not registered.');

        $middleware = array_map('strval', $route->gatherMiddleware());
        fwrite(STDERR, "\nROUTE  " . implode('|', $route->methods()) . '  ' . $route->uri() . "\n");
        fwrite(STDERR, 'MIDDLEWARE  ' . implode(', ', $middleware) . "\n");

        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('PlanModuleCheck:AuctionProduct', $middleware);
    }

    /**
     * @return array{0: Store, 1: int}  the store and one auction product id in it
     */
    private function storeWithAuctionProduct(string $slug): array
    {
        $owner = $this->makeMerchant();
        $store = $this->makeStore($owner, ['slug' => $slug]);
        $owner->current_store = $store->id;
        $owner->save();

        $productId = (int) DB::table('products')->insertGetId([
            'name' => 'Auction ' . $slug,
            'slug' => 'auction-' . $slug,
            'store_id' => $store->id,
            'created_by' => $owner->id,
            'price' => 100,
            'sale_price' => 100,
            'track_stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$store, $productId];
    }

    private function customerIn(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ]);
    }

    /**
     * THE HEADLINE, AND MY FIRST VERSION OF IT ASSERTED THE WRONG THING.
     *
     * It asserted that NO ROW is written. That encoded this issue's framing, and the framing
     * conflated three different things. Reading the module settled it:
     *
     *   1. ATTRIBUTION FORGERY -- `customer_id` from request input. A real defect, and the severe
     *      one: `Console/Auction.php:73-80` picks the winner with `max('bid_amount')`, then
     *      `->where('bid_amount', $max)->first()` and reads THAT ROW's `customer_id` to create the
     *      order. So a forged bid would have made the VICTIM the auction winner and created an
     *      order in their name. Closed here.
     *   2. CROSS-STORE BIDDING -- the module's DESIGN, not a defect. `customers` is one session
     *      guard, storefronts are public, and `place_bid.blade.php:314` renders
     *      `store_id = $product->store_id` precisely so a bid lands in the product's store.
     *      Nothing anywhere expresses "only customers of this store may bid": every `store_id`
     *      predicate in the controller is on the merchant-admin side via `getCurrentStore()`.
     *      Refusing it would be the mistake P1-INV-20's refund guard shipped and had to withdraw.
     *   3. AN ABSURD BID AMOUNT -- a business-rule gap with no tenancy dimension. The module has
     *      no deposit or credit concept, so a high bid from a customer who can see the product is
     *      the auction working. What was missing is the SERVER-SIDE rule, covered separately below.
     *
     * So the right assertion is not "no row" but "the row is the attacker's own": the bid is
     * written, attributed to the caller, in the product's store -- and the victim is untouched.
     */
    public function test_a_customer_cannot_forge_a_bid_for_another_customer_on_another_store(): void
    {
        $this->enableModule();

        [$storeA, ] = $this->storeWithAuctionProduct('store-a');
        [$storeB, $productB] = $this->storeWithAuctionProduct('store-b');

        $attacker = $this->customerIn($storeA);
        $victim = $this->customerIn($storeB);

        $before = DB::table('auction_product_bids')->count();

        $response = $this->actingAs($attacker, 'customers')
            ->post('/store-a/product-bid', [
                'product_id' => $productB,       // store B's product
                'bid_amount' => 999999,
                'customer_id' => $victim->id,    // somebody else entirely
                'store_id' => $storeA->id,       // and a store of the attacker's choosing
            ]);

        $row = DB::table('auction_product_bids')->orderByDesc('id')->first();

        fwrite(STDERR, "\nSTATUS  " . $response->getStatusCode() . "\n");
        fwrite(STDERR, 'BIDS before=' . $before . ' after=' . DB::table('auction_product_bids')->count() . "\n");
        if ($row) {
            fwrite(STDERR, 'ROW  product_id=' . $row->product_id
                . ' customer_id=' . $row->customer_id
                . ' store_id=' . var_export($row->store_id, true)
                . ' bid_amount=' . $row->bid_amount . "\n");
            fwrite(STDERR, '     (attacker is customer ' . $attacker->id . ' in store ' . $storeA->id
                . '; victim is customer ' . $victim->id . ' in store ' . $storeB->id . ")\n");
        }

        $this->assertNotNull($row,
            'No bid row at all. The guard refused a legitimate cross-store bid, which is the '
            . "module's own design -- see the docblock. Status " . $response->getStatusCode()
            . ', error: ' . var_export(session('error'), true));

        // THE FORGERY, CLOSED. Both of these were the attacker's to choose and are now derived.
        $this->assertSame((int) $attacker->id, (int) $row->customer_id,
            'The bid is attributed to the customer named in the REQUEST, not the authenticated '
            . 'one. Console/Auction.php:79 reads this column to decide who wins the auction and '
            . 'gets the order.');
        $this->assertSame((int) $storeB->id, (int) $row->store_id,
            "The bid's store came from request input rather than from the product it is a bid on.");

        // AND THE VICTIM HAS NOTHING. Making them the winner was the point of the forgery.
        $this->assertSame(0, DB::table('auction_product_bids')->where('customer_id', $victim->id)->count(),
            'A bid is still recorded against the victim, so Console/Auction.php could still pick '
            . 'them as the winner.');
    }

    /**
     * THE VICTIM'S OWN BID RECORD IS UNTOUCHED, which is what the forgery was for.
     *
     * MY FIRST VERSION OF THIS TEST WAS "the forged bid does not become the victim's displayed
     * maximum", and that assertion was wrong on the merits. A high bid from a customer who can see
     * the product DOES legitimately become the maximum -- `:1568` and `:1635` read
     * `max('bid_amount')` keyed on `product_id`, and raising it is the auction working. There is no
     * deposit or credit concept in the module that would make a large bid improper.
     *
     * What must not happen is the maximum being attributed to someone who did not bid, because
     * `Console/Auction.php:74-80` resolves the winner as the max row's `customer_id` and creates
     * the order for that customer. So this asserts the attribution of the top bid, not its size.
     */
    public function test_the_top_bid_belongs_to_whoever_actually_placed_it(): void
    {
        $this->enableModule();

        [$storeA, ] = $this->storeWithAuctionProduct('top-a');
        [$storeB, $productB] = $this->storeWithAuctionProduct('top-b');

        $attacker = $this->customerIn($storeA);
        $victim = $this->customerIn($storeB);

        DB::table('auction_product_bids')->insert([
            'product_id' => $productB,
            'bid_amount' => 150,
            'customer_id' => $victim->id,
            'store_id' => $storeB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($attacker, 'customers')
            ->post('/top-a/product-bid', [
                'product_id' => $productB,
                'bid_amount' => 999999,
                'customer_id' => $victim->id,   // the forgery attempt
                'store_id' => $storeA->id,
            ]);

        $max = DB::table('auction_product_bids')->where('product_id', $productB)->max('bid_amount');
        $topRow = DB::table('auction_product_bids')
            ->where('product_id', $productB)->where('bid_amount', $max)->first();

        fwrite(STDERR, "\nTOP BID  amount=" . var_export($max, true)
            . ' customer_id=' . $topRow->customer_id
            . "  (attacker={$attacker->id}, victim={$victim->id})\n");

        // THE EXACT QUERY Console/Auction.php:74 RUNS, so this asserts the thing that actually
        // decides who gets the order rather than a proxy for it.
        $this->assertSame((int) $attacker->id, (int) $topRow->customer_id,
            'The winning bid is attributed to the victim. Console/Auction.php:79 would create an '
            . "AuctionProductOrder in the victim's name at this price.");

        $this->assertSame(1, DB::table('auction_product_bids')
            ->where('customer_id', $victim->id)->count(),
            "The victim's bid record changed. They placed exactly one bid, of 150.");
        $this->assertSame(150.0, (float) DB::table('auction_product_bids')
            ->where('customer_id', $victim->id)->value('bid_amount'));
    }

    /**
     * THE BID RULE THE MODULE ONLY EVER ENFORCED IN THE BROWSER.
     *
     * `place_bid.blade.php` sets an HTML `min` and a JS handler that recolours the input border
     * without blocking submit; the controller's validator asks `bid_amount => 'required'` and
     * nothing more -- no `numeric`, no `gt`, no comparison against the current maximum. So any
     * string, any negative, and any value below the standing high bid were all accepted.
     */
    public function test_a_bid_at_or_below_the_current_maximum_is_refused(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('amount-store');
        $leader = $this->customerIn($store);
        $challenger = $this->customerIn($store);

        DB::table('auction_product_bids')->insert([
            'product_id' => $productId,
            'bid_amount' => 500,
            'customer_id' => $leader->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([['below', 499], ['equal to', 500]] as [$label, $amount]) {
            $before = DB::table('auction_product_bids')->count();

            $this->actingAs($challenger, 'customers')
                ->post('/amount-store/product-bid', [
                    'product_id' => $productId,
                    'bid_amount' => $amount,
                    'customer_id' => $challenger->id,
                    'store_id' => $store->id,
                ]);

            $this->assertSame($before, DB::table('auction_product_bids')->count(),
                "A bid {$label} the current maximum of 500 ({$amount}) was accepted.");
        }

        // AND A HIGHER ONE IS STILL ACCEPTED -- otherwise the rule above is just a wall, and a
        // guard that refuses every bid would pass the two assertions above.
        $this->actingAs($challenger, 'customers')
            ->post('/amount-store/product-bid', [
                'product_id' => $productId,
                'bid_amount' => 501,
                'customer_id' => $challenger->id,
                'store_id' => $store->id,
            ]);

        $this->assertSame(501.0,
            (float) DB::table('auction_product_bids')->where('product_id', $productId)->max('bid_amount'),
            'A bid above the current maximum was refused, so the rule refuses everyone.');
    }

    /**
     * A NON-NUMERIC OR NON-POSITIVE AMOUNT IS REFUSED.
     *
     * `'999,999'` is the one worth naming: it is what a browser locale or a careless paste
     * produces, `filter_var` rejects it, and PHP's loose numeric cast would have read it as 999 --
     * a bid two orders of magnitude below what the customer typed.
     */
    public function test_an_unusable_bid_amount_is_refused(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('bad-amount');
        $customer = $this->customerIn($store);

        foreach (['', 'abc', '0', '-50', '999,999', '1e400'] as $amount) {
            $before = DB::table('auction_product_bids')->count();

            $response = $this->actingAs($customer, 'customers')
                ->post('/bad-amount/product-bid', [
                    'product_id' => $productId,
                    'bid_amount' => $amount,
                    'customer_id' => $customer->id,
                    'store_id' => $store->id,
                ]);

            $this->assertSame($before, DB::table('auction_product_bids')->count(),
                'A bid amount of ' . var_export($amount, true) . ' was accepted.');

            /*
             * THE AMOUNT CHECK IS SUBSUMED, AND THE REASON IS NOT THE ONE I FIRST WROTE.
             *
             * I predicted an engine-hiding case: `bid_amount` is `float NOT NULL default 0`
             * (2024_09_18_070113_create_auction_product_bids_table.php:18), so a merged NULL would
             * be refused by SQLite and coerced to 0 by production MySQL under `strict => false`.
             * MEASURED, THAT IS WRONG. With the amount check deleted, every value here still
             * returns 302 with no row -- because the merged NULL is then rejected by the HANDLER'S
             * OWN validator at `:1587-1593` (`bid_amount => 'required'`), which runs before any
             * write and is therefore engine-independent. No MySQL script is needed, and claiming
             * one would have been evidence for a mechanism that does not operate.
             *
             * The check is kept regardless: it gives the customer "please enter a valid bid amount"
             * instead of a generic validator message, and it does not depend on a vendored
             * validator line staying where it is.
             *
             * The status assertion stays too -- it is what proves the refusal is a refusal rather
             * than a crash, which is how the product-id check was found to be passing for the
             * wrong reason.
             */
            $this->assertNotSame(500, $response->getStatusCode(),
                'The guard crashed on bid_amount ' . var_export($amount, true)
                . ' rather than refusing it.');
        }
    }

    /**
     * A PRODUCT ID NAMING NOTHING IS REFUSED.
     *
     * `product_id => 'nullable'` with no `exists`, and `$product` at `:1585` assigned and never
     * read, meant a bid could be recorded against an id no product has -- invisible to every
     * merchant and counted toward nobody's maximum.
     *
     * `'5abc'` is in the list for the reason `ComparesRowOwnership` records at its own expense:
     * MySQL's implicit coercion in `WHERE id = ?` accepts it as row 5, so a guard using
     * `is_numeric` would reject it while the handler resolved a real row.
     */
    public function test_a_bid_on_a_nonexistent_product_is_refused(): void
    {
        $this->enableModule();

        [$store, ] = $this->storeWithAuctionProduct('ghost-store');
        $customer = $this->customerIn($store);

        // `'5.9'` AND `'5abc'` ARE THE TWO THAT MATTER, and they diverge in OPPOSITE directions:
        // `is_numeric` accepts `'5.9'` (and casts it to 5) while rejecting `'5abc'`; MySQL's
        // implicit coercion in `WHERE id = ?` does the reverse. A guard that validated one row
        // while the handler wrote another is the defect `ComparesRowOwnership` records at its own
        // expense, so `filter_var` is used and both are refused. Product 5 may well exist here.
        foreach ([999999, 0, -1, 'abc', '', '5abc', '5.9', '1.0'] as $productId) {
            $before = DB::table('auction_product_bids')->count();

            $response = $this->actingAs($customer, 'customers')
                ->post('/ghost-store/product-bid', [
                    'product_id' => $productId,
                    'bid_amount' => 100,
                    'customer_id' => $customer->id,
                    'store_id' => $store->id,
                ]);

            $this->assertSame($before, DB::table('auction_product_bids')->count(),
                'A bid on product_id ' . var_export($productId, true) . ' was recorded.');

            /*
             * AND NOT THROUGH A 500. Without the product-exists check the guard dereferences a
             * null row, which is an ErrorException -- so "no row was written" would be true for
             * the wrong reason and this test would pass against a broken guard. A removal check
             * showed exactly that.
             */
            $this->assertNotSame(500, $response->getStatusCode(),
                'The guard crashed on product_id ' . var_export($productId, true)
                . ' rather than refusing it.');
        }
    }

    /**
     * A FILE PART NAMED LIKE A DERIVED KEY CANNOT DEFEAT THE REWRITE.
     *
     * This is the one that makes `merge()` safe rather than merely intended. `Request::__get`
     * reads `all()`, and `all()` is `array_replace_recursive($this->input(), $this->allFiles())`
     * -- so an uploaded FILE outranks a merged value. A guard that only called `merge()` would
     * believe it had derived `customer_id` while the handler read an `UploadedFile`.
     *
     * The same input divergence falsified a docblock claim in P1-INV-20's refund guard, where it
     * was found by review and recorded rather than closed. Here it is closed, and this test is
     * what proves the check is not decoration.
     */
    public function test_a_file_part_cannot_outrank_the_derived_customer_id(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('file-store');
        $attacker = $this->customerIn($store);
        $victim = $this->customerIn($store);

        $before = DB::table('auction_product_bids')->count();

        // THE FILE GOES IN THE DATA ARRAY. `post()` is `post($uri, array $data, array $headers)`
        // -- there is no files parameter, and the first version of this test passed one, which
        // silently sent no file at all: the guard then derived customer_id correctly, a row was
        // written, and the test failed while reporting the opposite of what it had measured.
        $this->actingAs($attacker, 'customers')->post(
            '/file-store/product-bid',
            [
                'product_id' => $productId,
                'bid_amount' => 777,
                'store_id' => $store->id,
                'customer_id' => \Illuminate\Http\UploadedFile::fake()->create('x.txt', 1),
            ]
        );

        $this->assertSame($before, DB::table('auction_product_bids')->count(),
            'A file part named customer_id reached the handler, which reads all() and would see '
            . 'the UploadedFile rather than the derived id.');
        $this->assertSame(0, DB::table('auction_product_bids')->where('customer_id', $victim->id)->count());
    }

    /**
     * A NON-CUSTOMER PRINCIPAL IS STILL REFUSED -- and this is checked because CLAUDE.md requires
     * it: a customer-token probe alone proves nothing about this family, since `custom.auth` logs a
     * merchant's or driver's token into a non-customer guard and the usual trap is a handler whose
     * `auth('customers')` fallback then takes request input.
     *
     * Here the group carries NO `custom.auth`, so the trap should not apply -- but "should not" is
     * not evidence. Driven, not assumed.
     */
    public function test_a_merchant_session_cannot_write_a_bid(): void
    {
        $this->enableModule();

        [, $productB] = $this->storeWithAuctionProduct('merchant-b');
        $merchant = $this->makeMerchant();

        $before = DB::table('auction_product_bids')->count();

        $response = $this->actingAs($merchant)   // the `web` guard, not `customers`
            ->post('/merchant-b/product-bid', [
                'product_id' => $productB,
                'bid_amount' => 999999,
                'customer_id' => 1,
                'store_id' => 1,
            ]);

        fwrite(STDERR, "\nMERCHANT STATUS  " . $response->getStatusCode() . "\n");

        $this->assertSame($before, DB::table('auction_product_bids')->count(),
            'A merchant web session wrote an auction bid.');
    }

    /**
     * SAME FOR A DELIVERY BOY, the third session guard.
     */
    public function test_a_delivery_boy_session_cannot_write_a_bid(): void
    {
        $this->enableModule();

        [$store, $productB] = $this->storeWithAuctionProduct('driver-b');

        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'driver' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        $before = DB::table('auction_product_bids')->count();

        $response = $this->actingAs($driver, 'deliveryboy')
            ->post('/driver-b/product-bid', [
                'product_id' => $productB,
                'bid_amount' => 999999,
                'customer_id' => 1,
                'store_id' => 1,
            ]);

        fwrite(STDERR, "\nDRIVER STATUS  " . $response->getStatusCode() . "\n");

        $this->assertSame($before, DB::table('auction_product_bids')->count(),
            'A delivery-boy session wrote an auction bid.');
    }

    /**
     * AN ANONYMOUS BID IS REFUSED CLEANLY, NOT WITH A CRASH.
     *
     * The handler's own `:1582` already redirects a null `auth('customers')->user()`, so the
     * guard's own sign-in limb looks redundant -- and a removal check confirmed the suite stayed
     * green without it. It is not redundant, and this test is why: with that limb gone the guard
     * reaches `merge(['customer_id' => $customer->id])` on a null `$customer`, which is an
     * ErrorException. So removing it converts a clean redirect into a 500 for every signed-out
     * visitor who submits the form -- a worse outcome than the defect for an ordinary user.
     */
    public function test_an_anonymous_bid_is_refused_without_crashing(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('anon-bid');

        $before = DB::table('auction_product_bids')->count();

        $response = $this->post('/anon-bid/product-bid', [
            'product_id' => $productId,
            'bid_amount' => 300,
            'customer_id' => 1,
            'store_id' => $store->id,
        ]);

        fwrite(STDERR, "\nANON STATUS  " . $response->getStatusCode() . "\n");

        $this->assertSame($before, DB::table('auction_product_bids')->count(),
            'An anonymous caller placed a bid.');
        $this->assertNotSame(500, $response->getStatusCode(),
            'An anonymous bid crashes instead of being refused.');
    }

    /**
     * THE GUARD IS INERT ON EVERYTHING ELSE.
     *
     * It is appended to the `web` GROUP, so every web request in the application passes through
     * it. A target match that was too broad would be an application-wide outage rather than a fix,
     * and a removal check on the action-keying early return came back GREEN precisely because
     * nothing here was driving a non-target route.
     */
    public function test_the_guard_is_inert_on_a_non_target_route(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('inert-bid');
        $customer = $this->customerIn($store);

        DB::table('auction_product_bids')->insert([
            'product_id' => $productId,
            'bid_amount' => 50,
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // An ordinary host page, which must be untouched.
        $login = $this->get('/login');

        $this->assertSame(200, $login->getStatusCode(),
            'The guard broke an ordinary host page. Error: ' . var_export(session('error'), true));
        $this->assertNull(session('error'),
            'The guard flashed a refusal on an ordinary host page.');

        // AND A SIBLING ROUTE IN THE SAME MODULE GROUP -- same slug shape, not this guard's
        // target. It posts nothing the guard would accept, so an over-broad guard would refuse it.
        $sibling = $this->post('/inert-bid/update-auction-status', ['id' => 1, 'status' => 1]);

        $this->assertNotSame(403, $sibling->getStatusCode(),
            'The guard forbade a route that is not its target.');
        $this->assertTrue(DB::table('auction_product_bids')->count() === 1,
            'The guard altered bid rows on a route that is not its target.');
    }

    /**
     * AND THE LEGITIMATE BID MUST STILL WORK. Asserted here, against UNFIXED code, so that the
     * positive case is pinned before any guard exists -- a guard that breaks this is then
     * immediately visible rather than discovered later.
     */
    public function test_a_customer_can_bid_on_their_own_stores_product(): void
    {
        $this->enableModule();

        [$store, $productId] = $this->storeWithAuctionProduct('own-store');
        $customer = $this->customerIn($store);

        $response = $this->actingAs($customer, 'customers')
            ->post('/own-store/product-bid', [
                'product_id' => $productId,
                'bid_amount' => 250,
                'customer_id' => $customer->id,
                'store_id' => $store->id,
            ]);

        $row = DB::table('auction_product_bids')->orderByDesc('id')->first();

        fwrite(STDERR, "\nLEGIT STATUS  " . $response->getStatusCode() . "\n");

        $this->assertNotNull($row,
            'A customer could not bid on their own store\'s product. Status '
            . $response->getStatusCode() . ', error: ' . var_export(session('error'), true));
        $this->assertSame((int) $customer->id, (int) $row->customer_id);
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame(250.0, (float) $row->bid_amount);
    }
}
