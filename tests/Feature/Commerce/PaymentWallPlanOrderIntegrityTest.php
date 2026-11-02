<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F147 — the PaymentWall plan branch writes a `PlanOrder`, and the gate in front of it stays shut.
 * ============================================================================================
 *
 * Owner instruction, 2026-10-28 §3: *"احتفظ بتصحيح `PlanOrder`، ولا تفعّل PaymentWall أو تغيّر
 * بوابة الأمان القائمة. اختبر سلامة نموذج البيانات والعقود المحيطة به، **ولا تعتبر عدم الوصول إلى
 * مسار الدفع الحي دليلاً على نجاح عملية تحصيل فعلية**."*
 *
 * THAT LAST CLAUSE IS WHAT THIS FILE IS SHAPED AROUND. The branch cannot be reached — the config
 * gate is false by default and no Paymentwall SDK is installed — and it would be easy to read
 * that as "it works now". It is the opposite: **nothing here establishes that a real charge
 * succeeds, and nothing here should be taken as evidence that it would.** What is asserted is
 * narrower and checkable: the row the branch would write names real columns, the gate is still
 * shut, and the host `orders` table is not involved.
 */
class PaymentWallPlanOrderIntegrityTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Blank `//`, `#` and docblock content while preserving line numbers. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));

                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    private function planBranchSource(): string
    {
        $src = $this->withoutComments(
            (string) file_get_contents(base_path('app/Http/Controllers/PaymentWallPaymentController.php'))
        );

        $start = strpos($src, '$order                 = new PlanOrder();');

        $this->assertNotFalse($start,
            'premise: the branch still constructs a PlanOrder. If this moved, every assertion '
            . 'below would silently answer about nothing.');

        return substr($src, $start, 1400);
    }

    /**
     * DATA-MODEL INTEGRITY. Every attribute the branch assigns must be a real `plan_orders`
     * column. The defect was that twelve of them were real `plan_orders` columns being written
     * onto `orders`, which has none of them.
     */
    public function test_every_attribute_the_branch_writes_is_a_real_plan_orders_column(): void
    {
        $this->assertTrue(Schema::hasTable('plan_orders'), 'premise: the table exists');

        $columns = Schema::getColumnListing('plan_orders');

        preg_match_all('/\$order->([a-z_]+)\s*=/i', $this->planBranchSource(), $m);

        $assigned = array_values(array_unique($m[1]));

        $this->assertNotEmpty($assigned, 'premise: the branch assigns something');
        $this->assertGreaterThanOrEqual(10, count($assigned),
            'premise: it assigns the whole plan row, not a fragment. Found: '
            . implode(', ', $assigned));

        $notColumns = array_values(array_diff($assigned, $columns));

        $this->assertSame([], $notColumns,
            'These attributes are not columns on `plan_orders`: ' . implode(', ', $notColumns)
            . '. Direct property assignment bypasses $fillable, so each one becomes a named '
            . 'column in the INSERT and the write fails outright.');
    }

    /** And none of them is an `orders` column being written by the wrong model. */
    public function test_the_branch_does_not_touch_the_host_orders_table(): void
    {
        $source = $this->planBranchSource();

        $this->assertStringNotContainsString('new Order()', $source,
            'the plan branch must not construct a host Order: a subscription payment is not a '
            . 'shopper\'s order, which is the whole of F147');
        $this->assertStringNotContainsString('order_source', $source,
            '`plan_orders` has no such column, and the provenance of a plan sale is not in the '
            . 'pos/manual/storefront vocabulary — the stamp was deleted, not moved');
    }

    /**
     * THE GATE IS STILL SHUT, asserted so that "we fixed the branch" can never be mistaken for
     * "we enabled the gateway". The owner's instruction is explicit that neither the activation
     * nor the security gate may change.
     */
    public function test_the_activation_gate_is_still_closed_by_default(): void
    {
        $this->assertFalse(
            (bool) config('payments.paymentwall'),
            'PaymentWall plan activation must remain OFF by default. F147 corrected which TABLE '
            . 'the branch writes to; it did not and must not enable the gateway.'
        );

        $source = $this->withoutComments(
            (string) file_get_contents(base_path('app/Http/Controllers/PaymentWallPaymentController.php'))
        );

        $this->assertStringContainsString("assertPlanActivationEnabled('paymentwall')", $source,
            'the handler still refuses before doing anything, and that guard was not touched');
    }

    /**
     * THE CLAUSE THE OWNER UNDERLINED. Unreachability is not success.
     *
     * This test asserts a NEGATIVE about this test file's own scope, because the risk here is not
     * a code defect but a reporting one: a green suite over an unreachable branch could be read
     * as evidence that a PaymentWall subscription charge works. It is not. No test in this
     * repository drives a real Paymentwall charge, no SDK is installed to drive one with, and the
     * correctness of a live collection remains UNVERIFIED.
     */
    public function test_no_claim_is_made_about_a_live_collection(): void
    {
        $composer = (string) file_get_contents(base_path('composer.json'))
            . (string) file_get_contents(base_path('composer.lock'));

        $this->assertStringNotContainsStringIgnoringCase('paymentwall', $composer,
            'No Paymentwall SDK is installed. If one is ever added, the branch becomes reachable '
            . 'and the question "does a real charge succeed?" becomes live and must be answered '
            . 'by driving it — not by this file passing.');
    }
}
