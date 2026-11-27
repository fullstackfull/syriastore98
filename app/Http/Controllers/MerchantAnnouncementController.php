<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Help\ContextualHelp;
use App\Support\Help\MerchantAnnouncements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * PHASE 06 `SUPPORT-002` — THE WEB HALF OF «وإعلانات»: THE MERCHANT'S DURABLE LIST.
 * =================================================================================================
 *
 * `CTRL-003` already puts a BANNER on every merchant page. This screen is not that banner again —
 * it is the thing a banner cannot be:
 *
 *   A BANNER IS TRANSIENT BY CONSTRUCTION. It shows what is live, on the page you happen to be on,
 *   while the window is open. A merchant who was not logged in on Thursday night has no way at all
 *   to learn what the platform said about Thursday night. That is the gap, and "they should have
 *   been looking" is not an answer for the platform's own record of an outage.
 *
 * So the list carries the ENDED announcements too, flagged, and never a draft and never a window
 * that has not opened — `MerchantAnnouncements`' docblock has the four-state reasoning and
 * `MerchantAnnouncementFeedTest` drives all four.
 *
 * ONE READER FOR BOTH CLIENTS. This screen and `Api\MerchantHelpController::announcements()` both
 * call `MerchantAnnouncements::feed()`. `SUPPORT-002` names the web AND Flutter, and two readers
 * over one table is how the two clients come to disagree about what was announced.
 *
 * -------------------------------------------------------------------------------------------------
 * THE GATE IS IDENTITY, NOT A PERMISSION, AND BOTH HALVES OF THAT ARE DELIBERATE.
 *
 * A PERMISSION WOULD BE WRONG IN BOTH DIRECTIONS AT ONCE. CLAUDE.md: a merchant creates their own
 * roles through the product, so a Laratrust permission is theirs to grant or withhold — and
 * `isAbleTo()` alone ALSO refuses the platform operator, who has no super-admin bypass. A platform
 * announcement is the platform talking to the account; it is not a feature a merchant subscribes to
 * or an admin grants.
 *
 * SO THE HANDLER REQUIRES AN AUTHENTICATED `User`, AND CHECKS IT ITSELF. CLAUDE.md records twice
 * that a handler-level check behind an `abort()`ing middleware is invisible to an HTTP test — once
 * with a `Customer` typed `super admin` reaching a real handler and disabling a plan at HTTP 200.
 * The route's `auth` middleware redirects rather than aborting, so this clause is reachable, and
 * `MerchantAnnouncementScreenTest` drives it by CALLING THE HANDLER DIRECTLY as a `Customer` rather
 * than trusting the route.
 *
 * `instanceof User` AND NOT `auth('web')->check()`: CLAUDE.md records that registration is open and
 * that the `web` and `customers` guards are separate sessions, so a visitor can hold both — a
 * `check()` on the wrong guard is not a principal.
 *
 * NO TENANT PREDICATE, BECAUSE THERE IS NOTHING TO PREDICATE ON. The announcement table has no
 * `store_id`; the announcement is the platform's and reaches every tenant. That is asserted rather
 * than assumed: `MerchantAnnouncementFeedTest` fails if the column ever appears, which is the point
 * at which this handler would need a predicate derived from the principal.
 */
class MerchantAnnouncementController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $merchant = auth('web')->user();

        if (! $merchant instanceof User) {
            /*
             * A REDIRECT RATHER THAN AN `abort(403)`, and keyed on a REASON rather than a status.
             *
             * CLAUDE.md: a gateless handler's own "not found" branch is also a 302, so a status
             * code cannot tell a refusal from a miss. There is no "not found" branch here — the
             * list is never missing — so the test asserts the LANDED PATH, which is the login
             * screen, and the status alone would have proved nothing either way.
             */
            return redirect()->route('login');
        }

        return view('announcements.index', [
            'announcements' => MerchantAnnouncements::feed($this->locale($merchant)),
        ]);
    }

    /**
     * The reader's language.
     *
     * `app()->getLocale()` is what `SetLocate` already resolved for this request — session, cookie,
     * then `users.language` — so the panel's own answer is reused rather than a second rule written
     * beside it. `ContextualHelp::normaliseLocale()` folds it to one of the two the announcement
     * table has columns for, and accepts a regional tag.
     */
    private function locale(User $merchant): string
    {
        $locale = (string) app()->getLocale();

        return ContextualHelp::normaliseLocale(
            $locale !== '' ? $locale : (string) ($merchant->language ?? '')
        );
    }
}
