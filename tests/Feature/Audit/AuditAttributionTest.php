<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Support\Audit\AuditAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE OPERATOR'S AUDIT ROWS WERE ALL FILED UNDER STORE 1, WHOEVER THEY WERE TAKEN AGAINST.
 *
 * `AuditLog::record()` took `store_id` from `getCurrentStore()`, and that helper FABRICATES a
 * tenant for the platform operator -- `app/Helper/helper.php:888-889` is
 * `if ($user->type == 'super admin') { return 1; // Default store for super admin }`. So:
 *
 *   - auditing merchant Y missed every operator action against Y, and
 *   - store 1, a real store, accumulated rows describing other tenants' data.
 *
 * NOT a cross-tenant DISCLOSURE today, and that distinction is measured rather than assumed: there
 * is no merchant-facing audit reader. `AuditLog` appears in three controllers
 * (`BankTransferController`, `PlanController`, `UserController`) and every reference is a WRITE. The
 * trail is wrong; nobody can read the wrong part of it yet. `test_no_reader_exists_without_a_gate`
 * below is the ratchet that keeps it that way.
 *
 * `audit_logs.store_id` is `->nullable()` (migration line 87), which is what makes an honest
 * "this concerns no single store" representable instead of forcing a tenant to be invented.
 */
class AuditAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function storeOwnedBy(User $owner, string $name): Store
    {
        // `stores.email`, `.theme_id`, `.name`, `.slug` and `.created_by` are all NOT NULL
        // (measured in the create_stores_table migration); the first draft supplied three of five
        // and died on an integrity constraint rather than on anything this test is about.
        return Store::create([
            'name' => $name,
            'slug' => $name,
            'email' => $name . '@example.test',
            'theme_id' => 'stylique',
            'created_by' => $owner->id,
        ]);
    }

    // ---------------------------------------------------------------------------------------------
    // the defect
    // ---------------------------------------------------------------------------------------------

    /**
     * THE DEFECT: an operator acting with no store-bearing subject must not be filed under store 1.
     */
    #[Test]
    public function test_an_operator_action_is_not_filed_under_store_one(): void
    {
        $operator = $this->makePlatformAdmin();

        /*
         * THE OPERATOR MUST OWN A STORE, AND A MUTATION PROVED WHY.
         *
         * Deleting the operator check from `AuditAttribution` SURVIVED the first version of this
         * test: `makePlatformAdmin()` leaves `current_store` empty and owns nothing, so the
         * merchant branch answered null too and the two outcomes were identical. Giving the
         * operator a store of their own makes the branches differ -- without the check the
         * resolver would return THIS store, which is not what the action concerned.
         *
         * `getCurrentStore()` still answers 1, because its `super admin` branch fires before it
         * ever looks for an owned store, so the premise below is unaffected.
         */
        $ownedByOperator = $this->storeOwnedBy($operator, 'zz9-operator-own');

        $this->assertSame(
            1,
            getCurrentStore($operator->id),
            'premise: getCurrentStore() still fabricates store 1 for an operator. If this fails the '
            . 'helper has changed and this test is measuring the wrong thing.'
        );

        $this->actingAs($operator);
        $row = AuditLog::record('operator_action_with_no_subject');

        $this->assertNull(
            $row->store_id,
            'an operator action was attributed to store ' . $row->store_id . ', a tenant it has '
            . 'nothing to do with'
        );
        $this->assertNotSame(
            $ownedByOperator->id,
            $row->store_id,
            "the operator's own store is not what a platform-wide action concerns either"
        );
        $this->assertSame($operator->id, $row->user_id, 'the acting user must still be recorded');
    }

    /**
     * AND THE SUBJECT'S OWN STORE WINS OVER THE ACTOR'S SESSION.
     *
     * The owner's rule, verbatim: "Do not infer the affected tenant solely from an operator's
     * current UI selection when the modified resource identifies a different store." An operator
     * whose session points at store A, acting on a resource belonging to store B, must file against
     * **B**.
     */
    #[Test]
    public function test_the_subjects_own_store_beats_the_actors_selection(): void
    {
        /*
         * STORE B MUST NOT BE STORE 1, AND THE FIRST DRAFT OF THIS TEST MADE IT STORE 1.
         *
         * In a fresh RefreshDatabase the first store created takes id 1 -- which is exactly the id
         * `getCurrentStore()` fabricates for the operator, so the assertion below could not tell
         * "filed against the subject's store" from "filed against the operator's fabricated
         * store". A decoy store is created first so B's id is 2 and the two answers differ.
         */
        $decoyOwner = $this->makeMerchant();
        $decoy = $this->storeOwnedBy($decoyOwner, 'zz9-decoy');

        $merchantB = $this->makeMerchant();
        $storeB = $this->storeOwnedBy($merchantB, 'zz9-store-b');

        $this->assertSame(1, $decoy->id, 'premise: the decoy takes store 1');
        $this->assertNotSame(1, $storeB->id, 'premise: store B must differ from the fabricated 1');

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $decoy->id;
        $operator->save();

        /*
         * `plan_orders` has SIX NOT NULL columns beyond the obvious ones -- `order_id` (unique),
         * `plan_name`, `plan_id`, `price_currency`, `txn_id`, `payment_status`. Found by the
         * integrity constraint one at a time before the migration was read; recorded so the next
         * fixture reads the migration first.
         */
        $subject = \App\Models\PlanOrder::create([
            'order_id' => 'zz9-audit-' . uniqid(),
            'plan_id' => 1,
            'plan_name' => 'zz9 audit plan',
            'user_id' => $merchantB->id,
            'store_id' => $storeB->id,
            'price' => 0,
            'price_currency' => 'USD',
            'txn_id' => 'zz9-txn-' . uniqid(),
            'payment_type' => 'manual',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($operator);
        $row = AuditLog::record('plan_order_approved', $subject);

        $this->assertSame(
            $storeB->id,
            $row->store_id,
            'the row was filed under ' . var_export($row->store_id, true) . ' rather than the '
            . "store the modified resource itself names"
        );
    }

    // ---------------------------------------------------------------------------------------------
    // two stores, and the merchant path that already worked
    // ---------------------------------------------------------------------------------------------

    /** A merchant's own action keeps the attribution it already had. */
    #[Test]
    public function test_a_merchants_action_is_filed_under_their_own_store(): void
    {
        /*
         * TWO STORES, AND THE SELECTED ONE IS NOT THE ONE THE FALLBACK WOULD FIND -- ALSO BECAUSE A
         * MUTATION SURVIVED. With `current_store` equal to the merchant's only store, deleting the
         * `current_store` branch broke nothing: the `Store::where('created_by', ...)` fallback
         * returned the same id. A merchant who owns two stores and has selected the SECOND makes
         * that branch load-bearing, because `->first()` returns the first.
         */
        $merchant = $this->makeMerchant();
        $first = $this->storeOwnedBy($merchant, 'zz9-store-first');
        $selected = $this->storeOwnedBy($merchant, 'zz9-store-selected');
        $merchant->current_store = $selected->id;
        $merchant->save();

        $this->assertNotSame($first->id, $selected->id, 'premise: the two stores must differ');

        $this->actingAs($merchant);
        $row = AuditLog::record('merchant_action');

        $this->assertSame(
            $selected->id,
            $row->store_id,
            'the row was filed against store ' . var_export($row->store_id, true)
            . ' rather than the store the merchant has selected'
        );
    }

    /** TWO STORES: one merchant's action never lands on the other's trail. */
    #[Test]
    public function test_two_merchants_actions_do_not_cross(): void
    {
        $a = $this->makeMerchant();
        $storeA = $this->storeOwnedBy($a, 'zz9-two-a');
        $a->current_store = $storeA->id;
        $a->save();

        $b = $this->makeMerchant();
        $storeB = $this->storeOwnedBy($b, 'zz9-two-b');
        $b->current_store = $storeB->id;
        $b->save();

        $this->actingAs($a);
        AuditLog::record('action_by_a');

        $this->actingAs($b);
        AuditLog::record('action_by_b');

        $this->assertSame(
            [$storeA->id],
            AuditLog::where('action', 'action_by_a')->pluck('store_id')->unique()->values()->all()
        );
        $this->assertSame(
            [$storeB->id],
            AuditLog::where('action', 'action_by_b')->pluck('store_id')->unique()->values()->all()
        );
    }

    /** A merchant with no store at all is null, not 1 and not an error. */
    #[Test]
    public function test_a_merchant_with_no_store_is_not_attributed(): void
    {
        $merchant = $this->makeMerchant();

        $this->actingAs($merchant);
        $row = AuditLog::record('merchant_with_no_store');

        $this->assertNull($row->store_id);
    }

    // ---------------------------------------------------------------------------------------------
    // background contexts
    // ---------------------------------------------------------------------------------------------

    /**
     * A BACKGROUND CONTEXT -- console, queue, or a storefront request with no panel session.
     *
     * Nobody is on the `web` guard, so there is no store to attribute and no user either. Both
     * columns are nullable for exactly this case.
     */
    #[Test]
    public function test_a_background_context_attributes_neither_user_nor_store(): void
    {
        $row = AuditLog::record('background_action');

        $this->assertNull($row->store_id);
        $this->assertNull($row->user_id);
    }

    /**
     * AND A SHOPPER ON THE `customers` GUARD IS NOT A STORE ATTRIBUTION.
     *
     * CLAUDE.md: `CustomAuth` logs a token's owner into whichever guard matches its class, so the
     * DEFAULT guard can be `customers` — and a `Customer` is not a `User`. The resolver reads
     * `auth('web')` explicitly for that reason.
     */
    #[Test]
    public function test_a_customer_is_not_a_store_attribution(): void
    {
        $this->assertNull(AuditAttribution::storeFor(null, new \App\Models\Customer));
        $this->assertNull(AuditAttribution::storeFor(null, 'not a model at all'));
        $this->assertNull(AuditAttribution::storeFor(null, null));
    }

    // ---------------------------------------------------------------------------------------------
    // the reader ratchet
    // ---------------------------------------------------------------------------------------------

    /**
     * NO AUDIT READER EXISTS, AND IF ONE IS ADDED IT MUST CARRY A GATE.
     *
     * The owner's instruction: "Ensure any future audit reader enforces proper authorization." There
     * is nothing to gate today -- every `AuditLog` reference in `app/` is a write -- so the
     * honest way to honour that is a ratchet rather than a reader nobody asked for.
     *
     * This fails the moment a read appears without an authorization check beside it, which is the
     * moment the store-1 contamination described in this file's docblock would turn from a trail
     * defect into a cross-tenant disclosure.
     */
    #[Test]
    public function test_no_audit_reader_exists_without_an_authorization_gate(): void
    {
        $readers = [];
        $writesSeen = 0;

        foreach ($this->firstPartyPhpFiles() as $path) {
            $source = (string) file_get_contents($path);

            $touches = preg_match_all('/AuditLog::(\w+)\(/', $source, $m);

            if ($touches === 0) {
                continue;
            }

            /*
             * WRITES ARE NOT READS, AND THE FIRST DRAFT OF THIS LIST GOT THAT WRONG. It treated
             * anything but `record` as a read and so flagged `ThemeVersionController::create()` --
             * which is `AuditLog::create([...])`, an Eloquent INSERT. That controller writes
             * directly ON PURPOSE, and its own docblock says why: `record()` used to set
             * `getCurrentStore()`, so a refusal by an operator who named no store was audited
             * against a merchant's store. Phase 03 M4 found this same defect and routed around it;
             * this slice fixes it at the source. The bypass stays, because it passes the store the
             * GATE resolved, which is more precise than any rule `record()` can apply.
             */
            $writes = ['record', 'create', 'insert', 'forceCreate', 'updateOrCreate', 'firstOrCreate'];

            foreach ($m[1] as $method) {
                if (in_array($method, $writes, true)) {
                    $writesSeen++;

                    continue;
                }

                $gated = preg_match('/isAbleTo\(|super admin|Gate::|authorize\(/', $source) === 1;

                if (! $gated) {
                    $readers[] = basename($path) . '::' . $method . '()';
                }
            }
        }

        /*
         * THE PREMISE AN ABSENCE-RATCHET NEEDS, or it passes by seeing nothing.
         *
         * This test asserts that a list is EMPTY, which a broken regex satisfies perfectly. The
         * known writes -- `AuditLog::record()` in three controllers plus
         * `ThemeVersionController`'s direct `create()` -- must therefore be FOUND, or the sweep is
         * not looking at anything and its empty answer means nothing.
         */
        $this->assertGreaterThanOrEqual(
            4,
            $writesSeen,
            'the sweep found ' . $writesSeen . ' AuditLog writes. It should see at least four, so '
            . 'the regex is not matching and the empty reader list below proves nothing.'
        );

        $this->assertSame(
            [],
            $readers,
            "An audit READ was added with no authorization check in the same file. Audit rows for\n"
            . "operator actions are attributed per-store, and historical rows are all filed under\n"
            . "store 1 -- so an ungated reader is a cross-tenant disclosure, not just a missing\n"
            . "permission. Found: " . implode(', ', $readers)
        );
    }

    /** @return list<string> */
    private function firstPartyPhpFiles(): array
    {
        $files = [];

        foreach ([base_path('app')] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertGreaterThan(300, count($files), 'premise: the sweep found almost no files');

        return $files;
    }
}
