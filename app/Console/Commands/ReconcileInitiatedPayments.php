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
        {--reason= : What was done about the refusal being acknowledged}';

    protected $description = 'Reconcile previously initiated plan payments against their provider (report only unless --apply)';

    public function handle(PlanPaymentReconciler $reconciler, CaptureVerifierRegistry $verifiers): int
    {
        if ($this->option('acknowledge') !== null) {
            return $this->acknowledgeOneRefusal();
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
     */
    private function reportWhatOnlyAPersonCanResolve(): int
    {
        $unreconciled = PlanPurchaseIntent::unreconciled(0);

        if ($unreconciled->isEmpty()) {
            return 0;
        }

        $this->newLine();
        $this->warn(sprintf('%d intent(s) need a person. Nothing here acts on them:', $unreconciled->count()));

        $this->table(
            ['intent', 'provider', 'reference', 'purchaser', 'status', 'order', 'provider txn', 'reason'],
            $unreconciled->map(fn ($intent) => [
                $intent->id,
                $intent->provider,
                $intent->reference,
                $intent->user_id,
                $intent->status,
                $intent->plan_order_id ?? '-',
                $intent->provider_txn_id ?? '-',
                $intent->failure_reason ?? ($intent->status === PlanPurchaseIntent::CONSUMED ? 'consumed, with no plan order attached' : '-'),
            ])->all()
        );

        return $unreconciled->count();
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
        $id = (string) $this->option('acknowledge');
        $reason = $this->stringOption('reason');

        /* `ctype_digit`, NOT `is_numeric`: independent review pointed out that `is_numeric('1e2')`
           is true and `(int) '1e2'` is 100, so a typo could acknowledge a different row. */
        if (! ctype_digit($id) || (int) $id <= 0) {
            $this->error('--acknowledge takes the id of one refusal, as listed by this command.');

            return 2;
        }

        if ($reason === null) {
            $this->error('--acknowledge needs --reason: what was found, and what was done about it.');

            return 2;
        }

        /*
         * THE ENCODING IS CHECKED BEFORE THE ROW LEAVES THE LIST. `audit_logs.new_values` is a
         * `json` cast, so a reason carrying a malformed UTF-8 byte makes `json_encode()` return
         * false and Eloquent throw -- and the acknowledgement would already have committed, leaving
         * a refusal that had left the list with nothing recorded about why. This project measured
         * exactly that at `StoreFinanceController:403-410`, where the beneficiary was the principal
         * the audit exists to hold to account; independent review found it here too. Refused in the
         * validator, before anything moves.
         */
        if (! mb_check_encoding($reason, 'UTF-8')) {
            $this->error('--reason must be valid UTF-8: it is written into an audit row and a malformed byte would lose it.');

            return 2;
        }

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
