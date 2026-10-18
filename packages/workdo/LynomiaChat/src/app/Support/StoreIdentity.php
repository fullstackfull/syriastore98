<?php

namespace Workdo\LynomiaChat\app\Support;

use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

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
    /**
     * The identifier Lynomia holds for one of this installation's stores.
     *
     * READ BACK, NOT RECOMPUTED. This used to compose the value from
     * `config('lynomia.installation_ref')` on every call, and that setting falls back to the
     * host of `APP_URL`. Lynomia PERSISTS what it is told; this side did not, so a domain
     * change or one deploy target without `LYNOMIA_INSTALLATION_REF` moved every answer at
     * once and the integration failed closed and silently on all three paths that use it --
     * outbound decisions 404 and were retired, reconciliation 404'd, and inbound events were
     * rejected as another installation's. The value is now whatever was in force when this
     * store first told Lynomia who it was, which is the only value Lynomia will match.
     *
     * Memoised in the CONTAINER rather than in a static, for the reason
     * `activated_modules_for_owner()` is: a static outlives the application instance while
     * the store ids it is keyed on do not, so a suite that rebuilds the app and restarts the
     * auto-increment would answer one store's identity for another's.
     */
    public static function of(int $storeId): string
    {
        $key = "lynomia.store_identity.{$storeId}";

        if (! app()->bound($key)) {
            app()->scoped($key, fn () => self::resolve($storeId));
        }

        return app($key);
    }

    /**
     * The identity WITHOUT materialising one, for a reader that must not write.
     *
     * `of()` is a lazy writer: the first caller for a store persists the composed value, because
     * that value is what Lynomia matches the store by forever after and it must not be able to
     * move. That is right for every path that is about to TELL Lynomia something, and wrong for a
     * reader -- the reconciliation sweep's `--dry-run` promised to change nothing and then pinned
     * the identity of every provisioned link it looked at, before the HTTP call and therefore even
     * for links it went on to skip.
     *
     * Returns null when nothing has been recorded, which a reader should treat as "this store has
     * never told Lynomia who it is" rather than guessing -- a computed value here would be a guess
     * that the next real caller might not agree with.
     */
    public static function known(int $storeId): ?string
    {
        $stored = (string) (LynomiaStoreLink::where('store_id', $storeId)->value('commerce_external_store_id') ?? '');

        return $stored === '' ? null : $stored;
    }

    /**
     * Stored if we have it; otherwise composed once and kept.
     *
     * The lazy write is what makes this safe to deploy over existing links, which all
     * predate the column: the first caller after the upgrade stores exactly the value the
     * old code would have produced, so nothing moves, and nothing can move afterwards.
     * A store with no link row at all cannot have registered with Lynomia, so there is
     * nothing to remember and the computed value stands.
     */
    private static function resolve(int $storeId): string
    {
        $link = LynomiaStoreLink::where('store_id', $storeId)->first();
        $stored = (string) ($link->commerce_external_store_id ?? '');

        if ($stored !== '') {
            return $stored;
        }

        $computed = (string) config('lynomia.installation_ref') . ':' . $storeId;
        $link?->forceFill(['commerce_external_store_id' => $computed])->save();

        return $computed;
    }
}
