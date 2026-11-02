<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * F205 — A VERIFIABLE CONSENT FOR THE BULK VARIANT REMOVAL, ISSUED AND CHECKED BY THE SERVER.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30: replace the plain boolean with a confirmation bound to the
 * user's identity and store, the target product, the variant IDs to be deleted **in a stable
 * order**, the product state that was reviewed, and a **limited time window** — *"a consent token
 * the server issues and verifies, that cannot be forged by sending `confirm=1`."*
 *
 * WHY THIS IS A SERVER-HELD RECORD AND NOT A SIGNED TOKEN
 * -------------------------------------------------------------------------------------------------
 * There is nothing honest to sign with. `APP_KEY` is in git history and unrotated, which this
 * repository already records as the reason `Crypt::encrypt()` is an encoding rather than a control;
 * `URL::temporarySignedRoute` derives from the same key and has no first-party use; every
 * first-party `hash_hmac` is keyed on a *payment gateway's* secret, not ours. The one sound HMAC
 * primitive in the tree, `EventSignature`, lives in a vendored package and reads a master secret
 * that has no equivalent in `config/` for application code.
 *
 * So the secret is **the stored record**, not anything derivable from a leaked key — the same
 * decision `OrderCompleteBinding` already made for order ids, and the same shape
 * `CustomerPasswordResetService` uses: an opaque random id, a server-held payload, a TTL, and a
 * single use. A stateless signature could not be *consumed*, and single-use is what stops a
 * captured token from authorising a second, different removal.
 *
 * WHERE THE RECORD LIVES, AND WHY NOT THE DEFAULT CACHE
 * -------------------------------------------------------------------------------------------------
 * **Not the default store, and this is measured rather than cautious.** `laravel-model-caching`
 * flushes on every model save, and because `DatabaseStore` is not taggable that flush is
 * `Cache::flush()` over the WHOLE default store: one `Setting::updateOrCreate()` erased 4 of 4
 * seeded keys on MariaDB 10.11.14 in this repository's own verification script. `Product` and
 * `ProductVariant` are both `Cachable`. A consent record in the default store would be erased by
 * any model save anywhere in the installation between issue and verify — near-certain in a
 * multi-tenant SaaS — and the merchant would be refused on a correct retry with no diagnosable
 * cause.
 *
 * The store is therefore **named by config** (`config/consent.php`), defaulting to `file`, which
 * is a separate store and is not touched when the default one is flushed.
 *
 * **THE RESIDUAL IS A DEPLOYMENT DECISION AND IS STATED RATHER THAN HIDDEN:** a file-backed record
 * is per-server. In a multi-server deployment without sticky sessions, the issue and the verify can
 * land on different machines and the merchant is refused on a correct retry. The honest fixes are a
 * shared store (Redis), or activating `MODEL_CACHE_STORE=model_cache` so the default store stops
 * being flushed wholesale (the F194 runbook), or a dedicated table — and that last one is a
 * persistent migration, which is an owner escalation under this project's standing rules. Set
 * `CONSENT_STORE` to a shared store when one exists. Nothing here writes to production.
 *
 * WHAT THE TOKEN IS BOUND TO, AND WHY EACH BINDING IS THERE
 * -------------------------------------------------------------------------------------------------
 *   - **user id** — so a token issued to one employee cannot be replayed by another.
 *   - **store id** — so it cannot cross a tenant boundary even if the ids coincide.
 *   - **product id** — so it cannot authorise a removal on a different product.
 *   - **the variant ids, sorted** — so it authorises exactly the set that was shown. Sorting is
 *     not tidiness: `variantsBeingRemoved()` carries **no `ORDER BY`**, so the row order is
 *     engine-defined and an unsorted hash would be rejected at random on MySQL/MariaDB.
 *   - **a state hash over those rows' price, sale price, stock and SKU** — so a token stops being
 *     valid the moment the data the merchant reviewed changes. `ss_last_seen_at` CANNOT serve this
 *     purpose and that was measured, not assumed: with `products.updated_at` parked in 2020, a
 *     variant save, a variant delete and the bulk delete each left it untouched — there is no
 *     `$touches` and no observer. The optimistic lock is blind to the variant set it is being
 *     asked to protect, and it fails open on a missing field besides.
 *   - **a TTL** — so a token left in a stale browser tab stops working.
 *
 * THE TOKEN IS LOWERCASE HEX, DELIBERATELY. `product.update` carries the `xss` middleware, which
 * rewrites every incoming string — `htmlspecialchars_decode()` then stripping `&lt;`, `&gt;`,
 * `javascript` and `alert`. A `Str::random()` or base64 token can contain those substrings and
 * would arrive mangled, leaving the merchant with no value they could send and no way to discover
 * why. Hex cannot.
 *
 * AND THE COMPARISON IS `hash_equals`, because this one IS a secret — unlike the per-variant name
 * echo, which a caller is *supposed* to be able to read off the row.
 */
final class VariantRemovalConsent
{
    /** The request field. A new key beside the boolean, never the boolean's own. */
    public const FIELD = 'ss_variant_removal_consent';

    private const PREFIX = 'ssvrc:';

    /**
     * Issue a consent for exactly this principal, product and removal set.
     *
     * @param  Collection<int,object>  $removed  the rows `variantsBeingRemoved()` returned
     * @return array{confirm_with:string,confirm_value:string,expires_in:int}
     */
    public static function issue($user, $product, Collection $removed): array
    {
        $token = bin2hex(random_bytes(16));

        Cache::store(self::store())->put(
            self::key($token),
            [
                'user_id' => (int) ($user->id ?? 0),
                'store_id' => (int) ($product->store_id ?? 0),
                'product_id' => (int) ($product->id ?? 0),
                'variant_ids' => self::variantIds($removed),
                'state' => self::stateHash($removed),
            ],
            now()->addMinutes(self::ttlMinutes())
        );

        return [
            'confirm_with' => self::FIELD,
            'confirm_value' => $token,
            'expires_in' => self::ttlMinutes() * 60,
        ];
    }

    /**
     * Is this the consent the server issued, for this principal, this product and this exact set?
     *
     * CONSUMED ON A MATCH, so one token authorises one removal. A mismatch does NOT consume it:
     * a merchant whose set changed under them should be able to read the fresh refusal rather than
     * also lose the token they were holding.
     *
     * @param  Collection<int,object>  $removed
     */
    public static function verify($token, $user, $product, Collection $removed): bool
    {
        if (! is_string($token) || $token === '') {
            return false;
        }

        /* Normalised the same way the per-variant echo is, so whatever the request pipeline did on
           the way in is undone identically here. Hex survives it, but the symmetry is the point. */
        $token = strtolower(trim($token));

        if (! preg_match('/^[0-9a-f]{32}$/', $token)) {
            return false;
        }

        $record = Cache::store(self::store())->get(self::key($token));

        if (! is_array($record)) {
            return false;
        }

        $expected = [
            'user_id' => (int) ($user->id ?? 0),
            'store_id' => (int) ($product->store_id ?? 0),
            'product_id' => (int) ($product->id ?? 0),
            'variant_ids' => self::variantIds($removed),
            'state' => self::stateHash($removed),
        ];

        /* `hash_equals` on a canonical encoding of the whole record rather than field-by-field
           `===`: one comparison, constant time, and no limb can be forgotten. */
        $ok = hash_equals(
            hash('sha256', json_encode($expected)),
            hash('sha256', json_encode([
                'user_id' => (int) ($record['user_id'] ?? -1),
                'store_id' => (int) ($record['store_id'] ?? -1),
                'product_id' => (int) ($record['product_id'] ?? -1),
                'variant_ids' => $record['variant_ids'] ?? [],
                'state' => (string) ($record['state'] ?? ''),
            ]))
        );

        if ($ok) {
            Cache::store(self::store())->forget(self::key($token));
        }

        return $ok;
    }

    /**
     * The variant ids, SORTED — the stable order the owner's decision requires.
     *
     * @param  Collection<int,object>  $removed
     * @return list<int>
     */
    public static function variantIds(Collection $removed): array
    {
        $ids = $removed->pluck('id')->map(static fn ($id) => (int) $id)->all();
        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    /**
     * A hash of what the merchant was actually shown about each row.
     *
     * The columns are the ones the refusal message itself names — the name, the SKU, the price and
     * the stock — so "the state that was reviewed" means the state they read, not an internal
     * revision counter they never saw. Sorted by id first, for the same reason the id list is.
     *
     * @param  Collection<int,object>  $removed
     */
    public static function stateHash(Collection $removed): string
    {
        $rows = $removed
            ->map(static fn ($v) => [
                'id' => (int) ($v->id ?? 0),
                'variant' => (string) ($v->variant ?? ''),
                'sku' => (string) ($v->sku ?? ''),
                'variation_price' => (string) ($v->variation_price ?? ''),
                'price' => (string) ($v->price ?? ''),
                'stock' => (string) ($v->stock ?? ''),
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return hash('sha256', json_encode($rows));
    }

    private static function key(string $token): string
    {
        /* The plaintext is never a cache key: a dump of the store would otherwise hand over every
           live consent. Same reason `CustomerPasswordResetService` hashes its key. */
        return self::PREFIX . hash('sha256', $token);
    }

    private static function store(): ?string
    {
        $store = config('consent.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private static function ttlMinutes(): int
    {
        $minutes = (int) config('consent.ttl_minutes', 15);

        return $minutes > 0 ? $minutes : 15;
    }
}
