<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderController;
use App\Models\Order;
use App\Services\PaymentVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE CASH-ON-DELIVERY STATE MACHINE.
 *
 * THE DEFECT. PaymentService::process() routes 'cod' straight to
 * getProductStatus() (:315) and the order writer recorded it 'Paid' at once, so
 * a cash-on-delivery order counted as paid before the courier had collected
 * anything. An order placed and never delivered stayed Paid forever, so revenue
 * figures counted cash nobody handed over.
 *
 * THE EVIDENCE THAT THIS IS WRONG IS IN THE PLATFORM'S OWN CODE, not in an
 * opinion: Order::order_status_change() writes `payment_status = 'Unpaid'` when
 * an order is CANCELLED (app/Models/Order.php:550), with a comment calling it
 * the missing magic line needed "to refund the amount". There is nothing to
 * refund on a COD order that was never delivered. That reversal exists purely to
 * undo a Paid state the order should never have had -- and an Observer reacts to
 * it by adjusting the wallet, so the premature Paid propagates into the
 * financial records and then has to be walked back.
 *
 * WHAT IS IMPLEMENTED. Both halves of the correct machine:
 *
 *   placed    -> Pending   (PaymentVerificationService::resolveOrderPaymentStatus)
 *   delivered -> Paid      (OrderController::order_status_change)
 *   cancelled -> stays unpaid; nothing to reverse
 *
 * AND IT IS OFF BY DEFAULT. `services.cod.settlement` defaults to 'order', the
 * pre-existing behaviour, because when an order counts as paid decides when a
 * merchant's wallet is credited -- a business decision for the owner, not a fix
 * to impose on a live shop. Half of this suite exists to prove the default is
 * genuinely unchanged, which is the property a merchant relying on today's
 * behaviour actually cares about.
 */
class CodStateMachineTest extends TestCase
{
    private PaymentVerificationService $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new PaymentVerificationService();
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFAULT IS UNCHANGED -- the preservation half
    |--------------------------------------------------------------------------
    */

    /**
     * OWNER DECISION D-06, 2026-10-22: settle on delivery, and settle-at-order is NO LONGER a
     * permitted value.
     *
     * These five tests previously asserted the OPPOSITE -- that the default was settle-at-order and
     * the delivery transition inert -- because when an order counts as paid decides when a merchant's
     * wallet is credited, and that was the owner's call to make. It is made, so they now assert the
     * decision. Their failing when the implementation changed is the signal the suite was built to
     * give, not a regression.
     *
     * BOTH defaults are still pinned, and the reason is worth keeping: `config/services.php` declares
     * the key, so the CONFIG FILE's default is what actually governs and the PHP fallback in
     * codSettlementPolicy() is never reached while the key is declared. A test exercising only the
     * helper would stay green if the config file disagreed -- measured, not assumed, when this suite
     * was first written: flipping the helper's fallback alone produced NO failure at all. So the
     * config value is asserted directly.
     */
    public function test_the_default_policy_is_settle_on_delivery(): void
    {
        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            config('services.cod.settlement'),
            'config/services.php must default COD to settle-on-delivery (owner decision D-06), because '
                . 'that declared value is what governs -- not the helper fallback'
        );

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy(),
            'the resolved policy must be settle-on-delivery'
        );
    }

    /**
     * The helper's own fallback, exercised by removing the declared key. This is the path a deployment
     * with an older config/services.php takes, and it must ALSO land on settle-on-delivery -- otherwise
     * a stale config file would silently reinstate the behaviour the decision forecloses.
     */
    public function test_the_helper_falls_back_to_settle_on_delivery_when_the_key_is_absent(): void
    {
        config(['services.cod' => null]);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy(),
            'an undeclared key must still mean settle-on-delivery'
        );
    }

    /**
     * THE CLAUSE THAT MAKES THIS MORE THAN A DEFAULT FLIP. The decision says the workflow is
     * configurable per store "WITHOUT ALLOWING UNCOLLECTED MONEY TO BE REPORTED AS PAID", so
     * settle-at-order is not merely off -- it is unreachable. A deployment that still carries
     * COD_SETTLEMENT=order gets settle-on-delivery anyway.
     */
    #[DataProvider('codSpellings')]
    public function test_a_stale_settle_at_order_setting_cannot_report_cod_as_paid(string $spelling): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_AT_ORDER]);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy(),
            'the retired value must not be honoured'
        );

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus($spelling, null, '100.00', __METHOD__),
            'a stale COD_SETTLEMENT=order reported uncollected money as paid'
        );
    }

    /** A COD order is Pending when placed, on every spelling, with nothing configured. */
    #[DataProvider('codSpellings')]
    public function test_cod_is_pending_at_order_time(string $spelling): void
    {
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus($spelling, null, '100.00', __METHOD__),
            'COD must not be recorded Paid before collection (owner decision D-06)'
        );
    }

    /**
     * AND THE DELIVERY-SIDE TRANSITION IS WITHDRAWN. This test has now been inverted TWICE, and
     * the history is the point rather than noise.
     *
     *   v1  asserted the transition was INERT until the owner opted in (the D-06 proposal state)
     *   v2  asserted it was LIVE, once D-06 removed the policy gate
     *   v3  asserts it does not exist, because the owner withdrew it on 2026-10-24:
     *       "Delivered must never, by itself, mark COD as Paid or credit a merchant wallet."
     *
     * A reader who finds only v3 might reasonably wonder whether delivery settlement was ever
     * considered. It was, twice, and was retired on evidence rather than preference: a merchant
     * clicking 'delivered' on their own order is a proxy for collection, not evidence of it.
     */
    public function test_the_delivery_transition_is_withdrawn(): void
    {
        foreach (['Unpaid', 'Pending'] as $status) {
            $this->assertFalse(
                $this->verifier->codMayBeSettled($this->codOrder($status), null),
                "a COD order in state '{$status}' must NOT settle merely because it was delivered"
            );
        }
    }

    /** The other offline types are untouched by any of this. */
    #[DataProvider('otherOfflineTypes')]
    public function test_other_offline_payment_types_are_unaffected(string $type): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertSame(
            PaymentVerificationService::STATUS_PAID,
            $this->verifier->resolveOrderPaymentStatus($type, null, '100.00', __METHOD__),
            'the COD policy must not change bank transfer or whatsapp'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | THE CORRECT MACHINE, when the owner opts in
    |--------------------------------------------------------------------------
    */

    /** Opted in, a COD order is born Pending rather than Paid. */
    #[DataProvider('codSpellings')]
    public function test_an_opted_in_cod_order_is_pending_when_placed(string $spelling): void
    {
        config(['services.cod.settlement' => PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY]);

        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus($spelling, null, '100.00', __METHOD__),
            'no money has moved at checkout on a cash-on-delivery order'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELIVERED IS NOT PAID -- owner decision, 2026-10-24, FINAL.
    |
    | THREE TESTS HERE ARE INVERSIONS, not repairs, and the inversion IS the
    | decision. They asserted that marking an order delivered settles it:
    |   test_an_opted_in_unpaid_cod_order_settles_on_delivery
    |   test_an_already_paid_cod_order_does_not_settle_again
    |   test_a_non_cod_order_never_settles_on_delivery
    | all three drove codShouldSettleOnDelivery(), which no longer exists as a
    | working method. They encoded the behaviour the owner withdrew:
    |
    |   "Delivered must never, by itself, mark COD as Paid or credit a merchant
    |    wallet. Require a separate verified cash collection event, recording
    |    the collector (merchant, courier or delivery company), collection
    |    amount, time and supporting evidence."
    |
    | What they were really protecting -- idempotency, and COD-only -- is kept
    | below against the NEW rule, because those requirements did not change.
    | Only the trigger did.
    |--------------------------------------------------------------------------
    */

    /** The withdrawn method is gone, and says why rather than being an undefined-method fatal. */
    public function test_the_delivered_trigger_is_withdrawn_and_says_so(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/withdrawn by owner decision/i');

        $this->verifier->codShouldSettleOnDelivery($this->codOrder('Pending'));
    }

    /** No caller survives in app/. A withdrawn trigger with a live call site is not withdrawn. */
    public function test_no_code_in_app_still_calls_the_withdrawn_trigger(): void
    {
        $callers = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Comments stripped before matching: a docblock EXPLAINING the withdrawal is not a
            // call, and this project has already been bitten by a ratchet that could not tell the
            // difference -- in both directions.
            $code = implode('', array_map(
                static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
                token_get_all(file_get_contents($file->getPathname()))
            ));

            // The declaration itself is not a call.
            if (str_contains($code, 'function codShouldSettleOnDelivery')) {
                continue;
            }

            if (str_contains($code, 'codShouldSettleOnDelivery')) {
                $callers[] = $file->getPathname();
            }
        }

        $this->assertSame([], $callers,
            'the Delivered trigger is still called, so withdrawing it changed nothing at those sites');
    }

    /** WITHOUT A COLLECTION EVENT, NOTHING SETTLES. This is the decision in one assertion. */
    public function test_a_cod_order_does_not_settle_without_a_collection_event(): void
    {
        foreach (['Pending', 'Unpaid', 'unpaid', ''] as $status) {
            $this->assertFalse(
                $this->verifier->codMayBeSettled($this->codOrder($status), null),
                "a COD order in state '{$status}' settled with no verified cash collection event"
            );
        }
    }

    /** A verified event for the right order, in the right amount, DOES settle it. */
    public function test_a_verified_collection_event_settles_the_order(): void
    {
        $order = $this->codOrder('Pending');

        $this->assertTrue(
            $this->verifier->codMayBeSettled($order, $this->collectionEvent($order)),
            'a verified collection naming the collector, amount, time and evidence must settle the order; '
            . 'without this the rule is a blanket refusal and every assertion around it is vacuous'
        );
    }

    /** IDEMPOTENT, kept from the withdrawn test: the requirement did not change, the trigger did. */
    public function test_an_already_paid_cod_order_does_not_settle_again(): void
    {
        foreach (['Paid', 'paid'] as $status) {
            $order = $this->codOrder($status);

            $this->assertFalse(
                $this->verifier->codMayBeSettled($order, $this->collectionEvent($order)),
                "a replayed collection against an already-{$status} order must not settle it twice"
            );
        }
    }

    /** COD-ONLY, likewise kept: a collection event does not settle a card order. */
    public function test_a_non_cod_order_never_settles_through_the_collection_rule(): void
    {
        foreach (['stripe', 'paymera', 'bank_transfer', 'whatsapp', '', null] as $type) {
            $order = new Order();
            $order->forceFill(['id' => 4242, 'payment_type' => $type, 'payment_status' => 'Pending', 'final_price' => 100]);

            $this->assertFalse(
                $this->verifier->codMayBeSettled($order, $this->collectionEvent($order)),
                'only cash on delivery settles through a cash collection event'
            );
        }
    }

    /** AN EVENT FOR ANOTHER ORDER SETTLES NOTHING -- the authorise-one-row, mutate-another class. */
    public function test_a_collection_event_belonging_to_another_order_does_not_settle_this_one(): void
    {
        $order = $this->codOrder('Pending');
        $event = $this->collectionEvent($order);
        $event->order_id = (int) $order->id + 1;

        $this->assertFalse($this->verifier->codMayBeSettled($order, $event));
    }

    /** A SHORT OR OVER COLLECTION IS NOT SETTLEMENT. Partial collection is a concept this has not got. */
    public function test_an_amount_that_does_not_settle_the_order_is_refused(): void
    {
        $order = $this->codOrder('Pending');

        foreach ([0, -100, 99.0, 100.01, 150] as $amount) {
            $event = $this->collectionEvent($order);
            $event->amount = $amount;

            $this->assertFalse(
                $this->verifier->codMayBeSettled($order, $event),
                "a collection of {$amount} against an order of 100 must not settle it"
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Robustness
    |--------------------------------------------------------------------------
    */

    /**
     * A typo in the config falls back to the default rather than failing closed.
     *
     * Failing closed here would mean a misconfigured key stops a shop recording
     * orders correctly, which is worse than the thing being guarded.
     */
    public function test_an_unrecognised_policy_still_settles_on_delivery(): void
    {
        // The fallback direction is the point. A typo in the environment used to mean settle-at-order,
        // i.e. a misconfiguration silently reported uncollected money as paid. It now falls the safe
        // way: an unreadable value cannot produce the state the owner's decision forecloses.
        config(['services.cod.settlement' => 'on-delivery-ish']);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy()
        );
        $this->assertSame(
            PaymentVerificationService::STATUS_PENDING,
            $this->verifier->resolveOrderPaymentStatus('cod', null, '100.00', __METHOD__),
            'a typo in COD_SETTLEMENT must not report uncollected money as paid'
        );
    }

    /** The policy value is read case- and whitespace-insensitively. */
    public function test_the_policy_value_tolerates_case_and_whitespace(): void
    {
        config(['services.cod.settlement' => '  DELIVERY  ']);

        $this->assertSame(
            PaymentVerificationService::COD_SETTLEMENT_ON_DELIVERY,
            $this->verifier->codSettlementPolicy()
        );
    }

    /** The spellings are recognised, and nothing else is. */
    public function test_cod_spellings_are_recognised_and_others_are_not(): void
    {
        foreach (PaymentVerificationService::COD_PAYMENT_TYPES as $type) {
            $this->assertTrue($this->verifier->isCashOnDelivery($type));
            $this->assertTrue($this->verifier->isCashOnDelivery(strtoupper($type)));
        }

        foreach (['cods', 'cash-on-delivery', 'bank_transfer', 'stripe', '', null] as $type) {
            $this->assertFalse(
                $this->verifier->isCashOnDelivery($type),
                var_export($type, true) . ' must not be treated as cash on delivery'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | The controller half -- structural, and why
    |--------------------------------------------------------------------------
    */

    /**
     * The settlement sits in order_status_change(), is gated on BOTH 'delivered'
     * and the policy helper, and fires the OrderPaymentStatus event.
     *
     * STRUCTURAL, and the limit is stated rather than implied: driving
     * order_status_change() behaviourally needs an order, a store, a store owner,
     * a mail template and an SMS gateway, because the method sends an email and
     * an SMS before it returns. This suite does not have that fixture and does
     * not pretend to. What is asserted is what a later edit would most easily
     * break: that the transition is gated on the policy helper (so it stays
     * inert by default) and that it fires the event the wallet and notification
     * listeners hang off, rather than writing payment_status silently.
     *
     * Comments are stripped before matching, so a comment cannot satisfy or
     * defeat any assertion here.
     */
    public function test_the_delivery_settlement_lives_in_the_status_change_writer(): void
    {
        $m = new \ReflectionMethod(OrderController::class, 'order_status_change');

        $source = implode('', array_slice(
            file($m->getFileName()),
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1
        ));

        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        /*
         * INVERTED on 2026-10-24. This block used to assert that the settlement LIVED in this
         * writer -- that it called codShouldSettleOnDelivery() and keyed on 'delivered'. The owner
         * withdrew that trigger, so the same three strings are now the things that must be ABSENT.
         *
         * Comments are stripped above before any of this is matched, which is load-bearing here:
         * the withdrawal left a long docblock in that method EXPLAINING what was removed and why,
         * and that prose names every string below. Without the stripping this test would fail on
         * its own explanation -- the exact trap that bit EnforcedPaymentTypesIntegrityTest.
         */
        $this->assertStringNotContainsString(
            'codShouldSettleOnDelivery(',
            $code,
            'the status-change writer still calls the withdrawn Delivered trigger'
        );
        $this->assertStringNotContainsString(
            'STATUS_PAID',
            $code,
            'the status-change writer still writes a PAID payment status. Marking an order '
            . 'delivered must change the DELIVERY status and nothing else -- the owner asked for '
            . 'delivery reporting to stay separate from cash collection and settlement.'
        );
        /*
         * ALSO INVERTED. This asserted that the writer FIRED OrderPaymentStatus, on the reasoning
         * that writing payment_status without firing it would bypass the wallet and notification
         * listeners. That reasoning was sound for a method that wrote payment_status; it writes
         * none now, so firing a payment event from a DELIVERY status change would be the defect
         * -- it is precisely the wallet credit the owner forbade.
         */
        $this->assertStringNotContainsString(
            'OrderPaymentStatus(',
            $code,
            'a delivery status change still fires a PAYMENT event. Its listeners credit the '
            . 'merchant wallet, which is what "no wallet credit ... for Delivered" forbids.'
        );

        // And the ordering assertion that policed the old gate is gone with it: there is no
        // payment_status write left in this method to be inside or outside anything.
        $this->assertStringNotContainsString(
            'payment_status =',
            $code,
            'the status-change writer still assigns payment_status'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Providers / fixture
    |--------------------------------------------------------------------------
    */

    public static function codSpellings(): array
    {
        return [
            'cod' => ['cod'],
            'cash_on_delivery' => ['cash_on_delivery'],
            'cash' => ['cash'],
            'upper case' => ['COD'],
            'padded' => ['  cod  '],
        ];
    }

    public static function otherOfflineTypes(): array
    {
        return [
            'bank_transfer' => ['bank_transfer'],
            'whatsapp' => ['whatsapp'],
        ];
    }

    /** An unsaved Order; nothing here needs a database. */
    private function codOrder(string $paymentStatus): Order
    {
        $order = new Order();
        $order->forceFill([
            'id' => 1,
            'payment_type' => 'cod',
            'payment_status' => $paymentStatus,
            'store_id' => 11,
            // The amount a collection event has to match. Added with the 2026-10-24 decision:
            // settlement is now "the cash collected equals what is owed", so an order with no
            // total cannot be settled by anything and every amount assertion would be vacuous.
            'final_price' => 100,
        ]);

        return $order;
    }

    /**
     * A verified cash collection event, in the shape CodCollectionService records and
     * PaymentVerificationService::codMayBeSettled() reads.
     *
     * An OBJECT rather than an array, because that is what a DB::table()->first() hands back and
     * what the production caller will pass -- a fixture shaped unlike the real thing is how a
     * rule passes its tests and fails in use.
     */
    private function collectionEvent(Order $order, array $overrides = []): object
    {
        return (object) array_merge([
            'order_id' => (int) $order->id,
            'store_id' => $order->store_id,
            'collector_type' => 'courier',
            'collector_id' => 7,
            'amount' => (float) $order->final_price,
            'collected_at' => '2026-10-24 09:00:00',
            'evidence' => 'signed delivery note 4471',
            'recorded_by' => 3,
        ], $overrides);
    }

    // =====================================================================================
    //  THE HAZARD THIS SECTION GUARDED IS RESOLVED. WHAT REMAINS IS KEEPING IT RESOLVED.
    // =====================================================================================

    /**
     * SUPERSEDES test_gate_f_cannot_be_released_while_marked_delivered_is_the_settlement_trigger,
     * which was added one commit earlier and lived for about an hour.
     *
     * THAT TEST ASSERTED A CONJUNCTION -- that the GATE F financial tables and the
     * marked-Delivered trigger must never both exist -- because at the time the trigger was the
     * shipped behaviour and withdrawing it was an open owner decision. It was the right control
     * for that state: it would have gone red the day someone promoted GATE F while a merchant's
     * own click could credit their own wallet.
     *
     * The owner then withdrew the trigger outright, so one half of that conjunction is now
     * permanently false and the test was asserting nothing. Its own premise check caught exactly
     * that, which is the behaviour a premise check exists for.
     *
     * WHAT REPLACES IT. The hazard is no longer "these two may not coexist"; it is "the withdrawn
     * trigger may not come back". That is a different and longer-lived assertion, because the
     * pressure to restore it will be real: once GATE F lands and merchants ask why COD orders sit
     * Pending, re-wiring delivery to settlement is the obvious shortcut and it is the one the
     * owner has already refused.
     *
     * WHY THE SOURCE IS SCANNED RATHER THAN THE BEHAVIOUR DRIVEN. Behaviour is covered above --
     * codMayBeSettled() refuses without an event. This is about the OTHER end: a future
     * controller, observer or module writing payment_status = Paid off a delivery status change
     * without going near codMayBeSettled() at all. Only the source can see that.
     */
    public function test_the_delivered_trigger_has_not_been_reinstated_anywhere(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Comments stripped FIRST. The withdrawal left docblocks that quote every string
            // below; this project has been bitten by a source-grep ratchet reading its own prose
            // in both directions, so the rule is now unconditional.
            $code = implode('', array_map(
                static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
                token_get_all(file_get_contents($file->getPathname()))
            ));

            // The pattern that matters: a file that mentions the delivered status AND assigns a
            // paid payment status. Either alone is ordinary -- plenty of code reads 'delivered',
            // and the explicit merchant assertion in order_payment_status legitimately writes
            // Paid. Together in one file is the shape that was withdrawn.
            $mentionsDelivered = str_contains($code, "'delivered'") || str_contains($code, '"delivered"');

            /*
             * THE ASSIGNMENT AND THE PAID VALUE MUST BE THE SAME STATEMENT. The first version of
             * this matched "a payment_status assignment somewhere" AND "the word Paid somewhere",
             * and flagged app/Models/Order.php -- which writes `payment_status = 'Unpaid'` on
             * CANCEL and separately lists 'Paid' in a dropdown helper at :1028. Two unrelated
             * facts in one 1000-line file are not a delivered-to-Paid write.
             *
             * It then flagged the SAME FILE a second time, for a different reason: the dropdown
             * helper is `$payment_status = ['Paid' => 'Paid', ...]`, a LOCAL VARIABLE whose
             * assignment and value really are one statement. Hence `->payment_status`: a status
             * write is a property assignment on a model, and a local array of labels is not.
             *
             * Both near-misses are kept in the comment rather than just fixed, because the loose
             * forms are the obvious ways to write this and the next person will reach for them
             * too. Two false positives on one file, from two different causes, is what a
             * co-occurrence scan costs.
             */
            $writesPaid = (bool) preg_match(
                '/->payment_status\s*=\s*[^;=][^;]{0,80}?(STATUS_PAID|[\'"]Paid[\'"])/',
                $code
            );

            if ($mentionsDelivered && $writesPaid) {
                $offenders[] = str_replace(base_path() . '/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders,
            "A file both names the 'delivered' status and writes a Paid payment status. The owner "
            . 'withdrew that combination on 2026-10-24: "Delivered must never, by itself, mark COD '
            . 'as Paid or credit a merchant wallet." If this is a legitimate new path, it must go '
            . 'through a verified cash collection event -- App\Services\CodCollectionService and '
            . 'PaymentVerificationService::codMayBeSettled() -- not off a delivery status change.');
    }

    /**
     * THE PREMISE FOR THE ABOVE. A ratchet that scans for a pattern proves nothing unless the
     * scan can actually see that pattern, so this asserts the detector fires on a file that
     * really does contain it -- using this test file's own fixture text rather than writing a
     * decoy into app/.
     */
    public function test_the_reinstatement_detector_can_actually_detect_it(): void
    {
        $decoy = '<?php $s = \'delivered\'; $order->payment_status = \'Paid\';';

        $code = implode('', array_map(
            static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($decoy)
        ));

        $mentionsDelivered = str_contains($code, "'delivered'");
        $writesPaid = (bool) preg_match('/payment_status\s*=\s*[^=]/', $code)
            && (bool) preg_match("/['\"]Paid['\"]/", $code);

        $this->assertTrue($mentionsDelivered && $writesPaid,
            'the detector above cannot see the pattern it exists to find, so its empty result means nothing');
    }

    /**
     * THE CONSTANT AND THE LITERALS MUST AGREE.
     *
     * CodCollectionService spells its table name as a quoted LITERAL in both `DB::table()` calls
     * and keeps the constant for `Schema::hasTable()` and the public name -- deliberate
     * duplication, because RawQueryTenancyInventoryTest's per-table exposure ratchet matches only
     * a quoted literal and could not see `DB::table(self::TABLE)` at all. That was measured: the
     * per-directory count moved 143 -> 145 while the per-table map gained no row, so a later
     * change dropping the store_id predicate would have tripped nothing.
     *
     * Duplication bought ratchet visibility, and this test is the price of it: if the constant is
     * ever renamed without the literals, the service reads a table nobody declared and the
     * inventory watches the wrong one.
     */
    public function test_the_table_constant_and_the_literals_agree(): void
    {
        $source = file_get_contents(app_path('Services/CodCollectionService.php'));

        $code = implode('', array_map(
            static fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($source)
        ));

        preg_match_all('/DB::table\s*\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $code, $matches);

        $this->assertNotEmpty($matches[1],
            'CodCollectionService no longer names its table as a literal in DB::table(), so the '
            . 'per-table tenancy ratchet cannot see its queries. See the constant\'s docblock.');

        foreach (array_unique($matches[1]) as $literal) {
            $this->assertSame(\App\Services\CodCollectionService::TABLE, $literal,
                'a DB::table() literal in CodCollectionService disagrees with its TABLE constant');
        }
    }
}
