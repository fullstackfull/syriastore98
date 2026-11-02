<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
/*
 * F229 review finding -- THIS IMPORT WAS `AWS\CRT\Log`, AND EVERY `Log::` CALL IN THIS FILE THREW.
 *
 * `vendor/aws/aws-crt-php/src/AWS/CRT/Log.php:9` is a `final class Log` whose only statics are
 * toStdout/toStderr/toFile/toStream/stop/setLogLevel/log -- no `warning`, no `error`, no
 * `__callStatic`. So `Log::warning(...)` raised `Error: Call to undefined method
 * AWS\CRT\Log::warning()`, and **`Error` does not extend `Exception`**, so neither of this
 * method's `catch (\Exception $e)` blocks caught it. MEASURED, not reasoned.
 *
 * The consequence was worst exactly where it mattered. In production the purchase-intent table
 * does not exist (D-31 is development/test-only), so `create()` returns null on EVERY Paytm plan
 * purchase, the fail-closed refusal fires -- and instead of the documented
 * `redirect()->route('plan.index')->with('error', ...)` the merchant got an uncaught 500 with no
 * log line at all. Still fail-closed, since nothing activated and no 401 was emitted, but the
 * refusal left no evidence and did not look like a refusal.
 *
 * NO TEST REACHED ANY OF THE FIVE SITES, which is why the suite was green over them: the
 * no-merchant-key case returns at an already-fully-qualified call, the positive control takes the
 * success path, and the replay case returns before them.
 *
 * The pre-existing `Log::debug` on the free-plan branch had the same fault.
 */
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PaytmWallet;


use App\Http\Controllers\Concerns\GuardsPlanActivation;

class PaytmPaymentController extends Controller
{
    use GuardsPlanActivation;

    public $currancy;

    public function paymentConfig()
    {
        if (\Auth::check()) {
            $payment_setting = getSuperAdminAllSetting();
            config(
                [
                    'services.paytm-wallet.env' => isset($payment_setting['paytm_mode']) ? $payment_setting['paytm_mode'] : '',
                    'services.paytm-wallet.merchant_id' => isset($payment_setting['paytm_merchant_id']) ? $payment_setting['paytm_merchant_id'] : '',
                    'services.paytm-wallet.merchant_key' => isset($payment_setting['paytm_merchant_key']) ? $payment_setting['paytm_merchant_key'] : '',
                    'services.paytm-wallet.merchant_website' => 'WEBSTAGING',
                    'services.paytm-wallet.channel' => 'WEB',
                    'services.paytm-wallet.industry_type' => isset($payment_setting['paytm_industry_type']) ? $payment_setting['paytm_industry_type'] : '',
                ]
            );
        }

    }
    public function planPayWithPaytm(Request $request)
    {

        $this->paymentConfig();

        $planID         = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan           = Plan::find($planID);
        $authuser       = Auth::user();
        $coupons_id = '';
        if ($plan) {
         try{
            /* Check for code usage */
            $plan->discounted_price = false;
            $price                  = $plan->price;

            $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
            $admin_payments_details = getSuperAdminAllSetting();
            $CURRENCY = !empty($admin_payments_details['CURRENCY_NAME']) ? $admin_payments_details['CURRENCY_NAME'] : 'USD';
            if (!empty($coupons))
            {
                $usedCoupun     = $coupons->used_coupon();
                $discount_value = ($plan->price / 100) * $coupons->discount;
                $price          = $plan->price - $discount_value;
                /*
                 * `>=`, not `==`. Found by independent verification of the F3 finding below.
                 *
                 * Once `used` EXCEEDS `limit` -- which the double-count this block used to cause
                 * is one way to arrange -- `limit == used` is false and the gate PASSES. It was
                 * only saved by `is_active` having been set to 0 elsewhere, in which case `:77`'s
                 * `where('is_active','1')` finds nothing and the buyer is silently charged the
                 * undiscounted price instead of being told the coupon is spent.
                 */
                if ($coupons->limit <= $usedCoupun) {
                    return redirect()->back()->with('error', __('This coupon code has expired.'));
                }
                $coupon_id = $coupons->id;


                /*
                 * ═══════════════════════════════════════════════════════════════════════════════
                 * THE SAME CONDITION AS THE ACTIVATION BELOW. It was `< 1` against the next
                 * block's `<= 0`, and the two are not the same set. Found by independent review of
                 * 3b846065, then verified and sustained by three adversarial readings.
                 * ═══════════════════════════════════════════════════════════════════════════════
                 *
                 * For a price strictly between 0 and 1 -- a plan priced 10 with a 95% coupon gives
                 * 0.50, and both values are creatable in the admin UI -- this block wrote a
                 * `PlanUserCoupon` row and then **kept going**: it created the intent, sent the
                 * buyer to Paytm, and the return wrote a SECOND usage row for the same purchase.
                 *
                 * `PlanCoupon::used_coupon()` counts EVERY user's rows, so the limit is
                 * platform-wide and the deactivation at `:669` flips `is_active = 0` for
                 * everybody: a coupon in that window burned its limit at twice the rate and went
                 * dead for the whole platform at half its intended usage.
                 *
                 * The round-2 commit caused this by *correctly* making the return's usage write
                 * fire at all -- it had been keyed on `$request->coupon`, which a legitimate
                 * return never carries, so no row was ever written. The fix was right; the
                 * collision with this pre-existing `< 1` branch was not considered.
                 *
                 * `<= 0` is a strict subset of `< 1`, so the genuinely-free case is unchanged.
                 */
                /*
                 * ═══════════════════════════════════════════════════════════════════════════════
                 * F240. THE COUPON IS CONSUMED **AFTER** THE ACTIVATION SUCCEEDS, not before it.
                 * ═══════════════════════════════════════════════════════════════════════════════
                 *
                 * What stood here wrote the `PlanUserCoupon` row and deactivated the coupon in its
                 * own `if ($price <= 0)` block, and only THEN entered the activation block below.
                 * Between the two sat `$authuser->plan = $plan->id; $authuser->save();` --
                 * and **there is no `users.plan` column**. The real column is `users.plan_id`,
                 * which `assignPlan()` writes correctly on the next line. So the save raised, the
                 * enclosing `try` swallowed it, and the coupon use that had already committed was
                 * never undone: a 100%-off plan purchase burned the coupon, activated nothing, and
                 * on a limited coupon took a use away from every merchant on the platform
                 * (`PlanCoupon::used_coupon()` counts across users).
                 *
                 * MEASURED: `Schema::hasColumn('users', 'plan')` is **false** on the schema the
                 * migrations build, and `users.plan_id` is true. Pinned by
                 * `PlanCouponRefusalPersistsNothingTest::test_premise_f240_...`, so if anyone adds
                 * that column the finding dissolves loudly instead of rotting.
                 *
                 * Two things are fixed together, and both are F196 Option A / D-28b -- the refusal
                 * must precede the persistence:
                 *
                 *   1. the dead write to the non-existent column is gone. `assignPlan()` already
                 *      sets `plan_id`, so nothing is lost; `users.plan` never held anything.
                 *   2. the coupon consumption moved below the activation and inside its success
                 *      branch, beside the `PlanOrder::create()` it belongs with -- and it now uses
                 *      `$orderID`, the id of the plan order actually written, instead of a second
                 *      `uniqid()` for which no order ever existed. `plan_user_coupons.order` was
                 *      unresolvable before; now it points at a real row.
                 *
                 * THE BRANCH IS UNREACHABLE FROM THE FEATURE SUITE, which is why this is argued
                 * from the source rather than driven: the coupon lookup above is
                 * `PlanCoupon::whereRaw('BINARY `code` = ?')`, a syntax error on SQLite, so a
                 * probe redirects 302 having written nothing. That is the dialect blindness
                 * `verify-plan-coupon-refusal-ordering-on-mysql.php` exists for.
                 */
                if($price <= 0)
                {
                    $assignPlan = $authuser->assignPlan($plan->id);

                    if ($assignPlan['is_success'] == true && !empty($plan)) {
                        if (!empty($authuser->payment_subscription_id) && $authuser->payment_subscription_id != '') {
                            try {
                                $authuser->cancel_subscription($authuser->id);
                            } catch (\Exception $exception) {
                                Log::debug($exception->getMessage());
                            }
                        }

                        $orderID = strtoupper(str_replace('.', '', uniqid('', true)));
                        PlanOrder::create(
                            [
                                'order_id' => $orderID,
                                'name' => null,
                                'email' => null,
                                'card_number' => null,
                                'card_exp_month' => null,
                                'card_exp_year' => null,
                                'plan_name' => $plan->name,
                                'plan_id' => $plan->id,
                                'price' => $price == null ? 0 : $price,
                                'price_currency' => $CURRENCY,
                                'txn_id' => '',
                                'payment_type' => 'Paytm',
                                'payment_status' => 'succeeded',
                                'receipt' => null,
                                'user_id' => $authuser->id,
                            ]
                        );

                        /*
                         * F240 -- THE COUPON IS CONSUMED HERE, after the entitlement landed and
                         * beside the order it belongs to. See the block comment above.
                         *
                         * `$orderID` is the plan order that was just written, so
                         * `plan_user_coupons.order` resolves to a real row for the first time: it
                         * used to carry a second, unrelated `uniqid()` for which no order was
                         * ever created.
                         */
                        if ($coupon_id != '' && ! empty($coupons)) {
                            $userCoupon            = new PlanUserCoupon();
                            $userCoupon->user_id   = $authuser->id;
                            $userCoupon->coupon_id = $coupons->id;
                            $userCoupon->order     = $orderID;
                            $userCoupon->save();

                            $usedCoupun = $coupons->used_coupon();

                            if ($coupons->limit <= $usedCoupun) {
                                $coupons->is_active = 0;
                                $coupons->save();
                            }
                        }

                        $res['msg'] = __("Plan successfully upgraded.");
                        $res['flag'] = 2;
                        return $res;
                    } else {
                        return redirect()->back()->with(__('Plan fail to upgrade.'));
                    }
                }
            }

            try {
                /*
                 * F229 / D-31 REQUIREMENT 1 -- RECORD WHAT IS BEING BOUGHT BEFORE LEAVING.
                 *
                 * The return side cannot establish WHICH PLAN the money was for. Paytm's checksum
                 * covers `TXNAMOUNT` -- the amount is genuinely provider-attested -- but
                 * `ReceivePaymentProvider::beginTransaction()` DROPS the `plan` key when building
                 * its params, so no checksum can ever bind the plan. The plan instead arrives on
                 * the return as a `Crypt` route parameter, and `APP_KEY` is in git history and
                 * unrotated, so that is an encoding and not a control. Hence the substitution:
                 * pay once for the cheapest plan, name a dearer one on the first return.
                 *
                 * The intent is the binding. The server decides here, before the caller leaves,
                 * exactly which plan, which coupon, how much and in what currency -- and records
                 * it against the one identifier that IS inside the attested body: the order
                 * reference, which is now generated BY THE SERVER.
                 *
                 * THE OLD REFERENCE WAS THE CLOCK. `date('Y-m-d') . '-' . strtotime(...)` is
                 * second-resolution, so two purchases in the same second collided on it -- the
                 * same defect F228 measured on `plan_orders.order_id`, whose UNIQUE constraint was
                 * keying on `time()` rather than on anything the provider said. A random reference
                 * cannot collide, and `plan_purchase_intents.reference` is UNIQUE so that is a
                 * database fact rather than a convention.
                 *
                 * IT FAILS CLOSED. A null reference means the intent could not be recorded, and
                 * taking a payment against an intent that does not exist is exactly the
                 * unverifiable capture requirement 5 asks us not to create. So the purchase does
                 * not start. In production the table is absent by design (D-31 is
                 * development/test-only), so this refuses there -- which is requirement 8's
                 * fail-closed behaviour and its documented cost.
                 */
                $ssIntentReference = \App\Services\PlanPurchaseIntent::create([
                    'provider' => 'Paytm',
                    'user_id' => $authuser->id,
                    'plan_id' => $plan->id,
                    'coupon_id' => $coupon_id ?? null,
                    'coupon_code' => $request->coupon,
                    'expected_amount' => $price,
                    'currency' => $CURRENCY,
                ]);

                if ($ssIntentReference === null) {
                    Log::warning('Paytm plan purchase refused: the purchase intent could not be recorded.', [
                        'plan_id' => $plan->id,
                        'user_id' => $authuser->id,
                    ]);

                    return redirect()->route('plan.index')
                        ->with('error', __('This payment method is not available for subscription purchases.'));
                }

                $call_back = route('plan.paytm', [$request->plan_id]);
                $payment = PaytmWallet::with('receive');
                $payment->prepare(
                    [
                        'order' => $ssIntentReference,
                        'user' => Auth::user()->id,
                        'mobile_number' => $request->mobile_number,
                        'email' => Auth::user()->email,
                        'amount' => $price,
                        'plan' => $plan->id,
                        'callback_url' => $call_back
                        ]
                    );
                return $payment->receive();

            } catch (\Exception $e) {
                return redirect()->route('plan.index')->with('error', __($e->getMessage()));
            }
        }
        catch (\Exception $e) {
            return redirect()->route('plan.index')->with('error', __($e->getMessage()));
        }

        } else {
            return redirect()->back()->with(__('Plan is deleted.'));
        }
    }
    public function getPaymentStatus(Request $request, $plan, $coupon = null)
    {

        $transaction = $this->paymentConfig();
        $planID  = \Illuminate\Support\Facades\Crypt::decrypt($plan);
        $plan    = Plan::find($planID);
        $user   = Auth::user();
        $orderID = time();

        /*
         * AN UNCONFIGURED MERCHANT KEY MUST REFUSE, NOT VERIFY AGAINST THE EMPTY STRING.
         *
         * `paymentConfig()` at :31 passes `isset(...) ? ... : ''`, so an installation with no
         * `paytm_merchant_key` row verifies `CHECKSUMHASH` against `''` -- a key the caller knows
         * as well as the server does. MEASURED by driving this route: a self-minted `TXN_SUCCESS`
         * body signed with the empty key wrote one plan order with `payment_status = 'success'`
         * and assigned a 499 plan, with no Paytm account involved anywhere. That is a free paid
         * plan on every installation that has not configured Paytm, which is every installation
         * that does not use it.
         *
         * An installation that HAS configured Paytm is unaffected, and one that has not cannot
         * take a Paytm payment anyway -- so this fails closed in the only direction that exists.
         */
        $paytmMerchantKey = trim((string) config('services.paytm-wallet.merchant_key'));

        if ($paytmMerchantKey === '') {
            \Illuminate\Support\Facades\Log::warning('Paytm plan return refused: no merchant key is configured, so the checksum proves nothing.', [
                'plan_id' => $plan->id ?? null,
                'user_id' => $user->id ?? null,
            ]);

            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
        }

        if ($plan) {
            try {
                $transaction = PaytmWallet::with('receive');
                $response    = $transaction->response();

                if ($transaction->isSuccessful()) {

                    /*
                     * ONE PAYTM TRANSACTION, ONE PLAN ACTIVATION -- and here the guard runs AFTER
                     * the checksum, unlike Benefit, Cashfree and Mollie.
                     *
                     * Those three guard first because the reference is what they are about to
                     * spend an outbound call on. Paytm's verification is local and free (an AES
                     * check against the server-held key, no network), so verifying first costs
                     * nothing and denies a caller the chance to probe which transaction ids exist.
                     *
                     * MEASURED: a valid checksum stays valid on a re-POST, so the cryptography is
                     * no defence against replay. Driving this route twice with the identical
                     * signed body wrote TWO rows carrying one `txn_id`. `plan_orders.order_id` is
                     * UNIQUE and blocks a same-second repeat, but `$orderID` is `time()` -- so
                     * that constraint keys on the clock, not on anything the provider said, and
                     * two posts a second apart both landed.
                     */
                    $ssOrderReference = (string) $request->ORDERID;

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * A REFRESH OF AN ALREADY-SUCCESSFUL PURCHASE IS ANSWERED FROM THE SERVER'S
                     * OWN RECORD, AND WRITES NOTHING. Found by independent review of 2f011efb.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * This must come BEFORE `refusePlanActivationReplay()`, because that guard's
                     * same-buyer-same-plan branch RE-RUNS `assignPlan()`
                     * (`GuardsPlanActivation.php:463`) with the **route's** plan id -- 46 lines
                     * before the intent is consulted and 67 before the intent is consumed. So it
                     * was a write that happened before verification, which is the F196 Option A
                     * ordering D-28b settled.
                     *
                     * AND IT WAS NOT A NO-OP, which the trait's own comment mislabels it as.
                     * `User::assignPlan()` sets `plan_expire_date = Carbon::now()->addMonths(1)`
                     * -- **recomputed from now rather than accumulated** (`User.php:250-257`). A
                     * valid checksum stays valid on a re-POST, as this controller's own comment
                     * records. So re-POSTing one legitimate signed body once a month kept a paid
                     * plan alive indefinitely on a single payment. A free renewal, not a
                     * substitution: `:417` requires the plan to match, so the plan cannot be
                     * changed this way -- only the expiry.
                     *
                     * The honest answer to a refresh is "you already have it", from the record the
                     * server wrote itself, with no entitlement write at all. The intent carries
                     * exactly the three facts needed: consumed, by this buyer, with an order
                     * behind it.
                     *
                     * It is keyed on `plan_order_id` rather than on `status` alone so that a
                     * consumed intent whose order never landed does NOT report success -- that is
                     * requirement 5's captured-payment-without-entitlement case, and it belongs in
                     * `unreconciled()` rather than in a success message.
                     */
                    $ssAlready = \App\Services\PlanPurchaseIntent::find('Paytm', $ssOrderReference);

                    if ($ssAlready !== null
                        && $ssAlready->status === \App\Services\PlanPurchaseIntent::CONSUMED
                        && is_numeric($user->id ?? null)
                        && (int) $ssAlready->user_id === (int) $user->id
                        && ! empty($ssAlready->plan_order_id)) {
                        Log::info('Paytm return re-presented for a purchase that already activated; reporting success without re-writing the entitlement.', [
                            'reference' => $ssOrderReference,
                            'plan_order_id' => $ssAlready->plan_order_id,
                            'user_id' => $user->id,
                        ]);

                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                    }

                    $paytmReference = $request->TXNID;
                    $paytmReplay = $this->refusePlanActivationReplay('Paytm', $paytmReference, $plan, $user);

                    if ($paytmReplay !== null) {
                        return $paytmReplay;
                    }

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * F229 / D-31 -- THE INTENT IS THE AUTHORITY FROM HERE DOWN.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * Everything above establishes that an AUTHENTIC body says a payment
                     * succeeded, and that this provider reference has not already activated a
                     * plan. Neither of those says WHAT WAS BOUGHT, and that is the whole of F229.
                     *
                     * REQUIREMENT 2 -- the provider is asked directly. The checksum proves the
                     * body was minted by someone holding the merchant key; it does not prove the
                     * transaction is still successful, was not reversed, and was ever settled.
                     * The owner's instruction is explicit that a valid checksum is not by itself
                     * sufficient proof of a successful payment, so the authoritative answer is
                     * fetched server-to-server from /merchant-status/getTxnStatus.
                     *
                     * REQUIREMENT 4 -- the plan comes from the INTENT, never from the route. The
                     * route's `$plan` was decrypted from a `Crypt` parameter and `APP_KEY` is in
                     * git history, so it is forgeable. It is used below only to ANSWER the caller;
                     * the entitlement follows `$ssIntent->plan_id`.
                     *
                     * REQUIREMENTS 3 and 4 -- the amount and currency are matched against the
                     * intent by exact scaled-integer comparison, never float equality and never
                     * `bccomp` (bcmath is not installed and not declared, so it would fatal).
                     *
                     * REQUIREMENT 5 -- the intent is consumed by one conditional UPDATE from
                     * `pending`, so two concurrent callbacks cannot both win, a replay finds it
                     * already consumed, and another account's reference is refused on the
                     * purchaser check before the amount is ever disclosed.
                     *
                     * REQUIREMENT 8 -- UNVERIFIABLE IS A REFUSAL. An unreachable status endpoint,
                     * an absent intent record, a malformed response: none of them activates
                     * anything. The cost is stated in PaytmTransactionVerifier's header and the
                     * leftover is surfaced by PlanPurchaseIntent::unreconciled() for a human.
                     *
                     * REQUIREMENT 9 -- nothing here refunds, cancels or settles anything. A
                     * refused intent beside a captured payment is a RECORD, and that is all.
                     */
                    $ssProviderSays = app(\App\Services\PaytmTransactionVerifier::class)
                        ->verify($ssOrderReference);

                    if ($ssProviderSays['state'] !== \App\Services\PaytmTransactionVerifier::VERIFIED) {
                        /*
                         * THE PURCHASER IS NAMED HERE TOO. Round 2 scoped only one of this
                         * method's three `recordRefusal()` calls, and this is the one that
                         * mattered: it runs BEFORE any ownership check, on a reference taken
                         * straight from the request. Low reachability -- the reference is pinned
                         * inside the AES-verified POST body, so a victim's cannot be substituted
                         * without the merchant key -- but the round-2 rationale was that "the
                         * asymmetry was indefensible", and two thirds of it was still there.
                         */
                        \App\Services\PlanPurchaseIntent::recordRefusal(
                            'Paytm',
                            $ssOrderReference,
                            'provider verification ' . $ssProviderSays['state'] . ': ' . $ssProviderSays['reason'],
                            $paytmReference,
                            $user->id ?? null
                        );

                        Log::warning('Paytm plan activation refused: the provider did not confirm the payment.', [
                            'state' => $ssProviderSays['state'],
                            'reason' => $ssProviderSays['reason'],
                            'reference' => $ssOrderReference,
                            'user_id' => $user->id ?? null,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    [$ssIntentState, $ssIntent, $ssIntentReason] = \App\Services\PlanPurchaseIntent::verifyAndConsume(
                        'Paytm',
                        $ssOrderReference,
                        $user->id ?? null,
                        (string) $ssProviderSays['amount'],
                        (string) ($ssProviderSays['currency'] ?? ''),
                        $ssProviderSays['txn_id'] ?? $paytmReference
                    );

                    if ($ssIntentState !== \App\Services\PlanPurchaseIntent::VERIFIED) {
                        /*
                         * A RECOVERABLE REFUSAL IS NOT RECORDED, because recording it is what makes
                         * it terminal. See `PlanPurchaseIntent::REFUSED_RECOVERABLE`: the currency
                         * comparison is against a platform DISPLAY label that Paytm is never sent
                         * and never echoes, so a difference there is a misconfiguration and
                         * closing the intent over it would destroy a captured payment.
                         *
                         * The purchaser is passed so a terminal refusal cannot land on somebody
                         * else's pending row -- the cross-user case returns `REFUSED_MISMATCH`, and
                         * without this the refusal was written onto the OWNER's intent.
                         */
                        if ($ssIntentState === \App\Services\PlanPurchaseIntent::REFUSED_RECOVERABLE) {
                            /*
                             * KEEP THE INTENT OPEN, BUT NOT INVISIBLE. Round 2 wrote nothing here
                             * at all, which left the row `pending` with a NULL `provider_txn_id`
                             * -- a combination `unreconciled()` cannot match, so a captured
                             * payment became byte-indistinguishable from an abandoned checkout.
                             * This writes only the server's own reason, which the fourth clause
                             * of `unreconciled()` now matches.
                             */
                            \App\Services\PlanPurchaseIntent::recordRecoverableRefusal(
                                'Paytm',
                                $ssOrderReference,
                                $ssIntentState . ': ' . $ssIntentReason,
                                $user->id ?? null
                            );
                        } else {
                            \App\Services\PlanPurchaseIntent::recordRefusal(
                                'Paytm',
                                $ssOrderReference,
                                $ssIntentState . ': ' . $ssIntentReason,
                                $ssProviderSays['txn_id'] ?? $paytmReference,
                                $user->id ?? null
                            );
                        }

                        Log::warning('Paytm plan activation refused: the payment does not match the recorded purchase intent.', [
                            'state' => $ssIntentState,
                            'reason' => $ssIntentReason,
                            'reference' => $ssOrderReference,
                            'user_id' => $user->id ?? null,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    /*
                     * THE PLAN THE MONEY WAS ACTUALLY FOR. Replacing the route's forgeable
                     * parameter with the server's own record is the substitution fix itself.
                     */
                    $ssIntendedPlan = Plan::find($ssIntent->plan_id);

                    if (empty($ssIntendedPlan)) {
                        /* Scoped like the other two, so no reader has to work out which of the
                           three is the asymmetric one. Behaviourally a no-op here: by this point
                           `verifyAndConsume()` has bound the purchaser and consumed the row. */
                        \App\Services\PlanPurchaseIntent::recordRefusal(
                            'Paytm',
                            $ssOrderReference,
                            'the intent names a plan that no longer exists',
                            $ssProviderSays['txn_id'] ?? $paytmReference,
                            $user->id ?? null
                        );

                        Log::warning('Paytm plan activation refused: the intent names a plan that no longer exists.', [
                            'reference' => $ssOrderReference,
                            'plan_id' => $ssIntent->plan_id,
                        ]);

                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    $plan = $ssIntendedPlan;

                    /*
                     * THE COUPON COMES FROM THE INTENT, NOT FROM THE REQUEST. Review finding.
                     *
                     * `$request->coupon` was caller-supplied AND OUTSIDE THE CHECKSUM:
                     * `PaytmWalletProvider::response()` verifies over `$this->request->post()` --
                     * the body only -- while `$request->coupon` resolves through `input()`, which
                     * is body + QUERY STRING. `coupon` appears in neither Paytm's response body
                     * nor the callback URL this controller builds, so `?coupon=X` on the return was
                     * unsigned, unbound, and the only such key that reached a write. It drove the
                     * recorded price, wrote a `PlanUserCoupon` the buyer never used, and could
                     * deactivate a coupon PLATFORM-WIDE (`PlanCoupon::used_coupon()` counts every
                     * user's rows).
                     *
                     * The intent already records `coupon_id` and `coupon_code` -- requirement 1's
                     * "discount named explicitly" -- and nothing read them. Now the server's own
                     * record is the authority, exactly as it is for the plan.
                     *
                     * A CORRECTION TO WHAT THIS DOCBLOCK FIRST CLAIMED, caught by independent
                     * review and worth keeping rather than quietly deleting.
                     *
                     * It said the change *"fixes the converse, which was the larger half"* -- that
                     * because the legitimate flow never sends `coupon` on the return, the usage row
                     * was never written for a real Paytm purchase, and reading the intent made it
                     * work. **The first half is true and the fix was not.** The usage WRITE below
                     * still tested `$request->coupon`, so moving only the pricing lookup changed
                     * nothing about it: `PlanUserCoupon` was still never written and a limit-1
                     * coupon was still unboundedly reusable on this provider.
                     *
                     * Worse, the half-applied refactor left the two sites testing DIFFERENT
                     * conditions, so a buyer appending `?coupon=1` to their own return reached the
                     * write with `$coupons` undefined -- an `ErrorException` after the order, the
                     * attachment and the entitlement had all succeeded, flashing "Plan not found!"
                     * over a plan that was in fact active.
                     *
                     * Both are closed below, at the write itself.
                     */
                    $ssIntentCoupon = $ssIntent->coupon_code ?? null;

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * NO COUPON CHECK MAY REFUSE FROM HERE. The payment is captured and the intent
                     * is CONSUMED. Found by independent review of 7341b4d0.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * What stood here was the vendored block, with two `return redirect()->back()`
                     * paths and an `is_active` filter -- both of which this trait had already ruled
                     * out in writing. `GuardsPlanActivation.php:179-185`:
                     *
                     * > *"Each gateway's checkout initiator deactivates a coupon once its usage
                     * > limit is reached. A coupon that was valid when checkout started can
                     * > therefore be inactive by the time the provider returns, and filtering on
                     * > is_active here would recompute a HIGHER price than the server itself
                     * > advertised and charged."*
                     *
                     * And `PlanCoupon::used_coupon()` counts PLATFORM-WIDE, so ANOTHER merchant
                     * completing their purchase while this buyer is on Paytm's page flips
                     * `is_active` to 0. The buyer pays, the provider confirms, the intent is
                     * consumed -- and then the block refused with "This coupon code is invalid or
                     * has expired.", leaving money captured, no order, no entitlement and no
                     * retry. That is the exact shape of F234, reintroduced past the point where it
                     * can be recovered from.
                     *
                     * `planCouponForPricing()` is the trait's answer and the same method already
                     * used it below: exists, `is_active` NOT required. The two refusals are gone
                     * because by here there is nothing left to refuse -- and `$price` was dead
                     * anyway, since the recorded price is computed independently.
                     */
                    $coupons = ! empty($ssIntentCoupon) ? $this->planCouponForPricing($ssIntentCoupon) : null;

                    $order                 = new PlanOrder();


                    $order->order_id       = $orderID;
                    $order->name           = isset($user->name) ? $user->name : '';
                    $order->card_number    = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year  = '';
                    $order->plan_name      = $plan->name;
                    $order->plan_id        = $plan->id;
                    // Server-owned: was `isset($request->TXNAMOUNT) ? $request->TXNAMOUNT : 0`.
                    // This handler ALREADY computed the correct price server-side a few lines
                    // above ($price = $plan->price - $discount_value) and then discarded it in
                    // favour of the posted TXNAMOUNT. Paytm IS verified on this path
                    // ($transaction->isSuccessful()), so only the RECORDED price changes.
                    /*
                     * THE RECORDED FIGURES COME FROM THE INTENT, not from a recomputation and not
                     * from the request. Independent review of 7341b4d0 found both.
                     *
                     * `serverOwnedPlanPrice()` is a second, disagreeing definition of a number the
                     * intent already holds and the provider has just been matched against. They
                     * can differ: `planPayWithPaytm` records `coupon_code => $request->coupon`
                     * unconditionally, even when its own lookup found nothing because the coupon
                     * was inactive -- so a buyer posting an existing-but-inactive code is charged
                     * the FULL price while `planCouponForPricing()` (no `is_active` filter, by
                     * design) would find that coupon on the return and record the DISCOUNTED one.
                     * The `plan_orders` row would under-state collected revenue, by a value the
                     * buyer chose, which is the very thing `serverOwnedPlanPrice`'s own docblock
                     * says it exists to prevent.
                     *
                     * `price_currency` read `$request->CURRENCY_CODE`, which is in neither the
                     * callback URL nor the signed body -- so where it is absent from the body the
                     * QUERY STRING supplies it. The same class of value this round removed for
                     * `plan` and `coupon`, left in place for currency. (The docblock above said
                     * `coupon` was *"the only such key that reached a write"*; it was not.)
                     *
                     * The intent is the server's own record of what it advertised, charged and
                     * then verified. Nothing else is authoritative.
                     */
                    $order->price          = $ssIntent->expected_amount;
                    $order->price_currency = ! empty($ssIntent->currency) ? $ssIntent->currency : 'NFD';
                    // The same variable the guard was keyed on, so the recorded value cannot drift
                    // away from the looked-up one. See the structural assertion in
                    // PlanActivationReplayGuardTest.
                    $order->txn_id         = (string) $paytmReference;
                    $order->payment_type   = __('paytm');
                    $order->payment_status = 'success';
                    $order->receipt        = '';
                    $order->user_id        = $user->id;
                    $order->save();

                    /*
                     * ═══════════════════════════════════════════════════════════════════════════
                     * THE ENTITLEMENT FIRST, THEN THE ATTACHMENT. The order of these two lines is
                     * load-bearing, and it was wrong. Found by independent review of 7341b4d0.
                     * ═══════════════════════════════════════════════════════════════════════════
                     *
                     * `attachOrder()` used to run BEFORE `assignPlan()`, which made
                     * `plan_purchase_intents.plan_order_id` mean "an order exists" rather than
                     * "this purchase completed". Two consequences, both bad:
                     *
                     *   * the refresh short-circuit above keys on `plan_order_id`, so a buyer whose
                     *     order landed and whose ENTITLEMENT did not was told "Plan activated
                     *     Successfully." on every retry, with no write -- permanently stranded.
                     *     `GuardsPlanActivation.php:437-443` had already settled that exact
                     *     question: *"a bare 'already recorded, report success' guard would then
                     *     strand that buyer permanently: every retry, including a perfectly clean
                     *     one, would be told the plan was activated while it never was."*
                     *   * `unreconciled()`'s captured-payment clause requires `plan_order_id IS
                     *     NULL`, so the one state requirement 5 asks to surface was the one state
                     *     it could not see.
                     *
                     * Attaching only after a successful activation makes `plan_order_id` set if and
                     * only if the purchase completed -- which repairs the short-circuit's key and
                     * the reconciliation query at the same time, with no extra condition in either.
                     *
                     * AND THE THROW IS CAUGHT HERE, not left to the outer `catch`. `assignPlan()`
                     * both returns `is_success => false` AND raises (`User.php:273`,
                     * `$oldplan->custom_plan` where the stored `plan_id` resolves to no row -- a
                     * merchant who changed plans). The recovery record below tests the RETURN
                     * value, so a throw unwound straight past it into the outer catch: money
                     * captured, order written, entitlement absent, and **nothing logged at all**.
                     * The comment below calls that case "not hypothetical" and then did not cover
                     * it.
                     */
                    try {
                        $assignPlan = $user->assignPlan($plan->id, $request->payment_frequency);
                    } catch (\Throwable $theEntitlementRaised) {
                        Log::error('Paytm payment verified and captured, but the entitlement did not activate.', [
                            'reference' => $ssOrderReference,
                            'plan_order_id' => $order->id,
                            'plan_id' => $plan->id,
                            'user_id' => $user->id,
                            'reason' => 'assignPlan raised: ' . $theEntitlementRaised->getMessage(),
                        ]);

                        /* The intent keeps a NULL `plan_order_id`, so `unreconciled()` surfaces it.
                           Re-throwing would reach the outer catch and flash "Plan not found!" over
                           a captured payment; the buyer is told the truth instead. */
                        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                    }

                    if (! empty($assignPlan['is_success'])) {
                        \App\Services\PlanPurchaseIntent::attachOrder('Paytm', $ssOrderReference, $order->id);
                    }

                    /*
                     * THE USAGE WRITE, now keyed on the INTENT and on the coupon actually found.
                     *
                     * This tested `$request->coupon`, which is why the pricing fix alone changed
                     * nothing: the legitimate return carries no `coupon`, so no usage row was ever
                     * written for a real Paytm purchase and a limit-1 coupon stayed reusable on
                     * this provider without bound. `!empty($coupons)` is the second half -- the two
                     * sites tested different conditions after the half-applied refactor, so
                     * `?coupon=1` with no coupon on the intent reached `$coupons->id` undefined.
                     *
                     * NO DOUBLE COUNT: `planPayWithPaytm` writes a usage row only on its free-plan
                     * branch (`if ($price < 1)`), which activates there and never reaches this
                     * return.
                     */
                    if (! empty($ssIntentCoupon) && ! empty($coupons))
                    {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user_id   = $user->id;
                        $userCoupon->coupon_id = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();

                        $usedCoupun = $coupons->used_coupon();
                        if($coupons->limit <= $usedCoupun)
                        {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }

                    }
                    /*
                     * REQUIREMENT 5's RECOVERY RECORD. The payment is verified and the intent is
                     * consumed, so if `assignPlan()` fails from here the money is taken and the
                     * entitlement did not land -- the plan-side twin of F186's
                     * captured-payment-without-order. `User::assignPlan()` has a reachable throw
                     * inside it, so this is not hypothetical.
                     *
                     * It RECORDS and does nothing else. Requirement 9 forbids new refund or
                     * settlement behaviour and D-15 condition 3 separates reversing a merchant's
                     * balance from a real refund to a customer, so the intent is marked with its
                     * reason and `PlanPurchaseIntent::unreconciled()` surfaces it for a human. The
                     * intent stays CONSUMED -- re-opening it would let the same payment be spent
                     * again, which is the opposite error.
                     */
                    if (empty($assignPlan['is_success'])) {
                        Log::error('Paytm payment verified and captured, but the entitlement did not activate.', [
                            'reference' => $ssOrderReference,
                            'plan_order_id' => $order->id,
                            'plan_id' => $plan->id,
                            'user_id' => $user->id,
                            'reason' => $assignPlan['error'] ?? 'assignPlan reported no success',
                        ]);
                    }

                    if ($assignPlan['is_success']) {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully!'));
                    } else {
                        return redirect()->route('plan.index')->with('error', __($assignPlan['error']));
                    }
                } else {
                    return redirect()->route('plan.index')->with('error', __('Transaction has been failed! '));
                }
            } catch (\Exception $e) {

                return redirect()->route('plan.index')->with('error', __('Plan not found!'));
            }
        }

        /*
         * A NULL PLAN REFUSES. Found by independent review of 7341b4d0.
         *
         * `if ($plan)` gates this whole method and had no `else`, so a route parameter naming a
         * plan that no longer exists fell off the end and returned NULL -- an **empty HTTP 200**
         * with no refusal, no log and no `recordRefusal`, leaving an intent `pending` with a NULL
         * `provider_txn_id`, which `unreconciled()` cannot surface either. Reachable in the
         * legitimate flow when a plan is deleted between checkout and return.
         */
        Log::warning('Paytm plan return refused: the route names a plan that no longer exists.', [
            'user_id' => $user->id ?? null,
        ]);

        return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
    }

}
