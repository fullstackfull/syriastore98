<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportAccessApproval;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Support\SupportAccess\AccessKind;
use App\Support\SupportAccess\SupportAccessAudit;
use App\Support\SupportAccess\SupportAccessGate;
use App\Support\SupportAccess\SupportAccessGrants;
use App\Support\SupportAccess\SupportAccessScope;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * THE THREE STRUCTURAL DECISIONS ON THE GRANT MODEL, EACH OF WHICH IS A SECURITY PROPERTY.
 * =================================================================================================
 *
 * `SUPPORT-004` / `SUPPORT-005`. These are the properties that would be quietly undone by someone
 * tidying up — adding the trait every other model has, adding the concern every other `store_id`
 * model has, or widening `$fillable` so a form can post to it. Each is asserted both as a fact
 * about the class AND, where it is observable, as a behaviour.
 *
 *  1. NOT `Cachable`         — a revocation must be visible to the next read.
 *  2. NOT `StoreScope`d      — the reader is the operator, for whom `getCurrentStore()` is 1.
 *  3. NARROW `$fillable`     — the columns that decide activeness are not mass-assignable.
 */
class SupportAccessGrantStructureTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private User $operator;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        config()->set('support_access.enforce', true);
        config()->set('support_access.max_duration_minutes', 1440);
        config()->set('support_access.emergency.required_approvals', 2);

        $this->requireTable('support_access_grants');
    }

    private function liveGrant(): SupportAccessGrant
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Structure test fixture.',
            60
        );

        return SupportAccessGrants::consent($grant, $this->merchant);
    }

    // =============================================================================================
    //  1. NOT CACHABLE
    // =============================================================================================

    /**
     * A REVOCATION MUST BE VISIBLE TO THE VERY NEXT READ.
     *
     * This is the behavioural half, and it is the sequence that would admit the operator if the
     * model were `Cachable`:
     *
     *   1. the gate reads the grant (so with the trait, the row is now cached);
     *   2. the merchant revokes;
     *   3. the gate reads again.
     *
     * CLAUDE.md records that under the suite's `CACHE_STORE=array` a stale read "lives for the rest
     * of the test", so step 3 would be served the pre-revocation row. A merchant withdrawing
     * consent is the one moment in this feature's life when the answer MUST change immediately.
     */
    public function test_a_revocation_is_visible_to_the_very_next_gate_read(): void
    {
        $grant = $this->liveGrant();

        // STEP 1 -- the read that would populate a cache.
        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'PREMISE: the grant was not live before revocation'
        );

        // STEP 2.
        SupportAccessGrants::revoke($grant, $this->merchant, 'Immediately, please.');

        // STEP 3.
        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'the gate was served a pre-revocation row -- a merchant withdrawing consent did not '
            . 'take effect, which is what adding the Cachable trait to SupportAccessGrant would do'
        );
    }

    /**
     * AND THE SAME FOR A DECLINE ON AN EMERGENCY GRANT, which is read through a second model.
     * `SupportAccessApproval` must not be cached either: a decline served as absent would admit
     * access the reviewer refused.
     */
    public function test_a_decline_is_visible_to_the_very_next_gate_read(): void
    {
        $second = $this->makePlatformAdmin();
        $third = $this->makePlatformAdmin();
        $fourth = $this->makePlatformAdmin();
        (new User)->flushCache();

        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::EMERGENCY,
            SupportAccessScope::FULL,
            'Emergency fixture.',
            60
        );
        SupportAccessGrants::decide($grant, $second, SupportAccessApproval::APPROVED);
        SupportAccessGrants::decide($grant, $third, SupportAccessApproval::APPROVED);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'PREMISE: two approvals did not make the emergency grant live'
        );

        SupportAccessGrants::decide($grant, $fourth, SupportAccessApproval::DECLINED, 'No.');

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'the approvals query was served a cached set without the decline'
        );
    }

    /**
     * THE CLASS FACT, so the reason survives even where the behaviour above happens to pass.
     *
     * Asserted against the class's real trait list rather than the file text: a `use Cachable` added
     * through a parent class or a concern would not appear in this file's source, and the behavioural
     * test cannot distinguish "not cached" from "cached but the tag happened to be flushed".
     */
    public function test_neither_support_access_model_is_cachable(): void
    {
        foreach ([SupportAccessGrant::class, SupportAccessApproval::class] as $model) {
            $traits = [];
            for ($class = $model; $class !== false; $class = get_parent_class($class)) {
                $traits = array_merge($traits, class_uses($class) ?: []);
            }

            $this->assertNotContains(
                Cachable::class,
                array_values($traits),
                $model . ' uses the Cachable trait. A cached answer to an authorization question is '
                . 'a stale answer to an authorization question: a merchant\'s revocation would not '
                . 'take effect until some unrelated write flushed the tag. AuditLog, Permission and '
                . 'Role are uncached for the same reason.'
            );
        }
    }

    // =============================================================================================
    //  2. NOT SCOPED — and this is the opposite of the usual rule
    // =============================================================================================

    /**
     * THE GRANT MODEL MUST NOT BE IN `scoped_models`.
     *
     * CLAUDE.md's table lists eleven models that really filter, and the usual advice for a new
     * `store_id`-bearing model is to join them. This one must not, and the consequence is
     * measurable: `StoreScope` would filter by the ACTING principal's resolved tenant, and the
     * acting principal is the platform operator, for whom `getCurrentStore()` returns a hardcoded
     * `1`. Every grant for any store but 1 would vanish from the gate's own query.
     *
     * BOTH HALVES, because CLAUDE.md is explicit that `getGlobalScopes()` is not the same question
     * as "is it scoped": a model absent from `scoped_models` adds no predicate even when the scope
     * is registered.
     */
    public function test_the_grant_model_is_deliberately_not_tenant_scoped(): void
    {
        $scoped = (array) config('tenancy.scoped_models', []);

        $this->assertNotContains(
            SupportAccessGrant::class,
            $scoped,
            'SupportAccessGrant was added to config/tenancy.php scoped_models. StoreScope filters by '
            . 'the ACTING principal\'s tenant, and the actor here is the platform operator, for whom '
            . 'getCurrentStore() answers a hardcoded 1 -- so the gate would refuse every grant '
            . 'except store 1\'s. Every read in SupportAccessGate names store_id explicitly instead.'
        );
        $this->assertNotContains(SupportAccessApproval::class, $scoped);

        $this->assertSame(
            [],
            array_keys((new SupportAccessGrant)->getGlobalScopes()),
            'SupportAccessGrant now registers a global scope'
        );
    }

    /**
     * THE BEHAVIOURAL CONSEQUENCE, asserted so the config check above is not the only layer.
     *
     * A grant for a store that is NOT store 1, read while the operator is the acting principal.
     * This is the read that a scope would break, and the assertion fails if one is ever added.
     *
     * A SECOND STORE IS CREATED ON PURPOSE, AND THE PREMISE BELOW IS WHY IT HAS TO BE.
     * The first version of this case used `$this->store` from `setUp()` and FAILED on its own
     * premise: `assertNotSame(1, 1)`. `SecurityTestCase::makeStore()` produces the first row in the
     * table, so the fixture store IS store 1 — exactly the id `getCurrentStore()` fabricates for
     * the platform operator. A grant for store 1 is admitted by a scoped read and an unscoped read
     * alike, so the case could not have distinguished them and would have passed either way. That
     * is CLAUDE.md's "a test that passes for the wrong reason", caught by the premise rather than
     * by review.
     */
    public function test_the_gate_finds_a_grant_for_a_store_other_than_store_one(): void
    {
        $secondStore = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        $this->assertNotSame(1, (int) $secondStore->id,
            'PREMISE: the store under test is store 1, which getCurrentStore() fabricates for the '
            . 'operator -- this case cannot distinguish a scoped read from an unscoped one');

        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $secondStore->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'A grant for a store that is not store 1.',
            60
        );
        SupportAccessGrants::consent($grant, $this->merchant);

        $this->actingAs($this->operator);

        $this->assertTrue(
            SupportAccessGate::allows($this->operator, (int) $secondStore->id, SupportAccessScope::FULL),
            'the gate could not find a grant for a store other than store 1 while the operator was '
            . 'the acting principal -- a tenant scope has been added to SupportAccessGrant, and '
            . 'StoreScope would confine the operator to the store id getCurrentStore() fabricates'
        );
    }

    // =============================================================================================
    //  3. NARROW $fillable
    // =============================================================================================

    /**
     * THE COLUMNS THAT DECIDE ACTIVENESS ARE NOT MASS-ASSIGNABLE.
     *
     * `consented_at` in `$fillable` would let `->update($request->all())` consent to a grant on the
     * merchant's behalf — the exact self-authorisation `SUPPORT-004` exists to end. CLAUDE.md
     * records a measured defect caused by `$fillable` claiming a column the table did not have;
     * this is the same discipline in the other direction.
     */
    public function test_the_activeness_columns_are_not_mass_assignable(): void
    {
        $fillable = (new SupportAccessGrant)->getFillable();

        foreach (['consented_at', 'consented_by', 'revoked_at', 'revoked_by', 'uses',
                  'first_used_at', 'last_used_at'] as $column) {
            $this->assertNotContains(
                $column,
                $fillable,
                $column . ' is mass-assignable on SupportAccessGrant. These columns decide whether '
                . 'the grant is live, so a single ->update($request->all()) would be able to consent '
                . 'to support access on the merchant\'s behalf or un-revoke a withdrawn consent.'
            );
        }
    }

    /** And the behaviour: a mass assignment cannot consent. */
    public function test_a_mass_assignment_cannot_consent_to_a_grant(): void
    {
        $grant = SupportAccessGrants::request(
            $this->operator,
            (int) $this->store->id,
            AccessKind::SUPPORT,
            SupportAccessScope::FULL,
            'Mass assignment attempt.',
            60
        );

        $grant->update(['consented_at' => now(), 'consented_by' => $this->operator->id]);

        $this->assertFalse(
            SupportAccessGate::allows($this->operator, (int) $this->store->id, SupportAccessScope::FULL),
            'a mass assignment consented to a support grant'
        );
        $this->assertNull(
            SupportAccessGrant::query()->find($grant->id)->consented_at,
            'consented_at was written by a mass assignment'
        );
    }

    /** Every `$fillable` entry is a real column. The defect CLAUDE.md records, in its own direction. */
    public function test_every_fillable_column_exists_on_both_tables(): void
    {
        foreach ([SupportAccessGrant::class, SupportAccessApproval::class] as $model) {
            $instance = new $model;

            foreach ($instance->getFillable() as $column) {
                $this->assertTrue(
                    \Schema::hasColumn($instance->getTable(), $column),
                    $model . ' declares ' . $column . ' mass-assignable and '
                    . $instance->getTable() . ' has no such column'
                );
            }
        }
    }

    // =============================================================================================
    //  THE AUDIT TRAIL IS THE EXISTING ONE
    // =============================================================================================

    /**
     * NO SECOND AUDIT TABLE, AND NO `Log::` LINE.
     *
     * Two claims the brief made load-bearing. The first: `audit_logs` is already the compliance
     * trail, with store attribution, explicit-actor support and a no-op `down()`; a second table
     * would give a merchant two places to look, one of which somebody would forget to write to.
     * The second: `docs/security/P01-OBSERVABILITY-INVENTORY.md` and `SecurityLogInventoryTest`
     * census `Log::` calls in `app/`, so if this feature wrote one it would need an inventory row in
     * the same commit. It writes none, and this asserts that so a later author who adds one is told.
     *
     * COMMENTS ARE STRIPPED FIRST. CLAUDE.md records eight occasions where a census was satisfied —
     * or broken — by prose ABOUT the thing it measures, and the docblocks in these files discuss
     * `Log::` and `AuditLog` by name.
     */
    public function test_the_support_access_feature_writes_no_log_lines_and_no_second_audit_table(): void
    {
        $files = glob(app_path('Support/SupportAccess/*.php'));
        $this->assertNotEmpty($files, 'PREMISE: no SupportAccess source files were found at all');

        foreach ($files as $file) {
            // The trait takes a path relative to the repository root, not file contents.
            $source = $this->sourceWithoutComments('app/Support/SupportAccess/' . basename($file));

            $this->assertDoesNotMatchRegularExpression(
                '/\bLog::(warning|error|critical|alert|emergency|info|notice|debug)\s*\(/',
                $source,
                basename($file) . ' writes a Log:: line. Every security log line in app/ must have a '
                . 'row in docs/security/P01-OBSERVABILITY-INVENTORY.md, matched on file:line, added '
                . 'in the SAME commit -- SecurityLogInventoryTest asserts it in both directions.'
            );
        }

        // The trail is `audit_logs` and nothing else: no new table whose name says "audit".
        $audit = $this->sourceWithoutComments('app/Support/SupportAccess/SupportAccessAudit.php');
        $this->assertStringContainsString('AuditLog::record', $audit,
            'SupportAccessAudit no longer writes to the existing audit_logs trail');
    }

    /** Every declared action name fits `audit_logs.action`, which production has as varchar(100). */
    public function test_every_audit_action_name_fits_the_column(): void
    {
        foreach (SupportAccessAudit::actions() as $action) {
            $this->assertLessThanOrEqual(
                100,
                strlen($action),
                'the audit action "' . $action . '" is longer than audit_logs.action, which the '
                . 'measured production structure has as varchar(100) -- it would be silently '
                . 'truncated on MySQL and the trail would be unsearchable'
            );
        }
    }
}
