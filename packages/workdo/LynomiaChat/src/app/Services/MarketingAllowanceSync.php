<?php

namespace Workdo\LynomiaChat\app\Services;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreAddon;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;

/**
 * Pushes "this merchant may send N marketing messages a period" to Lynomia (P5G).
 *
 * THE SAME TWO AUTHORITIES AS SeatAllowanceSync, and for the same reason. Store Syria decides
 * what a package includes and what a merchant has bought on top of it -- commercial decisions
 * about its own product. Lynomia decides whether a particular message goes out, which it has
 * to decide anyway at the send boundary. This is the one wire between them and it carries a
 * number in one direction.
 *
 * INCLUDED AND ADD-ONS ADD UP HERE; LYNOMIA DOES NOT ADD. Both halves are Store Syria's own
 * commercial decision about one merchant, so summing them is summing one authority's numbers
 * -- which is right. Lynomia then composes THAT single number with whatever else the account
 * has, by MAX, so a merchant who also pays Lynomia directly keeps the larger of the two
 * rather than the sum. Adding across the two platforms would be additive stacking of
 * overlapping quotas, which is the mistake Billing::Entitlements is written to avoid.
 *
 * A PLAN THAT SAYS NOTHING AND NO ADD-ONS GRANT NOTHING. The grant is withdrawn rather than
 * set to zero -- those are different, and zero would stop a merchant's campaigns because
 * somebody had not filled a field in yet. An add-on alone is enough to produce a grant: that
 * is what makes a trial work for a merchant on a plan that includes none.
 *
 * EXPIRED ADD-ONS STOP COUNTING, which is also how a trial ends. Nothing deletes the row --
 * the ledger is the record of what was given and when -- and the next sync simply computes a
 * smaller number.
 */
class MarketingAllowanceSync
{
    public const RESOURCE = 'campaign_sends';

    public function __construct(private readonly PlatformApiClient $api)
    {
    }

    /**
     * Bring Lynomia's grant for this store's workspace in line with its package and add-ons.
     *
     * @return int|null the sends now included, or null when nothing grants any
     */
    public function sync(LynomiaStoreLink $link, ?User $actor = null): ?int
    {
        $accountId = $link->lynomia_account_id;

        if ($accountId === null) {
            return null;
        }

        $allowance = $this->allowanceFor($link);

        try {
            if ($allowance === null) {
                $this->api->withdrawEntitlementGrant((int) $accountId, self::RESOURCE);
            } else {
                $this->api->grantEntitlement((int) $accountId, self::RESOURCE, $allowance, $this->planRef($link));
            }
        } catch (LynomiaApiException $e) {
            // NOT RE-THROWN. This runs behind an operator granting an add-on and behind a
            // plan change; neither should fail because Lynomia is briefly unreachable, and
            // the next sync corrects it. Logged with the store so it is findable.
            Log::warning('[lynomia] could not sync the marketing allowance', [
                'store_id' => $link->store_id,
                'lynomia_account_id' => $accountId,
                'kind' => $e->kind,
            ]);

            return null;
        }

        AuditLog::record('lynomia_marketing_allowance_synced', $link, [], [
            'store_id' => $link->store_id,
            'lynomia_account_id' => $accountId,
            'included_sends' => $this->includedSendsFor($link),
            'addon_sends' => $this->addonSendsFor($link),
            'total' => $allowance,
            'actor_id' => $actor?->id,
        ]);

        return $allowance;
    }

    /**
     * The package plus everything currently valid, or null when neither says anything.
     *
     * Null rather than zero when the plan is silent AND there are no add-ons, because those
     * are different facts: "nobody has decided" must not stop a merchant's campaigns.
     */
    public function allowanceFor(LynomiaStoreLink $link): ?int
    {
        $included = $this->includedSendsFor($link);
        $addons = $this->addonSendsFor($link);

        if ($included === null && $addons === 0) {
            return null;
        }

        return (int) $included + $addons;
    }

    /**
     * What the store owner's plan includes, or null when it says nothing.
     *
     * READ FROM THE OWNER'S PLAN, like the seat allowance: entitlement in this product is the
     * tenant owner's, and resolving it from anywhere else would make the two disagree.
     */
    public function includedSendsFor(LynomiaStoreLink $link): ?int
    {
        $plan = $this->planFor($link);

        if ($plan === null || $plan->lynomia_campaign_sends === null) {
            return null;
        }

        return max(0, (int) $plan->lynomia_campaign_sends);
    }

    /** Every add-on this store holds that has not expired, summed. */
    public function addonSendsFor(LynomiaStoreLink $link): int
    {
        return LynomiaStoreAddon::forStore($link->store_id)
            ->current()
            ->with('addon')
            ->get()
            ->sum(fn (LynomiaStoreAddon $grant) => $grant->sends());
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
