<?php

namespace Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Requests\ApproveLinkRequest;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaMarketingAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Services\MarketingAllowanceSync;
use Workdo\LynomiaChat\app\Services\SeatAllowanceSync;

/**
 * The dedicated "Lynomia Chat Integration" page in Store Syria Super Admin.
 *
 * ONE PAGE, TABS INSIDE IT. The approved rule is that integration settings are centralised
 * on a single page per platform rather than scattered across unrelated menus. Phase 1 ships
 * the Overview and Merchant Accounts tabs, which are the ones that have content; the
 * remaining approved tabs (Plans & Features, Add-ons, Trials, Promotional Grants, Merchant
 * Exceptions, Quotas, Channel Status, Subscription Sync, Integration Errors, Security &
 * Audit) arrive with the phases that give them something to show. The page is the container
 * from the start so later phases add a tab rather than a menu entry.
 *
 * Authorization is PlatformOperatorOnly on the route, the same control the plan-order rail
 * uses. Approving a link is an administrative act with commercial consequences, so it is not
 * delegated to a permission a merchant role could hold.
 */
class IntegrationController extends Controller
{
    /**
     * Attempts after which a waiting event stops being ordinary backlog and starts being a
     * store that needs an operator. The dispatcher's backoff reaches ten minutes by the third
     * attempt, so anything at five has been failing for the better part of an hour.
     */
    private const STUCK_AFTER_ATTEMPTS = 5;

    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly AccountLinker $linker,
        private readonly SeatAllowanceSync $seats,
        private readonly MarketingAllowanceSync $marketing
    ) {
    }

    /**
     * The merchant-side permissions this module defines, in the order they widen.
     *
     * READ FROM ONE PLACE so the Super Admin page cannot drift from the seeder that creates
     * them: a permission added there and forgotten here is a control nobody can see the reach of.
     */
    public const MODULE_PERMISSIONS = [
        'Manage Lynomia Chat',
        'Reply Lynomia Chat',
        'Assign Lynomia Chat',
        'Manage Lynomia Automation',
        'Manage Lynomia Consent',
        'Manage Lynomia Audiences',
        'Manage Lynomia Templates',
        'Manage Lynomia Campaigns',
    ];

    public function show(): View
    {
        return view('lynomia-chat::superadmin.integration', [
            // Booleans and counts only. The page never renders the base URL or the token:
            // an operator needs to know whether the integration is usable, not what the
            // credential is.
            'integrationEnabled' => $this->api->isEnabled(),
            'integrationConfigured' => $this->api->isConfigured(),
            'links' => LynomiaStoreLink::query()->orderByDesc('id')->paginate(25),
            'pendingCount' => LynomiaStoreLink::query()
                ->where('state', \Workdo\LynomiaChat\app\Support\LinkState::PENDING_VERIFICATION)
                ->count(),
            // Phase 2. Seats, channels and event health, read from THIS side only -- the page
            // renders without reaching Lynomia at all, so it still answers when the integration
            // is unreachable, which is exactly when an operator opens it.
            'planSeats' => Plan::query()->orderBy('name')->get(['id', 'name', 'lynomia_agent_seats']),
            'seatedStores' => LynomiaStoreIdentity::query()
                ->selectRaw('store_id, count(*) as seats, sum(case when role = ? then 1 else 0 end) as admins',
                    [LynomiaStoreIdentity::ROLE_ADMINISTRATOR])
                ->groupBy('store_id')
                ->pluck('seats', 'store_id'),
            'eventHealth' => $this->eventHealth(),
            // Phase 3. The OUTBOUND feed, which fails differently from the inbound one: an
            // event this side could not deliver is still sitting in the table, so its health
            // is a count rather than a timestamp.
            'commerceHealth' => $this->commerceHealth(),
            // Phase 4. Who, on this side, can build an automation or record a consent decision.
            'automationAccess' => $this->automationAccess(),
        ]);
    }

    /**
     * WHO CAN DO WHAT, counted from this side's own grants.
     *
     * The Automation Studio's engine, its limits and its kill switches are Lynomia's and are
     * administered there; what Store Syria decides is WHICH of a merchant's people reach the
     * Studio at all, and that is a Laratrust grant in this database. An operator asked "who in
     * this installation can publish an automation to customers" can answer it here without
     * reaching Lynomia -- which matters, because the times an operator asks are the times
     * Lynomia may be the thing that is wrong.
     *
     * COUNTS, NOT NAMES. A list of every staff member who holds a permission is a list of other
     * tenants' employees, and an operator deciding whether a control is too widely granted needs
     * the shape rather than the people. The store count is what makes the number readable: four
     * roles across four stores is ordinary, four roles in one store is worth a look.
     *
     * A ROLE WITH NO store_id IS PLATFORM-WIDE, AND IS COUNTED SEPARATELY RATHER THAN AS A STORE.
     * That is not a corner case -- it is the shape this module's own seeder creates, one global
     * `admin` role granted all five permissions, reaching every merchant owner on the installation.
     * Counted under "stores covered" it reads 0, because COUNT(DISTINCT store_id) skips NULL: the
     * broadest grant there is would render as the narrowest. So the store count covers only the
     * roles that really are confined to one store, and the platform-wide ones are their own number.
     *
     * @return list<array{name:string, roles:int, platform_wide:int, stores:int}>
     */
    private function automationAccess(): array
    {
        return collect(self::MODULE_PERMISSIONS)->map(function (string $name): array {
            $permission = Permission::query()->where('name', $name)->where('module', 'LynomiaChat')->first();

            $roles = $permission
                ? Role::query()->whereHas('permissions', fn ($query) => $query->where('permissions.id', $permission->id))
                : Role::query()->whereRaw('1 = 0');

            return [
                'name' => $name,
                'roles' => (clone $roles)->count(),
                'platform_wide' => (clone $roles)->whereNull('store_id')->count(),
                'stores' => (clone $roles)->whereNotNull('store_id')->distinct()->count('store_id'),
            ];
        })->all();
    }

    /**
     * Is the commerce event feed draining, and which stores are stuck?
     *
     * THE NUMBER THAT MATTERS IS THE BACKLOG, not the throughput. An inbound delivery that
     * fails is gone and the only evidence is a gap; an OUTBOUND one that fails is still in the
     * outbox with its attempt count and its last error, so "how many are waiting, how old is
     * the oldest, and which stores are they" is a question this side can answer exactly.
     *
     * A store appearing here with a double-digit attempt count is the one thing on this page
     * an operator has to act on: its credential has almost certainly been revoked or rotated
     * on the Lynomia side, and nothing will deliver until it is reissued.
     *
     * @return array{enabled:bool, pending:int, stuck:int, oldest:?string, stores:array<int,int>}
     */
    private function commerceHealth(): array
    {
        $pending = LynomiaCommerceEvent::query()->whereNull('delivered_at');

        return [
            'enabled' => (bool) config('lynomia.commerce_events_enabled'),
            'pending' => (clone $pending)->count(),
            // Tried repeatedly and still failing: a backlog is ordinary for a minute, this is not.
            'stuck' => (clone $pending)->where('attempts', '>=', self::STUCK_AFTER_ATTEMPTS)->count(),
            'oldest' => (clone $pending)->oldest('id')->first()?->occurred_at?->diffForHumans(),
            'stores' => (clone $pending)->where('attempts', '>=', self::STUCK_AFTER_ATTEMPTS)
                ->selectRaw('store_id, count(*) as waiting')
                ->groupBy('store_id')
                ->pluck('waiting', 'store_id')
                ->all(),
        ];
    }

    /**
     * Is the event pipe alive, and how stale is it?
     *
     * READ FROM THE EVENTS WE RECEIVED, not by asking Lynomia. An operator opening this page
     * usually does so because something is wrong, and a health panel that itself needs the
     * remote system to answer is a panel that goes blank exactly when it is needed. "The last
     * delivery we accepted was N minutes ago" is a fact this side already holds.
     *
     * @return array{total:int, last_at:?string, stores_reporting:int}
     */
    private function eventHealth(): array
    {
        $latest = LynomiaInboxEvent::query()->latest('id')->first();

        return [
            'total' => LynomiaInboxEvent::query()->count(),
            'last_at' => $latest?->created_at?->diffForHumans(),
            'stores_reporting' => LynomiaInboxEvent::query()->distinct()->count('store_id'),
        ];
    }

    /**
     * Set how many Lynomia agent seats a Store Syria plan includes.
     *
     * CONFIGURABLE, AND DELIBERATELY NOT DEFAULTED. The column is nullable with no default and
     * every plan starts null, which means "this plan grants and withholds nothing" -- the
     * integration withdraws its entitlement grant and Lynomia's own rules apply unchanged. A
     * default of 0 would lock every merchant out of an inbox they are already paying for the
     * moment enforcement arrived; a default of 5 would invent a commercial decision nobody made.
     * Clearing the field returns a plan to that state.
     *
     * IT LIVES HERE RATHER THAN ON THE CORE PLAN FORM. Approved decision 14 keeps integration
     * configuration on each platform's dedicated integration page, and PlanController belongs to
     * the host platform -- adding a Lynomia field to it would scatter the integration across an
     * unrelated module and give the setting two owners.
     *
     * CHANGING IT DOES NOT TOUCH ANYBODY'S SEATS. The new ceiling is pushed to every connected
     * store on that plan, and Lynomia applies it to what happens NEXT: a merchant already over a
     * lowered ceiling keeps every person they have, and simply cannot add another. Nobody is
     * removed, which is the rule a downgrade has to obey.
     */
    /**
     * HOW MANY MARKETING SENDS A PLAN INCLUDES (P5G).
     *
     * The same shape as `updateSeats`, deliberately -- an operator setting a commercial
     * number on a plan and pushing it to every connected store on that plan is one pattern,
     * and two spellings of it would be two places to fix the next time the push changes.
     *
     * `nullable` IS the product feature here too: an empty field says "this plan grants
     * nothing", which is different from "grants zero". Zero would stop every campaign on the
     * plan the moment it was saved.
     */
    public function updateMarketingAllowance(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'campaign_sends' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ]);

        $plan = Plan::find($validated['plan_id']);

        if (! $plan) {
            return back()->with('error', __('That plan no longer exists.'));
        }

        $sends = $validated['campaign_sends'] === null || $validated['campaign_sends'] === ''
            ? null
            : (int) $validated['campaign_sends'];

        $before = $plan->lynomia_campaign_sends;
        $plan->lynomia_campaign_sends = $sends;
        $plan->save();

        AuditLog::record('lynomia_plan_marketing_sends_changed', $plan, ['lynomia_campaign_sends' => $before], [
            'lynomia_campaign_sends' => $sends,
            'plan_id' => $plan->id,
            'actor_id' => auth()->id(),
        ]);

        $this->resyncPlan($plan);

        return back()->with('success', __('Included marketing sends updated for :plan.', ['plan' => $plan->name]));
    }

    /** A bundle an operator offers. Recorded with its price; nothing here charges it. */
    public function storeAddon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'campaign_sends' => ['required', 'integer', 'min:1', 'max:10000000'],
            'price_cents' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'currency' => ['nullable', 'string', 'max:8'],
        ]);

        $addon = LynomiaMarketingAddon::create([
            'name' => $validated['name'],
            'campaign_sends' => (int) $validated['campaign_sends'],
            'price_cents' => (int) ($validated['price_cents'] ?? 0),
            'currency' => strtoupper($validated['currency'] ?? 'USD'),
            'is_active' => true,
        ]);

        AuditLog::record('lynomia_marketing_addon_created', $addon, [], [
            'addon_id' => $addon->id, 'campaign_sends' => $addon->campaign_sends, 'actor_id' => auth()->id(),
        ]);

        return back()->with('success', __('Add-on created.'));
    }

    /**
     * Give a store an add-on.
     *
     * GRANTED BY AN OPERATOR, NOT BOUGHT BY A MERCHANT, and that is the only safe thing to
     * ship while every payment gateway in this installation is switched off. The row records
     * who granted it and why, so the ledger answers "where did these sends come from".
     *
     * `expires_at` IS WHAT MAKES A TRIAL A TRIAL. A time-boxed grant and a purchased bundle
     * differ only in what was paid; one mechanism with one expiry rule is one thing to get
     * right.
     */
    public function grantAddon(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'link_id' => ['required', 'integer', 'exists:lynomia_store_links,id'],
            'addon_id' => ['required', 'integer', 'exists:lynomia_marketing_addons,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $link = LynomiaStoreLink::find($validated['link_id']);

        if (! $link) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        $grant = LynomiaStoreAddon::create([
            'store_id' => $link->store_id,
            'addon_id' => $validated['addon_id'],
            'quantity' => (int) $validated['quantity'],
            'expires_at' => $validated['expires_at'] ?? null,
            'granted_by' => auth()->id(),
            'note' => $validated['note'] ?? null,
        ]);

        AuditLog::record('lynomia_marketing_addon_granted', $link, [], [
            'store_id' => $link->store_id, 'addon_id' => $grant->addon_id,
            'quantity' => $grant->quantity, 'expires_at' => optional($grant->expires_at)->toIso8601String(),
            'actor_id' => auth()->id(),
        ]);

        // Quietly, and the sweep behind it is what makes that safe: an unreachable workspace
        // must not fail an operator's grant, and lynomia:reconcile-events re-pushes the
        // allowance for every link every five minutes rather than losing it.
        $this->marketing->syncQuietly($link, auth()->user());

        return back()->with('success', __('Add-on granted.'));
    }

    /**
     * Push a plan's marketing allowance to every connected store on that plan.
     *
     * Quietly: one unreachable workspace must not block an operator's configuration change,
     * and the scheduled sweep picks up whatever failed rather than losing it.
     */
    private function resyncPlan(Plan $plan): void
    {
        LynomiaStoreLink::query()
            ->where('state', 'active')
            ->whereNotNull('lynomia_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($links) use ($plan) {
                foreach ($links as $link) {
                    $owner = User::find($link->owner_user_id);

                    if ($owner === null || (int) $owner->plan_id !== (int) $plan->id) {
                        continue;
                    }

                    $this->marketing->syncQuietly($link, auth()->user());
                }

                return true;
            });
    }

    public function updateSeats(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            // `nullable` IS the product feature, not laxity -- an empty field is how an operator
            // says "grant nothing", which is a different statement from "grant zero".
            'seats' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $plan = Plan::find($validated['plan_id']);

        if (! $plan) {
            return back()->with('error', __('That plan no longer exists.'));
        }

        $seats = $validated['seats'] === null || $validated['seats'] === ''
            ? null
            : (int) $validated['seats'];

        $before = $plan->lynomia_agent_seats;
        $plan->lynomia_agent_seats = $seats;
        $plan->save();

        AuditLog::record('lynomia_plan_seats_changed', $plan, ['lynomia_agent_seats' => $before], [
            'lynomia_agent_seats' => $seats,
            'plan_id' => $plan->id,
            'actor_id' => auth()->id(),
        ]);

        // Push the new ceiling to the connected stores on this plan. Quietly, because one
        // unreachable workspace must not block an operator's configuration change -- but the
        // counts below are the TRUTH, not the number of attempts, and whatever fails here is
        // picked up by the scheduled sweep rather than lost.
        $synced = 0;
        $failed = 0;

        LynomiaStoreLink::query()
            ->where('state', 'active')
            ->whereNotNull('lynomia_account_id')
            ->orderBy('id')
            ->chunkById(100, function ($links) use ($plan, &$synced, &$failed) {
                foreach ($links as $link) {
                    $owner = User::find($link->owner_user_id);

                    if ($owner === null || (int) $owner->plan_id !== (int) $plan->id) {
                        continue;
                    }

                    $this->seats->syncQuietly($link, auth()->user()) ? $synced++ : $failed++;
                }

                return true;
            });

        if ($failed > 0) {
            return back()->with('warning', __('Included seats updated for :plan. :count store(s) synced, :failed could not be reached and will be retried automatically.', [
                'plan' => $plan->name, 'count' => $synced, 'failed' => $failed,
            ]));
        }

        return back()->with('success', __('Included seats updated for :plan (:count store(s) synced).', [
            'plan' => $plan->name,
            'count' => $synced,
        ]));
    }

    /**
     * Mint the commerce credential for a store, and revoke whatever came before it.
     *
     * SHOWN ONCE AND NEVER STORED. The credential is derived from the installation's master key
     * plus the store id and a version (see CommerceCredential), so there is nothing in the
     * database to read it back out of -- which is the point, given APP_KEY is published in this
     * installation and the `encrypted` cast therefore protects nothing. An operator who loses it
     * issues another, and issuing another revokes the first.
     *
     * ISSUING IS REVOKING. Bumping the version changes the signed material, so every credential
     * minted before this call stops verifying immediately, without a deploy and without a row
     * to delete.
     */
    public function issueCommerceCredential(int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        if (! $model->allowsSso()) {
            return back()->with('error', __('That workspace connection is not active.'));
        }

        $previous = (int) $model->commerce_credential_version;

        $model->forceFill([
            'commerce_credential_version' => $previous + 1,
            'commerce_credential_issued_at' => now(),
        ])->save();

        AuditLog::record('lynomia_commerce_credential_issued', $model, [
            'commerce_credential_version' => $previous,
        ], [
            'commerce_credential_version' => $previous + 1,
            'store_id' => $model->store_id,
            'actor_id' => auth()->id(),
            // The credential itself is NEVER written to the audit trail. Only the fact and the
            // version, which is what an operator needs to answer "when was this rotated".
        ]);

        return back()
            ->with('success', __('A new commerce credential was issued. The previous one no longer works.'))
            ->with('lynomia_commerce_credential', CommerceCredential::issue($model->fresh()));
    }

    /**
     * Turn the credential off without issuing a replacement.
     */
    public function revokeCommerceCredential(int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        $previous = (int) $model->commerce_credential_version;

        // Version 0 is the "never issued" state and nothing verifies against it, so bumping is
        // also how a revocation is expressed -- there is no separate disabled flag to drift.
        $model->forceFill([
            'commerce_credential_version' => $previous + 1,
            'commerce_credential_issued_at' => null,
        ])->save();

        AuditLog::record('lynomia_commerce_credential_revoked', $model, [
            'commerce_credential_version' => $previous,
        ], [
            'commerce_credential_version' => $previous + 1,
            'store_id' => $model->store_id,
            'actor_id' => auth()->id(),
        ]);

        return back()->with('success', __('The commerce credential has been revoked.'));
    }

    public function approve(ApproveLinkRequest $request, int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection request no longer exists.'));
        }

        try {
            $this->linker->approve(
                $model,
                $request->user(),
                (string) $request->validated('verification_code')
            );
        } catch (LynomiaApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The workspace connection has been approved.'));
    }

    public function revoke(int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        $this->linker->revoke($model, auth()->user(), 'Revoked by platform operator.');

        return back()->with('success', __('The workspace connection has been revoked.'));
    }

    /**
     * Undo a revocation.
     *
     * Without this, revoking for a billing hold -- the ordinary reason to revoke -- was a one
     * way door: the merchant could not re-claim (a revoked row is refused), could not
     * provision (same), and could not move to another store either, because the revoked row
     * still owned the account id under the unique index.
     */
    public function restore(int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        try {
            $restored = $this->linker->restore($model, auth()->user());
        } catch (LynomiaApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $restored->isActive()
            ? __('The workspace connection has been restored.')
            : __('The connection has been reset. The merchant can connect their workspace again.'));
    }
}
