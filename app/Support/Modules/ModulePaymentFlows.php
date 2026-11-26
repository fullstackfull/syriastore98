<?php

namespace App\Support\Modules;

use Illuminate\Routing\Route;

/**
 * WHICH VENDORED HANDLERS START A PAYMENT, AND WHICH FINISH ONE. Keyed on the controller action,
 * because the URL is the wrong thing to key on — and that is the finding.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY NOT `ModuleRouteManifest`'S `payment-callback` CATEGORY
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * The manifest classifies 87 `METHOD uri` keys as `payment-callback`, and the owner's rule for a
 * switched-off application was first written against that label. Read against the three modules
 * that own those keys (Donation 37, LotteryPrizes 33, Campaigns 17), the label does not follow the
 * code in either direction:
 *
 *   - ONE WRITER, TWO LABELS. Donation mounts `StoreDonationPayment` on 49 keys: 37 are
 *     `payment-callback` and 12 (`donation-telegram`, `donation-whatsapp`, six verbs each) are
 *     `storefront`. LotteryPrizes mounts `StoreLotteryPayment` on 43: 33 and 10. It is the same
 *     method. A boundary drawn on the label is walked around by changing the last word of the URL.
 *   - ONE URL, TWO LABELS. `GET {storeSlug?}/ticket-telegram` is `payment-callback` and the five
 *     other verbs of that same `Route::any` are `storefront`; nothing in the module issues a GET.
 *   - FIVE OF THE 17 CAMPAIGNS KEYS START A PAYMENT RATHER THAN FINISH ONE — a card form, a
 *     gateway charge, an auto-submitting redirect. Treating them as returns to be honoured would
 *     be honouring an initiation.
 *   - THE PLACES A PAYMENT IS STARTED ARE NOT IN THE CATEGORY AT ALL. Donation's and Lottery's
 *     initiation routes are `storefront`; Campaigns' is `ADMINISTRATIVE`.
 *
 * The manifest is not wrong — it was built to answer "may this be reached without signing in",
 * and it answers that. It was never asked what a route does to a payment.
 *
 * So the rule is applied to what the owner stated it about, which is the payment: an action that
 * STARTS one is refused for a disabled application, and an action that FINISHES one is honoured
 * only for an initiation the host recorded. `ModulePaymentFlowsTest` holds the two readings
 * together: every one of the manifest's 87 keys must resolve to an action classified here, so a
 * module update that adds a payment route fails the build instead of quietly bypassing the rule.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS MEANS FOR THE `storefront` CATEGORY, WHICH IS STILL THE OWNER'S
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Whether switching an application off should take its shopper-facing pages down is an open owner
 * decision and nothing here answers it. Of the manifest's 100 `storefront` keys, 24 are covered by
 * this class — the two initiation routes and the 22 keys that reach a completion writer — because
 * they are the payment itself and the rule for the payment is already given. The other 76 are
 * untouched: the donation form still renders, the lottery product page still renders, and a
 * shopper who submits either is told the service is not available.
 *
 * THE ROLES.
 *
 *   `START`     takes the payer's form and leaves for a gateway. The host records the initiation.
 *   `STEP`      a later step of starting — a card form, a charge, a redirect to the gateway. No
 *               payment has completed when it runs, so refusing it loses nothing.
 *   `RELAY`     on the way back, before the writer. Must find an open initiation; spends nothing,
 *               so the writer behind it still can.
 *   `COMPLETE`  the writer. Spends the initiation, once.
 */
final class ModulePaymentFlows
{
    public const START = 'start';
    public const STEP = 'step';
    public const RELAY = 'relay';
    public const COMPLETE = 'complete';

    /**
     * `Controller@method` => role, for every vendored handler on a payment path.
     *
     * @var array<string,string>
     */
    private const ROLES = [
        // Donation — a shopper pays the store.
        'Workdo\Donation\app\Http\Controllers\CustomerDonationController@store' => self::START,
        'Workdo\Donation\app\Http\Controllers\CustomerDonationController@StoreDonationPayment' => self::COMPLETE,

        // LotteryPrizes — a shopper buys a ticket from the store.
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@lottery_form_data' => self::START,
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@StoreLotteryPayment' => self::COMPLETE,

        // Campaigns — a merchant pays the PLATFORM to promote a product.
        'Workdo\Campaigns\app\Http\Controllers\CampaignsController@store' => self::START,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@create' => self::STEP,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@storePayWithDPO' => self::STEP,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@CampaignPayWithPowertranz' => self::STEP,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@pay' => self::STEP,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@payBraintree' => self::STEP,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@campaignsGetPowertranzStatus' => self::RELAY,
        'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@storeGetDPOStatus' => self::RELAY,
        'Workdo\Campaigns\app\Http\Controllers\CampaignsController@paymentSuccess' => self::COMPLETE,
    ];

    /**
     * The request field each module's START handler reads the chosen method from. Recorded as a
     * label on the initiation, for a person; it is caller-supplied and decides nothing.
     *
     * @var array<string,string>
     */
    private const METHOD_FIELD = [
        'Donation' => 'donationpayment_method',
        'LotteryPrizes' => 'payment_methods',
        'Campaigns' => 'payment_type',
    ];

    /**
     * MODULES WHOSE COMPLETION WRITER IS BEHIND THE MODULE'S OWN ENTITLEMENT GATE, so a switched-off
     * application cannot complete a payment at all — whatever this boundary does.
     *
     * FOUND BY INDEPENDENT REVIEW, AND IT INVALIDATED THE FIRST VERSION OF THE BOUNDARY. Campaigns
     * declares its writer inside `Route::group(['middleware' => 'PlanModuleCheck:Campaigns'])`
     * (`packages/workdo/Campaigns/src/routes/web.php:22`, `:33`), and ROUTE-LEVEL MIDDLEWARE RUNS
     * AFTER THE GROUP'S: `DisabledModuleRouteGuard` is appended to `web` and neither it nor
     * `PlanModuleCheck` is in Laravel's priority list, so the guard decided first and
     * `PlanModuleCheck` refused the request afterwards. The boundary therefore marked the
     * initiation `completed` for a request that never reached the writer — a record that said a
     * payment had been honoured when it had not, which is worse than refusing it.
     *
     * AND THE POWERTRANZ RELAY IS THE SHARP EDGE. `campaignsGetPowertranzStatus` sits OUTSIDE that
     * group and is the step that CAPTURES (`CampaignPaymentController:461-469` posts the SpiToken to
     * `/api/spi/Payment`) before redirecting into the writer. Letting it through on an open
     * initiation meant taking the money and then being refused at a door this application controls:
     * a captured payment with no campaign, created by the boundary's own "continuity".
     *
     * So for a module listed here, a disabled application refuses the whole return path and records
     * the refusal, and the initiation is left OPEN — if the operator switches the application back
     * on, the payer can still complete. Nothing is captured in the meantime.
     *
     * `tests/Unit/Modules/ModulePaymentFlowsTest` asserts this flag against the vendored route
     * files in BOTH directions, so a module update that moves the writer out of that group, or
     * moves another module's writer into one, fails the build rather than silently changing what
     * the boundary promises.
     *
     * @var array<string,true>
     */
    private const COMPLETION_BEHIND_THE_MODULES_OWN_GATE = [
        'Campaigns' => true,
    ];

    public static function completionIsEntitlementGated(string $module): bool
    {
        return isset(self::COMPLETION_BEHIND_THE_MODULES_OWN_GATE[$module]);
    }

    /** The role of the action a route points at, or null when it is not on a payment path. */
    public static function roleOf(Route $route): ?string
    {
        return self::roleOfAction((string) $route->getActionName());
    }

    public static function roleOfAction(string $action): ?string
    {
        return self::ROLES[ltrim($action, '\\')] ?? null;
    }

    /**
     * The session key each module's OWN service writes when a payment is initiated — the vendored
     * flow state, read by this host and never written by it.
     *
     * Measured in the three services: `DonationPaymentService` puts `donation_request_data` in
     * every one of its 35 branches, `LotteryPaymentService` `lottery_request_data` in its 35, and
     * `CampaignsPaymentService` `campaigns_request_data` in all of its. The handlers then test it
     * with `== null`, which is the test `DisabledModuleRouteGuard` copies.
     *
     * @var array<string,string>
     */
    private const VENDORED_FLOW_KEY = [
        'Donation' => 'donation_request_data',
        'LotteryPrizes' => 'lottery_request_data',
        'Campaigns' => 'campaigns_request_data',
    ];

    public static function vendoredFlowKey(string $module): ?string
    {
        return self::VENDORED_FLOW_KEY[$module] ?? null;
    }

    public static function methodField(string $module): ?string
    {
        return self::METHOD_FIELD[$module] ?? null;
    }

    /** @return array<string,string> `Controller@method` => role */
    public static function roles(): array
    {
        return self::ROLES;
    }

    /** @return list<string> the modules that take payments */
    public static function modules(): array
    {
        return array_keys(self::METHOD_FIELD);
    }
}
