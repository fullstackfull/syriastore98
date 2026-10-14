<?php

namespace Workdo\LynomiaChat\app\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Requests\ApproveLinkRequest;
use Workdo\LynomiaChat\app\Models\LynomiaInboxEvent;
use Workdo\LynomiaChat\app\Models\LynomiaStoreIdentity;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
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
    public function __construct(
        private readonly PlatformApiClient $api,
        private readonly AccountLinker $linker,
        private readonly SeatAllowanceSync $seats
    ) {
    }

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
        ]);
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

        // Push the new ceiling to every connected store on this plan. Quietly: a workspace that
        // is unreachable right now must not block an operator's configuration change, and the
        // next sync picks it up.
        $synced = 0;

        LynomiaStoreLink::query()
            ->where('state', 'active')
            ->whereNotNull('lynomia_account_id')
            ->get()
            ->each(function (LynomiaStoreLink $link) use ($plan, &$synced) {
                $owner = User::find($link->owner_user_id);

                if ($owner === null || (int) $owner->plan_id !== (int) $plan->id) {
                    return;
                }

                $this->seats->syncQuietly($link, auth()->user());
                $synced++;
            });

        return back()->with('success', __('Included seats updated for :plan (:count store(s) synced).', [
            'plan' => $plan->name,
            'count' => $synced,
        ]));
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
