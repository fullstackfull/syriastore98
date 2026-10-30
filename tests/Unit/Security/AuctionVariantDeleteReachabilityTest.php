<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * F200 — THE AUCTION MODULE'S VARIANT DELETES ARE UNREACHABLE, AND THIS TEST FAILS THE DAY THEY
 * ARE NOT.
 * =================================================================================================
 *
 * THE LEDGER'S PREMISE WAS WRONG, AND THE CORRECTION IS THE REASON THIS FILE EXISTS.
 *
 * F200 recorded that `AuctionProductController::update()` hard-deletes every uncovered
 * `product_variants` row at two sites with no reference check and no consent, and raised its
 * severity on the measured finding that the method carries no `product_type` predicate — so it
 * reaches an ordinary product as readily as an auction one. Both of those readings are correct
 * about the SOURCE.
 *
 * Neither delete can execute. `update()` calls `$this->combinations($options)`, and **no
 * `combinations()` method exists anywhere in the AuctionProduct package**. The controller extends
 * `Illuminate\Routing\Controller`, whose `__call()` throws `BadMethodCallException`; that is a
 * `LogicException`, so it is caught by the method's own `catch (\Exception $e)` and returned as
 * `{"flag":"error","msg":"Method …::combinations does not exist."}`. The call sits at brace depth 5
 * inside the `variant_product != 0` branch and BOTH deletes sit lexically after it inside that same
 * branch — so it dominates them and there is no path that reaches a delete without hitting it
 * first.
 *
 * That was measured end to end, not inferred: with the module enabled through the production
 * loader (an `add_on_managers` row and `PackageServiceProvider::loadAddons()`), its migrations run
 * and the plan entitlement seeded, a complete narrowing payload on BOTH the `choice_no` path and
 * the `attribute_no`/`options_datas` path returned HTTP 200 with that error body and the four
 * variants **unchanged**.
 *
 * WHY THIS IS A TEST AND NOT ONLY A LEDGER CORRECTION
 * -------------------------------------------------------------------------------------------------
 * The owner's sixth condition for any F200 fix is that it *"does not regress when the WorkDo module
 * is updated."* The reason those deletes are harmless today is an accident of a broken vendored
 * copy — and the host's own `ProductController` **does** define `combinations()`, so upstream
 * plainly intended it to exist. A WorkDo update that restores the method turns two ungated,
 * reference-blind, consent-free mass deletes live in one `composer update`, silently.
 *
 * So the control is this ratchet. It reads the vendored file rather than reflecting on the class,
 * deliberately: **no WorkDo module loads in a test run** without an `add_on_managers` row, so a
 * reflection-based check would pass by never reaching the module at all — this codebase's
 * best-documented way for a test to be vacuous.
 *
 * IT IS INSURANCE, NOT REMEDIATION, AND F200 IS NOT CLOSED BY IT. What this file asserts is that a
 * known hole is currently unreachable. The live findings in that module are different ones, recorded
 * separately, and `packages/workdo/` must not be edited either way.
 */
final class AuctionVariantDeleteReachabilityTest extends TestCase
{
    private const CONTROLLER =
        'packages/workdo/AuctionProduct/src/app/Http/Controllers/AuctionProductController.php';

    private const PACKAGE = 'packages/workdo/AuctionProduct';

    private function repo(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function controllerSource(): string
    {
        $path = $this->repo(self::CONTROLLER);

        if (! is_file($path)) {
            $this->markTestSkipped(
                'The AuctionProduct module is not vendored in this checkout, so there is no F200 '
                . 'path to guard. This skip is the honest answer: the file cannot assert anything '
                . 'about code that is not here.'
            );
        }

        return (string) file_get_contents($path);
    }

    /**
     * THE RATCHET. If `combinations()` ever becomes callable on that controller, the two deletes
     * below it become live and F200 stops being latent.
     */
    public function test_the_auction_controller_still_cannot_reach_its_variant_deletes(): void
    {
        $source = $this->controllerSource();

        $this->assertStringContainsString('$this->combinations(', $source,
            'F200: `update()` no longer calls `$this->combinations()`. That call is the ONLY reason '
            . 'the two uncovered-variant deletes below it are unreachable. If the call has been '
            . 'removed or renamed, re-measure whether those deletes now execute — the ledger row '
            . 'F200 records what they do: a reference-blind, consent-free mass delete of every '
            . 'variant the payload does not cover, on a product with no `product_type` predicate.');

        $defined = [];
        foreach ($this->phpFilesUnder($this->repo(self::PACKAGE)) as $file) {
            if (preg_match('/function\s+combinations\s*\(/i', (string) file_get_contents($file))) {
                $defined[] = str_replace($this->repo(''), '', $file);
            }
        }

        $this->assertSame([], $defined,
            "F200 IS NO LONGER LATENT. A `combinations()` method now exists in the AuctionProduct "
            . "package, so `AuctionProductController::update()` no longer throws before reaching "
            . "its two variant deletes — and those deletes carry no reference check, no consent "
            . "signal and no `product_type` predicate, so they can remove a variant that a shopper's "
            . "cart or a past order refers to, on an ORDINARY product.\n\n"
            . "Found in: " . implode(', ', $defined) . "\n\n"
            . "`packages/workdo/` must not be edited (owner §4.7), so the fix is first-party: the "
            . "`web` middleware group reaches this route and `ModuleResourceOwnershipGuard` already "
            . "targets `PUT auctionproduct/{auctionproduct}`. See the F200 ledger row for the "
            . "containment plan, including why a `ProductVariant::deleting` observer would NOT work "
            . "(a Builder mass delete fires no model events — measured).");
    }

    /**
     * The premise of the ratchet above: the deletes really are after the call that throws.
     *
     * Without this, `combinations()` could be moved below a delete and the ratchet would still
     * pass while the hole was open.
     */
    public function test_both_variant_deletes_still_sit_after_the_call_that_throws(): void
    {
        $source = $this->controllerSource();

        $callAt = strpos($source, '$this->combinations(');
        $this->assertNotFalse($callAt, 'the dominating call is gone — see the ratchet above');

        $deletes = [];
        if (preg_match_all('/ProductVariant::where\([^;]*whereNotIn\(\s*[\'"]variant[\'"]/s', $source, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $hit) {
                $deletes[] = $hit[1];
            }
        }

        $this->assertNotEmpty($deletes,
            'F200: the uncovered-variant deletes are no longer in the shape this test recognises. '
            . 'Re-read `AuctionProductController::update()` and re-measure reachability before '
            . 'trusting the ratchet above.');

        foreach ($deletes as $i => $offset) {
            $this->assertGreaterThan($callAt, $offset,
                'F200: variant delete #' . ($i + 1) . ' now sits BEFORE `$this->combinations()`, so '
                . 'the throw no longer dominates it and the delete is reachable. This is the exact '
                . 'reordering that turns a latent hole into a live one.');
        }
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
