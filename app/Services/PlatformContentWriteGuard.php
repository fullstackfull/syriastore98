<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\Platform\PlatformContentStore;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ONLY THE PLATFORM OPERATOR MAY WRITE THE TEXT PUBLISHED ON THE COMPANY'S OWN WEBSITE.
 *
 * Found by the independent authorization review of GAP-104, against a docblock in that change
 * which asserted there were "two independent reasons a merchant cannot" write this row. Both were
 * wrong. The chain, each link verified in the source:
 *
 *   1. `getCurrentStore()` (`helper.php:881-895`) returns NULL for a merchant with no store — a
 *      supported product state with its own existing fixture. An operator can never reach that
 *      branch: `type === 'super admin'` returns the literal 1 two lines earlier. So this is an
 *      escalation and not a collision the owner could cause themselves.
 *   2. `SettingController::CookieSettings` writes one row per POSTED KEY, matching on
 *      `['name' => $key, 'store_id' => getCurrentStore()]`, and its validator is assigned and
 *      never inspected. `AppSettingController::seoSettings` has the same shape. Both are HOST
 *      routes: no module, no permission, any tenant-root merchant.
 *   3. Illuminate turns `where(['store_id' => null])` into `whereNull`, so that lookup matches the
 *      platform's own row — a mechanic this repository already records at `routes/web.php:331-334`
 *      for a different handler.
 *   4. `SettingOwnership::keep()` guards only the CREATE path; on UPDATE it RESTORES the original
 *      `created_by`. So the merchant's write kept the operator's ownership stamp and the reader's
 *      `where('created_by', $operatorId)` predicate still matched it. The control the change cited
 *      as its protection is the thing that made the hijack land.
 *
 * WHAT WAS AT STAKE. Not script injection — the value still renders through Blade escaping or the
 * Markdown renderer, and `xss` runs on both host routes. It is content, brand and LEGAL-TEXT
 * integrity: a third party publishing terms of service, a privacy notice and an acceptable-use
 * policy at the company's own address. For documents that bind the business that is worse than a
 * defacement, not better.
 *
 * WHY HERE AND NOT IN THE HANDLERS. Every Eloquent writer of `settings` passes through
 * `Setting::booted()`'s `saving` hook — which is why `HostClaims`, `StoredPathPolicy` and
 * `SettingOwnership` all live there. At least ten VENDORED module controllers have the same
 * `$request->all()` shape and cannot be edited. Fixing the two host handlers would have left those
 * open and would have had to be repeated for the next one.
 *
 * DELIBERATELY NARROW. This guards the `platform_site_content_` prefix and nothing else. General
 * ownership of the `settings` table is a much larger question that is already open elsewhere, and
 * widening this to answer it would be a behaviour change across every merchant's settings screen
 * rather than the close of a specific hole.
 *
 * FAIL-CLOSED, INCLUDING WITH NO PRINCIPAL AT ALL. A console command, a queued job or a seeder has
 * no authenticated user, and is refused. Nothing in this repository seeds platform content, so the
 * cost is nil and the alternative — allowing unauthenticated writes — would reopen the hole for
 * anything that reaches Eloquent outside a request.
 */
final class PlatformContentWriteGuard
{
    /**
     * Refuse a write to the official site's content unless the platform operator is making it.
     */
    public static function assertOperator(Setting $setting): void
    {
        $name = (string) $setting->getAttribute('name');

        if (! str_starts_with($name, PlatformContentStore::NAME_PREFIX)) {
            return;
        }

        $actor = auth()->user();

        /*
         * `instanceof User` BEFORE `isOperator()`, and the order is load-bearing.
         * `UserPolicy::isOperator()` takes an untyped actor and reads `->type`, and `customers`
         * HAS a `type` column with `'type'` in its `$fillable`. The same clause was added to
         * `PlatformOperatorOnly` and to the editor's own handler for the same reason; this is the
         * third place it is needed, which is itself worth noticing.
         */
        if ($actor instanceof User && UserPolicy::isOperator($actor)) {
            return;
        }

        Log::warning('Refused a non-operator write to the official website content', [
            'setting_name' => $name,
            'actor_id' => $actor?->getAuthIdentifier(),
            'actor_class' => $actor === null ? null : $actor::class,
        ]);

        throw new HttpException(403, 'Only the platform operator may change the official website content.');
    }
}
