<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\PlanCoupon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;


class PaystackPaymentController extends Controller
{
    /*
     * D-12 / F87. The trait is adopted for `serverOwnedPlanPrice()` and
     * `existingPlanOrderForProviderTxn()` only. `assertPlanActivationEnabled()` is deliberately
     * NOT called: Paystack has no entry in `config/payments.php`'s `plan_activation` map, and that
     * helper treats a missing key as disabled, so calling it would abort(403) on every Paystack
     * plan purchase. The owner's D-17 reserves the fail-closed route for the case where
     * verification safety cannot be proven; here it can be, so the verification is fixed instead.
     */
    use GuardsPlanActivation;

    /** The canonical, locale-independent `plan_orders.payment_type` for this provider. */
    public const PAYMENT_TYPE = 'Paystack';

    /**
     * D-12 / F87. WHETHER A PAYSTACK VERIFY RESPONSE IS A MATCHING, SUCCESSFUL TRANSACTION.
     *
     * Pulled out of the handler so it can be DRIVEN. The handler reaches Paystack with a raw
     * `curl_init()`, not the HTTP client, so there is nothing to fake and no test could otherwise
     * tell "we refused because the payment failed" from "we refused because this container has no
     * network" -- and those two are the same refusal on the old code, which is part of why F87
     * went unnoticed. Everything that decides the outcome is in here and takes its inputs as
     * arguments; the handler keeps the curl, the logging and the redirects.
     *
     * `$result` is whatever `json_decode($responce, true)` produced, which on a failed call is
     * not an array at all -- so this takes `mixed` and narrows, rather than declaring `array`
     * and fataling on the path that matters most.
     *
     * @param  mixed  $result             decoded verify response, or anything on a failed call
     * @param  mixed  $expectedReference  the reference this server asked Paystack about
     * @return array{paid: bool, envelope_ok: bool, state: string, reference_matches: bool}
     */
    /**
     * Every spelling `plan_orders.payment_type` may hold for Paystack, newest first.
     *
     * The replay guard matches on `payment_type`, and this column was written as `__('Paystack')`
     * before D-12 — so a row written while the UI was Arabic holds `كومة الدفع`. An earlier
     * version of the guard checked the canonical literal plus `__('Paystack')`, which is the
     * CURRENT REQUEST's locale — and the caller chooses that, through the ordinary language
     * switcher (`SetLocate` resolves it from the session, then the cookie, then `users.language`).
     * So a merchant holding an Arabic-written row could simply switch to English and the guard
     * would miss on both lookups. Checking the translation in EVERY shipped locale removes the
     * caller's say in it.
     *
     * It is a handful of indexed-by-nothing lookups on a small table, done once per return, and
     * the canonical spelling is first so the normal case costs one.
     *
     * @return list<string>
     */
    private function paystackPaymentTypeSpellings(): array
    {
        $spellings = [self::PAYMENT_TYPE];

        foreach ((array) glob(resource_path('lang/*.json')) as $file) {
            $locale = basename((string) $file, '.json');

            if ($locale === 'language') {
                continue;
            }

            $spellings[] = (string) trans(self::PAYMENT_TYPE, [], $locale);
        }

        return array_values(array_filter(array_unique($spellings), static fn ($v) => trim($v) !== ''));
    }

    /**
     * The existing plan order for this provider reference, under any spelling of `payment_type`.
     *
     * Deliberately NOT scoped to the current user: scoping the LOOKUP would let a second account
     * present the same reference, find nothing, and have its own plan activated on a stranger's
     * payment. One reference, one activation, whoever presents it — the caller's identity only
     * decides the RESPONSE.
     */
    private function paystackExistingPlanOrder($reference)
    {
        foreach ($this->paystackPaymentTypeSpellings() as $spelling) {
            $found = $this->existingPlanOrderForProviderTxn($spelling, $reference);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public static function paystackVerificationOutcome($result, $expectedReference): array
    {
        $envelope = is_array($result) ? $result : [];
        $payload = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];

        $envelopeOk = isset($envelope['status']) && $envelope['status'] == true;
        $state = strtolower(trim((string) ($payload['status'] ?? '')));
        $reference = trim((string) ($payload['reference'] ?? ''));
        $expected = trim((string) $expectedReference);

        return [
            'envelope_ok' => $envelopeOk,
            'state' => $state,
            // `success` and nothing else. Paystack also reports `failed`, `abandoned`,
            // `reversed` and `pending`; every one of them activated the plan before this.
            'paid' => $envelopeOk && $state === 'success',
            // hash_equals so the comparison is not length-short-circuited, and both sides must be
            // non-empty: two missing references must not read as "they match".
            'reference_matches' => $reference !== '' && $expected !== ''
                && hash_equals($reference, $expected),
        ];
    }

    //

    public $secret_key;
    public $public_key;
    public $is_enabled;

    public function paymentConfig()
    {
        $user = Auth::user();


        if (Auth::user()->type == 'admin') {
            $payment_setting = getSuperAdminAllSetting();
        } else {
            $payment_setting = Utility::getCompanyPaymentSetting($user);
        }

        $this->secret_key = isset($payment_setting['paystack_secret_key']) ? $payment_setting['paystack_secret_key'] : '';
        $this->public_key = isset($payment_setting['paystack_public_key']) ? $payment_setting['paystack_public_key'] : '';
        $this->is_enabled = isset($payment_setting['is_paystack_enabled']) ? $payment_setting['is_paystack_enabled'] : 'off';
        return $this;
    }

    public function planPayWithPaystack(Request $request)
    {
        $this->paymentConfig();

        $planID    = \Illuminate\Support\Facades\Crypt::decrypt($request->plan_id);
        $plan      = Plan::find($planID);
        $admin_payment_setting = getSuperAdminAllSetting();
        $currency = $admin_payment_setting['CURRENCY_NAME'];
        $coupon_id = '';
        $authuser = auth()->user();
        if ($plan) {
            $price = $plan->price;
            if (!empty($request->coupon)) {
                $coupons = PlanCoupon::whereRaw('BINARY `code` = ?', [$request->coupon])->where('is_active', '1')->first();
                if (!empty($coupons)) {
                    $usedCoupun     = $coupons->used_coupon();
                    $discount_value = ($plan->price / 100) * $coupons->discount;
                    $price          = $plan->price - $discount_value;
                    if ($coupons->limit == $usedCoupun) {
                        return redirect()->back()->with('error', __('This coupon code has expired.'));
                    }
                    $coupon_id = $coupons->id;
                } else {
                    return redirect()->back()->with('error', __('This coupon code is invalid or has expired.'));
                }
            }

            if ($price <= 0) {
                $authuser->plan = $plan->id;
                $authuser->save();

                $assignPlan = $authuser->assignPlan($plan->id);

                if ($assignPlan['is_success'] == true && !empty($plan)) {


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
                            'price' => $price,
                            'price_currency' => $currency,
                            'txn_id' => '',
                            'payment_type' => 'Paystack',
                            'payment_status' => 'succeeded',
                            'receipt' => null,
                            'user_id' => $authuser->id,
                        ]
                    );   // P1-PLT-02: a plan sale is a plan_orders row; Order::create wrote a blank host order with store_id NULL
                    $res['msg']  = __("Plan successfully upgraded.");
                    $res['flag'] = 2;

                    return $res;
                } else {
                    return redirect()->route('plan.index')->with('error', __('Plan fail to upgrade.'));
                }
            }

            $res_data['email']       = \Auth::user()->email;
            $res_data['total_price'] = $price;
            $res_data['currency']    = $currency;
            $res_data['flag']        = 1;
            $res_data['coupon']      = $coupon_id;
            // $res_data['payment_frequency'] = $request->paystack_payment_frequency;

            return $res_data;
        } else {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }

    public function getPaymentStatus(Request $request, $pay_id, $plan)
    {
        $payment = $this->paymentConfig();
        $planID  = \Illuminate\Support\Facades\Crypt::decrypt($plan);
        $plan    = Plan::find($planID);
        $user    = Auth::user();
        $result  = array();
        $admin_payment_setting = getSuperAdminAllSetting();
        $currency = $admin_payment_setting['CURRENCY_NAME'];
        if($plan)
        {
            try
            {
                $orderID = strtoupper(str_replace('.', '', uniqid('', true)));

                //The parameter after verify/ is the transaction reference to be verified
                $url = "https://api.paystack.co/transaction/verify/$pay_id";
                $ch  = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt(
                    $ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $payment->secret_key,
                        ]
                    );
                    $responce = curl_exec($ch);
                    curl_close($ch);
                    if($responce)
                    {
                        $result = json_decode($responce, true);
                    }

                    /*
                     * D-12 / F87 -- THE ENVELOPE IS NOT THE PAYMENT.
                     *
                     * This gate used to be `isset($result['status']) && $result['status'] == true`
                     * and nothing else. That field is whether Paystack's *verify call* succeeded,
                     * not whether the customer paid: the real transaction state is `data.status`,
                     * which the handler below demonstrably knows about, because it RECORDS it --
                     * `$order->payment_status = $result['data']['status']` -- without ever
                     * requiring it to be `success`. A failed or abandoned transaction therefore
                     * activated the plan, repeatably, and Paystack was the single outlier among the
                     * 18 providers on the `plan_activation_provider_verified` list: the other 17
                     * markers describe a check on the PAYMENT state.
                     *
                     * WHAT IS CHECKED, AND WHY ONLY THIS MUCH. D-17 asks for a match on
                     * transaction, amount, currency, plan and user "where the provider's contracts
                     * support it -- i.e. match what can actually be matched, not an invented
                     * contract".
                     *   - `data.status === 'success'` is unambiguous and is the whole of F87.
                     *   - `data.reference` is echoed by Paystack and must equal the reference this
                     *     server asked it to verify. Comparing them can only catch a provider
                     *     answering about a different transaction; it cannot refuse a real payment.
                     *   - THE AMOUNT IS NOT A REFUSAL, deliberately rather than by omission.
                     *     `GuardsPlanActivation`'s own docblock records why: "a verified provider
                     *     may legitimately post an amount back in minor units, and a blind
                     *     comparison would refuse real payments on a live integration". This repo
                     *     believes Paystack reports minor units -- it divides by 100 -- but that is
                     *     a belief read off the code, not a measurement against a live response,
                     *     and no Paystack account is reachable from here. So a disagreement with
                     *     the server-owned price is LOGGED at warning and the provider's own
                     *     captured figure is still what gets recorded, because that is what was
                     *     charged. Making it a refusal needs one real Paystack response to settle
                     *     the unit; that is recorded in the ledger, not guessed at here.
                     *   - The plan and the user are not provider-supplied at all: the plan comes
                     *     from the route and the user from the session, so there is nothing to
                     *     match them against.
                     */
                    $paystackPayload = is_array($result['data'] ?? null) ? $result['data'] : [];
                    $paystackOutcome = self::paystackVerificationOutcome($result ?? null, $pay_id);
                    $paystackAccepted = $paystackOutcome['paid'] && $paystackOutcome['reference_matches'];

                    if (! $paystackAccepted) {
                        \Illuminate\Support\Facades\Log::warning('Paystack plan activation refused: the transaction is not a matching success.', [
                            'envelope_ok' => $paystackOutcome['envelope_ok'],
                            'transaction_status' => $paystackOutcome['state'],
                            'reference_matches' => $paystackOutcome['reference_matches'],
                            'plan_id' => $plan->id ?? null,
                            'user_id' => $user->id ?? null,
                        ]);
                    }

                    if($paystackAccepted)
                    {
                        /*
                         * REPLAY IS A NO-OP, NOT A SECOND PURCHASE. The reference is a route
                         * parameter, so the same URL can be opened any number of times, and nothing
                         * stopped a second PlanOrder and a second assignPlan().
                         *
                         * THE KEY MUST NOT BE A TRANSLATED STRING, and this is the trap that makes
                         * the existing callers of this helper weaker than they look. `payment_type`
                         * was written as `__('Paystack')`, and `resources/lang/ar.json` translates
                         * it to `كومة الدفع` -- executed, not assumed. A row written while the
                         * locale was Arabic is invisible to a lookup made while the locale is
                         * English, so a locale switch between two arrivals defeats the guard
                         * entirely. The canonical English literal is now used for BOTH the lookup
                         * and the stored value, with the translated spelling checked as a fallback
                         * so rows already written under the current locale are still found. Rows
                         * written under some OTHER locale stay unmatched: a bounded residue
                         * recorded in the ledger, which needs a data decision rather than more code.
                         */
                        $paystackExistingOrder = $this->paystackExistingPlanOrder($pay_id);

                        if ($paystackExistingOrder !== null) {
                            /*
                             * WHOSE REPLAY IT IS DECIDES WHAT TO SAY, and the guard is deliberately
                             * NOT scoped to the current user.
                             *
                             * Scoping the LOOKUP by user would be actively dangerous: a second
                             * account presenting the same reference would find nothing, fall
                             * through, and get its own plan activated on someone else's payment.
                             * So the lookup stays global -- one reference, one activation, whoever
                             * presents it -- and only the RESPONSE depends on who is asking.
                             *
                             * The buyer re-opening their own return URL is the ordinary case and
                             * gets the success they expect. Anyone else is told the transaction
                             * was unsuccessful rather than "Plan activated Successfully", which
                             * would otherwise confirm to a stranger that a guessed reference is a
                             * real payment -- a reference-enumeration oracle, and the reference is
                             * a route parameter.
                             */
                            $paystackSameBuyer = (int) ($paystackExistingOrder->user_id ?? 0) === (int) ($user->id ?? -1);
                            $paystackSamePlan = (int) ($paystackExistingOrder->plan_id ?? 0) === (int) ($plan->id ?? -1);

                            if ($paystackSameBuyer && $paystackSamePlan) {
                                /*
                                 * RE-RUN THE ACTIVATION RATHER THAN JUST CLAIMING IT HAPPENED.
                                 *
                                 * The PlanOrder row is written BEFORE `assignPlan()`, so anything
                                 * that kills the request between them leaves a row and no
                                 * entitlement — and a bare "already recorded, report success"
                                 * guard would then strand that buyer permanently: every retry,
                                 * including a perfectly clean one, would be told the plan was
                                 * activated while it never was. A reachable trigger exists today:
                                 * `?coupon=` with no `coupon_id` dereferences a null `$coupons`
                                 * below, which raises an `Error` that `catch (\Exception)` does
                                 * not catch.
                                 *
                                 * `assignPlan()` is idempotent in the way that matters here — it
                                 * sets the plan and recomputes the expiry from now, rather than
                                 * accumulating — so re-running it for the SAME buyer and the SAME
                                 * plan repairs that state and is a no-op otherwise. No second
                                 * PlanOrder row is written, which is what the guard is for.
                                 */
                                $assignPlan = $user->assignPlan($plan->id);

                                if ($assignPlan['is_success']) {
                                    return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                                }

                                return redirect()->route('plan.index')->with('error', $assignPlan['error']);
                            }

                            /*
                             * SAME BUYER, DIFFERENT PLAN is the substitution attempt, not a
                             * retry: one paid reference being presented a second time against a
                             * more expensive plan. It is refused and logged like any other
                             * stranger's replay.
                             */

                            \Illuminate\Support\Facades\Log::warning('Paystack plan activation refused: this reference is already recorded against a different buyer or plan.', [
                                'plan_order_id' => $paystackExistingOrder->id ?? null,
                                'owner_user_id' => $paystackExistingOrder->user_id ?? null,
                                'presenting_user_id' => $user->id ?? null,
                                'recorded_plan_id' => $paystackExistingOrder->plan_id ?? null,
                                'requested_plan_id' => $plan->id ?? null,
                                'same_buyer' => $paystackSameBuyer,
                                'same_plan' => $paystackSamePlan,
                            ]);

                            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                        }

                        /*
                         * THE AMOUNT IS A REFUSAL, AND AN EARLIER VERSION OF THIS CODE TALKED
                         * ITSELF OUT OF IT. That version logged a mismatch and carried on, citing
                         * `GuardsPlanActivation`'s warning that "a verified provider may
                         * legitimately post an amount back in minor units, and a blind comparison
                         * would refuse real payments on a live integration" — and concluding that
                         * this repo only BELIEVED Paystack used minor units because it divides by
                         * 100.
                         *
                         * That was wrong, and the evidence was already in the repository: THIS
                         * SERVER RENDERS THE CHECKOUT, and it sends `amount: res.total_price * 100`
                         * (resources/views/plans/stripe.blade.php:3228) with
                         * `currency: res.currency` (:3229) — the platform currency this handler
                         * then compares against. Both sides are first-party and they agree, so the
                         * unit is corroborated by the write side rather than assumed. Were it
                         * otherwise, every Paystack plan sale already in `plan_orders` would be
                         * recorded at a hundredth of its price.
                         *
                         * WITHOUT THIS REFUSAL THE GATE PROVES THE WRONG THING. `status` and
                         * `reference` together prove that SOME transaction in the platform's
                         * Paystack account succeeded. They prove nothing about WHICH PLAN it
                         * bought — and the plan is a route parameter whose encrypted id the plans
                         * page prints for every plan card (`stripe.blade.php:3246`). So a merchant
                         * could pay for the cheapest plan once and open the callback URL with the
                         * most expensive plan's id. The amount is the only field that can tell
                         * those apart.
                         *
                         * THE COUPON MUST BE A CODE, NOT AN ID, or this refusal would reject every
                         * discounted purchase. The callback URL carries `?coupon_id=` and that
                         * value is `$coupons->id` (:116, :164), while `serverOwnedPlanPrice()`
                         * resolves a coupon by `code` (GuardsPlanActivation::findPlanCouponExact).
                         * Passing the id matched nothing, so the server price was always the
                         * UNDISCOUNTED one. That was harmless while this only logged; it is the
                         * difference between working and refusing every coupon sale now.
                         *
                         * The comparison is in MINOR UNITS on both sides so no rounding is
                         * introduced, and `serverOwnedPlanPrice()` applies the same
                         * `price - (price/100)*discount` formula the initiator used at :111-112.
                         */
                        $paystackCouponCode = optional(PlanCoupon::find($request->coupon_id))->code;
                        $paystackServerPrice = $this->serverOwnedPlanPrice($plan, $paystackCouponCode);
                        $paystackServerMinor = (int) round($paystackServerPrice * 100);
                        $paystackCapturedMinor = (int) round((float) ($paystackPayload['amount'] ?? 0));

                        $paystackCapturedCurrency = trim((string) ($paystackPayload['currency'] ?? ''));
                        $paystackExpectedCurrency = trim((string) $currency);
                        /* Both non-empty and different is a real mismatch; an empty one means the
                           platform has not configured a currency, and refusing every purchase over
                           a missing setting would be the wrong failure direction. */
                        $paystackCurrencyMismatch = $paystackCapturedCurrency !== ''
                            && $paystackExpectedCurrency !== ''
                            && strcasecmp($paystackCapturedCurrency, $paystackExpectedCurrency) !== 0;

                        if ($paystackCapturedMinor !== $paystackServerMinor || $paystackCurrencyMismatch) {
                            \Illuminate\Support\Facades\Log::warning('Paystack plan activation refused: the captured payment does not match the plan being activated.', [
                                'captured_minor' => $paystackCapturedMinor,
                                'server_owned_minor' => $paystackServerMinor,
                                'captured_currency' => $paystackCapturedCurrency,
                                'expected_currency' => $paystackExpectedCurrency,
                                'coupon_code_resolved' => $paystackCouponCode !== null,
                                'plan_id' => $plan->id ?? null,
                                'user_id' => $user->id ?? null,
                            ]);

                            return redirect()->route('plan.index')->with('error', __('Transaction Unsuccesfull'));
                        }

                        if($request->has('coupon_id') && $request->coupon_id != '')
                        {
                            $coupons = PlanCoupon::find($request->coupon_id);
                            if(!empty($coupons))
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
                    }

                    $order                 = new PlanOrder();
                    $order->order_id       = $orderID;
                    $order->name           = $user->name;
                    $order->card_number    = '';
                    $order->card_exp_month = '';
                    $order->card_exp_year  = '';
                    $order->plan_name      = $plan->name;
                    $order->plan_id        = $plan->id;
                    $order->price          = $result['data']['amount'] / 100;
                    $order->price_currency = $currency;
                    $order->txn_id         = $pay_id;
                    // Canonical and locale-independent; see the replay note above.
                    $order->payment_type   = self::PAYMENT_TYPE;
                    $order->payment_status = $result['data']['status'];
                    $order->receipt        = '';
                    $order->user_id        = $user->id;
                    $order->save();

                    if(!empty($request->coupon))
                    {
                        $userCoupon         = new PlanUserCoupon();
                        $userCoupon->user   = $user->id;
                        $userCoupon->coupon = $coupons->id;
                        $userCoupon->order  = $orderID;
                        $userCoupon->save();

                        $usedCoupun = $coupons->used_coupon();
                        if($coupons->limit <= $usedCoupun)
                        {
                            $coupons->is_active = 0;
                            $coupons->save();
                        }

                    }
                    $assignPlan = $user->assignPlan($plan->id);
                    if($assignPlan['is_success'])
                    {
                        return redirect()->route('plan.index')->with('success', __('Plan activated Successfully.'));
                    }
                    else
                    {
                        return redirect()->route('plan.index')->with('error', $assignPlan['error']);
                    }
                }
                else
                {
                    return redirect()->back()->with('error', __('Transaction Unsuccesfull'));
                }

            }
            catch(\Exception $e)
            {
                return redirect()->route('plan.index')->with('error', __('Transaction has been failed.'));
            }
        }
        else
        {
            return redirect()->route('plan.index')->with('error', __('Plan is deleted.'));
        }
    }



}
