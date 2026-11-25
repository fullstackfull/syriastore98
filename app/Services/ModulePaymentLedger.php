<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * THE WRITER AND READER FOR `module_payment_initiations` — the host's own record that a payment was
 * initiated through a vendored module. The migration has the measurement and the status vocabulary.
 *
 * ONE RULE GOVERNS THE ABSENT TABLE, AND IT IS THE OPPOSITE OF `PlanPurchaseIntent`'S ON PURPOSE.
 *
 * `PlanPurchaseIntent` refuses when its table is missing: a plan activation without an intent is an
 * entitlement granted without proof, so absence must not be permission. Here the thing that would
 * be refused is a payer RETURNING FROM A GATEWAY, and the owner's instruction is that a payment
 * already initiated must not be lost. Without the table there is no way to tell a return from a new
 * transaction, so `available()` being false means the caller must leave the route exactly as it was
 * before this record existed — passed through, and said so in the log. It is the reasoning
 * `GatewayReference::claimState()` gives for `CLAIM_UNPROTECTED_TABLE_ABSENT`: the feature is
 * additive, and it cannot turn a missing migration into a refused payment.
 *
 * So the boundary this record supports is INERT wherever the migration has not been run, which by
 * the owner's standing decision includes production. That is stated in the boundary document
 * rather than discovered later.
 *
 * RAW `DB::table()`, for the reasons `PlanPurchaseIntent` gives: the row is written and then read
 * across two requests of one session, which a `Cachable` model can serve stale; and the completion
 * is ONE conditional UPDATE, which Eloquent's read-modify-write cannot express without a race.
 */
final class ModulePaymentLedger
{
    public const TABLE = 'module_payment_initiations';

    public const INITIATED = 'initiated';
    public const ABANDONED = 'abandoned';
    public const SUPERSEDED = 'superseded';
    public const COMPLETED = 'completed';
    public const REFUSED = 'refused';

    /**
     * How many refusal rows one module may gain in an hour.
     *
     * A disabled module's completion routes are ANONYMOUS, so "record every refusal" is an insert
     * anybody on the internet can cause. Repeats of one request are folded into one row
     * (`attempts`), and past this many DISTINCT refusals in an hour the rest are logged and not
     * stored. The number bounds a table, it does not decide anything about money.
     */
    public const REFUSALS_PER_MODULE_PER_HOUR = 200;

    /**
     * A REFERENCE THIS SERVICE WILL ACT ON: exactly 32 lower-case hex characters, which is what
     * `bin2hex(random_bytes(16))` produces and nothing else.
     *
     * FOUND BY `verify-module-payment-boundary-on-mysql.php`, AND IT IS THE P1-INV-18 SHAPE AGAIN.
     * MySQL's default collation is case-INSENSITIVE and SQLite's is BINARY, so on the engine
     * production runs `where('reference', $r)` matches a WIDER set than the value asked for: the
     * harness spent an initiation with `strtolower()` of its reference and the claim returned 1.
     * No SQLite test can see it -- CLAUDE.md records that difference as how a real defect survived
     * a green suite.
     *
     * Not currently exploitable: the reference is minted lower-case and lives in the payer's own
     * session, so nobody holds a differently-cased copy without already holding the real one. It is
     * closed anyway, and closed HERE rather than only in the column's collation, because this is
     * the control that holds whatever engine, charset or collation the installation runs -- and
     * because the claim must match on the value asked for rather than on a set the database
     * chooses. The migration narrows the column too; either alone would do, and the two disagree
     * about nothing.
     *
     * `hash_equals` for the canonical form, so the check itself is not a timing oracle.
     */
    public static function isWellFormedReference(mixed $reference): bool
    {
        if (! is_string($reference) || strlen($reference) !== 32) {
            return false;
        }

        return hash_equals(strtolower($reference), $reference) && ctype_xdigit($reference);
    }

    /**
     * NOT MEMOISED, DELIBERATELY, AND THE FIRST ATTEMPT AT MEMOISING IT WAS A LANDMINE.
     *
     * A review note observed that a payment request asks this up to three times and suggested a
     * per-process memo. One was written and it broke four tests in a way worth recording rather
     * than just reverting: the suite creates this table per test and DROPS it in one of them, so a
     * process-lifetime memo made every test that ran after that one believe the table was absent --
     * and the ledger then silently did nothing while the assertions read as the code's own
     * behaviour. That is the false-reading shape CLAUDE.md spends a section on (a cached answer
     * standing in for the table), and a memo the suite has to remember to reset is the same trap
     * with a reset method bolted on.
     *
     * `Schema::hasTable()` is one cheap catalogue query and this runs on a payment return, not in a
     * loop. The cost is real and small; the trap was neither.
     */
    public static function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable $theSchemaIsUnreadable) {
            return false;
        }
    }

    /** The session key that holds a payer's open reference for `$module`. */
    public static function sessionKey(string $module): string
    {
        return 'ss_module_payment.' . $module;
    }

    /**
     * Record that a payer submitted `$module`'s payment form, and return the reference to keep in
     * their session — or null when nothing could be recorded.
     *
     * `$supersedes` is the reference this session already held for the module, if any: starting a
     * second payment closes the first, so one session holds at most one open initiation per module
     * and a return is honoured at most once.
     */
    public static function recordInitiation(
        string $module,
        ?int $storeId,
        string $route,
        ?string $payerGuard,
        ?int $payerId,
        ?string $gateway,
        ?string $supersedes = null
    ): ?string {
        if (! self::available() || $module === '') {
            return null;
        }

        try {
            if (self::isWellFormedReference($supersedes)) {
                DB::table(self::TABLE)
                    ->where('reference', $supersedes)
                    ->where('module', $module)
                    ->where('status', self::INITIATED)
                    ->update(['status' => self::SUPERSEDED, 'updated_at' => now()]);
            }

            $reference = bin2hex(random_bytes(16));

            DB::table(self::TABLE)->insert([
                'reference' => $reference,
                'module' => substr($module, 0, 64),
                'status' => self::INITIATED,
                'store_id' => $storeId,
                'route' => substr($route, 0, 191),
                'payer_guard' => $payerGuard !== null ? substr($payerGuard, 0, 16) : null,
                'payer_id' => $payerId,
                'gateway' => self::label($gateway),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $reference;
        } catch (\Throwable $theInitiationWasNotRecorded) {
            /* Never thrown at the payer: a failure to RECORD must not become a failure to PAY. */
            /* THE DRIVER'S MESSAGE IS NOT LOGGED. A `QueryException` renders the SQL with its
               bindings, and one of those bindings is the reference that authenticates this payer's
               return -- so the message would put a live credential in the log. Independent review
               found it. The class is enough to diagnose with. */
            Log::warning('Module payment initiation not recorded.', [
                'module' => $module,
                'exception' => $theInitiationWasNotRecorded::class,
            ]);

            return null;
        }
    }

    /** The module's own handler rejected the form, so nothing was initiated after all. */
    public static function abandon(string $module, string $reference): void
    {
        if (! self::available() || ! self::isWellFormedReference($reference)) {
            return;
        }

        try {
            DB::table(self::TABLE)
                ->where('reference', $reference)
                ->where('module', $module)
                ->where('status', self::INITIATED)
                ->update(['status' => self::ABANDONED, 'updated_at' => now()]);
        } catch (\Throwable $theRowWasNotUpdated) {
            Log::warning('Module payment initiation could not be marked abandoned.', [
                'module' => $module,
                'exception' => $theRowWasNotUpdated::class,
            ]);
        }
    }

    /** Does this session's reference name an initiation that is still open? A read; it spends nothing. */
    public static function hasOpenInitiation(string $module, ?string $reference): bool
    {
        if (! self::available() || ! self::isWellFormedReference($reference)) {
            return false;
        }

        try {
            return DB::table(self::TABLE)
                ->where('reference', $reference)
                ->where('module', $module)
                ->where('status', self::INITIATED)
                ->exists();
        } catch (\Throwable $theRecordIsUnreadable) {
            return false;
        }
    }

    /**
     * SPEND THE INITIATION: one conditional UPDATE from `initiated`.
     *
     * True only when THIS call moved the row. Two returns for one initiation — a double click, a
     * replayed URL, a refresh of the confirmation page — both reach here, and the engine lets one
     * through. That is the whole of the idempotency, and it is the database's rather than this
     * class's, for the reason `GatewayReference` gives: a read-then-write races.
     */
    public static function completeInitiation(string $module, ?string $reference): bool
    {
        if (! self::available() || ! self::isWellFormedReference($reference)) {
            return false;
        }

        try {
            return DB::table(self::TABLE)
                ->where('reference', $reference)
                ->where('module', $module)
                ->where('status', self::INITIATED)
                ->update([
                    'status' => self::COMPLETED,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]) === 1;
        } catch (\Throwable $theClaimFailed) {
            Log::warning('Module payment initiation could not be completed.', [
                'module' => $module,
                'exception' => $theClaimFailed::class,
            ]);

            return false;
        }
    }

    /**
     * PUT A CLAIMED INITIATION BACK, because the request it was claimed for was refused downstream.
     *
     * FOUND BY INDEPENDENT REVIEW. The claim is taken BEFORE the vendored handler runs -- it has to
     * be, or two concurrent returns both reach the handler and it writes twice -- so a request that
     * is then refused by something further down the stack left a row saying `completed` for a
     * payment nobody recorded. `DisabledModuleRouteGuard::wasRefusedDownstream()` has what counts as
     * refused.
     *
     * Reopening does not reopen a spent payment: nothing was written, which is precisely why the row
     * is being put back. And it cannot resurrect a row some other request claimed, because the
     * `completed_at` it must match is the one this call's own claim wrote.
     */
    public static function reopenInitiation(string $module, ?string $reference): bool
    {
        if (! self::available() || ! self::isWellFormedReference($reference)) {
            return false;
        }

        try {
            return DB::table(self::TABLE)
                ->where('reference', $reference)
                ->where('module', $module)
                ->where('status', self::COMPLETED)
                ->update([
                    'status' => self::INITIATED,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]) === 1;
        } catch (\Throwable $theReopenFailed) {
            Log::warning('Module payment initiation could not be reopened after a refused request.', [
                'module' => $module,
            ]);

            return false;
        }
    }

    /**
     * The store an initiation was started in, or null when it was started in none (Campaigns) or
     * from a route carrying no slug.
     *
     * Read so a completion can be required to happen in the SAME store, which is the one thing the
     * reference alone does not say -- independent review's finding: a payer who started a donation
     * at store A could, after the switch-off, complete it at store B in the same session and the
     * vendored handler would write the row into store B. The row is the server's own record of
     * where the payment began, so comparing the request against it is a real check rather than the
     * caller's two values compared with each other.
     *
     * @return array{0:bool,1:?int} [the row exists, its store]
     */
    public static function initiationStore(string $module, ?string $reference): array
    {
        if (! self::available() || ! self::isWellFormedReference($reference)) {
            return [false, null];
        }

        try {
            $row = DB::table(self::TABLE)
                ->where('reference', $reference)
                ->where('module', $module)
                ->where('status', self::INITIATED)
                ->first();
        } catch (\Throwable $theRecordIsUnreadable) {
            return [false, null];
        }

        if ($row === null) {
            return [false, null];
        }

        return [true, is_numeric($row->store_id) ? (int) $row->store_id : null];
    }

    /**
     * A request reached a DISABLED module's completion route with no open initiation behind it.
     * Write that down, so that if it was a payer whose money was taken, somebody can find out.
     *
     * A repeat of the same request (same module, route and input hash) is counted on the existing
     * row rather than stored again, and a module that has collected
     * `REFUSALS_PER_MODULE_PER_HOUR` distinct refusals in the last hour gains no more until the
     * hour moves on — see that constant.
     */
    public static function recordRefusedCompletion(string $module, ?int $storeId, string $route, string $payloadHash, string $reason): void
    {
        if (! self::available() || $module === '') {
            return;
        }

        try {
            /*
             * THE STORE IS PART OF WHAT MAKES A REFUSAL "THE SAME ONE", and independent review found
             * why it has to be. `route` is the URI PATTERN, and the hash covers `$request->all()`,
             * which excludes route parameters -- so without the store one anonymous empty request to
             * any slug's return URL created the row that every later genuine refusal at every other
             * store then merely incremented, attributed to the first caller's store. The repeat
             * count is useful precisely because a person reads it as "this happened N times here".
             */
            $repeat = DB::table(self::TABLE)
                ->where('module', $module)
                ->where('route', substr($route, 0, 191))
                ->where('payload_hash', $payloadHash)
                ->where('status', self::REFUSED)
                ->whereNull('acknowledged_at')
                ->when($storeId === null, fn ($query) => $query->whereNull('store_id'))
                ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
                ->orderByDesc('id')
                ->first();

            if ($repeat !== null) {
                DB::table(self::TABLE)->where('id', $repeat->id)->update([
                    'attempts' => (int) $repeat->attempts + 1,
                    'updated_at' => now(),
                ]);

                return;
            }

            $recent = DB::table(self::TABLE)
                ->where('module', $module)
                ->where('status', self::REFUSED)
                ->where('created_at', '>=', now()->subHour())
                ->count();

            if ($recent >= self::REFUSALS_PER_MODULE_PER_HOUR) {
                Log::warning('Module payment refusal not stored: the hourly bound for this module is reached.', [
                    'module' => $module,
                    'route' => $route,
                ]);

                return;
            }

            DB::table(self::TABLE)->insert([
                'reference' => bin2hex(random_bytes(16)),
                'module' => substr($module, 0, 64),
                'status' => self::REFUSED,
                'store_id' => $storeId,
                'route' => substr($route, 0, 191),
                'payload_hash' => $payloadHash,
                'attempts' => 1,
                'reason' => substr($reason, 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $theRefusalWasNotRecorded) {
            Log::warning('Module payment refusal not recorded.', [
                'module' => $module,
                'exception' => $theRefusalWasNotRecorded::class,
            ]);
        }
    }

    /**
     * Refusals nobody has looked at yet. Platform-wide, for the operator: they are about a module
     * that was switched off for the whole platform.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    public static function unacknowledgedRefusals()
    {
        if (! self::available()) {
            return collect();
        }

        try {
            return DB::table(self::TABLE)
                ->where('status', self::REFUSED)
                ->whereNull('acknowledged_at')
                ->orderBy('id')
                ->get();
        } catch (\Throwable $theQueryFailed) {
            return collect();
        }
    }

    /**
     * Initiations of `$modules` that were started and never came back: payments that were in
     * flight when the application was switched off, or forms somebody walked away from. Which of
     * the two cannot be known from here, so they are counted and not judged.
     *
     * @param  list<string>  $modules
     */
    public static function openInitiationsFor(array $modules): int
    {
        if (! self::available() || $modules === []) {
            return 0;
        }

        try {
            return DB::table(self::TABLE)
                ->whereIn('module', $modules)
                ->where('status', self::INITIATED)
                ->count();
        } catch (\Throwable $theQueryFailed) {
            return 0;
        }
    }

    /**
     * A person has looked at one refusal and says what it was. The row stays — disabling is not
     * deletion and neither is acknowledging — and it leaves the list.
     */
    public static function acknowledge(int $id, string $reason, ?int $actorId): bool
    {
        if (! self::available() || trim($reason) === '') {
            return false;
        }

        try {
            return DB::table(self::TABLE)
                ->where('id', $id)
                ->where('status', self::REFUSED)
                ->whereNull('acknowledged_at')
                ->update([
                    'acknowledged_at' => now(),
                    'acknowledged_by' => $actorId,
                    'acknowledged_reason' => substr(trim($reason), 0, 255),
                    'updated_at' => now(),
                ]) === 1;
        } catch (\Throwable $theAcknowledgementFailed) {
            return false;
        }
    }

    /** One refusal row, for the command to show what it is about to acknowledge. */
    public static function refusal(int $id): ?object
    {
        if (! self::available()) {
            return null;
        }

        try {
            return DB::table(self::TABLE)
                ->where('id', $id)
                ->where('status', self::REFUSED)
                ->first();
        } catch (\Throwable $theRecordIsUnreadable) {
            return null;
        }
    }

    /** A caller-supplied label, reduced to something safe to print in a terminal and a log. */
    private static function label(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = preg_replace('/[^A-Za-z0-9 _.-]/', '', $value) ?? '';

        return $clean === '' ? null : substr($clean, 0, 64);
    }
}
