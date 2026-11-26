<?php

namespace App\Support\Modules;

/**
 * WHERE A VENDORED MODULE'S BENEFIT CHARGE IS TOLD TO SEND ITS SERVER-TO-SERVER CALLBACK.
 * =================================================================================================
 *
 * GAP-138 / P04-P5-04. Three vendored WorkDo payment services build a Tap charge whose `post.url`
 * is `https://webhook.site/fd8b0712-...`, a PUBLIC bin whose URL is committed in this repository
 * and in its git history, so it is not a secret: anybody holding it receives whatever Tap posts
 * there. `post.url` is Tap's SERVER-TO-SERVER notification destination, so for every real Benefit
 * payer Tap was asked to POST the charge -- amount, currency, the payer's name and email, and the
 * charge id this platform's return paths treat as PROOF OF PAYMENT -- to a page anyone can read.
 *
 * The two FIRST-PARTY copies were removed under P4-F046 (`App\Services\PaymentService::
 * processBenefit()` and `BenefitPaymentController`, both now sending `''`). These three are the
 * remainder, and `GatewayCallbackDestinationTest::VENDORED_COPIES_NOT_ASSERTED` recorded them as
 * "a standing red light" precisely because `packages/workdo/` must not be edited.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *  THE DECISION THIS CLASS CARRIES, AND THE ONE IT DOES NOT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The owner decision is **"remove webhook.site"**, quoted verbatim at
 * `app/Services/PaymentService.php:1818`. It is not limited to the first-party copy, so removing
 * the bin from these three is covered by it.
 *
 * **CHOOSING A REPLACEMENT DESTINATION IS NOT.** That is the OPEN escalation `P1-SEC-55`. So
 * `DESTINATION` is the empty string and NOTHING here is configurable: no env var, no setting, no
 * fallback to a host route. A configurable destination would BE the decision, taken quietly.
 *
 * WHY `''` AND NOT `null`, which looks equally like "no callback". `PaymentService.php:1817-1845`
 * has the measurement and it is worth repeating because the first draft of the first-party fix got
 * it wrong: `processTap` and `TapPaymnetController` pass PHP `null` into
 * `App\Package\Payment::charge()`, which builds its JSON BY STRING CONCATENATION and therefore puts
 * `"post":{"url":""}` on the wire. The Benefit sites `json_encode()` an array, so `null` here would
 * reach Tap as `"url":null` -- a shape no other Tap flow in this repository has ever sent, and one
 * nobody here can verify, because no Tap credential exists in this container and probing a live
 * gateway is forbidden. `''` is byte-identical to what the card flows already prove. It adds no
 * unknown to a payment rail.
 *
 * WHAT IS LOST BY SENDING NO CALLBACK URL: nothing this application was using. No route in this
 * repository receives a Tap server-to-server callback -- measured under P4-F046; the only
 * credential-authenticated provider-callback receiver is the delivery one. The payer's return is
 * `redirect.url`, which every override leaves exactly as the module built it.
 *
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *  WHY THE FIX IS A CONTAINER BINDING AND NOT AN EDIT
 * ═════════════════════════════════════════════════════════════════════════════════════════════════
 *
 * CLAUDE.md forbids editing `packages/workdo/` and `.github/scripts/guard-no-workdo-changes.sh`
 * refuses a pull request that does, for the reason that applies exactly here: an in-place edit is
 * silently reverted by the next upstream module update and leaves no patch record. The same reason
 * `DisabledModuleRouteGuard` and `ModuleAdministrativeRouteGuard` are host middleware rather than
 * module patches.
 *
 * The seam is the one `AppServiceProvider::register()` already uses for `SafeHelpdeskData` and
 * `TenantScopedCampaignsData`: all three controllers take their payment service by CONSTRUCTOR
 * INJECTION, so Laravel resolves it through the container and a host subclass bound over the
 * vendored name is what the controller is handed. Asserted, not assumed, by
 * `ModuleBenefitCallbackDestinationTest::test_premise_each_controller_takes_its_payment_service_by_constructor_injection`
 * -- a module that constructed its service with `new` would be UNCOVERED by this and the test says
 * so in those words.
 *
 * `processBenefit()` is PRIVATE in all three services, so it cannot be overridden: a subclass's
 * same-named method is a different method and the parent's own `process()` would still reach the
 * parent's. The public `process()` IS overridable, so each override takes the `Benefit` branch
 * itself and delegates every other branch to `parent::process()`.
 *
 * REPRODUCING A VENDORED PAYLOAD IS A DRIFT RISK AND IS RATCHETED. A module update that adds a
 * field to the charge would leave the override shipping the old shape for Benefit only --
 * invisible, and on a payment path.
 * `test_the_override_payload_is_the_vendored_payload_except_its_callback_destination` compares the
 * two literals KEY BY KEY on their source expressions, so `post` is the only permitted difference
 * and anything else fails the build.
 */
final class ModuleBenefitCallback
{
    /**
     * The Benefit charge's `post.url`.
     *
     * NOT A DESTINATION, AND NOT CONFIGURABLE. See the class docblock: choosing one is the open
     * owner escalation P1-SEC-55, and `ModuleBenefitCallbackCensusTest` fails if this is ever
     * anything but the empty string, so a destination cannot arrive here without that decision.
     */
    public const DESTINATION = '';

    /**
     * Tap's charge endpoint, exactly as all five sites (two first-party, three vendored) spell it.
     * Named so the transport has one spelling; NEVER contacted from a test.
     */
    public const TAP_CHARGES_ENDPOINT = 'https://api.tap.company/v2/charges';

    /**
     * `vendored service => host override`, the whole of what `AppServiceProvider::register()` binds.
     *
     * BY NAME, as `::class` on an imported name is a compile-time string: nothing here loads either
     * class, so this list costs nothing while the modules are switched off -- the same property the
     * four existing Helpdesk and Campaigns DataTable bindings rely on, and the reason a host
     * subclass of a vendored class is safe to name in a provider that always runs.
     *
     * @var array<class-string,class-string>
     */
    public const OVERRIDES = [
        \Workdo\Campaigns\app\Services\CampaignsPaymentService::class
            => \App\Services\Modules\CampaignsBenefitCallbackOverride::class,
        \Workdo\Donation\app\Services\DonationPaymentService::class
            => \App\Services\Modules\DonationBenefitCallbackOverride::class,
        \Workdo\LotteryPrizes\app\Services\LotteryPaymentService::class
            => \App\Services\Modules\LotteryBenefitCallbackOverride::class,
    ];

    /**
     * The vendored files that name a callback bin, and the service each one declares.
     *
     * THE RATCHET'S DECLARED SET. `ModuleBenefitCallbackCensusTest` sweeps all 2,085 PHP files
     * under `packages/workdo/` with comments stripped and asserts the set of files naming a bin is
     * EXACTLY these three -- so a fourth copy arriving in a module update fails the build instead
     * of shipping, and a copy disappearing upstream prompts the override to be retired rather than
     * quietly kept.
     *
     * @var array<string,class-string>
     */
    public const VENDORED_SITES = [
        'packages/workdo/Campaigns/src/app/Services/CampaignsPaymentService.php'
            => \Workdo\Campaigns\app\Services\CampaignsPaymentService::class,
        'packages/workdo/Donation/src/app/Services/DonationPaymentService.php'
            => \Workdo\Donation\app\Services\DonationPaymentService::class,
        'packages/workdo/LotteryPrizes/src/app/Services/LotteryPaymentService.php'
            => \Workdo\LotteryPrizes\app\Services\LotteryPaymentService::class,
    ];

    /** The host override bound over a vendored payment service, or null when there is none. */
    public static function overrideFor(string $vendored): ?string
    {
        return self::OVERRIDES[ltrim($vendored, '\\')] ?? null;
    }
}
