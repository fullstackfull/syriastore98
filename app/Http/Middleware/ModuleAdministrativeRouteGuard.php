<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ComparesRowOwnership;
use App\Models\User;
use App\Policies\UserPolicy;
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
 * P1-SEC-68 extends the same table to the module's AUTHENTICATED ticket routes. The census above classifies only routes with NO authentication, so the
 * seven Helpdesk routes behind `auth` + a permission + the plan check were never read: each looks a ticket up by its identifier alone, the three that take
 * the ticket NUMBER take it encrypted (a number is `time()`, and APP_KEY is public, so the encryption is an encoding), and one of them rewrites `user_id` to
 * the caller. A rule here is OWNERSHIP only -- the permission stays the handler's. Executed in tests/Feature/Security/HelpdeskTicketOwnershipTest.
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
 *                        (`helpdesk-ticket-show/{ticketId}`, and the authenticated `edit-ticket`, `update-ticket` and `ticket-reply`); it is decoded here
 *                        without unserialize(), and a segment that is not EXACTLY a ticket number is refused FOR EVERYBODY, THE OPERATOR INCLUDED: the
 *                        handlers `decrypt()` it WITH unserialize(), APP_KEY is public, and the operator used to be admitted before the segment was read.
 *   owner:helpdesk-ticket-row   as owner:helpdesk-ticket, but the route's {ticketId} is the ROW id (`helpdesk_tickets.id`), not the ticket number:
 *                        `delete-ticket`, `ticket-conversion-store` and `ticket-note-store` look the ticket up by `id`. Read from the ROUTE only, because the
 *                        handlers take it as a method argument -- a body or query key of the same name must not move the check onto another row.
 *   owner:email-template    the operator, or a user of the tenant that owns the template named by the route's {id}; and when the body names a
 *                        user_email_template row, that row must be for the same template and the same tenant.
 *   settings:store       a module's STORE-SCREEN settings writer (it takes every posted name into `settings` for the caller's store): the user may
 *                        use the Store Setting screen (operator, tenant owner, `Manage Setting` or `Manage Store Setting`) - and a name the
 *                        host calls restricted (mail_*, gateway keys, domain routing... StoreSettingsAccess) is refused unless the user holds
 *                        `Manage Setting`. Same rule as AppSettingController's three Store Setting handlers (P1-SEC-27).
 *   settings:global      a module's INTEGRATION-CREDENTIAL writer (social-login client secrets, SMS, chat widgets, CRM tokens): `Manage Setting`
 *                        outright (operator and tenant owner included, as StoreSettingsAccess::permits()), like the host's own fixed-name
 *                        credential writers (P1-SEC-30). P1-SEC-31.
 *   tenant-store:<body key>    the `stores` row named by that POST BODY key must belong to the caller's own merchant tenant (UserPolicy::merchantTenantRoot(),
 *                        fail closed on null). For an AJAX reader whose handler scopes by `created_by = creatorId()` and takes the store from the body:
 *                        Campaigns' `campaigns-product`, which answered a principal rooted at the operator with the OPERATOR's product names. It asks
 *                        `created_by`, not `store_id`/`current_store`, because that is the predicate the handler itself uses (P1-SEC-69 b).
 *   owner:duo-setting    the operator, or the TENANT OWNER (not staff) -- and, while Duo is enforced for the store, only in a session that has
 *                        passed Duo. `duo2fa-setting` switches Duo off and re-keys it; reachable by a password-only session it made the second
 *                        factor optional (found by the P1-SEC-35 review).
 *
 * The operator is tested FIRST in every ownership rule: Laratrust has no super-admin bypass and the operator holds no merchant role.
 *
 * "Tenant" is `User::creatorId()` equality, never a request-supplied id -- AND the tenant root must be a MERCHANT (a type-`admin` user), the rule
 * UserPolicy::sameTenant() and ThemeEditorGate already follow. A root of 1 is the platform operator, not a tenant: see sameTenant().
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

        // A SEGMENT THAT IS NOT EXACTLY A TICKET NUMBER IS REFUSED FOR EVERYBODY, THE OPERATOR INCLUDED (P1-SEC-68). The handlers behind this rule `decrypt()`
        // the segment WITH unserialize(), and APP_KEY is public, so anyone can mint a segment that carries any serialized bytes. A non-operator is judged by
        // the number the segment decodes to; the operator used to be admitted before the segment was read, so a forged one reached unserialize() in an
        // operator session (it takes only a link that operator's browser is made to open). A legitimate link is always `Crypt::encrypt($ticket->ticket_id)`.
        $linked = $rule === 'owner:helpdesk-ticket-link' ? $this->linkedTicketNumber($route->parameter('ticketId')) : null;

        if ($rule === 'owner:helpdesk-ticket-link' && $linked === null) {
            return $this->deny($request, false);
        }

        if ($rule === 'auth' || $this->isOperator($user)) {
            return $next($request);
        }

        $allowed = match ($rule) {
            'owner:campaign' => $this->ownsCampaign($user, $request->input('id'), true),
            'owner:helpdesk-ticket' => $this->ownsTicket($user, $route->parameter('ticketId')),
            'owner:campaign-route' => $this->ownsCampaign($user, $route->parameter('id')),
            'owner:helpdesk-ticket-link' => $this->ownsTicket($user, $linked),
            'owner:helpdesk-ticket-row' => $this->ownsTicketRow($user, $route->parameter('ticketId')),
            'owner:email-template' => $this->ownsEmailTemplate($user, $route->parameter('id'), $request->input('user_email_template_id')),
            'owner:duo-setting' => $this->mayChangeDuoSettings($user),
            'settings:store' => StoreSettingsAccess::permitsStoreScreen($user)
                && ! StoreSettingsAccess::refusesRestrictedPost($request, $user, getCurrentStore($user->id)),
            'settings:global' => StoreSettingsAccess::permits($user),
            default => match (true) {                                       // 'operator' and any unknown rule: fail closed
                str_starts_with($rule, 'store-row:') => $this->ownsStoreRow($user, $rule, $route),
                str_starts_with($rule, 'tenant-store:') => $this->tenantOwnsStore($user, $rule, $request),
                default => false,
            },
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
     * Does the `stores` row named by a BODY key belong to the caller's own merchant tenant?
     *
     * WHY A SECOND STORE RULE. `store-row:<table>:<param>` reads a ROUTE parameter and compares the
     * row's `store_id` to the caller's `current_store`. Neither half fits here:
     * `CampaignsController::productData` takes its `store_id` from the POST BODY (it is an AJAX
     * call from the create form), and the question is not "is this the store I am currently working
     * in" but "is this store MINE" -- a merchant with several stores may legitimately ask about any
     * of them, and the handler's own predicate is `created_by`, not `store_id`.
     *
     * So the comparison is against `UserPolicy::merchantTenantRoot()`, which is the value the
     * handler's own `creatorId()` should have been: for a merchant owner and their staff the two
     * agree exactly, and for a principal rooted at the OPERATOR -- staff the operator created, or a
     * merchant who demoted themselves -- `creatorId()` is the operator's id and this is null, so
     * the request is refused instead of answered with the operator's product names (MEASURED:
     * CampaignsFilterListsTenantScopeTest).
     *
     * The operator never reaches here: handle() admits them before any rule is asked.
     */
    private function tenantOwnsStore(User $user, string $rule, Request $request): bool
    {
        [, $key] = array_pad(explode(':', $rule, 2), 2, null);

        if (! is_string($key) || $key === '') {
            return false;
        }

        $id = $this->usableId($request->input($key));
        $tenant = UserPolicy::merchantTenantRoot($user);

        if ($id === null || $tenant === null) {
            return false;
        }

        try {
            $store = DB::table('stores')->where('id', $id)->first(['created_by']);
        } catch (\Throwable $e) {
            // Fail closed on an unreadable table, for the reason ComparesRowOwnership::readRowStore records.
            return false;
        }

        return $store !== null && (int) $store->created_by === $tenant;
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

        // \A .. \z, not ^ .. $: `$` also matches before a trailing newline, and the handler's unserialize() would then be handed bytes this did not judge.
        if (is_string($plain) && preg_match('/\A(?:i:(\d{1,18});|s:(\d{1,2}):"(\d{1,18})";)\z/', $plain, $m) === 1) {
            if (! isset($m[3])) {
                return $m[1];
            }

            // `s:L:"digits";` -- L must be the digits' length, or unserialize() will not hand the handler the string this returned
            return (int) $m[2] === strlen($m[3]) ? $m[3] : null;
        }

        return null;
    }

    private function ownsTicket(User $user, mixed $ticketNumber): bool
    {
        if (! is_scalar($ticketNumber) || ! ctype_digit((string) $ticketNumber)) {
            return false;
        }

        return $this->ticketIsTheTenants($user, 'ticket_id', (string) $ticketNumber);
    }

    /** `owner:helpdesk-ticket-row`: the ticket whose ROW id is the route's {ticketId} belongs to the caller's tenant. */
    private function ownsTicketRow(User $user, mixed $rowId): bool
    {
        $rowId = $this->usableId($rowId);

        return $rowId !== null && $this->ticketIsTheTenants($user, 'id', $rowId);
    }

    /**
     * ONE read serves both identifiers (the column is one of our two literals, never input). A ticket is the caller's tenant's when either side of it -- the
     * user it was raised for, or the user who raised it -- is a colleague; a missing row is nobody's.
     */
    private function ticketIsTheTenants(User $user, string $column, string|int $identifier): bool
    {
        $ticket = DB::table('helpdesk_tickets')->where($column, $identifier)->first(['created_by', 'user_id']);

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

        // A USER IS ALWAYS THEIR OWN COLLEAGUE, whatever their tenant root (UserPolicy::sameTenant()'s first limb): the creator of a row keeps it. A
        // demoted merchant, or staff of one, still edits the templates, tickets and campaigns they themselves created; only the OTHER side of the
        // comparison is refused them below.
        if ($otherUserId === (int) $user->id) {
            return true;
        }

        // THE TENANT ROOT MUST BE A MERCHANT (P1-SEC-64b; the same rule P0-TENANT-ROOT put in UserPolicy::sameTenant() and ThemeEditorGate).
        // `creatorId()` equality alone treated a root of 1 as a tenant: every merchant has created_by = 1, and a merchant can demote their own
        // `type`, which drops their `creatorId()` to 1 -- the operator's own id, and the `creatorId()` the operator's rows resolve to. That user was then
        // "the same tenant" as the operator and passed every ownership rule here for the operator's campaigns, tickets and (platform-wide) e-mail
        // templates. A user rooted at the operator -- a demoted merchant, or staff the operator created -- is nobody's colleague; the operator
        // themselves is admitted before any rule is asked (handle()).
        if (! UserPolicy::tenantRootIsAMerchant((int) $user->creatorId())) {
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
