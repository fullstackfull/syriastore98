<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\User;
use App\Services\ModulePaymentLedger;
use App\Services\Payments\Reconciliation\CaptureAnswer;
use App\Services\Payments\Reconciliation\PlanPaymentReconciler;
use App\Services\Payments\Reconciliation\PlanPurchaseSettlement;
use App\Services\Payments\Reconciliation\ReconciliationOutcome;
use App\Services\PlanPurchaseIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE RECONCILIATION OF PREVIOUSLY INITIATED PLAN PAYMENTS (#204 / P5-FIN-4 / review finding F13).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT IS UNDER TEST
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `PlanPaymentReconciler` takes an intent the SERVER recorded before a buyer left for the provider,
 * asks that provider -- server to server -- what became of it, and either settles it exactly once
 * through the existing engine or writes down why it could not. Each requirement the owner listed
 * has a case here, and every case that asserts a REFUSAL carries its own control: the same fixture,
 * one value different, shown to settle. "Nothing was written" proves nothing by itself -- it is also
 * what a broken fixture produces.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE PROVIDER IS FAKED AT THE TRANSPORT AND NOWHERE ELSE
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `Http::fake()` stands in for the network. The status responses are signed with
 * `getChecksumFromArray()` -- the helper from the VENDORED Paytm SDK, loaded the same way
 * `PaytmTransactionVerifier` loads it -- so a response these cases call authentic is one Paytm's own
 * code calls authentic, and the real verifier runs against it. Nothing about the contract is
 * re-implemented here.
 *
 * AND THE CREDENTIALS ARE THE PLATFORM'S STORED SETTINGS, NOT A PRESET CONFIG. That is deliberate
 * and it is the point of `test_the_credentials_come_from_the_platforms_stored_settings`: a
 * reconciliation run has no session, and `services.paytm-wallet.*` is only ever populated from a
 * session. A fixture that set the config directly would test a state production cannot reach.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THESE CASES CANNOT ESTABLISH
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * No live Paytm transaction has been verified from this project -- no credentials, no route to
 * `securegw.paytm.in`. So nobody has seen a real captured payment come back `TXN_SUCCESS` over the
 * wire, and that is why the command writes nothing unless a person passes `--apply`.
 *
 * And SQLite cannot race, nor show what REPEATABLE READ does to a re-read. Both are in
 * `tests/Support/Security/bin/verify-plan-payment-reconciliation-on-mysql.php`, which drives THIS
 * service against a database built by the project's own migrations -- and which found a real defect
 * the cases below could not: the loser of a race was reported and audited as a mismatching AMOUNT,
 * because the plain re-read answered from the loser's own snapshot. On SQLite `lockForUpdate()`
 * compiles to nothing and the plain read is already current, so every case here passes with that
 * fix and without it.
 */
class PlanPaymentReconciliationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const KEY = 'test-merchant-key-0123456789';

    private const MID = 'TESTMID123';

    private User $purchaser;

    private Plan $plan;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * THE PRODUCTION SHAPE: the operator's stored settings, and NO preset
         * `services.paytm-wallet.*`. `getSuperAdminAllSetting()` reads
         * `created_by = <operator> AND store_id = <operator's current_store>`.
         */
        $this->operator = $this->makePlatformAdmin(['current_store' => 1]);
        $this->storePlatformSetting('paytm_merchant_key', self::KEY);
        $this->storePlatformSetting('paytm_merchant_id', self::MID);
        $this->storePlatformSetting('paytm_mode', 'local');

        $this->assertSame('', (string) config('services.paytm-wallet.merchant_key'),
            'premise: the merchant key must NOT be preset in config, or these cases test a state '
            . 'a session-less run can never be in');

        [$merchant] = $this->makeTenant();
        $this->purchaser = User::find($merchant->id);
        $this->plan = $this->makePlan();
    }

    /**
     * `Setting` is `Cachable` and this is a raw write, so the model cache is flushed with it --
     * CLAUDE.md's rule, met the usual way: the first version did not flush, and the case that
     * removes the key and puts it back went on reading the settings from before it was put back.
     */
    private function storePlatformSetting(string $name, string $value): void
    {
        DB::table('settings')->insert([
            'name' => $name, 'value' => $value,
            'created_by' => $this->operator->id, 'store_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->forgetPlatformSettings();
    }

    private function forgetPlatformSettings(): void
    {
        (new \App\Models\Setting)->flushCache();
        Cache::forget('super_admin_details');
    }

    /** `plans` declares more NOT NULL columns than the model's $fillable covers; see `PlanOrderRailTest`. */
    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . uniqid(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    /**
     * A server-minted intent, the way `PaytmPaymentController::planPayWithPaytm()` records one --
     * then aged past the grace period, because a run leaves a fresh intent to its own buyer.
     */
    private function intent(array $overrides = [], int $ageMinutes = 120): string
    {
        $reference = PlanPurchaseIntent::create(array_merge([
            'provider' => 'Paytm',
            'user_id' => $this->purchaser->id,
            'plan_id' => $this->plan->id,
            'coupon_id' => null,
            'coupon_code' => null,
            'expected_amount' => '100.00',
            'currency' => 'INR',
        ], $overrides));

        $this->assertIsString($reference, 'premise: the purchase intent was not recorded');

        DB::table('plan_purchase_intents')->where('reference', $reference)
            ->update(['created_at' => now()->subMinutes($ageMinutes)]);

        return $reference;
    }

    private function row(string $reference): object
    {
        return DB::table('plan_purchase_intents')->where('reference', $reference)->first();
    }

    /** A status response signed by Paytm's own helper. `TXNAMOUNT` defaults to the intent's 100.00. */
    private function paytmSays(string $reference, array $overrides = []): array
    {
        $body = array_merge([
            'MID' => self::MID,
            'ORDERID' => $reference,
            'TXNID' => 'TXN' . uniqid(),
            'TXNAMOUNT' => '100.00',
            'STATUS' => 'TXN_SUCCESS',
            'CURRENCY' => '',
            'RESPCODE' => '01',
            'RESPMSG' => 'Txn Success',
        ], $overrides);

        if (! function_exists('getChecksumFromArray')) {
            class_exists(\Anand\LaravelPaytmWallet\Providers\PaytmWalletProvider::class);
        }

        $this->assertTrue(function_exists('getChecksumFromArray'),
            'premise: the vendored Paytm checksum helper is not loadable, so nothing here signs '
            . 'with the official contract');

        $body['CHECKSUMHASH'] = getChecksumFromArray($body, self::KEY);

        return $body;
    }

    /** What the faked provider says NEXT. Swappable mid-test: see `providerAnswers()`. */
    private array $providerBody = [];

    private int $providerStatus = 200;

    private bool $providerIsFaked = false;

    /**
     * ONE FAKE, REGISTERED ONCE, READING A VALUE THAT CAN CHANGE.
     *
     * `Http::fake()` called a second time does NOT replace the first stub -- stubs are tried in
     * the order they were registered and the first match wins. The first version of this helper
     * registered a new stub per call, so every "and now the provider answers properly" control in
     * this file went on receiving the FIRST answer and four cases failed on their control rather
     * than on their subject. A closure over a property is the shape that can be re-pointed.
     */
    private function providerAnswers(array $body, int $status = 200): void
    {
        $this->providerBody = $body;
        $this->providerStatus = $status;

        if (! $this->providerIsFaked) {
            $this->providerIsFaked = true;
            Http::fake(fn () => Http::response($this->providerBody, $this->providerStatus));
        }
    }

    /** Nobody is expected to be asked anything; an answer, if one is sought, is a failure. */
    private function providerMustNotBeAsked(): void
    {
        $this->providerAnswers([], 500);
    }

    private function reconcile(string $reference, bool $apply): ReconciliationOutcome
    {
        return app(PlanPaymentReconciler::class)->reconcile($this->row($reference), $apply);
    }

    private function orders(): \Illuminate\Support\Collection
    {
        return DB::table('plan_orders')->get();
    }

    private function audits(string $action): int
    {
        return AuditLog::where('action', $action)->count();
    }

    // ═══════════════════════════════════════════════════════════════════ SETTLEMENT, ONCE

    public function test_a_pending_intent_the_provider_confirms_is_settled(): void
    {
        $reference = $this->intent();
        $says = $this->paytmSays($reference);
        $this->providerAnswers($says);

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::SETTLED, $outcome->kind, $outcome->reason);

        $intent = $this->row($reference);
        $this->assertSame(PlanPurchaseIntent::CONSUMED, $intent->status);
        $this->assertSame($says['TXNID'], $intent->provider_txn_id);

        $orders = $this->orders();
        $this->assertCount(1, $orders, 'a verified payment must leave exactly one plan order');
        $order = $orders->first();

        $this->assertSame((int) $order->id, (int) $intent->plan_order_id,
            'the order must be attached to the intent, or unreconciled() lists a completed purchase forever');
        $this->assertSame($reference, $order->order_id, 'the order is keyed on the intent reference, not the clock');
        $this->assertSame($says['TXNID'], $order->txn_id);
        $this->assertSame('Paytm', $order->payment_type);
        $this->assertSame('success', $order->payment_status);
        $this->assertSame((int) $this->plan->id, (int) $order->plan_id);
        $this->assertSame((int) $this->purchaser->id, (int) $order->user_id);
        $this->assertEqualsWithDelta(100.0, (float) $order->price, 0.0001);
        $this->assertSame('INR', $order->price_currency);

        $this->assertSame((int) $this->plan->id, (int) DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'),
            'the entitlement did not land, so a verified payment bought nothing');

        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_SETTLED));
        $audit = AuditLog::where('action', PlanPurchaseSettlement::AUDIT_SETTLED)->first();
        $this->assertSame(PlanPurchaseSettlement::AUDIT_SUBJECT, $audit->subject_type);
        $this->assertSame((int) $intent->id, (int) $audit->subject_id);
        $this->assertSame((int) $order->id, (int) $audit->new_values['plan_order_id']);
    }

    /** REPLAY. A second applying run finds the intent spent and does nothing at all. */
    public function test_a_second_run_settles_nothing_more_and_does_not_extend_the_plan(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind, 'premise');

        /* `assignPlan()` recomputes the expiry from NOW, so a second activation would be a free
           renewal. Push the stored expiry somewhere a recomputation could not land on. */
        DB::table('users')->where('id', $this->purchaser->id)->update(['plan_expire_date' => '2001-01-01']);
        (new User)->flushCache();

        $second = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::NOT_ELIGIBLE, $second->kind);
        $this->assertCount(1, $this->orders(), 'a replay wrote a second plan order');
        $this->assertSame('2001-01-01', DB::table('users')->where('id', $this->purchaser->id)->value('plan_expire_date'),
            'a replay re-ran the activation and moved the expiry');
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_SETTLED));
    }

    /**
     * CONCURRENCY, as far as one connection can show it: two settlements start from the SAME stale
     * `pending` snapshot. The conditional UPDATE lets one through.
     */
    public function test_two_settlements_from_one_stale_snapshot_cannot_both_win(): void
    {
        $reference = $this->intent();
        $says = $this->paytmSays($reference);
        $stale = $this->row($reference);
        $answer = CaptureAnswer::fromVerifierArray([
            'state' => CaptureAnswer::VERIFIED, 'amount' => '100.00', 'currency' => '',
            'txn_id' => $says['TXNID'], 'reason' => 'test',
        ]);

        $first = app(PlanPurchaseSettlement::class)->settle($stale, $answer);
        $second = app(PlanPurchaseSettlement::class)->settle($stale, $answer);

        $this->assertSame(ReconciliationOutcome::SETTLED, $first->kind, $first->reason);
        $this->assertNotSame(ReconciliationOutcome::SETTLED, $second->kind,
            'the same intent was settled twice from one snapshot');
        $this->assertCount(1, $this->orders());
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_SETTLED));
    }

    /** A RUN THAT IS NOT TOLD TO APPLY WRITES NOTHING -- and says what it would have done. */
    public function test_a_report_only_run_writes_nothing(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));
        $before = $this->row($reference);

        $outcome = $this->reconcile($reference, false);

        $this->assertSame(ReconciliationOutcome::WOULD_SETTLE, $outcome->kind, $outcome->reason);
        $this->assertEquals($before, $this->row($reference), 'a report-only run changed the intent');
        $this->assertCount(0, $this->orders());
        $this->assertSame(0, AuditLog::count());
        $this->assertNull(DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'));

        /* CONTROL: the identical fixture, applied, settles. So the nothing above was the dry run. */
        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
    }

    // ═══════════════════════════════════════════════════════ WHAT THE PROVIDER CAN ANSWER

    /**
     * AN ANSWER THAT COULD NOT BE OBTAINED CLOSES NOTHING. The browser return makes this terminal;
     * a reconciliation that did the same would destroy the purchase it exists to recover.
     */
    public function test_an_unreachable_provider_settles_nothing_and_leaves_the_intent_open(): void
    {
        $reference = $this->intent();
        $before = $this->row($reference);
        $this->providerAnswers([], 500);

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::UNVERIFIABLE, $outcome->kind);
        $this->assertTrue($outcome->isRetryable());
        $this->assertEquals($before, $this->row($reference),
            'an unverifiable answer wrote to the intent; it must stay exactly as it was so the next run can ask again');
        $this->assertCount(0, $this->orders());
        $this->assertSame(0, AuditLog::count());

        /* CONTROL, and the point of leaving it open: the next run, with a provider that answers,
           settles the same intent. */
        $this->providerAnswers($this->paytmSays($reference));
        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
    }

    public function test_a_forged_answer_settles_nothing(): void
    {
        $reference = $this->intent();
        $forged = $this->paytmSays($reference);
        $forged['TXNAMOUNT'] = '100.00';
        $forged['CHECKSUMHASH'] = 'not-a-checksum';
        $this->providerAnswers($forged);

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::UNVERIFIABLE, $outcome->kind);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertCount(0, $this->orders());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notSuccessful')]
    public function test_a_payment_the_provider_does_not_confirm_settles_nothing_and_stays_open(string $status): void
    {
        $reference = $this->intent();
        $before = $this->row($reference);
        $this->providerAnswers($this->paytmSays($reference, ['STATUS' => $status]));

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::NOT_CAPTURED, $outcome->kind);
        $this->assertEquals($before, $this->row($reference),
            "a {$status} answer closed the intent. PENDING becomes TXN_SUCCESS later, so nothing terminal may be written");
        $this->assertCount(0, $this->orders());
        $this->assertNull(DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'));
    }

    public static function notSuccessful(): array
    {
        return ['failure' => ['TXN_FAILURE'], 'pending at the bank' => ['PENDING']];
    }

    /** A PROVIDER NOBODY CAN ASK. Listed, never settled, and never contacted. */
    public function test_an_intent_for_a_provider_with_no_verifier_is_never_settled(): void
    {
        $reference = $this->intent(['provider' => 'Cashfree']);
        $this->providerMustNotBeAsked();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::NO_VERIFIER, $outcome->kind);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertCount(0, $this->orders());
        Http::assertNothingSent();
    }

    /**
     * THE CREDENTIALS ARE READ FROM WHERE PRODUCTION KEEPS THEM. `setUp()` stores them as the
     * operator's settings and asserts config is empty; this is the other half -- without those
     * settings nothing can be verified, so nothing is settled.
     */
    public function test_the_credentials_come_from_the_platforms_stored_settings(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        DB::table('settings')->where('name', 'paytm_merchant_key')->delete();
        $this->forgetPlatformSettings();
        config(['services.paytm-wallet.merchant_key' => '']);

        $without = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::UNVERIFIABLE, $without->kind);
        $this->assertStringContainsString('merchant key', $without->reason);
        $this->assertCount(0, $this->orders());

        /* CONTROL: put the key back, and the same intent settles. */
        $this->storePlatformSetting('paytm_merchant_key', self::KEY);

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
    }

    // ═══════════════════════════════════════════════ A CAPTURE THAT CANNOT BE MATCHED

    #[\PHPUnit\Framework\Attributes\DataProvider('wrongAmounts')]
    public function test_a_capture_of_a_different_amount_is_recorded_and_never_settled(string $captured): void
    {
        $reference = $this->intent();
        $says = $this->paytmSays($reference, ['TXNAMOUNT' => $captured]);
        $this->providerAnswers($says);

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertSame(ReconciliationOutcome::AMOUNT, $outcome->discrepancy);
        $this->assertTrue($outcome->needsHuman());

        $intent = $this->row($reference);
        $this->assertSame(PlanPurchaseIntent::REFUSED, $intent->status,
            'an amount mismatch closes the intent, as the browser return closes it');
        /* "amount mismatch: ..." for a different figure, "an amount could not be compared
           exactly: ..." for precision beyond the scale. Both are the amount; both close it. */
        $this->assertStringContainsString('amount', (string) $intent->failure_reason);
        /* The canonical, machine-readable marker the once-only rule keys on. */
        $this->assertStringStartsWith('reconciliation[' . ReconciliationOutcome::AMOUNT . ']:', (string) $intent->failure_reason);
        $this->assertSame($says['TXNID'], $intent->provider_txn_id,
            'the provider transaction must be on the row, or nobody can find the money');

        $this->assertCount(0, $this->orders());
        $this->assertNull(DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'));
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_DISCREPANCY));
        $this->assertTrue(PlanPurchaseIntent::unreconciled(0)->contains('reference', $reference),
            'a recorded discrepancy must be in the list a person reads');
    }

    public static function wrongAmounts(): array
    {
        return [
            'a short payment' => ['99.00'],
            'an overpayment' => ['1000.00'],
            'a difference below the cent' => ['100.00001'],
        ];
    }

    public function test_a_report_only_run_records_no_discrepancy_either(): void
    {
        $reference = $this->intent();
        $before = $this->row($reference);
        $this->providerAnswers($this->paytmSays($reference, ['TXNAMOUNT' => '99.00']));

        $outcome = $this->reconcile($reference, false);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertEquals($before, $this->row($reference));
        $this->assertSame(0, AuditLog::count());
    }

    /**
     * A CURRENCY LABEL THAT DISAGREES KEEPS THE INTENT OPEN -- and is written down ONCE. The second
     * run finds the same thing and must not tell the audit trail about it again.
     */
    public function test_a_currency_discrepancy_stays_open_and_is_audited_once(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, ['CURRENCY' => 'USD']));

        $first = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $first->kind);
        $this->assertSame(ReconciliationOutcome::CURRENCY, $first->discrepancy);

        $intent = $this->row($reference);
        $this->assertSame(PlanPurchaseIntent::PENDING, $intent->status, 'a currency label must not close a captured payment');
        $this->assertStringContainsString('currency mismatch', (string) $intent->failure_reason);
        $this->assertStringStartsWith('reconciliation[' . ReconciliationOutcome::CURRENCY . ']:', (string) $intent->failure_reason);
        $this->assertNull($intent->provider_txn_id, 'a pending row must not claim a captured transaction');
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_DISCREPANCY));

        $second = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $second->kind);
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_DISCREPANCY),
            'the same discrepancy was audited twice; a second run must be a no-op');
        $this->assertCount(0, $this->orders());
    }

    public function test_a_confirmed_capture_with_no_transaction_id_is_not_settled(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, ['TXNID' => '']));

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertSame(ReconciliationOutcome::NO_TRANSACTION_ID, $outcome->discrepancy);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertNotNull($this->row($reference)->failure_reason);
        $this->assertCount(0, $this->orders());
    }

    public function test_a_purchaser_who_no_longer_exists_spends_nothing(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        DB::table('users')->where('id', $this->purchaser->id)->delete();
        (new User)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertSame(ReconciliationOutcome::PURCHASER_MISSING, $outcome->discrepancy);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status,
            'the intent was consumed for a purchaser who does not exist, which nobody can retry');
        $this->assertCount(0, $this->orders());
    }

    public function test_a_plan_that_no_longer_exists_spends_nothing(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        DB::table('plans')->where('id', $this->plan->id)->delete();
        (new Plan)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertSame(ReconciliationOutcome::PLAN_MISSING, $outcome->discrepancy);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertCount(0, $this->orders());
    }

    /** ONE PROVIDER TRANSACTION, ONE PLAN ORDER -- whoever wrote the first. */
    public function test_a_transaction_that_is_already_a_plan_order_is_never_recorded_twice(): void
    {
        $reference = $this->intent();
        $says = $this->paytmSays($reference);
        $this->providerAnswers($says);

        /* Dated before the intent, so this case measures the transaction check and not the
           entitlement-moved-on one -- see the sibling case for what that cost. */
        DB::table('plan_orders')->insert([
            'order_id' => 'EARLIER-' . uniqid(), 'plan_name' => $this->plan->name, 'plan_id' => $this->plan->id,
            'price' => 100, 'price_currency' => 'INR', 'txn_id' => $says['TXNID'],
            'payment_type' => 'Paytm', 'payment_status' => 'success', 'user_id' => $this->purchaser->id,
            'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
        ]);
        (new \App\Models\PlanOrder)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind);
        $this->assertSame(ReconciliationOutcome::TRANSACTION_ALREADY_RECORDED, $outcome->discrepancy);
        $this->assertCount(1, $this->orders(), 'a second order was written for a transaction already recorded');
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
    }

    // ═══════════════════════════════════════════════════════════════ ALL OF IT OR NONE OF IT

    /**
     * THE ORDER CANNOT BE WRITTEN -> the consumption is rolled back with it. The intent must be
     * `pending` again, or a failed settlement would have spent a payment for nothing.
     */
    public function test_a_settlement_that_cannot_write_its_order_leaves_the_intent_unspent(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        /*
         * A row already holding this intent's order id, under a DIFFERENT transaction, so the
         * one-transaction-one-order check passes and the UNIQUE index is what refuses.
         *
         * DATED BEFORE THE INTENT, deliberately. The entitlement-moved-on check added after the
         * independent financial review refuses a settlement when a successful plan order for this
         * purchaser is NEWER than the intent -- so with `now()` here the case never reached the
         * write it exists to test, and the first version of it was measuring that instead.
         */
        DB::table('plan_orders')->insert([
            'order_id' => $reference, 'plan_name' => 'other', 'plan_id' => $this->plan->id,
            'price' => 1, 'price_currency' => 'INR', 'txn_id' => 'SOMETHING-ELSE',
            'payment_type' => 'Paytm', 'payment_status' => 'success', 'user_id' => $this->purchaser->id,
            'created_at' => now()->subHours(4), 'updated_at' => now()->subHours(4),
        ]);
        (new \App\Models\PlanOrder)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::SETTLEMENT_FAILED, $outcome->kind, $outcome->reason);
        $this->assertTrue($outcome->isRetryable());
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status,
            'the settlement failed and the intent stayed consumed: money spent, nothing to show, no retry');
        $this->assertNull($this->row($reference)->provider_txn_id);
        $this->assertCount(1, $this->orders());
        $this->assertNull(DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'));
        $this->assertSame(0, $this->audits(PlanPurchaseSettlement::AUDIT_SETTLED));
    }

    /**
     * THE ENTITLEMENT RAISES AFTER THE ORDER IS WRITTEN -> the order goes too. This is the state
     * the browser return can leave behind (order written, plan not assigned) and this must not.
     */
    public function test_an_entitlement_that_raises_takes_the_order_and_the_consumption_with_it(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        $purchaserId = $this->purchaser->id;
        User::saving(function (User $user) use ($purchaserId) {
            if ((int) $user->id === (int) $purchaserId && $user->isDirty('plan_id')) {
                throw new \RuntimeException('forced: the entitlement could not be stored');
            }
        });

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::SETTLEMENT_FAILED, $outcome->kind, $outcome->reason);
        $this->assertCount(0, $this->orders(), 'an order survived an entitlement that did not land');
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertNull($this->row($reference)->plan_order_id);
        $this->assertNull(DB::table('users')->where('id', $purchaserId)->value('plan_id'));
    }

    // ═══════════════════════════════════════════════ NOTHING THE CALLER SAYS CHOOSES ANYTHING

    /**
     * THE PURCHASER, THE PLAN AND THE PRICE ARE THE INTENT'S. Another merchant and a dearer plan
     * exist and are untouched; the provider is asked about the intent's own reference and no other.
     */
    public function test_the_settlement_follows_the_intent_and_nothing_else(): void
    {
        [$bystander] = $this->makeTenant();
        $dearer = $this->makePlan(['price' => 999]);
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);

        Http::assertSent(function ($request) use ($reference) {
            return str_contains(urldecode((string) $request->body()), '"ORDERID":"' . $reference . '"');
        });
        Http::assertSentCount(1);

        $this->assertNull(DB::table('users')->where('id', $bystander->id)->value('plan_id'));
        $this->assertSame(0, DB::table('plan_orders')->where('plan_id', $dearer->id)->count());
        $this->assertSame(0, DB::table('plan_orders')->where('user_id', $bystander->id)->count());
    }

    /** A plan purchase is paid to the platform. It moves no merchant money and no stock. */
    public function test_a_settlement_touches_no_wallet_order_or_stock_record(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        $tables = array_values(array_filter(
            ['orders', 'store_wallets', 'store_wallet_transactions', 'store_order_financials', 'stock_movements', 'order_gateway_references'],
            fn ($table) => Schema::hasTable($table)
        ));
        $this->assertContains('orders', $tables, 'premise');
        $before = array_map(fn ($table) => DB::table($table)->count(), $tables);

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind, 'premise');

        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), $tables));
    }

    // ═══════════════════════════════════════════════════════════════════════ THE COUPON

    /** The purchase used a coupon: the usage is counted, against the order, as the return counts it. */
    public function test_the_coupon_the_purchase_used_is_counted_once(): void
    {
        $coupon = PlanCoupon::create(['name' => 'Ten', 'code' => 'TEN' . uniqid(), 'discount' => 10, 'limit' => 1, 'description' => '']);
        DB::table('plan_coupons')->where('id', $coupon->id)->update(['is_active' => 1]);
        (new PlanCoupon)->flushCache();

        $reference = $this->intent(['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'expected_amount' => '90.00']);
        $this->providerAnswers($this->paytmSays($reference, ['TXNAMOUNT' => '90.00']));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);

        $usage = DB::table('plan_user_coupons')->get();
        $this->assertCount(1, $usage);
        $this->assertSame((int) $coupon->id, (int) $usage->first()->coupon_id);
        $this->assertSame($reference, $usage->first()->order, 'the usage must point at the order that was written');
        $this->assertSame(0, (int) DB::table('plan_coupons')->where('id', $coupon->id)->value('is_active'),
            'a coupon at its limit stays switched on');
        $this->assertEqualsWithDelta(90.0, (float) DB::table('plan_orders')->value('price'), 0.0001);
    }

    /**
     * A CODE THE BUYER TYPED THAT DISCOUNTED NOTHING IS NOT A USE. The initiator records
     * `coupon_code` unconditionally and `coupon_id` only when it applied the discount.
     */
    public function test_a_code_that_discounted_nothing_is_not_counted_as_a_use(): void
    {
        $coupon = PlanCoupon::create(['name' => 'Off', 'code' => 'OFF' . uniqid(), 'discount' => 10, 'limit' => 5, 'description' => '']);

        $reference = $this->intent(['coupon_id' => null, 'coupon_code' => $coupon->code, 'expected_amount' => '100.00']);
        $this->providerAnswers($this->paytmSays($reference));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
        $this->assertSame(0, DB::table('plan_user_coupons')->count());
    }

    // ═══════════════════════════════════════════════════════════════════════ THE COMMAND

    public function test_the_command_reports_and_writes_nothing_until_it_is_told_to_apply(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        /*
         * EXIT 1, NOT 0, AND THE FIRST VERSION OF THIS CASE ASSERTED 0. `would_settle` means a
         * provider CONFIRMS a capture and the purchaser has no entitlement for it -- money taken,
         * nothing granted -- so a report that exits clean on it is a report nobody has to read.
         * Changed after the independent financial review named this case as pinning the wrong thing.
         */
        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('REPORT ONLY')
            ->expectsOutputToContain(ReconciliationOutcome::WOULD_SETTLE)
            ->assertExitCode(1);

        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);
        $this->assertCount(0, $this->orders());

        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain('APPLYING')
            ->expectsOutputToContain(ReconciliationOutcome::SETTLED)
            ->assertExitCode(0);

        $this->assertSame(PlanPurchaseIntent::CONSUMED, $this->row($reference)->status);
        $this->assertCount(1, $this->orders());
    }

    /** Exit 1 and a named list when something needs a person -- the first reader `unreconciled()` has had. */
    public function test_the_command_fails_and_lists_what_only_a_person_can_resolve(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, ['TXNAMOUNT' => '99.00']));

        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain(ReconciliationOutcome::DISCREPANCY)
            ->expectsOutputToContain('need a person')
            ->assertExitCode(1);

        /* And it keeps saying so on the next run, when the intent is no longer pending at all. */
        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('need a person')
            ->assertExitCode(1);
    }

    /**
     * THE WINDOW. A fresh intent is left to its own buyer; an old one is counted and not asked
     * about. Neither costs a provider call.
     */
    public function test_the_command_leaves_fresh_intents_alone_and_counts_the_ones_it_did_not_examine(): void
    {
        $fresh = $this->intent([], 1);
        $ancient = $this->intent([], 60 * 24 * 90);
        $this->providerMustNotBeAsked();

        /* Exit 1: an unexamined pending intent is a question nobody asked, so the run is not
           clean -- the financial review measured a verified capture going green that way. */
        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain('1 pending intent(s) are older than 30 day(s) and were NOT examined')
            ->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($fresh)->status);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($ancient)->status);

        /* CONTROL: widen the window and the old one IS asked about. */
        $this->providerAnswers($this->paytmSays($ancient));
        /*
         * EXIT 0, and it is worth being exact about why rather than guessing: the ancient intent
         * settles, and the FRESH one is counted by neither clause -- `--minutes=30` excludes it
         * from the examined set, and `outsideReconciliationWindow(120)` counts only intents older
         * than 120 days. A fresh intent is not an unanswered question; its buyer is still at the
         * provider. (My first version of this line asserted 1 on exactly that reasoning and was
         * wrong.)
         */
        $this->artisan('payments:reconcile', ['--apply' => true, '--days' => 120])->assertExitCode(0);
        $this->assertSame(PlanPurchaseIntent::CONSUMED, $this->row($ancient)->status);
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($fresh)->status);
    }

    /** NO RECORD OF INITIATION, NO RECONCILIATION. Exit 2, and nobody is asked anything. */
    public function test_with_neither_record_installed_the_command_refuses_to_run(): void
    {
        $this->providerMustNotBeAsked();
        Schema::drop('plan_purchase_intents');
        Schema::drop(ModulePaymentLedger::TABLE);

        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain('Neither record')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    /**
     * WITHOUT THE INTENT RECORD NO PLAN PAYMENT IS RECONCILED -- said out loud, not skipped -- and
     * the other record is still reported, because a payer turned away from a switched-off
     * application does not stop mattering when a different table is missing.
     */
    public function test_without_the_intent_record_no_plan_payment_is_asked_about_and_the_command_says_so(): void
    {
        $this->providerMustNotBeAsked();
        Schema::drop('plan_purchase_intents');

        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain('no plan payment can be reconciled')
            ->assertExitCode(0);

        Http::assertNothingSent();

        ModulePaymentLedger::recordRefusedCompletion('Donation', null, 'GET {storeSlug}/store-donation-payment', str_repeat('a', 64), 'no payment initiation is held by this session');

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('refused payment return(s) to a switched-off application need a person')
            ->assertExitCode(1);
    }

    // ══════════════════════════════ WHAT THE INDEPENDENT FINANCIAL REVIEW FOUND

    /**
     * A REFUNDED CAPTURE IS NOT PROOF THE MONEY IS STILL THERE.
     *
     * `STATUS` has four values and none says "refunded", so a refunded sale keeps reporting
     * `TXN_SUCCESS`. The case is the ordinary one: `plan-paytm-payment` answers 419, the buyer is
     * charged and shown an error, they complain, the operator refunds them — and nothing can close
     * the intent, so a run days later would grant the plan and record the sale.
     *
     * NO FIELD CONTRACT IS ASSERTED HERE, which is the point: the verifier refuses on ANY
     * refund-shaped key it cannot read as zero, so these cases drive three spellings and a
     * non-numeric value rather than one documented name.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refundSignals')]
    public function test_a_success_that_mentions_a_refund_is_never_settled(array $extra): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, $extra));

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::UNVERIFIABLE, $outcome->kind, $outcome->reason);
        $this->assertStringContainsString('refund', $outcome->reason);
        $this->assertCount(0, $this->orders(), 'a refunded capture was recorded as a sale');
        $this->assertNull(DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'),
            'a plan was granted for money that had been given back');
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status,
            'nothing is written: the contract for reading a refund is not established, so the purchase stays recoverable');
    }

    public static function refundSignals(): array
    {
        return [
            'the documented spelling' => [['REFUNDAMT' => '100.00']],
            'a lower-case variant' => [['refundAmt' => '100.00']],
            'a refund-shaped word this code cannot read' => [['REFUND_STATUS' => 'REFUND_SUCCESS']],
        ];
    }

    /** CONTROL for the cases above: a zero refund value is not a refund, and still settles. */
    public function test_a_zero_refund_value_is_not_a_refund(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, ['REFUNDAMT' => '0.00']));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
    }

    /**
     * A DAYS-OLD PAYMENT IS NOT APPLIED OVER AN ENTITLEMENT THAT HAS MOVED ON.
     *
     * THE SHARPEST FINDING OF THE REVIEW, confirmed there by execution: a three-day-old intent for
     * a cheaper plan DOWNGRADED a buyer who had since bought a dearer one and switched off two of
     * their three stores, because `assignPlan()` SETS the plan and recomputes the expiry from now.
     */
    public function test_a_payment_is_not_applied_over_a_plan_the_purchaser_has_since_bought(): void
    {
        $dearer = $this->makePlan(['price' => 999, 'duration' => 'Year', 'max_stores' => 5]);
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        /* Since initiation, the buyer bought the dearer plan. */
        DB::table('users')->where('id', $this->purchaser->id)
            ->update(['plan_id' => $dearer->id, 'plan_expire_date' => '2027-11-25']);
        (new User)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::DISCREPANCY, $outcome->kind, $outcome->reason);
        $this->assertSame(ReconciliationOutcome::ENTITLEMENT_MOVED_ON, $outcome->discrepancy);
        $this->assertTrue($outcome->needsHuman());

        $this->assertSame((int) $dearer->id, (int) DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'),
            'the buyer was downgraded to the plan of a payment made days earlier');
        $this->assertSame('2027-11-25', DB::table('users')->where('id', $this->purchaser->id)->value('plan_expire_date'),
            'their paid-for expiry was moved a year closer');
        $this->assertCount(0, $this->orders());
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status,
            'the money is real, so the intent stays open for a person to decide about');
        $this->assertTrue(PlanPurchaseIntent::unreconciled(0)->contains('reference', $reference));
    }

    /** The same rule for a purchase somebody already granted by hand: one payment, one month. */
    public function test_a_payment_already_granted_by_another_door_is_not_granted_again(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        /* The operator recorded this sale by hand after the payment was initiated. */
        DB::table('plan_orders')->insert([
            'order_id' => 'BY-HAND-' . uniqid(), 'plan_name' => $this->plan->name, 'plan_id' => $this->plan->id,
            'price' => 100, 'price_currency' => 'INR', 'txn_id' => 'A-DIFFERENT-TRANSACTION',
            'payment_type' => 'Paytm', 'payment_status' => 'success', 'user_id' => $this->purchaser->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\PlanOrder)->flushCache();

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::ENTITLEMENT_MOVED_ON, $outcome->discrepancy, $outcome->reason);
        $this->assertCount(1, $this->orders(), 'one payment produced two plan orders');
    }

    /** CONTROL: a purchaser who has done nothing since initiation settles, however old the intent. */
    public function test_an_old_payment_still_settles_when_nothing_has_changed(): void
    {
        $reference = $this->intent([], 60 * 24 * 20);
        $this->providerAnswers($this->paytmSays($reference));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind,
            'a 20-day-old payment was refused although nothing had happened to the purchaser since');
    }

    /**
     * A VERIFIED CAPTURE THAT COULD NOT BE SETTLED IS WRITTEN DOWN.
     *
     * The review measured the first version: the intent stayed `pending` with a NULL
     * `failure_reason`, absent from `unreconciled()` entirely — and once past `--days` the run
     * exited 0 with the payment unsettled.
     */
    public function test_a_settlement_that_failed_is_recorded_so_a_person_can_find_it(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));

        $purchaserId = $this->purchaser->id;
        User::saving(function (User $user) use ($purchaserId) {
            if ((int) $user->id === (int) $purchaserId && $user->isDirty('plan_id')) {
                throw new \RuntimeException('forced: the entitlement could not be stored');
            }
        });

        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::SETTLEMENT_FAILED, $outcome->kind, $outcome->reason);

        $intent = $this->row($reference);
        $this->assertSame(PlanPurchaseIntent::PENDING, $intent->status, 'the rollback must leave it retryable');
        $this->assertNotNull($intent->failure_reason, 'a verified capture that did not settle was written nowhere');
        $this->assertTrue(PlanPurchaseIntent::unreconciled(0)->contains('reference', $reference),
            'it is absent from the one list a person reads');
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_DISCREPANCY));

        /* And a second run does not grow a second audit row for the same persistent fault. */
        $this->reconcile($reference, true);
        $this->assertSame(1, $this->audits(PlanPurchaseSettlement::AUDIT_DISCREPANCY));
    }

    /**
     * THE SETTLEMENT FOLLOWS THE ROW IT CONSUMED, not the object it was handed.
     *
     * Latent — both callers pass a freshly-read row — and the review confirmed by execution that an
     * object naming another purchaser and a dearer plan settled: the bystander got the plan and the
     * order, the consumed row named somebody else, and the real purchaser got nothing.
     */
    public function test_the_settlement_cannot_be_pointed_at_another_purchaser_by_its_argument(): void
    {
        [$bystander] = $this->makeTenant();
        $dearer = $this->makePlan(['price' => 999]);
        $reference = $this->intent();
        $says = $this->paytmSays($reference);

        $tampered = clone $this->row($reference);
        $tampered->user_id = $bystander->id;
        $tampered->plan_id = $dearer->id;

        $outcome = app(PlanPurchaseSettlement::class)->settle($tampered, CaptureAnswer::fromVerifierArray([
            'state' => CaptureAnswer::VERIFIED, 'amount' => '100.00', 'currency' => '',
            'txn_id' => $says['TXNID'], 'reason' => 'test',
        ]));

        $this->assertSame(ReconciliationOutcome::SETTLED, $outcome->kind, $outcome->reason);

        $this->assertNull(DB::table('users')->where('id', $bystander->id)->value('plan_id'),
            'a bystander was given a plan by an argument');
        $this->assertSame((int) $this->plan->id, (int) DB::table('users')->where('id', $this->purchaser->id)->value('plan_id'),
            'the purchaser the consumed row names did not get their plan');
        $this->assertSame((int) $this->purchaser->id, (int) $this->orders()->first()->user_id);
        $this->assertSame((int) $this->plan->id, (int) $this->orders()->first()->plan_id);
    }

    /** The coupon is resolved by the id the intent recorded, so renaming the code loses no usage. */
    public function test_a_coupon_whose_code_was_renamed_after_initiation_is_still_counted(): void
    {
        $coupon = PlanCoupon::create(['name' => 'Ten', 'code' => 'TEN' . uniqid(), 'discount' => 10, 'limit' => 5, 'description' => '']);
        DB::table('plan_coupons')->where('id', $coupon->id)->update(['is_active' => 1]);
        (new PlanCoupon)->flushCache();

        $reference = $this->intent(['coupon_id' => $coupon->id, 'coupon_code' => $coupon->code, 'expected_amount' => '90.00']);
        $this->providerAnswers($this->paytmSays($reference, ['TXNAMOUNT' => '90.00']));

        /* The operator renames the code between initiation and the reconciliation run. */
        DB::table('plan_coupons')->where('id', $coupon->id)->update(['code' => 'RENAMED' . uniqid()]);
        (new PlanCoupon)->flushCache();

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);

        $usage = DB::table('plan_user_coupons')->get();
        $this->assertCount(1, $usage, 'the usage was dropped because the code had changed');
        $this->assertSame((int) $coupon->id, (int) $usage->first()->coupon_id);
    }

    /** The model cache is flushed AFTER the commit, so a reader cannot cache the pre-settlement row. */
    public function test_the_entitlement_cache_is_flushed_after_the_commit(): void
    {
        $source = file_get_contents(app_path('Services/Payments/Reconciliation/PlanPurchaseSettlement.php'));

        $this->assertStringContainsString('DB::afterCommit(', $source,
            'the flush is inside the transaction, so a concurrent reader caches the pre-settlement '
            . 'row and it outlives the commit -- measured on MariaDB with CACHE_STORE=database');

        foreach (['User', 'PlanOrder', 'PlanUserCoupon', 'PlanCoupon'] as $model) {
            $this->assertStringContainsString('(new ' . $model . '())->flushCache();', $source,
                "{$model} is written by the settlement and is Cachable, so leaving it unflushed "
                . 'leaves part of the entitlement stale while the rest is fresh');
        }

        /* The behavioural half: after a settlement an Eloquent read sees the new plan. On SQLite
           with CACHE_STORE=array this passes either way, which is why the structure is asserted
           too -- the real-engine measurement is in the review's record. */
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference));
        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind, 'premise');

        $this->assertSame((int) $this->plan->id, (int) User::find($this->purchaser->id)->plan_id);
    }

    /** A mistyped `--provider` examined nothing and reported success. */
    public function test_a_provider_no_intent_carries_is_refused_rather_than_reported_clean(): void
    {
        $this->intent();
        $this->providerMustNotBeAsked();

        $this->artisan('payments:reconcile', ['--provider' => 'Paytn'])
            ->expectsOutputToContain('No intent has ever been recorded for provider [Paytn]')
            ->assertExitCode(2);

        Http::assertNothingSent();
    }

    // ═════════════════════════════════════ THE MODULE SIDE: LISTED, ACKNOWLEDGED, NEVER SETTLED

    /**
     * A RETURN REFUSED AT A SWITCHED-OFF APPLICATION IS IN THE LIST A PERSON READS, AND TURNS THE
     * RUN RED UNTIL SOMEBODY SAYS WHAT IT WAS. Acknowledging keeps the row and writes an audit row;
     * it changes no payment.
     */
    public function test_a_refused_module_payment_return_is_listed_until_a_person_acknowledges_it(): void
    {
        ModulePaymentLedger::recordRefusedCompletion('Donation', 7, 'GET {storeSlug}/store-donation-payment', str_repeat('b', 64), 'no payment initiation is held by this session');
        $id = (int) DB::table(ModulePaymentLedger::TABLE)->value('id');

        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('1 refused payment return(s) to a switched-off application need a person')
            ->assertExitCode(1);

        /* A reason is required: what it was is a fact only the person who checked holds. */
        $this->artisan('payments:reconcile', ['--acknowledge' => $id])->assertExitCode(2);
        $this->assertNull(DB::table(ModulePaymentLedger::TABLE)->value('acknowledged_at'));

        $this->artisan('payments:reconcile', ['--acknowledge' => $id, '--reason' => 'Checked the gateway dashboard: no capture for this store that day.'])
            ->expectsOutputToContain('acknowledged')
            ->assertExitCode(0);

        $row = DB::table(ModulePaymentLedger::TABLE)->first();
        $this->assertNotNull($row->acknowledged_at);
        $this->assertSame(ModulePaymentLedger::REFUSED, $row->status, 'acknowledging must keep the row as it was');
        $this->assertStringContainsString('gateway dashboard', (string) $row->acknowledged_reason);

        $audit = AuditLog::where('action', 'payment.reconciliation.acknowledged')->first();
        $this->assertNotNull($audit, 'an acknowledgement left no audit row');
        $this->assertSame($id, (int) $audit->subject_id);
        $this->assertSame(7, (int) $audit->store_id);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        /* It cannot be acknowledged twice, and an id that is not a refusal is not acknowledged. */
        $this->artisan('payments:reconcile', ['--acknowledge' => $id, '--reason' => 'again'])->assertExitCode(2);
        $this->assertSame(1, AuditLog::where('action', 'payment.reconciliation.acknowledged')->count());
    }

    /** Only a REFUSAL can be acknowledged. An open initiation is somebody's payment, not a finding. */
    public function test_an_initiation_cannot_be_acknowledged_away(): void
    {
        $reference = ModulePaymentLedger::recordInitiation('Donation', 7, 'POST {storeSlug}/customer-donation-store', null, null, 'Stripe');
        $id = (int) DB::table(ModulePaymentLedger::TABLE)->where('reference', $reference)->value('id');

        $this->artisan('payments:reconcile', ['--acknowledge' => $id, '--reason' => 'tidy up'])->assertExitCode(2);

        $this->assertSame(ModulePaymentLedger::INITIATED, DB::table(ModulePaymentLedger::TABLE)->value('status'));
        $this->assertTrue(ModulePaymentLedger::hasOpenInitiation('Donation', $reference), 'the payer\'s initiation was closed from the console');
    }

    // ═══════════════════════════════════════════ ACKNOWLEDGING A PLAN INTENT A PERSON HAS DEALT WITH
    //
    // The independent financial review's finding 9. `--acknowledge` reaches the MODULE ledger only,
    // so a plan intent that needs a person needed one FOREVER: `reportWhatOnlyAPersonCanResolve()`
    // adds `unreconciled()`'s whole count to the exit status, and `unreconciled()` has no terminal
    // state. One definite provider NO therefore made every later run exit 1 -- and this command is
    // the only reader of "captured, nothing delivered", so the next, genuinely new loss arrived
    // behind a status that had already been red for weeks. A monitor that cannot go green is a
    // monitor nobody reads.
    //
    // An acknowledgement silences the ALARM and nothing else: the row is kept, still listed, and
    // -- the property that matters more -- it becomes permanently INELIGIBLE for automatic
    // settlement, because "a person dealt with it" includes "the buyer was refunded out of band",
    // and settling that would grant a plan for money that has gone back.

    /** The finding itself: a loss a person has dealt with stops making every later run exit 1. */
    public function test_an_acknowledged_loss_stops_failing_every_later_run_and_is_still_listed(): void
    {
        $reference = $this->intent();
        $this->providerAnswers($this->paytmSays($reference, ['TXNAMOUNT' => '99.00']));

        $this->artisan('payments:reconcile', ['--apply' => true])
            ->expectsOutputToContain(ReconciliationOutcome::DISCREPANCY)
            ->assertExitCode(1);

        $id = (int) $this->row($reference)->id;

        $this->artisan('payments:reconcile', [
            '--acknowledge-intent' => $id,
            '--reason' => 'provider confirmed 99.00 was captured; refunded in full on 2026-11-25, ticket 4471',
        ])->assertExitCode(0);

        /* GREEN now -- and the row is still printed, under a heading that does not raise an alarm. */
        $this->providerMustNotBeAsked();
        $this->artisan('payments:reconcile')
            ->expectsOutputToContain('already dealt with')
            ->expectsOutputToContain($reference)
            ->doesntExpectOutputToContain('need a person')
            ->assertExitCode(0);

        $row = $this->row($reference);
        $this->assertNotNull($row->acknowledged_at);
        $this->assertStringContainsString('ticket 4471', (string) $row->acknowledged_reason);
        $this->assertSame(PlanPurchaseIntent::REFUSED, $row->status, 'the acknowledgement must not alter what happened');
    }

    /**
     * AND IT IS NOT A SETTLEMENT ORDER. An acknowledged intent is never settled again, and the
     * provider is not even asked -- the money may have been refunded, which no provider status
     * field this verifier reads would necessarily still show.
     */
    public function test_an_acknowledged_intent_is_never_settled_and_the_provider_is_not_asked(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[ENTITLEMENT_MOVED_ON]: the buyer has since bought a dearer plan');

        $this->assertTrue(
            PlanPurchaseIntent::acknowledge((int) $this->row($reference)->id, 'refunded out of band', null),
            'premise: the acknowledgement was not recorded'
        );

        $this->providerMustNotBeAsked();
        $outcome = $this->reconcile($reference, true);

        $this->assertSame(ReconciliationOutcome::ACKNOWLEDGED, $outcome->kind);
        $this->assertFalse($outcome->needsHuman());
        $this->assertFalse($outcome->isRetryable());
        Http::assertNothingSent();
        $this->assertCount(0, $this->orders());
        $this->assertSame(PlanPurchaseIntent::PENDING, $this->row($reference)->status);

        /* CONTROL: the same intent, same refusal, WITHOUT the acknowledgement, does settle. */
        $second = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $second, 'reconciliation[UNVERIFIABLE]: the provider timed out');
        $this->providerAnswers($this->paytmSays($second));

        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($second, true)->kind);
        $this->assertCount(1, $this->orders());
    }

    /**
     * THE SINGLE WRITER REFUSES IT TOO, so the browser return cannot settle what the console
     * acknowledged. `PlanPaymentReconciler` guards early to save a provider call; this is the
     * guard that actually makes it a property of the system rather than of one caller.
     */
    public function test_the_settlement_itself_refuses_an_acknowledged_intent(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[UNVERIFIABLE]: no answer');
        PlanPurchaseIntent::acknowledge((int) $this->row($reference)->id, 'handled manually', null);

        $answer = CaptureAnswer::fromVerifierArray([
            'state' => CaptureAnswer::VERIFIED,
            'amount' => '100.00',
            'currency' => 'INR',
            'txn_id' => 'TXN-LIVE-RETURN',
            'reason' => 'the provider confirmed the capture',
        ]);
        $outcome = app(PlanPurchaseSettlement::class)->preflight($this->row($reference), $answer);

        $this->assertNotNull($outcome, 'the single writer let an acknowledged intent through to settlement');
        $this->assertSame(ReconciliationOutcome::ACKNOWLEDGED, $outcome->kind);
        $this->assertCount(0, $this->orders());
    }

    /**
     * A CLEAN PENDING INTENT CANNOT BE SILENCED. Nothing has examined it, so there is nothing to
     * have dealt with -- and acknowledging it would make a captured payment permanently
     * unsettleable with one console command and no record of what it was.
     */
    public function test_an_unexamined_pending_intent_cannot_be_acknowledged(): void
    {
        $reference = $this->intent();
        $id = (int) $this->row($reference)->id;

        $this->assertFalse(PlanPurchaseIntent::acknowledge($id, 'nothing to see here', null));

        $this->artisan('payments:reconcile', ['--acknowledge-intent' => $id, '--reason' => 'nothing to see here'])
            ->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at);

        /* CONTROL: once something HAS been recorded against it, the same id is acknowledgeable. */
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[UNVERIFIABLE]: no answer');
        $this->assertTrue(PlanPurchaseIntent::acknowledge($id, 'nothing to see here', null));
    }

    /**
     * EVERY STATE `unreconciled()` HAS A CLAUSE FOR, and the two it has none for.
     *
     * `[status, plan_order_id, failure_reason code, provider_txn_id, is it a finding]`. The four
     * true rows are `unreconciled()`'s four clauses in order; the two false ones are a settled
     * purchase and a clean pending one -- a buyer who may still be on the provider's page.
     */
    public static function acknowledgeableStates(): array
    {
        return [
            'refused outright' => [PlanPurchaseIntent::REFUSED, null, 'AMOUNT', null, true],
            'pending carrying a provider transaction id' => [PlanPurchaseIntent::PENDING, null, null, 'TXN-CAPTURED', true],
            'pending with a refusal recorded against it' => [PlanPurchaseIntent::PENDING, null, 'UNVERIFIABLE', null, true],
            'consumed with no order behind it' => [PlanPurchaseIntent::CONSUMED, null, null, null, true],
            'consumed WITH an order' => [PlanPurchaseIntent::CONSUMED, 4242, null, 'TXN-SETTLED', false],
            'pending and clean' => [PlanPurchaseIntent::PENDING, null, null, null, false],
        ];
    }

    /**
     * THE EQUALITY, IN BOTH DIRECTIONS: a row is reported as needing a person exactly when a person
     * can answer it.
     *
     * Asserted rather than assumed because either direction failing is a real defect and neither is
     * visible from reading one predicate. A row reported but not answerable is the permanently-red
     * status this change exists to end, only worse -- the command would be telling an operator to
     * deal with something it then refuses to record. A row answerable but not reported is a capture
     * that can be silenced before anybody is told about it.
     *
     * The first version of `needingAPerson()` omitted `unreconciled()`'s `pending` +
     * `provider_txn_id` clause on the grounds that nothing can currently produce such a row -- which
     * is true, and is exactly why this test constructs each state with a raw UPDATE rather than
     * waiting for a writer to make one.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acknowledgeableStates')]
    public function test_an_intent_is_answerable_by_a_person_exactly_when_it_is_reported_as_needing_one(
        string $status,
        ?int $planOrderId,
        ?string $reason,
        ?string $providerTxnId,
        bool $isAFinding
    ): void {
        $reference = $this->intent();

        DB::table('plan_purchase_intents')->where('reference', $reference)->update([
            'status' => $status,
            'plan_order_id' => $planOrderId,
            'failure_reason' => $reason === null ? null : 'reconciliation[' . $reason . ']: measured',
            'provider_txn_id' => $providerTxnId,
        ]);

        $id = (int) $this->row($reference)->id;

        /* DIRECTION 1 -- is it reported? `unreconciled(0)` is what the command lists. */
        $reported = PlanPurchaseIntent::unreconciled(0)->contains(fn ($intent) => (int) $intent->id === $id);

        $this->assertSame($isAFinding, $reported, $isAFinding
            ? 'premise: this state is one of unreconciled()\'s clauses and was not reported'
            : 'premise: a state nobody is being asked about was reported as needing a person');

        /* DIRECTION 2 -- can a person answer it? */
        $this->assertSame(
            $isAFinding,
            PlanPurchaseIntent::acknowledge($id, 'dealt with', null),
            $isAFinding
                ? 'a state this command reports as needing a person could not be acknowledged, so it '
                . 'would be reported for ever'
                : 'a purchase nobody is being asked about could be silenced'
        );
    }

    /** No silent acknowledgement: a reason is the only thing it leaves behind. */
    public function test_an_acknowledgement_without_a_reason_is_refused(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');
        $id = (int) $this->row($reference)->id;

        $this->assertFalse(PlanPurchaseIntent::acknowledge($id, '   ', null));
        $this->artisan('payments:reconcile', ['--acknowledge-intent' => $id])->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at);
    }

    /** Once, and the first reason survives -- a second attempt must not overwrite the record. */
    public function test_an_intent_cannot_be_acknowledged_twice(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');
        $id = (int) $this->row($reference)->id;

        $this->assertTrue(PlanPurchaseIntent::acknowledge($id, 'first: refunded, ticket 4471', null));
        $this->assertFalse(PlanPurchaseIntent::acknowledge($id, 'second: and I changed my mind', null));

        $this->assertStringContainsString('ticket 4471', (string) $this->row($reference)->acknowledged_reason);
        $this->artisan('payments:reconcile', ['--acknowledge-intent' => $id, '--reason' => 'again'])->assertExitCode(2);
    }

    /**
     * TWO IDS AND ONE REASON IS A TYPO, NOT A BATCH. The ids index different tables, so taking the
     * first and ignoring the second would leave the operator believing they had answered both --
     * and the one they did not answer is either a payment that stays unsettleable or an alarm that
     * stays red. Neither is something they would go back and check.
     */
    public function test_both_acknowledgements_at_once_are_refused_and_change_nothing(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');
        $intentId = (int) $this->row($reference)->id;

        ModulePaymentLedger::recordRefusedCompletion(
            'Donation', 11, 'POST {storeSlug}/customer-donation-store', 'abc123', 'no initiation was recorded'
        );
        $refusalId = (int) DB::table(ModulePaymentLedger::TABLE)->where('status', ModulePaymentLedger::REFUSED)->value('id');

        $this->assertGreaterThan(0, $refusalId, 'premise: no module refusal was recorded, so the ambiguity under test cannot arise');

        $this->artisan('payments:reconcile', [
            '--acknowledge' => $refusalId,
            '--acknowledge-intent' => $intentId,
            '--reason' => 'dealt with both',
        ])->expectsOutputToContain('Pass one of')->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at, 'the plan intent was acknowledged by an ambiguous run');
        $this->assertNull(
            DB::table(ModulePaymentLedger::TABLE)->where('id', $refusalId)->value('acknowledged_at'),
            'the module refusal was acknowledged by an ambiguous run'
        );
        $this->assertSame(0, $this->audits('payment.reconciliation.acknowledged'));
        $this->assertSame(0, $this->audits('payment.reconciliation.intent_acknowledged'));
    }

    /**
     * A SETTLEMENT THAT COMMITS WHILE THE ACKNOWLEDGEMENT WAITS MUST WIN, and the acknowledgement
     * must then FAIL rather than be written onto a payment that was just granted.
     *
     * Found by the independent financial review. The first version tested the state with a separate
     * non-locking read and then ran an UPDATE keyed only on `id` and `acknowledged_at IS NULL`,
     * which a settlement never touches. On MySQL the operator's UPDATE blocks on the settlement's
     * row lock -- held across `assignPlan()`, which saves the user, every store and every staff row
     * -- and then succeeded anyway. The operator was told the payment "will not be settled by any
     * door from here on" about a payment that just had been: they refund out of band, the merchant
     * keeps the plan, and because `plan_order_id` is now set the row matches no `unreconciled()`
     * clause and the contradiction never surfaces again.
     *
     * THE INVARIANT IS "NO ROW IS EVER BOTH ACKNOWLEDGED AND SETTLED", and it is asserted that way
     * rather than on a return value, because the return value cannot tell the two shapes apart.
     *
     * SQLite cannot show the lock -- `lockForUpdate()` compiles to nothing -- so the interleaving is
     * forced with a one-shot `DB::listen` that settles the payment WHILE the acknowledgement is
     * mid-flight, which is exactly what the released row lock did on MySQL. A first version of this
     * test settled the payment BEFORE calling `acknowledge()` and passed against the defective
     * shape, because the separate state read then saw the settled row and refused for the wrong
     * reason. With the predicate inside the UPDATE there is no separate read to interleave with, so
     * the listener never fires and the acknowledgement simply succeeds on a row nothing settled --
     * which is why the assertion is the invariant and not "it returned false".
     */
    public function test_an_acknowledgement_is_never_written_onto_a_payment_a_settlement_granted(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[CURRENCY]: INR against inr');
        $id = (int) $this->row($reference)->id;

        $this->assertNotNull(PlanPurchaseIntent::needingAPerson($id), 'premise: the row is not answerable to begin with');

        /*
         * THE INTERLEAVE. Fires once, on any SELECT this acknowledgement makes against the table,
         * and does what the settlement's commit does to the row: spends it and attaches its order.
         * A raw UPDATE is correct here -- `plan_purchase_intents` is read through `DB::table()`
         * throughout, so there is no `Cachable` model to leave stale.
         */
        $interleaved = false;
        DB::listen(function ($query) use (&$interleaved, $id) {
            /*
             * `from "plan_purchase_intents"` and not just the table's name: `acknowledgementAvailable()`
             * runs `Schema::hasColumn()` first, whose `pragma_table_xinfo('plan_purchase_intents')`
             * ALSO carries the name. A looser match fired on that instead, so the state changed
             * before the state read rather than after it -- and the defective shape then refused for
             * the wrong reason and the test passed against it.
             */
            if ($interleaved
                || ! str_contains(strtolower($query->sql), 'from "plan_purchase_intents"')
                || ! str_starts_with(strtolower(trim($query->sql)), 'select')) {
                return;
            }
            $interleaved = true;
            DB::table('plan_purchase_intents')->where('id', $id)->update([
                'status' => PlanPurchaseIntent::CONSUMED,
                'consumed_at' => now(),
                'plan_order_id' => 4242,
            ]);
        });

        PlanPurchaseIntent::acknowledge($id, 'refunded in full, ticket 4471', null);

        $row = $this->row($reference);

        $this->assertFalse(
            $row->acknowledged_at !== null && $row->plan_order_id !== null,
            'a payment carries BOTH an acknowledgement and a granted plan order. The operator was '
            . 'told it "will not be settled by any door from here on" about a payment that just '
            . 'was: they refund out of band, the merchant keeps the plan, and because plan_order_id '
            . 'is set the row matches no unreconciled() clause, so the contradiction never surfaces '
            . 'again.'
        );
    }

    /** And the plain case: a payment already settled cannot be acknowledged at all. */
    public function test_a_settled_payment_cannot_be_acknowledged(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[CURRENCY]: INR against inr');
        $id = (int) $this->row($reference)->id;

        $this->providerAnswers($this->paytmSays($reference));
        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);
        $this->assertNotNull($this->row($reference)->plan_order_id, 'premise: no plan order was written');

        $this->assertFalse(PlanPurchaseIntent::acknowledge($id, 'refunded in full, ticket 4471', null));

        $this->artisan('payments:reconcile', ['--acknowledge-intent' => $id, '--reason' => 'refunded in full'])
            ->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at);
        $this->assertSame(0, $this->audits('payment.reconciliation.intent_acknowledged'),
            'an audit row claims a payment was foreclosed when it was settled');
    }

    /**
     * A MISTYPED ID IS CORRECTABLE ON THE RECORD. The ids are small sequential integers printed
     * beside other purchasers' findings and nothing could clear `acknowledged_at`, so the only
     * remedy was a hand-written UPDATE -- same shell access, no record. Raised by independent
     * review.
     */
    public function test_an_acknowledged_intent_can_be_reopened_and_is_then_settleable_again(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRecoverableRefusal('Paytm', $reference, 'reconciliation[UNVERIFIABLE]: no answer');
        $id = (int) $this->row($reference)->id;

        $this->artisan('payments:reconcile', ['--acknowledge-intent' => $id, '--reason' => 'wrong row, meant 43'])
            ->assertExitCode(0);
        $this->assertNotNull($this->row($reference)->acknowledged_at);

        /* Acknowledged, so the reconciler refuses it and asks nobody. */
        $this->providerMustNotBeAsked();
        $this->assertSame(ReconciliationOutcome::ACKNOWLEDGED, $this->reconcile($reference, true)->kind);

        $this->artisan('payments:reconcile', ['--reopen-intent' => $id, '--reason' => 'acknowledged the wrong id'])
            ->expectsOutputToContain('reopened')
            ->assertExitCode(0);

        $row = $this->row($reference);
        $this->assertNull($row->acknowledged_at);
        $this->assertNull($row->acknowledged_by);
        $this->assertNull($row->acknowledged_reason);
        $this->assertSame('reconciliation[UNVERIFIABLE]: no answer', $row->failure_reason,
            'the reopen altered what happened to the payment, which is not its job');

        /* AND THE REOPEN SETTLED NOTHING BY ITSELF -- the provider still has to confirm. */
        $this->assertCount(0, $this->orders());

        $this->providerAnswers($this->paytmSays($reference));
        $this->assertSame(ReconciliationOutcome::SETTLED, $this->reconcile($reference, true)->kind);

        $this->assertSame(1, $this->audits('payment.reconciliation.intent_reopened'));
        $audit = AuditLog::where('action', 'payment.reconciliation.intent_reopened')->first();
        $this->assertStringContainsString('wrong row, meant 43', json_encode($audit->old_values),
            'the acknowledgement being undone was not recorded, so what was cleared is unrecoverable');
    }

    /** Only an acknowledged row can be reopened, and a reason is required for that too. */
    public function test_a_reopen_needs_an_acknowledged_row_and_a_reason(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');
        $id = (int) $this->row($reference)->id;

        /* Not acknowledged yet. */
        $this->assertFalse(PlanPurchaseIntent::reopen($id));
        $this->artisan('payments:reconcile', ['--reopen-intent' => $id, '--reason' => 'why not'])->assertExitCode(2);

        PlanPurchaseIntent::acknowledge($id, 'refunded', null);

        $this->artisan('payments:reconcile', ['--reopen-intent' => $id])->assertExitCode(2);
        $this->assertNotNull($this->row($reference)->acknowledged_at, 'a reopen with no reason still cleared the row');

        /* And twice is once. */
        $this->assertTrue(PlanPurchaseIntent::reopen($id));
        $this->assertFalse(PlanPurchaseIntent::reopen($id));
    }

    /** A padded id is a paste that went wrong, and this action cannot be undone by the typist. */
    public function test_a_padded_id_is_refused_rather_than_coerced(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');
        $id = (int) $this->row($reference)->id;

        $this->artisan('payments:reconcile', [
            '--acknowledge-intent' => '0' . $id,
            '--reason' => 'ctype_digit says this is a number and (int) says it is a different row',
        ])->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at);
    }

    /** Three ids and one reason is a typo, not a batch -- whichever two of the three are passed. */
    public function test_more_than_one_named_record_is_refused(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: measured');
        $id = (int) $this->row($reference)->id;

        $this->artisan('payments:reconcile', [
            '--acknowledge-intent' => $id,
            '--reopen-intent' => $id,
            '--reason' => 'both at once',
        ])->expectsOutputToContain('not 2')->assertExitCode(2);

        $this->assertNull($this->row($reference)->acknowledged_at);
    }

    /** It is written down where an auditor looks, not only on the row. */
    public function test_acknowledging_an_intent_is_audited(): void
    {
        $reference = $this->intent();
        PlanPurchaseIntent::recordRefusal('Paytm', $reference, 'reconciliation[AMOUNT]: 99.00 against 100.00');

        $this->artisan('payments:reconcile', [
            '--acknowledge-intent' => (int) $this->row($reference)->id,
            '--reason' => 'refunded in full, ticket 4471',
        ])->assertExitCode(0);

        $this->assertSame(1, $this->audits('payment.reconciliation.intent_acknowledged'));

        $audit = AuditLog::where('action', 'payment.reconciliation.intent_acknowledged')->first();
        $this->assertStringContainsString($reference, json_encode($audit->new_values));
        $this->assertStringContainsString('ticket 4471', json_encode($audit->new_values));
    }
}
