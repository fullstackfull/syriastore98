<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-J — BOTH ANSWERS to every confirmation question, in the dark admin.
 * ================================================================================================
 *
 * THE DEFECT WAS INTRODUCED BY A CORRECT RULE, which is the only reason it survived this long.
 *
 *   P2.5-DESIGN-E gave the host's soft `.btn-danger` a light ink for the DARK PAGE:
 *   `[data-ss-theme="dark"] .btn-danger { color: var(--ss-fg) }`, store-syria-ds.css. Measured
 *   there it is right, and this file proves it: 4.80:1 on the page, 4.56:1 on the card.
 *
 *   `data-ss-theme` sits on <html>, so that rule also reaches the SweetAlert confirmation sheet —
 *   which stays WHITE in dark mode, exactly as P2.5-A-01/A-02 decided for the Bootstrap modal.
 *   There the host's 41% pink tint composites to rgb(255,222,228) and #e8eaed lands at 1.04:1.
 *
 * SO THE MERCHANT COULD NOT READ EITHER ANSWER. The confirm button fails for a different reason
 * and was found only by measuring every part of the open dialog rather than the one button a
 * capture had been looked at for: the host paints `.btn-success` as `--bs-btn-color: #5eb839` over
 * `--bs-btn-bg: var(--third-color)`, and `--third-color` RESOLVES TO THE EMPTY STRING on this
 * element, so the fill is invalid-at-computed-value and falls back to transparent. Light green on
 * the white sheet: 2.50:1.
 *
 *     dark, on the dialog's white sheet     confirm 2.50:1      cancel 1.04:1
 *     light, same sheet, same fills         confirm 7.82:1      cancel 7.30:1
 *
 * THE FOURTH TIME THIS FILE'S FAMILY HAS RECORDED THIS SHAPE. A-02 on the page (right),
 * WAVE-17a inside `.modal-content` (1.69:1), PKG-I on `.swal2-title` (1.00:1), and now these two
 * buttons. Each time the page-level rule was correct and incomplete, and each time the SweetAlert
 * sheet was missed BECAUSE IT IS NOT A `.modal` — none of the `.modal-content` overrides reach it.
 *
 * THE TARGET IS NOT INVENTED. The host paints both fills identically in the light and dark sheets
 * (style.css:3555 == style-dark.css:3561; asserted below), and the sheet is #ffffff in both
 * themes, so the LIGHT theme's own inks are already measured correct against exactly these
 * grounds. The repair makes the dark dialog identical to the light dialog.
 *
 * EVERY FIGURE BELOW WAS PRODUCED TWICE — by this file's arithmetic and by
 * docs/development/phase-2.5/tools/audit-dialog-contrast.cjs against the running admin in all four
 * language/theme combinations at 1440, 390 and 320 — and they agree to the hundredth. The two
 * mutations are not hypothetical either: both were applied to the sheet and re-measured live.
 */
class ConfirmDialogButtonContrastTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const HOST_DARK_SHEET = 'public/assets/css/style-dark.css';
    private const HOST_LIGHT_SHEET = 'public/assets/css/style.css';
    private const HANDLER = 'public/js/custom.js';

    /** The host's danger fill, style-dark.css:3612. A 41% tint, so it COMPOSITES. */
    private const CHIP_TINT = [255, 174, 189];
    private const CHIP_ALPHA = 0.41;

    /** The SweetAlert sheet is white in BOTH themes — the same decision A-02 made for the modal. */
    private const SHEET_BG = [255, 255, 255];
    private const PAGE_BG  = [34, 36, 44];
    private const CARD_BG  = [41, 42, 51];

    private const FG_DARK   = [232, 234, 237]; // --ss-fg in a dark scope, #e8eaed — today's ink
    private const HOST_GREEN = [94, 184, 57];  // --bs-btn-color on .btn-success, #5eb839

    private const GREEN_800 = [22, 94, 52];  // #165e34, a LAYER-1 primitive — the repair
    private const RED_800   = [140, 29, 23]; // #8c1d17, a LAYER-1 primitive — the repair

    /** The semantic tokens IN A DARK SCOPE (store-syria-ds.css:579-581). They re-theme. */
    private const SUCCESS_FG_DARK = [127, 214, 160]; // #7fd6a0
    private const DANGER_FG_DARK  = [243, 169, 164]; // #f3a9a4

    private const AA = 4.5; // 14px regular button labels; the large-text relief does not apply

    // =============================================================================================
    //  PREMISES — every half of the collision is real and still in the sheets
    // =============================================================================================

    public function test_premise_the_host_still_paints_the_danger_fill_as_a_41_percent_tint(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.btn-danger\s*\{[^}]*--bs-btn-bg:\s*rgba\(255,\s*174,\s*189,\s*0?\.41\)/i',
            $this->read(self::HOST_DARK_SHEET),
            'The danger fill is no longer a 41% pink tint, so every composited figure in this file '
            . 'is stale. Re-measure with audit-dialog-contrast.cjs before editing any hex here.');
    }

    public function test_premise_the_host_still_gives_btn_success_an_unresolvable_fill(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.btn-success\s*\{[^}]*--bs-btn-color:\s*#5eb839;\s*--bs-btn-bg:\s*var\(--third-color\)/i',
            $this->read(self::HOST_DARK_SHEET),
            'The confirm button no longer takes #5eb839 over var(--third-color). Its 2.50:1 reading '
            . 'depends on that fill resolving to nothing, so re-measure rather than trust this file.');
    }

    /**
     * WHY THE LIGHT INKS ARE THE RIGHT TARGET: the host paints these two fills IDENTICALLY in both
     * sheets, so the light theme's measured-correct inks are correct on the dark dialog too. If the
     * host ever diverges, the repair's figures stop following from light mode and must be re-taken.
     */
    public function test_premise_the_host_paints_both_fills_identically_in_light_and_dark(): void
    {
        foreach ([
            ['.btn-success', '/\.btn-success\s*\{[^}]*--bs-btn-bg:\s*var\(--third-color\)/i'],
            ['.btn-danger', '/\.btn-danger\s*\{[^}]*--bs-btn-bg:\s*rgba\(255,\s*174,\s*189,\s*0?\.41\)/i'],
        ] as [$name, $pattern]) {
            $this->assertMatchesRegularExpression($pattern, $this->read(self::HOST_LIGHT_SHEET),
                "The LIGHT sheet no longer paints {$name}'s fill the way the dark sheet does, so "
                . 'the light theme is no longer the correct target for the dark dialog.');
        }
    }

    public function test_premise_design_e_still_inks_the_danger_button_for_the_dark_page(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s+\.btn-danger,\s*\n\s*\[data-ss-theme="dark"\]\s+\.btn-danger:not\(:hover\)[^{]*\{\s*color:\s*var\(--ss-fg\)/i',
            $this->read(self::DS_SHEET),
            'The page-level dark rule is gone. If .btn-danger is no longer re-inked for dark, the '
            . 'dialog override has nothing to correct and should be DELETED, not left as a dead rule.');
    }

    /**
     * THE LAYER-1 PRIMITIVES MUST NOT RE-THEME. This is the whole reason the repair uses them, and
     * it is asserted rather than assumed because the identical mistake was made twice before — once
     * with `--ss-fg` on the dialog title, once with `--ss-fg` inside the modal.
     */
    public function test_premise_the_layer_one_primitives_are_declared_once_and_never_rethemed(): void
    {
        $sheet = $this->read(self::DS_SHEET);

        foreach (['--ss-green-800' => '#165e34', '--ss-red-800' => '#8c1d17'] as $token => $hex) {
            preg_match_all('/' . preg_quote($token, '/') . '\s*:\s*([^;]+);/i', $sheet, $m);

            $this->assertCount(1, $m[1],
                "{$token} is declared " . count($m[1]) . ' times. A primitive that is re-declared '
                . 'can re-theme, which is exactly the trap this repair exists to avoid.');
            $this->assertSame($hex, strtolower(trim($m[1][0])),
                "{$token} is no longer {$hex}, so every ratio in this file is stale.");
        }
    }

    // =============================================================================================
    //  THE DEFECT
    // =============================================================================================

    public function test_the_cancel_button_is_invisible_on_the_dialog_sheet(): void
    {
        $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, self::SHEET_BG);

        $this->assertSame([255, 222, 228], $chip,
            'The composited chip is not the pale pink the live measurement read.');
        $this->assertSame(1.04, round($this->ratio(self::FG_DARK, $chip), 2));
        $this->assertLessThan(self::AA, $this->ratio(self::FG_DARK, $chip));
    }

    public function test_the_confirm_button_fails_too_and_for_a_different_reason(): void
    {
        // No composite: the fill is invalid-at-computed-value, so the ground is the sheet itself.
        $this->assertSame(2.50, round($this->ratio(self::HOST_GREEN, self::SHEET_BG), 2));
        $this->assertLessThan(self::AA, $this->ratio(self::HOST_GREEN, self::SHEET_BG));
    }

    /** DESIGN-E is RIGHT where it was measured, so the repair must not widen onto these grounds. */
    public function test_the_page_level_ink_is_correct_on_both_dark_grounds(): void
    {
        foreach ([[self::PAGE_BG, 4.80], [self::CARD_BG, 4.56]] as [$ground, $expected]) {
            $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, $ground);
            $ratio = $this->ratio(self::FG_DARK, $chip);

            $this->assertSame($expected, round($ratio, 2));
            $this->assertGreaterThanOrEqual(self::AA, $ratio);
        }
    }

    // =============================================================================================
    //  THE REPAIR, AND WHY EACH SIMPLER ONE IS WORSE
    // =============================================================================================

    public function test_the_repair_clears_aa_on_both_buttons(): void
    {
        $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, self::SHEET_BG);

        $this->assertSame(7.30, round($this->ratio(self::RED_800, $chip), 2));
        $this->assertSame(7.82, round($this->ratio(self::GREEN_800, self::SHEET_BG), 2));

        $this->assertGreaterThanOrEqual(self::AA, $this->ratio(self::RED_800, $chip));
        $this->assertGreaterThanOrEqual(self::AA, $this->ratio(self::GREEN_800, self::SHEET_BG));
    }

    /**
     * THE FIRST TRAP, MEASURED BY MUTATION. The repair was applied with the semantic tokens and
     * re-measured in the running admin: 1.75:1 and 1.53:1. The confirm button comes out WORSE than
     * the defect it replaced (2.50:1), and the cancel button is still unreadable.
     */
    public function test_the_semantic_tokens_would_not_fix_either_button(): void
    {
        $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, self::SHEET_BG);

        $this->assertSame(1.75, round($this->ratio(self::SUCCESS_FG_DARK, self::SHEET_BG), 2));
        $this->assertSame(1.53, round($this->ratio(self::DANGER_FG_DARK, $chip), 2));

        // worse than the defect, not merely insufficient
        $this->assertLessThan(
            $this->ratio(self::HOST_GREEN, self::SHEET_BG),
            $this->ratio(self::SUCCESS_FG_DARK, self::SHEET_BG),
            '--ss-success-fg is no longer worse than the untouched host green, so the warning this '
            . 'test carries is stale. Re-measure before relaxing the rule.');
    }

    /**
     * THE SECOND TRAP. An unconfined `[data-ss-theme="dark"] .btn-danger { color: --ss-red-800 }`
     * fixes the dialog and destroys both dark grounds. This is why the rule names `.swal2-popup`.
     */
    public function test_an_unconfined_repair_would_destroy_both_dark_grounds(): void
    {
        foreach ([[self::PAGE_BG, 1.58], [self::CARD_BG, 1.66]] as [$ground, $expected]) {
            $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, $ground);
            $ratio = $this->ratio(self::RED_800, $chip);

            $this->assertSame($expected, round($ratio, 2));
            $this->assertLessThan(self::AA, $ratio);
        }
    }

    // =============================================================================================
    //  THE RULES, AND THE REACH THEY HAVE TO COVER
    // =============================================================================================

    public function test_both_rules_are_present_and_scoped_to_the_dialog_sheet(): void
    {
        $sheet = $this->read(self::DS_SHEET);

        foreach ([
            'btn-success' => '--ss-green-800',
            'btn-danger' => '--ss-red-800',
        ] as $variant => $token) {
            $this->assertMatchesRegularExpression(
                '/\[data-ss-theme="dark"\]\s+\.swal2-popup\s+\.' . $variant . '[^{]*\{\s*color:\s*var\('
                . preg_quote($token, '/') . '/i',
                $sheet,
                "The dialog override for .{$variant} is missing or no longer uses {$token}. Without "
                . 'it the dark confirmation returns to an unreadable label.');
        }
    }

    /** The scope is not optional: a rule without `.swal2-popup` is the second trap above. */
    public function test_neither_rule_was_widened_off_the_dialog_sheet(): void
    {
        $sheet = $this->read(self::DS_SHEET);

        foreach (['btn-success', 'btn-danger'] as $variant) {
            $this->assertDoesNotMatchRegularExpression(
                '/\[data-ss-theme="dark"\]\s+\.' . $variant . '[^{,]*\{\s*color:\s*var\(--ss-(?:green|red)-800/i',
                $sheet,
                "A `[data-ss-theme=\"dark\"] .{$variant}` rule now applies a Layer-1 dark ink "
                . 'without naming the dialog sheet. On the dark page that is 1.58:1 — see '
                . 'test_an_unconfined_repair_would_destroy_both_dark_grounds.');
        }
    }

    /**
     * THE COMPLETENESS RATCHET, and the reason this slice did not simply patch two selectors and
     * stop. The pairing above is the DEFAULT of every confirmation pathway in the product — the
     * shared `.show_confirm` handler, `.order_replace` on the order detail, both terminal refund
     * dialogs, and four vendored modules including BulkDelete. If a pathway ever starts using a
     * third `.btn-*` variant, the shared CSS above does not cover it and nothing else would notice,
     * so this test fails and names the variant.
     *
     * `ss-btn--*` is excluded deliberately: the design system's own buttons are built for both
     * themes and already ratcheted, and the live audit measures them at 8.83:1 and 11.81:1.
     */
    public function test_no_confirmation_pathway_uses_a_button_variant_the_repair_does_not_cover(): void
    {
        $covered = ['btn-success', 'btn-danger'];
        $found = [];

        foreach ($this->swalCallSites() as $file) {
            $source = @file_get_contents($file);
            if ($source === false) {
                continue;
            }

            /*
             * THE SCANNER HAS TO SURVIVE A TERNARY, and the first version did not. `custom.js`
             * reads `confirmButton: tone === 'danger' ? 'ss-btn ss-btn--danger' : 'btn btn-success'`
             * and a regex that takes the first quoted string after the colon returns `danger` --
             * which this test then reported as an uncovered button variant. A scanner bug filed as
             * a product finding is the failure mode this project has paid for most often, so the
             * comparison operands are removed BEFORE the class lists are read, rather than the
             * result being filtered afterwards (a `btn`-prefix filter would have hidden a genuinely
             * unrecognised class such as `my-confirm` and silently cost the ratchet its bite).
             */
            foreach ($this->buttonClassLiterals($source) as $classList) {
                foreach (preg_split('/\s+/', trim($classList)) as $class) {
                    if ($class === '' || $class === 'btn' || str_starts_with($class, 'ss-btn')
                        || str_starts_with($class, 'm-') || in_array($class, $covered, true)) {
                        continue;
                    }
                    $found[$class][] = str_replace(dirname(__DIR__, 3) . '/', '', $file);
                }
            }
        }

        $this->assertSame([], array_keys($found),
            "A confirmation dialog uses a button variant the P2.5-PKG-J rules do not cover:\n"
            . implode("\n", array_map(
                static fn ($k, $v) => "  .{$k}  <- " . implode(', ', array_unique($v)),
                array_keys($found),
                $found
            ))
            . "\nEither add a `[data-ss-theme=\"dark\"] .swal2-popup` ink rule for it in "
            . 'store-syria-ds.css and measure it with audit-dialog-contrast.cjs, or switch the '
            . 'pathway to the design system\'s `ss-btn` variants.');
    }

    /** Without this the ratchet above passes vacuously the day the scan stops finding anything. */
    public function test_the_scan_sees_the_confirmation_pathways_at_all(): void
    {
        $sites = $this->swalCallSites();

        $this->assertGreaterThanOrEqual(5, count($sites),
            'The scan found fewer than five SweetAlert call sites. It used to find the shared '
            . 'handler, the order-detail replace dialog, two refund dialogs and four vendored '
            . 'modules, so either the pathways moved or the scan is broken.');

        $joined = implode('|', $sites);
        $this->assertStringContainsString('public/js/custom.js', $joined,
            'The shared .show_confirm handler is not in the scan, so the ratchet covers nothing.');
    }

    /**
     * THE SCANNER'S OWN TEST. The real `custom.js` line is a ternary whose condition is a quoted
     * string, and the first version of the ratchet reported that string -- `danger` -- as an
     * uncovered button variant. Both halves are asserted: the comparison operand is dropped, and
     * an unrecognised class is still returned rather than filtered away.
     */
    public function test_the_class_list_extractor_survives_a_ternary(): void
    {
        $ternary = "confirmButton: tone === 'danger' ? 'ss-btn ss-btn--danger' : 'btn btn-success',";

        $this->assertSame(
            ['ss-btn ss-btn--danger', 'btn btn-success'],
            $this->buttonClassLiterals($ternary),
            'The extractor is reading the ternary condition as a class list again.');

        $this->assertSame(
            ['btn btn-success', 'btn btn-danger'],
            $this->buttonClassLiterals("customClass: { confirmButton: 'btn btn-success', cancelButton: 'btn btn-danger' },"),
            'The extractor no longer reads the plain single-line shape the refund dialogs use.');

        // and it must NOT quietly drop something it does not recognise -- that is the whole bite
        $this->assertSame(
            ['my-own-confirm'],
            $this->buttonClassLiterals("confirmButton: 'my-own-confirm',"),
            'An unrecognised class is being filtered out, so the ratchet would miss a new variant.');
    }

    /** The shared handler still carries the opt-in tone PKG-I added; this slice did not remove it. */
    public function test_the_shared_handler_still_offers_the_design_system_tone(): void
    {
        $handler = $this->read(self::HANDLER);

        $this->assertStringContainsString('data-confirm-tone', $handler);
        $this->assertMatchesRegularExpression(
            '/confirmButton:\s*tone === \'danger\'\s*\?\s*\'ss-btn ss-btn--danger\'\s*:\s*\'btn btn-success\'/',
            $handler,
            'The opt-in danger tone from P2.5-PKG-I is gone. Its two triggers would silently fall '
            . 'back to the default pairing.');
    }

    // =============================================================================================
    //  helpers
    // =============================================================================================

    /**
     * Every file that fires a SweetAlert with its own button classes — first-party and vendored.
     * Vendored modules are INCLUDED because the shared rules reach them and a new variant there is
     * exactly as invisible as one here; nothing under packages/workdo/ is edited by this slice.
     */
    private function swalCallSites(): array
    {
        $roots = [
            dirname(__DIR__, 3) . '/public/js',
            dirname(__DIR__, 3) . '/resources/views',
            dirname(__DIR__, 3) . '/packages/workdo',
        ];

        $hits = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['js', 'php'], true)) {
                    continue;
                }
                $source = @file_get_contents($file->getPathname());
                if ($source !== false && preg_match('/(?:confirmButton|cancelButton)\s*:/', $source)) {
                    $hits[] = $file->getPathname();
                }
            }
        }

        sort($hits);

        return $hits;
    }

    /**
     * Every class list assigned to `confirmButton`/`cancelButton` in a source file.
     *
     * Comparison operands are removed before the literals are read — see the note at the call
     * site. Exercised directly by test_the_class_list_extractor_survives_a_ternary so that a
     * scanner bug cannot pass itself off as either a finding or a clean bill of health.
     */
    private function buttonClassLiterals(string $source): array
    {
        preg_match_all('/(?:confirmButton|cancelButton)\s*:\s*([^,}\n]+)/', $source, $m);

        $literals = [];
        foreach ($m[1] as $expression) {
            $withoutComparisons = preg_replace(
                '/(?:===|!==|==|!=)\s*([\'"])(?:(?!\1).)*\1/',
                ' ',
                $expression
            );
            preg_match_all('/[\'"]([^\'"]+)[\'"]/', (string) $withoutComparisons, $inner);
            foreach ($inner[1] as $literal) {
                $literals[] = $literal;
            }
        }

        return $literals;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Half-up, which is what both PHP's round() and the browser's Math.round() do. */
    private function composite(array $tint, float $alpha, array $ground): array
    {
        return array_map(
            static fn (int $i): int => (int) round($tint[$i] * $alpha + $ground[$i] * (1 - $alpha)),
            [0, 1, 2]
        );
    }

    private function ratio(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(array $rgb): float
    {
        $linear = array_map(static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
