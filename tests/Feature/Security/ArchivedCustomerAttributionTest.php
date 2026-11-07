<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ModuleStorefrontInputGuard;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

    public function test_an_ordinary_live_shopper_still_resolves(): void
    {
        $live = $this->customer('Only', 'only@example.test');

        $this->assertSame($live->id, $this->resolve($live),
            'the repair broke the ordinary case: a single live customer no longer resolves');
    }
}
