<?php

namespace Tests\Unit\Database;

use App\Models\Order;
use PHPUnit\Framework\TestCase;

/**
 * P2-04-11 / WS E8 -- THE RATCHET: every host-`Order` writer records where the order came from.
 * ============================================================================================
 *
 * WHY THIS FILE EXISTS, and it is not a style rule. `orders.order_source` is declared with NO
 * DEFAULT, deliberately, so that a writer nobody stamped leaves NULL instead of CLAIMING a
 * provenance. That makes NULL honest -- but it also makes an unstamped writer INVISIBLE: the
 * order simply appears on the merchant's screen with no source at all, which tells the merchant
 * nothing about an order that did have a provenance -- somebody just never recorded it.
 *
 * THE CENSUS THAT FOUND THE WRITERS WAS WRONG THE FIRST TIME. A `grep` for `new Order()` and
 * `Order::create(` over `app/` was read from a terminal that truncated at forty lines, and FIVE
 * gateway controllers -- Khalti, Easebuzz, PaiementPro, PaymentWall and CinetPay -- fell off the
 * end. Eleven writers were stamped and thirteen were believed to be all of them. The real figure
 * is sixteen. A one-off census is a snapshot of what somebody managed to read; this is a
 * statement about the tree, re-made on every run.
 *
 * NOT A FEATURE TEST, on purpose: it reads source text and needs neither a database nor the
 * framework, so it belongs in Unit and runs in milliseconds.
 */
class OrderSourceWriterCensusTest extends TestCase
{
    /**
     * Construction sites that do NOT persist an order, with the reason each is exempt. Every
     * entry is `relative/path.php:line` and every one has been read.
     */
    private const NOT_A_WRITER = [
        // An in-memory dummy for the invoice TEMPLATE PREVIEW: it is populated with
        // 'Item 1'/'Item 2' placeholder lines and a '+216 654654' phone number and is never
        // saved. It is also inside `packages/workdo/`, which must not be edited, so stamping it
        // would be impossible as well as wrong.
        'packages/workdo/AutomaticOrderPrinting/src/app/Http/Controllers/AutomaticOrderPrintingController.php',
    ];

    /**
     * WRITERS THAT DO PERSIST AN ORDER AND CANNOT BE STAMPED. Declared, not exempted, because
     * the distinction matters: each of these leaves `order_source` NULL, so the order reaches the
     * merchant's list with its source blank, and nothing records why.
     * That is a real provenance gap and it is recorded as **F148** rather than hidden.
     *
     * They are inside `packages/workdo/`, which CLAUDE.md §2 and
     * `.github/scripts/guard-no-workdo-changes.sh` both forbid editing, so there is no fix
     * available from this repository's first-party code. SCANNING vendored code is not editing
     * it, which is why the scan reaches in: an undeclared gap here is invisible, and this file's
     * whole reason for existing is that an invisible gap is how five writers went unstamped.
     *
     * @var array<string, string> path => why it cannot be stamped
     */
    private const UNSTAMPED_VENDOR = [
        'packages/workdo/LotteryPrizes/src/app/Http/Controllers/LotteryResultController.php' =>
            'F148. A real persisted host-Order writer: `new Order()` + `fill([...])` + `save()`, '
            . 'with payment_type "Lottery Product" -- the exact value OrderDataTable renders. '
            . 'Vendored, so it cannot be stamped; reachable only on an install holding an '
            . '`add_on_managers` row for LotteryPrizes plus the plan entitlement. A fourth '
            . 'vocabulary value for it is a later slice and an owner decision.',
    ];

    /**
     * WRITERS THAT ARE NOT A COMMERCE PATH AT ALL. Not exempt because they cannot be stamped --
     * `LynomiaChat` is the ONE package CLAUDE.md §2 permits editing -- but because there is no
     * honest value to stamp them with.
     *
     * These rows declare themselves fixtures IN THEIR OWN DATA: `product_order_id` is
     * `"STAGING-{key}-{i}"` and `payment_type` is the literal `'staging-fixture'`. The column
     * records which of three real paths created an order, and "a seeder somebody ran by hand on
     * staging" is none of them. Stamping `storefront` would invent a provenance for a row that
     * openly says it is synthetic -- the fabrication the no-default decision forbids -- and
     * widening the vocabulary with a fourth value for fixtures would put test scaffolding in a
     * merchant-facing filter.
     *
     * ENUMERATED RATHER THAN MATCHED STRUCTURALLY (e.g. "anything under `database/seeders/`"),
     * deliberately: a blanket rule would also excuse a real writer somebody later put in a
     * seeder. One more seeder writing orders turns this test red, and that is the test working.
     *
     * @var array<string, string> path => why no value is honest
     */
    private const NOT_A_COMMERCE_PATH = [
        'packages/workdo/LynomiaChat/src/database/seeders/LynomiaStagingFixtureSeeder.php' =>
            'Staging fixtures, self-declared: product_order_id "STAGING-…" and payment_type '
            . '"staging-fixture". A seeder run by hand is not POS, manual or storefront, and no '
            . 'value in the vocabulary describes it.',
    ];

    /**
     * The measured census. See `test_the_census_is_not_vacuous` for how it is composed; change it
     * only together with the list of writers, and say why in the commit.
     */
    private const MEASURED_WRITERS = 19;

    private function appRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every line in `app/` that constructs or mass-creates the host `Order` model.
     *
     * COMMENTS ARE BLANKED, NOT DELETED, before matching. This codebase's controllers discuss
     * `new Order()` in prose constantly -- `PaymentController` alone mentions it in four
     * comments -- and a scanner that reads its own subject's commentary has produced three false
     * readings in this project already. Blanking rather than deleting keeps every line number
     * truthful, which is what the failure message has to report.
     *
     * @return array<string, string> `relative/path.php:line` => the matched line
     */
    private function writerLines(): array
    {
        $found = [];
        $root = $this->appRoot();

        $roots = [$root . '/app', $root . '/packages/workdo'];
        $files = [];

        foreach ($roots as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            ) as $file) {
                $files[] = $file;
            }
        }

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $lines = explode("\n", $this->withoutComments((string) file_get_contents($file->getPathname())));

            foreach ($lines as $i => $line) {
                /*
                 * THREE FORMS, because two were missing and a missed form is a missed writer --
                 * which is this file's entire subject. `new Order()` covers the bare
                 * constructor; `new \App\Models\Order(...)` covers the fully-qualified spelling
                 * (and `new Order([...])`, the array constructor, which `$fillable` honours);
                 * and the static list covers mass creation.
                 */
                $isWriter = preg_match('/=\s*new\s+(\\\\?App\\\\Models\\\\)?Order\s*\(/', $line)
                    || preg_match('/\bOrder::(create|insert|insertGetId|updateOrCreate|firstOrCreate|forceCreate)\s*\(/', $line);

                if (! $isWriter) {
                    continue;
                }

                // `PlanOrder`, `OrderNote`, `OrderBillingDetail` and friends are different models.
                if (preg_match('/\b(Plan|Note|Billing|Coupon|Tax|Refund|Meta|Status)Order|Order(Note|Billing|Coupon|Tax|Refund|Meta)\b/', $line)) {
                    continue;
                }

                $found[$relative . ':' . ($i + 1)] = trim($line);
            }
        }

        ksort($found);

        return $found;
    }

    /** Blank out `//`, `#`, `/* *\/` and docblock content, preserving line count and offsets. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Keep the newlines so every subsequent line number stays correct.
                $out .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /**
     * Without this the comparison below is satisfied by a regex that matches nothing: an empty
     * census has no unstamped writers in it.
     */
    public function test_the_census_is_not_vacuous(): void
    {
        $writers = $this->writerLines();

        /*
         * AN EXACT COUNT, NOT A FLOOR. A floor of 14 against a measured 16 is two writers of
         * slack: one could be DELETED and one could stop matching the regex with the suite still
         * green. Exact makes any change to the census a deliberate edit of this number, with the
         * new figure stated by whoever changed it. A broken regex still fails loudly, which is
         * the vacuity half of the job.
         *
         * The figure, 19, composed:
         *   15  in `app/`, every one stamped -- 12 storefront gateway/checkout writers, the POS
         *       till, and the two merchant-manual `Order::create` writers. (16 were found before
         *       F147 converted the PaymentWall site to `PlanOrder`, where it belonged.)
         *    1  `NOT_A_WRITER`      -- AutomaticOrderPrinting's unsaved invoice-preview dummy
         *    1  `UNSTAMPED_VENDOR`  -- LotteryPrizes, F148: a real writer that cannot be stamped
         *    2  `NOT_A_COMMERCE_PATH` -- LynomiaChat's staging-fixture seeder
         * The last three groups are what widening the scan to `packages/workdo/` revealed, and
         * none of them was visible while the scan read `app/` alone.
         */
        $this->assertCount(self::MEASURED_WRITERS, $writers,
            'The scan found ' . count($writers) . ' order writers; ' . self::MEASURED_WRITERS
            . ' were measured. A LOWER number means the regex broke or a writer was deleted; a '
            . 'HIGHER one means a new writer exists and must be stamped or declared. Either way '
            . 'this number is edited deliberately, never relaxed. Found: '
            . implode(', ', array_keys($writers)));
    }

    /**
     * THE RATCHET ITSELF. A writer is stamped if the five lines after it (for the
     * `$order = new Order();` form) or the twelve before and after (for the array form) mention
     * `order_source`. The window is deliberately generous: the point is to catch a writer that
     * records NOTHING, not to police where in the block the line sits.
     */
    public function test_every_order_writer_records_its_source(): void
    {
        $root = $this->appRoot();
        $unstamped = [];

        foreach ($this->writerLines() as $where => $line) {
            [$relative, $lineNo] = explode(':', $where);

            if (in_array($relative, self::NOT_A_WRITER, true)
                || array_key_exists($relative, self::UNSTAMPED_VENDOR)
                || array_key_exists($relative, self::NOT_A_COMMERCE_PATH)) {
                continue;
            }

            /*
             * COMMENT-BLANKED, AND IT WAS NOT. This window used the RAW source, so a mere
             * COMMENT mentioning `order_source` within six lines of a writer satisfied the
             * ratchet -- in a file whose own docblock warns that "a scanner that reads its own
             * subject's commentary has produced three false readings in this project already".
             * Nothing was actually masked when this was found (all sixteen windows held a real
             * assignment), but the next writer added with an explanatory comment would have been.
             * `withoutComments()` preserves the newline count, so every line number below stays
             * truthful. The window SIZE is deliberately generous and is not narrowed here: the
             * defect was the window's source, not its width.
             */
            $all = explode("\n", $this->withoutComments((string) file_get_contents($root . '/' . $relative)));
            $index = (int) $lineNo - 1;

            // An `Order::create([...])` array can carry the key before or after this line;
            // a `new Order()` is followed by its assignments.
            $isArrayForm = str_contains($line, 'Order::create');
            $from = $isArrayForm ? max(0, $index - 4) : $index;
            $to = min(count($all) - 1, $index + ($isArrayForm ? 40 : 6));

            $window = implode("\n", array_slice($all, $from, $to - $from + 1));

            if (! str_contains($window, 'order_source')) {
                $unstamped[$where] = $line;
            }
        }

        $this->assertSame([], $unstamped,
            "THESE ORDER WRITERS RECORD NO SOURCE. `orders.order_source` has no default, so an "
            . "order written here reaches the merchant's screen with no source at all, and nothing "
            . "records why. The no-default decision makes that absence HONEST rather than a false "
            . "claim -- it does not make it acceptable for a writer somebody simply forgot:\n  "
            . implode("\n  ", array_map(
                static fn ($k, $v) => $k . '  ' . $v,
                array_keys($unstamped),
                $unstamped
            ))
            . "\n\nStamp it with one of Order::SOURCE_POS / SOURCE_MANUAL / SOURCE_STOREFRONT, or "
            . "-- if it does not PERSIST an order -- add it to self::NOT_A_WRITER with the reason.");
    }

    /**
     * Every stamp names a value the vocabulary holds. A typo'd constant is a compile error, but a
     * STRING literal ('store_front', 'POS') is not, and it would reach the list as an
     * unrecognised value the label map answers NULL for -- rendering a brand new order as
     * with no source at all.
     */
    public function test_every_stamp_uses_the_vocabulary(): void
    {
        $root = $this->appRoot();
        $bad = [];

        foreach (['app', 'resources/views'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $lines = explode("\n", $this->withoutComments((string) file_get_contents($file->getPathname())));

                foreach ($lines as $i => $line) {
                    if (! str_contains($line, 'order_source')) {
                        continue;
                    }

                    // A write: `->order_source = X` or `'order_source' => X`.
                    if (! preg_match("/order_source'?\s*(=>|=)\s*(.+?)[,;]/", $line, $m)) {
                        continue;
                    }

                    $value = trim($m[2]);

                    $ok = str_contains($value, 'Order::SOURCE_')
                        || str_contains($value, 'null');

                    if (! $ok) {
                        $bad[$relative . ':' . ($i + 1)] = $value;
                    }
                }
            }
        }

        $this->assertSame([], $bad,
            "These lines write `order_source` as something other than an `Order::SOURCE_*` "
            . "constant. A bare string is not checked by anything and a typo in one renders a "
            . "brand new order as having no source at all:\n  "
            . implode("\n  ", array_map(
                static fn ($k, $v) => $k . '  ' . $v,
                array_keys($bad),
                $bad
            )));
    }

    /**
     * EVERY WRITER STAMPS THE RIGHT CONSTANT, not merely some constant.
     *
     * `test_every_order_writer_records_its_source` proves a stamp EXISTS and
     * `test_every_stamp_uses_the_vocabulary` proves it is a vocabulary constant; neither notices
     * the till stamping `SOURCE_STOREFRONT`. That gap was real: the only test asserting the POS
     * value compared constants to each other and never drove `PosController`, so deleting the
     * till's stamp left it green.
     *
     * The rule is a rule, not a list of lines: the POS controller stamps POS, the two
     * merchant-manual writers stamp MANUAL, and everything else is a storefront checkout path.
     */
    public function test_every_writer_stamps_the_constant_its_path_warrants(): void
    {
        $expected = [
            'app/Http/Controllers/PosController.php' => 'SOURCE_POS',
            'app/Http/Controllers/OrderController.php' => 'SOURCE_MANUAL',
            'app/Http/Controllers/Api/DashboardController.php' => 'SOURCE_MANUAL',
        ];

        $root = $this->appRoot();
        $wrong = [];
        $checked = 0;

        foreach ($this->writerLines() as $where => $line) {
            [$relative, $lineNo] = explode(':', $where);

            if (in_array($relative, self::NOT_A_WRITER, true)
                || array_key_exists($relative, self::UNSTAMPED_VENDOR)
                || array_key_exists($relative, self::NOT_A_COMMERCE_PATH)) {
                continue;
            }

            $all = explode("\n", $this->withoutComments((string) file_get_contents($root . '/' . $relative)));
            $index = (int) $lineNo - 1;
            $isArrayForm = str_contains($line, 'Order::create');
            $from = $isArrayForm ? max(0, $index - 4) : $index;
            $to = min(count($all) - 1, $index + ($isArrayForm ? 40 : 6));
            $window = implode("\n", array_slice($all, $from, $to - $from + 1));

            $want = $expected[$relative] ?? 'SOURCE_STOREFRONT';
            $checked++;

            if (! str_contains($window, 'Order::' . $want)) {
                $wrong[$where] = 'expected Order::' . $want;
            }
        }

        $this->assertGreaterThanOrEqual(15, $checked,
            'premise: the loop actually examined the writers rather than skipping them all');

        $this->assertSame([], $wrong,
            "THESE WRITERS STAMP THE WRONG SOURCE. The till is not a storefront checkout and a "
            . "merchant typing an order is not either; a writer stamping the wrong constant puts "
            . "a confident false provenance on a commercial record, which is worse than NULL:\n  "
            . implode("\n  ", array_map(
                static fn ($k, $v) => $k . '  ' . $v,
                array_keys($wrong),
                $wrong
            )));
    }

    /** The vocabulary itself: three values, and no name for the absence of one. */
    public function test_the_vocabulary_has_no_name_for_an_absent_source(): void
    {
        $this->assertSame(['pos', 'manual', 'storefront'], Order::SOURCES);

        foreach (['', 'unknown', 'not_recorded', 'legacy', 'UNKNOWN', '0'] as $notASource) {
            $this->assertNull(Order::orderSourceLabel($notASource),
                "`{$notASource}` must not have a label. A name for the absence of a record is a "
                . 'name somebody can store, compare and filter on, which is how "nobody knows" '
                . 'becomes a provenance.');
        }

        $this->assertNull(Order::orderSourceLabel(null));
    }
}
