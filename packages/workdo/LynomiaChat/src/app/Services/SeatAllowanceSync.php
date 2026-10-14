<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Pushes "this merchant's package includes N agent seats" to Lynomia.
 *
 * TWO AUTHORITIES, AND NEITHER IS BOTH. Store Syria decides what a package includes -- that is a
 * commercial decision about its own product, made by its own operators. Lynomia decides what an
 * account may actually do -- that is a technical decision it has to make anyway, at the point
 * where somebody is added. This service is the one wire between them, and it carries a number in
 * one direction only.
 *
 * IT GRANTS; IT DOES NOT CHARGE. Lynomia's own subscription machinery is deliberately not touched:
 * a Store Syria merchant gets a ceiling through PlatformAppEntitlementGrant WITHOUT a Lynomia
 * BillingSubscription, so there is no second place they could be billed for seats their Store
 * Syria package already covers. That is also why the grant layer had to exist at all -- before it,
 * the only way to give an account a ceiling was to sell it a Lynomia plan.
 *
 * IT CANNOT TAKE AWAY WHAT SOMEBODY BOUGHT SEPARATELY. Lynomia composes this grant with the
 * account's own plan by MAX and lets an operator's explicit override beat both, so a merchant who
 * pays Lynomia directly for ten agents keeps ten even if their Store Syria package mentions two.
 *
 * A PLAN THAT SAYS NOTHING GRANTS NOTHING. A null allowance withdraws the grant rather than
 * granting zero -- those are different, and granting zero would lock a merchant out of an inbox
 * they are using because somebody had not filled a field in yet.
 */
class SeatAllowanceSync
{
    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Bring Lynomia's grant for this store's workspace in line with its owner's current plan.
     *
     * @return int|null the seats now included, or null when the plan says nothing
     */
    public function sync(LynomiaStoreLink $link, ?User $actor = null): ?int
    {
        $accountId = $link->lynomia_account_id;

        if ($accountId === null) {
            return null;
        }

        $seats = $this->includedSeatsFor($link);

        if ($seats === null) {
            $this->api->withdrawEntitlementGrant((int) $accountId, 'agents');
        } else {
            $this->api->grantEntitlement((int) $accountId, 'agents', $seats, $this->planRef($link));
        }

        // AUDITED ON THIS SIDE TOO. Lynomia records what it was told; this records what was
        // decided and by whom, which is the half an operator here can actually investigate.
        AuditLog::record('lynomia_seat_allowance_synced', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $accountId,
            'included_seats' => $seats,
            'plan_ref' => $this->planRef($link),
            'actor_id' => $actor?->id,
        ]);

        return $seats;
    }

    /**
     * The same sync, but never fatal to the caller.
     *
     * USED WHERE THE SEAT ALLOWANCE IS NOT THE POINT OF THE REQUEST -- at the moment a workspace
     * goes live, where the workspace already exists and has been recorded. Failing the whole
     * provisioning because Lynomia was briefly unreachable while being told a number would
     * report a failure for work that succeeded, and would send the merchant back to retry
     * something that is already done. The grant is pushed again on the next sync, and until then
     * the account simply has no integration ceiling, which is the state it was in a moment ago.
     */
    public function syncQuietly(LynomiaStoreLink $link, ?User $actor = null): void
    {
        try {
            $this->sync($link, $actor);
        } catch (LynomiaApiException $e) {
            Log::warning('[lynomia] could not sync the included seat allowance', [
                'store_id' => $link->store_id,
                'lynomia_account_id' => $link->lynomia_account_id,
                'kind' => $e->kind,
            ]);
        }
    }

    /**
     * What the store owner's plan includes, or null when it says nothing.
     *
     * READ FROM THE OWNER'S PLAN, not from the store or the link. Entitlement in this product is
     * the tenant owner's -- PlanModuleCheck resolves the module list the same way -- so the seat
     * allowance has to be resolved from the same place or the two would disagree.
     */
    public function includedSeatsFor(LynomiaStoreLink $link): ?int
    {
        $plan = $this->planFor($link);

        if ($plan === null || $plan->lynomia_agent_seats === null) {
            return null;
        }

        return max(0, (int) $plan->lynomia_agent_seats);
    }

    public function planFor(LynomiaStoreLink $link): ?Plan
    {
        $owner = User::find($link->owner_user_id);

        return $owner?->plan_id ? Plan::find($owner->plan_id) : null;
    }

    private function planRef(LynomiaStoreLink $link): ?string
    {
        $plan = $this->planFor($link);

        return $plan ? "plan:{$plan->id}" : null;
    }
}
