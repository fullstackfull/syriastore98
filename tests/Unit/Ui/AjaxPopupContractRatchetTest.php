<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-21 / WS F closing rule — every `data-ajax-popup` caller survives a screen migration.
 * =================================================================================================
 *
 * The rule is the master prompt's: *"Preserve existing AJAX modal contracts during migration."*
 * The risk it guards is specific and has already happened elsewhere in this phase -- a screen is
 * converted to the design system, the markup is rewritten, and one trigger attribute is dropped on
 * the way. Nothing fails, nothing 500s; a button simply stops opening its dialog, on one screen,
 * and no test notices because no test knew the attribute was there.
 *
 * SO THIS COUNTS THEM. A ratchet, not a per-screen assertion: `PilotScreenContractTest` already
 * guards the attribute-option pilot, and it is the only screen guarded. One screen out of 83 is
 * not the rule the prompt states.
 *
 * TWO MECHANISMS, AND THEY ARE NOT THE SAME THING -- which a substring count gets wrong.
 * `data-ajax-popup` opens a dialog. `data-ajax-popup-over` opens one STACKED OVER an existing
 * dialog, bound separately in `public/js/custom.js:72`. A `grep` for the first matches the second,
 * and the first measurement taken here reported 154 occurrences by making exactly that mistake.
 * Measured separately, with the `=` included so neither matches the other:
 *
 *     data-ajax-popup=        128 occurrences across 83 files
 *     data-ajax-popup-over=    19 occurrences across 16 files
 *
 * THE SECOND NUMBER IS THE NESTED-MODAL SURFACE P2-01-19 OWNS, and it is counted here rather than
 * only there, because the two rows pull in opposite directions: F3/F4 wants the nested workflows
 * gone, and this rule wants no contract silently dropped. Counting both means a change that
 * removes a nested dialog has to come here and say so, instead of the number quietly sliding.
 *
 * WHY A FLOOR AND NOT AN EQUALITY for the plain attribute: adding a screen that legitimately opens
 * a dialog is ordinary work and should not fail CI. Removing one is what this guards. The nested
 * count is an EQUALITY, because nothing should be adding more stacked dialogs.
 */
class AjaxPopupContractRatchetTest extends TestCase
{
    /** Measured 2026-10-28. A migration that drops a trigger lands below this. */
    private const PLAIN_FLOOR = 128;
    private const PLAIN_FILES_FLOOR = 83;

    /** Measured 2026-10-28. P2-01-19 owns bringing this DOWN, deliberately and with a note. */
    private const NESTED_EXACT = 19;
    private const NESTED_FILES_EXACT = 16;

    /** @return array{0:int,1:int} [occurrences, files] */
    private function tally(string $needle): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $occurrences = 0;
        $files = 0;

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $hits = substr_count((string) file_get_contents($file->getPathname()), $needle);
            if ($hits > 0) {
                $occurrences += $hits;
                $files++;
            }
        }

        return [$occurrences, $files];
    }

    public function test_the_plain_ajax_popup_contract_has_not_lost_a_caller(): void
    {
        // `data-ajax-popup-over=` contains `data-ajax-popup`, so the `=` is what keeps the two
        // counts apart. Without it this assertion silently includes the nested ones.
        [$occurrences, $files] = $this->tally('data-ajax-popup=');

        $this->assertGreaterThanOrEqual(self::PLAIN_FLOOR, $occurrences,
            'A `data-ajax-popup` trigger has been removed. If a screen was migrated to the design '
            . 'system, one of its buttons has stopped opening its dialog -- silently, because '
            . 'nothing 500s when an attribute goes missing. If the removal is deliberate, lower '
            . 'PLAIN_FLOOR in the same change and say why.');

        $this->assertGreaterThanOrEqual(self::PLAIN_FILES_FLOOR, $files,
            'A whole view stopped emitting ajax-popup triggers.');
    }

    public function test_the_nested_dialog_surface_has_not_grown(): void
    {
        [$occurrences, $files] = $this->tally('data-ajax-popup-over=');

        $this->assertSame(self::NESTED_EXACT, $occurrences,
            'The number of STACKED dialogs changed. The master prompt asks for nested modal '
            . 'workflows to be eliminated, so this number may only go DOWN, and only in a change '
            . 'that updates NESTED_EXACT and records which site it removed. Growth here is a new '
            . 'nested workflow.');

        $this->assertSame(self::NESTED_FILES_EXACT, $files,
            'The set of views carrying a stacked dialog changed.');
    }

    public function test_the_two_mechanisms_are_still_distinct_in_the_script(): void
    {
        /*
         * If the `-over` binding were ever folded into the plain one, every stacked dialog would
         * become an ordinary one and the counts above would stop meaning anything -- the ratchet
         * would pass while the behaviour it describes had gone.
         */
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public/js/custom.js');

        $this->assertStringContainsString('data-ajax-popup-over="true"', $js,
            'custom.js no longer binds the stacked-dialog mechanism separately, so the nested '
            . 'count above no longer describes anything');
        $this->assertStringContainsString('data-ajax-popup="true"', $js,
            'custom.js no longer binds the plain dialog mechanism');
    }
}
