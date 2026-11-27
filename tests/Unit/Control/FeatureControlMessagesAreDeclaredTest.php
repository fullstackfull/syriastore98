<?php

namespace Tests\Unit\Control;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * EVERY SENTENCE THE FEATURE CONTROL SURFACE PRINTS IS DECLARED IN ENGLISH *AND* ARABIC.
 * =================================================================================================
 *
 * `__()` RETURNS ITS OWN KEY WHEN NOTHING IS DECLARED. So an undeclared English sentence reads
 * perfectly on the English page — by accident — and reads IN ENGLISH on the Arabic one. That is a
 * failure no rendered-page assertion in English can see, and the exact reason this project already
 * ratchets its other operator surfaces the same way
 * (`PlatformResourceMessagesAreDeclaredTest`, `StorefrontMessagesAreDeclaredTest`,
 * `AnalyticsMessagesAreDeclaredTest`). This file is Phase 06 `CTRL-001`/`002`/`003`'s.
 *
 * CLAUDE.md records that the merchant admin is bilingual **Arabic (RTL) and English (LTR)** and that
 * any UI change must be checked in both directions. `PlatformFeatureControlScreenTest` drives the
 * rendered page in both; this file is the half that can see a sentence nobody translated, which the
 * rendered page cannot — a missing Arabic declaration renders as English, HTTP 200, no error.
 *
 * COMMENTS ARE STRIPPED FIRST. CLAUDE.md records this class of census being satisfied — or broken —
 * by prose ABOUT the thing it measures **eight times**, four of them on one day. The view's own
 * docblock names `__()` and quotes key fragments while explaining the convention, and the
 * controller's explains why it does NOT collect a free-text note. Both would pollute the extraction.
 *
 * WHAT IT DOES NOT COVER, stated rather than implied: `__()` only, in the sources listed below.
 * Nothing in them uses `@lang()` or `trans()`, and a key built at runtime from a variable cannot be
 * read statically by any census — which is why the view's vocabulary maps are written as literal
 * `__()` calls inside array literals rather than as `__('prefix.' . $value)`.
 */
final class FeatureControlMessagesAreDeclaredTest extends TestCase
{
    use StripsSourceComments;

    /** The Phase 06 feature-control surface: producer, screen, controller and the models. */
    private const SURFACES = [
        'app/Support/Control/FeatureControl.php',
        'app/Support/Control/FeatureControlOverview.php',
        'app/Support/Control/PlatformChangeLog.php',
        'app/Support/Control/FeatureDecision.php',
        'app/Models/PlatformFeatureFlag.php',
        'app/Models/PlatformFeatureFlagTarget.php',
        'app/Models/PlatformPilotGroupMember.php',
        'app/Models/PlatformControlChange.php',
        'app/Http/Controllers/PlatformFeatureControlController.php',
        'resources/views/platform/feature_control.blade.php',
        'app/Support/Control/MaintenanceAnnouncements.php',
        'app/Models/PlatformMaintenanceAnnouncement.php',
        'app/Http/Controllers/PlatformMaintenanceController.php',
        'resources/views/platform/maintenance.blade.php',
        'resources/views/components/ss/maintenance-notice.blade.php',
    ];

    /**
     * THE SOURCES THAT ARE SUPPOSED TO BE SILENT, and asserted to be.
     *
     * The resolver answers a question; the overview returns scalars; the change log stores rows; the
     * models carry constants. None of them has a reader, so none of them has a sentence — and the
     * day a label appears in one, it belongs in the view or in the controller's flash message where
     * a translator can find it. Requiring them to YIELD keys would be asserting the opposite of
     * their design, which is how the first version of the sibling file's premise failed.
     *
     * `FeatureControl`'s and `PlatformChangeLog`'s `Log::warning()` strings are deliberately NOT
     * `__()`: a log line is read by an operator in a file, not by a merchant on a page, and
     * translating one makes it ungreppable.
     */
    private const SILENT_SURFACES = [
        'app/Support/Control/FeatureControl.php',
        'app/Support/Control/FeatureControlOverview.php',
        'app/Support/Control/PlatformChangeLog.php',
        'app/Support/Control/FeatureDecision.php',
        'app/Models/PlatformFeatureFlag.php',
        'app/Models/PlatformFeatureFlagTarget.php',
        'app/Models/PlatformPilotGroupMember.php',
        'app/Models/PlatformControlChange.php',
        'app/Support/Control/MaintenanceAnnouncements.php',
        'app/Models/PlatformMaintenanceAnnouncement.php',
    ];

    /**
     * The measured number of keys this surface printed when the ratchet was written.
     *
     * A FLOOR AND NOT AN EQUALITY, because adding a sentence to the screen is ordinary and should
     * not redden a test; the floor exists so that an extraction which has BROKEN — a comment
     * stripper that ate the file, a regular expression that stopped matching — cannot leave every
     * assertion below passing over an empty array.
     */
    private const MEASURED_KEY_FLOOR = 120;

    private function repoPath(string $relative = ''): string
    {
        return dirname(__DIR__, 3) . ($relative === '' ? '' : '/' . $relative);
    }

    /**
     * @return array<string, list<string>> key => the sources that print it
     */
    private function keysByOrigin(): array
    {
        $keys = [];

        foreach (self::SURFACES as $relative) {
            $code = $this->sourceWithoutComments($relative);

            foreach ([
                "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
                '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/',
            ] as $pattern) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $raw) {
                    $keys[stripcslashes($raw)][] = $relative;
                }
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function language(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($this->repoPath('resources/lang/' . $file)), true);

        $this->assertIsArray($decoded, "resources/lang/{$file} is not readable as JSON.");

        return $decoded;
    }

    /**
     * THE PREMISE. `assertSame([], $missing)` is satisfied just as well by a scan that read
     * nothing, and this one goes through a comment stripper and two regular expressions, each of
     * which can silently return empty.
     */
    #[Test]
    public function test_the_census_reads_the_surfaces_it_claims_to(): void
    {
        $keys = $this->keysByOrigin();

        $this->assertGreaterThanOrEqual(self::MEASURED_KEY_FLOOR, count($keys), sprintf(
            'Only %d translation keys were found across %d sources. At least %d were measured when '
            . 'this ratchet was written, so the extraction has broken and every assertion below is '
            . 'vacuous.',
            count($keys),
            count(self::SURFACES),
            self::MEASURED_KEY_FLOOR
        ));

        foreach (self::SURFACES as $surface) {
            $fromThisFile = array_filter($keys, fn (array $origins): bool => in_array($surface, $origins, true));

            if (in_array($surface, self::SILENT_SURFACES, true)) {
                $this->assertSame([], array_keys($fromThisFile),
                    "{$surface} has started printing text. It is a resolver, a producer or a model "
                    . 'and the sentences belong in the view or in the controller\'s flash message; '
                    . 'if that has genuinely changed, take it out of SILENT_SURFACES deliberately.');

                continue;
            }

            $this->assertNotEmpty($fromThisFile,
                "No translation key was extracted from {$surface}. Either the file stopped printing "
                . 'text through __(), or the extraction no longer reads it.');
        }
    }

    #[Test]
    public function test_every_key_this_surface_prints_is_declared_in_english_and_arabic(): void
    {
        $en = $this->language('en.json');
        $ar = $this->language('ar.json');

        $undeclared = [];

        foreach ($this->keysByOrigin() as $key => $origins) {
            $absent = [];

            if (! array_key_exists($key, $en)) {
                $absent[] = 'en';
            }

            if (! array_key_exists($key, $ar)) {
                $absent[] = 'ar';
            }

            if ($absent !== []) {
                $undeclared[] = sprintf(
                    '[%s] missing from %s: %s',
                    implode(', ', array_unique(array_map('basename', $origins))),
                    implode(' and ', $absent),
                    mb_strimwidth($key, 0, 120, '...')
                );
            }
        }

        sort($undeclared);

        $this->assertSame([], $undeclared, sprintf(
            "%d string(s) the feature-control surface prints are not declared in both language "
            . "files. `__()` returns its KEY when nothing is declared, so these read correctly in "
            . "English by accident and read IN ENGLISH on the Arabic page. Declare each in BOTH "
            . "resources/lang/en.json and resources/lang/ar.json — and if the sentence already "
            . "exists under slightly different wording, REUSE that key rather than adding a near "
            . "duplicate.\n  %s",
            count($undeclared),
            implode("\n  ", $undeclared)
        ));
    }

    /**
     * KEYS WHOSE ARABIC VALUE MAY LEGITIMATELY CARRY NO ARABIC LETTER.
     *
     * EMPTY, AND MEASURED EMPTY: all 84 keys this surface prints have a real Arabic declaration.
     * The list exists so that the day a genuinely untranslatable string appears — a currency code,
     * a bare placeholder, a function name — it is added HERE, deliberately, with a reason, instead
     * of being waved through by a general escape hatch.
     *
     * @var list<string>
     */
    private const LEGITIMATELY_UNTRANSLATED = [];

    /**
     * THE ARABIC IS ARABIC, not the English sentence copied into the Arabic file.
     *
     * A catalogue entry whose value equals its key is what a bulk tool leaves behind, and it passes
     * the declaration test above while rendering English on the Arabic page — the exact failure
     * that test exists to catch, one level down.
     *
     * STRICTER THAN ITS SIBLING, AND A SURVIVING MUTATION IS WHY. The sibling
     * `PlatformResourceMessagesAreDeclaredTest` accepts an Arabic value that is identical to the
     * English one, on the ground that some keys legitimately are (a currency code, a function
     * name). That hatch is exactly the hole: replacing `"Feature control"`'s Arabic with the
     * English string `Feature control` SURVIVED this file's first draft, because the two then
     * matched and the hatch let it through — an Arabic operator would have seen an English heading
     * and nothing would have been red.
     *
     * So the hatch is replaced by an explicit, currently EMPTY allowlist. Every one of this
     * surface's keys is a label or a sentence a person reads, and such a string always needs
     * translating; "this one does not" is a claim that belongs in a constant somebody has to edit,
     * not in a general rule.
     */
    #[Test]
    public function test_the_arabic_declarations_are_not_the_english_sentence(): void
    {
        $ar = $this->language('ar.json');

        $untranslated = [];

        foreach (array_keys($this->keysByOrigin()) as $key) {
            if (! array_key_exists($key, $ar)) {
                continue;   // the test above owns that failure
            }

            if (in_array($key, self::LEGITIMATELY_UNTRANSLATED, true)) {
                continue;
            }

            if (preg_match('/\p{Arabic}/u', (string) $ar[$key]) === 1) {
                continue;
            }

            $untranslated[] = mb_strimwidth($key, 0, 120, '...');
        }

        sort($untranslated);

        $this->assertSame([], $untranslated, sprintf(
            "%d Arabic declaration(s) carry no Arabic letter. An entry whose value is the English "
            . "sentence passes the declaration check and renders English for an Arabic operator, "
            . "which is the failure that check exists to prevent. If one of these genuinely needs "
            . "no translation, add it to LEGITIMATELY_UNTRANSLATED with a reason rather than "
            . "loosening the rule.\n  %s",
            count($untranslated),
            implode("\n  ", $untranslated)
        ));
    }

    /**
     * AND THE OPERATOR'S ENTRY POINT IS TRANSLATED TOO.
     *
     * The dashboard link lives in another workstream's view (`superadmin/dashboard.blade.php`), so
     * it is not in `SURFACES` — this workstream does not own that file's whole string set. Its two
     * keys are checked by name instead, because a screen reachable only through an untranslated
     * link is a screen an Arabic operator cannot find.
     */
    #[Test]
    public function test_the_dashboard_link_to_this_screen_is_declared_in_both_languages(): void
    {
        $en = $this->language('en.json');
        $ar = $this->language('ar.json');

        foreach ([
            'Feature control',
            'Which features reach which stores: by plan, by one store, or by a pilot group. Widen a '
            . 'release gradually, or switch one off without losing where it had got to.',
        ] as $key) {
            $this->assertArrayHasKey($key, $en, 'The dashboard link is not declared in English.');
            $this->assertArrayHasKey($key, $ar, 'The dashboard link is not declared in Arabic.');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', (string) $ar[$key],
                'The Arabic declaration of the dashboard link carries no Arabic letter.');
        }
    }
}
