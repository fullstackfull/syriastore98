<?php

namespace App\Support\Commerce;

use App\Models\Order;

/**
 * DID THIS STORE'S CUSTOMER ACTUALLY BUY THIS PRODUCT? ONE DEFINITION, ONE PLACE.
 * =================================================================================================
 * `CUST-005` — «التقييمات الموثقة والأسئلة والمفضلة» / *verified reviews, questions and wishlists*
 * (`docs/development/master-plan/DECISION-ID-REGISTER.md`, section 03-B). The register records this
 * requirement as **half-tracked**: product questions appear once in the traceability matrix and
 * **verified reviews appear zero times**. This class is the measurable half of the missing half.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS IS, AND — IMPORTANTLY — WHAT IT IS NOT
 * -------------------------------------------------------------------------------------------------
 *
 * It is a READER. It answers a question of FACT about the `orders` table and writes nothing, gates
 * nothing and refuses nothing.
 *
 * **It is not the purchase GATE, and must not be turned into one here.** Whether a review *requires*
 * a completed purchase, and what becomes of the reviews already published without one, is
 * `GAP-312` / owner decision `J19` — a product rule, on a phase that is declared
 * engineering-complete. `Gap193ReviewPurchaseGateAndTenancyTest` pins the gate's absence on purpose.
 * So the storefront writers call this to RECORD what they found and still accept the review; the
 * day the owner answers `J19`, the gate is a single `if` in front of the same call.
 *
 * -------------------------------------------------------------------------------------------------
 * THE PREDICATE, CLAUSE BY CLAUSE, AND WHY EACH ONE IS EVIDENCE RATHER THAN TASTE
 * -------------------------------------------------------------------------------------------------
 *
 * 1. **`store_id` IS EXPLICIT AND IS THE WHOLE CONTROL.** `Order` carries **no** `StoreScope` and is
 *    absent from `config/tenancy.php`'s `scoped_models` (`CLAUDE.md` §1's table), so no global scope
 *    adds a predicate for any principal and `null_tenant => 'open'` never comes into it. An
 *    unpredicated read here would answer "has this id bought this product *anywhere on the
 *    platform*", which is a cross-tenant read of another store's order book.
 *
 * 2. **`is_guest = 0` IS REQUIRED, and this is a SECURITY clause, not a policy one.** A guest
 *    checkout's `orders.customer_id` is the id of the `Customer` the checkout *found or created from
 *    the billing e-mail the buyer typed* — `GAP-214` records that a guest who types a victim's
 *    address has that id adopted "with no proof of ownership". So a guest order's `customer_id` is
 *    an unproven claim and cannot verify anybody. `is_guest = 0` is set at exactly one condition in
 *    each checkout writer — `if (auth('customers')->check())` in
 *    `PaymentController::getProductStatus()` and `PaymentController::whatsapp()` — so it means
 *    *the account holder was signed in*, which is the thing "verified" needs.
 *
 *    THE METHOD, NOT THE LINE, and that is this project's convention rather than a preference:
 *    `CLAUDE.md` §3 records four of five `place_order:<n>` citations being stale, each by a
 *    different amount, and one workstream's citations going stale twice by the change that was
 *    citing them. A method name cannot drift and a grep finds it.
 *
 *    Consequence, recorded rather than hidden: a **POS** counter sale attributed to a customer by
 *    the merchant does not verify them either, because the merchant typed the attribution. That is
 *    the same unproven-claim class and the same answer.
 *
 * 3. **CANCELLED AND RETURNED ORDERS DO NOT VERIFY.** `orders.delivered_status` 2 is `cancelled` and
 *    3 is `return` (`App\Support\Api\OrderStatusContract::KEYS`, derived from the integer and never
 *    from a language file). A purchase that was undone is not a purchase.
 *
 * 4. **`payment_status` IS DELIBERATELY *NOT* IN THE PREDICATE, and the reason is measured in this
 *    repository.** Requiring `Paid` would mark every COD buyer unverified for good: the traceability
 *    matrix's `P04-COD-01` row records that `App\Services\CodCollectionService` writes to
 *    `cod_collection_events`, that table has **no migration** (it is still a `.draft` under
 *    `database/migrations/_gate_f_pending/`), so `isAvailable()` is false and **no COD order can
 *    ever be settled through the approved mechanism**; and `P04-COD-02` records that asserting
 *    `Paid` on a COD order at creation is refused outright by owner decision `D-06`. COD is a named
 *    Phase 04 scope element and a first-class Syrian payment method. A clause that silently excluded
 *    it would be a product decision disguised as a technicality.
 *
 *    It follows that an **unpaid, uncancelled, not-yet-delivered** online order verifies a purchase
 *    under this definition. That is the one clause of this predicate a reasonable owner might want
 *    tightened, it is named in the report as such, and {@see self::WITHDRAWN_FULFILMENT_STATUSES}
 *    plus {@see self::qualifyingOrders()} are the only two places a tightening would touch.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE LINE MATCH IS DONE IN PHP AND NOT IN SQL
 * -------------------------------------------------------------------------------------------------
 *
 * `orders` records its lines twice and neither is queryable portably. `orders.product_id` is a
 * **comma-joined string** of the distinct ids (`PaymentController::getProductStatus()` builds it
 * with `array_unique(...)` then `implode(',', ...)`), which needs `FIND_IN_SET` — absent from SQLite, and
 * `CLAUDE.md` §1 records that MySQL-only SQL in `selectRaw` is how 3,660 lines of `ReportController`
 * became structurally untestable. `orders.product_json` is a `text` blob. So the candidate set is
 * narrowed in SQL by the three indexed/cheap columns and the line match runs in PHP, which keeps the
 * whole thing reachable by the Feature suite.
 *
 * The decode mirrors `App\Models\Order::orderData()`'s own reader exactly — `$line['product_id'] ??
 * $line['id']`, and the single-line-not-a-list shape that reader also unwraps — because a second
 * spelling of "which key holds the product id" is how this codebase's writer families have
 * repeatedly drifted (`ReviewTargetOwnership`'s docblock is the worked example).
 *
 * @see \App\Support\Commerce\ReviewTargetOwnership  the sibling predicate on the same table
 * @see \Tests\Feature\Security\Gap193ReviewPurchaseGateAndTenancyTest  pins the GATE's absence
 * @see \Tests\Feature\Commerce\VerifiedPurchaseReviewTest  this class and its two writers
 */
final class VerifiedPurchase
{
    /**
     * `orders.delivered_status` values that withdraw the purchase: 2 `cancelled`, 3 `return`.
     *
     * Integers, matching `OrderStatusContract::KEYS`, because the column is an integer and the key
     * is derived from it. Not a translated label, and not a string: `delivered_status` is written as
     * an int by every checkout writer.
     */
    public const WITHDRAWN_FULFILMENT_STATUSES = [2, 3];

    /**
     * The id of the earliest order in which this store's customer bought this product, or null.
     *
     * Earliest rather than latest so the recorded value is STABLE: a later order for the same
     * product must not change what an already-published review says it was verified by.
     *
     * @param  mixed  $customerId  the reviewer's `customers.id`, derived from the authenticated
     *                             principal — NEVER from request input (`CLAUDE.md` §1)
     * @param  mixed  $productId   the product being reviewed, already store-verified by the caller
     * @param  mixed  $storeId     the store, from the route or the principal
     */
    public static function orderIdFor($customerId, $productId, $storeId): ?int
    {
        $customerId = self::positiveInt($customerId);
        $productId = self::positiveInt($productId);
        $storeId = self::positiveInt($storeId);

        if ($customerId === null || $productId === null || $storeId === null) {
            return null;
        }

        foreach (self::qualifyingOrders($customerId, $storeId) as $order) {
            if (self::orderContains($order, $productId)) {
                return (int) $order->id;
            }
        }

        return null;
    }

    /** Did this store's customer buy this product? The boolean form of {@see self::orderIdFor()}. */
    public static function isVerified($customerId, $productId, $storeId): bool
    {
        return self::orderIdFor($customerId, $productId, $storeId) !== null;
    }

    /**
     * The candidate orders: this store, this signed-in customer, not cancelled, not returned.
     *
     * `orderBy('id')` is the stability guarantee above. Only the three columns the line match needs
     * are selected — `product_json` is a `text` column and an order's blob is unbounded.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Order>
     */
    private static function qualifyingOrders(int $customerId, int $storeId)
    {
        return Order::query()
            ->where('store_id', $storeId)
            ->where('customer_id', $customerId)
            // Clause 2 of the docblock. `is_guest` is `integer` with default 0 and is written as
            // 0 or 1 by both checkout writers, so a loose comparison cannot admit a guest row.
            ->where('is_guest', 0)
            ->whereNotIn('delivered_status', self::WITHDRAWN_FULFILMENT_STATUSES)
            ->orderBy('id')
            ->get(['id', 'product_json', 'product_id']);
    }

    /**
     * Does this order's line list name the product?
     *
     * `product_json` first, because it is the authoritative per-line record; the comma-joined
     * `orders.product_id` is the fallback for rows whose json is absent, empty or unreadable (a NULL
     * `product_json` is a real shape — `Order::return()`'s own null guard records it).
     */
    private static function orderContains(Order $order, int $productId): bool
    {
        $lines = json_decode((string) $order->product_json, true);

        if (is_array($lines) && $lines !== []) {
            // `Order::orderData()` unwraps exactly this shape: a SINGLE line stored as an object
            // rather than a list of one. Same spelling, so the two cannot disagree.
            if (isset($lines['id']) || isset($lines['product_id'])) {
                $lines = [$lines];
            }

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineProductId = self::positiveInt($line['product_id'] ?? ($line['id'] ?? null));

                if ($lineProductId === $productId) {
                    return true;
                }
            }
        }

        // The CSV fallback. Split and compare as integers rather than with `in_array` on strings:
        // `'12'` and `'12 '` are the same product and `str_contains('112', '12')` is not a match.
        foreach (explode(',', (string) $order->product_id) as $candidate) {
            if (self::positiveInt($candidate) === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A strictly-validated positive integer, or null.
     *
     * `filter_var` rather than `is_numeric` or a cast, for the reason `ReviewTargetOwnership`
     * records: MySQL coerces `'5abc'` to 5 in a `WHERE`, and a cast turns junk into 0 — and 0 is a
     * `customers.id` nobody has and an `orders.customer_id` every guest row carries by default.
     */
    private static function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) && ! is_float($value)) {
            return null;
        }

        $int = filter_var(is_float($value) ? (string) $value : trim($value), FILTER_VALIDATE_INT);

        return ($int === false || $int <= 0) ? null : $int;
    }
}
