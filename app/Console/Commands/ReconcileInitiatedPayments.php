<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ModulePaymentLedger;
use App\Services\Payments\Reconciliation\CaptureVerifierRegistry;
use App\Services\Payments\Reconciliation\PlanPaymentReconciler;
use App\Services\Payments\Reconciliation\ReconciliationOutcome;
use App\Services\PlanPurchaseIntent;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModulePaymentFlows;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE RECONCILIATION SURFACE FOR PREVIOUSLY INITIATED PAYMENTS -- the command three separate
 * documents record as not existing (`F216-CAPTURE-RECOVERY-DESIGN.md` §1, P4-F128's finding, and
 * Phase 06 review finding F13).
 *
 * WHO MAY RUN IT. Whoever has a shell on the server, which is the platform operator. That is the
 * authentication: there is no route, no token and no request to forge. `PlanPaymentReconciler` is
 * the service and takes an optional actor, so an operator screen can call the same code later
 * without a second implementation.
 *
 * WITHOUT `--apply` IT CHANGES NOTHING. It asks each provider what became of each initiated
 * purchase and prints what an applying run WOULD do. Settling is one deliberate act per run by a
 * person who has read that output -- the same stance `config/payments.php` takes in shipping the
 * Paytm callback closed, and for the same reason: no live Paytm transaction has ever been verified
 * from this project.
 *
 * IT IS NOT SCHEDULED, on purpose. An unattended job that grants entitlements on a provider's
 * answer is a decision about how the platform is operated, and `routes/console.php` already records
 * that nothing in the repository can show whether the scheduler even runs. Scheduling it is the
 * owner's call.
 *
 * WHAT IT CANNOT RECONCILE, SAID HERE BECAUSE THE NAME PROMISES MORE THAN THE RECORDS ALLOW:
 *
 *   - a provider with no authoritative verifier (`CaptureVerifierRegistry`). Listed, never settled.
 *   - a storefront ORDER payment. `orders` carries no gateway reference and
 *     `order_gateway_references` has no production writer, so there is no durable proof that such
 *     a payment was initiated and nothing to reconcile it against. `F216-CAPTURE-RECOVERY-DESIGN.md`
 *     §6 names the two owner decisions that stand in front of that.
 *   - a payment taken through a MODULE (Donation, LotteryPrizes, Campaigns). Those modules verify
 *     nothing with a provider and keep no gateway reference, so there is nothing to ask and
 *     nothing to settle. What the host does record -- `module_payment_initiations` -- is listed
 *     here: every return to a switched-off application that was refused for want of an initiation,
 *     so that a payer whose money was taken is not turned away unseen. `--acknowledge` is how a
 *     person says they have dealt with one; it changes no payment, only the list.
 *   - an installation with NEITHER record. No proof that anything was initiated, so nothing to
 *     reconcile: exit 2, and nothing is asked of anybody.
 *
 * EXIT STATUS: 0 the run completed and nothing needs a person; 1 it completed and something does;
 * 2 it could not run.
 */
class ReconcileInitiatedPayments extends Command
{
    protected $signature = 'payments:reconcile
        {--apply : Settle what the provider confirms and record what does not match. Without it nothing is written.}
        {--provider= : Only intents recorded for this provider}
        {--reference= : Only the intent with this server-minted reference}
        {--minutes=30 : Leave an intent younger than this alone -- its buyer may still be at the provider}
        {--days=30 : Do not ask a provider about an intent older than this}
        {--acknowledge= : The id of one refused module payment return a person has dealt with. Needs --reason}
        {--acknowledge-intent= : The id of one plan intent a person has dealt with. Needs --reason. It will never be settled afterwards}
        {--reopen-intent= : The id of one acknowledged plan intent to put back in front of a person. Needs --reason}
        {--reason= : What was done about the refusal being acknowledged}';

    protected $description = 'Reconcile previously initiated plan payments against their provider (report only unless --apply)';

    public function handle(PlanPaymentReconciler $reconciler, CaptureVerifierRegistry $verifiers): int
    {
        $named = array_filter(
            ['acknowledge', 'acknowledge-intent', 'reopen-intent'],
            fn ($option) => $this->option($option) !== null
        );

        if (count($named) > 1) {
            /*
             * BOTH, WITH ONE `--reason`, IS AMBIGUOUS AND MUST NOT BE GUESSED. The two ids index
             * DIFFERENT tables, so a run that took the first and ignored the second would record one
             * operator's single sentence against one of two records they believed they had answered
             * -- and the acknowledgement they did not get is a payment that stays unsettleable or an
             * alarm that stays red, neither of which they would go back and check.
             *
             * Refused rather than ordered, because there is no defensible order: this is a typo or a
             * misreading of the two lists, and the operator is the only one who knows which.
             */
            $this->error(sprintf(
                'Pass one of --acknowledge, --acknowledge-intent or --reopen-intent, not %d: they name rows in different records and share one --reason.',
                count($named)
            ));
            $this->line('--acknowledge=<id> is a refused module payment return; --acknowledge-intent=<id> and --reopen-intent=<id> are plan payments.');

            return 2;
        }

        if ($this->option('acknowledge') !== null) {
            return $this->acknowledgeOneRefusal();
        }

        if ($this->option('acknowledge-intent') !== null) {
            return $this->acknowledgeOneIntent();
        }

        if ($this->option('reopen-intent') !== null) {
            return $this->reopenOneIntent();
        }

        if (! PlanPurchaseIntent::available() && ! ModulePaymentLedger::available()) {
            $this->error('Neither record of initiated payments is installed (plan_purchase_intents, module_payment_initiations).');
            $this->line('Without one there is no durable proof that any payment was initiated, so nothing can be reconciled.');

            return 2;
        }

        if (! PlanPurchaseIntent::available()) {
            $this->warn('The purchase-intent record (plan_purchase_intents) is not installed, so no plan payment can be reconciled.');

            return $this->reportRefusedModulePaymentReturns() > 0 ? 1 : 0;
        }

        $minutes = $this->wholeNumberOption('minutes', 30);
        $days = max(1, $this->wholeNumberOption('days', 30));
        $apply = (bool) $this->option('apply');
        $provider = $this->stringOption('provider');
        $reference = $this->stringOption('reference');

        $this->line($apply
            ? 'APPLYING: verified payments will be settled and discrepancies recorded.'
            : 'REPORT ONLY: nothing will be written. Re-run with --apply to act on what follows.');
        $this->line('Providers that can be asked authoritatively: ' . implode(', ', $verifiers->providers()));
        $this->newLine();

        /*
         * A MISTYPED `--provider` EXAMINED NOTHING AND REPORTED SUCCESS. Refused instead: the
         * independent financial review found `--provider=Paytn` indistinguishable from a clean run.
         */
        $recorded = PlanPurchaseIntent::recordedProviders();

        if ($provider !== null && $recorded !== [] && ! in_array($provider, $recorded, true)) {
            $this->error('No intent has ever been recorded for provider [' . $provider . '].');
            $this->line('Recorded providers: ' . implode(', ', $recorded));

            return 2;
        }

        $counts = [];
        $needsHuman = 0;
        $rows = [];

        try {
            $awaiting = PlanPurchaseIntent::awaitingReconciliation($minutes, $days, $provider, $reference);
        } catch (\Throwable $theListingFailed) {
            /* A run that could not read its own list must not report an empty one. */
            $this->error('The pending-intent list could not be read, so nothing was examined.');

            return 2;
        }

        foreach ($awaiting as $intent) {
            $outcome = $reconciler->reconcile($intent, $apply);

            $counts[$outcome->kind] = ($counts[$outcome->kind] ?? 0) + 1;
            $needsHuman += $outcome->needsHuman() ? 1 : 0;

            /*
             * WOULD_SETTLE IS NOT A CLEAN RESULT, and the first version exited 0 on it (with a test
             * asserting that). It means a provider CONFIRMS a capture and the purchaser has no
             * entitlement for it: money taken, nothing granted, and a person has to decide to run
             * `--apply`. A report that exits 0 on that is a report nobody has to read.
             */
            $needsHuman += $outcome->kind === ReconciliationOutcome::WOULD_SETTLE ? 1 : 0;

            $purchaser = is_numeric($intent->user_id) ? User::disableCache()->find((int) $intent->user_id) : null;

            $rows[] = [
                $intent->id,
                $intent->provider,
                $intent->reference,
                $intent->user_id,
                $intent->expected_amount . ' ' . $intent->currency,
                /* WHAT THE PURCHASER HOLDS NOW, so an operator can SEE the case the settlement
                   refuses: applying a days-old payment is not the same as recording it once
                   anything else has happened to their plan. Requested by the financial review,
                   which found the report gave a reader nothing to notice that with. */
                $purchaser === null ? '-' : trim(($purchaser->plan_id ?? '-') . ' until ' . ($purchaser->plan_expire_date ?? 'never')),
                $outcome->kind,
                $outcome->reason,
            ];
        }

        if ($rows === []) {
            $this->line('No pending intent falls inside the window examined.');
        } else {
            $this->table(['intent', 'provider', 'reference', 'purchaser', 'expected', 'holds now', 'outcome', 'detail'], $rows);
        }

        foreach ($counts as $kind => $count) {
            $this->line(sprintf('  %-18s %d', $kind, $count));
        }

        $unexamined = PlanPurchaseIntent::outsideReconciliationWindow($days);

        if ($unexamined > 0) {
            /*
             * Said, not hidden: a run that examined 30 days must not read as a run that examined
             * everything -- and it COUNTS, which the first version did not. The financial review
             * measured the consequence: a verified capture that failed to settle aged past the
             * window and the run went green with the payment still unsettled. An unexamined pending
             * intent is a question nobody asked, so the run is not clean.
             */
            $this->warn(sprintf(
                '%d pending intent(s) are older than %d day(s) and were NOT examined. Widen --days to ask about them.',
                $unexamined,
                $days
            ));

            $needsHuman += $unexamined;
        }

        $needsHuman += $this->reportWhatOnlyAPersonCanResolve();
        $needsHuman += $this->reportRefusedModulePaymentReturns();

        if (($counts[ReconciliationOutcome::NO_VERIFIER] ?? 0) > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d pending intent(s) belong to a provider that cannot be asked. Whether money moved is not knowable from here.',
                $counts[ReconciliationOutcome::NO_VERIFIER]
            ));
        }

        return $needsHuman > 0 ? 1 : 0;
    }

    /**
     * The first reader `PlanPurchaseIntent::unreconciled()` has ever had.
     *
     * Refused intents, consumed intents with no order behind them, and pending intents that carry
     * a reason: captured-or-possibly-captured payments that nothing here will act on. Listed with
     * the reason each one carries, in the server's own words.
     *
     * PARTITIONED BY WHETHER A PERSON HAS ANSWERED, and only the unanswered are returned -- the
     * financial review's finding 9. This count is added to the exit status, and before the
     * acknowledgement existed no state ever left `unreconciled()`, so one definite provider NO made
     * every later run exit 1 for ever. Since this is the only reader of "captured, nothing
     * delivered" in the application, that meant the next genuinely new loss arriving behind a red
     * status weeks old, which is the same as not reporting it.
     *
     * THE ACKNOWLEDGED ROWS ARE STILL PRINTED, under their own heading and with `line()` rather
     * than `warn()`. Dropping them from the output would make a loss disappear once somebody had
     * looked at it, which is the opposite of the point: the row is kept, the reason is kept, and
     * only the alarm stops.
     */
    private function reportWhatOnlyAPersonCanResolve(): int
    {
        $unreconciled = PlanPurchaseIntent::unreconciled(0);

        if ($unreconciled->isEmpty()) {
            return 0;
        }

        /* `?? null`, so a database whose table predates `2026_11_25_000200` reads every row as
           unanswered and this behaves exactly as it did before the column existed. */
        $answered = $unreconciled->filter(fn ($intent) => ($intent->acknowledged_at ?? null) !== null);
        $open = $unreconciled->reject(fn ($intent) => ($intent->acknowledged_at ?? null) !== null);

        $row = fn ($intent) => [
            $intent->id,
            $intent->provider,
            $intent->reference,
            $intent->user_id,
            $intent->status,
            $intent->plan_order_id ?? '-',
            $intent->provider_txn_id ?? '-',
            $intent->failure_reason ?? ($intent->status === PlanPurchaseIntent::CONSUMED ? 'consumed, with no plan order attached' : '-'),
        ];

        $columns = ['intent', 'provider', 'reference', 'purchaser', 'status', 'order', 'provider txn', 'reason'];

        if ($open->isNotEmpty()) {
            $this->newLine();
            $this->warn(sprintf('%d intent(s) need a person. Nothing here acts on them:', $open->count()));
            $this->table($columns, $open->map($row)->all());
            $this->line('When one has been dealt with: payments:reconcile --acknowledge-intent=<intent> --reason="what was done"');
        }

        if ($answered->isNotEmpty()) {
            $this->newLine();
            $this->line(sprintf('%d intent(s) already dealt with. Kept, and never settled from here:', $answered->count()));
            $this->table(
                /* `amount` IS HERE AND WAS NOT: this table is the only place a reader sees how much
                   money has been acknowledged away, and without it the figure lived solely in
                   `audit_logs`. Raised by independent review. */
                array_merge($columns, ['amount', 'dealt with', 'by', 'what was done']),
                $answered->map(fn ($intent) => array_merge($row($intent), [
                    trim((string) $intent->expected_amount . ' ' . (string) $intent->currency),
                    (string) $intent->acknowledged_at,
                    $intent->acknowledged_by ?? 'console',
                    (string) ($intent->acknowledged_reason ?? '-'),
                ]))->all()
            );
            $this->line('If one was answered by mistake: payments:reconcile --reopen-intent=<intent> --reason="why"');
        }

        return $open->count();
    }

    /**
     * RETURNS TO A SWITCHED-OFF APPLICATION THAT WERE REFUSED, and how many payments were still
     * under way when one was switched off.
     *
     * The first is a list for a person: each row is a request that reached a disabled module's
     * payment writer with no initiation to show. It may be a payer whose session expired after
     * their money was taken, a replay, or a probe -- this application cannot tell which, because
     * those modules verify nothing with a provider. So it says what it saw and how often, and
     * decides nothing.
     *
     * The second is a count and not a list, and does not fail the run: an initiation that never
     * came back is a form somebody abandoned far more often than it is money in transit.
     */
    private function reportRefusedModulePaymentReturns(): int
    {
        if (! ModulePaymentLedger::available()) {
            return 0;
        }

        $disabled = array_values(array_filter(
            ModulePaymentFlows::modules(),
            fn (string $module) => ModuleAvailability::isDisabled($module)
        ));

        $open = ModulePaymentLedger::openInitiationsFor($disabled);

        if ($open > 0) {
            $this->newLine();
            $this->line(sprintf(
                '%d payment(s) were started through a now switched-off application (%s) and have not come back. '
                . 'Each is honoured once if its payer returns; none is known to have been paid.',
                $open,
                implode(', ', $disabled)
            ));
        }

        $refused = ModulePaymentLedger::unacknowledgedRefusals();

        if ($refused->isEmpty()) {
            return 0;
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d refused payment return(s) to a switched-off application need a person. Nothing here acts on them:',
            $refused->count()
        ));

        $this->table(
            ['id', 'module', 'store', 'route', 'attempts', 'first seen', 'reason'],
            $refused->map(fn ($row) => [
                $row->id,
                $row->module,
                $row->store_id ?? '-',
                $row->route,
                $row->attempts,
                $row->created_at,
                $row->reason,
            ])->all()
        );

        $this->line('When one has been dealt with: payments:reconcile --acknowledge=<id> --reason="what was done"');

        return $refused->count();
    }

    /**
     * A PERSON SAYS ONE REFUSAL HAS BEEN DEALT WITH. The row is kept, with who-and-why; it leaves
     * the list. Nothing about any payment changes, and the reason is required for the same reason
     * the finance screen's is: whether a refused return was a real payer or a probe is a fact only
     * the person who checked holds, and it is unrecoverable a week later.
     */
    private function acknowledgeOneRefusal(): int
    {
        $claim = $this->validatedAcknowledgement('acknowledge', 'refusal');

        if (! is_array($claim)) {
            return $claim;
        }

        [$id, $reason] = $claim;

        $refusal = ModulePaymentLedger::refusal((int) $id);

        if ($refusal === null) {
            $this->error('No unacknowledged refusal has that id.');

            return 2;
        }

        /*
         * ONE TRANSACTION, so the row cannot leave the list without the audit row that says who
         * took it off, and cannot gain an audit row without leaving the list.
         *
         * THE CONDITIONAL UPDATE GOES FIRST, and the first version of this had it second: the audit
         * row was written, `acknowledge()` then matched nothing, no exception was raised, and the
         * transaction committed -- so a SECOND attempt on an already-acknowledged refusal wrote a
         * second audit row for an acknowledgement that did not happen. Caught by the test that
         * asserts exactly that. Claim first, and throw if the claim is lost, so the audit row goes
         * back with it.
         */
        try {
            $acknowledged = DB::transaction(function () use ($id, $reason, $refusal) {
                if (! ModulePaymentLedger::acknowledge((int) $id, $reason, null)) {
                    throw new \RuntimeException('the refusal was not acknowledged by this call');
                }

                AuditLog::create([
                    /* THE ACTOR IS THE OPERATOR WITH A SHELL ON THE SERVER, and this command has no
                       session to name them from. Recorded as the channel rather than as a null that
                       reads like "nobody": the honest answer is "whoever ran it", and an operator
                       screen calling the same service will pass a real id. */
                    'user_id' => null,
                    'store_id' => is_numeric($refusal->store_id ?? null) ? (int) $refusal->store_id : null,
                    'action' => 'payment.reconciliation.acknowledged',
                    'subject_type' => 'module_payment_initiation',
                    'subject_id' => (int) $id,
                    'old_values' => ['acknowledged_at' => null],
                    'new_values' => [
                        'module' => $refusal->module,
                        'route' => $refusal->route,
                        'attempts' => $refusal->attempts,
                        'refusal_reason' => $refusal->reason,
                        'acknowledged_reason' => $reason,
                        'acknowledged_by' => 'console (no session; whoever ran payments:reconcile)',
                    ],
                    'ip_address' => null,
                    'user_agent' => 'payments:reconcile (console)',
                ]);

                return true;
            });
        } catch (\Throwable $theAcknowledgementFailed) {
            /* Either the claim was already spent, or the audit row could not be written. Both roll
               the whole thing back, and both leave the refusal in the list -- which is the right
               direction for a record somebody still has to deal with. */
            $this->error('No unacknowledged refusal has that id, or the acknowledgement could not be recorded. Nothing was changed.');

            return 2;
        }

        if (! $acknowledged) {
            $this->error('No unacknowledged refusal has that id.');

            return 2;
        }

        $this->info(sprintf('Refusal %d acknowledged. The row is kept.', (int) $id));

        return 0;
    }

    /**
     * THE CHECKS BOTH ACKNOWLEDGEMENTS SHARE. Returns `[id, reason]`, or the exit code to return.
     *
     * One copy rather than two, because the reasoning behind each of these three is specific enough
     * that a second copy would drift from it:
     *
     *   - `ctype_digit`, NOT `is_numeric`: independent review pointed out that `is_numeric('1e2')`
     *     is true and `(int) '1e2'` is 100, so a typo could acknowledge a DIFFERENT row.
     *   - a reason is required, because whether a refused return was a real payer or a probe --
     *     or what was done about a captured payment -- is a fact only the person who checked holds,
     *     and it is unrecoverable a week later. The finance screen's reason field exists for this.
     *   - THE ENCODING IS CHECKED BEFORE ANYTHING MOVES. `audit_logs.new_values` is a `json` cast,
     *     so a reason carrying a malformed UTF-8 byte makes `json_encode()` return false and
     *     Eloquent throw -- and with the order these writers use, the acknowledgement would already
     *     have committed, leaving a row that had left the list with nothing recorded about why.
     *     This project measured exactly that at `StoreFinanceController:403-410`, where the
     *     beneficiary was the principal the audit exists to hold to account.
     *
     * @return array{0:string,1:string}|int
     */
    private function validatedAcknowledgement(string $option, string $noun)
    {
        $id = (string) $this->option($option);
        $reason = $this->stringOption('reason');

        /*
         * AND NO LEADING ZERO. `ctype_digit('0042')` is true and `(int) '0042'` is 42, so a padded
         * id silently acted on a different-looking row -- the same class of defect as the
         * `is_numeric('1e2')` one this check replaced, and it now guards an action that cannot be
         * undone. An id this command printed never has a leading zero, so refusing one costs
         * nothing and catches a paste that went wrong. Raised by independent review.
         */
        if (! ctype_digit($id) || (int) $id <= 0 || ($id !== '0' && str_starts_with($id, '0'))) {
            $this->error(sprintf('--%s takes the id of one %s, as listed by this command.', $option, $noun));

            return 2;
        }

        if ($reason === null) {
            $this->error(sprintf('--%s needs --reason: what was found, and what was done about it.', $option));

            return 2;
        }

        if (! mb_check_encoding($reason, 'UTF-8')) {
            $this->error('--reason must be valid UTF-8: it is written into an audit row and a malformed byte would lose it.');

            return 2;
        }

        return [$id, $reason];
    }

    /**
     * A PERSON SAYS ONE PLAN INTENT HAS BEEN DEALT WITH -- the financial review's finding 9.
     *
     * THIS IS NOT THE MODULE LEDGER'S ACKNOWLEDGEMENT WITH A DIFFERENT TABLE, and the difference is
     * worth stating because it is the dangerous part. A module refusal is a record of a request
     * that was turned away; acknowledging it changes no payment, as its own docblock says. A plan
     * intent is a PAYMENT, and acknowledging one makes it permanently unsettleable. The enforcement
     * is named precisely because an earlier version of this paragraph named the wrong place: both
     * conditional consumption UPDATEs in `PlanPurchaseIntent` carry `acknowledged_at IS NULL`, and
     * that is the one statement every door funnels through -- the three browser returns, this
     * command, and `PaymentCallbackController`. Atomic, rather than a convention each caller has to
     * remember.
     *
     * That is deliberate rather than incidental. The two honest resolutions for one of these rows
     * are "refunded it" and "granted the plan by hand", and NEITHER is visible to any verifier this
     * application has -- a refund issued after a capture need not change what a provider's status
     * endpoint reports. So an acknowledgement has to mean "do not settle this", or it would be an
     * invitation to grant a plan for money that has gone back.
     *
     * Which is why only an intent something has already been RECORDED against can be acknowledged
     * (`needingAPerson()`): a clean pending payment is not a finding, and silencing one would make
     * a captured payment permanently unsettleable with one console command.
     */
    private function acknowledgeOneIntent(): int
    {
        $claim = $this->validatedAcknowledgement('acknowledge-intent', 'plan intent');

        if (! is_array($claim)) {
            return $claim;
        }

        [$id, $reason] = $claim;

        if (! PlanPurchaseIntent::available()) {
            /*
             * NO RECORD AT ALL -- which is production, BY DESIGN (D-31, D-15 condition 9). Said
             * separately from the missing-column case because `acknowledgementAvailable()` is false
             * for both, and an earlier version answered this one with "plan_purchase_intents has no
             * acknowledged_at column. Run the migrations." Both sentences are false there: the table
             * is absent on purpose and running the migrations is not the remedy. Independent review
             * executed it. The accurate message further down cannot be reached, because this
             * dispatch runs before it.
             */
            $this->error('There is no plan purchase intent record on this installation, so no plan payment can be acknowledged.');
            $this->line('plan_purchase_intents does not exist here. On production that is deliberate (D-31); nothing is wrong.');

            return 2;
        }

        if (! PlanPurchaseIntent::acknowledgementAvailable()) {
            /* Table present, columns absent: a database migrated before `2026_11_25_000200`. Said
               in words rather than reported as "no such intent", which would be a lie about the id. */
            $this->error('This installation cannot record an acknowledgement yet: plan_purchase_intents has no acknowledged_at column.');
            $this->line('Run the migrations. Nothing else about reconciliation is affected.');

            return 2;
        }

        $intent = PlanPurchaseIntent::needingAPerson((int) $id);

        if ($intent === null) {
            $this->error('No unacknowledged plan intent that needs a person has that id.');
            $this->line('Only an intent something has been recorded against can be acknowledged: refused, consumed with no');
            $this->line('order behind it, or pending with a reason. Run the command first -- it either settles the payment or');
            $this->line('writes down why it could not, and the second is what there is to acknowledge.');

            return 2;
        }

        /* ONE TRANSACTION, CONDITIONAL UPDATE FIRST -- see `acknowledgeOneRefusal()` for what the
           other order cost: an audit row for an acknowledgement that did not happen. */
        try {
            $acknowledged = DB::transaction(function () use ($id, $reason, $intent) {
                if (! PlanPurchaseIntent::acknowledge((int) $id, $reason, null)) {
                    throw new \RuntimeException('the intent was not acknowledged by this call');
                }

                /*
                 * THE STATE IS RE-READ HERE, INSIDE THE TRANSACTION AND AFTER THE CLAIM. `$intent`
                 * was read before it, so every state field taken from it could describe a row that
                 * has since moved -- independent review found the audit row recording
                 * `status => 'pending'` for an intent that a concurrent settlement had consumed.
                 * The claim itself is now safe from that (`answerableById()` carries the state), but
                 * the RECORD still has to say what was true when the claim won, so it reads the row
                 * rather than the object. Falls back to `$intent` only if the re-read fails, which
                 * cannot happen on a row this transaction just updated.
                 */
                $claimed = PlanPurchaseIntent::byId((int) $id) ?? $intent;

                AuditLog::create([
                    'user_id' => null,
                    /*
                     * NO STORE. A plan purchase is between the platform and a merchant's account,
                     * not inside one of their stores -- `plan_purchase_intents` carries `user_id`
                     * and no `store_id` -- so the purchaser goes in `new_values` where it can be
                     * read, rather than into a column that would claim the wrong scope.
                     */
                    'store_id' => null,
                    'action' => 'payment.reconciliation.intent_acknowledged',
                    'subject_type' => 'plan_purchase_intent',
                    'subject_id' => (int) $id,
                    'old_values' => ['acknowledged_at' => null],
                    'new_values' => [
                        'provider' => $claimed->provider,
                        'reference' => $claimed->reference,
                        'purchaser_id' => $claimed->user_id,
                        'plan_id' => $claimed->plan_id,
                        'expected_amount' => $claimed->expected_amount,
                        'currency' => $claimed->currency,
                        'status' => $claimed->status,
                        'provider_txn_id' => $claimed->provider_txn_id,
                        'failure_reason' => $claimed->failure_reason,
                        /*
                         * BOTH OF THESE, because `plan_order_id` IS the finding for the
                         * "consumed with nothing to show" clause -- an auditor reading this row
                         * cannot tell that state from a settled one without it, and review found
                         * neither was recorded.
                         */
                        'plan_order_id' => $claimed->plan_order_id ?? null,
                        'consumed_at' => $claimed->consumed_at ?? null,
                        'acknowledged_reason' => $reason,
                        /* Always this sentence today: the only caller is a console command with no
                           session, so `acknowledged_by` on the row is null. The column exists for
                           the operator screen the docblock below anticipates, which will pass a
                           real id. */
                        'acknowledged_by' => 'console (no session; whoever ran payments:reconcile)',
                        'consequence' => 'this payment will not be settled by any door from here on',
                    ],
                    'ip_address' => null,
                    'user_agent' => 'payments:reconcile (console)',
                ]);

                return true;
            });
        } catch (\Throwable $theAcknowledgementFailed) {
            $this->error('That intent could not be acknowledged, or was acknowledged by somebody else first. Nothing was changed.');

            return 2;
        }

        if (! $acknowledged) {
            $this->error('No unacknowledged plan intent that needs a person has that id.');

            return 2;
        }

        /*
         * SAY WHICH PAYMENT IT WAS, because this cannot be undone and the ids are small sequential
         * integers printed in a table next to OTHER purchasers' findings. Nothing clears
         * `acknowledged_at` -- `acknowledge()` requires it to be null -- so a single-digit typo
         * permanently closes a different purchaser's payment under a reason describing somebody
         * else's. There is no confirmation prompt (the command must stay usable unattended), so the
         * mitigation is that the operator can SEE what they just did while they still remember what
         * they meant to do. `$intent` is already in hand.
         */
        $this->info(sprintf('Intent %d acknowledged. The row is kept, and it will not be settled from here.', (int) $id));
        $this->line(sprintf(
            '  %s %s | purchaser %s | plan %s | %s %s | was: %s',
            $intent->provider,
            $intent->reference,
            $intent->user_id,
            $intent->plan_id,
            $intent->expected_amount,
            $intent->currency,
            $intent->status
        ));

        return 0;
    }

    /**
     * PUT AN ACKNOWLEDGED PAYMENT BACK IN FRONT OF A PERSON -- the correction for a mistyped id.
     *
     * Independent review raised the irreversibility twice, and the comparison that justifies this is
     * not "should an acknowledgement be undoable" but "when somebody undoes one, is there a record".
     * Before this the only way back was a hand-written UPDATE, which needs the same shell access and
     * leaves nothing behind.
     *
     * IT SETTLES NOTHING. Clearing the columns only makes the row eligible for the reconciliation
     * command again, and that command still has to obtain an authoritative capture from the provider
     * before it writes anything. The worst a wrong reopen does is show the row to a person.
     */
    private function reopenOneIntent(): int
    {
        $claim = $this->validatedAcknowledgement('reopen-intent', 'acknowledged plan intent');

        if (! is_array($claim)) {
            return $claim;
        }

        [$id, $reason] = $claim;

        if (! PlanPurchaseIntent::acknowledgementAvailable()) {
            $this->error('This installation records no acknowledgements, so there is nothing to reopen.');

            return 2;
        }

        $intent = PlanPurchaseIntent::acknowledged((int) $id);

        if ($intent === null) {
            $this->error('No acknowledged plan intent has that id.');

            return 2;
        }

        try {
            $reopened = DB::transaction(function () use ($id, $reason, $intent) {
                if (! PlanPurchaseIntent::reopen((int) $id)) {
                    throw new \RuntimeException('the intent was not reopened by this call');
                }

                AuditLog::create([
                    'user_id' => null,
                    'store_id' => null,
                    'action' => 'payment.reconciliation.intent_reopened',
                    'subject_type' => 'plan_purchase_intent',
                    'subject_id' => (int) $id,
                    /* WHAT IS BEING UNDONE, in full, because clearing the columns destroys it. */
                    'old_values' => [
                        'acknowledged_at' => (string) $intent->acknowledged_at,
                        'acknowledged_by' => $intent->acknowledged_by,
                        'acknowledged_reason' => $intent->acknowledged_reason,
                    ],
                    'new_values' => [
                        'provider' => $intent->provider,
                        'reference' => $intent->reference,
                        'purchaser_id' => $intent->user_id,
                        'plan_id' => $intent->plan_id,
                        'expected_amount' => $intent->expected_amount,
                        'currency' => $intent->currency,
                        'status' => $intent->status,
                        'reopened_reason' => $reason,
                        'reopened_by' => 'console (no session; whoever ran payments:reconcile)',
                        'consequence' => 'this payment is eligible for reconciliation again; nothing has been settled',
                    ],
                    'ip_address' => null,
                    'user_agent' => 'payments:reconcile (console)',
                ]);

                return true;
            });
        } catch (\Throwable $theReopenFailed) {
            $this->error('That intent could not be reopened. Nothing was changed.');

            return 2;
        }

        if (! $reopened) {
            $this->error('No acknowledged plan intent has that id.');

            return 2;
        }

        $this->info(sprintf('Intent %d reopened. It needs a person again, and nothing has been settled.', (int) $id));
        $this->line(sprintf('  it had been dealt with on %s: %s', (string) $intent->acknowledged_at, (string) $intent->acknowledged_reason));

        return 0;
    }

    private function wholeNumberOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : $default;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
