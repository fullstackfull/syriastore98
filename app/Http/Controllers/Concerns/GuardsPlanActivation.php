<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use Illuminate\Support\Facades\Log;

/**
 * THE PLAN-PURCHASE MONEY RULES, IN ONE PLACE.
 * =============================================================================================
 *
 * Twenty plan-purchase return handlers recorded the PlanOrder price from REQUEST-SUPPLIED data,
 * and fifteen of those activated the subscription on the strength of something the caller
 * supplies (`status=success`, `responsecode=0`, `Status=Complete`, a session flag, or nothing
 * at all). Each gateway's own checkout initiator publishes the replayable return URL, so a
 * merchant who starts ONE legitimate checkout holds every parameter needed to replay it: one
 * authenticated GET then activated any paid plan for zero.
 *
 * Fixing that twenty times over by hand is how a money rule drifts, so the three rules live
 * here and each handler calls them:
 *
 *   assertPlanActivationEnabled()      fail closed for a provider that cannot be verified
 *   serverOwnedPlanPrice()             the recorded price comes from the PLAN, never the request
 *   existingPlanOrderForProviderTxn()  a replayed provider transaction is a no-op
 *
 * WHY THIS IS A TRAIT AND NOT A SERVICE. These are controllers in a commercial upstream product
 * (Workdo eCommerceGo SaaS); the smaller the structural change, the smaller the merge surface
 * when upstream ships its next version. A trait adds three method names to a controller and
 * nothing else.
 *
 * WHAT IS DELIBERATELY NOT HERE. No amount-mismatch refusal. The already-repaired eSewa and
 * Sofort handlers DO refuse a return whose claimed `amount` disagrees with the recomputed
 * price, and that is safe for them because THIS server builds their success_url, so the units
 * and field names are server-generated and known. It is NOT safe in general: a verified
 * provider may legitimately post an amount back in minor units, and a blind comparison would
 * refuse real payments on a live integration. For the fifteen forgeable providers the refusal is
 * unconditional anyway (assertPlanActivationEnabled), which is strictly stronger than
 * refusing only on a mismatch; for the eight verified ones the price is simply recorded
 * correctly and nothing is refused.
 *
 * WHAT NONE OF THIS CLAIMS. It does not verify that money moved. No payment provider is
 * reachable from this environment. These rules close the free activation and the forged amount;
 * they do not make any integration trustworthy.
 */
trait GuardsPlanActivation
{
    /**
     * REFUSE PLAN ACTIVATION FOR A PROVIDER THAT CANNOT BE VERIFIED. Call this FIRST.
     *
     * It must run before the PlanOrder is built and before assignPlan(), so that a refusal
     * leaves no partial row and no partial entitlement behind.
     *
     * 403, NEVER 401. Repo B's lib/network/network.dart maps ANY 401 into a forced logout that
     * clears stored preferences; it handles 403 as "not allowed" without logging the user out.
     * A 401 here would sign merchants out of the mobile admin app on a refused payment return.
     *
     * A MISSING CONFIG KEY FAILS CLOSED. config('payments.plan_activation.<provider>') returning
     * null -- because the line was deleted, or the provider name was misspelt at the call site --
     * is treated as disabled, not as enabled, and logged as a misconfiguration. The safe
     * direction for an accident is "refuse", not "activate".
     *
     * The refusal message names no secret, no internal path and no setting value.
     */
    protected function assertPlanActivationEnabled(string $provider, $plan = null): void
    {
        $configured = config('payments.plan_activation.' . $provider);

        if ($configured === null) {
            Log::error('Plan activation refused: provider is not present in config/payments.php.', [
                'provider'   => $provider,
                'controller' => static::class,
                'plan_id'    => is_object($plan) ? ($plan->id ?? null) : $plan,
                'user_id'    => optional(\Auth::user())->id,
            ]);

            abort(403, __('This payment method is not available for subscription purchases.'));
        }

        // filter_var rather than a bare cast: env() hands back the string '0' or 'false' for
        // PLAN_ACTIVATION_X=false, and (bool) '0' is false but (bool) 'false' is TRUE -- which
        // would quietly enable a provider the owner had just turned off.
        if (! filter_var($configured, FILTER_VALIDATE_BOOLEAN)) {
            Log::warning('Plan activation refused: provider is disabled pending verification.', [
                'provider'   => $provider,
                'controller' => static::class,
                'plan_id'    => is_object($plan) ? ($plan->id ?? null) : $plan,
                'user_id'    => optional(\Auth::user())->id,
            ]);

            abort(403, __('This payment method is not available for subscription purchases.'));
        }
    }

    /**
     * THE PRICE OF A PLAN PURCHASE, COMPUTED FROM THE PLAN. Never from the request.
     *
     * This is the whole of the pricing defect: every one of these handlers read the amount off
     * the return URL or the posted body, so `&amount=0` recorded a zero-rand purchase of a paid
     * plan -- and on the fifteen ungated providers, activated it.
     *
     * THE COUPON IS LOOKED UP WITHOUT THE `is_active` FILTER, ON PURPOSE. Each gateway's
     * checkout initiator deactivates a coupon once its usage limit is reached. A coupon that was
     * valid when checkout started can therefore be inactive by the time the provider returns,
     * and filtering on is_active here would recompute a HIGHER price than the server itself
     * advertised and charged. The coupon still has to EXIST, so this cannot be used to invent a
     * discount. (The same reasoning, and the same choice, as the repaired eSewa and Sofort
     * handlers.)
     *
     * THE NEGATIVE-PRICE RULE MIRRORS THE CONTROLLERS' OWN addpayment(). Those compute
     * `$price = $plan->price - $discount; if ($price < 0) { $price = $plan->price; }` -- so a
     * coupon whose percentage exceeds 100 charges the FULL price rather than nothing. That rule
     * is reproduced here rather than clamping to zero, because the recorded amount must match
     * what the server actually charged; clamping to zero would UNDER-record revenue. The two
     * rules agree for every discount from 0 to 100 inclusive, so this only bites on a coupon
     * that no admin UI can create.
     */
    protected function serverOwnedPlanPrice(Plan $plan, $couponCode = null): float
    {
        $planPrice = (float) $plan->price;
        $price     = $planPrice;

        $coupon = $this->planCouponForPricing($couponCode);

        if ($coupon !== null) {
            $price = $planPrice - (($planPrice / 100) * (float) $coupon->discount);

            if ($price < 0) {
                $price = $planPrice;
            }
        }

        return round(max($price, 0.0), 2);
    }

    /**
     * The coupon used for PRICING: must exist, is_active NOT required. See the note above.
     */
    protected function planCouponForPricing($code): ?PlanCoupon
    {
        return $this->findPlanCouponExact($code, false);
    }

    /**
     * The coupon used for RECORDING a usage: is_active IS required, matching the behaviour of
     * the upstream code this replaces.
     */
    protected function planCouponForRecording($code): ?PlanCoupon
    {
        return $this->findPlanCouponExact($code, true);
    }

    /**
     * A CASE-EXACT COUPON LOOKUP THAT RUNS ON MORE THAN MySQL.
     *
     * The upstream lookup is `PlanCoupon::whereRaw('BINARY `code` = ?', [$code])`, which is
     * MySQL-only: on sqlite it is a hard syntax error --
     *   SQLSTATE[HY000]: General error: 1 near "`code`": syntax error
     * -- and in five of these handlers (Nepalste, PayHere, PhonePe, Paddle, FedPay) that query
     * runs UNCONDITIONALLY after the PlanOrder is saved and before assignPlan(). So on any
     * non-MySQL connection those handlers write an order row and then throw, leaving the order
     * recorded and the plan NOT assigned. It also made the legitimate-activation path
     * impossible to test at all, which is why it is replaced here rather than merely noted.
     *
     * BINARY's purpose is case-sensitivity, and that is preserved: the query is a portable
     * equality (which MySQL's default collation answers case-INsensitively), and the strict
     * string comparison afterwards restores the case-exact semantics on every driver.
     *
     * An empty code returns null without querying -- the upstream code issued the query anyway,
     * with a null bound parameter.
     */
    private function findPlanCouponExact($code, bool $requireActive): ?PlanCoupon
    {
        if ($code === null || $code === '' || $code === 0 || $code === '0') {
            return null;
        }

        $code = (string) $code;

        $query = PlanCoupon::query()->where('code', $code);

        if ($requireActive) {
            $query->where('is_active', '1');
        }

        foreach ($query->get() as $candidate) {
            if ((string) $candidate->code === $code) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * HAS THIS PROVIDER TRANSACTION ALREADY BEEN RECORDED?
     *
     * A gateway WILL retry a notification, and a merchant can replay a return URL out of their
     * own browser history. Today that produces a SECOND PlanOrder and a second assignPlan():
     * plan_orders.order_id is UNIQUE, but every handler mints a fresh
     * `strtoupper(str_replace('.', '', uniqid('', true)))` per call, so the constraint catches
     * nothing on a replay.
     *
     * Real idempotency has to key on the PROVIDER's transaction identifier, and this returns the
     * already-recorded row for one so the caller can return success without writing again.
     *
     * THE READ MUST NOT BE SERVED FROM A CACHE, AND IT MUST NOT BE A RAW QUERY.
     *
     * PlanOrder composes GeneaLabs\LaravelModelCaching\Traits\Cachable, and an idempotency
     * check is exactly the read that must not come out of a cache -- so this first used
     * DB::table('plan_orders'). That was wrong for a different reason:
     * tests/Feature/Tenancy/RawQueryTenancyInventoryTest counts every raw query-builder entry
     * point in app/ precisely because a raw query is invisible to every Eloquent global scope,
     * and it went red on the new one (app 111 -> 112, plus a new plan_orders row). Settling a
     * caching question by adding a raw entry point trades a caching problem for a
     * tenancy-visibility problem.
     *
     * disableCache() is the scope Cachable itself provides
     * (vendor/genealabs/laravel-model-caching/src/Traits/ModelCaching.php:157), so the read is
     * Eloquent -- visible to any scope PlanOrder ever acquires -- and still uncached.
     *
     * WHERE NO PROVIDER TRANSACTION IDENTIFIER IS AVAILABLE, this is not called and no claim of
     * idempotency is made. Only two of the fifteen forgeable handlers read one that is
     * identifiable without guessing a field name off the wire -- Ozow's `TransactionId` and
     * SenangPay's `transaction_id` -- and those two are the ones with a replay test. For the
     * other ten the replay is a no-op only because activation is refused outright, which the
     * tests assert instead.
     */
    protected function existingPlanOrderForProviderTxn(string $paymentType, $txnId)
    {
        if ($txnId === null || $txnId === '') {
            return null;
        }

        return PlanOrder::disableCache()
            ->where('payment_type', $paymentType)
            ->where('txn_id', (string) $txnId)
            ->first();
    }
}
