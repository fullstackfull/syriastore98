<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * REGISTERED, BUT NOTHING HAS HAPPENED YET.  P2.5-WAVE-16
 * =================================================================================================
 *
 * `customer/timeline.blade.php` had TWO branches and needed three. The outer
 * `@if (!empty($customer_data->regiester_date))` answers "never registered" in its `@else`. The
 * commoner case -- registered, but no activity -- fell through to:
 *
 *     <div @if (count($logs) > 0) class="my-custom-timeline" @endif>
 *
 * which with zero logs rendered an UNCLASSED, EMPTY div and a `@foreach` over nothing. The merchant
 * got a blank panel under the stat cards with no word of explanation, and no way to tell "this
 * customer has done nothing" from "this screen is broken".
 *
 * MEASURED, not assumed: `activity_logs` holds **0 rows** in the dev fixture, so this is the state
 * the screen actually renders today -- which is also why it could be verified immediately rather
 * than needing data seeded first.
 *
 * `@forelse` / `@empty` appear ZERO times in all 511 lines of this view, and the work-list records
 * the same absence across all 25 files of the customers/stores area.
 *
 * THE ICON WAS CHECKED BEFORE USE. `ti-timeline` -- the obvious name -- does NOT exist in
 * `tabler-icons.min.css`, and a missing name fails silently: valid CSS that paints nothing.
 * `ti-activity` does exist and is what an activity timeline means. IconNamesExistTest enforces that
 * generally; this records why the first choice was discarded.
 */
class CustomerTimelineEmptyStateTest extends TestCase
{
    private const VIEW = 'resources/views/customer/timeline.blade.php';

    private function read(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::VIEW;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** PREMISE. The branch this guards must still exist, or the assertion below guards nothing. */
    public function test_the_registered_branch_still_exists(): void
    {
        $this->assertStringContainsString(
            'count($logs) > 0',
            $this->read(),
            'the timeline no longer switches on the log count, so the empty case may have moved'
        );
    }

    /** The empty case is answered, and answered with the design system's own component. */
    public function test_the_timeline_answers_the_no_activity_case(): void
    {
        $src = $this->read();

        $this->assertMatchesRegularExpression(
            '/@if\s*\(\s*count\(\$logs\)\s*===\s*0\s*\)/',
            $src,
            'the timeline has no branch for a registered customer with no activity, so it renders '
                . 'an empty unclassed div and nothing else'
        );
        $this->assertStringContainsString(
            '<x-ss.empty',
            $src,
            'the no-activity case is answered with ad-hoc markup instead of the design system\'s '
                . 'empty-state component'
        );
    }

    /** Both strings are translatable -- customer/ is inside the Arabic coverage ratchet. */
    public function test_the_empty_state_copy_is_translatable(): void
    {
        $src = $this->read();

        $this->assertMatchesRegularExpression(
            '/:title="__\(/',
            $src,
            'the empty-state title is a bare string, so it renders in English for an Arabic merchant'
        );
        $this->assertMatchesRegularExpression(
            '/:body="__\(/',
            $src,
            'the empty-state body is a bare string'
        );
    }

    /** `ti-timeline` does not exist in the bundled font; a missing icon name paints nothing. */
    public function test_the_empty_state_does_not_ask_for_an_icon_the_font_lacks(): void
    {
        $this->assertStringNotContainsString(
            'icon="timeline"',
            $this->read(),
            'the empty state asks for ti-timeline, which is absent from tabler-icons.min.css and '
                . 'renders as nothing at all'
        );
    }
}
