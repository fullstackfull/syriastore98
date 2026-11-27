<?php

namespace App\Support\Loyalty;

/**
 * `MKT-005` — WHAT CAUSED THE NEXT BALANCE MOVEMENT. A ONE-SHOT, CUSTOMER-KEYED NOTE.
 * =================================================================================================
 *
 * {@see \App\Observers\CustomerLoyaltyPointObserver} is handed a `Customer` and nothing else. It can
 * see with certainty *that* a balance moved and *by how much* — but not *why*. For a dispute ("my
 * points went from 500 to 10") the cause is most of the answer, so {@see \App\Listeners\RecordLoyaltyPointAttribution}
 * leaves a note here when an order is about to move a balance, and the observer claims it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE NOTE AND NOT A WIDER EVENT
 * -------------------------------------------------------------------------------------------------
 *
 * The write is inside a VENDORED listener this project may not edit, and a first-party listener on
 * the same event runs STRICTLY EARLIER (module providers are brought in from `app->booted()`; see
 * `NormaliseRewardRedemptionFlag`'s docblock, where that ordering is measured). So "before" is the
 * only place first-party code can stand, and a note left for the write that follows is the only
 * shape available. The alternative — re-deriving the award figure from the order and the settings —
 * is the *"second copy of a decision the host already makes elsewhere"* the project skill forbids,
 * and it would drift the first time upstream changed a rounding.
 *
 * -------------------------------------------------------------------------------------------------
 * ITS TWO SAFETY PROPERTIES, BOTH DRIVEN BY TESTS
 * -------------------------------------------------------------------------------------------------
 *
 * 1. **KEYED ON THE CUSTOMER.** A note left for customer A can never be applied to customer B's
 *    movement. Mis-naming the cause of a money-adjacent movement is worse than naming none, and a
 *    single "current attribution" slot would do exactly that the moment two customers were written
 *    in one request.
 * 2. **CLAIMED ONCE.** {@see self::claimFor()} returns and FORGETS, so one order's note cannot stick
 *    to every later movement in the request. A note nobody claims — the ordinary case when the
 *    vendored module is not installed, so no balance moves at all — simply dies with the request.
 *
 * REQUEST-SCOPED BY BEING A CONTAINER SINGLETON, bound in `AppServiceProvider::register()`. Not
 * static state: a static would persist across tests in one process and across queued jobs in one
 * worker, which is the memoised-binding pollution `systematic-debugging`'s root-cause notes name for
 * this codebase.
 *
 * @see \App\Listeners\RecordLoyaltyPointAttribution
 */
final class LoyaltyAttribution
{
    /** @var array<int, array{order_id: int|null, redemption_claimed: bool, source: string}> */
    private array $notes = [];

    /**
     * Note that something is about to move this customer's balance.
     *
     * IDEMPOTENT. `NormaliseRewardRedemptionFlag` records that its own listener is registered twice
     * — once explicitly and once by Laravel's event discovery over `app/Listeners`, which CLAUDE.md
     * names as a measured source of double registration. Writing the same note twice must therefore
     * be indistinguishable from writing it once, and it is: the second write replaces the first with
     * identical content.
     */
    public function attribute(
        int $customerId,
        ?int $orderId,
        bool $redemptionClaimed,
        string $source,
    ): void {
        if ($customerId <= 0) {
            return;
        }

        $this->notes[$customerId] = [
            'order_id' => ($orderId !== null && $orderId > 0) ? $orderId : null,
            'redemption_claimed' => $redemptionClaimed,
            'source' => $source,
        ];
    }

    /**
     * Take this customer's note, if there is one, and forget it.
     *
     * @return array{order_id: int|null, redemption_claimed: bool, source: string}|null
     */
    public function claimFor(int $customerId): ?array
    {
        $note = $this->notes[$customerId] ?? null;

        unset($this->notes[$customerId]);

        return $note;
    }

    /**
     * Look without taking.
     *
     * Exists for PREMISE ASSERTIONS. A test that drives the event and then asserts on the ledger row
     * cannot tell "the listener ran and the attribution was applied" from "the listener never ran and
     * the unattributed path was measured" — both give a row. CLAUDE.md records that family of false
     * reading repeatedly, so the observer suite asserts the note exists *before* provoking the
     * movement.
     *
     * @return array{order_id: int|null, redemption_claimed: bool, source: string}|null
     */
    public function peekFor(int $customerId): ?array
    {
        return $this->notes[$customerId] ?? null;
    }
}
