<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\DownloadGrant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * F217 — PAID DOWNLOADS ARE SERVED BY THIS CONTROLLER, AFTER AN ENTITLEMENT CHECK, OR NOT AT ALL.
 * =================================================================================================
 *
 * The owner's decision, 2026-10-30, HIGH PRIORITY: confirm order ownership or download entitlement;
 * support the registered buyer **and the guest**; *"never treat a guessable link as
 * authorisation"*; serve through a controller or a safe streaming service; an appropriate
 * `Content-Disposition`; `X-Content-Type-Options: nosniff`; path controls preventing traversal;
 * prevent unauthorised direct access to the **old** paid files; keep authorised public media
 * working; expose no file across customers or stores.
 *
 * WHAT THIS REPLACES. `AccountProfileController::downloadable_prodcut()` checked entitlement
 * correctly and then **emailed `get_file($path)`** — a URL under `public/uploads`, which is a
 * symlink to `../uploads`, so the web server serves it directly with no controller in the path. The
 * authorization gated *who may request the email*; the file itself was public. The stored name is
 * `rand(10, 100) . '_' . time() . '_' . $originalName`, i.e. 91 prefixes × a one-second timestamp ×
 * a filename the product page often reveals.
 *
 * TWO INDEPENDENT WAYS TO BE ENTITLED, and neither is the URL
 * -------------------------------------------------------------------------------------------------
 *   1. **The signed-in buyer.** `auth('customers')` plus the order's own `customer_id` and
 *      `store_id` — the same predicate `ownedOrder()` uses, re-asked HERE, at download time, rather
 *      than inherited from whoever requested a mail earlier.
 *   2. **A server-held grant** (`App\Services\DownloadGrant`), for the guest order that has no
 *      account to authenticate. Opaque, stored server-side, bound to the order, the store, the
 *      customer (0 for a guest) and the resolved path, with a TTL. Not a signed URL, because
 *      `APP_KEY` is in git history and unrotated, so anything derived from it is forgeable.
 *
 * THE PATH IS NEVER TAKEN FROM THE REQUEST. It is resolved from the ORDER'S OWN rows — the same
 * derivation `downloadLinkFor()` uses — and then checked against the grant. A caller supplies an
 * order id and maybe a grant; they never supply a filename. That is what makes traversal a
 * non-question rather than a filtered string, and it is this codebase's standing rule: derive the
 * target from the authenticated principal, never from request input.
 *
 * AND THE RESOLVED PATH IS STILL CONFINED, belt and braces, because the value comes from a database
 * column a merchant's upload wrote and this controller must not trust the database to be
 * well-formed either. `realpath()` must land inside the uploads root; a symlink or `..` that escapes
 * it is refused.
 *
 * WHAT THIS DOES NOT CLOSE, AND IT MUST BE SAID RATHER THAN IMPLIED.
 * **The old files remain reachable at their public URLs.** Paid downloadables and ordinary product
 * images are written to the **same directory** — `uploads/{store_id}/` — by the same
 * `Utility::upload_file()` call, so there is no path pattern that denies one without denying the
 * other, and the owner requires public media to keep working. Containing the existing files means
 * MOVING them and rewriting their database paths, which is a production data write and is
 * forbidden. So F217 is **not closed by this controller**; the remaining step is an operator action
 * documented in the runbook, and `php artisan downloads:exposure-report` names exactly which files
 * are affected. Recorded in the ledger rather than implied by a green test.
 */
class PaidDownloadController extends Controller
{
    /**
     * Stream one paid download for one order.
     *
     * Route: `GET {storeSlug?}/paid-download/{order}` with an optional `grant` query key.
     */
    public function show(Request $request, $slug, $order)
    {
        $store = getStore($slug);

        if (empty($store)) {
            return $this->refuse('store');
        }

        if (! is_scalar($order) || ! preg_match('/^\d+$/', (string) $order)) {
            /* A non-integer id is refused before it reaches the database. `WHERE id = '5abc'`
               returns row 5 on MySQL and no row on SQLite, which this project has already been
               bitten by: a guard that verified the requested VALUE while the handler matched a
               wider row set. */
            return $this->refuse('id');
        }

        $row = Order::where('id', (int) $order)->where('store_id', $store->id)->first();

        if ($row === null) {
            return $this->refuse('order');
        }

        /*
         * THE GRANT'S OWN PATH WINS WHERE THERE IS ONE, and that is a fix rather than a refinement.
         *
         * This read `downloadablePathFor($row)` alone, which returns the FIRST non-empty
         * downloadable across the order's rows. For an order with TWO different downloadable
         * products that meant the buyer always received the first one, whichever grant they
         * followed — the second was unreachable, and the grant's binding to a specific path did no
         * work at all. Found by an independent review.
         *
         * So a valid grant's path is used when one is presented, and it is still CHECKED rather
         * than trusted: `authorises()` compares a hash over canonical JSON of (store, path), and
         * `pathBelongsToOrder()` below requires the path to be one this order actually carries. A
         * grant naming a file from another order therefore buys nothing — the path has to satisfy
         * BOTH the grant and the order.
         */
        $grantedPath = $this->grantedPathFor($request, $store, $row);

        $path = $grantedPath ?? $this->downloadablePathFor($row);

        if ($path === null) {
            return $this->refuse('nothing to download');
        }

        if (! $this->entitled($request, $store, $row, $path)) {
            return $this->refuse('not entitled');
        }

        $absolute = $this->confinedAbsolutePath($path);

        if ($absolute === null) {
            return $this->refuse('path');
        }

        /*
         * `Content-Disposition: attachment` so a browser saves the file rather than rendering it,
         * which matters for every type the approved F190 list contains that a browser would
         * otherwise execute or display inline on this origin. `nosniff` so a mislabelled file is
         * not re-interpreted by content sniffing. Both are the owner's explicit requirements and
         * both are properties of THIS response — they could not be set at all while the file was
         * served by the web server from the document root.
         */
        /* `response()->download()` already sets `Content-Disposition: attachment`; an explicit
           `setContentDisposition()` on top of it was redundant AND named a constant that does not
           exist on `BinaryFileResponse` (it is on `ResponseHeaderBag`), which the tests caught. */
        return response()->download($absolute, basename($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Entitled by the signed-in buyer's own order, or by a server-held grant.
     *
     * BOTH limbs are asked against the ORDER row that was loaded by id+store, so neither can be
     * satisfied by naming a different store's order.
     */
    /**
     * The path a presented grant names, if that grant is valid AND the path is one this order
     * carries. Null otherwise, so the caller falls back to the order's first downloadable.
     *
     * Two independent conditions, deliberately: the grant proves somebody was ENTITLED to that
     * path, and `pathBelongsToOrder()` proves the path is THIS order's. Either alone would be
     * weaker — a grant for another order's file would otherwise select that file here, and the
     * confinement check further down only keeps it inside the uploads root, not inside this order.
     */
    private function grantedPathFor(Request $request, $store, Order $row): ?string
    {
        $grant = DownloadGrant::resolve($request->query(DownloadGrant::FIELD));

        if ($grant === null) {
            return null;
        }

        $path = (string) ($grant['path'] ?? '');

        if ($path === '' || ! DownloadGrant::authorises($grant, $store, $path)) {
            return null;
        }

        return $this->pathBelongsToOrder($row, $path) ? $path : null;
    }

    /** Is this path one of the order's own line items' downloadables? */
    private function pathBelongsToOrder(Order $row, string $path): bool
    {
        foreach ($this->downloadablePathsFor($row) as $candidate) {
            if (hash_equals($candidate, $path)) {
                return true;
            }
        }

        return false;
    }

    private function entitled(Request $request, $store, Order $row, string $path): bool
    {
        $customer = auth('customers')->user();

        if ($customer instanceof \App\Models\Customer
            && (int) $row->customer_id === (int) $customer->id
            && (int) $row->store_id === (int) $store->id
            && ($customer->store_id === null || (int) $customer->store_id === (int) $store->id)) {
            return true;
        }

        $grant = DownloadGrant::resolve($request->query(DownloadGrant::FIELD));

        if ($grant === null) {
            return false;
        }

        if ((int) $grant['order_id'] !== (int) $row->id) {
            return false;
        }

        /*
         * A grant issued to a SIGNED-IN buyer is not usable by a different signed-in buyer. For a
         * guest order the stored customer id is 0 and there is no second party to confuse, which
         * is the whole reason the grant exists.
         */
        if ((int) $grant['customer_id'] !== 0) {
            if (! $customer instanceof \App\Models\Customer
                || (int) $grant['customer_id'] !== (int) $customer->id) {
                return false;
            }
        }

        return DownloadGrant::authorises($grant, $store, $path);
    }

    /**
     * The order's own downloadable file, derived exactly as `downloadLinkFor()` derives it.
     *
     * Deliberately the same derivation rather than a second one: two ways of answering "what does
     * this order let you download" would drift, and the one that drifted would be the one serving
     * bytes.
     */
    /**
     * EVERY downloadable path this order carries, in line order.
     *
     * `downloadablePathFor()` returns the FIRST and is kept, because it is the right answer when no
     * grant names a path. This plural form exists so `pathBelongsToOrder()` can ask the real
     * question — *is this path one of THIS order's?* — rather than the weaker one the singular form
     * can answer. The duplication is one loop and the alternative was a flag argument that makes
     * both callers read worse.
     *
     * @return string[]
     */
    private function downloadablePathsFor(Order $row): array
    {
        $paths = [];
        $lines = json_decode((string) $row->product_json, true);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || empty($line['product_id'])) {
                continue;
            }

            if (! empty($line['variant_id'])) {
                $variantPath = ProductVariant::where('id', $line['variant_id'])
                    ->where('product_id', $line['product_id'])
                    ->where('store_id', $row->store_id)
                    ->value('downloadable_product');

                if (! empty($variantPath)) {
                    $paths[] = (string) $variantPath;
                }
            }

            $productPath = Product::where('id', $line['product_id'])
                ->where('store_id', $row->store_id)
                ->value('downloadable_product');

            if (! empty($productPath)) {
                $paths[] = (string) $productPath;
            }
        }

        return array_values(array_unique($paths));
    }

    private function downloadablePathFor(Order $row): ?string
    {
        $lines = json_decode((string) $row->product_json, true);

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (! is_array($line) || empty($line['product_id'])) {
                continue;
            }

            $path = null;

            if (! empty($line['variant_id'])) {
                $path = ProductVariant::where('id', $line['variant_id'])
                    ->where('product_id', $line['product_id'])
                    ->where('store_id', $row->store_id)
                    ->value('downloadable_product');
            }

            if (empty($path)) {
                $path = Product::where('id', $line['product_id'])
                    ->where('store_id', $row->store_id)
                    ->value('downloadable_product');
            }

            if (! empty($path)) {
                return (string) $path;
            }
        }

        return null;
    }

    /**
     * The absolute path, or null if it escapes the uploads root.
     *
     * The stored value is a relative path a merchant's upload wrote. This controller does not take
     * it from the request, so traversal is not the primary risk — but the database is not a trusted
     * input either, and a column holding `../../.env` would otherwise be served to an entitled
     * buyer. `realpath()` resolves `..` and symlinks; the prefix test is then meaningful.
     */
    private function confinedAbsolutePath(string $path): ?string
    {
        $root = realpath(base_path('uploads'));

        if ($root === false) {
            return null;
        }

        $candidate = realpath(base_path(ltrim($path, '/')));

        if ($candidate === false || ! is_file($candidate)) {
            return null;
        }

        /* The separator is appended so `/uploads-public` cannot pass a prefix test for
           `/uploads`. */
        if (! str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            Log::warning('paid download refused: resolved path escapes the uploads root', [
                'stored_path' => $path,
            ]);

            return null;
        }

        return $candidate;
    }

    /**
     * One refusal shape for every cause.
     *
     * 404 rather than 403, and the reason is never returned: the response must not say whether the
     * order exists, belongs to someone else, or has nothing to download. A distinguishable refusal
     * would turn this route into an oracle for order ids — the same reasoning
     * `ModuleResourceOwnershipGuard::deny()` records.
     */
    private function refuse(string $reason)
    {
        Log::warning('paid download refused', [
            'reason' => $reason,
            'customer' => optional(auth('customers')->user())->id,
        ]);

        abort(404);
    }
}
