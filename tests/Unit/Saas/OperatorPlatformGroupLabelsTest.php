<?php

namespace Tests\Unit\Saas;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * NAV-004 — THE OPERATOR'S PLATFORM GROUP READS IN ARABIC, AND EVERY LABEL IN IT IS DISTINCT.
 * =================================================================================================
 *
 * TWO DIFFERENT FAILURES, AND ONLY ONE OF THEM IS "A MISSING TRANSLATION".
 *
 * 1. `__()` RETURNS ITS KEY when nothing is declared, so an undeclared English label renders
 *    correctly in English BY ACCIDENT and renders IN ENGLISH on the Arabic page. Nothing that
 *    checks "the sidebar is not empty" can tell those apart.
 *
 * 2. A DECLARED LABEL CAN STILL BE THE WRONG WORD. CLAUDE.md records two measured cases in this
 *    repository's own catalogue: the vendored `__('Store')` is `مخزن`, a *warehouse*, and
 *    `__('Theme')` is `الموضوع`, a *topic*. Reusing a vendored key because its English reads right
 *    puts a word in the Arabic sidebar that means something else entirely, and every "declared in
 *    both files" assertion passes. So this file also asserts that no label in the group resolves to
 *    one of those two Arabic values, and that the group's Arabic labels are PAIRWISE DISTINCT —
 *    which is the general form of the same defect: two sidebar siblings an Arabic-reading operator
 *    cannot tell apart are two screens they cannot choose between.
 *
 * WHY THE GROUP AND NOT THE WHOLE LISTENER. The ten children of `platform-command` are the
 * operator's cross-tenant instruments and four of them (the verification queue, the approval
 * queue, the incident log and the operations screen) were added by NAV-004 after shipping with no
 * navigation entry at all. The pre-existing top-level entries are the vendored WorkDo vocabulary
 * (`Users`, `Coupons`, `Plan`, `Country`, `Currency`) whose Arabic is upstream's and is not ours
 * to re-translate; asserting over them would be asserting about `packages/workdo/`'s catalogue.
 *
 * THE SET IS READ FROM THE LISTENER, NOT TYPED HERE. Every `add([...])` whose `parent` is
 * `platform-command`, so the eleventh entry joins this file by itself. A hand-written list would
 * be satisfied forever by whatever was true the day it was written — which is exactly how the
 * four screens NAV-004 added shipped unreachable.
 *
 * COMMENTS ARE STRIPPED, and it is load-bearing rather than precautionary: `SuperAdminMenuListener`
 * explains its own labels in prose directly above them and the dashboard view quotes its hint
 * sentences while explaining why they are worded that way. CLAUDE.md §3 records a census being
 * satisfied — or broken — by prose about the thing it measures EIGHT times, in three syntaxes.
 *
 * A UNIT TEST: it reads three files and parses two of them. No database, no container, no boot.
 */
final class OperatorPlatformGroupLabelsTest extends TestCase
{
    use StripsSourceComments;

    private const LISTENER = 'app/Listeners/SuperAdminMenuListener.php';

    /** The operator dashboard, which carries the second discoverability surface: the link panel. */
    private const DASHBOARD = 'resources/views/superadmin/dashboard.blade.php';

    /** The group whose children are the operator's platform-wide instruments. */
    private const GROUP = 'platform-command';

    /**
     * The two measured mistranslations CLAUDE.md names, by the key whose Arabic they are.
     *
     * Stated as KEYS and resolved against the real catalogue rather than hard-coding `مخزن` and
     * `الموضوع`: if upstream ever corrects one of them, this test should follow the catalogue
     * instead of failing on a string that is no longer wrong.
     */
    private const MISLEADING_VENDORED_KEYS = ['Store', 'Theme'];

    private function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private function listenerSource(): string
    {
        $path = $this->repoPath(self::LISTENER);

        $this->assertFileExists($path, self::LISTENER . ' is gone, so this census is vacuous.');

        return $this->sourceWithoutComments($path);
    }

    /**
     * The `title` of every entry whose `parent` is the platform group, keyed by its `name`.
     *
     * READ PER `add([...])` BLOCK so a title can never be paired with another entry's parent —
     * two independent greps would align by position and silently misalign the moment one entry
     * omits a key, which would make this test compare real values against each other and pass.
     *
     * @return array<string, string> name => title
     */
    private function groupTitles(): array
    {
        preg_match_all('/->add\(\s*\[(.*?)\]\s*\)\s*;/s', $this->listenerSource(), $blocks);

        $titles = [];

        foreach ($blocks[1] as $block) {
            if (preg_match("/'parent'\s*=>\s*'" . self::GROUP . "'/", $block) !== 1) {
                continue;
            }

            $this->assertSame(1,
                preg_match("/'name'\s*=>\s*'([^']+)'/", $block, $name),
                'a child of ' . self::GROUP . ' has no name literal.');

            $this->assertSame(1,
                preg_match("/'title'\s*=>\s*__\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*\)/", $block, $title),
                'the ' . $name[1] . ' entry does not pass its title through __(), so it cannot be '
                . 'translated at all.');

            $titles[$name[1]] = stripcslashes($title[1]);
        }

        return $titles;
    }

    /**
     * Every `__()` key inside the dashboard's platform-links panel.
     *
     * SCOPED TO THE PANEL'S OWN BLOCK, not the whole 300-line view. CLAUDE.md: *scope the search
     * to the block too* — an assertion over a whole file answers "does this string exist
     * anywhere", and the dashboard prints about ninety other keys whose declaration is
     * `OperatorDashboardCardLabelTranslationTest`'s business, not this file's.
     *
     * @return list<string>
     */
    private function panelKeys(): array
    {
        $path = $this->repoPath(self::DASHBOARD);

        $this->assertFileExists($path, self::DASHBOARD . ' is gone, so this census is vacuous.');

        /* Blade comments, not PHP ones: the panel's reasoning lives in `{{-- --}}` blocks that
           quote the sentences beneath them. */
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '',
            (string) file_get_contents($path));

        $from = strpos($source, 'ss-platform-links');
        $to = $from === false ? false : strpos($source, 'ss-platform-links-end', $from);

        $this->assertNotFalse($from, 'the platform-links panel markers are gone from the operator '
            . 'dashboard, so this census reads nothing.');
        $this->assertNotFalse($to, 'the panel\'s closing marker is gone, so the slice is unbounded.');

        $panel = substr($source, $from, $to - $from);

        preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/s", $panel, $m);

        return array_values(array_unique(array_map('stripcslashes', $m[1])));
    }

    /** @return array<string, string> */
    private function language(string $file): array
    {
        $decoded = json_decode(
            (string) file_get_contents($this->repoPath('resources/lang/' . $file)), true);

        $this->assertIsArray($decoded, "resources/lang/{$file} is not readable as JSON.");

        return $decoded;
    }

    /**
     * PREMISE, IN BOTH DIRECTIONS. Every assertion below is `assertSame([], $bad)`-shaped and is
     * satisfied just as well by a census that read nothing; this one goes through a comment
     * stripper and four regular expressions, any of which can silently return empty.
     */
    #[Test]
    public function test_premise_the_census_finds_the_group_and_the_panel(): void
    {
        $titles = $this->groupTitles();

        $this->assertGreaterThanOrEqual(10, count($titles), sprintf(
            'only %d children of %s were found; ten were measured when NAV-004 was written '
            . '(Store 360, the verification queue, the approval queue, platform revenue, '
            . 'platform analytics, platform resources, platform operations, platform incidents, '
            . 'the official website content and the Documentation Center). Found: %s',
            count($titles),
            self::GROUP,
            implode(', ', array_keys($titles))
        ));

        /* THE FOUR NAV-004 ENTRIES BY NAME. This is the one hard-coded list in the file and it is
           deliberate: these four are the reason it exists, and a census that merely counted ten
           would be satisfied by ten of anything. The computed set above is what catches the
           ELEVENTH screen; this is what proves the four did not quietly leave again. */
        foreach ([
            'platform-verification',
            'platform-approvals',
            'platform-operations',
            'platform-incidents',
        ] as $name) {
            $this->assertArrayHasKey($name, $titles,
                $name . ' is no longer a child of ' . self::GROUP . '. These four screens were '
                . 'built, gated and tested and reachable only by typing their uri until NAV-004 '
                . 'added them; if one has genuinely moved, move it deliberately.');
        }

        $keys = $this->panelKeys();

        $this->assertGreaterThanOrEqual(16, count($keys), sprintf(
            'only %d translation keys were read out of the dashboard panel; 18 were measured '
            . 'when NAV-004 added the two queue links. The slice markers or the extraction have '
            . 'broken.',
            count($keys)
        ));

        /* AND THE SLICE REALLY IS THE PANEL, not the whole view: a key the dashboard prints far
           outside the panel must not be in it. */
        $this->assertNotContains('Popular Plan', $keys,
            'the panel slice is reading the whole dashboard, so it is no longer scoped to the '
            . 'block it claims to measure.');
    }

    #[Test]
    public function test_every_group_label_and_panel_sentence_is_declared_in_english_and_arabic(): void
    {
        $en = $this->language('en.json');
        $ar = $this->language('ar.json');

        $undeclared = [];

        $subjects = [];

        foreach ($this->groupTitles() as $name => $title) {
            $subjects[$title][] = 'sidebar:' . $name;
        }

        foreach ($this->panelKeys() as $key) {
            $subjects[$key][] = 'dashboard panel';
        }

        foreach ($subjects as $key => $origins) {
            $absent = [];

            if (! array_key_exists($key, $en)) {
                $absent[] = 'en';
            }

            if (! array_key_exists($key, $ar)) {
                $absent[] = 'ar';
            }

            if ($absent !== []) {
                $undeclared[] = sprintf('[%s] missing from %s: %s',
                    implode(', ', $origins), implode(' and ', $absent),
                    mb_strimwidth($key, 0, 110, '...'));
            }
        }

        sort($undeclared);

        $this->assertSame([], $undeclared, sprintf(
            "%d string the operator's platform navigation prints is not declared in both language "
            . "files. `__()` returns its KEY when nothing is declared, so these read correctly in "
            . "English by accident and read IN ENGLISH in the Arabic sidebar. Declare each in BOTH "
            . "resources/lang/en.json and resources/lang/ar.json:\n  %s",
            count($undeclared),
            implode("\n  ", $undeclared)
        ));
    }

    /**
     * THE ARABIC IS ARABIC. A catalogue entry whose value equals its key is what a bulk tool
     * leaves behind, and it passes the declaration test above while rendering English in the
     * Arabic sidebar — the exact failure that test exists to catch, one level down.
     */
    #[Test]
    public function test_every_group_label_and_panel_sentence_really_carries_arabic(): void
    {
        $ar = $this->language('ar.json');

        $notArabic = [];

        foreach (array_merge(array_values($this->groupTitles()), $this->panelKeys()) as $key) {
            $value = (string) ($ar[$key] ?? '');

            if (preg_match('/\p{Arabic}/u', $value) !== 1) {
                $notArabic[] = mb_strimwidth($key, 0, 90, '...') . ' => ' . ($value === ''
                    ? '(undeclared)'
                    : mb_strimwidth($value, 0, 60, '...'));
            }
        }

        sort($notArabic);

        $this->assertSame([], $notArabic,
            "an Arabic catalogue entry for the operator's platform navigation contains no Arabic "
            . "letter, so that label renders in English on the Arabic sidebar while every "
            . "'declared in both files' check passes:\n  " . implode("\n  ", $notArabic));
    }

    /**
     * NO LABEL IN THE GROUP BORROWS A VENDORED KEY WHOSE ARABIC MEANS SOMETHING ELSE.
     *
     * Measured and recorded in CLAUDE.md: `__('Store')` resolves to `مخزن` (a warehouse) and
     * `__('Theme')` to `الموضوع` (a topic). An English-reading developer reusing either sees a
     * sensible label; an Arabic-reading operator sees the wrong noun.
     */
    #[Test]
    public function test_no_group_label_resolves_to_a_known_mistranslation(): void
    {
        $ar = $this->language('ar.json');

        $forbidden = [];

        foreach (self::MISLEADING_VENDORED_KEYS as $key) {
            /* The premise: the trap is still in the catalogue. If upstream corrected it, this
               assertion is about nothing and should say so rather than pass silently. */
            $this->assertArrayHasKey($key, $ar,
                "the vendored key '{$key}' is no longer in ar.json, so the mistranslation this "
                . 'case guards against may no longer exist. Re-read CLAUDE.md before deleting '
                . 'this case.');

            $forbidden[$ar[$key]] = $key;
        }

        $collisions = [];

        foreach ($this->groupTitles() as $name => $title) {
            $value = (string) ($ar[$title] ?? '');

            if (isset($forbidden[$value])) {
                $collisions[] = sprintf('%s ("%s") resolves to %s, which is the Arabic of the '
                    . 'vendored key "%s" and means something else',
                    $name, $title, $value, $forbidden[$value]);
            }
        }

        sort($collisions);

        $this->assertSame([], $collisions,
            "a platform navigation label resolves to a known mistranslation:\n  "
            . implode("\n  ", $collisions));
    }

    /**
     * THE TEN LABELS ARE PAIRWISE DISTINCT, IN BOTH LANGUAGES.
     *
     * The general form of the mistranslation defect above: two siblings in one submenu that read
     * identically are two screens an operator cannot choose between. It bites in Arabic first,
     * because the English labels differ by a word that the Arabic may collapse — `Platform
     * resources` and `Platform operations` are one word apart in English and measure a host
     * against the shared database, which is the distinction the dashboard panel's sentences exist
     * to carry.
     */
    #[Test]
    public function test_the_group_labels_are_distinct_in_both_languages(): void
    {
        $titles = $this->groupTitles();
        $ar = $this->language('ar.json');

        $duplicates = [];

        foreach (['en' => null, 'ar' => $ar] as $language => $catalogue) {
            $seen = [];

            foreach ($titles as $name => $title) {
                $rendered = $catalogue === null ? $title : (string) ($catalogue[$title] ?? $title);

                if (isset($seen[$rendered])) {
                    $duplicates[] = sprintf('%s: %s and %s both read "%s"',
                        $language, $seen[$rendered], $name, $rendered);
                }

                $seen[$rendered] = $name;
            }
        }

        sort($duplicates);

        $this->assertSame([], $duplicates,
            "two entries in the operator's platform group render the same label, so an operator "
            . "cannot tell the two screens apart in that language:\n  "
            . implode("\n  ", $duplicates));
    }
}
