<?php

namespace Tests\Unit\Merchant;

use App\Services\OrderEntitlementReversal;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * THE MERCHANT CANNOT FILTER FOR THE WALLET TRANSACTIONS THIS BRANCH TAUGHT THE LEDGER TO WRITE.
 * =================================================================================================
 *
 * FOUND BY THE PR #140 DELETION AUDIT, not by any test, and not by either independent review —
 * which is the point of asking "what did this change REMOVE or NARROW?" rather than only reading
 * the additions. Nothing was deleted here; an emission was RENAMED and the screen that filters on
 * it was never told.
 *
 * `OrderEntitlementReversal::TRANSACTION_TYPES` (`:93-96`) maps each reversal reason to the
 * `store_wallet_transactions.type` it writes at `:280`:
 *
 *     REASON_CANCELLED => 'order_cancelled'
 *     REASON_REFUNDED  => 'order_refunded'
 *
 * Before this branch, cancellation went through `markFinancialAsUnpaid()` and wrote
 * `'order_payment_reversed'` (`OrderFinancialService.php:698`). That type is still written — but
 * only by that other path, no longer on cancel or refund.
 *
 * THE FILTER NEVER FOLLOWED. `resources/views/finance/index.blade.php` offers ten type options and
 * neither new one is among them, while the backend match is exact:
 * `$query->where('type', $value)` (`StoreFinanceController.php:886`, and again at `:2358`). So a
 * merchant who selects **Order Payment Reversed** to find their cancellations gets an empty table,
 * and there is no option that would find them. The rows are not lost — they appear under *All
 * Types* — but they cannot be isolated, and the one plausible choice silently returns nothing,
 * which is worse than an absent option because it reads as "there were none".
 *
 * BOTH ARE MISSING, AND THE AUDIT NAMED ONLY ONE. It reported `order_cancelled`; `order_refunded`
 * comes from the same two-entry map and is equally absent. That is why this test is keyed on the
 * map BY REFLECTION rather than on a hand-written list of two strings: a third reversal reason
 * added later fails here until the filter learns it, instead of repeating this defect.
 *
 * NOTHING COMPUTES. No figure, balance, direction or amount changes — only which types a merchant
 * can select. The `direction` of both new types is already `'debit'` (`:281`), consistent with the
 * reversal they record.
 */
class FinanceLedgerTypeFilterTest extends TestCase
{
    private const VIEW = 'resources/views/finance/index.blade.php';

    /**
     * Options the dropdown offers that NOTHING in this repository writes.
     *
     * Declared rather than fixed, and declared rather than ignored. Both are pre-existing and
     * neither is this branch's: a repo-wide search for `'type' => 'commission'` and
     * `'type' => 'adjustment'` finds zero write sites in `app/` or `packages/`. They are harmless
     * (selecting one returns an empty table, which is accurate — there are none) but they are the
     * same class of drift pointing the other way, so they are recorded here instead of being
     * discovered again later. Removing them is a product decision, not a test fix.
     */
    private const DROPDOWN_ONLY = ['commission', 'adjustment'];

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    private function viewSource(): string
    {
        return (string) file_get_contents($this->repoRoot().'/'.self::VIEW);
    }

    /** @return list<string> the `value="..."` of every type option the dropdown renders */
    private function dropdownTypes(): array
    {
        // The select is built in an inline template string, so this reads the rendered option
        // values rather than a PHP array. Anchored on the block that follows `All Types`, so an
        // unrelated `<option value=...>` elsewhere in the file cannot satisfy it.
        $source = $this->viewSource();
        $start = strpos($source, "__('All Types')");
        $this->assertIsInt($start, 'The type filter block is gone from '.self::VIEW);

        $block = substr($source, $start, 2600);
        preg_match_all('/<option value="([a-z_]+)"/', $block, $m);

        return array_values(array_unique(array_filter($m[1])));
    }

    /** @return array<string,string> reason => transaction type, read from the real private const */
    private function reversalTypes(): array
    {
        $const = (new ReflectionClass(OrderEntitlementReversal::class))->getConstant('TRANSACTION_TYPES');

        $this->assertIsArray($const, 'OrderEntitlementReversal::TRANSACTION_TYPES is gone or renamed.');
        $this->assertNotEmpty($const);

        return $const;
    }

    public function test_premise_the_reversal_map_still_names_the_types_it_writes(): void
    {
        $types = $this->reversalTypes();

        // If this fails, the map changed and the rest of this file is describing the wrong thing.
        $this->assertContains('order_cancelled', $types);
        $this->assertContains('order_refunded', $types);
    }

    public function test_premise_the_backend_filters_on_an_exact_type_match(): void
    {
        // The whole defect depends on the match being exact rather than a LIKE, so it is measured
        // rather than assumed: a partial match would make a missing option merely inconvenient.
        $controller = (string) file_get_contents($this->repoRoot().'/app/Http/Controllers/StoreFinanceController.php');

        $this->assertStringContainsString("\$query->where('type', \$value);", $controller);
    }

    public function test_every_type_a_reversal_writes_can_be_selected_in_the_filter(): void
    {
        $offered = $this->dropdownTypes();
        $missing = array_values(array_diff(array_values($this->reversalTypes()), $offered));

        $this->assertSame(
            [],
            $missing,
            "OrderEntitlementReversal writes these store_wallet_transactions.type values, and the\n"
            ."/finance type filter offers no option for them, so a merchant cannot isolate those\n"
            ."rows and the nearest option returns an empty table:\n  ".implode("\n  ", $missing)
            ."\n\nOffered: ".implode(', ', $offered)
        );
    }

    public function test_both_new_labels_are_translated_rather_than_falling_back_to_english(): void
    {
        /*
         * `__('Order Cancelled')` with no key returns THE KEY, so an untranslated label renders as
         * English on an Arabic RTL page without any error — the quiet half of this defect. The two
         * labels follow the pattern the file already uses for their sibling,
         * `"Order Paid": "تم دفع الطلب"`, and reuse the roots already present as
         * `"Cancel": "إلغاء"` and `"Refund": "استرداد"`.
         */
        $labels = ['Order Cancelled', 'Order Refunded'];

        foreach (['en', 'ar'] as $locale) {
            $path = $this->repoRoot().'/resources/lang/'.$locale.'.json';
            $this->assertFileExists($path);

            $strings = json_decode((string) file_get_contents($path), true);
            $this->assertIsArray($strings, $locale.'.json is not valid JSON.');

            foreach ($labels as $label) {
                $this->assertArrayHasKey($label, $strings, $locale.'.json has no entry for '.$label);
                $this->assertNotSame('', trim((string) $strings[$label]));
            }
        }

        // And the Arabic really is Arabic: an entry equal to its own key is the silent fallback
        // this test exists to catch, which `__()` would produce with no entry at all.
        $ar = json_decode((string) file_get_contents($this->repoRoot().'/resources/lang/ar.json'), true);

        foreach ($labels as $label) {
            $this->assertNotSame($label, $ar[$label], $label.' is untranslated in ar.json.');
            $this->assertMatchesRegularExpression(
                '/\p{Arabic}/u',
                (string) $ar[$label],
                $label.' carries no Arabic script in ar.json.'
            );
        }
    }

    public function test_the_dropdown_options_nothing_writes_are_the_declared_two(): void
    {
        // A ratchet on the OTHER direction of the same drift. If a new dead option appears, or one
        // of these gains a writer, this fails and someone decides deliberately.
        $offered = $this->dropdownTypes();
        $written = [];

        foreach ([$this->repoRoot().'/app', $this->repoRoot().'/packages'] as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                foreach ($offered as $type) {
                    if (preg_match("/'type'\s*=>\s*'".preg_quote($type, '/')."'/", $src)) {
                        $written[$type] = true;
                    }
                }
            }
        }

        // Types reachable only through the reversal map are written via a constant lookup
        // (`self::TRANSACTION_TYPES[$reason]`), not a literal, so credit them explicitly.
        foreach ($this->reversalTypes() as $type) {
            if (in_array($type, $offered, true)) {
                $written[$type] = true;
            }
        }

        $unwritten = array_values(array_diff($offered, array_keys($written)));
        sort($unwritten);
        $expected = self::DROPDOWN_ONLY;
        sort($expected);

        $this->assertSame(
            $expected,
            $unwritten,
            'The set of filter options that nothing writes has changed. Offered: '.implode(', ', $offered)
        );
    }
}
