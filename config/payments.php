<?php

/**
 * PLAN-ACTIVATION ENABLEMENT, PER PAYMENT PROVIDER. FAIL-CLOSED BY DEFAULT.
 * =============================================================================================
 *
 * WHAT THIS FILE GOVERNS, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It governs exactly one thing: whether a named provider's PLAN-PURCHASE return handler is
 * allowed to create a PlanOrder and call assignPlan(). It is consulted nowhere else.
 *
 * It does NOT touch, and must not be made to touch:
 *   - storefront ORDER payments. Those run through PaymentController / OrderController and the
 *     per-controller store paths (storeGetOzowStatus, storeGetDPOStatus, getProductStatus, the
 *     `slug` branch of SenangPayController::paymentCallback, ...). None of those consult this
 *     file. A merchant's customers keep checking out while a provider is disabled here.
 *   - the merchant's or platform admin's ability to assign a plan by hand.
 *   - any provider not named in the map below. An absent provider is NOT silently disabled
 *     everywhere; it is simply a provider whose handler does not consult this file at all.
 *
 * WHY THE DEFAULT IS DISABLED.
 *
 * Each handler in the map records a plan purchase and activates a paid subscription on the
 * strength of something the CALLER supplies -- `status=success`, `responsecode=0`,
 * `Status=Complete`, a session flag, or in several cases nothing whatsoever. None of them asks
 * the provider whether money actually moved. One authenticated GET to such a route therefore
 * activates any paid plan for free, and each gateway's own checkout initiator publishes the
 * replayable URL, so a merchant who starts ONE legitimate checkout holds every parameter
 * needed to replay it.
 *
 * Real verification cannot be implemented for these from this environment: no payment provider
 * is reachable, and guessing a callback contract is how you ship a unit bug (this codebase
 * already has one -- processPaymera strips '.', so 1250.50 goes out as 125050 while 1250 goes
 * out as 1250). The owner's instruction is therefore the posture encoded here: "A provider that
 * cannot be verified must not activate paid subscriptions."
 *
 * HOW THE OWNER RE-ENABLES ONE, WITHOUT A CODE CHANGE.
 *
 * Every entry reads an env key. Setting that key to true in the deployment environment
 * re-enables plan activation for that provider alone. Each entry below carries its env key, the
 * REASON it is off, and the exact RE-ENABLE CONDITIONS.
 *
 * THIS IS NOT A SILENT KILL SWITCH. tests/Feature/Security/PlanPaymentPostureTest asserts, per
 * provider, which state it is in and that the shipped default is disabled, so the posture is
 * visible in the suite and cannot drift unnoticed in either direction.
 *
 * A DISABLED PROVIDER IS NOT A VERIFIED PROVIDER. Turning an entry off closes the free
 * activation by refusing the route; it does not make the integration trustworthy. The
 * classification is FAIL-CLOSED -- DISABLED PENDING VERIFICATION.
 */
return [

    /**
     * Providers whose PLAN-purchase return handler consults this map before writing anything.
     *
     * The guard is App\Http\Controllers\Concerns\GuardsPlanActivation::assertPlanActivationEnabled(),
     * called as the FIRST statement of each handler -- before the PlanOrder is built and before
     * assignPlan() -- so a refusal leaves no partial row behind. A refusal is HTTP 403, never
     * 401: repo B's lib/network/network.dart turns any 401 into a forced logout that clears
     * stored preferences, and handles 403 as "not allowed" without logging the user out.
     *
     * A MISSING KEY IS TREATED AS DISABLED, not as enabled. Deleting a line here fails closed.
     */
    'plan_activation' => [

        /*
         * NepalstePaymnetController::planGetNepalsteStatus
         * env key: PLAN_ACTIVATION_NEPALSTE
         * REASON:  No gate whatsoever. The handler creates the PlanOrder and calls assignPlan()
         *          unconditionally on a GET to route('nepalste.status'), which is published as
         *          both success_url AND ipn_url by planPayWithnepalste().
         * RE-ENABLE WHEN: the handler verifies the transaction against Nepalste's own status
         *          endpoint (transaction id, amount, currency and plan all confirmed by the
         *          provider), with that verification covered by a test using a faked provider
         *          response. Nepalste's return/IPN field names are not derivable from this repo
         *          -- planPayWithnepalste() only POSTS -- so the contract must come from
         *          Nepalste's documentation, not from a guess.
         */
        'nepalste' => env('PLAN_ACTIVATION_NEPALSTE', false),

        /*
         * PayHerePaymnetController::planGetPayHereStatus
         * env key: PLAN_ACTIVATION_PAYHERE
         * REASON:  No gate. PayHere's md5 hash IS computed in this repo, but OUTBOUND only
         *          (planPayWithPayHere, where the merchant secret is folded in before
         *          redirecting). Nothing is checked on the return, and the same URL is used as
         *          both successUrl and failUrl, so even a FAILED payment activates the plan.
         * RE-ENABLE WHEN: the return's `md5sig` is recomputed from the documented PayHere
         *          formula (merchant_id + order_id + payhere_amount + payhere_currency +
         *          status_code + md5(merchant_secret)) and compared, with a test over a faked
         *          return. NOTE the deployment also depends on lahirulhr/laravel-payhere, whose
         *          pinned commit no longer exists upstream (composer install 404s on a clean
         *          runner), so this provider cannot currently be built reproducibly either.
         */
        'payhere' => env('PLAN_ACTIVATION_PAYHERE', false),

        /*
         * PhonePePaymentController::planGetPhonePeStatus
         * env key: PLAN_ACTIVATION_PHONEPE
         * REASON:  No gate. App\Libraries\PhonePe\PhonePe is used to INITIATE the payment only;
         *          the redirect lands on this handler, which writes and activates.
         * RE-ENABLE WHEN: the handler calls PhonePe's order-status API for merchantOrderId and
         *          requires a confirmed-paid state plus a matching amount. MIND THE UNIT:
         *          planPayWithPhonePe sends `(int) str_replace([' ', ',', $currency], '', $price)`,
         *          which is neither reliably paise nor reliably rupees -- that unit must be
         *          settled before any amount comparison is added.
         */
        'phonepe' => env('PLAN_ACTIVATION_PHONEPE', false),

        /*
         * PaddlePaymentController::planGetPaddleStatus
         * env key: PLAN_ACTIVATION_PADDLE
         * REASON:  No gate. Also the weakest failure mode of the set: the handler reads
         *          `$plan->name ?? null` and `$plan->id ?? null`, so it happily writes a
         *          PlanOrder with a NULL plan_id before dying on $plan->id in assignPlan().
         * RE-ENABLE WHEN: activation is driven by a signature-verified Paddle webhook
         *          (p_signature against the Paddle public key) rather than by a browser return,
         *          with a test over a faked signed payload.
         */
        'paddle' => env('PLAN_ACTIVATION_PADDLE', false),

        /*
         * FedPayPaymentController::planGetFedPayStatus
         * env key: PLAN_ACTIVATION_FEDPAY
         * REASON:  The gate is `$request->status == 'approved'`, which the caller supplies.
         * RE-ENABLE WHEN: the transaction is confirmed by querying FedPay, with amount and
         *          currency checked, covered by a test over a faked provider response.
         */
        'fedpay' => env('PLAN_ACTIVATION_FEDPAY', false),

        /*
         * EasebuzzController::notify_url   (PLAN path only; getProductStatus is the store path)
         * env key: PLAN_ACTIVATION_EASEBUZZ
         * REASON:  The gate is `$request->error == 'Transaction is successful.'` -- an English
         *          sentence, supplied by the caller, compared by string equality.
         * RE-ENABLE WHEN: the Easebuzz response hash is recomputed from the documented
         *          reverse-hash formula using the salt and compared, or the transaction is
         *          re-fetched through the Easebuzz SDK already used by planPayWithEasebuzz;
         *          with a test over a faked response.
         */
        'easebuzz' => env('PLAN_ACTIVATION_EASEBUZZ', false),

        /*
         * DPOPayController::planGetDPOPayStatus   (store path is storeGetDPOStatus)
         * env key: PLAN_ACTIVATION_DPOPAY
         * REASON:  The gate is `$request['response']['Result']`, read straight off the request,
         *          so a caller supplies its own result code.
         * RE-ENABLE WHEN: the handler calls the controller's OWN checkToken() helper -- which
         *          already posts verifyToken to secure.3gdirectpay.com and is the correct
         *          verification for this provider -- and requires its confirmation, with amount
         *          and currency checked. checkToken() exists in this file today and is simply
         *          not called on the plan path; wire it up and this provider becomes verifiable
         *          without guessing a contract.
         */
        'dpopay' => env('PLAN_ACTIVATION_DPOPAY', false),

        /*
         * YookassaController::planGetYooKassaStatus
         * env key: PLAN_ACTIVATION_YOOKASSA
         * REASON:  The only gate is a SESSION value (`\Session::get('payment_id')`), which
         *          merely proves this browser once started a checkout -- not that it completed.
         *          A YooKassa\Client IS constructed in the handler and then never asked
         *          anything.
         * RE-ENABLE WHEN: the handler calls the SDK's payment-info lookup for the stored
         *          payment id and requires a succeeded/paid state with a matching amount and
         *          currency.
         * BLOCKED ON MORE THAN CODE: yoomoney/yookassa-sdk-php is pinned in composer.lock but
         *          is NOT INSTALLED in this environment -- `class_exists('YooKassa\Client')` is
         *          false and no file under vendor/ declares that namespace. So `new Client()` in
         *          the handler raises an Error today, which the handler's `catch (\Exception)`
         *          does not catch. The SDK's contract therefore cannot be read here and its
         *          verification cannot be implemented or tested until the SDK is installed.
         */
        'yookassa' => env('PLAN_ACTIVATION_YOOKASSA', false),

        /*
         * SenangPayController::paymentCallback   (PLAN branch only; the `slug` branch is the
         *                                         store path and is NOT governed by this map)
         * env key: PLAN_ACTIVATION_SENANGPAY
         * REASON:  A distinct and milder sub-class: the plan AND the price come from server-side
         *          session state, so neither can be forged and the recorded amount is always
         *          right. But the only gate was `$request->status_id == 1`, which the caller
         *          supplies, so an abandoned checkout could be converted into a free plan.
         * WHAT HAS CHANGED: the handler now ALSO requires
         *          App\Models\Senangpay::checkIfReturnHashCorrect(), the return-hash verifier
         *          that has existed in this repo all along (app/Models/Senangpay.php:120) with
         *          ZERO call sites. That is a real cryptographic gate keyed on the merchant
         *          secret, and it is covered by a test.
         * STILL DISABLED BECAUSE: that verifier has never been exercised by any code path here,
         *          so there is no production evidence its formula matches SenangPay's. The
         *          formula at Senangpay.php:115 appends a literal '&hash=[HASH]' to the signed
         *          string, which is unusual enough to need confirming before real payments
         *          depend on it.
         * RE-ENABLE WHEN: generateReturnHash()'s formula is confirmed against SenangPay's
         *          published return-hash specification (one sandbox payment is enough). No code
         *          change is needed after that -- only this env key.
         */
        'senangpay' => env('PLAN_ACTIVATION_SENANGPAY', false),

        /*
         * PaytrController::paytrsuccessCallback
         * env key: PLAN_ACTIVATION_PAYTR
         * REASON:  The gate is `$request->payment_status == "success"`. PlanpayWithPaytr sets
         *          `$request['payment_status']` from its OWN curl result, but that is a
         *          different request in a different lifecycle; the callback route
         *          (Route::any 'pay.paytr.success') receives whatever the caller sends.
         * RE-ENABLE WHEN: the PayTR callback hash is recomputed from the documented formula
         *          (merchant_oid + merchant_salt + status + total_amount, HMAC-SHA256 with the
         *          merchant key -- the same primitive already used OUTBOUND at
         *          PaytrController:126) and compared, with a test over a faked callback.
         */
        'paytr' => env('PLAN_ACTIVATION_PAYTR', false),

        /*
         * PaiementProPaymentController::planGetPaiementproStatus
         * env key: PLAN_ACTIVATION_PAIEMENTPRO
         * REASON:  The gate is `$request->responsecode == 0`, read straight off the request.
         * RE-ENABLE WHEN: the transaction is confirmed against PaiementPro's status endpoint
         *          with amount and currency checked, covered by a test over a faked response.
         */
        'paiementpro' => env('PLAN_ACTIVATION_PAIEMENTPRO', false),

        /*
         * OzowController::planGetOzowStatus   (store path is storeGetOzowStatus)
         * env key: PLAN_ACTIVATION_OZOW
         * REASON:  The gate is `$request['Status'] == 'Complete'`, supplied by the caller. Ozow
         *          signs its notifications, and this controller already owns the primitive --
         *          generate_request_hash_check() / get_sha512_hash() at OzowController:17-26 --
         *          but uses it OUTBOUND only, at :108.
         * RE-ENABLE WHEN: the return's `Hash` is recomputed with get_sha512_hash() over Ozow's
         *          documented response field order (SiteCode, TransactionId,
         *          TransactionReference, Amount, Status, Optional1..5, CurrencyCode, IsTest,
         *          StatusMessage, then the private key, lower-cased) and compared. The field
         *          ORDER is the whole security property and is not derivable from this repo's
         *          outbound string, so it must come from Ozow's documentation.
         */
        'ozow' => env('PLAN_ACTIVATION_OZOW', false),

        /*
         * ------------------------------------------------------------------------------------
         * THE THREE BELOW WERE FOUND BY CLOSING A HOLE IN THE AUDIT'S OWN CENSUS, not by the
         * census. It looked for `$order->price = ...`, so it never saw a handler that writes
         * `$planorder->price` (CoinGate), one that writes through `PlanOrder::create([...])`
         * (PayTabs, CyberSource), or one that writes NO price at all because it flips an
         * existing order (SSLCommerz). Nine assignPlan() callers were outside the inventory
         * entirely. PlanPaymentVerificationInventoryTest now covers all four shapes.
         * ------------------------------------------------------------------------------------
         */

        /*
         * CoingateController::coingatePlanGetPayment
         * env key: PLAN_ACTIVATION_COINGATE
         * REASON:  No gate of any kind. route('coingate.coingate.callback') is a plain GET
         *          (routes/web.php:607); the handler reads plan_id off the request, writes
         *          `$planorder->payment_status = 'success'` and calls assignPlan(). The price
         *          was already server-owned, so this is a free activation that records the
         *          CORRECT price for a payment that never happened -- harder to spot in the
         *          books than a zero-amount row.
         * RE-ENABLE WHEN: CoinGate's order-status API is consulted for the order id and a paid
         *          state is required, with amount and currency checked, covered by a test over
         *          a faked provider response.
         */
        'coingate' => env('PLAN_ACTIVATION_COINGATE', false),

        /*
         * PaytabsController::planGetPaymentStatus
         * env key: PLAN_ACTIVATION_PAYTABS
         * REASON:  The real verification is computed and then discarded. $this->Verify()
         *          (PaytabsController:46) posts payment/query to PayTabs with the server key
         *          and the stored tran_ref and returns ['success' => bool]; the handler assigns
         *          it to $status and never reads it. The actual gate is
         *          `$request->respMessage == "Authorised"`, a string the caller supplies. Same
         *          family as Yookassa building a client and never calling it. The price here
         *          was already server-owned.
         * RE-ENABLE WHEN: the gate becomes `$status['success'] === true`. That is a ONE-LINE
         *          change whose contract is fully readable in this repo, and because Verify()
         *          uses the Http facade it is testable with Http::fake(). It was not made here
         *          for one measurable reason: Verify() resolves the server key, region and
         *          tran_ref from Session::get($order_id), written by PaytabsPaymentPrepare, so
         *          a server-to-server callback would carry no session and would be REFUSED.
         *          Confirm PayTabs returns the customer through the browser with the session
         *          intact before switching the gate, or key the tran_ref off the order row
         *          instead of the session.
         */
        'paytabs' => env('PLAN_ACTIVATION_PAYTABS', false),

        /*
         * SSLCommerzPaymentController::planGetSSLCommerzStatus
         * env key: PLAN_ACTIVATION_SSLCOMMERZ
         * REASON:  The most damaging of the set. The gate is `if (!$request->success == 0)` --
         *          which parses as `(!$request->success) == 0`, true whenever `success` is
         *          truthy, and caller-supplied either way. Worse, THE PLAN AND THE ORDER COME
         *          FROM DIFFERENT PLACES: the order is found by `$request['tran_id']` while the
         *          plan comes from the {plan_id} ROUTE parameter. A merchant can check out for
         *          the CHEAPEST plan, take their own real tran_id, and call this route naming
         *          the MOST EXPENSIVE plan -- their cheap order is flipped to 'succeeded' and
         *          the expensive plan is assigned, with the cheap price recorded against the
         *          expensive entitlement. The books stay internally consistent, so nothing
         *          looks wrong. assignPlan() is also called twice (:157 and :175).
         * RE-ENABLE WHEN: the transaction is validated through SSLCommerz's validator API AND
         *          the order found by tran_id is required to belong to the caller and to name
         *          the SAME plan as the route parameter. The second half is necessary even with
         *          provider validation, because provider validation says the cheap payment was
         *          real -- not that it paid for the expensive plan.
         */
        'sslcommerz' => env('PLAN_ACTIVATION_SSLCOMMERZ', false),
    ],

    /**
     * DOCUMENTATION ONLY -- these providers' plan handlers do NOT consult the map above.
     *
     * Each of these asks the provider, on the server, whether the transaction really succeeded
     * before activating, so a forged return cannot activate a plan and there is nothing to fail
     * closed. They are listed so the posture test can assert that the two sets together account
     * for every request-priced plan-purchase writer -- i.e. that no provider is quietly in
     * neither list.
     *
     * The value is the in-code verification marker, so that losing the verification shows up as
     * a lie in this file rather than as silence.
     *
     * NOTE: every one of these ALSO used to record a REQUEST-SUPPLIED price. That is a
     * data-integrity defect rather than a free activation (the plan_orders row could carry an
     * amount the caller chose), and it has been closed for all of them: the recorded price is
     * now recomputed from $plan->price plus a server-validated coupon. No amount-mismatch
     * refusal was added on these paths, deliberately -- the amount a verified provider posts
     * back may legitimately be in minor units, and a blind comparison would refuse genuine
     * payments on live integrations.
     */
    'plan_activation_provider_verified' => [
        'TapPaymnetController'          => '->status != "CAPTURED"',
        'CashfreeController'            => '$info->payment_status == "SUCCESS"',
        'BenefitPaymentController'      => 'outbound GET api.tap.company/v2/charges/{tap_id}, gates on $data->gateway->response->code',
        'PaynowController'              => '$this->paynowgetstatus()',
        'PaytmPaymentController'        => '$transaction->isSuccessful()',
        'XenditPaymentController'       => "\$getInvoice['status'] == 'PAID'",
        'CinetPayController'            => "\$this->getPaymentStatus(...) then \$response_body['code'] == '00'",
        'PowertranzPaymentController'   => "Http::post(.../api/spi/Payment) then ['Approved'] === true && ['IsoResponseCode'] == '00'",

        // ALSO OUTSIDE THE OLD CENSUS -- they write through PlanOrder::create([...]) or
        // $planorder->price -- and all three resolve onto the SAFE side on reading.
        //
        // Stripe deserves a note: it fabricates ['paid'] = 1 and ['captured'] = 1 in an `else`
        // branch, which looks self-fulfilling until you read the condition. It is
        // `if ($price > 0.0)`, and $price is computed from $plan->price minus a validated
        // coupon -- so the fabricated success only happens on a SERVER-computed zero price,
        // i.e. a free plan where no charge is due. That is legitimate.
        'CyberSourceController'         => '"AUTHORIZED"',
        'MolliePaymentController'       => '$payment->isPaid()',
        'StripePaymentController'       => "Stripe\\Charge::create(...) then ['paid'] == 1 && ['captured'] == 1",
    ],
];
