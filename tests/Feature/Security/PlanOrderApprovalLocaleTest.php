<?php

namespace Tests\Feature\Security;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use App\Payments\PlanPaymentType;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F153 — the bank-transfer approval control renders whatever language the buyer paid in,
 * and authorization is unchanged.
 * =================================================================================================
 *
 * `BankTransferController:103` writes `$order->payment_type = __('Bank Transfer')`, so the column
 * holds the BUYER'S locale. `plans/action.blade.php` compared it against the English literal to
 * decide whether to draw the Payment Status control — the one a platform operator uses to approve
 * a transfer. For a merchant who submitted in Arabic the column holds `تحويل مصرفي`, the
 * comparison was false, and the order sat paid, pending, and invisible to the only screen that can
 * advance it. SIXTEEN language files translate that string.
 *
 * **The fix is a comparison fix and touches no authorization.** These cases exist to prove both
 * halves of that: the control now renders in both languages, AND the money routes still refuse
 * everyone they refused before. A fix that made the button appear by relaxing a gate would pass
 * the first half and fail the second.
 */
class PlanOrderApprovalLocaleTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => 'Growth ' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ]);
    }

    /**
     * `plan_name`, `price_currency` and `txn_id` are NOT NULL and `$fillable` omits several
     * columns the table declares, so the row is built with forceFill rather than create().
     */
    private function makePlanOrder(User $user, Plan $plan, string $paymentType): PlanOrder
    {
        $order = new PlanOrder();

        $order->forceFill([
            'order_id' => 'PO-' . uniqid(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => $plan->price,
            'price_currency' => 'USD',
            'txn_id' => 'TXN-' . uniqid(),
            'payment_type' => $paymentType,
            'payment_status' => 'Pending',
            'is_refund' => 0,
        ])->save();

        return $order->refresh();
    }

    /** The action cell exactly as `PlanOrderDataTable:73` renders it. */
    private function renderActionCell(PlanOrder $order, User $user): string
    {
        return (string) view('plans.action', [
            'order' => $order,
            'user' => $user,
            'userOrders' => collect(),
        ])->render();
    }

    // ---------------------------------------------------------------- the defect

    /**
     * @dataProvider localeProvider
     */
    public function test_the_approval_control_renders_for_a_pending_bank_transfer(string $locale, string $storedType): void
    {
        $this->app->setLocale($locale);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, $storedType);

        // PREMISE: the stored value really is the one that locale produces, so a case that
        // passes because both sides are English proves nothing.
        $this->assertSame($storedType, $order->payment_type);

        $html = $this->renderActionCell($order, $merchant);

        $this->assertStringContainsString(
            route('plan.order.show', $order->id),
            $html,
            sprintf(
                'The Payment Status control is absent for a pending bank transfer stored as "%s" '
                . '(locale %s). The order cannot be approved from the plan-orders list.',
                $storedType,
                $locale
            )
        );
    }

    public static function localeProvider(): array
    {
        return [
            'english' => ['en', 'Bank Transfer'],
            'arabic' => ['ar', 'تحويل مصرفي'],
            // One more language, so the fix is not special-cased to Arabic.
            'french' => ['fr', 'Virement bancaire'],
        ];
    }

    /**
     * ANTI-VACUITY. The control is conditional, so a change that drew it unconditionally would
     * pass every case above. A settled order must still not offer it.
     */
    public function test_the_control_is_absent_once_the_transfer_is_no_longer_pending(): void
    {
        $this->app->setLocale('ar');

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, 'تحويل مصرفي');

        $order->forceFill(['payment_status' => 'Approved'])->save();

        $html = $this->renderActionCell($order->refresh(), $merchant);

        $this->assertStringNotContainsString(
            route('plan.order.show', $order->id),
            $html,
            'An already-approved transfer still offers the approval control.'
        );
    }

    /** A payment type that is not a bank transfer must not offer it either. */
    public function test_the_control_is_absent_for_a_card_payment(): void
    {
        $this->app->setLocale('ar');

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, 'Stripe');

        $html = $this->renderActionCell($order, $merchant);

        $this->assertStringNotContainsString(
            route('plan.order.show', $order->id),
            $html,
            'A Stripe plan order offers the bank-transfer approval control.'
        );
    }

    // ---------------------------------------------------------------- authorization is unchanged

    /**
     * THE HALF THAT MATTERS MOST. Drawing the button must not have made the action reachable.
     *
     * @dataProvider localeProvider
     */
    public function test_a_merchant_still_cannot_approve_their_own_transfer(string $locale, string $storedType): void
    {
        $this->app->setLocale($locale);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, $storedType);

        $this->actingAs($merchant->fresh())->get('/order_approve/' . $order->id);

        $this->assertSame(
            'Pending',
            $order->fresh()->payment_status,
            sprintf(
                'A merchant approved their OWN plan order (locale %s, stored "%s"). The F153 '
                . 'comparison fix must not have reached authorization.',
                $locale,
                $storedType
            )
        );
    }

    /** And the operator still can — in Arabic as well as English. */
    public function test_the_platform_operator_can_approve_an_arabic_transfer(): void
    {
        $this->app->setLocale('ar');

        $operator = User::factory()->create([
            'type' => 'super admin',
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, 'تحويل مصرفي');

        $this->actingAs($operator->fresh())->get('/order_approve/' . $order->id);

        $this->assertSame(
            'Approved',
            $order->fresh()->payment_status,
            'The platform operator could not approve a transfer stored in Arabic, so the workflow '
            . 'is still blocked for non-English buyers.'
        );
    }

    // ---------------------------------------------------------------- the receipt column

    /**
     * `plans/receipt.blade.php` had the same comparison, and a row that matched no branch fell
     * through to a bare `-`: no invoice link, no slip, no explanation.
     */
    public function test_the_receipt_column_recognises_an_arabic_bank_transfer(): void
    {
        $this->app->setLocale('ar');

        $merchant = $this->makeMerchant();
        $plan = $this->makePlan();
        $order = $this->makePlanOrder($merchant, $plan, 'تحويل مصرفي');
        $order->forceFill(['receipt' => 'uploads/slip.png'])->save();

        $html = (string) view('plans.receipt', ['order' => $order->refresh()])->render();

        $this->assertStringContainsString(
            'uploads/slip.png',
            $html,
            'The uploaded bank slip is not linked for an Arabic bank transfer.'
        );
    }

    // ---------------------------------------------------------------- the canonicaliser itself

    public function test_the_canonicaliser_is_what_makes_this_work(): void
    {
        // Named so that if this fails, the cause is obvious rather than three view assertions
        // failing for a reason that looks like a template problem.
        $this->assertTrue(PlanPaymentType::is('تحويل مصرفي', PlanPaymentType::BANK_TRANSFER));
        $this->assertTrue(PlanPaymentType::is('Virement bancaire', PlanPaymentType::BANK_TRANSFER));
        $this->assertTrue(PlanPaymentType::is('Bank Transfer', PlanPaymentType::BANK_TRANSFER));
        $this->assertFalse(PlanPaymentType::is('Stripe', PlanPaymentType::BANK_TRANSFER));
    }
}
