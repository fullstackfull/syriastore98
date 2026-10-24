<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-PAY-01 -- `explode(',', $variant->variation_option)` on a variant id that resolves to no row.
 * =============================================================================================
 *
 * WHY THIS IS A CRASH AND NOT A WARNING, measured rather than assumed. PHP 8.3 makes a property
 * read on null a WARNING, but this application runs with `error_reporting(-1)` and Laravel's
 * HandleExceptions converts any error in the mask into an `ErrorException`. MEASURED in the
 * bootstrapped container: `$null->variation_option` THROWS ErrorException, while
 * `explode(',', null)` merely deprecates and returns `['']`. So it is the DEREFERENCE that breaks,
 * which is why the fix is on the property read and not on the explode.
 *
 * (This is the same mechanism that returned 200 rather than 500 in P1-SEC-34's SpamPrevent probe:
 * the throw happens either way, and whether the shopper sees a 500 or a swallowed generic error
 * depends on whether the surrounding handler catches `\Exception`. Both are defects; neither is
 * "just a warning".)
 *
 * WHAT THE RECORDED CENSUS SAID AND WHAT MEASUREMENT FOUND. S36-9 recorded "the remaining seven"
 * -- PaymentService:2378, PaymentController:2174 and :3465, Khalti:453, Easebuzz:493,
 * PaiementPro:494, CinetPay:599. Keyed on the DEREFERENCE instead of on one occurrence per method,
 * `app/` holds 47 such sites, 15 already safe and 32 not. FOURTEEN are inside this task's declared
 * scope -- every one of those six files carries TWO, a `$ProductStock` site in the stock branch and
 * a `$variant_data` site in the OrderNote branch below it, and the recorded list named only the
 * first of each. All 14 have ZERO guard anywhere in their enclosing method, verified by searching
 * from the method signature with comment lines stripped.
 *
 * The other 18 are OUTSIDE this task's scope and are recorded rather than changed: 8 confirmed
 * unguarded method-wide (Cart.php:88, AuthorizeNet:1133, NMI:1167, PayU:408 and :849,
 * ReportController:3011, :3070 and :3117) and 10 that need per-branch analysis a static census
 * cannot do (Cart.php:783 and nine ApiController sites, where guards exist elsewhere in methods
 * hundreds of lines long -- ApiController:1554 has seven).
 *
 * WHY NO SITE IS DRIVEN END TO END, stated rather than implied. All 14 sit after a gateway has
 * confirmed a payment: `getProductStatus` and its siblings are the post-return handlers of Khalti,
 * Easebuzz, PaiementPro, CinetPay and Paymera, and reaching the loop needs a completed capture.
 * This environment has no gateway credentials and no network to one. `WhatsappUrlStockTest` --
 * the only neighbouring suite for one of these methods -- reaches the same conclusion and asserts
 * through `ReflectionMethod` and source inspection for the same reason. So the evidence here is
 * the MECHANISM (proved both ways below) plus a ratchet over all 14 sites, and the limitation is
 * named instead of being dressed up as coverage.
 */
class VariationOptionNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    /** The six files this task's scope field names. */
    private const SCOPE = [
        'app/Services/PaymentService.php',
        'app/Http/Controllers/PaymentController.php',
        'app/Http/Controllers/KhaltiPaymnetController.php',
        'app/Http/Controllers/EasebuzzController.php',
        'app/Http/Controllers/PaiementProPaymentController.php',
        'app/Http/Controllers/CinetPayController.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * THE PREMISE. Without this the whole task is "a deprecation we tidied up": the bare
     * dereference must actually throw in this application's error configuration.
     */
    public function test_the_premise_a_bare_dereference_of_a_missing_variant_throws(): void
    {
        $this->assertSame(-1, error_reporting(), 'the app reports every error class');
        $this->assertSame(E_WARNING, error_reporting() & E_WARNING, 'E_WARNING is in the mask');

        $missing = null;

        try {
            // exactly the pre-fix expression
            $options = explode(',', $missing->variation_option);
            $this->fail('expected the dereference to throw; got ' . var_export($options, true));
        } catch (\ErrorException $e) {
            $this->assertStringContainsString('variation_option', $e->getMessage());
            $this->assertStringContainsString('on null', $e->getMessage());
        }
    }

    /** The fix's semantics: no throw, and the stock block is SKIPPED rather than entered. */
    public function test_the_null_safe_form_skips_the_stock_block_instead_of_throwing(): void
    {
        $missing = null;

        $options = explode(',', (string) ($missing->variation_option ?? ''));

        $this->assertSame([''], $options);
        $this->assertFalse(in_array('manage_stock', $options, false),
            'a missing variant must not be treated as stock-managed');
    }

    /** ...and that a REAL variant is unaffected, which is what makes the change behaviour-preserving. */
    public function test_a_present_variant_is_read_exactly_as_before(): void
    {
        $present = new \stdClass();
        $present->variation_option = 'manage_stock,color';

        $before = explode(',', $present->variation_option);
        $after = explode(',', (string) ($present->variation_option ?? ''));

        $this->assertSame($before, $after);
        $this->assertTrue(in_array('manage_stock', $after, false));
    }

    /**
     * THE RATCHET. Every `variation_option` dereference in the six scoped files must be null-safe.
     * Comment lines are stripped first, because this docblock quotes the unsafe form and a ratchet
     * satisfiable by prose is not a ratchet -- a lesson this project has already paid for.
     *
     * @dataProvider scopedFiles
     */
    public function test_every_variation_option_dereference_in_the_file_is_null_safe(string $relative): void
    {
        $path = $this->root() . '/' . $relative;
        $this->assertFileExists($path);

        $unsafe = [];
        $safe = 0;

        foreach (explode("\n", (string) file_get_contents($path)) as $n => $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            // ONLY the two shapes this task addressed: the $ProductStock site in the stock branch
            // and the $variant_data site in the OrderNote branch. Deliberately NOT every
            // dereference in the file -- PaymentController:258 reads $variant->variation_option and
            // is correctly guarded by an `if ($variant === null) { continue; }` two lines above it,
            // and this ratchet flagged it until it was narrowed. Claiming "no unguarded dereference
            // anywhere" would also overreach: nine ApiController sites sit in methods hundreds of
            // lines long with guards on some branches, and settling those needs per-branch analysis
            // that a source ratchet cannot do. Those are recorded in the docblock, not asserted.
            if (! preg_match('/explode\(\s*\',\'\s*,\s*(.*\$(?:ProductStock|variant_data)->variation_option.*)$/', $line, $m)) {
                continue;
            }
            if (preg_match('/variation_option\s*\?\?/', $m[1])) {
                $safe++;
                continue;
            }
            $unsafe[] = ($n + 1) . ': ' . trim($line);
        }

        $this->assertSame([], $unsafe,
            $relative . " dereferences variation_option without a null guard at:\n  "
            . implode("\n  ", $unsafe)
            . "\nA variant id that resolves to no row then throws ErrorException on the property read.");
        $this->assertGreaterThanOrEqual(2, $safe,
            $relative . ' should carry at least two null-safe sites (the stock branch and the '
            . 'OrderNote branch). Found ' . $safe . ' -- if a site was removed, update this test '
            . 'deliberately rather than letting the count drift.');
    }

    public static function scopedFiles(): array
    {
        return array_combine(
            array_map(static fn ($f) => basename($f), self::SCOPE),
            array_map(static fn ($f) => [$f], self::SCOPE)
        );
    }

    /** All fourteen, counted across the scope, so a whole file reverting is caught too. */
    public function test_the_scope_carries_exactly_fourteen_null_safe_sites(): void
    {
        $total = 0;

        foreach (self::SCOPE as $relative) {
            foreach (explode("\n", (string) file_get_contents($this->root() . '/' . $relative)) as $line) {
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (preg_match('/explode\(\s*\',\'\s*,.*variation_option\s*\?\?/', $line)) {
                    $total++;
                }
            }
        }

        $this->assertSame(14, $total,
            'P1-PAY-01 made 14 dereferences null-safe across its six scoped files (two per file: '
            . 'the $ProductStock stock branch and the $variant_data OrderNote branch). Found '
            . $total . '.');
    }
}
