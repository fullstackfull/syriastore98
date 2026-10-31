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
     * REFUSE BEFORE PERSISTING WHEN THE PROVIDER'S CREDENTIALS ARE NOT THERE — F234.
     *
     * **THE DEFECT THIS EXISTS FOR.** `CoingateController::coingatePaymentPrepare()` persisted a
     * `PlanUserCoupon` row and could deactivate the coupon outright **fifty lines before** it
     * read `$admin_payment_setting['coingate_mode']` — a bare array subscript on the result of
     * `getSuperAdminAllSetting()`, which plucks only the setting rows that EXIST and supplies no
     * defaults. On an installation that never saved its CoinGate settings that read raises, the
     * method's own `catch` turns it into a flash redirect, and the coupon use is already committed
     * to a request that could not possibly have produced a payment.
     *
     * Worse than one wasted use: `PlanCoupon::used_coupon()` counts EVERY user's rows, so the
     * limit is platform-wide. One merchant retrying an unconfigured gateway drives the count to
     * `limit` and deactivates the coupon **for everybody**. No transaction wraps any of it.
     *
     * **NO NEW DECISION WAS NEEDED.** D-28b settled it: F196 **Option A** — *the refusal must
     * precede persistence and every side effect* — with Option B (persist, then paper over it)
     * explicitly REJECTED by the owner because it leaves the data inconsistent.
     *
     * **403, NEVER 401, and never a returned redirect.** The 401 rule is the same one
     * `assertPlanActivationEnabled()` documents above: Repo B's `lib/network/network.dart` maps any
     * 401 into a forced logout. The reason this ABORTS rather than returning a redirect for the
     * caller to pass on is narrower and specific to this repository's history: a guard whose
     * refusal has to be `return`ed is a guard somebody can forget to return, and *"a guard that
     * refuses after persisting is not a guard"* is the exact sentence the F196 row uses. `abort()`
     * cannot be forgotten.
     *
     * **IT MUST BE CALLED OUTSIDE ANY `try`.** `HttpException` extends `\Exception`, so an
     * `abort(403)` placed inside a handler that wraps its body in `try { } catch (\Exception $e)`
     * — which CoinGate does — is **swallowed and silently downgraded into a flash redirect**. The
     * refusal then stops being a refusal. `PlanCouponRefusalPersistsNothingTest` drives the status
     * code specifically, because that is the only observable difference between the two placements.
     *
     * **AND IT MUST SIT ABOVE EVERY BRANCH THAT PERSISTS.** Moving a single config read is not
     * sufficient and D-28b says so in terms: CoinGate's free-plan branch also writes before it can
     * fail (`$authuser->plan = $plan->id` throws on a column no migration creates), so a refusal
     * placed between the two branches would still leave the free path burning the coupon.
     *
     * The message names no setting, no key and no value — only that the method is unavailable.
     *
     * @param  string               $provider      canonical provider name, for the log only
     * @param  array<string,mixed>  $settings      the `getSuperAdminAllSetting()` bag
     * @param  array<int,string>    $requiredKeys  setting names that must be present and non-empty
     */
    protected function assertProviderCredentialsPresent(string $provider, array $settings, array $requiredKeys): void
    {
        $missing = [];

        foreach ($requiredKeys as $key) {
            /* is_scalar before the cast: `getSuperAdminAllSetting()` plucks strings so a non-scalar
               is unreachable today, but casting an array to string warns rather than refusing, and
               a guard that emits a warning instead of refusing is the wrong failure direction. */
            if (! isset($settings[$key]) || ! is_scalar($settings[$key]) || trim((string) $settings[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return;
        }

        /* The NAMES of the missing settings are safe to log and are the only useful diagnostic;
           their values are not logged, and none exists anyway. */
        Log::warning('Plan purchase refused before any write: the provider has no usable credentials.', [
            'provider' => $provider,
            'missing_settings' => $missing,
            'controller' => static::class,
            /* No plan_id key: review pointed out that logging it as a literal null reads to an
               operator as "no plan was involved", when a plan always is. The missing settings and
               the controller are the diagnostic; the plan adds nothing to it. */
            'user_id' => optional(\Auth::user())->id,
        ]);

        abort(403, __('This payment method is not available for subscription purchases.'));
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
        /*
         * A NON-SCALAR REFERENCE IS "NOT FOUND", NOT A 500. `?tap_id[]=x` makes `(string) $txnId`
         * raise "Array to string conversion", which Laravel's handler turns into an
         * `ErrorException` -- and the Benefit and Cashfree guard calls sit OUTSIDE their `try`, so
         * a malformed request that used to end in an error redirect would now 500. Found by
         * independent review. An empty or unusable reference cannot be the key of a prior row, so
         * returning null is both safe and the same direction as before.
         */
        if ($txnId === null || ! is_scalar($txnId) || (string) $txnId === '') {
            return null;
        }

        return PlanOrder::disableCache()
            ->where('payment_type', $paymentType)
            ->where('txn_id', (string) $txnId)
            ->first();
    }

    /**
     * EVERY SHIPPED SPELLING OF A PROVIDER'S `payment_type`.
     *
     * The handlers record `__('Benefit')`, `__('Mollie')`, `__('Paystack')` and so on, so the
     * stored value is whatever the BUYER'S locale translated it to. A replay lookup on the English
     * spelling alone misses a row written by an Arabic-locale buyer, and the replay goes through —
     * which is why Paystack built this list for itself under D-12/F87. It is generalised here
     * rather than copied: three private copies of a locale sweep is how one of them goes stale.
     *
     * The canonical spelling is first, so the ordinary case costs one lookup.
     *
     * @return list<string>
     */
    protected function planProviderTypeSpellings(string $canonicalType): array
    {
        $spellings = [$canonicalType];

        foreach ((array) glob(resource_path('lang/*.json')) as $file) {
            $locale = basename((string) $file, '.json');

            if ($locale === 'language') {
                continue;
            }

            $spellings[] = (string) trans($canonicalType, [], $locale);
        }

        return array_values(array_filter(
            array_unique($spellings),
            static fn ($spelling) => trim((string) $spelling) !== ''
        ));
    }

    /**
     * The plan order already recorded against this provider reference, under ANY spelling.
     *
     * DELIBERATELY NOT SCOPED TO THE CALLER. Scoping the LOOKUP would let a second account present
     * the same reference, find nothing, and have its own plan activated on a stranger's payment.
     * One reference, one activation, whoever presents it — the caller's identity decides only the
     * RESPONSE, below.
     */
    protected function existingPlanOrderForProviderReference(string $canonicalType, $reference)
    {
        foreach ($this->planProviderTypeSpellings($canonicalType) as $spelling) {
            $found = $this->existingPlanOrderForProviderTxn($spelling, $reference);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * ONE PROVIDER REFERENCE, ONE PLAN ACTIVATION. Returns a redirect to answer with, or null to
     * carry on.
     *
     * CALL IT BEFORE THE OUTBOUND VERIFICATION, not after. Three reasons, in order of weight:
     *
     *   1. A known-spent reference should not cost an outbound call.
     *   2. A refusal that runs before any network traffic is REACHABLE FROM A TEST. No payment
     *      provider is reachable from this container — Benefit and Cashfree use a raw
     *      `GuzzleHttp\Client` and Mollie its own SDK, none of which `Http::fake()` intercepts —
     *      so a guard placed after the call could not be driven, and a test that tried would be
     *      measuring the network failure instead. MEASURED: the first version of
     *      `PlanActivationReplayGuardTest` passed 12 of 12 against the unguarded code for exactly
     *      that reason.
     *   3. Nothing in the reference's meaning depends on the verification. It is the value the
     *      handler is about to ASK the provider about, read off the request or the session.
     *
     * WHAT IT DOES NOT DO: it does not re-run `assignPlan()`, write a row, or touch a coupon. A
     * replay performs no work at all. The same buyer returning to the same plan is answered with
     * the success they already earned; everyone else — a different plan named against the same
     * payment, or another account presenting it — is refused.
     *
     * THE LOG LINE IS PART OF THE CONTRACT. It is the one signal a failed outbound call cannot
     * produce, so it is what the tests assert the refusal by. Do not reword it without updating
     * `PlanActivationReplayGuardTest::REFUSAL_LOG`.
     */
    protected function refusePlanActivationReplay(string $canonicalType, $reference, $plan = null, $user = null)
    {
        $existing = $this->existingPlanOrderForProviderReference($canonicalType, $reference);

        if ($existing === null) {
            return null;
        }

        $planId = is_object($plan) ? ($plan->id ?? null) : $plan;
        $userId = is_object($user) ? ($user->id ?? null) : $user;

        $samePlan = $planId !== null && (int) $existing->plan_id === (int) $planId;
        $sameBuyer = $userId !== null && (int) $existing->user_id === (int) $userId;

        $context = [
            'provider' => $canonicalType,
            'recorded_plan_id' => $existing->plan_id,
            'requested_plan_id' => $planId,
            'recorded_user_id' => $existing->user_id,
            'requesting_user_id' => $userId,
            'same_plan' => $samePlan,
            'same_buyer' => $sameBuyer,
            // The reference itself is NOT logged: it is a payment identifier, and the pair of ids
            // above already says which row was matched.
        ];

        if ($samePlan && $sameBuyer) {
            /*
             * RE-RUN THE ACTIVATION RATHER THAN JUST CLAIMING IT HAPPENED.
             *
             * The first version of this method returned the success message and did nothing, and
             * INDEPENDENT REVIEW CAUGHT THAT IT CONTRADICTED THIS TRAIT'S OWN PRECEDENT.
             * `PaystackPaymentController:341-367` already decided the opposite, with the reason
             * written out: *"The PlanOrder row is written BEFORE assignPlan(), so anything that
             * kills the request between them leaves a row and no entitlement -- and a bare
             * 'already recorded, report success' guard would then strand that buyer permanently:
             * every retry, including a perfectly clean one, would be told the plan was activated
             * while it never was."*
             *
             * All three handlers guarded here have the same ordering -- Benefit saves then
             * assigns, Cashfree saves then assigns, Mollie saves then assigns -- so all three have
             * the same window, and `User::assignPlan()` has at least one reachable throw inside it
             * (it dereferences `$oldplan->custom_plan` for a user whose stored `plan_id` resolves
             * to no row, which is precisely a merchant buying a new plan).
             *
             * `assignPlan()` is idempotent in the way that matters: it sets the plan and
             * recomputes the expiry from NOW rather than accumulating, so re-running it for the
             * same buyer and the same plan repairs that state and is a no-op otherwise. **No
             * second PlanOrder row is written**, which is the property this guard exists for.
             *
             * AND IT IS LOGGED AT INFO, NOT WARNING. This is the ordinary case -- the buyer who
             * paid and refreshed the confirmation page. Logging it at warning would put the
             * success path into whatever anybody alerts on.
             */
            Log::info('Plan activation re-run for an already-recorded reference from the same buyer.', $context);

            if (is_object($user) && method_exists($user, 'assignPlan') && $planId !== null) {
                $assignPlan = $user->assignPlan($planId);

                if (empty($assignPlan['is_success'])) {
                    return redirect()->route('plan.index')
                        ->with('error', $assignPlan['error'] ?? __('Transaction Unsuccesfull'));
                }
            }

            return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
        }

        Log::warning('Plan activation refused: this provider reference has already activated a plan.', $context);

        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
    }
}
