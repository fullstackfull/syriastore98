<?php

namespace Tests\Unit\Platform;

use App\Support\Platform\PlatformContent;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-104 (P03-C-05) — THE OFFICIAL SITE'S CONTENT REGISTRY.
 * =================================================================================================
 *
 * The registry is the whole capability's contract: it declares which strings on the official
 * website an operator may change, which of them the OWNER still owes, and what the built-in copy
 * is for the rest. Three properties have to hold for the surface above it to be trustworthy, and
 * each is asserted here in both directions.
 *
 *   1. EVERY FIELD'S DEFAULT IS A REAL TRANSLATION KEY, or is deliberately null. A default that
 *      names a key no language file carries renders the English sentence to an Arabic reader --
 *      `__()` returns its argument on a miss -- so a typo in this table is a bilingual defect that
 *      no page-level test would attribute to the registry.
 *
 *   2. AN OWED FIELD RESOLVES TO THE EMPTY STRING AND NOT TO A PLACEHOLDER. The five legal
 *      documents and the contact details are the owner's content. The capability must SHOW that
 *      they are owed; inventing a privacy notice is the one outcome this work must not produce.
 *
 *   3. EVERY FIELD BELONGS TO A DECLARED PAGE, so the editor cannot carry a field no screen shows
 *      and the index's "x of y supplied" counts cannot silently exclude one.
 */
final class PlatformContentRegistryTest extends TestCase
{
    /**
     * THE STORE'S MEMO IS PROCESS-GLOBAL AND MUST BE DROPPED, which this file learned the hard way:
     * every test here passed alone and `test_a_defaulted_field_resolves_to_its_built_in_copy` FAILED
     * in a combined run, because a Feature test earlier in the same process had saved an override
     * and `PlatformContentStore`'s static memo still held it. That is pollution mode 3 from
     * `.claude/skills/store-syria-laravel-tenancy` — a memoised static surviving an app rebuild —
     * and the failure direction is the dangerous one: it reads as "the default resolution is
     * broken" when the default resolution is fine.
     *
     * After `forget()` the store re-queries, there is no migrated schema under a plain `TestCase`,
     * and the read fails safe to an empty map — which is exactly the state these assertions are
     * about.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \App\Support\Platform\PlatformContentStore::forget();
    }

    #[Test]
    public function test_the_registry_is_not_empty_and_covers_the_pages_the_gap_names(): void
    {
        $this->assertNotEmpty(PlatformContent::fields(), 'PREMISE: the registry is empty.');

        /* The four content areas GAP-104 names: marketing copy, the pricing narrative, FAQ
           entries and legal text. A registry missing one of them does not discharge the row. */
        foreach (['home', 'pricing', 'faq', 'legal', 'contact'] as $page) {
            $this->assertNotEmpty(
                PlatformContent::fieldsFor($page),
                "The registry declares no editable field for the {$page} page, which GAP-104 names."
            );
        }
    }

    #[Test]
    public function test_every_field_declares_a_page_that_the_registry_lists(): void
    {
        $pages = PlatformContent::pages();

        foreach (PlatformContent::fields() as $key => $field) {
            $this->assertArrayHasKey('page', $field, "{$key} declares no page.");
            $this->assertArrayHasKey(
                $field['page'],
                $pages,
                "{$key} belongs to the page '{$field['page']}', which the registry does not list, "
                . 'so no editor screen shows it.'
            );
        }
    }

    /**
     * A DEFAULT IS A TRANSLATION KEY THAT BOTH LANGUAGE FILES REALLY CARRY.
     *
     * `__()` returns its argument when a key is missing, and every key in this application IS the
     * English sentence -- so a default naming a key `ar.json` does not hold puts English on the
     * Arabic page and nothing fails. That is the exact failure mode CLAUDE.md records for the
     * bilingual assertions, measured one level lower.
     */
    #[Test]
    public function test_every_default_is_a_key_both_language_files_carry(): void
    {
        $en = json_decode(file_get_contents(base_path('resources/lang/en.json')), true);
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($en, 'PREMISE: en.json did not parse.');
        $this->assertIsArray($ar, 'PREMISE: ar.json did not parse.');

        $missing = [];

        foreach (PlatformContent::fields() as $key => $field) {
            $default = $field['default'] ?? null;

            if ($default === null) {
                continue;
            }

            if (! array_key_exists($default, $en)) {
                $missing[] = "{$key}: en.json has no key for its default";
            }

            if (! array_key_exists($default, $ar)) {
                $missing[] = "{$key}: ar.json has no key for its default";
            }
        }

        $this->assertSame([], $missing,
            "A registry default names a translation key a language file does not carry:\n  "
            . implode("\n  ", $missing));
    }

    /**
     * THE OWED FIELDS RESOLVE EMPTY. Nothing invents content the owner has not supplied.
     */
    #[Test]
    public function test_an_owed_field_resolves_to_the_empty_string(): void
    {
        $owed = PlatformContent::owedFields();

        $this->assertNotEmpty($owed,
            'PREMISE: the registry declares nothing as owed by the owner, yet GAP-104 names five '
            . 'legal documents and the contact details.');

        foreach (array_keys($owed) as $key) {
            $this->assertNull(PlatformContent::fields()[$key]['default'],
                "{$key} is marked owed but carries a built-in default, so the page would publish "
                . 'copy the owner never approved.');

            $this->assertSame('', PlatformContent::text($key),
                "{$key} is owed by the owner and must resolve to nothing until it is supplied.");
        }
    }

    /** The five legal documents and the four contact details are all present and all owed. */
    #[Test]
    public function test_the_five_legal_documents_and_the_contact_details_are_owed(): void
    {
        foreach (['terms', 'privacy', 'acceptable-use', 'refunds', 'data-processing'] as $doc) {
            $key = 'legal.' . $doc . '.text';

            $this->assertArrayHasKey($key, PlatformContent::fields(),
                "The legal document '{$doc}' has no editable body, so it can still only be "
                . 'published by a developer (GAP-104).');

            $this->assertArrayHasKey($key, PlatformContent::owedFields(),
                "{$key} is not marked as owed by the owner.");
        }

        foreach (['address', 'telephone', 'email', 'hours'] as $detail) {
            $key = 'contact.' . $detail;

            $this->assertArrayHasKey($key, PlatformContent::fields(),
                "The contact detail '{$detail}' is not editable, so it can still only be "
                . 'published by a developer (GAP-104).');

            $this->assertArrayHasKey($key, PlatformContent::owedFields(),
                "{$key} is not marked as owed by the owner.");
        }
    }

    /** A field with a default resolves to its translated built-in copy, not to nothing. */
    #[Test]
    public function test_a_defaulted_field_resolves_to_its_built_in_copy(): void
    {
        $withDefault = array_filter(
            PlatformContent::fields(),
            static fn (array $f) => ($f['default'] ?? null) !== null
        );

        $this->assertNotEmpty($withDefault, 'PREMISE: no field carries a built-in default.');

        foreach ($withDefault as $key => $field) {
            $this->assertSame(__($field['default']), PlatformContent::text($key),
                "{$key} does not fall back to its built-in copy, so removing an override would "
                . 'blank the page.');
        }
    }

    /** An unknown key is refused rather than silently answered. */
    #[Test]
    public function test_an_unknown_key_is_not_resolvable(): void
    {
        $this->assertFalse(PlatformContent::has('home.hero.nonexistent'));
        $this->assertSame('', PlatformContent::text('home.hero.nonexistent'));
    }

    /**
     * EVERY KEY A PUBLIC VIEW READS IS ONE THE REGISTRY DECLARES — AND THE CONSEQUENCE OF A MISS
     * IS A 500 ON THE COMPANY'S FRONT PAGE, not a blank.
     *
     * The views read `$c['home.hero.title']` from the array `PlatformContent::resolved()` builds,
     * with no `??` fallback — deliberately, because a silent empty heading is a worse failure than
     * a loud one. But Laravel's `HandleExceptions` turns an undefined-array-key warning into an
     * `ErrorException`, so a renamed registry key would take the PUBLIC page down rather than
     * render it short. This census is what makes that a Unit failure instead.
     *
     * COMMENTS ARE STRIPPED FIRST, IN BOTH SYNTAXES, AND THIS CENSUS BROKE ON ITS OWN PROSE
     * BEFORE THEY WERE. A census that reads comments can be satisfied — or broken — by prose
     * about the thing it measures; CLAUDE.md records six instances and this is the seventh,
     * measured while writing it: the first version stripped `{{-- --}}` only, and a C-style
     * comment inside `legal.blade.php`'s own raw-PHP block — explaining why the dynamic lookup
     * lives there — contains the characters `$c['literal']` and was reported as an undeclared key.
     * (And writing the closing delimiter of that comment form inside THIS docblock ended it early
     * and made PHPUnit refuse to load the file, which is the same class of mistake one level up.)
     *
     * A CONCATENATED KEY IS ALLOWED ONLY WHERE IT CANNOT THROW. `$c['legal.' . $id . '.text']`
     * cannot be resolved statically, so this census cannot vouch for it; what it can insist on is
     * that such a line carries a `??` fallback, which turns the unresolvable case from a 500 on a
     * public page into an empty string. One such line exists, by design, in `legal.blade.php`.
     */
    #[Test]
    public function test_every_content_key_a_public_view_reads_is_declared(): void
    {
        $views = glob(base_path('resources/views/platform/*.blade.php'));

        $this->assertNotEmpty($views, 'PREMISE: no platform views found.');

        $fields = PlatformContent::fields();
        $seen   = 0;
        $bad    = [];

        foreach ($views as $view) {
            $source = (string) file_get_contents($view);
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;
            $source = preg_replace('~/\*.*?\*/~s', '', $source) ?? $source;

            foreach (preg_split('/\R/', $source) ?: [] as $line) {
                preg_match_all('/\$c\[\s*\'([^\']+)\'/', $line, $matches);

                foreach ($matches[1] as $key) {
                    $seen++;

                    if (array_key_exists($key, $fields)) {
                        continue;
                    }

                    /* A concatenated key, which this census cannot resolve. Permitted only with a
                       `??` fallback on the same line, so the unresolvable case renders empty
                       instead of throwing. */
                    if (str_ends_with($key, '.') && str_contains($line, '??')) {
                        continue;
                    }

                    $bad[] = basename($view) . ' reads $c[\'' . $key . '\'], which the registry '
                        . 'does not declare'
                        . (str_ends_with($key, '.') ? ' (a concatenated key with no `??` fallback)' : '');
                }
            }
        }

        $this->assertGreaterThan(20, $seen,
            'PREMISE: the scan found almost no `$c[...]` reads, so it is not measuring the views.');

        $this->assertSame([], $bad, implode("\n  ", $bad));
    }

    /**
     * EVERY KIND IS ONE THE EDITOR AND THE PAGES BOTH KNOW HOW TO RENDER. A field declaring an
     * unknown kind would reach a view with no branch for it.
     */
    #[Test]
    public function test_every_field_declares_a_known_kind(): void
    {
        foreach (PlatformContent::fields() as $key => $field) {
            $this->assertContains(
                $field['kind'] ?? null,
                PlatformContent::KINDS,
                "{$key} declares an unknown kind."
            );
        }
    }
}
