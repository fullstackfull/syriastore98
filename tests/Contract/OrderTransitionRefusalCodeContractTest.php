<?php

namespace Tests\Contract;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * OWNER DECISION H3 — THE TWO DOORS ANSWER A REFUSED TRANSITION WITH DIFFERENT HTTP CODES, ON
 * PURPOSE, UNTIL A COORDINATED FLUTTER RELEASE.
 * =================================================================================================
 *
 * Decided 2026-11-25, option (a) now and option (b) with the appropriate Flutter release:
 * *"Preserve the current caller-specific response contracts until coordinated migration is safe."*
 * (`docs/development/OWNER-DECISION-PACKAGE.md`, H3.)
 *
 * So the same refusal, from the same state machine, is answered two ways:
 *
 *   `POST api/admin/orders/update-status`        HTTP **200** with `success: false` and the message
 *   (`Api\DashboardController::updateStatus`)    in the body. The shipped Flutter admin app reads
 *                                                this API, and `ApiResponser::error()` answers 200
 *                                                by default, so 200-with-a-flag is what every
 *                                                other refusal on that controller does.
 *
 *   `POST api/delivery-syria/orders/update-status`  HTTP **409**, consistent with its own
 *   (`Api\DeliverySyriaOrderController::updateStatus`)  neighbours and with `ProductController`,
 *                                                `StoreFinanceController`, `OrderRefundController`
 *                                                and `PosController`. It is a server-to-server
 *                                                webhook with no shipped client to break.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS RATHER THAN THE DECISION ENTRY ALONE.
 *
 * H3's own text says the obligation is *"recorded here because it will otherwise be forgotten"*.
 * This project's repeated failure is exactly that — a thing measured, written down, and then not
 * carried into any list. A decision that lives only in prose is one a later change can contradict
 * silently in either direction:
 *
 *   - "tidying" the admin door to 409 would be a USER-VISIBLE REGRESSION before the client that
 *     understands it ships. Worse, `ApiResponser` conventions mean someone reaching for a status
 *     code here is as likely to write 401 — and the shipped app FORCE-CLEARS ITS SESSION on any
 *     401, so one refused action becomes a logout.
 *   - "consistency" in the other direction, making the webhook answer 200, hides a refusal from a
 *     machine client that has no reason to read a body flag. The decision names that as the wrong
 *     direction.
 *
 * SO BOTH CODES ARE ASSERTED, IN BOTH DIRECTIONS, AND THE FAILURE MESSAGES NAME H3. When the
 * Flutter release that understands 409 is prepared, this file is the thing that has to change in
 * the same commit as the client — which is the whole point of option (b) being "with the
 * appropriate release" rather than "later".
 *
 * NOT A TEST OF THE RULE ITSELF. Whether the transition should be refused is
 * `FulfilmentStateMachineTest`'s question, and whether each door applies it is
 * `DeliverySyriaOrderTransitionTest`'s and `SettledOrderPaymentEchoTest`'s. This file asks only:
 * **what does each caller see on the wire, and is it still what its own client expects?**
 */
final class OrderTransitionRefusalCodeContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ADMIN_DOOR = '/api/admin/orders/update-status';

    private const WEBHOOK = '/api/delivery-syria/orders/update-status';

    private const SECRET = 'H3-CONTRACT-FIXTURE-SECRET';

    private User $merchant;

    private Store $store;

    /** A CANCELLED order. Nothing leaves it through either door — `TERMINAL` holds 2 and 3. */
    private Order $settled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant(['deliverysyria_secret' => self::SECRET]);
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);

        $this->settled = $this->makeOrder($this->store, [
            'delivered_status' => 2,
            'payment_status' => 'Unpaid',
        ]);
    }

    /**
     * THE PREMISE. Both doors must actually REFUSE this move, or the codes below are the codes of
     * a success and this file proves nothing. Driven rather than assumed, because a fixture that
     * quietly succeeds is how a contract assertion passes for the wrong reason.
     */
    public function test_the_premise_that_both_doors_refuse_the_same_move(): void
    {
        $this->assertFalse((bool) $this->pushAdmin(1)->json('success'),
            'premise: the admin door no longer refuses Cancelled -> Delivered, so the code asserted '
            . 'below is a success code and H3 is being measured against nothing');

        $this->assertNotSame(200, $this->pushWebhook(1)->status(),
            'premise: the webhook no longer refuses Cancelled -> Delivered');
    }

    /**
     * THE ADMIN DOOR ANSWERS 200 WITH `success: false` — H3 option (a), until a Flutter release.
     */
    public function test_the_admin_door_answers_200_with_a_body_flag(): void
    {
        $response = $this->pushAdmin(1);

        $this->assertSame(200, $response->status(),
            'THE ADMIN DOOR\'S REFUSAL CODE CHANGED. Owner decision H3 (option a) holds it at HTTP '
            . '200 with `success: false` until the Flutter release that understands a 409 ships in '
            . 'the SAME coordinated change. The shipped app force-clears its session on any 401 and '
            . 'does not expect a 409 here, so a new code on this path is a user-visible regression. '
            . 'If the coordinated release is what you are doing, change this case in the same '
            . 'commit as the client and say so.');

        $this->assertFalse((bool) $response->json('success'),
            'the refusal is no longer carried by the `success` flag, which is the only thing the '
            . 'shipped client reads on this path');

        $this->assertNotNull($response->json('message'),
            'the refusal carries no message, so the app has nothing to show the merchant');
    }

    /**
     * THE WEBHOOK ANSWERS 409 — and that is not drift, it is the other half of the same decision.
     */
    public function test_the_webhook_answers_409(): void
    {
        $response = $this->pushWebhook(1);

        $this->assertSame(409, $response->status(),
            'THE WEBHOOK\'S REFUSAL CODE CHANGED. Owner decision H3 keeps it at 409: it is a '
            . 'server-to-server integration, the courier platform composes its own requests, and '
            . 'H3 names making this door answer 200 as the WRONG direction — it would hide a '
            . 'refusal from a machine client that has no reason to read a body flag.');
    }

    private function pushAdmin(int $status)
    {
        $bearer = $this->merchant->createToken('h3-contract')->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer ' . $bearer)
            ->postJson(self::ADMIN_DOOR, [
                'product_order_id' => $this->settled->product_order_id,
                'store_id' => $this->store->id,
                'delivered_status' => $status,
            ]);
    }

    private function pushWebhook(int $status)
    {
        return $this->postJson(
            self::WEBHOOK,
            ['product_order_id' => $this->settled->product_order_id, 'status' => $status],
            ['X-Platform' => 'deliverysyria', 'Authorization' => 'Bearer ' . self::SECRET]
        );
    }
}
