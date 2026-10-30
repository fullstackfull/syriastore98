<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * F217 — A SERVER-HELD GRANT FOR ONE PAID DOWNLOAD, SO AN EMAILED LINK IS NOT A PUBLIC FILE.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30: *"never treat a guessable link as authorisation… serve through a
 * controller or a safe streaming service… support the registered buyer AND the guest under a safe
 * purchase model."*
 *
 * WHAT WAS ACTUALLY WRONG, because it was not the absence of an entitlement check.
 * `AccountProfileController::downloadable_prodcut()` already checks entitlement properly —
 * `shopper($store)` then `ownedOrder($store, $customer, $request->order_id)`, with P1-ORD-10's
 * reasoning recorded in place. It then **emails `get_file($path)`**, a URL under
 * `public/uploads` — which is a symlink to `../uploads`, so the web server serves it directly.
 *
 * So the authorization gated **who may request the email**, and the file itself stayed public. The
 * stored name is `rand(10, 100) . '_' . time() . '_' . $originalName` — **91 prefixes × a one-second
 * timestamp × a filename the product page often reveals.** Anyone who guesses it, or is forwarded
 * the mail, downloads paid goods with no order and no account.
 *
 * WHY AN OPAQUE SERVER-HELD RECORD AND NOT A SIGNED URL
 * -------------------------------------------------------------------------------------------------
 * The same reason `VariantRemovalConsent` gives, and it is not a preference: **`APP_KEY` is in git
 * history and unrotated**, which this repository already records as why `Crypt::encrypt()` is an
 * encoding rather than a control. `URL::temporarySignedRoute()` derives from that same key, so a
 * signed download URL would be forgeable by anyone with the repository's history. The secret has to
 * be **the stored record**, as `OrderCompleteBinding` decided for order ids and
 * `CustomerPasswordResetService` for resets.
 *
 * THE GUEST IS WHY THE GRANT EXISTS AT ALL. A registered buyer can be authorised at download time
 * by the check that already exists. A guest order (`orders.is_guest`) has no account to authenticate,
 * so the only thing that can carry entitlement to the guest is something sent to the address that
 * placed the order. That is this grant: issued when an entitled request is made, mailed to the
 * order's own billing address, and verified server-side on use.
 *
 * WHAT IT IS BOUND TO, and each binding earns its place:
 *   - **the order id** — so it cannot be moved to another order.
 *   - **the store id** — so it cannot cross a tenant boundary even if ids coincide.
 *   - **the customer id, or 0 for a guest** — so a grant issued to one shopper is not usable by
 *     another signed-in shopper, while a guest order still works.
 *   - **the resolved file path** — so that even if the order later gains another downloadable line,
 *     this grant still only opens the file it was issued for.
 *   - **a TTL** — so a forwarded or archived mail stops working. Downloads are not single-use:
 *     a buyer may legitimately re-download, and burning the grant on first use would turn a flaky
 *     connection into a lost purchase. **The bound TTL is the limit, not the use count** — which is
 *     the one place this deliberately differs from `VariantRemovalConsent`, where single use is the
 *     whole point.
 *
 * LOWERCASE HEX, for the reason F205 measured: request strings pass through middleware that rewrites
 * them, and a token containing `javascript`, `alert`, `&lt;` or `&gt;` would arrive mangled with no
 * way for the holder to discover why. Hex cannot.
 *
 * AND THE COMPARISON IS `hash_equals` over a canonical encoding, because this one IS a secret.
 */
final class DownloadGrant
{
    /** The query/route key the download route reads. */
    public const FIELD = 'grant';

    private const PREFIX = 'ssdlg:';

    /**
     * Issue a grant for exactly this order and file.
     *
     * @return string the opaque token to put in the link
     */
    public static function issue($order, string $path, $customerId = null): string
    {
        $token = bin2hex(random_bytes(20));

        Cache::store(self::store())->put(
            self::key($token),
            [
                'order_id' => (int) ($order->id ?? 0),
                'store_id' => (int) ($order->store_id ?? 0),
                'customer_id' => (int) ($customerId ?? 0),
                'path' => $path,
            ],
            now()->addMinutes(self::ttlMinutes())
        );

        return $token;
    }

    /**
     * The stored grant, or null. NOT consumed — see the class docblock on why downloads are
     * re-usable within the TTL where a consent is not.
     *
     * @return array{order_id:int,store_id:int,customer_id:int,path:string}|null
     */
    public static function resolve($token): ?array
    {
        if (! is_string($token) || $token === '') {
            return null;
        }

        $token = strtolower(trim($token));

        if (! preg_match('/^[0-9a-f]{40}$/', $token)) {
            return null;
        }

        $record = Cache::store(self::store())->get(self::key($token));

        if (! is_array($record) || ! isset($record['path'], $record['order_id'], $record['store_id'])) {
            return null;
        }

        return [
            'order_id' => (int) $record['order_id'],
            'store_id' => (int) $record['store_id'],
            'customer_id' => (int) ($record['customer_id'] ?? 0),
            'path' => (string) $record['path'],
        ];
    }

    /**
     * Does this grant authorise this store and this path?
     *
     * `hash_equals` on a canonical encoding of the whole tuple rather than field-by-field `===`:
     * one comparison, constant time, and no limb can be forgotten.
     */
    public static function authorises(array $grant, $store, string $path): bool
    {
        return hash_equals(
            hash('sha256', json_encode([
                'store_id' => (int) ($store->id ?? -1),
                'path' => $path,
            ])),
            hash('sha256', json_encode([
                'store_id' => (int) $grant['store_id'],
                'path' => (string) $grant['path'],
            ]))
        );
    }

    private static function key(string $token): string
    {
        /* The plaintext is never a cache key: a dump of the store would otherwise hand over every
           live download grant. Same reason `CustomerPasswordResetService` hashes its key. */
        return self::PREFIX . hash('sha256', $token);
    }

    /**
     * NOT the default cache store, and this is measured rather than cautious — see
     * `config/consent.php` for the full account. `laravel-model-caching` flushes on every model
     * save, and because `DatabaseStore` is not taggable that flush is `Cache::flush()` over the
     * WHOLE default store: one `Setting::updateOrCreate()` erased 4 of 4 seeded keys on MariaDB in
     * this repository's own script. A grant in the default store would be erased by any model save
     * anywhere between the mail going out and the buyer clicking it.
     *
     * The residual is the same one `config/consent.php` states: a file-backed record is per-server.
     * Set `DOWNLOAD_GRANT_STORE` to a shared store where one exists. Nothing here writes to
     * production.
     */
    private static function store(): ?string
    {
        $store = config('downloads.grant_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private static function ttlMinutes(): int
    {
        $minutes = (int) config('downloads.grant_ttl_minutes', 60 * 24 * 7);

        return $minutes > 0 ? $minutes : 60 * 24 * 7;
    }
}
