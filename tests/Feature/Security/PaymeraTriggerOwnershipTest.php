<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F185 RESIDUAL — THE PAYMERA TRIGGER ACTED ON A PATH SEGMENT WITH NO OWNERSHIP TEST.
 * =================================================================================================
 *
 * F185 stopped the trigger handing the ORDER NUMBER to a session that did not place the order
 * (`PaymeraTriggerOrderNumberTest`). It did not ask the prior question: may this caller act on this
 * payment reference at all?
 *
 * `handlePaymeraTrigger($orderId)` read `paymera_pending_<orderId>` and proceeded — poll the
 * gateway, verify, take the lock, write the order, decrement the stock, credit the merchant — with
 * nothing comparing the pending record's store to the caller's tenant. That is the shape this
 * codebase records as its most productive defect: authenticate, then act on request input, with
 * the identifier in the URL rather than the body.
 *
 * THE REACHABILITY IS NOT WHAT THE ENDPOINT'S NAME SUGGESTS, and it was measured from `route:list`:
 * `POST store/paymera/trigger/{orderId}` resolves behind
 * `['web', Authenticate, XSS, SetLocate, EmailIsVerified]`, inside the `['auth','xss','setlocate',
 * 'verified']` group in `routes/web.php`. `Authenticate` with no argument uses the `web` guard. So
 * the GATEWAY cannot call this at all, the SHOPPER cannot either (`customers` guard), and any
 * authenticated merchant or staff user can — across tenants.
 *
 * WHAT IT IS WORTH, STATED HONESTLY. It is not theft. `pollPaymentStatus()` and
 * `verifyPaymeraPayment()` run first and fail closed, so an order is written only for a payment the
 * gateway reports approved, for the customer and store the record names, never for the caller. What
 * a cross-tenant caller could do is make another tenant's write happen on their request, for a
 * reference they guessed. That is an unauthorised write across a tenant boundary.
 *
 * AND WHAT THESE TESTS DO NOT SETTLE: if Paymera is supposed to reach this endpoint, then `auth` on
 * the route is itself the defect and the answer is a signed gateway callback, not a tenancy
 * predicate. Removing authentication from a payment-completion endpoint is a payment-flow change,
 * so it is escalated to the owner rather than taken here. See the F185 ledger row.
 */
final class PaymeraTriggerOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    // =========================================================================================
    // F185 RESIDUAL — THE TRIGGER ACTED ON A PATH SEGMENT WITH NO OWNERSHIP TEST
    // =========================================================================================

    /**
     * THE DEFECT. `$orderId` is the whole of the handler's input, and nothing compared the pending
     * record's store to the caller's tenant.
     *
     * The route resolves behind `['web', Authenticate, XSS, SetLocate, EmailIsVerified]` — the
     * `auth` group in `routes/web.php` — and `Authenticate` with no argument uses the `web` guard.
     * So the gateway cannot reach it (no session), the shopper cannot reach it (`customers` guard),
     * and **any authenticated merchant or staff user can**, including one from another tenant.
     *
     * What they could do is not theft: the poll and `verifyPaymeraPayment()` fail closed, so an
     * order is written only for a gateway-approved payment, for the customer and store the record
     * names. What they could do is make another tenant's write happen on their request — the stock
     * decrement, the merchant credit and the customer's mail — for a reference they guessed.
     *
     * The refusal is the SAME 404 an unknown reference gets, so the endpoint is not an oracle for
     * which references are live.
     */
    public function test_a_merchant_from_another_tenant_cannot_trigger_this_payment(): void
    {
        [, $victimStore] = $this->makeTenant();
        [$stranger] = $this->makeTenant();

        $reference = 'ss-f185-residual-' . uniqid();
        \Illuminate\Support\Facades\Cache::put('paymera_pending_' . $reference, [
            'status' => 'P',
            'processed' => false,
            'payment_id' => 'pay-' . uniqid(),
            'order_id' => 'ORD-VICTIM',
            'request_data' => ['store_id' => $victimStore->id],
        ], 600);

        $response = $this->actingAs($stranger)
            ->postJson('/store/paymera/trigger/' . $reference);

        $this->assertSame(404, $response->getStatusCode(),
            'a merchant from another tenant reached another store\'s payment trigger. `$orderId` is '
            . 'a path segment; the store must be derived from the authenticated principal, never '
            . 'from the URL (F185 residual).');
        $this->assertSame(['error' => 'Payment context not found'], $response->json(),
            'the refusal differs from the unknown-reference answer, which turns this endpoint into '
            . 'an oracle for which payment references are live');
    }

    /**
     * THE PREMISE, and without it the case above passes for the wrong reason: a caller who DOES own
     * the store gets past the gate.
     *
     * It is asserted as "not the gate's refusal" rather than as a success, because no Paymera
     * credential is configured in this environment — `pollPaymentStatus()` returns
     * `PAYMERA_STATUS_UNAUTHENTICATED` and the handler correctly refuses to treat an
     * unauthenticated reply as authoritative. That is the right behaviour to leave alone; what
     * matters here is that the refusal comes from the PAYMENT check and not from the ownership
     * check, which is exactly the distinction a lockout would erase.
     */
    public function test_the_owning_merchant_still_reaches_the_payment_check(): void
    {
        [$owner, $store] = $this->makeTenant();

        $reference = 'ss-f185-owner-' . uniqid();
        \Illuminate\Support\Facades\Cache::put('paymera_pending_' . $reference, [
            'status' => 'P',
            'processed' => false,
            'payment_id' => 'pay-' . uniqid(),
            'order_id' => 'ORD-OWN',
            'request_data' => ['store_id' => $store->id],
        ], 600);

        $response = $this->actingAs($owner)
            ->postJson('/store/paymera/trigger/' . $reference);

        /*
         * NAMED POSITIVELY, and the independent review is why. `assertNotSame([...], json())` alone
         * is satisfied by `null` — which is what `json()` returns for a 302 and for an HTML 500. This
         * route sits in the `['auth','xss','setlocate','verified']` group, so an `EmailIsVerified`
         * redirect, an updater-gate redirect or any 500 would all have "passed" this premise while
         * proving nothing about the gate. A premise that cannot fail is not a premise.
         */
        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertNotSame(['error' => 'Payment context not found'], $response->json(),
            'the owning merchant was refused by the OWNERSHIP gate, which is a lockout rather than '
            . 'a tightening — and it would make the cross-tenant case above vacuous');
    }

    /**
     * The platform operator is tested FIRST, because `isAbleTo()` alone refuses them and a stuck
     * order is exactly what an operator diagnoses.
     *
     * F206 withdrew the operator's CATALOGUE writes and says in its own docblock that it leaves
     * unrelated platform administration alone; this is that.
     */
    public function test_the_platform_operator_is_not_locked_out_of_the_trigger(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();

        $reference = 'ss-f185-op-' . uniqid();
        \Illuminate\Support\Facades\Cache::put('paymera_pending_' . $reference, [
            'status' => 'P',
            'processed' => false,
            'payment_id' => 'pay-' . uniqid(),
            'order_id' => 'ORD-OP',
            'request_data' => ['store_id' => $store->id],
        ], 600);

        $response = $this->actingAs($operator)
            ->postJson('/store/paymera/trigger/' . $reference);

        /* Positively named, for the reason given on the case above. */
        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertNotSame(['error' => 'Payment context not found'], $response->json(),
            'the platform operator cannot reach a stuck payment they are responsible for '
            . 'diagnosing. Operator FIRST is this codebase\'s standing ordering.');
    }
}
