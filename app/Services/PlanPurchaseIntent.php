<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * F229 / D-31 REQUIREMENT 1 — the writer and reader for the server-owned plan purchase intent.
 *
 * ONE RULE GOVERNS EVERY METHOD HERE, and it is the same rule `StockMovement` states: **absence of
 * evidence is reported as absence of evidence, never as permission.** The migration is
 * development-and-test only, so in production `available()` is false — and a caller that treated a
 * missing table as "no intent needed" would activate every plan unverified, which is the exact
 * inversion of what this exists for. So `verifyAndConsume()` answers `UNVERIFIABLE` when the record
 * is absent, and **requirement 8 makes UNVERIFIABLE a refusal**, not a pass.
 *
 * WHY A SERVICE AND NOT AN ELOQUENT MODEL. Deliberately raw `DB::table()`, for the three reasons
 * `StockMovement`'s header gives and one more:
 *
 *   - 79 of 95 models in `app/Models` are `Cachable`. This row is written immediately before the
 *     caller leaves for the provider and read immediately when they return, so a `Cachable` model
 *     would serve a pre-write row from cache — here that means reading "no intent" for an intent
 *     that was just created, i.e. refusing a legitimate purchase.
 *   - F218 measured the other half: a `Cachable` model's write FLUSHES the whole default store on a
 *     non-taggable driver, which erases the payment replay claims. A model here would add another
 *     flush to the payment path.
 *   - **and the consumption must be one atomic conditional UPDATE.** Eloquent's
 *     read-modify-write cannot express "move this row from pending to consumed only if it is still
 *     pending" without a race, and that race IS requirement 5's concurrent-callback case.
 *
 * MONEY IS NEVER COMPARED AS A FLOAT, AND NOT WITH `bccomp` EITHER.
 *
 * Requirement 3 forbids "imprecise floating-point comparisons". The obvious answer is `bccomp`,
 * and the first version of this file used it — **which would have fatalled in CI.** Measured:
 * `extension_loaded('bcmath')` is FALSE in this environment, `composer.json` declares no `ext-*`
 * requirement at all, and the extension list in `.github/workflows/ci.yml:70` and `:811` does not
 * include it. So the comparison is done on **scaled integer strings derived by string
 * manipulation** — exact, and dependent on nothing that has to be installed.
 *
 * MORE PRECISION THAN THE SCALE IS A MISMATCH, NOT A ROUNDING. If a provider reports
 * `499.00001` against an intent of `499.0000`, truncating to the scale would call them equal.
 * They are not, and in the direction that matters (a provider reporting less than was asked) a
 * silent truncation is the whole defect. So non-zero digits beyond the scale refuse.
 *
 * WHICH LAYER ACTUALLY DOES THE WORK, measured rather than claimed. This method has three
 * defences and they are not equally load bearing:
 *
 *   - the `is_numeric()` guard above refuses `abc`, `''` and `499,00`. **That is what stops the
 *     malformed cases**, and it would stop them under a float comparison too — worth knowing,
 *     because it means those test cases pin the parse and say nothing about the comparison.
 *   - `scaledInteger()` refuses precision beyond the scale rather than rounding it away.
 *   - the `!==` on scaled integers is what catches a difference **float equality cannot see**:
 *     `(float) '499.0000' == (float) '499.00000000000001'` is TRUE, and those are different money.
 *
 * Replacing the whole comparison with naive `(float) $a != (float) $b` was driven, and it leaves
 * every other amount case in `PlanPurchaseIntentTest` passing — only
 * `test_a_difference_float_equality_cannot_see_is_still_refused` fails. That test exists precisely
 * so the rule requirement 3 states is enforceable rather than merely asserted; a rule with no test
 * behind it is how `GatewayReference`'s `bool` came to mean three different things.
 *
 * THE ONE PLACE A FLOAT IS UNAVOIDABLE, stated rather than hidden: `plans.price` is a float
 * column, so the amount arrives as a float at intent CREATION. It is normalised to a fixed-scale
 * decimal string exactly once, there, and every comparison afterwards is string-to-string.
 */
class PlanPurchaseIntent
{
    /** The states an intent can be in. */
    public const PENDING = 'pending';
    public const CONSUMED = 'consumed';
    public const REFUSED = 'refused';

    /**
     * The outcomes of a verification attempt — requirement 8's vocabulary.
     *
     * Three states and not a bool, for the reason the `GatewayReference` audit recorded about
     * `claim()`: a boolean cannot carry "verified", "refused for a named reason" and "no answer is
     * available", and a caller that collapses the third into either of the first two is either
     * refusing real purchases or activating unverified ones.
     */
    public const VERIFIED = 'verified';
    public const REFUSED_MISMATCH = 'refused_mismatch';
    public const UNVERIFIABLE = 'unverifiable';

    /** The comparison scale for money. Four, matching the column, so nothing truncates silently. */
    private const MONEY_SCALE = 4;

    /**
     * A numeric string as a SCALED INTEGER STRING, by string manipulation only.
     *
     * No float and no `bcmath`: `bcmath` is not installed here, is not declared in
     * `composer.json`, and is absent from CI's extension list, so a `bccomp` would fatal rather
     * than compare. `'499.5'` at scale 4 becomes `'4995000'`, and two amounts are equal exactly
     * when their scaled integers are identical strings.
     *
     * Returns null for anything that is not a plain decimal number, and null for a value carrying
     * NON-ZERO precision beyond the scale — because truncating that would make a short payment
     * compare equal to the amount that was asked for.
     */
    private static function scaledInteger(string $amount, int $scale): ?string
    {
        $amount = trim($amount);

        if ($amount === '') {
            return null;
        }

        $negative = false;

        if ($amount[0] === '+' || $amount[0] === '-') {
            $negative = $amount[0] === '-';
            $amount = substr($amount, 1);
        }

        if (preg_match('/^(\\d*)(?:\\.(\\d*))?$/', $amount, $parts) !== 1) {
            return null;
        }

        $whole = ($parts[1] ?? '') === '' ? '0' : $parts[1];
        $fraction = $parts[2] ?? '';

        if ($whole === '0' && $fraction === '' && ! str_contains($amount, '0')) {
            return null;
        }

        if (strlen($fraction) > $scale) {
            /* Beyond the scale: zeros are harmless, anything else is a real difference. */
            if (rtrim(substr($fraction, $scale), '0') !== '') {
                return null;
            }

            $fraction = substr($fraction, 0, $scale);
        }

        $digits = ltrim($whole . str_pad($fraction, $scale, '0'), '0');

        if ($digits === '') {
            $digits = '0';
        }

        return ($negative && $digits !== '0' ? '-' : '') . $digits;
    }

    private const TABLE = 'plan_purchase_intents';

    /**
     * Is the record installed at all?
     *
     * **This is the method that keeps requirement 8 honest.** The migration is development and test
     * only, so in production this is false — and every caller MUST treat that as a refusal rather
     * than as permission to proceed unverified.
     */
    public static function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $theSchemaIsUnreadable) {
            return false;
        }
    }

    /**
     * A reference for one purchase, generated by the SERVER.
     *
     * Random rather than derived from the user, the plan or the clock. `time()` is what several of
     * these handlers currently use as their order id, and F228 measured why that fails: two posts
     * a second apart both landed because `plan_orders.order_id`'s UNIQUE constraint was keying on
     * the clock rather than on anything the provider said. A random reference cannot collide
     * between two purchases by the same buyer for the same plan in the same second, which is
     * exactly the case where a collision would merge two payments into one.
     *
     * 32 hex characters: short enough for the order-id fields these providers accept, long enough
     * that it cannot be guessed by a caller trying to bind to somebody else's intent.
     */
    public static function newReference(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Record what is being bought, before the caller leaves for the provider.
     *
     * Returns the reference to send onward, or **null** when the intent could not be recorded —
     * and a null MUST be treated as a reason not to start the purchase, because a payment taken
     * against an intent that does not exist is precisely the unverifiable capture requirement 5
     * asks us to avoid creating.
     *
     * @param  array{provider:string,user_id:mixed,plan_id:mixed,expected_amount:mixed,currency:string,coupon_id?:mixed,coupon_code?:mixed}  $intent
     */
    public static function create(array $intent): ?string
    {
        if (! self::available()) {
            return null;
        }

        $provider = trim((string) ($intent['provider'] ?? ''));
        $currency = trim((string) ($intent['currency'] ?? ''));

        if ($provider === '' || $currency === '') {
            Log::warning('Plan purchase intent not recorded: provider or currency missing.', [
                'provider' => $provider,
                'currency' => $currency,
            ]);

            return null;
        }

        if (! is_numeric($intent['user_id'] ?? null) || ! is_numeric($intent['plan_id'] ?? null)) {
            Log::warning('Plan purchase intent not recorded: no usable purchaser or plan.', [
                'provider' => $provider,
            ]);

            return null;
        }

        /* The amount is normalised to a fixed-scale decimal STRING here, once, so that nothing
           downstream has to decide how to read it. `number_format` rather than a cast: a cast
           would reintroduce the float this design exists to avoid. */
        if (! is_numeric($intent['expected_amount'] ?? null)) {
            Log::warning('Plan purchase intent not recorded: the expected amount is not numeric.', [
                'provider' => $provider,
            ]);

            return null;
        }

        $reference = self::newReference();

        try {
            DB::table(self::TABLE)->insert([
                'reference' => $reference,
                'provider' => $provider,
                'user_id' => (int) $intent['user_id'],
                'plan_id' => (int) $intent['plan_id'],
                'coupon_id' => isset($intent['coupon_id']) && is_numeric($intent['coupon_id'])
                    ? (int) $intent['coupon_id'] : null,
                'coupon_code' => isset($intent['coupon_code']) && is_scalar($intent['coupon_code'])
                    ? substr((string) $intent['coupon_code'], 0, 191) : null,
                'expected_amount' => number_format((float) $intent['expected_amount'], self::MONEY_SCALE, '.', ''),
                'currency' => substr($currency, 0, 8),
                'status' => self::PENDING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $reference;
        } catch (\Throwable $theIntentWasNotRecorded) {
            Log::warning('Plan purchase intent not recorded.', [
                'reason' => $theIntentWasNotRecorded->getMessage(),
                'provider' => $provider,
            ]);

            return null;
        }
    }

    /** The intent this reference names, or null. Read-only; it decides nothing. */
    public static function find(string $provider, string $reference)
    {
        if (! self::available() || $reference === '') {
            return null;
        }

        try {
            return DB::table(self::TABLE)
                ->where('provider', $provider)
                ->where('reference', $reference)
                ->first();
        } catch (\Throwable $theRecordIsUnreadable) {
            return null;
        }
    }

    /**
     * THE WHOLE POINT: verify a provider's attested figures against this intent, and consume it.
     *
     * Returns `[state, intent|null, reason]`. `VERIFIED` is the ONLY state a caller may activate
     * on. Requirement 8 makes both of the others a refusal, and the distinction between them is
     * for the operator reading the log, not for the control flow.
     *
     * The order of the checks is deliberate and each one is a requirement:
     *
     *   1. the record must exist at all                      — req 8 (UNVERIFIABLE, not a pass)
     *   2. the intent must exist for this provider+reference — req 4's binding
     *   3. it must belong to the authenticated purchaser     — req 5's cross-user isolation
     *   4. the currency must match                           — req 4
     *   5. the amount must match, by `bccomp`                — req 3 and 4
     *   6. it must still be pending, moved atomically        — req 5's replay and concurrency
     *
     * **Cross-user isolation is checked BEFORE the amount**, so a caller probing somebody else's
     * reference learns nothing about what that purchase was worth.
     *
     * **The consumption is last and atomic.** Every check above is a read; the single write is a
     * conditional UPDATE from `pending`. Two concurrent callbacks therefore both pass the reads and
     * exactly one wins the write — the other matches zero rows and is refused. Doing it the other
     * way round, consuming first and validating after, would leave a mismatched payment holding a
     * consumed intent with nothing left to refuse it.
     *
     * @param  string  $reportedAmount  the provider's figure, ALREADY normalised to this
     *                                  intent's currency scale by the provider's own normaliser.
     *                                  This method does not guess units — requirement 3.
     * @return array{0:string,1:object|null,2:string}
     */
    public static function verifyAndConsume(
        string $provider,
        string $reference,
        $authenticatedUserId,
        string $reportedAmount,
        string $reportedCurrency,
        $providerTxnId = null
    ): array {
        if (! self::available()) {
            return [self::UNVERIFIABLE, null, 'the purchase-intent record is not installed'];
        }

        $intent = self::find($provider, $reference);

        if ($intent === null) {
            return [self::UNVERIFIABLE, null, 'no intent exists for this provider and reference'];
        }

        if (! is_numeric($authenticatedUserId) || (int) $intent->user_id !== (int) $authenticatedUserId) {
            return [self::REFUSED_MISMATCH, $intent, 'the intent belongs to a different purchaser'];
        }

        if (strcasecmp(trim((string) $intent->currency), trim($reportedCurrency)) !== 0) {
            return [
                self::REFUSED_MISMATCH,
                $intent,
                'currency mismatch: intent ' . $intent->currency . ', reported ' . $reportedCurrency,
            ];
        }

        if (! is_numeric($reportedAmount)) {
            return [self::REFUSED_MISMATCH, $intent, 'the reported amount is not numeric'];
        }

        /* SCALED INTEGER STRINGS, compared with ===. Not floats (requirement 3 forbids it) and
           not bccomp (bcmath is not installed here, not declared in composer.json, and absent
           from CI's extension list -- it would fatal, not compare). */
        $expected = self::scaledInteger((string) $intent->expected_amount, self::MONEY_SCALE);
        $reported = self::scaledInteger((string) $reportedAmount, self::MONEY_SCALE);

        if ($expected === null || $reported === null) {
            return [
                self::REFUSED_MISMATCH,
                $intent,
                'an amount could not be compared exactly: intent ' . $intent->expected_amount
                . ', reported ' . $reportedAmount,
            ];
        }

        if ($expected !== $reported) {
            return [
                self::REFUSED_MISMATCH,
                $intent,
                'amount mismatch: intent ' . $intent->expected_amount . ', reported ' . $reportedAmount,
            ];
        }

        /* THE ONE WRITE, AND IT IS CONDITIONAL. `where status = pending` is what makes a second
           concurrent callback lose rather than duplicate. */
        try {
            $claimed = DB::table(self::TABLE)
                ->where('id', $intent->id)
                ->where('status', self::PENDING)
                ->update([
                    'status' => self::CONSUMED,
                    'consumed_at' => now(),
                    'provider_txn_id' => is_scalar($providerTxnId) ? substr((string) $providerTxnId, 0, 191) : null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $theConsumptionFailed) {
            return [self::UNVERIFIABLE, $intent, 'the intent could not be consumed: ' . $theConsumptionFailed->getMessage()];
        }

        if ($claimed !== 1) {
            return [self::REFUSED_MISMATCH, $intent, 'the intent was already consumed'];
        }

        return [self::VERIFIED, $intent, ''];
    }

    /**
     * Record WHY an intent was refused, so a captured payment that activated nothing is findable.
     *
     * Requirement 5 asks for safe recovery when payment succeeds and entitlement assignment does
     * not. Requirement 9 forbids new refund or settlement behaviour, and D-15 condition 3 separates
     * reversing a merchant's balance from a real refund to a customer. So "recovery" here is a
     * durable, reviewable record and **nothing more** — the plan-side twin of F186's
     * captured-payment-without-order, surfaced for a human with nothing acting on it.
     *
     * Never throws, and never changes the status of an intent that was already consumed: a
     * successful activation followed by a refused retry must not be re-labelled as a failure.
     */
    public static function recordRefusal(string $provider, string $reference, string $reason, $providerTxnId = null): void
    {
        if (! self::available() || $reference === '') {
            return;
        }

        try {
            DB::table(self::TABLE)
                ->where('provider', $provider)
                ->where('reference', $reference)
                ->where('status', '!=', self::CONSUMED)
                ->update([
                    'status' => self::REFUSED,
                    'failure_reason' => substr($reason, 0, 255),
                    'provider_txn_id' => is_scalar($providerTxnId) ? substr((string) $providerTxnId, 0, 191) : null,
                    'updated_at' => now(),
                ]);
        } catch (\Throwable $theRefusalWasNotRecorded) {
            Log::warning('Plan purchase intent refusal not recorded.', [
                'reason' => $theRefusalWasNotRecorded->getMessage(),
                'provider' => $provider,
            ]);
        }
    }

    /** Attach the PlanOrder a consumed intent produced, so the two are reconcilable. */
    public static function attachOrder(string $provider, string $reference, $planOrderId): void
    {
        if (! self::available() || $reference === '' || ! is_numeric($planOrderId)) {
            return;
        }

        try {
            DB::table(self::TABLE)
                ->where('provider', $provider)
                ->where('reference', $reference)
                ->whereNull('plan_order_id')
                ->update(['plan_order_id' => (int) $planOrderId, 'updated_at' => now()]);
        } catch (\Throwable $theOrderWasNotAttached) {
            Log::warning('Plan purchase intent order not attached.', [
                'reason' => $theOrderWasNotAttached->getMessage(),
                'provider' => $provider,
            ]);
        }
    }

    /**
     * CAPTURED PAYMENTS THAT ACTIVATED NOTHING — the query that makes this design worth having.
     *
     * An intent that was refused, or is still pending long after it was created, beside a provider
     * transaction id that exists: money the provider says it took, with no entitlement behind it.
     * A reviewer can see it. **Nothing here acts on it**, because acting would be the refund or
     * settlement behaviour requirement 9 forbids.
     */
    public static function unreconciled(int $olderThanMinutes = 30)
    {
        if (! self::available()) {
            return collect();
        }

        try {
            return DB::table(self::TABLE)
                ->where(function ($query) {
                    $query->where('status', self::REFUSED)
                        ->orWhere(function ($pending) {
                            $pending->where('status', self::PENDING)
                                ->whereNotNull('provider_txn_id');
                        });
                })
                ->where('created_at', '<', now()->subMinutes($olderThanMinutes))
                ->orderBy('id')
                ->get();
        } catch (\Throwable $theQueryFailed) {
            return collect();
        }
    }
}
