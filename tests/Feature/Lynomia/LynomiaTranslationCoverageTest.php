<?php

namespace Tests\Feature\Lynomia;

use Tests\TestCase;

/**
 * EVERY SOURCE STRING THIS MODULE SHIPS, IN BOTH DICTIONARIES.
 *
 * Laravel's JSON translator falls back to the key when it finds no entry, so a string that was
 * never added to en.json still renders perfectly in English -- and renders in English on the
 * Arabic page too. The failure is invisible to every English reader and to every test that
 * asserts on English output, which is how fifty-six Phase 4, 5 and 6 strings reached en.json's
 * registry as absences and seven reached the Arabic page as English.
 *
 * Found by rendering the operator page in Arabic for Phase 6's browser verification: the two
 * controls the remote-health column adds, "Check" and "In Lynomia", came out in English beside
 * Arabic headings. The browser check only sees the pages it renders, so the gap is closed here
 * instead -- against the source, for the whole module.
 *
 * en.json is the registry a translator reads, not a translation: its values are identical to its
 * keys by construction, and an entry that is not would be a silent English rewrite of a string
 * the source says something else about.
 */
class LynomiaTranslationCoverageTest extends TestCase
{
    private const MODULE = 'packages/workdo/LynomiaChat/src';

    public function test_every_source_string_is_registered_in_both_dictionaries(): void
    {
        $missingEn = [];
        $missingAr = [];
        $en = $this->dictionary('en');
        $ar = $this->dictionary('ar');

        foreach ($this->sourceStrings() as $key => $files) {
            if (! array_key_exists($key, $en)) {
                $missingEn[] = $key . '  (' . implode(', ', $files) . ')';
            }
            if (! array_key_exists($key, $ar)) {
                $missingAr[] = $key . '  (' . implode(', ', $files) . ')';
            }
        }

        $this->assertSame([], $missingEn,
            "Source strings missing from en.json:\n" . implode("\n", $missingEn));
        $this->assertSame([], $missingAr,
            "Source strings with no Arabic -- they render in English on the Arabic page:\n" . implode("\n", $missingAr));
    }

    public function test_the_english_registry_does_not_rewrite_its_own_keys(): void
    {
        foreach ($this->dictionary('en') as $key => $value) {
            $this->assertSame($key, $value,
                "en.json rewrites '{$key}' to '{$value}'. The registry lists source strings; it does not translate them.");
        }
    }

    public function test_every_arabic_entry_for_a_module_string_is_actually_arabic(): void
    {
        $ar = $this->dictionary('ar');
        $untranslated = [];

        foreach (array_keys($this->sourceStrings()) as $key) {
            // A string with no lowercase letter is not prose: it is a mask or a machine-readable
            // form -- here, the shape Lynomia prints a verification code in. Those are the same
            // in every language on purpose, so translating one would be the defect.
            if (! preg_match('/[a-z]/', $key)) {
                continue;
            }

            // A key with no Arabic letters anywhere is the English string copied across, which
            // passes the coverage test above while changing nothing for an Arabic reader.
            if (! preg_match('/[\x{0600}-\x{06FF}]/u', (string) ($ar[$key] ?? ''))) {
                $untranslated[] = $key;
            }
        }

        $this->assertSame([], $untranslated,
            "ar.json carries the English text for:\n" . implode("\n", $untranslated));
    }

    public function test_no_placeholder_is_dropped_in_translation(): void
    {
        $ar = $this->dictionary('ar');
        $dropped = [];

        foreach (array_keys($this->sourceStrings()) as $key) {
            preg_match_all('/:[a-zA-Z_]+/', $key, $matches);
            foreach (array_unique($matches[0]) as $placeholder) {
                // A dropped :count or :plan does not fail -- it renders a sentence that states a
                // fact with the number taken out of it.
                if (! str_contains((string) ($ar[$key] ?? ''), $placeholder)) {
                    $dropped[] = "{$placeholder} in '{$key}'";
                }
            }
        }

        $this->assertSame([], $dropped,
            "Arabic drops a placeholder, so the sentence renders without its value:\n" . implode("\n", $dropped));
    }

    /**
     * Every `__('…')` argument in the module, mapped to the files that use it.
     *
     * Namespaced keys (`lynomia-chat::studio.yes`) are excluded: they resolve from
     * resources/lang/<locale>/studio.php, which is a different mechanism and is covered by
     * LynomiaFlowBuilderTest.
     */
    private function sourceStrings(): array
    {
        $found = [];
        $root = base_path(self::MODULE);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $patterns = ['/__\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/'];

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $matches);
                foreach ($matches[1] as $raw) {
                    $key = stripcslashes($raw);
                    if (str_contains($key, '::')) {
                        continue;
                    }
                    $found[$key][] = str_replace($root . '/', '', $file->getPathname());
                }
            }
        }

        foreach ($found as $key => $files) {
            $found[$key] = array_values(array_unique($files));
        }

        return $found;
    }

    private function dictionary(string $locale): array
    {
        $path = base_path(self::MODULE . "/resources/lang/{$locale}.json");
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
