<?php

namespace Tests\Unit\Ui;

use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * F177 — THE COMMAND PALETTE OPENED ON `focusin` AND SWALLOWED THE KEYBOARD.
 * ==========================================================================
 *
 * `public/js/store-syria-ds.js` bound the palette's opener to a `focusin` listener on the
 * sidebar's search input, and `cmdOpen()` ends with `cmd.input.focus()`. So a keyboard user
 * tabbing down the page reached `#sideBarsearch`, the palette opened, focus was moved into it,
 * and the palette is a dialog with its own Tab cycle — so Tab never advanced past it again.
 *
 * MEASURED on the running app, `/dashboard`, before the fix: the page has **75 tabbable
 * elements** and the shared header's seven controls begin at DOM index 37. Of 80 Tab presses from
 * the top of the document, **zero** reached the header. Press 3 left focus on
 * `#ss-workspace-trigger` with the palette `display:none, hidden=true`; by press 4 focus was on
 * `#ss-command-input` and the palette was `display:block, hidden=false`. From there the sequence
 * cycled six elements for ever: the palette's input, its three quick-create links, a favourite
 * and its remove button.
 *
 * So the merchant header's Notifications, Quick Add, store switcher and language switcher — and
 * everything else below the sidebar search, 72 of 75 controls — were unreachable by keyboard on
 * every merchant screen. Escape closes the palette and returns focus to the search, but the next
 * Tab re-opens it, so there is no way through at all.
 *
 * THE COMMENT ON THE HANDLER ASSERTED THE OPPOSITE, and that is the part worth keeping:
 * *"Focusing it offers the palette; ignoring the offer changes nothing."* Ignoring the offer is
 * exactly what a keyboard user cannot do, because the offer takes their focus. A handler that
 * opens a modal dialog on a FOCUS event cannot be non-intrusive; only a deliberate gesture can.
 *
 * `click` RATHER THAN REMOVING THE OPENER, because the palette has no visible trigger — its own
 * docblock says it is "opened with Ctrl/Cmd+K or from the sidebar search", and Ctrl/Cmd+K alone
 * is not discoverable. A click is deliberate and a mouse user clicking the search gets the same
 * palette they get today; Tab and Enter on a text input produce no click, so the keyboard path is
 * freed. The sidebar search keeps its own navigation filter, which is bound separately on `keyup`.
 */
class CommandPaletteFocusTrapTest extends TestCase
{
    use StripsSourceComments;

    private const SCRIPT = 'public/js/store-syria-ds.js';

    public function test_the_palette_is_not_opened_by_a_focus_event(): void
    {
        $code = $this->sourceWithoutComments(self::SCRIPT);

        /*
         * Any focus-family listener whose body opens the palette. Matched on the listener and its
         * body rather than on the string `focusin` alone, because the file legitimately uses
         * focus events elsewhere (the panel's own focus management, among others).
         */
        $this->assertSame(
            0,
            preg_match(
                "/addEventListener\(\s*'(focus|focusin|focusout)'[^;]*?cmdOpen\s*\(/s",
                $code
            ),
            'the command palette is opened from a focus event again. A keyboard user cannot '
            . 'decline that offer: cmdOpen() moves focus into the dialog, and the dialog has its '
            . 'own Tab cycle, so everything after the trigger becomes unreachable. Measured: 0 of '
            . '80 Tab presses reached the shared header.'
        );
    }

    public function test_the_palette_is_still_reachable_without_a_keyboard_shortcut(): void
    {
        $code = $this->sourceWithoutComments(self::SCRIPT);

        /*
         * THE OTHER HALF, so the fix cannot be "delete the opener". The palette has no visible
         * trigger in the chrome, so if the sidebar-search entry disappears entirely the only way
         * in is Ctrl/Cmd+K, which no merchant discovers on their own.
         */
        $this->assertSame(
            1,
            preg_match("/addEventListener\(\s*'click'[^;]*?cmdOpen\s*\(/s", $code),
            'nothing opens the palette on a click any more, so the only way in is Ctrl/Cmd+K'
        );

        $this->assertStringContainsString(
            'sideBarsearch',
            $code,
            'the sidebar search no longer opens the palette at all'
        );
    }

    public function test_the_keyboard_shortcut_survives(): void
    {
        $code = $this->sourceWithoutComments(self::SCRIPT);

        $this->assertSame(
            1,
            preg_match("/(ctrlKey|metaKey)[^;]*?'k'/s", $code),
            'Ctrl/Cmd+K no longer opens the palette'
        );
    }

    /**
     * AND THE OPENER STILL MOVES FOCUS INTO THE DIALOG, which is correct for a deliberate open
     * and is the very thing that made the focus-triggered version a trap. Asserted so a later
     * reader does not "fix" F177 by removing the focus call and leaving a dialog nobody can type
     * into.
     */
    public function test_a_deliberate_open_still_focuses_the_input(): void
    {
        $code = $this->sourceWithoutComments(self::SCRIPT);

        $this->assertSame(
            1,
            preg_match('/function cmdOpen\([^)]*\)\s*\{(?:[^{}]|\{[^{}]*\})*cmd\.input\.focus\(\)/s', $code),
            'cmdOpen() no longer focuses the palette input, so a merchant who opens it cannot type'
        );
    }
}
