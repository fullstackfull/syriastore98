<?php

namespace App\Services\Modules;

use App\Services\Modules\Concerns\SendsBenefitCharge;
use App\Support\Modules\ModuleBenefitCallback;
use Workdo\Donation\app\Services\DonationPaymentService;

/**
 * GAP-138 — THE DONATION BENEFIT CHARGE, WITHOUT THE PUBLIC BIN.
 * =================================================================================================
 *
 * `Workdo\Donation\app\Services\DonationPaymentService::processBenefit()` (vendored, line 968, NOT
 * edited) builds a Tap charge carrying
 *
 *     "post" => ["url" => "https://webhook.site/fd8b0712-..."]
 *
 * — the same committed public bin as the other four sites. This is the SHOPPER-FACING rail: a
 * donor at a merchant's storefront. The payload names the donor (`$request['name']`,
 * `$request['email']`), the amount they gave, and the Tap charge id that
 * `CustomerDonationController::StoreDonationPayment` — the completion writer
 * `ModulePaymentFlows` classifies as `COMPLETE` — is the return path for.
 *
 * SO THE DATA EXPOSED HERE IS A THIRD PARTY'S, NOT THE SUBSCRIBER'S. On the Campaigns rail the
 * payer is the merchant who installed the module; here it is a member of the public who donated to
 * one, and who has no relationship with this platform at all.
 *
 * `ModuleBenefitCallback` has the owner decision this rests on, why the replacement is `''` rather
 * than `null`, and why choosing a real destination is NOT taken here (the open P1-SEC-55).
 *
 * NOT `final`: the test subclasses this at runtime to replace the transport.
 */
class DonationBenefitCallbackOverride extends DonationPaymentService
{
    use SendsBenefitCharge;

    /**
     * The parent switches on `$paymentMethod`, a plain string the controller read from
     * `donationpayment_method`. `===` selects exactly the branch the parent's `case 'Benefit':`
     * would on PHP 8 (see the Campaigns override for why loose and strict agree here), and every
     * other value — including a non-string one — is handed straight back to the module.
     */
    public function process($request, $paymentMethod, $slug, $donation_amount)
    {
        if ($paymentMethod === 'Benefit') {
            return $this->processBenefit($request, $slug, $donation_amount);
        }

        return parent::process($request, $paymentMethod, $slug, $donation_amount);
    }

    /**
     * The vendored method, reproduced with ONE value changed and the POST behind the host seam.
     *
     * `$benefit_private_key` is dropped: the vendored method assigns it and never reads it. The
     * session key (`donation_request_data`, which `DisabledModuleRouteGuard` reads through
     * `ModulePaymentFlows::vendoredFlowKey()`), the catch, its translated message and the unwrap
     * are the module's own, and the unwrap is deliberately left outside the try — see the
     * Campaigns override and §6 of the evidence document for the second defect that is NOT fixed
     * here.
     */
    private function processBenefit($request, $slug, $donation_amount)
    {
        $slug = !empty($slug) ? $slug : '';

        $store = getStore($slug);

        $benefit_secret_key = \App\Models\Utility::GetValueByName('benefit_secret_key', $store->id);
        $admin_payment_setting = getAdminAllSetting($store->created_by, $store->id);

        $customerData = $this->benefitChargePayload($request, $slug, $donation_amount, $admin_payment_setting);

        session(['donation_request_data' => $request]);
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
     * THE CHARGE, VERBATIM FROM THE VENDORED SOURCE EXCEPT `post`. `redirect.url` — the donor's own
     * return to `store.donation.payment` — is untouched; only what Tap is told to POST to a server
     * changes.
     */
    protected function benefitChargePayload($request, $slug, $donation_amount, $admin_payment_setting): array
    {
        return [
            "amount" => $donation_amount,
            "currency" => $admin_payment_setting['CURRENCY_NAME'] ?? 'BHD',
            "customer_initiated" => true,
            "threeDSecure" => true,
            "save_card" => false,
            "description" => !empty($request['name']) ? $request['name'] : '',
            "metadata" => ["udf1" => "Metadata 1"],
            "reference" => ["transaction" => "txn_01", "order" => "ord_01"],
            "receipt" => ["email" => true, "sms" => true],
            "customer" => ["first_name" => $request['name'], "middle_name" => "", "last_name" => $request['name'], "email" => $request['email'], "phone" => ["country_code" => 965, "number" => 51234567]],
            "source" => ["id" => "src_bh.benefit"],
            "post" => ["url" => ModuleBenefitCallback::DESTINATION],
            "redirect" => ["url" => route('store.donation.payment', $slug)],
        ];
    }
}
