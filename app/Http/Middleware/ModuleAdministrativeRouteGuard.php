<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use App\Models\User;
use App\Services\StoreSettingsAccess;
use App\Support\ModuleRouteManifest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-SEC-04 -- ADMINISTRATIVE MODULE ROUTES THAT CARRIED NO AUTHENTICATION.
 * ==========================================================================
 *
 * WorkDo modules declare their routes in `Route::middleware(['web'])` groups, and a great many merchant screens and settings writers were put
 * in the same group as the shopper-facing endpoints: 452 module routes answer an anonymous caller (route-census.php, every module loaded
 * through the production loader). The read-only classification of the 143 mutating ones found six that an anonymous caller could drive to a
 * real effect, EXECUTED in tests/Feature/Security/AnonymousAdministrativeModuleRoutesTest: publish or expire any tenant's campaign, write the
 * platform landing page (rendered unescaped), append HTML to any helpdesk ticket, set any tenant's partial-payment status, rewrite any tenant's
 * e-mail template, decide any tenant's coupon request. Most of the others failed only by accident (`Auth::user()->id` dereferenced while a
 * query's arguments were built), and every signed-in user passed them.
 *
 * packages/workdo/ is vendored, so the control is host-side and driven by ONE manifest, App\Support\ModuleRouteManifest::ADMINISTRATIVE:
 * `'METHOD uri' => rule`. The matching census test (RouteCensusGateTest) fails when a module route has no authentication AND appears in neither
 * that table nor the manifest of routes that are anonymous by design, so a module update that adds an unclassified route is a red build, not a
 * silent exposure.
 *
 * RULES (every one needs a signed-in `web` user first; a refusal is a redirect with an error or a 403, NEVER a 401 -- the Flutter admin app
 * forces a logout on any 401):
 *
 *   auth                 any signed-in merchant-side user. This is the baseline for a screen or a settings writer; WHAT a signed-in user may
 *                        do with it (per-screen permission) is P1-SEC-31's, and is deliberately not decided here.
 *   operator             only the platform operator (users.type 'super admin'), as the module's own index()/create() already require.
 *   owner:campaign       the operator, or a user of the tenant that created the campaign named by the body key `id` AND only once the operator has
 *                        approved it (campaigns.approval = 1): the merchant's own list never offers the toggle for an unapproved or declined one,
 *                        and this endpoint used to let the owner publish it anyway.
 *   owner:helpdesk-ticket   the operator, or a user of the tenant that raised the ticket named by the route's {ticketId}.
 *   store-row:<table>:<param>  the row of <table> named by the ROUTE parameter <param> must belong to the caller's current store (the shared
 *                        ComparesRowOwnership predicate: operator first, fail closed on a missing row or store). For handlers that mutate a row by a
 *                        bare id with no tenant check: ProductAffiliate's payout accept/reject and ReviewReminder's review delete. The id is read from
 *                        the route only, because those handlers take it as a method argument - a body or query `id` must not authorise another row.
 *   owner:campaign-route  as owner:campaign, but the campaign is the route's {id} (the GET screens), not the body key `id`.
 *   owner:helpdesk-ticket-link  as owner:helpdesk-ticket, but the route segment is the ENCRYPTED ticket number the module emails and links
 *                        (`helpdesk-ticket-show/{ticketId}`); it is decoded here without unserialize(), and an undecodable segment is refused.
 *   owner:email-template    the operator, or a user of the tenant that owns the template named by the route's {id}; and when the body names a
 *                        user_email_template row, that row must be for the same template and the same tenant.
 *   settings:store       a module's STORE-SCREEN settings writer (it takes every posted name into `settings` for the caller's store): the user may
 *                        use the Store Setting screen (operator, tenant owner, `Manage Setting` or `Manage Store Setting`) - and a name the
 *                        host calls restricted (mail_*, gateway keys, domain routing... StoreSettingsAccess) is refused unless the user holds
 *                        `Manage Setting`. Same rule as AppSettingController's three Store Setting handlers (P1-SEC-27).
 *   settings:global      a module's INTEGRATION-CREDENTIAL writer (social-login client secrets, SMS, chat widgets, CRM tokens): `Manage Setting`
 *                        outright (operator and tenant owner included, as StoreSettingsAccess::permits()), like the host's own fixed-name
 *                        credential writers (P1-SEC-30). P1-SEC-31.
 *   owner:duo-setting    the operator, or the TENANT OWNER (not staff) -- and, while Duo is enforced for the store, only in a session that has
 *                        passed Duo. `duo2fa-setting` switches Duo off and re-keys it; reachable by a password-only session it made the second
 *                        factor optional (found by the P1-SEC-35 review).
 *
 * The operator is tested FIRST in every ownership rule: Laratrust has no super-admin bypass and the operator holds no merchant role.
 *
 * "Tenant" is `User::creatorId()` equality, never a request-supplied id.
 */
class ModuleAdministrativeRouteGuard
{
    use ComparesRowOwnership;

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route === null) {
            return $next($request);
        }

        $rule = ModuleRouteManifest::administrativeRule($request->method(), $route->uri());

        if ($rule === null) {
            return $next($request);
        }

        // EXPLICIT GUARD NAME, never auth()->user(): CustomAuth logs a token's owner into whichever of the three guards matches its class, so
        // the default guard can be `customers`, and a Customer has no isAbleTo()/creatorId().
        $user = auth('web')->user();

        if (! $user) {
            return $this->deny($request, true);
        }

        if ($rule === 'auth' || $this->isOperator($user)) {
            return $next($request);
        }

        $allowed = match ($rule) {
            'owner:campaign' => $this->ownsCampaign($user, $request->input('id'), true),
            'owner:helpdesk-ticket' => $this->ownsTicket($user, $route->parameter('ticketId')),
            'owner:campaign-route' => $this->ownsCampaign($user, $route->parameter('id')),
            'owner:helpdesk-ticket-link' => $this->ownsTicket($user, $this->linkedTicketNumber($route->parameter('ticketId'))),
            'owner:email-template' => $this->ownsEmailTemplate($user, $route->parameter('id'), $request->input('user_email_template_id')),
            'owner:duo-setting' => $this->mayChangeDuoSettings($user),
            'settings:store' => StoreSettingsAccess::permitsStoreScreen($user)
                && ! StoreSettingsAccess::refusesRestrictedPost($request, $user, getCurrentStore($user->id)),
            'settings:global' => StoreSettingsAccess::permits($user),
            default => str_starts_with($rule, 'store-row:') && $this->ownsStoreRow($user, $rule, $route),   // 'operator' and any unknown rule: fail closed
        };

        return $allowed ? $next($request) : $this->deny($request, false);
    }

    private function isOperator(User $user): bool
    {
        return strtolower(trim((string) $user->type)) === 'super admin';
    }

    private function ownsCampaign(User $user, mixed $id, bool $mustBeApproved = false): bool
    {
        $id = $this->usableId($id);

        if ($id === null) {
            return false;
        }

        $campaign = DB::table('campaigns')->where('id', $id)->first(['created_by', 'approval']);

        return $campaign !== null
            && $this->sameTenant($user, $campaign->created_by)
            && (! $mustBeApproved || (int) $campaign->approval === 1);
    }

    /** `store-row:<table>:<param>`: the row of <table> named by the route parameter <param> belongs to the caller's current store. */
    private function ownsStoreRow(User $user, string $rule, $route): bool
    {
        [, $table, $param] = array_pad(explode(':', $rule, 3), 3, null);
        $id = $this->usableId($route->parameter((string) $param));

        if (! is_string($table) || $table === '' || $id === null) {
            return false;
        }

        // a missing row, an unreadable table and a NULL store_id all come back as a null store, which storeMatches() refuses
        [, $rowStore] = $this->readRowStore($table, $id);

        return $this->storeMatches($user, $rowStore);
    }

    /**
     * The ticket number inside an encrypted link segment, or null. Decrypted WITHOUT unserialize (Crypt::decrypt($v, false)) and the plaintext
     * must be exactly a serialized integer or a serialized string of digits: the payload is attacker-shaped (APP_KEY is public), so it is never
     * handed to unserialize().
     */
    private function linkedTicketNumber(mixed $segment): ?string
    {
        if (! is_string($segment) || $segment === '') {
            return null;
        }

        try {
            $plain = \Illuminate\Support\Facades\Crypt::decrypt($segment, false);
        } catch (\Throwable $e) {
            return null;
        }

        if (is_string($plain) && preg_match('/^(?:i:(\d{1,18});|s:\d{1,2}:"(\d{1,18})";)$/', $plain, $m) === 1) {
            return $m[2] ?? $m[1];
        }

        return null;
    }

    private function ownsTicket(User $user, mixed $ticketNumber): bool
    {
        if (! is_scalar($ticketNumber) || ! ctype_digit((string) $ticketNumber)) {
            return false;
        }

        $ticket = DB::table('helpdesk_tickets')->where('ticket_id', (string) $ticketNumber)->first(['created_by', 'user_id']);

        return $ticket !== null
            && ($this->sameTenant($user, $ticket->created_by) || $this->sameTenant($user, $ticket->user_id));
    }

    private function ownsEmailTemplate(User $user, mixed $templateId, mixed $userTemplateId): bool
    {
        $templateId = $this->usableId($templateId);

        if ($templateId === null || ! $this->sameTenant($user, DB::table('email_templates')->where('id', $templateId)->value('created_by'))) {
            return false;
        }

        // The handler also writes the `user_email_templates` row named in the body (is_active). It must be this template's, and this tenant's;
        // otherwise a user could name their own template in the URL and switch another tenant's template on or off through the body.
        if ($userTemplateId === null || $userTemplateId === '') {
            return true;
        }

        $userTemplateId = $this->usableId($userTemplateId);
        $row = $userTemplateId === null ? null : DB::table('user_email_templates')->where('id', $userTemplateId)->first(['template_id', 'user_id']);

        return $row !== null && (int) $row->template_id === $templateId && $this->sameTenant($user, $row->user_id);
    }

    private function mayChangeDuoSettings(User $user): bool
    {
        // the tenant owner only: `current_store` of a staff member is the OWNER's store, so staff would otherwise rewrite the owner's Duo host/keys
        if ((int) $user->creatorId() !== (int) $user->id) {
            return false;
        }

        $store = DB::table('stores')->where('id', $user->current_store)->first(['duo_setting_enabled', 'duo_integration_key', 'duo_secret_key']);

        $enforced = $store !== null
            && $store->duo_setting_enabled === 'on'
            && trim((string) $store->duo_integration_key) !== ''
            && trim((string) $store->duo_secret_key) !== '';

        // the same condition showDuoForm() enforces: with Duo on, a session that has not passed it cannot change it
        return ! $enforced || session('is_login') === 'true';
    }

    /** Is the user with this id in the SAME TENANT as $user? Fails closed on a null, zero or unknown id. */
    private function sameTenant(User $user, mixed $otherUserId): bool
    {
        $otherUserId = $this->usableId($otherUserId);

        // a user with NO tenant (creatorId() null/0, e.g. a NULL created_by) is nobody's colleague: (int) null === (int) null would otherwise
        // make any two such users "the same tenant"
        if ($otherUserId === null || (int) $user->creatorId() <= 0) {
            return false;
        }

        $other = $otherUserId === (int) $user->id ? $user : User::find($otherUserId);

        return $other !== null && (int) $other->creatorId() === (int) $user->creatorId();
    }

    private function usableId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value) || $value === '' || strlen($value) > 18) {
            return null;
        }

        return (int) $value > 0 ? (int) $value : null;
    }

    private function deny(Request $request, bool $anonymous): Response
    {
        $message = __('Permission denied');

        if ($request->expectsJson()) {
            return response()->json(['is_success' => false, 'msg' => $message, 'message' => $message], 403);
        }

        return $anonymous
            ? redirect()->route('login')->with('error', $message)
            : redirect()->route('dashboard')->with('error', $message);
    }
}
