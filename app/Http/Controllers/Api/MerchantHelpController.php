<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Docs\DocumentationLibrary;
use App\Support\Help\ContextualHelp;
use App\Support\Help\MerchantAnnouncements;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PHASE 06 `SUPPORT-002` — THE FLUTTER HALF: «مساعدة سياقية وإعلانات للويب وFlutter».
 * =================================================================================================
 *
 * `SUPPORT-002` names both clients explicitly — *"للويب وFlutter"* — and
 * `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md`'s next action for it reads "note that Flutter parity
 * needs API endpoints that do not exist yet". These are those endpoints, and they are the whole
 * reason this slice is not web-only:
 *
 *     GET api/admin/announcements    what the platform is telling this merchant
 *     GET api/admin/help-topics      the contextual help for a surface, or the whole map
 *
 * A MERCHANT WHO ONLY USES THE APP SAW NO ANNOUNCEMENT AT ALL. `CTRL-003` put the banner in
 * `resources/views/layouts/app.blade.php` and deliberately left `api/admin` untouched, so the
 * platform could announce an outage and every app-only merchant would be told nothing. That is the
 * gap these two routes close.
 *
 * -------------------------------------------------------------------------------------------------
 * `custom.auth` IS NOT AN AUDIENCE CHECK, AND THIS IS THE CODEBASE'S MOST PRODUCTIVE DEFECT SHAPE.
 *
 * CLAUDE.md: `CustomAuth` resolves a Sanctum token and logs its owner into whichever guard matches
 * the TOKENABLE CLASS — `Customer` → `customers`, `User` → `web`, `DeliveryBoy` → `deliveryboy`. So
 * a shopper's token and a driver's token both PASS `custom.auth` on an `api/admin` route. Thirty-one
 * measured defects in this repository are the handler then reading an id out of the request.
 *
 * So the principal is DERIVED, in one place, by `merchant()` below: `auth('web')->user()` and an
 * `instanceof User` test. Nothing in either handler reads an identifier from the request — there is
 * no id to read, because announcements are platform-wide and the help map is a constant. The one
 * caller-supplied value is `surface`, which is a key lookup in `ContextualHelp::SURFACES` and never
 * part of a path, and `lang`, which selects between two repository locales.
 *
 * A CUSTOMER-TOKEN PROBE CANNOT TEST THE TRAP, so `MerchantHelpApiTest` drives a merchant token, a
 * customer token AND a driver token.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS A 200 WITH `status: 0`, AND THAT IS DELIBERATE RATHER THAN SLOPPY.
 *
 * CLAUDE.md: `ApiResponser::error()` returns HTTP **200** with `{status: 0, ...}` unless a code is
 * passed, **and the shipped Flutter app forces a logout on any 401**. A 401 here would log a
 * merchant out of the app because a driver's token hit a help endpoint. These routes are new, so no
 * installed copy calls them yet — but the app's own `apiRefused()` helper already reads
 * `status: 0` as a refusal, which is the contract that exists, and inventing a second one for two
 * new endpoints would be the drift this requirement is about.
 *
 * -------------------------------------------------------------------------------------------------
 * NOTHING EITHER HANDLER RETURNS IS TENANT DATA.
 *
 * Announcements have no `store_id` and the help articles are files in `resources/docs/`. That is why
 * `MerchantHelpApiTest` asserts that two merchants of two different stores receive BYTE-IDENTICAL
 * payloads: where every principal gets the same answer there is no per-tenant channel to confuse,
 * and CLAUDE.md's `null_tenant => 'open'` trap cannot apply to a query that has no tenant predicate
 * to lose in the first place.
 */
class MerchantHelpController extends Controller
{
    use ApiResponser;

    /** The page size when the app sends none. `MerchantAnnouncements` caps the maximum. */
    private const DEFAULT_LIMIT = 50;

    /**
     * What the platform is telling this merchant — live first, then the ones that have ended.
     *
     * `MerchantAnnouncements::feed()` is the same reader the panel's list page uses, so the app and
     * the web cannot disagree about what was announced.
     */
    public function announcements(Request $request): JsonResponse
    {
        $merchant = $this->merchant();

        if (! $merchant instanceof User) {
            return $this->refusal();
        }

        $locale = $this->locale($request, $merchant);
        $feed   = MerchantAnnouncements::feed($locale, $this->limit($request));

        return $this->success([
            'locale' => $locale,
            /* COUNTED HERE RATHER THAN IN THE APP. The badge on the app's menu item is "how many
               are live", not "how many exist", and an app that filtered the list itself would be a
               second copy of the four-state rule this slice exists to keep in one place. */
            'live_count' => count(array_filter($feed, static fn (array $row): bool => $row['is_live'])),
            'announcements' => $feed,
        ], 'announcements');
    }

    /**
     * The contextual help for one surface, or the whole map when no surface is named.
     *
     * The app asks for the map once and keeps it: help for the screen a merchant is already looking
     * at must not need a round trip.
     */
    public function helpTopics(Request $request): JsonResponse
    {
        $merchant = $this->merchant();

        if (! $merchant instanceof User) {
            return $this->refusal();
        }

        $locale = $this->locale($request, $merchant);

        /*
         * `surface` IS CALLER-SUPPLIED AND IS A KEY LOOKUP, NEVER A PATH.
         *
         * `ContextualHelp::articlesForSurface()` answers [] for anything not declared in its
         * constant, and the hostile shapes are driven in `ContextualHelpTest`. It is cast to string
         * first because `?surface[]=x` makes `$request->query('surface')` an ARRAY, and an array
         * reaching a string parameter is a TypeError and a 500 rather than a refusal.
         */
        $surface = $request->query('surface');
        $surface = is_string($surface) ? trim($surface) : '';

        if ($surface !== '') {
            return $this->success([
                'locale' => $locale,
                'surface' => $surface,
                /* `known` LETS THE APP TELL "no help for this screen" FROM "the server did not
                   understand the key". Both answer with an empty list, and they are different
                   facts: the first is expected on most screens, the second means the app and the
                   server have drifted and somebody should look. */
                'known' => array_key_exists($surface, ContextualHelp::SURFACES),
                'articles' => ContextualHelp::articlesForSurface($surface, $locale),
            ], 'help topics');
        }

        return $this->success([
            'locale' => $locale,
            'locales' => DocumentationLibrary::LOCALES,
            'surfaces' => ContextualHelp::vocabulary($locale),
        ], 'help topics');
    }

    // =============================================================================================
    // internals
    // =============================================================================================

    /**
     * THE PRINCIPAL, DERIVED — never read from the request. See the class docblock.
     *
     * `auth('web')` AND NOT `auth()`: the default guard IS `web`, so the two are the same object
     * today, and naming the guard means a future change to `auth.defaults` cannot quietly turn this
     * into "whoever happens to be signed in". CLAUDE.md records `auth()->user()` answering for the
     * wrong principal as a measured defect shape.
     */
    private function merchant(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * A 200 carrying `status: 0`. See the class docblock on why this is not a 401.
     *
     * THE MESSAGE IS ONE THE APPLICATION ALREADY SHIPS, and that is not housekeeping. The first
     * draft added `This action is not allowed.` -- a 5,201st key one letter of meaning away from
     * four that were already there. This one is the string repo B's `network/result.dart` NAMES as
     * what the backend sends when it refuses on permission, so the Flutter app's own refusal
     * handling and this endpoint now say the same words, and `ar.json` already carries its Arabic
     * («ليس لديك صلاحية لتنفيذ هذا الإجراء.») reviewed by somebody other than this workstream.
     */
    private function refusal(): JsonResponse
    {
        return $this->error([], __('You do not have permission to perform this action.'));
    }

    /**
     * The reader's language: what they asked for if the centre ships it, else their panel setting.
     *
     * THE APP'S LANGUAGE IS A DEVICE CHOICE THE SERVER CANNOT KNOW, which is why `lang` is accepted
     * at all — a merchant who set the app to Arabic must not be served English announcements
     * because `users.language` still says `en`. It selects between two repository locales and
     * nothing else: `ContextualHelp::normaliseLocale()` folds anything unrecognised, so this is a
     * presentation preference and not a predicate over data. The merchant's own
     * `users.language` — derived from the principal — is the default.
     */
    private function locale(Request $request, User $merchant): string
    {
        $asked = $request->query('lang');
        $asked = is_string($asked) ? trim($asked) : '';

        if ($asked !== '') {
            return ContextualHelp::normaliseLocale($asked);
        }

        return ContextualHelp::normaliseLocale((string) ($merchant->language ?? ''));
    }

    /**
     * How many announcements to return. `MerchantAnnouncements` caps it; this only parses it.
     *
     * `FILTER_VALIDATE_INT` AND NOT `is_numeric`, which the first draft used. The tenancy skill's
     * rule is that `is_numeric` is not strict enough — it accepts `'5.9'` and rejects `'5abc'`
     * while MySQL coerces `'5abc'` to 5 — and although a page size has no row to confuse (there is
     * no identifier anywhere in this controller and nothing tenant-scoped to reach), a loose parse
     * on a value that crosses the wire is the habit the rule exists to break. `?limit[]=1` is a
     * non-scalar and is rejected here rather than reaching the cast.
     */
    private function limit(Request $request): int
    {
        $limit = $request->query('limit');

        if (! is_scalar($limit)) {
            return self::DEFAULT_LIMIT;
        }

        $parsed = filter_var((string) $limit, FILTER_VALIDATE_INT);

        return $parsed === false ? self::DEFAULT_LIMIT : $parsed;
    }
}
