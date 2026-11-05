<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THREE ICON FAMILIES IN ONE ADMIN, AND TWENTY FILES MIXING THEM.  P2.5-WAVE-4 (ratchet)
 * =================================================================================================
 *
 * The directive asks for "a coherent icon system (consistent stroke weight, sizing, alignment)".
 * Measured across the 439 merchant Blade files (storefront, SaaS plans and marketplace excluded):
 *
 *     tabler         451 uses across 168 files     <- the house family
 *     fontawesome    130 uses across  46 files
 *     feather          4 uses across   3 files
 *     files mixing more than one family:  20
 *
 * These cannot be made to look alike by spacing them better. Tabler and Feather are 2px strokes on
 * a 24px grid; FontAwesome Solid is a filled glyph. Putting `fas fa-save` beside `ti ti-device-
 * floppy` in one toolbar gives two different drawing systems the same job.
 *
 * THIS FILE IS A RATCHET, NOT THE REPAIR. Converging on one family means choosing a Tabler
 * equivalent for 130 FontAwesome uses, and the directive is explicit: "Do not replace every icon
 * blindly." A wrong-but-similar glyph is worse than an inconsistent one, because it misleads. So
 * this pins the numbers where they are and fails if the mixture GROWS, which keeps the problem
 * from outrunning the fix.
 *
 * THE DETECTOR IS PART OF WHAT IS TESTED, and that is not ceremony. My first version of this
 * measurement reported "feather 95 uses across 35 files" and it was WRONG: it matched a bare
 * `icon-[a-z-]+` prefix, which in this codebase is a PROJECT CLASS CONVENTION -- `icon-input`,
 * `icon-field-label`, `icon-only`, `icon-picker-button` -- and not Feather at all. The real
 * figure is 4. A detector that counts layout classes as icons produces a confident number about
 * nothing, so `test_the_detector_does_not_count_project_class_names` holds it to the real shape.
 */
class IconFamilyCoherenceTest extends TestCase
{
    /** Directories that are not the merchant admin. */
    private const NOT_MERCHANT = ['front_end', 'plans', 'marketplace'];

    /**
     * Matched INSIDE a class attribute only. A bare prefix match is what produced the wrong
     * Feather count: `icon-input` is a layout class, not an icon.
     */
    private const FAMILIES = [
        'tabler' => '/class="[^"]*\bti\s+ti-[a-z0-9-]+/',
        'fontawesome' => '/class="[^"]*\bfa[srbl]?\s+fa-[a-z0-9-]+/',
        'feather' => '/class="[^"]*\bfeather\s+icon-[a-z0-9-]+/',
        'material' => '/class="[^"]*\bmaterial-icons/',
        'bootstrap' => '/class="[^"]*\bbi\s+bi-[a-z0-9-]+/',
    ];

    /** MEASURED 2026-11-05. Lower these as the convergence lands; never raise them. */
    private const CEILING_FONTAWESOME_FILES = 46;
    private const CEILING_FEATHER_FILES = 3;
    private const CEILING_MIXED_FILES = 20;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, list<string>> family => files */
    private function census(): array
    {
        $base = $this->root() . '/resources/views';
        $out = array_fill_keys(array_keys(self::FAMILIES), []);

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($base) + 1);
            foreach (self::NOT_MERCHANT as $skip) {
                if (str_starts_with($rel, $skip . '/')) {
                    continue 2;
                }
            }
            $src = (string) file_get_contents($file->getPathname());
            foreach (self::FAMILIES as $family => $pattern) {
                if (preg_match($pattern, $src) === 1) {
                    $out[$family][] = $rel;
                }
            }
        }

        return $out;
    }

    /** PREMISE. Without this the ceilings below could be satisfied by a detector that sees nothing. */
    public function test_the_census_actually_reaches_the_merchant_views(): void
    {
        $census = $this->census();

        $this->assertGreaterThan(
            100,
            count($census['tabler']),
            'the census found almost no Tabler icons, so it is not reading the merchant views at all '
                . 'and every ceiling below is vacuous'
        );
    }

    /**
     * The detector must not count project class names as icons.
     *
     * This is the exact bug that produced a "feather 95 / 35 files" reading: `icon-input` and
     * friends are layout classes. If the Feather pattern ever matches them again the real figure
     * is buried under noise, and the ceiling stops meaning anything.
     */
    public function test_the_detector_does_not_count_project_class_names(): void
    {
        $decoys = [
            '<span class="icon-input"></span>',
            '<label class="icon-field-label"></label>',
            '<button class="icon-only"></button>',
            '<div class="icon-picker-button"></div>',
            '<i class="icon-more-vertical"></i>',
        ];

        foreach ($decoys as $decoy) {
            $this->assertSame(
                0,
                preg_match(self::FAMILIES['feather'], $decoy),
                "the Feather detector counts the project class name in: {$decoy}"
            );
        }

        // ...and it must still catch the real thing, or the test above passes by being blind.
        $this->assertSame(
            1,
            preg_match(self::FAMILIES['feather'], '<i class="feather icon-more-vertical"></i>'),
            'the Feather detector no longer recognises a genuine Feather icon'
        );
    }

    public function test_the_foreign_icon_families_do_not_spread(): void
    {
        $census = $this->census();

        $this->assertLessThanOrEqual(
            self::CEILING_FONTAWESOME_FILES,
            count($census['fontawesome']),
            sprintf(
                'FontAwesome is now in %d merchant files, above the recorded %d. Use the house '
                    . 'family (Tabler) in new work; lower the ceiling as files convert, never raise it.',
                count($census['fontawesome']),
                self::CEILING_FONTAWESOME_FILES
            )
        );

        $this->assertLessThanOrEqual(
            self::CEILING_FEATHER_FILES,
            count($census['feather']),
            'Feather is spreading. It is down to a handful of files and is the cheapest family to retire.'
        );

        // Neither of these is in the merchant admin at all, and neither should arrive.
        $this->assertSame([], $census['material'], 'a Material Icons glyph has appeared in the merchant admin');
        $this->assertSame([], $census['bootstrap'], 'a Bootstrap Icons glyph has appeared in the merchant admin');
    }

    public function test_no_new_file_mixes_icon_families(): void
    {
        $census = $this->census();

        $counts = [];
        foreach ($census as $family => $files) {
            foreach ($files as $f) {
                $counts[$f] = ($counts[$f] ?? 0) + 1;
            }
        }
        $mixed = array_keys(array_filter($counts, static fn ($n) => $n > 1));
        sort($mixed);

        $this->assertLessThanOrEqual(
            self::CEILING_MIXED_FILES,
            count($mixed),
            sprintf(
                "%d merchant files now mix icon families, above the recorded %d:\n  %s\n"
                    . 'Two drawing systems in one toolbar cannot be made consistent by spacing.',
                count($mixed),
                self::CEILING_MIXED_FILES,
                implode("\n  ", $mixed)
            )
        );
    }
}
