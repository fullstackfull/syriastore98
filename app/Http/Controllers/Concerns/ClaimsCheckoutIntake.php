<?php

namespace App\Http\Controllers\Concerns;

/**
 * ONE CHECKOUT-INTAKE CLAIM, SHARED BY EVERY DOOR THAT TURNS A BASKET INTO AN ORDER.
 * =================================================================================================
 *
 * F276 built this for the two storefront API writers. P4-F026 moved it here unchanged so the WEB
 * checkout can use the same claim, because the same defect was live there and the fix being in one
 * place and not the other is this codebase's most repeated mistake -- CLAUDE.md counts four
 * separate occasions on which a guard was written for the API and the web path left open, and the
 * comments inside `PaymentController::processOrder` name two more.
 *
 * MEASURED ON THE WEB PATH at d227b7c0, before this was shared: a second POST to
 * `{slug}/process-order` with the same cart rows wrote a SECOND order, decremented the stock a
 * SECOND time, cleared the cart again and flashed no error -- the shopper is told the checkout
 * succeeded twice and, for `cod`, a driver has two amounts to collect
 * (`WebCheckoutIntakeIdempotencyTest::test_a_repeated_web_checkout_post_does_not_write_a_second_order`).
 *
 * NOTHING IN THE MECHANISM IS WEB- OR API-SPECIFIC: the fingerprint is built from the store, the
 * customer and the cart ROWS, all three of which the two doors resolve the same way, and the claim
 * itself is `Cache::add()`, the single atomic put-if-absent this codebase already uses for F226,
 * F269 and F270.
 *
 * The docblocks below are the originals from `Api\ApiController`, kept verbatim because they
 * record what was measured rather than what was intended.
 */
trait ClaimsCheckoutIntake
{
    /** The value a storefront checkout claim holds while the order is being written. */
    private const STOREFRONT_INTAKE_WRITING = 'writing';

    /**
     * How long one storefront checkout's claim survives.
     *
     * Long enough to cover a phone that timed out and retried, and a shopper tapping Pay again
     * because nothing visibly happened. Short enough that a shopper genuinely buying the same
     * basket again minutes later is not refused -- and that case is not hypothetical for a
     * consumable.
     */
    private const STOREFRONT_INTAKE_CLAIM_SECONDS = 120;

    /**
     * F276 — E14's payment half, on the two storefront writers that had no control at all.
     *
     * The master prompt's Workstream E rule 14 is "no duplication of payments or inventory
     * movements". `GatewayReplayGuardCensusTest` — which PARSES the tree, after the previous
     * census was found wrong in every number it asserted — classified exactly three customer-order
     * writers as `client-api/no-idempotency-control`. F226 closed `createOrderViaApi`. These two
     * were left, with the census's own note reading "nothing makes a repeated post idempotent".
     *
     * MEASURED BEFORE IT WAS FIXED, on both writers: a second post of one checkout wrote a SECOND
     * order and decremented stock a SECOND time
     * (`StorefrontOrderIntakeIdempotencyTest::test_a_repeated_checkout_post_does_not_write_a_second_order`).
     *
     * AND THE RETRY IS THE ORDINARY CASE HERE, not the attack. These are the storefront API's
     * checkout endpoints; the client is a phone on a mobile network, where a request that timed
     * out client-side has still arrived. `ApiResponser::error()` answering HTTP 200 makes it
     * worse, because a client cannot tell a refusal from a success by status code alone.
     *
     * THE CART IS NOT THE CONTROL, which is the same lesson F269 recorded at the till. A completed
     * order clears the cart, so a bare second post usually finds nothing to sell — but two
     * requests in flight together each hold their own view of the basket, and neither sees the
     * other's clear. The claim therefore lives in the cache: `Cache::add()` is a single atomic
     * put-if-absent, an INSERT against the `cache` primary key on the production `database` store.
     * Same primitive as F226, F269 and F270: one idempotency mechanism in this codebase, not four.
     *
     * THE FINGERPRINT IS SERVER-DERIVED. The store comes from the route's slug and the customer
     * from `resolveStoreCustomer()` — the shared predicate CLAUDE.md names — never from
     * `$request->customer_id`, which is caller input and which THIS HANDLER was once exploitable
     * through. Two shoppers with identical baskets are separate claims because the customer
     * differs; the same shopper posting the same basket twice inside the window is the duplicate
     * this exists to stop.
     */
    private function storefrontIntakeFingerprint(array $products, int $storeId, int $customerId, $paymentType): string
    {
        $lines = [];

        foreach ($products as $line) {
            $line = is_object($line) ? (array) $line : (array) $line;

            $lines[] = [
                /* THE CART ROW ID IS IN THE KEY, and leaving it out was the same hole F269 left
                   at the till before the press id went in. Keyed on content alone, a shopper who
                   buys two widgets and then rebuilds the SAME basket and buys again inside the
                   window hashes identically -- so their second purchase is answered as a replay:
                   money taken by the gateway that follows, nothing recorded. A rebuilt cart has
                   NEW `carts.id` values, so it is a different claim; two requests racing read the
                   SAME rows, so they are still one claim. `cart_list` emits it per line
                   (ApiController:809), which is why no new plumbing is needed.

                   AND THE COST IT BUYS, because this note used to state only the benefit: a retry
                   that REBUILDS the basket before re-posting is no longer caught -- new row ids,
                   new claim, second order, second stock decrement. That is the original F276
                   shape, re-admitted for one specific client behaviour: a client that holds the
                   basket in local state, re-syncs it after a timeout and re-posts.

                   NOT LIVE TODAY, and the reason is worth stating exactly rather than
                   comfortably: CLAUDE.md records that no client in either repository calls the
                   storefront API, and the realistic sequential retry never reaches this claim at
                   all -- the first post deletes the cart rows, so the retry is refused by the
                   empty-basket check that PRECEDES the claim. If a storefront client ever
                   appears, the fix is a client-supplied submission nonce and NOT removing the
                   cart id: a caller-supplied value can only SPLIT the caller's own key space,
                   because the key still binds the route-resolved store and
                   `resolveStoreCustomer()`'s customer. That is safe in a way a caller-supplied
                   TENANT predicate never is, and it is a contract change. */
                'cart' => (string) ($line['cart_id'] ?? ''),
                'id' => (string) ($line['product_id'] ?? $line['id'] ?? ''),
                'variant' => (string) ($line['variant_id'] ?? ''),
                'qty' => (string) ($line['qty'] ?? $line['quantity'] ?? ''),
                'price' => (string) ($line['price'] ?? $line['final_price'] ?? ''),
            ];
        }

        /* Sorted, so one basket assembled in a different order is one basket. */
        usort($lines, fn ($a, $b) => [$a['cart'], $a['id'], $a['variant']] <=> [$b['cart'], $b['id'], $b['variant']]);

        return 'storefront_intake:' . hash('sha256', json_encode([
            'store' => $storeId,
            'customer' => $customerId,
            'payment' => is_scalar($paymentType) ? (string) $paymentType : '',
            'lines' => $lines,
        ]));
    }
}
