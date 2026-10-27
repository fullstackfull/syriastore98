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
         * PayHerePaymnetController::planGetPayHereStatus (and ::planPayWithPayHere)
         * env key: PLAN_ACTIVATION_PAYHERE
         * STATUS:  RETIRED by owner decision at closure.
         * REASON:  The owner has decided PayHere is not needed. It also never verified: the md5
         *          hash IS computed in this repo, but OUTBOUND only (planPayWithPayHere, where
         *          the merchant secret is folded in before redirecting). Nothing is checked on
         *          the return, and the same URL serves as both successUrl and failUrl, so even a
         *          FAILED payment activated the plan.
         * RE-ENABLE WHEN: the owner reverses the retirement AND the return's `md5sig` is
         *          recomputed from the documented PayHere formula (merchant_id + order_id +
         *          payhere_amount + payhere_currency + status_code + md5(merchant_secret)) and
         *          compared, with a test over a faked return. It would also need a buildable
         *          SDK: lahirulhr/laravel-payhere is being removed at closure because its pinned
         *          commit no longer exists upstream, which is what made `composer install` fail
         *          on a clean runner. Re-enabling would mean sourcing a maintained package first.
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
         * YookassaController::paywithyookassa (the INITIATOR) and ::planGetYooKassaStatus
         * env key: PLAN_ACTIVATION_YOOKASSA
         * STATUS:  RETIRED by owner decision at closure -- not "disabled pending verification".
         * REASON:  The owner has decided YooKassa is not needed, so BOTH of its paths refuse.
         *          The return handler's only gate was a SESSION value (`Session::get(
         *          'payment_id')`) which paywithyookassa() set when it CREATED the payment, so an
         *          abandoned checkout activated the plan; a YooKassa\Client is built there and
         *          never asked anything. The INITIATOR is now gated too, so no checkout can be
         *          opened with a retired provider at all, and the coupon-covers-the-full-price
         *          branch -- which recorded a PlanOrder and called assignPlan() with no provider
         *          involvement whatsoever -- cannot be entered.
         * RE-ENABLE WHEN: the owner reverses the retirement AND planGetYooKassaStatus fetches the
         *          payment by its stored id through the SDK and requires a succeeded/paid state
         *          with a matching amount and currency, covered by a test over a faked response.
         *          Nothing external blocks that: yoomoney/yookassa-sdk-php installs from this
         *          lock and its contract is readable on any correct install. (An earlier note
         *          here claimed the SDK was absent and the contract unreadable. That was measured
         *          in one development container with an incomplete vendor/ tree and was false
         *          about the project; CI disproved it.) The work is simply not owed while the
         *          provider is retired.
         * NOT A DEPENDENCY REMOVAL: yoomoney/yookassa-sdk-php STAYS in composer.lock. The
         *          owner's decision was to retire this integration, not to strip unrelated
         *          dependencies. (Written without a slash-star glob on purpose: PlanPaymentPosture
         *          Test reads this block by backing up to the nearest comment opener, and one
         *          inside the prose truncates it.)
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

        /**
         * ------------------------------------------------------------------------------------
         * THE ELEVEN BELOW CLOSE THE LAST UNCLASSIFIED BLOCK OF assignPlan() CALLERS.
         *
         * Nineteen plan-purchase handlers had never had their ACTIVATION GATE read. Eight of
         * the nineteen turned out to be genuinely provider-verified (AuthorizeNet, Braintree,
         * Flutterwave, Khalti, NMI, PayPal, Paystack) or not a provider return path at all
         * (BankTransfer, whose activation is a platform-operator approval gated on the route at
         * routes/web.php:642). The eleven here are the residue, and every one of them activates
         * a paid subscription on something the caller holds.
         *
         * NONE of the eleven can forge the AMOUNT upward into revenue -- each prices from
         * $plan->price, a server-minted blob, or server-side session state -- so the defect is
         * not a mispriced order. It is that the SUBSCRIPTION IS FREE.
         * ------------------------------------------------------------------------------------
         */

        /*
         * AamarpayController::aamarpaysuccess
         * env key: PLAN_ACTIVATION_AAMARPAY
         * REASON:  The gate is `$data['response'] == "success"` (AamarpayController.php:189),
         *          where $data is Crypt::decrypt() of a ROUTE PARAMETER. The blob itself is
         *          unforgeable -- it is sealed with APP_KEY -- but it is minted by THIS server
         *          at checkout START (:125), before any money moves, and then travels out
         *          through aamarpay and back through the caller's browser. It is a bearer
         *          "success" token issued before payment, so holding it proves only that a
         *          checkout was started, and it stays valid for unlimited replay (txn_id is
         *          recorded as '' at :214, so there is nothing to deduplicate on). Nothing on
         *          this path asks aamarpay whether the transaction completed: the only outbound
         *          call in the controller is the request.php session setup at :138-146, in the
         *          initiator.
         * RE-ENABLE WHEN: the handler verifies tran_id against aamarpay's own transaction-status
         *          endpoint (amount, currency and plan confirmed by the provider) and refuses a
         *          tran_id it has already recorded, with that verification covered by a test
         *          over a faked provider response. Aamarpay's verification field names are not
         *          derivable from this repo -- the initiator only POSTs -- so the contract must
         *          come from aamarpay's documentation, not from a guess.
         */
        'aamarpay' => env('PLAN_ACTIVATION_AAMARPAY', false),

        /*
         * IyziPayController::iyzipayCallback
         * env key: PLAN_ACTIVATION_IYZIPAY
         * REASON:  No gate of ANY kind (IyziPayController.php:123-160): the handler writes the
         *          PlanOrder and calls assignPlan() unconditionally. The plan and the price are
         *          both ROUTE PARAMETERS ({id}/{amount}, routes/web.php:628) and the USER is
         *          `User::find($request->user_id)` (:127) -- so the caller also chooses WHOSE
         *          entitlement changes. \Iyzipay is used in initiatePayment() only, to create
         *          the checkout form; the callback consults nothing.
         * RE-ENABLE WHEN: the callback retrieves the checkout form result through the installed
         *          iyzico/iyzipay-php SDK (CheckoutForm::retrieve with the conversationId/token
         *          iyzico posts back) and requires a success status with a matching amount; AND
         *          the user is taken from Auth, never from the request. Covered by a test over a
         *          faked SDK response.
         */
        'iyzipay' => env('PLAN_ACTIVATION_IYZIPAY', false),

        /*
         * MercadoPaymentController::getPaymentStatus
         * env key: PLAN_ACTIVATION_MERCADO
         * REASON:  The gate is `$request->status == 'approved' && $request->flag == 'success'`
         *          (MercadoPaymentController.php:174) -- two query parameters. The route
         *          (plan.mercado, routes/web.php:588) takes the plan id UNENCRYPTED, so one
         *          authenticated GET names any plan and activates it. The initiator's back_urls
         *          are the literal placeholder 'https://yourdomain.com/plan/mercado/...'
         *          (:119-121), so Mercado Pago cannot even be the one calling this route in this
         *          deployment -- only a caller typing the URL can.
         * RE-ENABLE WHEN: the handler fetches the payment or merchant_order from
         *          api.mercadopago.com with the access token it already holds ($this->token)
         *          and requires an approved status with a matching amount and currency, covered
         *          by a test over a faked provider response. The back_urls must also be fixed to
         *          point at this installation.
         */
        'mercado' => env('PLAN_ACTIVATION_MERCADO', false),

        /*
         * MidtransController::planGetMidtransStatus
         * env key: PLAN_ACTIVATION_MIDTRANS
         * REASON:  The gate is `json_decode($request->json, true)['status_code'] == 200`
         *          (MidtransController.php:191-192). That JSON is the Snap.js CLIENT-SIDE result
         *          posted back by the browser (the view midtras.payment is handed snap_token and
         *          a fallback_url at :171-180), so the caller composes it. Worse, THE PLAN AND
         *          THE ORDER COME FROM DIFFERENT PLACES, as in SSLCommerz: the plan is
         *          `Plan::find($request['plan_id'])` (:193) while the order is looked up by
         *          `$request['order_id']` (:197) and flipped to 'succeeded'. A merchant can take
         *          the order_id of their own cheap PENDING order and name the most expensive
         *          plan.
         * RE-ENABLE WHEN: the status is fetched server-side through the installed
         *          midtrans/midtrans-php SDK (\Midtrans\Transaction::status($orderId)) and a
         *          settlement/capture state is required, AND the order found by order_id is
         *          required to belong to the caller and to name the SAME plan. The second half
         *          is necessary even with provider verification, because the provider confirms
         *          the cheap payment was real -- not that it paid for the expensive plan.
         */
        'midtrans' => env('PLAN_ACTIVATION_MIDTRANS', false),

        /*
         * PayFastController::success
         * env key: PLAN_ACTIVATION_PAYFAST
         * REASON:  No gate at all (PayFastController.php:126-149), and the capability is handed
         *          straight to the caller: the initiator seals plan_id/plan_amount into
         *          `$success` (:68-73), puts `route('payfast.payment.success', $success)` in the
         *          return_url field (:79), renders EVERY field into hidden inputs (:98-100) and
         *          returns that HTML to the browser in a JSON response (:101-104). The success
         *          URL is therefore in the caller's hands BEFORE any payment, and GETting it
         *          activates the plan. PayFast signs its notifications and this controller owns
         *          the primitive -- generateSignature() at :109 -- but uses it OUTBOUND only
         *          (:93). The amount is also request-sourced on the way out
         *          (`$plan_amount = $request->coupon_amount`, :65).
         * RE-ENABLE WHEN: activation is driven by PayFast's server-to-server ITN at notify_url
         *          (currently pointed at route('plan.index'), :81) with the ITN signature
         *          recomputed via generateSignature() over PayFast's documented response field
         *          order and the payment confirmed by a validate-query back to PayFast; and the
         *          amount comes from the plan, not from $request->coupon_amount. The response
         *          field ORDER is the whole security property and is not derivable from this
         *          repo's outbound string, so it must come from PayFast's documentation.
         */
        'payfast' => env('PLAN_ACTIVATION_PAYFAST', false),

        /*
         * PaymentWallPaymentController::planPayWithPaymentWall
         * env key: PLAN_ACTIVATION_PAYMENTWALL
         * REASON:  A different sub-class -- and no longer "the Yookassa precedent", which has
         *          been retracted: Yookassa's SDK does install from this lock, whereas no
         *          paymentwall package is required at all. The gate here WOULD be a real
         *          provider charge -- `$charge->isSuccessful() and empty($responseData['secure'])`
         *          then `$charge->isCaptured()` (:135-136) -- but \Paymentwall_Charge DOES NOT
         *          EXIST in this environment. Measured:
         *              php -r '... class_exists("Paymentwall_Charge")'  =>  false
         *          and no paymentwall package appears in composer.json. `new \Paymentwall_Charge()`
         *          at :131 therefore raises an Error today, which this handler does not catch, so
         *          the SDK's contract cannot be read here and its verification cannot be tested.
         *          The private key is also the hardcoded literal 'sdrsefrszdef' (:119) rather
         *          than a setting, so even with the SDK installed the charge could not
         *          authenticate. Note too that this handler writes App\Models\Order -- the
         *          STOREFRONT orders table (:160) -- for a plan purchase.
         * RE-ENABLE WHEN: a Paymentwall SDK is installed through the verified dependency path,
         *          the private key is read from the admin payment settings, and the
         *          isSuccessful()/isCaptured() gate is exercised by a test over a faked SDK
         *          response. Until the SDK is present this provider cannot be verified from this
         *          repo at all.
         */
        'paymentwall' => env('PLAN_ACTIVATION_PAYMENTWALL', false),

        /*
         * PayUPaymentController::planGetPayUStatus
         * env key: PLAN_ACTIVATION_PAYU
         * REASON:  The gate is `$data['status'] == 'true'` (PayUPaymentController.php:107) read
         *          from Crypt::decrypt($request->data). Same shape as Aamarpay -- a server-minted
         *          pre-payment bearer token -- except that here it is demonstrably in the
         *          caller's hands before payment: the initiator puts surl/furl into $pay
         *          (:76-77), and plans/payu_request.blade.php:14 renders `$pay->surl` into a
         *          hidden input in the browser. PayU's reverse hash IS computed in this repo
         *          (generateHash, :162) but OUTBOUND only (:172); the return's hash is never
         *          recomputed. The plan comes from the {plan_id} ROUTE parameter (:101) while the
         *          price comes from the session of whatever checkout was last started (:116), so
         *          a cheap checkout's amount can be recorded against an expensive plan.
         * RE-ENABLE WHEN: the return's `hash` is recomputed with the documented PayU reverse-hash
         *          formula (salt|status||||||udf5..udf1|email|firstname|productinfo|amount|txnid|key)
         *          and compared, and the txnid is required to match the session's own. Covered by
         *          a test over a faked return. The REVERSE order is the security property and is
         *          not derivable from generateHash()'s forward string, so it must come from PayU's
         *          documentation.
         */
        'payu' => env('PLAN_ACTIVATION_PAYU', false),

        /*
         * RazorpayPaymentController::getPaymentStatus
         * env key: PLAN_ACTIVATION_RAZORPAY
         * REASON:  THE VERIFICATION IS PERFORMED AND THEN NEVER READ -- the PayTabs pattern. The
         *          handler curls https://api.razorpay.com/v1/payments/{pay_id} with the key pair
         *          (:130-134) and then falls straight through to the PlanOrder write (:157) and
         *          assignPlan() (:175) with NO conditional between them. $response is consulted
         *          only for cosmetics: `$response->amount / 100` as the price (:165) and
         *          `$response->id` as the txn_id (:167). The payment id is a ROUTE PARAMETER,
         *          so the caller names the transaction that is looked up and never judged: a
         *          merchant's own ABANDONED or FAILED payment id is fetched successfully,
         *          carries an `amount`, and activates the plan -- `status` is never read.
         *          MEASURED LIMIT OF THAT CLAIM: with no outbound network at all (this
         *          environment) the handler does NOT activate -- curl_exec() returns false,
         *          `$response->amount` on null becomes an ErrorException under Laravel's error
         *          handler, and the `catch (\Exception)` at :183 redirects with 'Plan not
         *          found!'. So the exposure needs the provider to answer, which is why
         *          PlanPaymentNewlyGuardedTest proves this one structurally -- there is no
         *          conditional of any kind between the curl and assignPlan() -- rather than by
         *          driving the route.
         * RE-ENABLE WHEN: the gate becomes a required `$response->status === 'captured'` (and a
         *          matching amount) on a response that is required to have been fetched at all.
         *          The contract is readable in this repo only as far as the endpoint; the field
         *          names must come from Razorpay's documentation. Note razorpay/razorpay is NOT
         *          installed (class_exists('Razorpay\Api\Api') is false), so the SDK's signature
         *          verification is not available here either.
         */
        'razorpay' => env('PLAN_ACTIVATION_RAZORPAY', false),

        /*
         * SkrillPaymentController::getPaymentStatus
         * env key: PLAN_ACTIVATION_SKRILL
         * REASON:  The gate is `session()->has('skrill_data')` (SkrillPaymentController.php:203)
         *          -- exactly Yookassa's defect. That key is written by the INITIATOR at :176,
         *          before the browser ever leaves for Skrill, so an ABANDONED checkout satisfies
         *          it. The initiator also registers the cancel_url as the SAME route as the
         *          return_url (:135-142), so "it came back on the success URL" proves nothing
         *          here even when Skrill is the caller. And the plan is taken from
         *          `$request->ORDERID` (:193) while the price comes from the session (:236) --
         *          the decrypted route parameter at :192 is computed and discarded -- so a cheap
         *          checkout's amount can be recorded against an expensive plan.
         * RE-ENABLE WHEN: activation is driven by Skrill's server-to-server status_url with the
         *          documented md5sig recomputed and compared (merchant_id + transaction_id +
         *          upper(md5(secret_word)) + mb_amount + mb_currency + status), the status is
         *          required to be 2 (processed), and the plan is taken from the server-side
         *          session rather than from $request->ORDERID. Covered by a test over a faked
         *          notification. Note the initiator does not register a status_url at all today.
         */
        'skrill' => env('PLAN_ACTIVATION_SKRILL', false),

        /*
         * SspayController::SspayPlanGetPayment
         * env key: PLAN_ACTIVATION_SSPAY
         * REASON:  The gate is `$request->status_id == 1` (SspayController.php:160), a query
         *          parameter -- and the initiator registers ONE url as billReturnUrl AND
         *          billCallbackUrl (:53-54), so there is not even a separate server-to-server
         *          channel to tell apart from the browser. The plan and the price are both ROUTE
         *          PARAMETERS ({plan_id}/{amount}, routes/web.php:619), so `status_id=1` on a
         *          hand-typed URL activates any plan and records any amount. Nothing asks sspay.my
         *          anything; its only outbound call is createBill in the initiator (:86-94).
         * RE-ENABLE WHEN: the bill is re-fetched from sspay.my's getBillTransactions API with the
         *          userSecretKey and a paid status required for the billCode, with the amount
         *          compared against $plan->price, covered by a test over a faked provider
         *          response. The plan must come from the recorded order, not from the URL.
         */
        'sspay' => env('PLAN_ACTIVATION_SSPAY', false),

        /*
         * ToyyibpayController::toyyibpayPlanGetPayment
         * env key: PLAN_ACTIVATION_TOYYIBPAY
         * REASON:  Identical to Sspay, from which it is copied: the gate is
         *          `$request->status_id == 1` (ToyyibpayController.php:160), billReturnUrl and
         *          billCallbackUrl are the same single url (:55-56), and the plan and price are
         *          route parameters (routes/web.php:622). The only outbound call is createBill in
         *          the initiator (:88-95).
         * RE-ENABLE WHEN: the bill is re-fetched from toyyibpay.com's getBillTransactions API
         *          with the userSecretKey and billpaymentStatus required to be 1 for the
         *          billCode, with the amount compared against $plan->price, covered by a test
         *          over a faked provider response. The plan must come from the recorded order,
         *          not from the URL.
         */
        'toyyibpay' => env('PLAN_ACTIVATION_TOYYIBPAY', false),
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

        // ------------------------------------------------------------------------------------
        // SEVEN FOUND BY READING THE NINETEEN HANDLERS THAT HAD NEVER BEEN CLASSIFIED.
        //
        // All seven ask the provider, on the server, before activating; none of them can be
        // satisfied by anything the caller sends. Four take payment outright on this very
        // request (AuthorizeNet, Braintree, NMI, PayPal's capture), which is the strongest form
        // of verification available -- the money moves or the gate fails.
        //
        // READ, NOT GREPPED. Khalti looked ungated to a scan for an HTTP client because it
        // verifies through App\Khalti\Khalti::verifyPayment(); Braintree's and AuthorizeNet's
        // calls go out through vendor SDKs; and Razorpay looked verified to the same scan
        // because it DOES curl its provider -- and then never reads the answer, which is why
        // Razorpay is on the disabled map above and these seven are not.
        // ------------------------------------------------------------------------------------
        'AuthorizeNetPaymnetController' => 'executeWithApiResponse(...) then getResultCode() == "Ok" && $tresponse->getMessages() != null',
        'BraintreeController'           => '$gateway->transaction()->sale(...) then $result->success',
        'FlutterwaveController'         => "outbound POST api.ravepay.co/flwv3-pug/getpaidx/api/v2/verify then \$response['status'] == 'success'",
        'KhaltiPaymnetController'       => "Khalti::verifyPayment() POSTs khalti.com/api/v2/payment/verify/ with the merchant secret; gates on \$response['status_code'] == '200'",
        'NMIPayController'              => "outbound POST secure.networkmerchants.com/api/transact.php type=sale then \$parsedResponse['response'] == 1",
        'PaypalPaymentController'       => "capturePaymentOrder(\$request['token']) then \$response['status'] == 'COMPLETED'",
        /*
         * D-12 / F87, F88. REWRITTEN BECAUSE THE OLD MARKER WAS ACCURATE AND THE CLASSIFICATION
         * WAS STILL WRONG: it read "then $result['status'] == true", which is the API ENVELOPE,
         * and the list conflates "calls the gateway" with "checks that the payment succeeded".
         * Paystack was the single outlier among the 18 -- the other 17 markers all name a check
         * on the payment state. A marker must now describe what the code REQUIRES, not what it
         * calls.
         */
        'PaystackPaymentController'     => "outbound GET api.paystack.co/transaction/verify/{ref} then \$result['data']['status'] === 'success' AND data.reference === the requested reference AND data.amount === the server-owned plan price in minor units AND data.currency matches; replay refused by (payment_type, txn_id) across every locale spelling",
    ],

    /**
     * DOCUMENTATION ONLY -- a plan-purchase writer whose activation is NOT a provider return at
     * all, and which therefore belongs in neither list above.
     *
     * BankTransferController::planPayWithbank records a PENDING order with an uploaded receipt
     * and assigns NOTHING. The activation is BankTransferController::orderapprove (:119), an
     * out-of-band human review, and what stands in its way is authorization rather than
     * verification: routes/web.php:642 attaches App\Http\Middleware\PlatformOperatorOnly to
     * route('order.approve'). Before that middleware was attached, any authenticated merchant
     * could approve THEIR OWN pending bank-transfer order and receive a paid plan for nothing.
     *
     * Recorded here so that the next reader of the plan-payment census does not have to
     * re-derive why one of the writers consults neither map, and so that
     * PlanPaymentVerificationInventoryTest can pin the middleware that is doing the work.
     */
    'plan_activation_platform_approved' => [
        'BankTransferController' => 'routes/web.php order.approve => PlatformOperatorOnly middleware',
    ],
];
