<?php

namespace App\Services\Modules;

use App\Services\Modules\Concerns\SendsBenefitCharge;
use App\Support\Modules\ModuleBenefitCallback;
use Workdo\Campaigns\app\Services\CampaignsPaymentService;

/**
 * GAP-138 — THE CAMPAIGNS BENEFIT CHARGE, WITHOUT THE PUBLIC BIN.
 * =================================================================================================
 *
 * `Workdo\Campaigns\app\Services\CampaignsPaymentService::processBenefit()` (vendored, line 940,
 * NOT edited) builds a Tap charge carrying
 *
 *     "post" => ["url" => "https://webhook.site/fd8b0712-..."]
 *
 * `post.url` is Tap's SERVER-TO-SERVER notification destination, and that URL is a public bin
 * committed in this repository and in its git history. Campaigns is the MERCHANT-PAYS-THE-PLATFORM
 * rail — a merchant buying a promoted placement — so the payload names the merchant (`$user->name`,
 * `$user->email`), the amount, and the Tap charge id that `CampaignsController::paymentSuccess` is
 * the return path for.
 *
 * WHY A SUBCLASS AND WHY `process()`. `packages/workdo/` must not be edited; `processBenefit()` is
 * PRIVATE, so a same-named method here would be a different method and the parent's `process()`
 * would still call the parent's. `process()` is public, so this takes the one branch it must and
 * hands every other gateway straight back to the module. `ModuleBenefitCallback` has the full
 * reasoning, the owner decision it rests on, and the decision it deliberately does not take.
 *
 * NOT `final`: `ModuleBenefitCallbackDestinationTest` subclasses this at runtime to replace the
 * transport, which is how the bytes that would reach Tap are measured without contacting Tap.
 */
class CampaignsBenefitCallbackOverride extends CampaignsPaymentService
{
    use SendsBenefitCharge;

    /**
     * STRICT COMPARISON, AND IT IS EQUIVALENT TO THE PARENT'S `case 'Benefit':`.
     *
     * The parent is a `switch ($request->payment_type)`, which compares loosely. On PHP 8 no value
     * that is not the identical string loosely equals `'Benefit'`: the 7.x string-to-zero rule is
     * gone, and `==` does not invoke `__toString()`. So `===` selects exactly the branch the parent
     * would have, and `null` (an absent field) falls through to the parent's `default` as before.
     */
    public function process($request)
    {
        if (is_object($request) && ($request->payment_type ?? null) === 'Benefit') {
            return $this->processBenefit($request);
        }

        return parent::process($request);
    }

    /**
     * The vendored method, reproduced with ONE value changed and the POST behind the host seam.
     *
     * `$benefit_private_key` and `$currency` are the only other differences: the vendored method
     * assigns both and reads neither, so they are dropped. Nothing else about the flow moves —
     * the session key (`campaigns_request_data`, which `DisabledModuleRouteGuard` reads through
     * `ModulePaymentFlows::vendoredFlowKey()`), the catch, its message, and the unwrap are the
     * module's own.
     *
     * THE UNWRAP IS LEFT WHERE THE MODULE PUT IT — outside the try. The first-party twin had the
     * same shape and P4-F046 moved it inside, because Tap sends a 200 carrying an error body and
     * reading `->transaction` on the resulting null is an ErrorException. That is a REAL second
     * defect and it is NOT fixed here: this change is authorised to remove the bin, and silently
     * altering what a merchant sees when a payment fails is a different decision with a different
     * blast radius. It is recorded in
     * `docs/development/phase-4/PHASE-04-GAP-138-MODULE-BENEFIT-CALLBACK.md` §6 instead.
     */
    private function processBenefit($request)
    {
        $amount = $request->total_cost;
        $requests_data = $request->all();
        $payment_setting = getSuperAdminAllSetting();

        $benefit_secret_key = isset($payment_setting['benefit_secret_key']) ? $payment_setting['benefit_secret_key'] : '';
        $user = \Auth::user();

        $customerData = $this->benefitChargePayload($amount, $user, $payment_setting);

        session(['campaigns_request_data' => $requests_data]);
        $responseData = json_encode($customerData);

        try {
            $response = $this->sendBenefitCharge((string) $responseData, $benefit_secret_key);
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', 'Currency Not Supported.Contact To Your Site Admin');
        }

        $data = $response->getBody();
        $res = json_decode($data);

        return $res->transaction->url;
    }

    /**
     * THE CHARGE, VERBATIM FROM THE VENDORED SOURCE EXCEPT `post`.
     *
     * Every key, its order and its expression are the module's.
     * `test_the_override_payload_is_the_vendored_payload_except_its_callback_destination` compares
     * this literal against the vendored one key by key, so a module update that changes the charge
     * fails the build rather than leaving Benefit on the old shape.
     *
     * `redirect.url` IS UNTOUCHED. It is the merchant's own return from the gateway; emptying it
     * would strand every Benefit payer. Only `post.url` — what Tap is told to POST to a server —
     * changes.
     */
    protected function benefitChargePayload($amount, $user, $payment_setting): array
    {
        return [
            "amount" => $amount,
            "currency" => $payment_setting['CURRENCY_NAME'] ?? 'BHD',
            "customer_initiated" => true,
            "threeDSecure" => true,
            "save_card" => false,
            "description" => !empty($user->name) ? $user->name : '',
            "metadata" => ["udf1" => "Metadata 1"],
            "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
            "receipt" => ["email" => true, "sms" => true],
            "customer" => ["first_name" => $user->name, "middle_name" => "", "last_name" => $user->name, "email" => $user->email, "phone" => ["country_code" => 965, "number" => 51234567]],
            "source" => ["id" => "src_bh.benefit"],
            "post" => ["url" => ModuleBenefitCallback::DESTINATION],
            "redirect" => ["url" => route('promote.success')],
        ];
    }
}
