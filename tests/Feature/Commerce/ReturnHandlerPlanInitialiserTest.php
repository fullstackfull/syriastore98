<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Commerce\StorePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A STORE WHOSE OWNER HAS NO RESOLVABLE PLAN: WHERE THE GUARD IS LANDED, AND WHERE THE OWNER HAS HELD IT BACK.
 * =================================================================================================
 *
 * FOUND BY THE PR #140 DELETION AUDIT'S ADVERSARIAL PASS, and then CORRECTED by an independent review of the
 * fix -- which is why this file has two halves and not one.
 *
 * THE DEFECT. Twelve order writers share the shape
 *
 *     $user = StorePlan::ownerOf($store);
 *     if ($user && $user->type == 'admin') {
 *         $plan = Plan::find($user->plan_id);        // the ONLY assignment of $plan
 *     }
 *     …
 *     if ($plan->shipping_method == 'on') {          // read several times, with no guard
 *
 * The old line was `User::where('type','admin')->first()`, which filters in SQL, so the `type == 'admin'`
 * test after it was a TAUTOLOGY: it could never be false, `$plan` was always assigned and the later reads
 * were safe by construction. `StorePlan::ownerOf()` returns the store's own `created_by` user with NO type
 * predicate, which turned that test into a real branch and left `$plan` undefined on its false side. The
 * comment the change shipped says "The predicate below is unchanged; only the user asked is". The
 * predicate's TEXT is unchanged. Its MEANING is not.
 *
 * It fires for more than a demoted owner: `$plan` is also null when the owner IS `admin` but
 * `Plan::find($user->plan_id)` finds nothing (a null `plan_id`, or a plan row since deleted). That is the
 * case driven below, because it needs no role fixture.
 *
 * THE FIX, AND ITS SHAPE. Initialise `$plan = null` and read shipping through
 * `$planShippingMethod = $plan->shipping_method ?? 'off'`, so a missing plan means "shipping off". That is
 * the rule `PaymentController::processOrder` and `processShipping` already apply (`!$plan` -> no delivery),
 * and `NMIPayController` and `PaymentController::whatsapp()` already carried the guard. It is NOT the rule
 * of every quote endpoint -- `CartController::cart_list_sidebar` applies no `type` test at all, and
 * `get_shipping_data` / `get_tax_data` still throw on a null plan -- which is a finding in its own right
 * (GAP-210), not something this change claims to have closed.
 *
 * WHAT WAS LANDED AND WHAT WAS NOT -- the part the review corrected. The first version landed the guard on
 * all twelve. A recorded OWNER RULING says otherwise for some of them:
 * docs/development/phase-2/PHASE-02-DECISION-REGISTER.md, D-13 / D-18, reads "The `$plan` fix stays
 * suspended", because landing it on a handler that decrements stock BEFORE the payment is proven "would
 * convert a pre-decrement crash into a working unverified decrement ... the forbidden outcome, exactly".
 * The register names PaiementPro, CinetPay and Easebuzz as BLOCKED on that ground and discharges the
 * condition for Khalti only. The same reasoning reaches the generic gateway return, which writes the order
 * (and moves the stock) before `gatewayReturnPaymentStatus()` decides Paid or Pending, and PayU's copy,
 * which has no route and no caller. Those five are HELD_BACK below, deliberately and with the reason at the
 * site.
 *
 * WHY THE OTHER SEVEN CANNOT HELP A DRAIN:
 *
 *   - `PaymentService::getProductStatus` serves `cod` and `bank_transfer` only (PaymentService.php:435/437);
 *     no payment is expected at the return, so there is no evidence for a decrement to precede.
 *   - the three `ApiController` writers sit behind `custom.auth` (routes/api.php:140/141/165), so they are
 *     not an anonymous drain.
 *   - `AuthorizeNetPaymnetController::getOrderPaymentStatus` IS the charge: it builds an
 *     `authCaptureTransaction` and reads the plan BEFORE it (so a null plan used to fail before the card
 *     was touched, not after).
 *   - `KhaltiPaymnetController` verifies the capture before its stock loop; the register discharges the
 *     condition for it.
 *   - `NMIPayController` and `PaymentController::whatsapp()` already carried the guard.
 *
 * WHAT THE OWNER IS BEING ASKED (GAP-210). The ruling was written when the crash was true only of "a broken
 * install" (the plan came from the first admin row). P4-F014 moved the lookup to the store's own owner, so
 * it is now true of EVERY store whose owner is not a plan-holding admin: the operator's own store, a
 * self-demoted merchant, a deleted owner. A shopper at such a store who pays at a redirect gateway gets a
 * 500 and no order. That is new information for a decision the owner reserved, and this file records the
 * condition for lifting the hold rather than lifting it.
 *
 * WHAT THIS FILE DOES NOT CLAIM:
 *
 *   - It does not decide whether a store without a plan SHOULD be able to sell; that is a business rule.
 *   - The behavioural cases drive TWO of the seven landed handlers (the shared COD writer and `ordersave`).
 *     The others are held by the census below, which reads their method bodies. That is the limit of the
 *     evidence, stated rather than implied.
 */
class ReturnHandlerPlanInitialiserTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const LINE = 40.00;

    /**
     * Handlers where the guard IS landed: `$plan = null;` immediately before the assignment, and the
     * shipping read goes through `$planShippingMethod`.
     */
    private const LANDED = [
        [\App\Http\Controllers\AuthorizeNetPaymnetController::class, 'getOrderPaymentStatus'],
        [\App\Http\Controllers\KhaltiPaymnetController::class, 'getOrderPaymentStatus'],
        [\App\Http\Controllers\NMIPayController::class, 'getNMIProductStatus'],
        [\App\Http\Controllers\PaymentController::class, 'whatsapp'],
        [\App\Services\PaymentService::class, 'getProductStatus'],
        // The mobile/API storefront order writers. `ordersave` carries the shape TWICE, and its first block is
        // a PRE-EXISTING latent version (it already resolved `$store->created_by` before this branch).
        [\App\Http\Controllers\Api\ApiController::class, 'place_order'],
        [\App\Http\Controllers\Api\ApiController::class, 'ordersave'],
    ];

    /**
     * Handlers where the guard is DELIBERATELY NOT landed, by owner ruling D-13 / D-18 and its reasoning:
     * the decrement precedes any proof of payment, so the old crash is the only thing standing between an
     * anonymous request and an unverified decrement on a store whose owner has no plan.
     *
     * This list is a RATCHET IN BOTH DIRECTIONS. Landing the guard on one of these without moving it to
     * LANDED fails `test_the_held_back_handlers_still_carry_the_owner_rulings_suspension`; the way to move it
     * is the one the register states -- close the inventory-security dependency, prove no illegitimate
     * decrement path was opened, land it as its own reviewed commit, and update the register.
     */
    private const HELD_BACK = [
        [\App\Http\Controllers\PaymentController::class, 'getProductStatus'],
        [\App\Http\Controllers\PaiementProPaymentController::class, 'getProductStatus'],
        [\App\Http\Controllers\CinetPayController::class, 'getProductStatus'],
        [\App\Http\Controllers\EasebuzzController::class, 'getProductStatus'],
        [\App\Http\Controllers\PayUPaymentController::class, 'getProductStatus'],
    ];

    /**
     * Every occurrence under app/ of the shape `type == 'admin') { $plan = Plan::find(`, by file.
     *
     * THE DISCOVERY RATCHET. The two lists above are hand-written, so on their own they cannot see a
     * thirteenth writer pasted from one of these. This counts the SHAPE across the whole tree; a new
     * occurrence changes a number here and fails until it is classified on purpose.
     *
     * The first ten files are the order writers (classified above). The last two are merchant/login
     * contexts that resolve the SIGNED-IN user's own plan, not a store owner's, and are not order writers.
     */
    private const SHAPE_COUNTS = [
        'app/Http/Controllers/Api/ApiController.php' => 3,                 // place_order, ordersave x2 (LANDED)
        'app/Http/Controllers/AuthorizeNetPaymnetController.php' => 1,     // LANDED
        'app/Http/Controllers/CinetPayController.php' => 1,                // HELD_BACK
        'app/Http/Controllers/EasebuzzController.php' => 1,                // HELD_BACK
        'app/Http/Controllers/KhaltiPaymnetController.php' => 1,           // LANDED
        'app/Http/Controllers/NMIPayController.php' => 1,                  // LANDED (already guarded)
        'app/Http/Controllers/PaiementProPaymentController.php' => 1,      // HELD_BACK
        'app/Http/Controllers/PayUPaymentController.php' => 1,             // HELD_BACK
        'app/Http/Controllers/PaymentController.php' => 2,                 // getProductStatus (HELD_BACK), whatsapp (LANDED)
        'app/Services/PaymentService.php' => 1,                            // LANDED
        'app/Http/Controllers/AppSettingController.php' => 1,              // not an order writer: the signed-in user's own plan
        'app/Http/Controllers/Auth/AuthenticatedSessionController.php' => 1, // not an order writer
        'app/Http/Controllers/Auth/RegisteredUserController.php' => 2,     // not order writers
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    // ---------------------------------------------------------------------------------------------
    // The behaviour.
    // ---------------------------------------------------------------------------------------------

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} a tenant whose owner has NO plan */
    private function tenantWithoutPlan(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['stock_management' => 'off', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function shopperWithBasket($store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'ph-'.uniqid().'@e.test',
            'mobile' => '555'.random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-'.uniqid(), 'product_stock' => 50, 'track_stock' => 0,
            'variant_product' => 0, 'price' => self::LINE, 'sale_price' => self::LINE,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::LINE, 'store_id' => $store->id,
        ]);

        return $customer;
    }

    private function placeCodOrder($store, Customer $customer)
    {
        return $this->actingAs($customer, 'customers')->post('/'.$store->slug.'/process-order', [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customer->id,
            'payment_type' => 'cod',
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => $customer->email,
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
                'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ]);
    }

    public function test_premise_the_fixture_owner_is_an_admin_whose_plan_does_not_resolve(): void
    {
        [$merchant, $store] = $this->tenantWithoutPlan();

        // The guard `$user && $user->type == 'admin'` PASSES, so this is the case where the
        // old SQL-side filter and the new owner lookup AGREE about who the user is and disagree
        // only about whether a plan exists. It is the narrowest reproduction.
        $this->assertSame($merchant->id, StorePlan::ownerOf($store)->id);
        $this->assertSame('admin', StorePlan::ownerOf($store)->type);
        $this->assertNull($merchant->plan_id, 'Premise: the owner has no plan_id.');
        $this->assertNull(\App\Models\Plan::find($merchant->plan_id), 'Premise: so Plan::find() resolves nothing.');
    }

    public function test_a_cod_order_is_written_when_the_owner_has_no_resolvable_plan(): void
    {
        [, $store] = $this->tenantWithoutPlan();
        $customer = $this->shopperWithBasket($store);

        $response = $this->placeCodOrder($store, $customer);
        $order = Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();

        $this->assertNotNull(
            $order,
            'No order was written. The shared order writer read `$plan->shipping_method` on a plan '
            .'that was never assigned. HTTP '.$response->getStatusCode().'.'
        );

        // AND AT THE AMOUNT THE SHOPPER WAS QUOTED: a missing plan means no delivery charge in the
        // quote (`processShipping`: `!$plan` -> no delivery), so the writer must agree.
        $this->assertEqualsWithDelta(0.0, (float) $order->delivery_price, 0.005);
        $this->assertEqualsWithDelta(self::LINE, (float) $order->final_price, 0.005,
            'The order total must equal the basket when there is no plan to charge delivery.');
    }

    private function saveOrderViaApi($store, Customer $customer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken])
            ->postJson('/api/'.$store->slug.'/order-save', [
                'customer_id' => $customer->id,
                'billing_info' => [
                    'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                    'billing_address' => '1 St', 'billing_user_telephone' => '555',
                    'billing_postecode' => '11111', 'billing_country' => 'X',
                    'billing_state' => 'Y', 'billing_city' => 'Z',
                    'delivery_address' => '1 St', 'delivery_postcode' => '11111',
                    'delivery_country' => 'X', 'delivery_state' => 'Y', 'delivery_city' => 'Z',
                ],
                'payment_type' => 'cod',
                // Required by the validator, and ignored: a store with no plan charges no delivery.
                'delivery_id' => 0,
            ]);
    }

    public function test_the_api_order_writer_also_writes_an_order_when_the_owner_has_no_plan(): void
    {
        // A SECOND ROUTE AND A SECOND WRITER. `ordersave` is not the shared storefront writer, and
        // its first plan block pre-dates this branch -- so this is the latent version of the same
        // condition, not a regression, and it is held by the same fix.
        [, $store] = $this->tenantWithoutPlan();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrderViaApi($store, $customer);
        $order = Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();

        $this->assertNotNull(
            $order,
            'No order was written by the API order writer. HTTP '.$response->getStatusCode().': '
            .substr((string) $response->getContent(), 0, 300)
        );
        $this->assertEqualsWithDelta(0.0, (float) $order->delivery_price, 0.005);
        $this->assertEqualsWithDelta(self::LINE, (float) $order->final_price, 0.005);
    }

    // ---------------------------------------------------------------------------------------------
    // The census: every handler, by reading its real method body.
    // ---------------------------------------------------------------------------------------------

    /**
     * The method's code with comments blanked and line numbers preserved.
     *
     * COMMENT-STRIPPED because this project's text censuses are satisfied by prose, and because
     * the explanatory comments in these handlers necessarily quote `$plan->shipping_method`.
     *
     * @return list<string> one entry per source line of the method, 0-based from its first line
     */
    private function codeLines(string $class, string $method): array
    {
        $r = new ReflectionMethod($class, $method);
        $source = (string) file_get_contents($r->getFileName());

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $code .= str_repeat("\n", substr_count($token[1], "\n"));

                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        $all = explode("\n", $code);

        return array_slice($all, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1);
    }

    /** The method's source INCLUDING its comments, for the one assertion that is about a comment. */
    private function rawMethod(string $class, string $method): string
    {
        $r = new ReflectionMethod($class, $method);
        $lines = explode("\n", (string) file_get_contents($r->getFileName()));

        return implode("\n", array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
    }

    /** @return list<int> 0-based method-line indices of every `$plan = Plan::find(` assignment */
    private function planAssignments(array $lines): array
    {
        $assigns = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\$plan\s*=\s*Plan::find\(/', $line)) {
                $assigns[] = $i;
            }
        }

        return $assigns;
    }

    /** Whether the nearest non-blank code line above an assignment's guard line is `$plan = null;`. */
    private function isInitialisedImmediatelyBefore(array $lines, int $assign): bool
    {
        // $assign is the assignment; $assign - 1 is its guard line. Look above the guard, skipping lines that
        // were comments (blank after stripping).
        $j = $assign - 2;
        while ($j >= 0 && trim($lines[$j]) === '') {
            $j--;
        }

        return $j >= 0 && (bool) preg_match('/\$plan\s*=\s*null\s*;/', $lines[$j]);
    }

    private function label(string $class, string $method): string
    {
        return substr(strrchr($class, '\\'), 1).'::'.$method;
    }

    public function test_every_landed_handler_initialises_the_plan(): void
    {
        $uninitialised = [];

        foreach (self::LANDED as [$class, $method]) {
            $lines = $this->codeLines($class, $method);
            $body = implode("\n", $lines);

            $this->assertStringContainsString(
                'StorePlan::ownerOf(',
                $body,
                $class.'::'.$method.' no longer resolves the owner through StorePlan -- update LANDED.'
            );

            // EVERY assignment from Plan::find() sits inside its own `if (... admin ...) {` guard, and that guard
            // must be IMMEDIATELY preceded by `$plan = null;`.
            //
            // IMMEDIATELY, NOT MERELY EARLIER. A first version accepted any earlier initialiser, and mutation
            // showed the consequence: `ordersave` carries two blocks, and deleting the second block's
            // initialiser went unnoticed because the first one precedes it. Whether the two blocks can run on
            // different paths is not something a text check can prove, so the check requires the shape the fix
            // actually has rather than a weaker one that happens to pass.
            $assigns = $this->planAssignments($lines);

            $this->assertNotEmpty($assigns, $class.'::'.$method.' no longer assigns $plan from Plan::find().');

            foreach ($assigns as $assign) {
                if (! $this->isInitialisedImmediatelyBefore($lines, $assign)) {
                    $uninitialised[] = $this->label($class, $method).' (assignment at method line '.($assign + 1).')';
                }
            }
        }

        $this->assertSame(
            [],
            $uninitialised,
            "These handlers assign \$plan only inside the owner's `type == 'admin'` test and never\n"
            ."initialise it, so a store whose owner has no resolvable plan dies on an undefined\n"
            ."variable:\n  ".implode("\n  ", $uninitialised)
        );
    }

    public function test_no_landed_handler_reads_the_plans_shipping_method_off_the_raw_variable(): void
    {
        $raw = [];

        foreach (self::LANDED as [$class, $method]) {
            $lines = $this->codeLines($class, $method);
            $start = (new ReflectionMethod($class, $method))->getStartLine();

            foreach ($lines as $i => $line) {
                // `$plan->shipping_method` is only safe behind `??`; the repaired handlers read
                // `$planShippingMethod`, which was resolved once with `?? 'off'`.
                // Already null-safe on the same line: `$plan && $plan->shipping_method` (ordersave
                // carries one such read) or `$plan->shipping_method ?? 'off'`.
                if (preg_match('/\$plan\s*&&/', $line)) {
                    continue;
                }
                if (preg_match('/\$plan->shipping_method(?!\s*\?\?)/', $line)) {
                    $raw[] = $this->label($class, $method).':'.($start + $i);
                }
            }
        }

        $this->assertSame(
            [],
            $raw,
            "These lines read \$plan->shipping_method with no null guard, so a missing plan is a\n"
            ."fatal instead of 'shipping off':\n  ".implode("\n  ", $raw)
        );
    }

    /**
     * THE OWNER'S RULING, PINNED. A ratchet in the direction this project rarely needs: it fails if someone
     * lands the guard on a held-back handler. That is the point. The register says the guard "may land as its
     * own reviewed commit, with tests proving no illegitimate decrement path was opened", once the inventory
     * security dependency is closed. Landing it quietly inside another change is the thing the owner ruled out,
     * and this failure is the prompt to do it properly: close the dependency, add the drain test, update the
     * register, and only then move the handler to LANDED.
     */
    public function test_the_held_back_handlers_still_carry_the_owner_rulings_suspension(): void
    {
        $landedWithoutLifting = [];
        $missingExplanation = [];

        foreach (self::HELD_BACK as [$class, $method]) {
            $lines = $this->codeLines($class, $method);

            $assigns = $this->planAssignments($lines);
            $this->assertNotEmpty($assigns, $class.'::'.$method.' no longer assigns $plan -- update HELD_BACK.');

            foreach ($assigns as $assign) {
                if ($this->isInitialisedImmediatelyBefore($lines, $assign)) {
                    $landedWithoutLifting[] = $this->label($class, $method);
                }
            }

            // The reason must live AT the site, because the next person to see an undefined `$plan` will read
            // the code, not this file.
            $raw = $this->rawMethod($class, $method);

            if (! str_contains($raw, 'D-13') || ! str_contains($raw, 'GAP-210')) {
                $missingExplanation[] = $this->label($class, $method);
            }
        }

        $this->assertSame([], $landedWithoutLifting,
            "The `\$plan` guard was landed on a handler the owner has held back (PHASE-02-DECISION-REGISTER.md D-13/D-18):\n  "
            .implode("\n  ", $landedWithoutLifting)."\nLifting the hold needs the register's condition discharged and its own reviewed commit; "
            .'then move the handler from HELD_BACK to LANDED here.');

        $this->assertSame([], $missingExplanation,
            "These held-back handlers no longer say WHY at the site (they must name D-13 and GAP-210):\n  ".implode("\n  ", $missingExplanation));
    }

    public function test_the_register_still_says_what_this_file_says_it_says(): void
    {
        // The whole HELD_BACK list rests on a document. If the document stops saying it, the list is resting on
        // nothing, and that is something a reviewer must be told rather than discover.
        $register = (string) file_get_contents(base_path('docs/development/phase-2/PHASE-02-DECISION-REGISTER.md'));

        $this->assertStringContainsString('The `$plan` fix stays suspended', $register);
        $this->assertStringContainsString('PaiementPro, CinetPay, Easebuzz', $register);
        $this->assertStringContainsString('closed on', $register, 'the register no longer records that D-13 is closed on Khalti only');
    }

    public function test_the_shape_is_not_growing_unclassified(): void
    {
        $root = base_path('app');
        $found = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            // `(?:\\?(?:[A-Za-z_]+\\)*)?` tolerates a fully-qualified `\App\Models\Plan::find(`: a mutation run pasted
            // the shape that way and the first version of this pattern did not see it.
            $count = preg_match_all('/type\s*==\s*[\'"]admin[\'"]\s*\)\s*\{\s*\$plan\s*=\s*(?:\\\\?(?:[A-Za-z_]+\\\\)*)?Plan::find\(/', $code);

            if ($count > 0) {
                $found[substr($file->getPathname(), strlen(base_path()) + 1)] = $count;
            }
        }

        ksort($found);
        $expected = self::SHAPE_COUNTS;
        ksort($expected);

        $this->assertSame($expected, $found,
            "The number of `\$plan = Plan::find(` assignments inside a `type == 'admin'` test changed. A new one is a new "
            ."handler with the unguarded-plan shape: classify it in LANDED or HELD_BACK (and read the owner's ruling "
            ."in the docblock above before choosing), then update SHAPE_COUNTS.");
    }

    public function test_the_census_reaches_the_handlers_it_claims_to(): void
    {
        // A text census that finds nothing proves nothing. Every declared handler must really contain the owner
        // lookup AND the plan-assignment shape this file is about, so that a rename cannot turn the assertions
        // above into vacuous passes.
        $this->assertCount(7, self::LANDED);
        $this->assertCount(5, self::HELD_BACK);

        foreach (array_merge(self::LANDED, self::HELD_BACK) as [$class, $method]) {
            $body = implode("\n", $this->codeLines($class, $method));

            $this->assertMatchesRegularExpression(
                '/\$user->type\s*==\s*[\'"]admin[\'"]/',
                $body,
                $class.'::'.$method.' no longer carries the admin-type test this file is about.'
            );
        }
    }
}
