<?php

namespace Tests\Unit\Tenancy;

use App\Scopes\StoreScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * THE EXPLICIT-CONTEXT STACK, AS PURE LOGIC.
 *
 * `StoreScope::forStoreContext()` is the answer to the background-job question: a queued job or a
 * scheduled command has no request, no slug and no web-guard principal, so it opens its tenant
 * explicitly. The property that makes that safe -- A CONTEXT IS NEVER INHERITED AND NEVER
 * OUTLIVES ITS BLOCK -- is the one the brief singles out as the trap ("a queued job must not
 * inherit a stale context"), and it is pure static bookkeeping with no database, no request and
 * no container in it.
 *
 * SO IT IS PROVEN HERE, WITHOUT A LARAVEL APPLICATION, and that is deliberate rather than
 * convenient. A Feature test proving the same thing proves it for an application that happens to
 * be in a clean state; proving it as logic proves it for a queue worker that has run nine hundred
 * jobs, which is the environment where a leaked context would actually bite. The Feature-level
 * counterpart (same property, through real queries) is
 * StorefrontContextModelIsolationTest::test_a_job_in_an_explicit_context_acts_only_on_that_store.
 *
 * This class extends PHPUnit\Framework\TestCase, NOT Tests\TestCase, so no application is booted
 * by it. It deliberately asserts nothing that depends on a container being absent, because other
 * tests in the Unit suite do boot one and PHPUnit shares a process -- a test keyed on "no
 * container exists" would pass or fail on execution order, which is worse than no test at all.
 * Everything below is container-independent.
 */
class StoreScopeContextStackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
    }

    protected function tearDown(): void
    {
        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();

        parent::tearDown();
    }

    public function test_no_context_is_open_by_default(): void
    {
        $this->assertFalse(StoreScope::hasExplicitContext(),
            'There is no ambient tenant. A job that does not open a context gets none, which is '
            . 'what makes a stale inherited context structurally impossible rather than unlikely.');
    }

    public function test_a_context_is_open_inside_its_block_and_closed_after(): void
    {
        $seen = null;

        $returned = StoreScope::forStoreContext(7, function () use (&$seen) {
            $seen = StoreScope::hasExplicitContext();

            return 'the callback return value';
        });

        $this->assertTrue($seen);
        $this->assertSame('the callback return value', $returned,
            'forStoreContext() must return the callback\'s value, or wrapping a job body in it '
            . 'would silently discard the result.');
        $this->assertFalse(StoreScope::hasExplicitContext());
    }

    public function test_a_throwing_callback_still_closes_its_context(): void
    {
        try {
            StoreScope::forStoreContext(7, function () {
                throw new RuntimeException('job failed');
            });
            $this->fail('the exception must propagate to the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('job failed', $e->getMessage());
        }

        $this->assertFalse(StoreScope::hasExplicitContext(),
            'A FAILED JOB MUST NOT LEAVE ITS TENANT BEHIND. A queue worker runs the next job in '
            . 'the same process, so a context restored only on the happy path would hand job N+1 '
            . 'the tenant of a job that crashed.');
    }

    public function test_nesting_restores_the_outer_context_rather_than_clearing_it(): void
    {
        $depths = [];

        StoreScope::forStoreContext(7, function () use (&$depths) {
            $depths[] = StoreScope::hasExplicitContext();

            StoreScope::forStoreContext(9, function () use (&$depths) {
                $depths[] = StoreScope::hasExplicitContext();
            });

            $depths[] = StoreScope::hasExplicitContext();
        });

        $depths[] = StoreScope::hasExplicitContext();

        $this->assertSame([true, true, true, false], $depths,
            'A STACK, NOT A BOOLEAN. If this were a flag, the inner block\'s exit would clear the '
            . 'outer one and the rest of the outer job would run unfiltered -- the same class of '
            . 'bug that a counter fixes in allowCrossTenant().');
    }

    public function test_a_throwing_inner_block_restores_the_outer_context_not_none(): void
    {
        $afterInner = null;

        StoreScope::forStoreContext(7, function () use (&$afterInner) {
            try {
                StoreScope::forStoreContext(9, function () {
                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException $e) {
                // swallowed by the outer job, as a job that retries a sub-step would
            }

            $afterInner = StoreScope::hasExplicitContext();
        });

        $this->assertTrue($afterInner,
            'The outer job caught the inner failure and carried on. Its own tenant must still be '
            . 'in force, or the remainder of that job runs unfiltered after any handled error.');
        $this->assertFalse(StoreScope::hasExplicitContext());
    }

    public function test_an_empty_context_is_rejected_instead_of_compiling_to_zero_rows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs at least one store id');

        StoreScope::forStoreContext([], fn () => null);
    }

    public function test_the_rejection_does_not_leave_a_context_open(): void
    {
        try {
            StoreScope::forStoreContext([], fn () => null);
        } catch (InvalidArgumentException $e) {
            // expected
        }

        $this->assertFalse(StoreScope::hasExplicitContext(),
            'The validation runs BEFORE the stack is pushed, so a rejected call must leave the '
            . 'stack exactly as it found it.');
    }

    /**
     * THE PRECEDENCE OF THE TWO HATCHES, asserted so it is a decision rather than an accident of
     * where the two checks happen to be written.
     *
     * `isActiveFor()` short-circuits on `$suppressionDepth > 0` BEFORE `resolveTenant()` is ever
     * called, so `allowCrossTenant()` nested inside `forStoreContext()` removes the predicate
     * entirely rather than narrowing it to the explicit context. That is the intended reading:
     * one hatch says "act as THIS tenant", the other says "act as NONE".
     */
    public function test_a_cross_tenant_block_nested_in_an_explicit_context_wins(): void
    {
        $inside = null;

        StoreScope::forStoreContext(7, function () use (&$inside) {
            $this->assertFalse(StoreScope::isSuppressed(), 'precondition: not suppressed yet');

            StoreScope::allowCrossTenant(function () use (&$inside) {
                $inside = [StoreScope::isSuppressed(), StoreScope::hasExplicitContext()];
            });

            $this->assertFalse(StoreScope::isSuppressed(),
                'the suppression must be restored when the inner block exits');
            $this->assertTrue(StoreScope::hasExplicitContext(),
                'and the explicit context must still be in force afterwards');
        });

        $this->assertSame([true, true], $inside,
            'Inside the nested block BOTH are live, and suppression is what isActiveFor() reads '
            . 'first -- so no predicate is added at all. The explicit context is not lost, it is '
            . 'simply not consulted while suppressed.');
        $this->assertFalse(StoreScope::hasExplicitContext());
        $this->assertFalse(StoreScope::isSuppressed());
    }

    public function test_the_all_models_flip_is_off_as_a_compiled_in_default(): void
    {
        $this->assertFalse(StoreScope::$enabled,
            'Asserted with NO application and NO config, so this is the class\'s compiled-in '
            . 'default rather than whatever config/tenancy.php happens to say. $enabled '
            . 'constrains EVERY trait-carrying model at once and must stay false.');
    }

    public function test_the_context_constants_are_all_distinct(): void
    {
        $contexts = [
            StoreScope::CONTEXT_SUPER_ADMIN,
            StoreScope::CONTEXT_MERCHANT_ADMIN,
            StoreScope::CONTEXT_STOREFRONT_WEB,
            StoreScope::CONTEXT_STOREFRONT_PUBLIC,
            StoreScope::CONTEXT_EXPLICIT,
            StoreScope::CONTEXT_CONSOLE,
            StoreScope::CONTEXT_UNRESOLVED,
        ];

        $this->assertCount(7, array_unique($contexts),
            'Two contexts share a string value. The whole design is that each context resolves '
            . 'its own admissible set, so a collision would make two of them indistinguishable '
            . 'in resolveTenant()\'s return value and in every assertion written against it -- '
            . 'a copy-paste away, and invisible to php -l.');
    }
}
