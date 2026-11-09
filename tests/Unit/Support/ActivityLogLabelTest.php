<?php

namespace Tests\Unit\Support;

use App\Support\ActivityLogLabel;
use Tests\TestCase;

/**
 * THE CUSTOMER TIMELINE PRINTED ITS EVENT TYPE RAW, FOURTEEN TIMES.  P2.5-PKG-G SLICE 5
 * =================================================================================================
 *
 * `customer/timeline.blade.php` rendered `{{ $value->log_type }}` bare at fourteen sites — never
 * through `__()`. The stored values are the lowercase enum strings `register`, `last login`,
 * `add wishlist`, `delete wishlist`, `add to cart`, `remove to cart` and `place order`, and ALL
 * SEVEN were measured absent from `resources/lang/ar.json`. So wrapping the call in `__()` alone
 * would have changed nothing — there was nothing to translate to.
 *
 * WHY THE PASS-THROUGH MATTERS, and why it is asserted rather than left to read. The sibling
 * screen's own mapper records what the alternative costs: a 27-branch chain with no `@else`
 * rendered 52 of 71 payment values BLANK. An unknown activity type here returns unchanged, so a
 * writer this map has not been told about is visible on the screen rather than silently erased.
 *
 * IT EXTENDS THE LARAVEL BASE CASE, and the first version of this file did not — it extended
 * PHPUnit's and claimed in this very docblock that "`__()` falls through to the key when no
 * translator is bound". IT DOES NOT: it throws `BindingResolutionException: Target class
 * [translator] does not exist`. Two of the five tests failed on that immediately. The claim is
 * recorded rather than quietly deleted because a comment that is wrong about the framework is
 * the same defect class this package has been correcting in the views it touches.
 *
 * No database is used: `RefreshDatabase` is deliberately absent and nothing here queries.
 */
class ActivityLogLabelTest extends TestCase
{
    public function test_the_map_covers_every_stored_activity_type(): void
    {
        // The seven the writers really store. If a writer adds an eighth, this is the list to
        // update — and `test_an_unknown_type_passes_through_unchanged` is why forgetting to is
        // visible rather than silent.
        $stored = [
            'register', 'last login', 'add wishlist', 'delete wishlist',
            'add to cart', 'remove to cart', 'place order',
        ];

        $this->assertSame($stored, ActivityLogLabel::known(),
            'the map no longer matches the stored vocabulary the timeline branches on. The KEYS '
            . 'are database values and must not change; only the text a merchant reads is '
            . 'translated');
    }

    public function test_every_known_type_resolves_to_something_other_than_the_raw_value(): void
    {
        // The whole point: a merchant must not read `add wishlist` on a screen. Under the test
        // locale `__()` returns the English entry, so this asserts the label differs from the
        // STORED value — the property that makes the ar.json entry reachable at all.
        foreach (ActivityLogLabel::known() as $type) {
            $this->assertNotSame($type, ActivityLogLabel::for($type),
                "the stored value '{$type}' resolves to itself, so the timeline would still print "
                . 'the raw database string');
            $this->assertNotSame('', trim(ActivityLogLabel::for($type)),
                "the stored value '{$type}' resolves to nothing at all");
        }
    }

    public function test_an_unknown_type_passes_through_unchanged(): void
    {
        // NOT a dash and NOT empty. The sibling payment mapper's comment records what a
        // placeholder costs: 52 of 71 values rendered blank and nobody noticed for a release.
        $this->assertSame('some new event', ActivityLogLabel::for('some new event'));
    }

    public function test_an_empty_or_null_type_yields_an_empty_string_rather_than_a_label(): void
    {
        $this->assertSame('', ActivityLogLabel::for(null));
        $this->assertSame('', ActivityLogLabel::for(''));
        $this->assertSame('', ActivityLogLabel::for('   '));
    }

    public function test_the_stored_value_is_trimmed_before_lookup(): void
    {
        // A stored value with stray whitespace must still find its label rather than falling
        // through to the pass-through arm and printing itself.
        $this->assertSame(ActivityLogLabel::for('register'), ActivityLogLabel::for('  register  '));
    }
}
