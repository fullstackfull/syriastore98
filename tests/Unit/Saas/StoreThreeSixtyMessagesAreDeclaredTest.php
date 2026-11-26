<?php

namespace Tests\Unit\Saas;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * EVERY SENTENCE STORE 360° AND THE OPERATOR'S PLATFORM GROUP PRINT IS DECLARED IN BOTH LANGUAGES.
 * =================================================================================================
 *
 * `__()` RETURNS ITS KEY when nothing is declared. So an undeclared English sentence renders
 * correctly in English BY ACCIDENT and renders IN ENGLISH on the Arabic page — and no test that
 * merely checks "the hint is not empty" can tell the two apart. `AnalyticsMessagesAreDeclaredTest`
 * is this ratchet over the Phase 05 analytics surfaces and found seven undeclared sentences, one
 * of which stated the opposite of what the code did; `PlatformResourceMessagesAreDeclaredTest` is
 * the same over the Phase 06 SaaS screens. This is that ratchet over ADM-001's and ADM-002's.
 *
 * A HAND-CHECKED DIFF IS NOT THIS. The argument for a ratchet over a habit is the sentence an
 * EARLIER slice left behind.
 *
 * COMMENTS ARE STRIPPED, and here it is load-bearing rather than precautionary: `StoreThreeSixty`'s
 * own header quotes the recovered Arabic decision text, the roster view's comment explains why
 * `__('Store name')` is used instead of `__('Store')`, and both views quote sentences while
 * explaining them. CLAUDE.md records EIGHT occasions on which a census was satisfied — or broken —
 * by prose about the thing it measures, four of them in a single day, in three syntaxes.
 *
 * WHAT IT DOES NOT COVER, stated rather than implied: `__()` with a single-quoted literal, in the
 * sources listed below. A key built at runtime from a variable cannot be read statically by any
 * census, and `$bucket['status']` on the support block is exactly that — a `support_tickets.status`
 * value, printed as the database holds it. That is deliberate and is recorded in
 * `StoreThreeSixtyFactsTest`: the set is `SupportTicket::STATUSES`, which the model declares in
 * English, and inventing Arabic for a free-string column the vendored modules also write would be
 * guessing at data rather than translating an interface.
 */
final class StoreThreeSixtyMessagesAreDeclaredTest extends TestCase
{
    use StripsSourceComments;

    /** ADM-001's and ADM-002's surfaces: the producer, the controller, both screens, the menu. */
    private const SURFACES = [
        'app/Support/Operator/StoreThreeSixty.php',
        'app/Http/Controllers/StoreThreeSixtyController.php',
        'app/Listeners/SuperAdminMenuListener.php',
        'resources/views/superadmin/store_360.blade.php',
        'resources/views/superadmin/store_360_index.blade.php',
    ];

    /** @return array<string, string> relative path => source with comments removed */
    private function sources(): array
    {
        $out = [];

        foreach (self::SURFACES as $relative) {
            $path = dirname(__DIR__, 3) . '/' . $relative;

            $this->assertFileExists($path, $relative . ' is gone, so this census is vacuous');

            $out[$relative] = str_ends_with($relative, '.blade.php')
                ? (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path))
                : $this->sourceWithoutComments($path);
        }

        return $out;
    }

    /** @return array<string, list<string>> key => the files that print it */
    private function keys(): array
    {
        $keys = [];

        foreach ($this->sources() as $relative => $source) {
            preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/s", $source, $matches);

            foreach ($matches[1] as $raw) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);
                $keys[$key][] = $relative;
            }
        }

        return $keys;
    }

    /** @return array<string, string> */
    private function declared(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/' . $locale . '.json';

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, $locale . '.json is not valid JSON');

        return $decoded;
    }

    /**
     * PREMISE. Without it, a regex that stopped matching would make every assertion below
     * vacuously green — the first failure mode CLAUDE.md names.
     */
    public function test_premise_the_census_reads_the_surfaces_and_finds_their_sentences(): void
    {
        $keys = $this->keys();

        $this->assertGreaterThan(40, count($keys),
            'the census found almost no translatable strings across the Store 360 surfaces, so '
            . 'every assertion in this file is vacuous. Found ' . count($keys) . '.');

        /* Two sentences that must be IN the set, one per language direction of the requirement:
           the door list's heading is ADM-002's own clause, and the group name is ADM-001's. */
        $this->assertArrayHasKey('Manage this store from its own systems', $keys);
        $this->assertArrayHasKey('Across the platform', $keys);
    }

    /**
     * PREMISE, THE OTHER DIRECTION: comments really are stripped. `StoreThreeSixty`'s docblock
     * contains `__()`-shaped prose nowhere, but the roster view's comment names `__('Store name')`
     * and `__('Store')` while explaining the choice — so if stripping ever broke, `Store` would
     * enter the census and demand a declaration for a key this surface does not print.
     */
    public function test_premise_comments_are_stripped_before_anything_is_counted(): void
    {
        $raw = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/superadmin/store_360_index.blade.php'
        );

        $this->assertStringContainsString("__('Store')", $raw,
            'the comment this premise is built on has been reworded; pick another or drop this case.');

        $this->assertArrayNotHasKey('Store', $this->keys(),
            'comment stripping is not working: a key named only inside a `{{-- --}}` comment '
            . 'entered the census.');
    }

    public function test_every_sentence_is_declared_in_english(): void
    {
        $declared = $this->declared('en');
        $missing = [];

        foreach ($this->keys() as $key => $files) {
            if (! array_key_exists($key, $declared)) {
                $missing[] = $key . '   [' . implode(', ', array_unique($files)) . ']';
            }
        }

        $this->assertSame([], $missing,
            "Undeclared in en.json, so nothing pins the English wording:\n  "
            . implode("\n  ", $missing));
    }

    public function test_every_sentence_is_declared_in_arabic(): void
    {
        $declared = $this->declared('ar');
        $missing = [];

        foreach ($this->keys() as $key => $files) {
            if (! array_key_exists($key, $declared)) {
                $missing[] = $key . '   [' . implode(', ', array_unique($files)) . ']';
            }
        }

        $this->assertSame([], $missing,
            "Undeclared in ar.json, so these render IN ENGLISH on the Arabic operator screens:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * AND THE ARABIC IS ACTUALLY ARABIC. A declaration whose value is the English key is a
     * placeholder, not a translation, and it passes the case above.
     */
    public function test_every_arabic_declaration_is_written_in_arabic(): void
    {
        $declared = $this->declared('ar');
        $untranslated = [];

        foreach (array_keys($this->keys()) as $key) {
            $value = (string) ($declared[$key] ?? '');

            if ($value === '' || ! preg_match('/\p{Arabic}/u', $value)) {
                $untranslated[] = $key;
            }
        }

        $this->assertSame([], $untranslated,
            "Declared in ar.json with no Arabic in the value, which is a placeholder rather than a "
            . "translation:\n  " . implode("\n  ", $untranslated));
    }
}
