<?php

namespace Tests\Feature\Modules;

use App\Http\Middleware\DisabledModuleRouteGuard;
use App\Models\AddOnManager;
use App\Services\ModulePaymentLedger;
use App\Support\Modules\ModulePaymentFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * #204 / PHASE 06 ITEM 2(B) — A SWITCHED-OFF APPLICATION AND THE PAYMENTS THAT WERE ALREADY UNDER WAY.
 *
 * The owner's rule: *"Keep a safe, authenticated and idempotent reconciliation mechanism for
 * previously initiated payments; do not use it to permit new transactions through disabled
 * applications."* Until this change the guard passed every anonymous module route untouched, so a
 * disabled Donation, LotteryPrizes or Campaigns module went on taking and recording payments.
 *
 * WHAT IS DRIVEN, AND WHY IT IS THE REAL ACTION NAMES ON SYNTHETIC ROUTES. No WorkDo module loads
 * in a test run (CLAUDE.md), and loading one in-process pollutes the Composer ClassLoader for every
 * later test (`route-census.php`'s docblock has the measurement). The guard decides on a route's
 * ACTION NAME, so each route here carries the vendored handler's real `Controller@method` with the
 * real URI shape, and the "handler" is a closure that records whether it was reached. What that
 * proves is the guard's decision — the part that can be wrong without anybody noticing.
 * `ModulePaymentFlowsTest` ties those action names to the vendored route files and to the manifest.
 *
 * EVERY REFUSAL HERE HAS ITS CONTROL IN THE SAME TEST: the identical request, with the one thing
 * that should make the difference changed, reaching the handler. A refusal alone is also what a
 * broken fixture looks like.
 */
class DisabledModulePaymentBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const DONATION_START = 'Workdo\Donation\app\Http\Controllers\CustomerDonationController@store';
    private const DONATION_COMPLETE = 'Workdo\Donation\app\Http\Controllers\CustomerDonationController@StoreDonationPayment';
    private const LOTTERY_COMPLETE = 'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@StoreLotteryPayment';
    private const CAMPAIGN_START = 'Workdo\Campaigns\app\Http\Controllers\CampaignsController@store';
    private const CAMPAIGN_STEP = 'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@storePayWithDPO';
    private const CAMPAIGN_RELAY = 'Workdo\Campaigns\app\Http\Controllers\CampaignPaymentController@storeGetDPOStatus';
    private const CAMPAIGN_COMPLETE = 'Workdo\Campaigns\app\Http\Controllers\CampaignsController@paymentSuccess';

    /** One browser session, shared by the requests of a test the way a payer's cookie shares it. */
    private SessionStore $browser;

    private int $reached = 0;

    private int $storeId;

    private int $otherStoreId;

    /**
     * TWO REAL STORES, WITH THE SLUGS THE REQUESTS BELOW USE.
     *
     * The first version of this file sent requests to `/my-store/...` with no such row, so
     * `storeIdOf()` resolved NULL for every one of them -- and the cross-store check added after
     * independent review could never fire, in either direction. A fixture whose slug resolves to
     * nothing makes every store-shaped assertion in the file vacuous.
     */
    protected function setUp(): void
    {
        parent::setUp();

        [$merchant] = $this->makeTenant();
        $this->storeId = (int) $this->makeStore($merchant, ['slug' => 'my-store'])->id;
        $this->otherStoreId = (int) $this->makeStore($merchant, ['slug' => 'other-store'])->id;

        $this->browser = $this->newBrowser();
    }

    private function newBrowser(): SessionStore
    {
        $session = new SessionStore('test-' . uniqid(), new ArraySessionHandler(120));
        $session->start();

        return $session;
    }

    private function enable(string $module): void
    {
        AddOnManager::updateOrCreate(['module' => $module], ['name' => $module, 'is_enable' => 1]);
    }

    private function disable(string $module): void
    {
        AddOnManager::updateOrCreate(['module' => $module], ['name' => $module, 'is_enable' => 0]);
    }

    /**
     * Drive the guard for one request and report the response. `$handler` stands in for the
     * vendored action; by default it counts the call and answers 200.
     */
    private function send(
        string $verb,
        string $uri,
        string $path,
        string $action,
        array $input = [],
        ?SessionStore $session = null,
        ?\Closure $handler = null,
        bool $wantsJson = false
    ): \Symfony\Component\HttpFoundation\Response {
        $route = new Route([$verb], $uri, ['controller' => $action, 'middleware' => ['web']]);

        $request = Request::create($path, $verb, $input);
        $request->setLaravelSession($session ?? $this->browser);

        if ($wantsJson) {
            $request->headers->set('Accept', 'application/json');
        }

        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        return (new DisabledModuleRouteGuard())->handle($request, $handler ?? function () {
            $this->reached++;

            return new \Illuminate\Http\Response('REACHED THE HANDLER', 200);
        });
    }

    private function startDonation(array $input = ['donationpayment_method' => 'Stripe'], ?SessionStore $session = null, ?\Closure $handler = null)
    {
        return $this->send('POST', '{storeSlug}/customer-donation-store', '/my-store/customer-donation-store', self::DONATION_START, $input, $session, $handler);
    }

    private function returnToDonation(array $input = [], ?SessionStore $session = null, string $uri = '{storeSlug}/store-donation-payment', string $path = '/my-store/store-donation-payment', bool $wantsJson = false, ?\Closure $handler = null)
    {
        return $this->send('GET', $uri, $path, self::DONATION_COMPLETE, $input, $session, $handler, $wantsJson);
    }

    private function rows(string $status): \Illuminate\Support\Collection
    {
        return DB::table(ModulePaymentLedger::TABLE)->where('status', $status)->get();
    }

    // ═══════════════════════════════════════════════════════ ENABLED: A RECORD, AND NO REFUSAL

    public function test_starting_a_payment_through_an_enabled_module_is_recorded_and_never_refused(): void
    {
        $this->enable('Donation');

        $response = $this->startDonation();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->reached);

        $initiated = $this->rows(ModulePaymentLedger::INITIATED);
        $this->assertCount(1, $initiated, 'a payment was started and the host kept no record of it');
        $this->assertSame('Donation', $initiated->first()->module);
        $this->assertSame('POST {storeSlug}/customer-donation-store', $initiated->first()->route);
        $this->assertSame('Stripe', $initiated->first()->gateway);
        $this->assertSame($this->storeId, (int) $initiated->first()->store_id,
            'the store the slug resolved to was not recorded, so nothing can require the payment to '
            . 'be completed where it started');
        $this->assertSame(
            $initiated->first()->reference,
            $this->browser->get(ModulePaymentLedger::sessionKey('Donation')),
            'the reference must be held by the payer\'s session, or their return cannot show it'
        );
    }

    /** The handler rejected the form, so nothing left for a gateway and nothing was initiated. */
    public function test_a_form_the_module_itself_rejects_is_not_an_initiation(): void
    {
        $this->enable('Donation');

        $this->startDonation([], null, function () {
            $this->browser->flash('error', 'Store not found.');

            return redirect()->to('/back');
        });

        $this->assertCount(0, $this->rows(ModulePaymentLedger::INITIATED));
        $this->assertCount(1, $this->rows(ModulePaymentLedger::ABANDONED));
        $this->assertNull($this->browser->get(ModulePaymentLedger::sessionKey('Donation')));
    }

    public function test_a_second_start_in_one_session_closes_the_first(): void
    {
        $this->enable('Donation');

        $this->startDonation();
        $first = $this->browser->get(ModulePaymentLedger::sessionKey('Donation'));
        $this->startDonation();
        $second = $this->browser->get(ModulePaymentLedger::sessionKey('Donation'));

        $this->assertNotSame($first, $second);
        $this->assertSame(ModulePaymentLedger::SUPERSEDED, DB::table(ModulePaymentLedger::TABLE)->where('reference', $first)->value('status'));
        $this->assertSame(ModulePaymentLedger::INITIATED, DB::table(ModulePaymentLedger::TABLE)->where('reference', $second)->value('status'));
    }

    /**
     * While the module is enabled a return is never refused — with a record or without one. The
     * record is only kept true, so a return already honoured is not honoured again after a
     * switch-off.
     */
    public function test_a_return_to_an_enabled_module_is_never_refused_and_spends_its_initiation(): void
    {
        $this->enable('Donation');

        $this->startDonation();
        $this->assertSame(200, $this->returnToDonation()->getStatusCode());
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));

        /* No initiation at all, a different browser: still not refused while enabled. */
        $this->assertSame(200, $this->returnToDonation([], $this->newBrowser())->getStatusCode());
        $this->assertCount(0, $this->rows(ModulePaymentLedger::REFUSED));
        $this->assertSame(3, $this->reached);
    }

    // ═══════════════════════════════════════════════════ DISABLED: NO NEW TRANSACTION

    public function test_a_disabled_module_cannot_start_a_payment(): void
    {
        $this->disable('Donation');

        $response = $this->startDonation();

        $this->assertSame(0, $this->reached, 'a switched-off application took a new payment form');
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, DB::table(ModulePaymentLedger::TABLE)->count(), 'a refused start must not leave an initiation behind');
        $this->assertNull($this->browser->get(ModulePaymentLedger::sessionKey('Donation')));

        /* CONTROL: the same request, the module enabled. */
        $this->enable('Donation');
        $this->startDonation();
        $this->assertSame(1, $this->reached);
    }

    /** A later step of STARTING — here the one that charges a card — is refused too. */
    public function test_a_disabled_module_cannot_take_the_next_step_of_starting_either(): void
    {
        $this->enable('Campaigns');
        $this->send('POST', 'campaigns/campaigns', '/campaigns/campaigns', self::CAMPAIGN_START, ['payment_type' => 'Dpopay']);
        $this->assertSame(1, $this->reached, 'premise: the first step was taken while enabled');

        $this->disable('Campaigns');

        $this->send('GET', 'campaigns/campaigns-pay-with/DPO', '/campaigns/campaigns-pay-with/DPO', self::CAMPAIGN_STEP);

        $this->assertSame(1, $this->reached,
            'a switched-off application charged a card: holding an initiation must not open the charging step');
        $this->assertSame(ModulePaymentLedger::INITIATED, DB::table(ModulePaymentLedger::TABLE)->value('status'),
            'a refused step must not spend the initiation');
    }

    // ══════════════════════════════════ DISABLED: A PAYMENT ALREADY UNDER WAY, ONCE

    /** THE RULE'S FIRST HALF. Started while enabled, switched off, the payer comes back: honoured. */
    public function test_a_payment_started_before_the_switch_off_is_honoured_when_the_payer_returns(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $this->disable('Donation');

        $response = $this->returnToDonation();

        $this->assertSame(200, $response->getStatusCode(),
            'a payment that was already initiated was turned away because the application was switched off afterwards');
        $this->assertSame(2, $this->reached);
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));
        $this->assertCount(0, $this->rows(ModulePaymentLedger::REFUSED));
    }

    /**
     * IDEMPOTENT. The same return, again — a refresh, a replayed URL, a second tab. The vendored
     * handler writes a new row on every call it is allowed to make; this allows one.
     */
    public function test_the_same_return_is_honoured_once_and_then_refused_and_written_down(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $this->disable('Donation');

        $this->returnToDonation(['flag' => 'success']);
        $this->assertSame(2, $this->reached, 'premise: the first return was honoured');

        $second = $this->returnToDonation(['flag' => 'success']);

        $this->assertSame(2, $this->reached, 'one initiation was honoured twice');
        $this->assertSame(302, $second->getStatusCode());

        $refused = $this->rows(ModulePaymentLedger::REFUSED);
        $this->assertCount(1, $refused, 'a refused return was dropped instead of recorded');
        $this->assertSame('Donation', $refused->first()->module);
        $this->assertSame('GET {storeSlug}/store-donation-payment', $refused->first()->route);
        $this->assertStringContainsString('not open', (string) $refused->first()->reason);
    }

    /** THE RULE'S SECOND HALF. A return with nothing behind it is a new transaction by another name. */
    public function test_a_return_with_no_initiation_behind_it_is_refused_and_written_down(): void
    {
        $this->disable('Donation');

        $response = $this->returnToDonation(['name' => 'A Donor', 'amount' => '500', 'donationpayment_method' => 'Telegram']);

        $this->assertSame(0, $this->reached,
            'a switched-off application recorded a payment for a request that never started one');
        $this->assertSame(302, $response->getStatusCode());

        $refused = $this->rows(ModulePaymentLedger::REFUSED);
        $this->assertCount(1, $refused);
        $this->assertStringContainsString('no payment initiation', (string) $refused->first()->reason);
        $this->assertSame(1, (int) $refused->first()->attempts);

        /* The same request again is the same refusal, counted — not a second row. */
        $this->returnToDonation(['name' => 'A Donor', 'amount' => '500', 'donationpayment_method' => 'Telegram']);
        $this->assertCount(1, $this->rows(ModulePaymentLedger::REFUSED));
        $this->assertSame(2, (int) DB::table(ModulePaymentLedger::TABLE)->value('attempts'));
    }

    /**
     * THE SAME WRITER UNDER ITS OTHER LABEL. `donation-telegram` is `storefront` in the manifest and
     * `store-donation-payment` is `payment-callback`; both are `StoreDonationPayment`. A boundary
     * drawn on the label is walked around by changing the last word of the URL.
     */
    public function test_the_writer_is_gated_on_every_url_it_is_mounted_on(): void
    {
        $this->disable('Donation');

        $this->send('POST', '{storeSlug?}/donation-telegram', '/my-store/donation-telegram', self::DONATION_COMPLETE, ['amount' => '500']);
        $this->send('POST', '{storeSlug?}/donation-whatsapp', '/my-store/donation-whatsapp', self::DONATION_COMPLETE, ['amount' => '500']);

        $this->assertSame(0, $this->reached, 'the payment writer was reached through its storefront-labelled URL');
        $this->assertCount(2, $this->rows(ModulePaymentLedger::REFUSED));
    }

    /** A reference is for ONE module. Lottery's cannot open Donation's writer. */
    public function test_an_initiation_cannot_be_spent_on_another_modules_writer(): void
    {
        $this->enable('LotteryPrizes');
        $this->send('POST', '{storeSlug}/lottery-form-data', '/my-store/lottery-form-data',
            'Workdo\LotteryPrizes\app\Http\Controllers\LotteryPrizesController@lottery_form_data', ['payment_methods' => 'Stripe']);
        $lottery = $this->browser->get(ModulePaymentLedger::sessionKey('LotteryPrizes'));
        $this->assertIsString($lottery, 'premise');

        /* Plant the lottery reference where Donation's would be. */
        $this->browser->put(ModulePaymentLedger::sessionKey('Donation'), $lottery);
        $this->disable('Donation');
        $this->disable('LotteryPrizes');

        $this->returnToDonation();
        $this->assertSame(1, $this->reached, 'another module\'s initiation opened this module\'s writer');

        /* CONTROL: the lottery writer, with its own reference, is honoured. */
        $this->send('GET', '{storeSlug}/store-lottery-payment', '/my-store/store-lottery-payment', self::LOTTERY_COMPLETE);
        $this->assertSame(2, $this->reached);
    }

    /** Another browser holds nothing, so it shows nothing — however much it knows about the payment. */
    public function test_a_different_session_cannot_complete_somebody_elses_payment(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $this->disable('Donation');

        /* THE STRANGER SENDS THE REAL REFERENCE AS INPUT, which is what pins "never read from the
           request" -- independent review pointed out that sending nothing proves only that an empty
           request fails. */
        $reference = $this->browser->get(ModulePaymentLedger::sessionKey('Donation'));
        $this->returnToDonation(['reference' => $reference, 'ss_module_payment' => $reference], $this->newBrowser());

        $this->assertSame(1, $this->reached);
        $this->assertCount(1, $this->rows(ModulePaymentLedger::INITIATED), 'a stranger\'s request spent the payer\'s initiation');

        /* CONTROL: the payer's own browser still can. */
        $this->returnToDonation();
        $this->assertSame(2, $this->reached);
    }

    /**
     * A RELAY LOOKS AND DOES NOT SPEND — asserted on the one module where a relay can be honoured.
     *
     * Campaigns cannot (see the case below), so this drives the property on Donation's writer
     * instead: a `COMPLETE` that is refused downstream must leave the initiation for the next
     * attempt rather than keep it spent.
     */
    public function test_a_claim_that_was_refused_downstream_is_put_back(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $this->disable('Donation');

        /* The vendored handler's own refusal shape: redirect, with its error flashed by THIS
           request. Nothing is written, so nothing was honoured. */
        $this->returnToDonation([], null, '{storeSlug}/store-donation-payment', '/my-store/store-donation-payment', false, function () {
            $this->browser->flash('error', 'Store not found.');

            return redirect()->to('/back');
        });

        $this->assertCount(1, $this->rows(ModulePaymentLedger::INITIATED),
            'the initiation stayed spent for a return nothing was recorded for, so the payer can never complete it');
        $this->assertCount(0, $this->rows(ModulePaymentLedger::COMPLETED));
        $this->assertCount(1, $this->rows(ModulePaymentLedger::REFUSED),
            'a return that was allowed and then refused was not written down');

        /* CONTROL: the next attempt, which the handler does not refuse, IS honoured — once. */
        $this->returnToDonation();
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));
        $this->assertCount(0, $this->rows(ModulePaymentLedger::INITIATED));
    }

    /**
     * CAMPAIGNS CANNOT COMPLETE A PAYMENT WHILE IT IS SWITCHED OFF, SO ITS RETURN PATH IS REFUSED
     * AND NOT "HONOURED".
     *
     * FOUND BY INDEPENDENT REVIEW, and it invalidated the first version of this test, which
     * asserted that the Campaigns relay and writer were honoured. They are not: the writer is
     * declared inside `PlanModuleCheck:Campaigns`
     * (`packages/workdo/Campaigns/src/routes/web.php:22`, `:33`) and ROUTE MIDDLEWARE RUNS AFTER THE
     * GROUP'S, so this guard decided first and `PlanModuleCheck` refused the request afterwards —
     * leaving a row that said `completed` for a payment that was never written.
     *
     * **And the relay is the step that CAPTURES** (`CampaignPaymentController:461-469` posts the
     * SpiToken to PowerTranz `/api/spi/Payment`), so honouring it meant taking the money and then
     * being refused at a door this application controls.
     *
     * So both are refused, the refusal is recorded, and **the initiation is left OPEN** — switch the
     * application back on and the payer can still complete.
     */
    public function test_a_disabled_campaigns_return_is_refused_and_its_initiation_is_left_open(): void
    {
        $this->assertTrue(ModulePaymentFlows::completionIsEntitlementGated('Campaigns'), 'premise');

        $this->enable('Campaigns');
        $this->send('POST', 'campaigns/campaigns', '/campaigns/campaigns', self::CAMPAIGN_START, ['payment_type' => 'Powertranz']);
        $this->assertSame(1, $this->reached, 'premise: the payment was started while enabled');
        $this->disable('Campaigns');

        $this->send('GET', 'campaigns/campaigns-Powertranz', '/campaigns/campaigns-Powertranz', self::CAMPAIGN_RELAY);
        $this->assertSame(1, $this->reached,
            'the capturing relay was reached for a campaign the writer behind it cannot record');

        $this->send('GET', 'campaigns/campaigns-card-payment', '/campaigns/campaigns-card-payment', self::CAMPAIGN_COMPLETE);
        $this->assertSame(1, $this->reached);

        $this->assertCount(1, $this->rows(ModulePaymentLedger::INITIATED),
            'the initiation was spent for a return that could not be honoured');
        $this->assertCount(0, $this->rows(ModulePaymentLedger::COMPLETED),
            'the record says a Campaigns payment was honoured while switched off, which cannot happen');
        $this->assertCount(2, $this->rows(ModulePaymentLedger::REFUSED));
        $this->assertStringContainsString('entitlement gate', (string) $this->rows(ModulePaymentLedger::REFUSED)->first()->reason);

        /* CONTROL: switched back on, the same session completes. */
        $this->enable('Campaigns');
        $this->send('GET', 'campaigns/campaigns-card-payment', '/campaigns/campaigns-card-payment', self::CAMPAIGN_COMPLETE);
        $this->assertSame(2, $this->reached, 'the payer could not complete even after the application was switched back on');
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));
    }

    /**
     * A PAYMENT STARTED IN ONE STORE CANNOT BE COMPLETED IN ANOTHER.
     *
     * Independent review's finding: the reference says whose payment this is and said nothing about
     * where, so one session could start a donation at store A and, after the switch-off, complete it
     * at store B — and the vendored handler writes the row into whichever store the slug names.
     */
    public function test_a_payment_cannot_be_completed_in_a_different_store(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $this->disable('Donation');

        $this->returnToDonation([], null, '{storeSlug}/store-donation-payment', '/other-store/store-donation-payment');

        $this->assertNotSame($this->storeId, $this->otherStoreId, 'premise: two distinct stores');
        $this->assertSame(1, $this->reached, 'a payment started in one store was completed in another');
        $this->assertCount(1, $this->rows(ModulePaymentLedger::INITIATED));
        $this->assertStringContainsString('different store', (string) $this->rows(ModulePaymentLedger::REFUSED)->first()->reason);

        /* CONTROL: the store it started in. */
        $this->returnToDonation();
        $this->assertSame(2, $this->reached);
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));
    }

    // ═══════════════════════════════════════════════════════════ WHAT IT MUST NOT DO

    /**
     * NO RECORD INSTALLED: A RETURN PASSES ONLY FOR A SESSION HOLDING THE MODULE'S OWN PAYMENT
     * STATE, and a START is still refused.
     *
     * THE FIRST VERSION OF THIS CASE ASSERTED THAT ANY RETURN PASSED, and independent review showed
     * what that costs: `CustomerDonationController::StoreDonationPayment` builds its record from
     * the REQUEST when the session is empty, so one anonymous GET to a disabled module's return URL
     * wrote a `customer_donations` row — a brand new transaction through a switched-off
     * application, which is exactly what the owner's sentence forbids, in the one environment that
     * has no table.
     *
     * The vendored flow key needs no table. A disabled module cannot initiate a payment, so nothing
     * can newly obtain it; an in-flight payer has it. Weaker than the reference (not single-use, not
     * store-bound) and the strongest thing available without the record.
     */
    public function test_without_the_record_only_a_session_holding_the_modules_own_state_may_return(): void
    {
        Schema::drop(ModulePaymentLedger::TABLE);
        $this->disable('Donation');

        /* An anonymous request with everything the vendored handler needs to write a row. */
        $this->returnToDonation([
            'name' => 'A Donor', 'email' => 'a@example.test', 'number' => '1', 'address' => 'x',
            'country_id' => '1', 'state_id' => '1', 'city_id' => '1', 'donation' => '1',
            'amount' => '500', 'donationpayment_method' => 'Telegram',
        ], $this->newBrowser());

        $this->assertSame(0, $this->reached,
            'a switched-off application recorded a brand new donation from one anonymous request');

        /* CONTROL: the payer whose session really is mid-payment. */
        $this->browser->put(ModulePaymentFlows::vendoredFlowKey('Donation'), ['amount' => '500']);
        $this->assertSame(200, $this->returnToDonation()->getStatusCode());
        $this->assertSame(1, $this->reached);

        /* And a START is refused either way: that needs no record at all. */
        $this->startDonation();
        $this->assertSame(1, $this->reached, 'a switched-off application started a payment because a table was missing');
    }

    /** What a refusal keeps is a hash. One of these gateways puts a card number in a return URL. */
    public function test_a_refusal_stores_a_fingerprint_and_nothing_that_was_sent(): void
    {
        $this->disable('Campaigns');

        $this->send('GET', 'campaigns/campaigns-get-DPO-status', '/campaigns/campaigns-get-DPO-status', self::CAMPAIGN_RELAY, [
            'data' => '{"carddata":{"number":"4111111111111111","cvv":"123"}}',
        ]);

        $row = (array) DB::table(ModulePaymentLedger::TABLE)->first();
        $this->assertSame(ModulePaymentLedger::REFUSED, $row['status'], 'premise');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['payload_hash']);

        foreach ($row as $column => $value) {
            $this->assertStringNotContainsString('4111111111111111', (string) $value, "the card number was stored in `{$column}`");
            $this->assertStringNotContainsString('carddata', (string) $value, "the request payload was stored in `{$column}`");
        }
    }

    /**
     * A REFERENCE THE DATABASE WOULD CASE-FOLD IS NOT A REFERENCE.
     *
     * FOUND BY `verify-module-payment-boundary-on-mysql.php`, NOT BY THIS SUITE, and that is the
     * point of the case. MySQL's default collation is case-INSENSITIVE and SQLite's is BINARY, so
     * on the engine production runs the completion claim matched a reference differing only in
     * letter case -- the harness spent one that way. SQLite cannot show it at all, so what this
     * case pins is the engine-independent control: a non-canonical reference is refused before any
     * query. `ModulePaymentLedger::isWellFormedReference()` has the full note.
     */
    public function test_only_a_canonical_reference_can_spend_an_initiation(): void
    {
        $this->enable('Donation');
        $this->startDonation();
        $reference = $this->browser->get(ModulePaymentLedger::sessionKey('Donation'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $reference, 'premise');
        $this->disable('Donation');

        /* The same bytes, upper-cased -- which a case-folding collation would match. */
        $this->browser->put(ModulePaymentLedger::sessionKey('Donation'), strtoupper((string) $reference));
        $this->returnToDonation();

        $this->assertSame(1, $this->reached, 'a non-canonical reference spent an initiation');
        $this->assertCount(1, $this->rows(ModulePaymentLedger::INITIATED));

        /*
         * AND THE REASON IS WHAT MAKES THIS CASE BITE ON SQLITE. Independent review found that the
         * two assertions above hold with `isWellFormedReference()` DELETED, because SQLite compares
         * binary and the upper-cased reference simply matches no row either way. What differs is
         * which refusal was recorded: with the check, the session is treated as holding NO
         * reference; without it, as holding one that is not open. On MySQL, where the collation
         * folds case, deleting the check spends the initiation outright --
         * `verify-module-payment-boundary-on-mysql.php` measures that half.
         */
        $this->assertStringContainsString('no payment initiation', (string) $this->rows(ModulePaymentLedger::REFUSED)->first()->reason,
            'a non-canonical reference was treated as a reference that exists, which is the reading '
            . 'that spends an initiation on a case-folding collation');
        $this->assertFalse(ModulePaymentLedger::isWellFormedReference(strtoupper((string) $reference)));
        $this->assertTrue(ModulePaymentLedger::isWellFormedReference((string) $reference));

        /* CONTROL: the canonical reference spends it. */
        $this->browser->put(ModulePaymentLedger::sessionKey('Donation'), $reference);
        $this->returnToDonation();
        $this->assertSame(2, $this->reached);
        $this->assertCount(1, $this->rows(ModulePaymentLedger::COMPLETED));
    }

    /** Anybody can reach a disabled module's return URL, so what they can make it store is bounded. */
    public function test_the_refusals_one_module_can_accumulate_in_an_hour_are_bounded(): void
    {
        $this->disable('Donation');

        for ($i = 0; $i < ModulePaymentLedger::REFUSALS_PER_MODULE_PER_HOUR + 5; $i++) {
            $this->returnToDonation(['probe' => (string) $i], $this->newBrowser());
        }

        $this->assertSame(ModulePaymentLedger::REFUSALS_PER_MODULE_PER_HOUR, $this->rows(ModulePaymentLedger::REFUSED)->count());
        $this->assertSame(0, $this->reached);
    }

    /** JSON for a caller that asked for JSON; a 403; and never the 401 that logs the Flutter app out. */
    public function test_the_refusal_is_a_403_for_a_json_caller_and_never_a_401(): void
    {
        $this->disable('Donation');

        $response = $this->returnToDonation([], null, '{storeSlug}/store-donation-payment', '/my-store/store-donation-payment', true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotSame(401, $response->getStatusCode());
        $this->assertFalse(json_decode((string) $response->getContent(), true)['is_success']);
    }

    /** A shopper is sent back to the store they were in, not to a merchant login page. */
    public function test_a_shopper_is_sent_back_to_the_store_they_were_in(): void
    {
        $this->disable('Donation');

        $response = $this->returnToDonation();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('landing_page', 'my-store'), $response->headers->get('Location'));
    }

    /**
     * EVERYTHING THAT IS NOT A PAYMENT IS LEFT AS IT WAS. The donation FORM is a storefront page;
     * whether switching the application off takes it down is the owner's decision and is still
     * open. So is a webhook's continuity, which is settled the other way.
     */
    public function test_a_disabled_modules_other_anonymous_routes_are_untouched(): void
    {
        $this->disable('Donation');
        $this->disable('LynomiaChat');

        $this->send('GET', '{storeSlug}/customer-donation-create', '/my-store/customer-donation-create',
            'Workdo\Donation\app\Http\Controllers\CustomerDonationController@create');
        $this->send('POST', 'lynomia/events/{store}', '/lynomia/events/7',
            'Workdo\LynomiaChat\app\Http\Controllers\EventIngressController@store');

        $this->assertSame(2, $this->reached);
        $this->assertSame(0, DB::table(ModulePaymentLedger::TABLE)->count());
        $this->assertNull(ModulePaymentFlows::roleOfAction('Workdo\Donation\app\Http\Controllers\CustomerDonationController@create'), 'premise');
    }
}
