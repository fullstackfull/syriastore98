<?php

namespace App\Support\Audit;

use App\Models\Store;

/**
 * WHICH STORE AN AUDITED ACTION BELONGS TO -- AND WHEN THE HONEST ANSWER IS "NONE".
 *
 * `AuditLog::record()` took its `store_id` from `getCurrentStore()`, and that helper FABRICATES A
 * TENANT for the platform operator: `app/Helper/helper.php:888-889` is
 * `if ($user->type == 'super admin') { return 1; // Default store for super admin }`.
 *
 * So every audit row written by the operator was filed under store 1 **regardless of whose data
 * changed**. Two consequences:
 *
 *  - Auditing merchant Y's history MISSES every operator action taken against Y. The trail is
 *    incomplete exactly where it matters most -- the actions a tenant cannot take themselves.
 *  - Store 1 is a real store. Its audit history accumulates rows describing actions against OTHER
 *    merchants' data. Today that is contained, because there is no merchant-facing audit reader
 *    (measured: `AuditLog` appears in three controllers and all five references are WRITES), so it
 *    is a trail-integrity defect and NOT a cross-tenant disclosure. It becomes one the day a reader
 *    is added, which is why `AuditReaderAuthorizationTest` ratchets that.
 *
 * `getCurrentStore()` IS NOT CHANGED, and that is a scope decision rather than timidity: it has
 * **1,024 call sites in `app/`** (counted), and the operator's `1` is load-bearing for an unknown
 * number of them. The fix belongs at the audit door, which is the one place that needs a truthful
 * answer rather than a usable one.
 *
 * THE RULE, in the owner's own order of precedence:
 *
 *  1. IF THE SUBJECT CARRIES ITS OWN `store_id`, THAT WINS. The owner's instruction is explicit:
 *     "Do not infer the affected tenant solely from an operator's current UI selection when the
 *     modified resource identifies a different store." `PlanOrder` carries a nullable `store_id`
 *     (`2023_12_11_102124_create_plan_orders_table.php:36`), so the three plan-order actions now
 *     file against the order's own store instead of the actor's session.
 *  2. OTHERWISE THE OPERATOR GETS `null`, not 1. An operator action with no store-bearing subject
 *     is platform-wide, and `null` says so without inventing a tenant. The column is nullable by
 *     design (`audit_logs.store_id`, migration line 87).
 *  3. OTHERWISE A MERCHANT OR STAFF MEMBER GETS THEIR RESOLVED STORE, which for them is
 *     unambiguous and is what `getCurrentStore()` already answered correctly -- `current_store`
 *     when set, else their single owned store. Reproduced here rather than delegated, so the
 *     operator fabrication cannot leak back in through a helper change.
 *  4. NO AUTHENTICATED `web` USER -> `null`. The console, a queue job, and a storefront request
 *     with no panel session all land here. CLAUDE.md: `auth()->user()` reads the DEFAULT guard, so
 *     a shopper or a driver is not a `User` and is correctly not a store attribution.
 *
 * WHAT THIS DOES NOT DO: it does not rewrite a single existing row. Whether the historical rows
 * filed under store 1 should be re-attributed is an owner decision about live data, and the
 * information needed to do it correctly -- which store each past action actually concerned -- is in
 * most cases no longer recoverable from the row itself.
 */
final class AuditAttribution
{
    private function __construct()
    {
    }

    /**
     * The store an action concerns, or null when it concerns no single store.
     *
     * @param  mixed  $subject  the audited model, or null
     * @param  mixed  $actor    the acting `User`, or null outside a panel session
     */
    public static function storeFor(mixed $subject = null, mixed $actor = null): ?int
    {
        $fromSubject = self::storeOfSubject($subject);

        if ($fromSubject !== null) {
            return $fromSubject;
        }

        if ($actor === null || ! $actor instanceof \App\Models\User) {
            return null;
        }

        /*
         * THE OPERATOR IS NOT A TENANT. Normalised the way CLAUDE.md requires -- operator status is
         * a string comparison on `users.type` and the stored value's case and padding are not
         * guaranteed, so `strtolower(trim(...))` rather than `==`.
         */
        if (strtolower(trim((string) $actor->type)) === 'super admin') {
            return null;
        }

        $current = $actor->current_store;

        if (! empty($current)) {
            return (int) $current;
        }

        $store = Store::where('created_by', $actor->id)->first();

        return $store === null ? null : (int) $store->id;
    }

    /**
     * The `store_id` the audited resource names for itself, or null when it names none.
     *
     * Read through `getAttribute()` rather than `->store_id` so a model without the attribute
     * answers null instead of raising, and guarded with `isset` on the attributes array so a
     * model whose `store_id` is genuinely null is not confused with one that has no such column.
     */
    private static function storeOfSubject(mixed $subject): ?int
    {
        if (! $subject instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }

        $value = $subject->getAttribute('store_id');

        return ($value === null || $value === '') ? null : (int) $value;
    }
}
