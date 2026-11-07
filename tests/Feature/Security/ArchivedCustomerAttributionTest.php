<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ModuleStorefrontInputGuard;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P2.5-PKG-E (F2) — the storefront guard resolved a shopper to their ARCHIVED row.
 * =================================================================================================
 *
 * Raised by the tenant-authz review of P2.5-PKG-E, as a CONSEQUENCE of that package rather than a
 * pre-existing fault, which is why it has its own file.
 *
 * `ModuleStorefrontInputGuard::sameShopperInStore()` resolves the slug store's own customer row for
 * a signed-in shopper, and the `customer_id` it returns is what a storefront review or comment is
 * attributed to. It did so with a RAW `DB::table('customers')` query -- so `Customer`'s
 * `SoftDeletingScope` did not apply -- ordered by `id` ASCENDING, which takes the OLDEST matching
 * row. An archived customer is always older than the live one who replaced them.
 *
 * WHY P2.5-PKG-E MADE THIS REACHABLE. Before that package, `CustomerController::store()` validated
 * `unique:customers,email` against the whole table including archived rows, so a store could not
 * hold an archived row and a live row with one address: the archived one blocked the live one for
 * ever. E-1 narrowed the rule to live rows -- deliberately, because the old behaviour was a dead
 * end a merchant reached in two clicks with no restore path. That repair is what creates the pair
 * this guard then resolved the wrong way round.
 *
 * A trashed-ONLY row already resolved here before the package, so half of this is pre-existing.
 * What is new is a trashed row sitting beside a CORRECT LIVE ONE and this still choosing the
 * deleted one.
 *
 * SAME STORE, so this is a wrong answer and not a cross-tenant write. It is still an attribution,
 * and an attribution to a deleted account is wrong in a way nothing downstream can detect.
 *
 * AND THE METHOD'S OWN DOCBLOCK ALREADY CLAIMED OTHERWISE: it says it matches "exactly as
 * `Utility::CustomerAuthCheck()` matches", and that one is Eloquent (`Utility.php:1079`) where the
 * soft-delete scope DOES apply. The two disagreed, and this line is what made them disagree.
 *
 * DRIVEN THROUGH REFLECTION, deliberately. The method is private and the middleware only runs on
 * the vendored SpamPrevent / ProductReview module routes -- and CLAUDE.md records that NO WorkDo
 * module loads in any test run, so a route-level test here would pass without ever reaching the
 * code. Calling the resolver directly is the only way to assert the thing that was wrong.
 */
class ArchivedCustomerAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use EnablesWorkdoModules;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $merchant = $this->makeMerchant();
        $this->store = $this->makeStore($merchant);
    }

    private function customer(string $first, string $email, bool $archived = false): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => $first, 'last_name' => 'Shopper',
            'email' => $email, 'password' => bcrypt('x'),
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
            'status' => 1,
        ]);

        if ($archived) {
            // Through Eloquent so the model cache is flushed: Customer is Cachable, and a raw
            // write would leave later reads serving the pre-write row.
            $customer->delete();
        }

        return $customer;
    }

    private function resolve(Customer $signedIn): ?int
    {
        $guard = new ModuleStorefrontInputGuard();
        $method = new \ReflectionMethod($guard, 'sameShopperInStore');
        $method->setAccessible(true);

        return $method->invoke($guard, (object) ['email' => $signedIn->email], $this->store->id);
    }

    public function test_premise_the_store_holds_an_archived_row_older_than_the_live_one(): void
    {
        $archived = $this->customer('Archived', 'shared@example.test', true);
        $live = $this->customer('Live', 'shared@example.test');

        $this->assertLessThan($live->id, $archived->id,
            'premise: the archived row must be the OLDER id, or ascending order would not prefer '
            . 'it and this test proves nothing');
        $this->assertNotNull(Customer::withoutGlobalScopes()->find($archived->id)->deleted_at,
            'premise: the archived row is not soft-deleted');
        $this->assertNull(Customer::withoutGlobalScopes()->find($live->id)->deleted_at,
            'premise: the live row is soft-deleted');
    }

    /** THE DEFECT. */
    public function test_the_guard_resolves_to_the_live_customer_not_the_archived_one(): void
    {
        $archived = $this->customer('Archived', 'shared@example.test', true);
        $live = $this->customer('Live', 'shared@example.test');

        $resolved = $this->resolve($live);

        $this->assertSame($live->id, $resolved,
            'the guard resolved the shopper to their ARCHIVED row, so a storefront review or '
            . 'comment would be attributed to a deleted customer. The query is raw, so the '
            . 'soft-delete scope does not apply, and orderBy(id) ascending takes the older row');
        $this->assertNotSame($archived->id, $resolved,
            'positive control: the two ids differ, so the assertion above is meaningful');
    }

    /**
     * The pre-existing half, pinned so the repair's SHAPE is right: a store with only an archived
     * row has no customer to attribute to, and the guard must say so rather than naming the
     * deleted one. Its docblock already calls that "the case that still has to be refused".
     */
    public function test_an_archived_only_shopper_resolves_to_nothing(): void
    {
        $archived = $this->customer('Archived', 'gone@example.test', true);

        $resolved = $this->resolve($archived);

        $this->assertNull($resolved,
            'a store holding only an ARCHIVED row for this shopper has no customer to attribute a '
            . 'comment to, so the guard must return null and let the caller refuse — naming the '
            . 'deleted row instead is the fault this file exists for');
    }

    // =============================================================================================
    //  THE SAME DEFECT, DRIVEN THROUGH THE REAL ROUTE INSTEAD OF REFLECTION
    // =============================================================================================

    /**
     * THE REFLECTION TESTS ABOVE PROVE THE RESOLVER. This one proves the SCREEN, which is a
     * different claim and the one that matters.
     *
     * CLAUDE.md records that **no WorkDo module loads in any test run** unless a row is inserted
     * into `add_on_managers`, so the suite is structurally blind to module routes: a test that
     * "passes" against one may never have reached it. `EnablesWorkdoModules` enables the module
     * through the production loader (`PackageServiceProvider::loadAddons`) and runs its
     * migrations, and its own contract is that every user asserts a premise afterwards. This does
     * both, and the premises are assertions rather than skips so an unmet one is loud.
     *
     * THE PATH, which is narrower than it first looks. `shopperComment()` only calls
     * `sameShopperInStore()` when the signed-in customer's store DIFFERS from the slug store
     * (`ModuleStorefrontInputGuard.php:136-138`) — the same-store case takes
     * `(int) $customer->id` directly. So the defect needs a shopper signed in at store B posting
     * on store A's storefront, which is a journey the platform deliberately supports: customer
     * e-mail uniqueness is per `(email, store_id)`, so one person legitimately holds a row at
     * each store, and the guard's own comment explains at length why it RESOLVES that case rather
     * than refusing it.
     *
     * AND THE HANDLER TAKES THE ID STRAIGHT FROM THE REQUEST —
     * `$comment->customer_id = $request->customer_id;`
     * (`SpamPreventController.php:87`) — which is exactly why the guard overwrites it first. So
     * the stored row is the honest end-to-end answer to "who did the platform attribute this to".
     */
    public function test_a_comment_from_another_stores_shopper_is_attributed_to_the_live_row(): void
    {
        $failed = $this->enableWorkdoModules(['SpamPrevent']);
        $this->assertSame([], $failed,
            'premise: SpamPrevent migrations threw, so a later assertion would fail for that '
            . 'reason rather than for the attribution: ' . implode(' | ', $failed));

        // PREMISES, asserted rather than skipped.
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('spamprevent.store'),
            'premise: route spamprevent.store is not registered, so the guard this test exists '
            . 'for was never reached and a pass would mean nothing');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('product_comments'),
            'premise: product_comments does not exist, so nothing can be read back');

        // Store A holds the archived row and the live one that replaced it.
        $archived = $this->customer('Archived', 'shared@example.test', true);
        $live = $this->customer('Live', 'shared@example.test');
        $this->assertLessThan($live->id, $archived->id,
            'premise: the archived row must be the older id');

        // The shopper is signed in at a DIFFERENT store, which is the only way this path is taken.
        $otherMerchant = $this->makeMerchant();
        $otherStore = $this->makeStore($otherMerchant);
        $shopperAtB = Customer::forceCreate([
            'first_name' => 'Same', 'last_name' => 'Person',
            'email' => 'shared@example.test', 'password' => bcrypt('x'),
            'store_id' => $otherStore->id, 'created_by' => $otherStore->created_by,
            'status' => 1,
        ]);

        $response = $this->actingAs($shopperAtB, 'customers')
            ->post('/' . $this->store->slug . '/spamprevent', [
                // Attacker-controlled, and the guard must overwrite it. Pointing it at the
                // ARCHIVED row makes the test fail loudly if the guard stops overwriting at all.
                'customer_id' => $archived->id,
                'comment' => 'A comment from the same person, signed in at the other store.',
            ]);

        $this->assertSame(200, $response->status(),
            'premise: the comment route answered ' . $response->status()
            . ', so nothing was stored and the attribution cannot be read back');

        $stored = DB::table('product_comments')
            ->where('store_id', $this->store->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($stored, 'premise: no comment row was written');

        $this->assertSame($live->id, (int) $stored->customer_id,
            'the comment was attributed to store A\'s ARCHIVED customer rather than the live one. '
            . 'The guard resolves the shopper with a raw query whose ordering prefers the older '
            . 'row, and the archived row is always older');
        $this->assertNotSame($archived->id, (int) $stored->customer_id,
            'positive control: the two ids differ, so the assertion above is meaningful');
        $this->assertNotSame($shopperAtB->id, (int) $stored->customer_id,
            "and it must not be store B's row either: the write is always a customer OF THE STORE "
            . 'BEING WRITTEN, which is the whole point of the guard');
    }

    public function test_an_ordinary_live_shopper_still_resolves(): void
    {
        $live = $this->customer('Only', 'only@example.test');

        $this->assertSame($live->id, $this->resolve($live),
            'the repair broke the ordinary case: a single live customer no longer resolves');
    }
}
