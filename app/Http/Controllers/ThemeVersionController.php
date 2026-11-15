<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ThemePublicationEvent;
use App\Services\Theme\ThemeConfigurationService;
use App\Services\Theme\ThemePublishingService;
use App\Support\Theme\ThemeEditorGate;
use App\Support\Theme\ThemeFieldCatalog;
use Illuminate\Http\Request;

/**
 * PHASE 03 M4-6 — PUBLISH, ROLLBACK AND HISTORY OVER HTTP.
 * =================================================================================================
 *
 * `ThemePublishingService` has existed since M3-D with **no production caller** -- the whole engine,
 * reachable by nothing. This is its front door, under the owner's decision of 2026-11-15: a live
 * publish or rollback is permitted to the verified tenant owner, a same-tenant holder of
 * `Manage Themes`, or the platform operator naming a store explicitly.
 *
 * THE RULE IS ENFORCED TWICE AND BOTH ARE THE SAME RULE. The `theme.editor` middleware applies
 * `allowsManagement()` before the route is reached, and `ThemePublishingService::apply()` applies it
 * again around the transaction. That is defence in depth in the correct direction -- M4-2 recorded
 * the inverse case, where the handler was STRICTER than the middleware and locked the store owner
 * out of their own editor.
 *
 * -------------------------------------------------------------------------------------------------
 * FOUR THINGS THIS CONTROLLER OWNS THAT THE SERVICE DELIBERATELY DOES NOT:
 *
 *   1. THE REFUSAL AUDIT. M3-D recorded that a refused publish leaves no trace at all: measured,
 *      zero `AuditLog` references under `app/Services/Theme/` and `app/Support/Theme/`. A refusal is
 *      the interesting event for an operator acting on a merchant's store, so it is written here.
 *
 *      IT DOES NOT USE `AuditLog::record()`, and that is not a style choice. That helper sets
 *      `'store_id' => getCurrentStore()` (`AuditLog.php:36`), the literal-store-1 fallback M4-1 and
 *      M4-2 spent two slices removing -- so a refusal by an operator who named no store would be
 *      audited against a merchant's store. The row is written directly with the store the gate
 *      actually resolved, or null.
 *
 *   2. THE RETRY DECISION. The service converts a deadlock, a lock-wait timeout and a duplicate key
 *      into a REFUSAL and deliberately does not retry (`ThemePublishingService:479-483`), because a
 *      retry must re-read `liveVersion()` first -- the old expected id would simply fail the check
 *      again. So the caller decides, and this caller does not retry either: it reports the conflict
 *      and lets the merchant look at what changed. Retrying silently would publish over whatever
 *      the other writer just did.
 *
 *   3. SURFACING `keysNotInSnapshot`. Publish and rollback DELETE NOTHING, so rolling back to a
 *      version that predates a setting leaves that setting live. Dropping the field would tell a
 *      merchant "reverted" about a revert that did not fully happen.
 *
 *   4. TRANSLATING THE REFUSAL. The service's reasons are engineer-English and leak installation
 *      detail ("'X' is not a theme this installation ships"). Following the convention already at
 *      `ThemeCustomizeController:205-218`: log the reason with the actor, show a translated generic
 *      message.
 */
class ThemeVersionController extends Controller
{
    public function publish(Request $request, $theme)
    {
        return $this->apply($request, (string) $theme, ThemePublicationEvent::ACTION_PUBLISH);
    }

    public function rollback(Request $request, $theme)
    {
        return $this->apply($request, (string) $theme, ThemePublicationEvent::ACTION_ROLLBACK);
    }

    /**
     * The publication history for this store and theme.
     *
     * `history()` returns publication EVENTS, not versions, so a merchant who has never published
     * sees an empty list -- which is why M4-4 captures a baseline event on first editor open, and
     * why the view must say "nothing published yet" rather than look broken.
     */
    public function history(Request $request, $theme)
    {
        $theme = (string) $theme;
        $storeId = $this->storeId();

        if ($storeId === null || ! $this->themeIsShipped($theme)) {
            return $this->refuse($request, __('Theme not found.'));
        }

        $service = new ThemePublishingService();

        /*
         * `liveVersion()` and `history()` reset `refusal` but NOT `rejected`, so a `rejected()`
         * read after them returns stale field errors from an earlier publish on the same instance.
         * Separate instances, rather than relying on a caller to know that.
         */
        $events = $service->history($storeId, $theme);
        $live   = (new ThemePublishingService())->liveVersion($storeId, $theme);

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 1,
                'data'    => [
                    'live_version_id' => $live?->id,
                    'events' => collect($events)->map(fn ($e) => [
                        'id'          => $e->id,
                        'action'      => $e->action,
                        'version_id'  => $e->version_id,
                        'from'        => $e->from_version_id,
                        'actor_id'    => $e->actor_id,
                        'created_at'  => optional($e->created_at)->toIso8601String(),
                    ])->values(),
                ],
            ]);
        }

        return back()->with('theme_history', $events);
    }

    /**
     * PHASE 03 M4-5 — the draft, rendered as the storefront renders it.
     *
     * Everything that makes this safe happens in `ThemeDraftPreview` middleware: authorization, the
     * draft injection, the theme switch (which must precede any view resolution because
     * `FileViewFinder` memoises) and the no-store headers. By the time this runs, `getThemeSetting()`
     * already answers with the draft for this one `(store, theme)` pair and with the live projection
     * for every other.
     *
     * IT DELEGATES TO THE REAL STOREFRONT HANDLER rather than re-implementing it. A preview that
     * rendered its own approximation of the shop would be a second renderer to keep in step with
     * three themes -- and the first time they diverged, the merchant would be previewing something
     * no shopper ever sees, which is worse than having no preview.
     */
    public function preview(Request $request, $storeSlug, $version)
    {
        $gate = new ThemeEditorGate();
        $storeId = $gate->storeIdForSelfService();

        $store = $storeId === null ? null : \App\Models\Store::find($storeId);

        if (! $store || $store->slug === null || $store->slug === '') {
            abort(404);
        }

        return app(\App\Http\Controllers\HomeController::class)->storeSlug($store->slug);
    }

    /* ============================================================================== internals */

    private function apply(Request $request, string $theme, string $action)
    {
        $storeId = $this->storeId();

        if ($storeId === null) {
            return $this->refuse($request, __('Permission denied.'));
        }

        if (! $this->themeIsShipped($theme)) {
            return $this->refuse($request, __('Theme not found.'));
        }

        $versionId = $request->input('version_id');

        if (! is_numeric($versionId) || (int) $versionId <= 0) {
            return $this->refuse($request, __('Select a version first.'));
        }

        /*
         * THE OPTIMISTIC CHECK IS ONLY REAL IF THE CLIENT SENDS WHAT IT SAW. A null here means "no
         * expectation", which the service treats as "publish regardless" -- correct for a first
         * publish and wrong for a form that has been open while somebody else published. The form
         * carries it; a caller that omits it gets the unchecked behaviour deliberately, not by
         * accident.
         */
        $expected = $request->input('expected_live_version_id');
        $expected = is_numeric($expected) ? (int) $expected : null;

        /* The baseline, so rollback has a target and history is not empty. Idempotent. */
        (new ThemeConfigurationService())->ensureBaseline($storeId, $theme);

        $service = new ThemePublishingService();

        $result = $action === ThemePublicationEvent::ACTION_ROLLBACK
            ? $service->rollback($storeId, $theme, (int) $versionId, null, $expected)
            : $service->publish($storeId, $theme, (int) $versionId, null, $expected);

        if ($result === null) {
            return $this->refuseWithAudit($request, $storeId, $theme, $action, $service->refusal());
        }

        $message = $action === ThemePublicationEvent::ACTION_ROLLBACK
            ? __('The previous version is live again.')
            : __('Your changes are live.');

        /*
         * NOTHING IS DELETED BY A PUBLISH, so a version that predates a setting leaves that setting
         * live. Saying so is the difference between "reverted" and "reverted, except these".
         */
        $notRestored = $result['keysNotInSnapshot'] ?? [];

        if ($notRestored !== []) {
            $message .= ' ' . trans_choice(
                '{1}One setting was left as it is because this version does not mention it.'
                . '|[2,*]:count settings were left as they are because this version does not mention them.',
                count($notRestored),
                ['count' => count($notRestored)]
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status'  => 1,
                'message' => $message,
                'data'    => [
                    'event_id'          => $result['event']->id ?? null,
                    'keysNotInSnapshot' => array_values($notRestored),
                ],
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * HONEST SCOPE, BECAUSE A REMOVAL CHECK SAID SO. Relaxing this to `allows()` -- the membership
     * rule -- leaves ALL ELEVEN tests in `ThemePublishRoutesTest` passing. Measured, not assumed.
     *
     * The reason is that it is the THIRD layer, not the first: the `theme.editor` middleware
     * applies `allowsManagement()` before this route is reached, and
     * `ThemePublishingService::apply()` applies it again around the transaction. A principal this
     * check would refuse never arrives here.
     *
     * It is kept because the route could later be moved out of the middleware group -- which is
     * exactly how M4-2 found the three WRITE handlers still carrying the pre-M4-2 rule -- and
     * because `storeId()` has a second job the gate does not: turning "which store" into a value,
     * and returning null rather than guessing. But the comment says which it is, so nobody reads
     * a green suite as proof that this line is what stops anyone.
     */
    private function storeId(): ?int
    {
        $gate = new ThemeEditorGate();
        $storeId = $gate->storeIdForSelfService();

        return $storeId !== null && $gate->allowsManagement($storeId) ? $storeId : null;
    }

    private function themeIsShipped(string $theme): bool
    {
        return $theme !== ''
            && basename($theme) === $theme
            && in_array($theme, ThemeFieldCatalog::themes(), true);
    }

    /**
     * A REFUSED PUBLICATION IS AN AUDITED EVENT. The reason is logged and stored; the merchant sees
     * a translated generic message, because the service's strings name the installation.
     */
    private function refuseWithAudit(
        Request $request,
        int $storeId,
        string $theme,
        string $action,
        ?string $reason
    ) {
        AuditLog::create([
            'user_id'      => auth()->id(),
            /* THE GATE'S STORE, never getCurrentStore() -- see the class docblock. */
            'store_id'     => $storeId,
            'action'       => 'theme.' . $action . '.refused',
            'subject_type' => \App\Models\ThemeVersion::class,
            'subject_id'   => is_numeric($request->input('version_id'))
                ? (int) $request->input('version_id') : null,
            'old_values'   => null,
            'new_values'   => ['theme' => $theme, 'reason' => $reason],
            'ip_address'   => $request->ip(),
            'user_agent'   => $request->userAgent(),
        ]);

        \Log::warning('Theme publication refused.', [
            'action'   => $action,
            'store_id' => $storeId,
            'theme'    => $theme,
            'user_id'  => auth()->id(),
            'reason'   => $reason,
        ]);

        return $this->refuse($request, __('That change could not be published. Please try again.'));
    }

    private function refuse(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json(['status' => 0, 'message' => $message], 403);
        }

        return back()->with('error', $message);
    }
}
