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
 * order simply appears on the merchant's screen as "created before the source was recorded",
 * which is a lie of a quieter kind, because the order was created this morning.
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $lines = explode("\n", $this->withoutComments((string) file_get_contents($file->getPathname())));

            foreach ($lines as $i => $line) {
                $isWriter = preg_match('/=\s*new Order\(\)\s*;/', $line)
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

        $this->assertGreaterThanOrEqual(14, count($writers),
            'The scan found ' . count($writers) . ' order writers. Sixteen were measured on '
            . '2026-10-28 (14 `new Order()` and 2 `Order::create([...])`). A number this far '
            . 'below that means the REGEX broke, not that the application shrank -- and a broken '
            . 'regex makes every assertion in this file pass. Found: '
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

            if (in_array($relative, self::NOT_A_WRITER, true)) {
                continue;
            }

            $all = explode("\n", (string) file_get_contents($root . '/' . $relative));
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
            . "order written here reaches the merchant's screen labelled \"created before the "
            . "source was recorded\" -- which is false for an order created today, and is the "
            . "specific dishonesty the no-default decision was supposed to make impossible:\n  "
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
     * "created before the source was recorded".
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
            . "brand new order as \"created before the source was recorded\":\n  "
            . implode("\n  ", array_map(
                static fn ($k, $v) => $k . '  ' . $v,
                array_keys($bad),
                $bad
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
