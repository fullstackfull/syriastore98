<?php

namespace App\Services\Modules;

use App\Services\Modules\Concerns\SendsBenefitCharge;
use App\Support\Modules\ModuleBenefitCallback;
use Workdo\LotteryPrizes\app\Services\LotteryPaymentService;

/**
 * GAP-138 — THE LOTTERY TICKET BENEFIT CHARGE, WITHOUT THE PUBLIC BIN.
 * =================================================================================================
 *
 * `Workdo\LotteryPrizes\app\Services\LotteryPaymentService::processBenefit()` (vendored, line 965,
 * NOT edited) builds a Tap charge carrying
 *
 *     "post" => ["url" => "https://webhook.site/fd8b0712-..."]
 *
 * — the same committed public bin. Like Donation this is a SHOPPER-FACING rail: a member of the
 * public buying a lottery ticket from a merchant's storefront. The payload names the buyer
 * (`$request['lottery_info']['firstname']`, `...['email']`), the ticket price and the Tap charge id
 * that `LotteryPrizesController::StoreLotteryPayment` — `ModulePaymentFlows`' `COMPLETE` writer for
 * this module — is the return path for.
 *
 * `ModuleBenefitCallback` has the owner decision this rests on, why the replacement is `''` rather
 * than `null`, and why choosing a real destination is NOT taken here (the open P1-SEC-55).
 *
 * NOT `final`: the test subclasses this at runtime to replace the transport.
 */
class LotteryBenefitCallbackOverride extends LotteryPaymentService
{
    use SendsBenefitCharge;

    /**
     * `'benefit'`, LOWER CASE, AND THIS IS THE ONE RAIL OF THE THREE WHERE THAT MATTERS.
     *
     * THE PREVIOUS VERSION COMPARED `'Benefit'` AND ITS DOCBLOCK SAID, IN SO MANY WORDS, that the
     * label *"is capitalised in both, which is why the single comparison below is enough."* That
     * is measurably false and the direction was reversed: the SERVICE's arms are the lower-case
     * ones and the FORM is what capitalises. `LotteryPaymentService:85` is `case 'benefit':`,
     * while Donation (`:84`) and Campaigns (`:111`) both spell it `case 'Benefit':`. LotteryPrizes
     * is the only one of the three that disagrees, and the override had been written as if all
     * three agreed. Found by the independent review of this change; the false docblock is why
     * self-review did not see it.
     *
     * WHAT THE MISMATCH COST, BOTH WAYS, because it was wrong in two directions at once:
     *
     *   1. THE EGRESS WAS NOT CLOSED ON THIS RAIL. PHP's `switch` compares with `==`, which is
     *      case-sensitive for strings, so `'benefit'` fell past this `if` into `parent::process()`
     *      and straight to the vendored private `processBenefit()` and its public bin. Reachable
     *      by anyone: `POST /{storeSlug}/lottery-form-data` sits in the bare `web` group and the
     *      controller reads the method from the body with only a `required` rule.
     *   2. IT WOULD HAVE ENABLED A DEAD PAYMENT BRANCH. The shipped form posts `Benefit`
     *      (`ucfirst($payment_data->name_string)`), which matches NO arm of the vendored switch and
     *      falls to its `default` — a 422 the controller turns into *"Incorrect currency of
     *      payment."* So on this module `'Benefit'` has never charged anybody, and intercepting it
     *      would have created a live charge where the product previously produced an error.
     *
     * SO THE FIX IS DELIBERATELY THE NARROW ONE. Matching the parent's own label closes the egress
     * and changes no routing: `'benefit'` — the only value that ever reached the bin — is now
     * handled host-side, and `'Benefit'` keeps 422-ing exactly as it does today. Intercepting BOTH
     * casings would also close the egress but would keep consequence 2, and enabling a payment
     * branch is a material change to a money path that CLAUDE.md §2 reserves to the owner. It is
     * not taken here, and must not arrive as a side effect of a security fix.
     */
    public function process($request, $paymentMethod, $slug, $lottery_price)
    {
        if ($paymentMethod === 'benefit') {
            return $this->processBenefit($request, $slug, $lottery_price);
        }

        return parent::process($request, $paymentMethod, $slug, $lottery_price);
    }

    /**
     * The vendored method, reproduced with ONE value changed and the POST behind the host seam.
     *
     * `$benefit_private_key` is dropped: assigned and never read. The session key
     * (`lottery_request_data`, read by `DisabledModuleRouteGuard` through
     * `ModulePaymentFlows::vendoredFlowKey()`), the catch, its translated message and the unwrap
     * are the module's own, and the unwrap is deliberately left outside the try — see §6 of the
     * evidence document for the second defect that is NOT fixed here.
     */
    private function processBenefit($request, $slug, $lottery_price)
    {
        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);

        $benefit_secret_key = \App\Models\Utility::GetValueByName('benefit_secret_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);

        $customerData = $this->benefitChargePayload($request, $slug, $lottery_price, $admin_payment_setting);

        session(['lottery_request_data' => $request]);
        $responseData = json_encode($customerData);

        try {
            $response = $this->sendBenefitCharge((string) $responseData, $benefit_secret_key);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Currency Not Supported.Contact To Your Site Admin'));
        }

        $data = $response->getBody();
        $res = json_decode($data);

        return $res->transaction->url;
    }

    /**
     * THE CHARGE, VERBATIM FROM THE VENDORED SOURCE EXCEPT `post`. `redirect.url` — the buyer's own
     * return to `store.lottery.payment` — is untouched; only what Tap is told to POST to a server
     * changes.
     */
    protected function benefitChargePayload($request, $slug, $lottery_price, $admin_payment_setting): array
    {
        return [
            "amount" => $lottery_price,
            "currency" => $admin_payment_setting['CURRENCY_NAME'] ?? 'BHD',
            "customer_initiated" => true,
            "threeDSecure" => true,
            "save_card" => false,
            "description" => !empty($request['lottery_info']['firstname']) ? $request['lottery_info']['firstname'] : '',
            "metadata" => ["udf1" => "Metadata 1"],
            "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
            "receipt" => ["email" => true, "sms" => true],
            "customer" => ["first_name" => $request['lottery_info']['firstname'], "middle_name" => "", "last_name" => $request['lottery_info']['firstname'], "email" => $request['lottery_info']['email'], "phone" => ["country_code" => 965, "number" => 51234567]],
            "source" => ["id" => "src_bh.benefit"],
            "post" => ["url" => ModuleBenefitCallback::DESTINATION],
            "redirect" => ["url" => route('store.lottery.payment', $slug)],
        ];
    }
}
