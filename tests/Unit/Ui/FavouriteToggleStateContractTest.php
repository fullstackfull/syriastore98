<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2-01-15 / WS A8 — the favourite toggle's executing, success and failure states.
 * =================================================================================================
 *
 * The behaviour itself is driven in a real browser in both languages by
 * `docs/development/phase-2/tools/check-favourite-toggle-live.cjs`. This file exists so the
 * CONTRACT that behaviour depends on cannot be removed without CI noticing, because a browser run
 * is not part of the suite.
 *
 * WHAT WAS ACTUALLY WRONG, after measurement cut the claimed gap down three times:
 * `cmdSearch()` already announced a searching note, the panel already set `aria-busy` and rendered
 * visible loading text, `.ss-command__star[disabled]` already gave `cursor: progress`, and the core
 * merchant DataTables are SYNCHRONOUS -- only the WooCommerce import screens use ajax -- so a
 * loading state there would have been decoration. Two real defects remained, both in the SUCCESS
 * state of the favourite toggle:
 *
 *   1. the glyph never changed. `is-on` moves `color` to the brand and nothing else, so the state
 *      was conveyed by COLOUR ALONE -- which this design system rejects everywhere else;
 *   2. `title` and the visually-hidden label were left exactly as rendered, so after adding a page
 *      the accessible name still read "Add :page to favourites" while `aria-pressed` beside it said
 *      `true`. The control stated the opposite of its own state.
 *
 * And nothing at all was announced between the click and the answer.
 *
 * ALL THE COPY TRAVELS IN THE MARKUP, which is the existing convention in that partial -- its own
 * comment reads "The endpoint and the strings the toggle needs, so the JS holds no URLs or copy."
 * The JS swaps between attributes; it introduces no strings and no second notification mechanism,
 * reusing the shared `announceLive()` region.
 */
class FavouriteToggleStateContractTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function palette(): string
    {
        return $this->read('resources/views/partision/command-palette.blade.php');
    }

    private function js(): string
    {
        return $this->read('public/js/store-syria-ds.js');
    }

    public function test_every_favourite_control_carries_both_states_copy(): void
    {
        $markup = $this->palette();

        // Two star buttons (favourited and not) plus the "add this page" button.
        $this->assertSame(3, substr_count($markup, 'data-ss-favourite="'),
            'the number of favourite controls changed; this contract covers all of them');

        foreach (['data-ss-fav-title-on', 'data-ss-fav-title-off',
                  'data-ss-fav-label-on', 'data-ss-fav-label-off'] as $attribute) {
            $this->assertSame(2, substr_count($markup, $attribute),
                "{$attribute} is missing from one of the two star controls, so a toggle there "
                . 'leaves its title or accessible name stating the opposite of its state');
        }

        foreach (['data-ss-fav-text-on', 'data-ss-fav-text-off'] as $attribute) {
            $this->assertStringContainsString($attribute, $markup,
                "the \"add this page\" button has no {$attribute}, so its visible label goes stale");
        }
    }

    public function test_every_favourite_control_has_a_swappable_glyph_and_label_target(): void
    {
        $markup = $this->palette();

        $this->assertSame(3, substr_count($markup, 'data-ss-fav-glyph'),
            'a control without a glyph target conveys its state by colour alone');
        $this->assertSame(2, substr_count($markup, 'data-ss-fav-label>'),
            'a star without a label target keeps its rendered accessible name after a toggle');
    }

    public function test_the_busy_copy_travels_with_the_endpoint_rather_than_in_the_script(): void
    {
        $this->assertStringContainsString('data-ss-favourite-busy', $this->palette());
        $this->assertStringContainsString("data-ss-favourite-busy", $this->js(),
            'the script does not read the busy copy, so nothing is announced while in flight');
    }

    public function test_the_script_announces_the_executing_state_through_the_shared_region(): void
    {
        $js = $this->js();

        $this->assertMatchesRegularExpression(
            "/data-ss-favourite-busy'\\);\\s*\\n\\s*if \\(busyText\\) \\{ announceLive\\(busyText\\); \\}/",
            $js,
            'the executing state is not announced through announceLive(), or a second notification '
            . 'mechanism was introduced beside it'
        );
        $this->assertStringContainsString("btn.setAttribute('aria-busy', 'true');", $js);
        $this->assertStringContainsString("btn.removeAttribute('aria-busy');", $js,
            'aria-busy is set and never cleared, so the control stays announced as busy');
    }

    public function test_the_script_moves_every_affordance_on_success(): void
    {
        $js = $this->js();

        foreach ([
            "[data-ss-fav-glyph]" => 'the glyph is not swapped, so success is colour-only',
            "data-ss-fav-title-on" => 'the title is not swapped, so it goes stale',
            "[data-ss-fav-label]" => 'the accessible name is not swapped, so it goes stale',
            "[data-ss-fav-text]" => 'the visible label is not swapped, so it goes stale',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $js, $why);
        }
    }

    public function test_both_new_strings_are_translated_in_both_languages(): void
    {
        foreach (['resources/lang/en.json', 'resources/lang/ar.json'] as $file) {
            $strings = json_decode($this->read($file), true);
            $this->assertIsArray($strings, "{$file} is not valid JSON");

            foreach (['Saving your change…', 'Remove this page from favourites'] as $key) {
                $this->assertArrayHasKey($key, $strings, "{$file} is missing `{$key}`");
                $this->assertNotSame('', trim((string) $strings[$key]),
                    "{$file} has an empty translation for `{$key}`, which announces nothing");
            }
        }

        // And the Arabic must actually differ from the key, or it is untranslated.
        $ar = json_decode($this->read('resources/lang/ar.json'), true);
        foreach (['Saving your change…', 'Remove this page from favourites'] as $key) {
            $this->assertNotSame($key, $ar[$key], "`{$key}` is not translated into Arabic");
        }
    }
}
