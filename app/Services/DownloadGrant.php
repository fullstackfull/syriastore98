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
    /**
     * Is this installation serving uploads from the LOCAL document root?
     *
     * F217's exposure is specifically that `public/uploads` is a symlink to `../uploads`, so a
     * paid downloadable written to local storage sits under the web root and is fetchable by URL.
     * THAT IS A LOCAL-STORAGE PHENOMENON. When the installation is configured for wasabi or s3 the
     * file was never in the document root, and the link `get_file()` builds is the provider's own
     * URL — whose access control belongs to the bucket policy, not to this application.
     *
     * So `PaidDownloadController` serves local files ONLY: it resolves an absolute path under the
     * uploads root and hands it to `response()->download()`, which cannot serve an object in a
     * bucket. Routing a remote-storage merchant through it would 404 every paid download they have
     * ever sold — measured, as a real regression in
     * `DownloadableProductMailTest::test_on_remote_storage_the_mailed_link_names_the_bucket`,
     * which is in the suite precisely because that path was already a live configuration.
     *
     * Hence this predicate, and hence the honest limit: **remote storage keeps the behaviour it
     * had.** A bucket URL with no expiry is still a guessable link, which is the same CLASS of
     * weakness F217 closes locally, and closing it means a signed temporary URL
     * (`Storage::disk(...)->temporaryUrl()`). That cannot be verified from this container — there
     * are no bucket credentials here — and shipping an unverified change on the paid-download path
     * is worse than recording the gap. It is recorded as F217's remote sibling.
     *
     * Reads the settings exactly the way `get_file()` does, including its measured guards: an
     * absent super admin yields `[]`, and an absent `storage_setting` key means local. Both of
     * those were real 500s and blank-URL defects, so the resolution is deliberately identical
     * rather than re-derived.
     */
    public static function servesFromLocalDocumentRoot(): bool
    {
        try {
            $admin = \App\Models\User::where('type', 'super admin')->first();

            $settings = $admin
                ? \App\Models\Setting::where('store_id', $admin->current_store)
                    ->pluck('value', 'name')
                    ->toArray()
                : [];

            $setting = strtolower(trim((string) ($settings['storage_setting'] ?? '')));

            return $setting === '' || $setting === 'local';
        } catch (\Throwable $settingsAreUnreadable) {
            /*
             * Fail towards LOCAL, which is the safe direction: a local link is served through the
             * entitlement controller. Failing towards remote would mail out a raw path.
             */
            return true;
        }
    }

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
        /*
         * `$store` ACCEPTS AN OBJECT OR AN ID, and that is a fix rather than a convenience.
         *
         * This read used to be `(int) ($store->id ?? -1)` alone. Reading `->id` on an `int` yields
         * null in PHP, so an integer argument silently became -1 and the comparison failed. FAILING
         * CLOSED is the right direction -- a caller who passes the wrong shape is refused, not
         * admitted -- but failing closed SILENTLY is how a caller comes to believe it checked
         * something it did not. Measured: a test passing `(int) $store->id` got a refusal that
         * looked exactly like a path mismatch.
         *
         * So both shapes resolve, and anything else still lands on -1, which no grant can match.
         */
        $storeId = is_object($store)
            ? (int) ($store->id ?? -1)
            : (is_numeric($store) ? (int) $store : -1);

        return hash_equals(
            hash('sha256', json_encode([
                'store_id' => $storeId,
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
