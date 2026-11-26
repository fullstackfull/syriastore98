<?php

namespace Tests\Unit\Saas;

use App\Services\SaasRevenueFigures;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-192 / SAAS-007 — WHICH `plan_orders.payment_status` SPELLINGS MEAN "THE PLATFORM WAS PAID".
 * =================================================================================================
 *
 * `plan_orders.payment_status` is `string(100)` with no enumeration anywhere, written by FIFTY-ONE
 * files, in EIGHT spellings, four of which mean the money arrived:
 *
 *     success      26 writers   succeeded   18 writers   Succeeded   9 writers   Approved   1
 *     Pending       1 writer    pending      1 writer    Rejected    1 writer    Failed     1
 *
 * A platform-revenue figure is therefore only as right as that list, and a list that was typed out
 * once from a grep is a list that goes stale the first time a gateway is added. This census
 * re-derives it from the writers themselves and fails on a spelling nobody has classified — naming
 * the file that writes it.
 *
 * WHY FAILING IS THE RIGHT ANSWER AND A DEFAULT IS NOT. An unclassified spelling could be either
 * kind, and both wrong answers are bad in a way that is invisible on screen: treating a new
 * `completed` as unsettled silently removes real money from platform revenue, and treating a new
 * `chargeback` as settled silently adds money the platform no longer has. Neither shows up as an
 * error; both show up as a slightly wrong number that somebody will plan against.
 *
 * THE SCAN IS KEYED ON THE WRITERS, NOT ON A WORD. CLAUDE.md records four censuses in this project
 * that missed what they claimed to cover because they were keyed on spelling — uri words, `Route::
 * post` lines, a uri prefix, a method list somebody happened to look at. This one selects files by
 * the presence of a `new PlanOrder` / `PlanOrder::create` construction and then reads the
 * `payment_status` literals in them, so a new gateway controller is in scope the moment it writes
 * a plan order.
 *
 * ITS KNOWN IMPRECISION, STATED RATHER THAN HIDDEN: several of those files also write a STORE
 * order's status, so a literal in one of them is not necessarily a plan-order status. That is why
 * the map below CLASSIFIES every spelling instead of asserting that each is a plan status — a
 * store-order spelling appearing here gets a classification and a note, and the next person still
 * has to look at it.
 *
 * COMMENTS ARE STRIPPED, through the shared trait, and here it is load-bearing: `SaasRevenueFigures`
 * quotes all four settled spellings in its own header while explaining this very census, and the
 * docblock of `PlatformRevenueFiguresTest` lists them too.
 */
final class PlanOrderSettledStatusCensusTest extends TestCase
{
    use StripsSourceComments;

    /**
     * EVERY SPELLING THE WRITERS CURRENTLY CONTAIN, AND WHAT IT MEANS.
     *
     * `true` means "the platform was paid", which is exactly the set
     * `SaasRevenueFigures::SETTLED_PLAN_STATUSES` holds, lower-cased. Keep the two in agreement:
     * the test below asserts they are.
     *
     * @var array<string, bool>
     */
    private const CLASSIFIED = [
        // Settled. 26 + 18 + 9 writers; the case difference is the same event written by
        // different gateway handlers, which is why the predicate folds case rather than listing
        // both spellings as separate truths.
        'success' => true,
        'succeeded' => true,
        // `BankTransferController::orderapprove` — the operator accepting an uploaded receipt.
        // It is a human decision rather than a gateway callback, and it is the point at which the
        // platform treats the money as received: the same method calls `assignPlan()`.
        'approved' => true,

        // Not settled.
        'pending' => false,     // awaiting the payer or the operator
        'rejected' => false,    // the operator refused the uploaded receipt
        'failed' => false,      // the gateway declined

        /*
         * THESE TWO ARE MISSPELLED IN THE SHIPPED PRODUCT, and no literal scan can find them:
         * `SspayController` and `ToyyibpayController` assign `$statuses = 'Fail'` / `'pandding'`
         * to a local and write the LOCAL into the column, so the regex above sees nothing. They
         * were invisible to this census until `nonLiteralWrites()` resolved the locals by hand.
         *
         * Both are unsettled, so no money is misstated today -- `SETTLED_PLAN_STATUSES` is an
         * allowlist and anything outside it is excluded by construction. They are listed anyway
         * because this census's claim is that it enumerates EVERY spelling the application writes,
         * and a claim with two known exceptions is not that claim.
         */
        'fail' => false,        // Sspay, Toyyibpay -- `$statuses = 'Fail'`
        'pandding' => false,    // Sspay, Toyyibpay -- `$statuses = 'pandding'`, sic
    ];

    /**
     * EVERY `payment_status` WRITE WHOSE VALUE IS NOT A LITERAL, DISPOSITIONED BY HAND.
     *
     * The literal regex in `spellings()` is blind to `$order->payment_status = $statuses` and to
     * `'payment_status' => $result['data']['status']`, and those are the writes most able to put a
     * word nobody classified into the column: a gateway's own vocabulary, straight through. Six
     * mechanisms exist; they are keyed by the EXPRESSION rather than by file or line, because the
     * expression is the mechanism and line numbers drift.
     *
     * `receivers` IS NOT DECORATION. These controllers write BOTH a storefront `Order` and a
     * `PlanOrder`, and only the second is platform revenue. The largest mechanism here --
     * `gatewayReturnPaymentStatus()`, seven controllers -- returns `Paid`, which is NOT in
     * `SETTLED_PLAN_STATUSES` and does not need to be, because it is written to `orders` and never
     * to `plan_orders`. If one of those calls is ever moved onto a plan order, platform revenue
     * drops that payment silently. This row is the tripwire for exactly that.
     *
     * `unresolved` means the backward walk did not reach a construction within 150 lines, not that
     * the write is unclassified: each one is named below with what it was measured to be by hand.
     */
    private const NON_LITERAL_WRITES = [
        '$statuses' => [
            'receivers' => ['PlanOrder'],
            'writes_plan_orders' => true,
            'resolves_to' => ['success', 'Fail', 'pandding'],
            'why' => 'Four controllers assign a local immediately above the write. Midtrans and '
                . 'Paypal only ever assign `success`; Sspay and Toyyibpay assign one of `Fail`, '
                . '`pandding` or `success`, one per branch. The two misspellings are classified '
                . 'above for this reason.',
        ],
        '$result[\'data\'][\'status\']' => [
            'receivers' => ['PlanOrder'],
            'writes_plan_orders' => true,
            'resolves_to' => ['success'],
            'why' => 'Paystack writes THE GATEWAY\'S OWN WORD into the column. Its vocabulary is '
                . 'success|failed|abandoned|reversed|ongoing|pending|processing|queued, but the '
                . 'branch is guarded: `$paystackAccepted = $paystackOutcome[\'paid\'] && '
                . '$paystackOutcome[\'reference_matches\']`, and `paid` is '
                . '`$envelopeOk && $state === \'success\'`, so only `success` reaches the write. '
                . 'The guard is what makes this safe, not the column.',
        ],
        '$payment->status == \'paid\' ? \'success\' : \'failed\'' => [
            'receivers' => ['PlanOrder'],
            'writes_plan_orders' => true,
            'resolves_to' => ['success', 'failed'],
            'why' => 'Mollie translates its own `paid` into this codebase\'s `success` at the write '
                . 'itself, which is why `paid` never appears in the column from this gateway.',
        ],
        'isset($data[\'status\']) ? $data[\'status\'] : \'succeeded\'' => [
            'receivers' => ['PlanOrder'],
            'writes_plan_orders' => true,
            'resolves_to' => ['succeeded'],
            'why' => '`$data` is a Stripe `Charge::create` response, whose status is '
                . 'succeeded|pending|failed; the branch is guarded by '
                . '`$data[\'paid\'] == 1 && $data[\'captured\'] == 1`, and the skipped-charge path '
                . '(a free plan or a 100%% coupon) sets `succeeded` itself before the write.',
        ],
        '$this->gatewayReturnPaymentStatus(' => [
            // AuthorizeNet\'s construction is further than 150 lines above its write; read by
            // hand, it sets `final_price`, `delivery_id` and `delivered_status` on the same
            // object, none of which are `plan_orders` columns. It is a storefront `Order`.
            'receivers' => ['Order', 'unresolved'],
            'writes_plan_orders' => false,
            'resolves_to' => ['Paid', 'Pending'],
            'why' => 'NOT PLATFORM REVENUE. Seven gateway controllers call it on a STOREFRONT '
                . '`Order`; it returns `PaymentVerificationService::STATUS_PAID` (`Paid`) or '
                . '`STATUS_PENDING` (`Pending`). `Paid` folds to `paid`, which is deliberately '
                . 'absent from `SETTLED_PLAN_STATUSES` -- correct while nothing writes it to '
                . '`plan_orders`, and a silent loss of revenue the day something does.',
        ],
        '$previousStatus' => [
            'receivers' => ['unresolved'],
            'writes_plan_orders' => false,
            'resolves_to' => [],
            'why' => 'Not a column write at all. `BankTransferController` records the pre-approval '
                . 'status in an `AuditLog::record()` payload, which is why the walk finds no '
                . 'construction: the enclosing call is `AuditLog::record`, not a model create.',
        ],
    ];

    /** @return list<string> the plan-order writer files, repo-relative */
    private function writerFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $code = $this->sourceWithoutComments($relative);

            if (preg_match('/(new\s+PlanOrder|PlanOrder::create|PlanOrder::insert)/', $code) === 1) {
                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, list<string>> lower-cased spelling => the files that write it */
    private function spellings(): array
    {
        $found = [];

        foreach ($this->writerFiles() as $relative) {
            $code = $this->sourceWithoutComments($relative);

            preg_match_all(
                '/payment_status[\'"]?\s*(?:=>|=)\s*[\'"]([^\'"]*)[\'"]/',
                $code,
                $matches
            );

            foreach ($matches[1] as $raw) {
                $key = strtolower(trim($raw));

                if ($key === '') {
                    continue;
                }

                $found[$key][] = $relative;
            }
        }

        ksort($found);

        return $found;
    }

    /** The premise: the scan really reads the gateway handlers. */
    public function test_the_census_finds_the_plan_order_writers(): void
    {
        $writers = $this->writerFiles();

        $this->assertGreaterThan(40, count($writers), sprintf(
            'Only %d plan-order writer files were found. 51 were measured when this ratchet was '
            . 'written, so the selection has broken and every assertion below is vacuous.',
            count($writers)
        ));

        $this->assertContains('app/Http/Controllers/BankTransferController.php', $writers,
            'the bank-transfer handler is the one writer whose settled spelling is a human '
            . 'decision rather than a gateway callback, so a scan that misses it misses the '
            . 'spelling that is hardest to guess');

        $spellings = $this->spellings();

        foreach (['success', 'succeeded', 'approved'] as $known) {
            $this->assertArrayHasKey($known, $spellings,
                "the spelling '{$known}' is written by the application but the scan did not find "
                . 'it, so this census can no longer see what it is for');
        }
    }

    /**
     * THE RATCHET. Every spelling in the writers is classified, and the classification agrees with
     * what the figures class actually treats as money.
     */
    public function test_every_status_spelling_in_the_writers_is_classified(): void
    {
        $unclassified = [];

        foreach ($this->spellings() as $spelling => $files) {
            if (array_key_exists($spelling, self::CLASSIFIED)) {
                continue;
            }

            $unclassified[] = sprintf('%s (written by %s)', $spelling,
                implode(', ', array_unique(array_map('basename', $files))));
        }

        sort($unclassified);

        $this->assertSame([], $unclassified, sprintf(
            "%d `plan_orders.payment_status` spelling(s) are written by the application and "
            . "classified nowhere. Platform revenue is the sum of the SETTLED ones, so an "
            . "unclassified spelling either removes real money from the figure or adds money the "
            . "platform does not have — silently, in both directions. Decide what each one means, "
            . "add it to CLASSIFIED, and if it is settled add it to "
            . "SaasRevenueFigures::SETTLED_PLAN_STATUSES as well:\n  %s",
            count($unclassified),
            implode("\n  ", $unclassified)
        ));
    }

    public function test_the_figures_class_treats_exactly_the_settled_spellings_as_money(): void
    {
        $expected = array_keys(array_filter(self::CLASSIFIED));
        sort($expected);

        $actual = SaasRevenueFigures::SETTLED_PLAN_STATUSES;
        sort($actual);

        $this->assertSame($expected, $actual,
            'the classification in this census and the predicate the figures actually use have '
            . 'drifted apart, so the census is no longer measuring the thing it guards');
    }

    /** The predicate is lower-case throughout, because the writers are not. */
    public function test_the_settled_set_is_lower_cased_so_the_predicate_can_fold_case(): void
    {
        foreach (SaasRevenueFigures::SETTLED_PLAN_STATUSES as $status) {
            $this->assertSame(strtolower($status), $status,
                "'{$status}' is not lower-cased. The figures class folds the column with lower() "
                . 'before comparing — SQLite compares case-SENSITIVELY by default while MySQL '
                . 'does not, and CLAUDE.md records that difference hiding real defects — so a '
                . 'mixed-case entry here would never match on either engine.');
        }
    }

    /**
     * `Succeeded` IS NOT A SEPARATE TRUTH, and this is the case the fold exists for.
     *
     * Nine writers spell it with a capital letter. On SQLite a case-sensitive `whereIn` would drop
     * all nine; on MySQL's default collation it would keep them. A figure that differs between the
     * suite's engine and production is worse than one that is wrong on both.
     */
    public function test_the_capitalised_spellings_fold_onto_the_set(): void
    {
        foreach (['Succeeded', 'SUCCESS', 'Approved'] as $asWritten) {
            $this->assertContains(strtolower($asWritten), SaasRevenueFigures::SETTLED_PLAN_STATUSES,
                "'{$asWritten}' is written by the application and does not fold onto the settled "
                . 'set');
        }
    }

    /**
     * EVERY `payment_status` WRITE WHOSE VALUE IS NOT A LITERAL, with the receiver it writes to.
     *
     * The literal scanner above cannot see `$order->payment_status = $statuses` or
     * `'payment_status' => $result['data']['status']`, and those are the writes most able to put a
     * spelling nobody classified into the column -- a gateway's own vocabulary, straight through.
     *
     * The receiver matters as much as the value: these gateway controllers write BOTH a storefront
     * `Order` and a `PlanOrder`, and only the second is platform revenue. The walk back to the
     * nearest `$var = new X` is how that is decided, and it is why this returns the receiver rather
     * than just the expression.
     *
     * @return array<string, array<string, list<string>>> expression => receiver => files
     */
    private function nonLiteralWrites(): array
    {
        $found = [];

        foreach ($this->writerFiles() as $relative) {
            // COMMENT-STRIPPED, and that is not a formality: a first-pass probe of this same
            // question read `$result[\'data\'][\'status\']` out of a PARAGRAPH EXPLAINING the
            // write and reported a gateway-controlled value that no line of code writes. CLAUDE.md
            // records four instances of a census satisfied or broken by prose about the thing it
            // measures; this would have been the fifth.
            $lines = explode("\n", $this->sourceWithoutComments($relative));

            foreach ($lines as $i => $line) {
                $expression = null;
                $variable = null;

                if (preg_match('/\$(\w+)->payment_status\s*=(?!=)\s*([^;\r\n]+)/', $line, $m) === 1) {
                    $variable = $m[1];
                    $expression = trim($m[2]);
                } elseif (preg_match('/[\'"]payment_status[\'"]\s*=>\s*([^,\r\n]+)/', $line, $m) === 1) {
                    $expression = rtrim(trim($m[1]), ',');
                }

                if ($expression === null || $expression === '') {
                    continue;
                }

                // A quoted value is a literal and the scanner above already has it.
                if ($expression[0] === "'" || $expression[0] === '"') {
                    continue;
                }

                $found[$expression][$this->receiverOf($lines, $i, $variable)][] = $relative;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * Which model the write lands on: the nearest preceding construction of the same variable, or
     * the nearest enclosing `X::create(`/`X::insert(` for an array write.
     *
     * Returns `unresolved` rather than guessing. An unresolved write is not a failure by itself --
     * the disposition map records what it was measured to be by hand -- but it must not be silently
     * read as the safe answer.
     */
    private function receiverOf(array $lines, int $at, ?string $variable): string
    {
        for ($j = $at; $j >= max(0, $at - 150); $j--) {
            if ($variable !== null
                && preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*new\s+(\w+)/', $lines[$j], $m) === 1) {
                return $m[1];
            }

            if ($variable === null && preg_match('/(\w+)::(?:create|insert)\s*\(/', $lines[$j], $m) === 1) {
                return $m[1];
            }
        }

        return 'unresolved';
    }

    /**
     * THE RATCHET FOR THE WRITES THE LITERAL SCAN CANNOT SEE. Both directions.
     *
     * A new `$order->payment_status = $someGatewayField` fails this case until somebody states what
     * the field can hold and which model it lands on. And a mechanism that is REMOVED fails it too,
     * so a stale disposition cannot sit here asserting something about code that no longer exists --
     * which is how a docblock in this repository ended up claiming a script was never registered
     * while it was registered twice.
     */
    public function test_every_non_literal_payment_status_write_is_dispositioned(): void
    {
        $found = $this->nonLiteralWrites();

        $undispositioned = [];

        foreach ($found as $expression => $byReceiver) {
            if (! array_key_exists($expression, self::NON_LITERAL_WRITES)) {
                $undispositioned[] = sprintf('%s (in %s)', $expression,
                    implode(', ', array_unique(array_map('basename', array_merge(...array_values($byReceiver))))));

                continue;
            }

            $declared = self::NON_LITERAL_WRITES[$expression]['receivers'];

            foreach (array_keys($byReceiver) as $receiver) {
                $this->assertContains($receiver, $declared, sprintf(
                    'the write `%s` now lands on `%s`, which its disposition does not list (%s). '
                    . 'The receiver decides whether the value reaches PLATFORM revenue or a '
                    . 'storefront order, so a changed receiver changes the answer.',
                    $expression, $receiver, implode(', ', $declared)
                ));
            }
        }

        sort($undispositioned);

        $this->assertSame([], $undispositioned, sprintf(
            "%d `payment_status` write(s) take a value this census cannot read, and nobody has said "
            . "what the value can be. A gateway's own vocabulary reaching `plan_orders` unclassified "
            . "removes real money from platform revenue, silently:\n  %s",
            count($undispositioned),
            implode("\n  ", $undispositioned)
        ));

        $stale = array_values(array_diff(array_keys(self::NON_LITERAL_WRITES), array_keys($found)));

        $this->assertSame([], $stale, sprintf(
            "%d disposition(s) describe a write that no longer exists. Delete the row:\n  %s",
            count($stale),
            implode("\n  ", $stale)
        ));
    }

    /**
     * AND THE VALUES THEMSELVES ARE CLASSIFIED, which is the assertion that actually guards money.
     *
     * For every mechanism measured to write a `PlanOrder`, each value it can produce must fold onto
     * a spelling this census classifies -- the same requirement the literal spellings already meet.
     * Without this, a disposition could truthfully record `resolves_to => ['paid']` and the figure
     * would still drop it.
     */
    public function test_every_value_a_non_literal_write_can_put_in_plan_orders_is_classified(): void
    {
        $unclassified = [];

        foreach (self::NON_LITERAL_WRITES as $expression => $disposition) {
            if ($disposition['writes_plan_orders'] !== true) {
                continue;
            }

            foreach ($disposition['resolves_to'] as $value) {
                $folded = strtolower(trim($value));

                if (! array_key_exists($folded, self::CLASSIFIED)) {
                    $unclassified[] = sprintf('%s (from `%s`, folds to `%s`)', $value, $expression, $folded);
                }
            }
        }

        sort($unclassified);

        $this->assertSame([], $unclassified, sprintf(
            "%d value(s) a non-literal write can put into `plan_orders.payment_status` are "
            . "classified nowhere:\n  %s",
            count($unclassified),
            implode("\n  ", $unclassified)
        ));
    }

    /**
     * THE POPULATION THIS CENSUS COVERS IS THE UNION OF THE TWO SCANS, and saying so is the point.
     *
     * `spellings()` alone is not the set of words the application writes -- it missed `Fail` and
     * `pandding` entirely. Anyone reading the literal census as complete would conclude that two
     * spellings in the shipped product do not exist. This case states the union explicitly so the
     * incompleteness of either half cannot be mistaken for the whole.
     */
    public function test_the_covered_population_is_the_union_of_the_literal_and_non_literal_scans(): void
    {
        $union = array_keys($this->spellings());

        foreach (self::NON_LITERAL_WRITES as $disposition) {
            if ($disposition['writes_plan_orders'] !== true) {
                continue;
            }

            foreach ($disposition['resolves_to'] as $value) {
                $union[] = strtolower(trim($value));
            }
        }

        $union = array_values(array_unique($union));
        sort($union);

        $classified = array_keys(self::CLASSIFIED);
        sort($classified);

        $this->assertSame($classified, $union,
            'the spellings this census classifies and the spellings it can actually find have '
            . 'drifted apart. Either a write was added or removed, or a CLASSIFIED entry describes '
            . 'a spelling nothing writes -- and an entry for a spelling nothing writes is how a '
            . 'census starts asserting history instead of the code.');
    }
}
