<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-10 -- who is calling a DeliverySyria status endpoint, answered BEFORE any order is looked up.
 *
 * The two endpoints (`api/delivery-syria/orders/update-status`, `api/delivery-syria/status`) authenticate the provider with a per-merchant
 * bearer secret, `users.deliverysyria_secret`. They used to find the order first and compare the secret of that order's store owner second,
 * so the answer to "does this order id exist anywhere?" (404 vs 403) was available to anyone holding the public X-Platform header and any
 * token. Resolving the credential first turns the order lookup into something only an authenticated caller reaches -- and restricting that
 * lookup to the stores the credential owns means a VALID secret of store B tells its holder nothing about store A's ids either.
 */
class DeliverySyriaCredential
{
    /**
     * The ids of the stores a bearer token is entitled to act for; an empty list means "not a valid credential".
     *
     * Every stored secret is compared (hash_equals, no early exit), so the time taken does not depend on which merchant's secret matched or
     * whether any did. Read straight from the table: the answer must reflect the secret as it is NOW, not a model-cache entry.
     *
     * @return list<int>
     */
    public static function storeIdsFor(string $token): array
    {
        $token = trim($token);

        if ($token === '') {
            return [];
        }

        $owners = [];

        foreach (DB::table('users')->whereNotNull('deliverysyria_secret')->where('deliverysyria_secret', '!=', '')->get(['id', 'deliverysyria_secret']) as $row) {
            $stored = trim((string) $row->deliverysyria_secret);

            if (hash_equals($stored, $token)) {
                $owners[] = (int) $row->id;
            }
        }

        if ($owners === []) {
            return [];
        }

        return array_map('intval', DB::table('stores')->whereIn('created_by', $owners)->pluck('id')->all());
    }
}
