<?php

namespace Workdo\LynomiaChat\app\Support;

/**
 * HOW LYNOMIA KNOWS ONE OF THIS INSTALLATION'S STORES, in one place.
 *
 * Lynomia stores every connected store in `commerce_stores` with a GLOBAL unique index on
 * (provider, external_store_id). A bare store id is not unique across installations -- two
 * Store Syria deployments both have a store 1 -- so what travels is the installation's own
 * reference and the store id together, and that qualified value is what Lynomia saves,
 * returns and looks a store up by.
 *
 * WHY THIS CLASS EXISTS RATHER THAN THE EXPRESSION. It was written twice (the commerce
 * identity endpoint and the commerce event dispatcher) and then NOT written four more times:
 * the consent dispatcher, the consent projector's store check, the consent reconciler and
 * both audience-scope resolvers each used the BARE store id against a value that is never
 * bare. Every one of them failed silently and in a different way --
 *
 *   outbound consent decisions   Lynomia looked up `commerce_stores` by "1", found nothing,
 *                                and answered 404. Every checkout opt-in this integration
 *                                exists to carry was permanently rejected.
 *   inbound consent events       the projector compared Lynomia's "localhost:1" against "1",
 *                                read it as a delivery speaking for another installation,
 *                                and dropped it.
 *   reconciliation               404, so the sweep that repairs the two above repaired
 *                                nothing.
 *   audience and campaign scope  no match, so `commerce_store_id` was never sent and every
 *                                store-scoped count silently answered account-wide.
 *
 * -- and none of their tests could see it, because each fake used the bare id on both sides.
 * A format that four callers can get wrong independently is a format that needs one name.
 */
final class StoreIdentity
{
    /** The identifier Lynomia holds for one of this installation's stores. */
    public static function of(int $storeId): string
    {
        return (string) config('lynomia.installation_ref') . ':' . $storeId;
    }
}
