<?php

namespace App\Http\Controllers;

use App\Services\FavouritePages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-06-7 — the merchant marks a page as a favourite.
 *
 * ONE INPUT, AND IT IS A KNOWN IDENTIFIER. The request carries a navigation `name` and nothing
 * else: no user id, no store id, no URL. `FavouritePages::toggle()` derives the principal from
 * `auth()` and the tenant from `getCurrentStore()`, and refuses any name that is not currently in
 * that principal's gated navigation. So the worst a caller can do with this endpoint is favourite
 * a page they can already open.
 *
 * WHICH IS WHY THERE IS NO PERMISSION MIDDLEWARE ON THE ROUTE. The authorisation is per PAGE, not
 * per endpoint: an employee who may open Products and not Orders must be able to favourite the
 * first and be refused the second, which a single route-level permission cannot express. Same
 * shape as `MerchantNotificationController`, where the authorisation is by notification type.
 *
 * POST, because it changes state. A state-changing GET is reachable from a third-party `<img>`
 * and by link prefetching -- F12 and F14 on this phase's ledger.
 */
class MerchantPageShortcutController extends Controller
{
    public function toggle(Request $request): JsonResponse
    {
        $page = (string) $request->input('page', '');

        /* ONE CALL, AND IT DECIDES EVERYTHING. The first version asked `permits()` here and then
           called `toggle()`, which asks again -- and each ask rebuilt the whole navigation. It
           also mapped a `false` return to "Removed from your favourites.", so a refused page, a
           full list and an unresolvable store were all reported to the merchant as a removal.
           `toggle()` now names its outcome and this maps each one to its own answer. */
        $outcome = FavouritePages::toggle($page);

        if ($outcome === FavouritePages::REFUSED) {
            return response()->json([
                'status' => 0,
                'message' => __('That page is not available to you.'),
            ], 403);
        }

        if ($outcome === FavouritePages::FULL) {
            return response()->json([
                'status' => 0,
                'favourited' => false,
                'message' => __('You can keep up to :count favourites. Remove one first.', [
                    'count' => FavouritePages::LIMIT,
                ]),
            ], 422);
        }

        if ($outcome === FavouritePages::NO_STORE) {
            return response()->json([
                'status' => 0,
                'favourited' => false,
                'message' => __('No store is selected, so favourites cannot be saved.'),
            ], 409);
        }

        $favourited = $outcome === FavouritePages::ADDED;

        /* The favourites list is NOT returned. It was, and nothing read it -- the script sets the
           one button from `favourited` -- so building it cost a directory read and up to twelve
           more per request for a payload that was discarded. */
        return response()->json([
            'status' => 1,
            'favourited' => $favourited,
            'message' => $favourited
                ? __('Added to your favourites.')
                : __('Removed from your favourites.'),
        ]);
    }
}
