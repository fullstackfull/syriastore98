<?php

namespace Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Requests\ApproveLinkRequest;
use Workdo\LynomiaChat\app\Models\LynomiaCommerceEvent;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaPlatformNotice;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaMarketingAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\InstallationLifecycle;
use Workdo\LynomiaChat\app\Services\CommerceCredential;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Services\PlatformWorkspaceGateway;
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
        private readonly MarketingAllowanceSync $marketing,
        private readonly InstallationLifecycle $lifecycle
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
            // Phase 6 / B3.2. Consent decisions fail differently again: the common transient
            // is the shopper not yet being a known contact in Lynomia, which can legitimately
            // take weeks, so a waiting row is not itself a fault. What an operator needs to see
            // is the ones that ran out of time and were retired undelivered.
            'consentHealth' => $this->consentHealth(),
            // Phase 6E. Operational communication to merchants, from Store Syria's own
            // workspace. Read from this side's own table: what was sent, what was not, and
            // what nobody knows about.
            'platformComms' => $this->platformComms(),
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
     * Operational notices to merchants: what landed, what did not, and what is unresolved.
     *
     * `indeterminate` IS THE NUMBER THAT MATTERS. A notice whose answer was lost may or may
     * not have reached the merchant, and nothing on Lynomia's send path would recognise a
     * repeat -- so it is never retried automatically and an operator decides. A count of
     * those is the only way that decision gets made at all.
     *
     * WHICH WORKSPACE, SHOWN RATHER THAN ASSUMED. The three ids are environment settings an
     * operator types after provisioning, and nothing on this side can tell a platform-owned
     * workspace from a merchant's -- the flag that does is Lynomia's. Pointed at a merchant's
     * account and inbox, this would send from a merchant's own WhatsApp channel, which the
     * authorisation forbids outright, and Lynomia's UTILITY-only gate would not apply either,
     * because that gate keys on the account being platform-owned. So the ids in use are on
     * the page: the operator who set them is the one person who can see they are wrong.
     *
     * @return array{configured:bool, workspace:?int, inbox:?int, accepted:int, in_flight:int,
     *               failed:int, indeterminate:int, recent:mixed}
     */
    private function platformComms(): array
    {
        return [
            'configured' => app(PlatformWorkspaceGateway::class)->configured(),
            'workspace' => config('lynomia.platform_workspace_account_id'),
            'inbox' => config('lynomia.platform_workspace_inbox_id'),
            // "accepted", not "sent": Lynomia answers 200 when the message row exists, and its
            // own UTILITY and consent gates run afterwards in a job. This number is how many
            // Lynomia took, not how many reached a merchant, and the label says so.
            'accepted' => LynomiaPlatformNotice::query()->where('status', LynomiaPlatformNotice::SENT)->count(),
            // Claimed and unresolved. Invisible in every other tile before this: not a
            // success, not a failure, not an unknown answer.
            'in_flight' => LynomiaPlatformNotice::query()->inFlight()->count(),
            'failed' => LynomiaPlatformNotice::query()->where('status', LynomiaPlatformNotice::FAILED)->count(),
            'indeterminate' => LynomiaPlatformNotice::query()
                ->where('status', LynomiaPlatformNotice::INDETERMINATE)->count(),
            'recent' => LynomiaPlatformNotice::query()->latest('id')->limit(25)->get(),
        ];
    }

    /**
     * Outbound consent decisions: what is waiting, what is stuck, and what was given up on.
     *
     * A DECISION RETIRED UNDELIVERED IS THE NUMBER THAT MATTERS. Until B3.2 there was no such
     * thing -- a decision Lynomia could not accept was retried hourly for ever, so the only
     * visible symptom was a backlog that never drained and nothing distinguished "waiting for
     * a shopper who will write in next week" from "waiting for something that will never
     * happen". `expired` counts the rows the dispatcher has now given up on, which is the set
     * an operator can actually act on.
     *
     * Retired rows carry `delivered_at` with no `remote_outcome`, which is the same shape the
     * merchant-facing screen already renders as "Not sent" -- they are not hidden from the
     * merchant to make this panel look better.
     *
     * @return array{pending:int, stuck:int, expired:int, oldest:?string, stores:array<int,int>}
     */
    private function consentHealth(): array
    {
        $pending = LynomiaConsentDecision::query()->whereNull('delivered_at');

        return [
            'pending' => (clone $pending)->count(),
            'stuck' => (clone $pending)->where('attempts', '>=', self::STUCK_AFTER_ATTEMPTS)->count(),
            'expired' => LynomiaConsentDecision::query()
                ->whereNotNull('delivered_at')
                ->whereNull('remote_outcome')
                ->where('last_error', 'like', 'expired_after_%')
                ->count(),
            'oldest' => (clone $pending)->oldest('id')->first()?->captured_at?->diffForHumans(),
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

    /**
     * P6B. A HOLD, NOT AN ENDING. The merchant keeps their workspace and their data; the
     * installation stops acting until somebody resumes it. Distinct from revoke, which
     * withdraws the arrangement and takes the merchant's access with it.
     */
    public function suspend(Request $request, int $link): RedirectResponse
    {
        return $this->lifecycleAction(
            $request, $link,
            fn ($model, $reason) => $this->lifecycle->suspend($model, auth()->user(), $reason),
            __('The installation is suspended. It will not send anything until it is resumed.')
        );
    }

    public function resume(Request $request, int $link): RedirectResponse
    {
        return $this->lifecycleAction(
            $request, $link,
            fn ($model, $reason) => $this->lifecycle->resume($model, auth()->user(), $reason),
            __('The installation is active again.')
        );
    }

    /**
     * Ended for good. Lynomia drops its stored credentials, so unlike a revocation this
     * cannot be undone -- which is why it asks for a reason and says so in the confirmation.
     * The merchant's conversations and the consent evidence are kept.
     */
    public function decommission(Request $request, int $link): RedirectResponse
    {
        return $this->lifecycleAction(
            $request, $link,
            fn ($model, $reason) => $this->lifecycle->decommission($model, auth()->user(), $reason),
            __('The installation has been decommissioned. Its history and consent records are kept.')
        );
    }

    /**
     * THE WAY OUT OF A REJECTED CREDENTIAL. Mints a replacement and asks Lynomia to bring the
     * installation back -- and upstream's resume checks the store can actually be reached, so
     * a credential that still does not work is reported here rather than discovered later by
     * a campaign that sends nothing.
     */
    public function reauthenticate(int $link): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        [, $credential, $resumed] = $this->lifecycle->reauthenticate($model, auth()->user());

        // THE CREDENTIAL IS SHOWN EITHER WAY. Issuing it invalidates the one Lynomia is
        // holding, so the resume that follows usually cannot succeed yet -- that is the normal
        // path, not a failure. An operator left looking at an error with no credential to
        // paste would be worse off than before they pressed the button.
        return back()
            ->with('success', $resumed
                ? __('A new commerce credential was issued and the installation was brought back.')
                : __('A new commerce credential was issued. Paste it into Lynomia, then press Resume.'))
            ->with('lynomia_commerce_credential', $credential);
    }

    /**
     * The three state transitions differ only in which method they call and what they say, so
     * the reason handling, the missing-row case and the refusal are in one place rather than
     * three.
     */
    private function lifecycleAction(Request $request, int $link, callable $move, string $success): RedirectResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model) {
            return back()->with('error', __('That connection no longer exists.'));
        }

        $reason = trim((string) $request->input('reason'));

        if ($reason === '') {
            return back()->with('error', __('Say why, so the next operator can read it.'));
        }

        try {
            $move($model, $reason);
        } catch (LynomiaApiException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }

    /**
     * WHAT LYNOMIA SEES OF THIS INSTALLATION -- the half of the picture this side cannot work
     * out for itself.
     *
     * Every incident in the Phase 5 register had this shape: a token rejected unattended, a
     * store dropping out of the counted set, an outbound consent decision retired, a campaign
     * blocked for want of a capability. In each one the fact existed on one side and the
     * person who had to act was on the other.
     *
     * ON DEMAND, AND JSON. The rest of this page is built from local tables precisely so that
     * it renders when Lynomia is unreachable, which is when an operator most wants it. Folding
     * a remote call into `show()` would undo that. So this is its own request, its failure is
     * one card's message rather than a blank page, and the operator chose to make it.
     *
     * A LINK, NOT AN ACCOUNT ID FROM THE CLIENT. The account asked about is the one recorded
     * against this link, so an operator cannot aim the call at a workspace this installation
     * does not hold -- and Lynomia refuses that independently anyway.
     */
    public function remoteHealth(int $link): JsonResponse
    {
        $model = LynomiaStoreLink::find($link);

        if (! $model?->lynomia_account_id) {
            return response()->json(['error' => __('That connection has no Lynomia workspace yet.')], 404);
        }

        try {
            return response()->json($this->api->integrationHealth((int) $model->lynomia_account_id));
        } catch (LynomiaApiException $e) {
            // The typed message, never the provider's prose: LynomiaApiException is where that
            // boundary is drawn for every other surface in this module.
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }
}
