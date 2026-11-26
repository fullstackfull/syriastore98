<?php

namespace Tests\Feature\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GAP-107 / GAP-108 — THE STRINGS THIS CHANGE INTRODUCES, IN BOTH LANGUAGES.
 * =================================================================================================
 *
 * THIS FILE IS RED ON ARRIVAL AND THAT IS ITS PURPOSE. The agent that wrote the editorial workflow
 * was not permitted to edit `resources/lang/en.json` or `resources/lang/ar.json` — concurrent
 * appends to those two files lose each other's keys — so it used the keys as if they existed and
 * handed the pairs over to be written. Until they are written, every one of these strings renders
 * in ENGLISH on an Arabic merchant's screen, which `__()` does silently: it returns its KEY when a
 * translation is missing, and the key here IS the English text. There is no other way to see that
 * happen.
 *
 * The pairs are listed in `docs/development/phase-3/PHASE-03-DOCS-EDITORIAL-WORKFLOW.md` §6 with
 * the Arabic to paste. The moment both files carry them this file is green and stays a standing
 * control: a later change that removes one of these Arabic rows fails here.
 *
 * WHY SO FEW. Seven, because the surfaces were deliberately built out of keys the two files already
 * carried — `Last Updated`, `Status`, `Category`, `Title`, `Version`, `Preview`, `Published`,
 * `Not set`, `Not published yet`, `Nothing published yet` — and because the per-language column
 * headers print each language's own endonym (`English`, `العربية`) rather than a translated noun,
 * which is both the better convention and two keys nobody has to write.
 */
class EditorialStringsAreBilingualTest extends TestCase
{
    /**
     * Every string this change introduced, with the Arabic it is owed.
     *
     * The Arabic is carried HERE as well as in the handover document so that the expectation and
     * the request cannot drift apart: whoever writes the language files can copy from this array
     * and this test then proves they copied it correctly.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pairs(): array
    {
        return [
            'Draft' => ['Draft', 'مسودة'],

            'In review' => ['In review', 'قيد المراجعة'],

            'Not translated' => ['Not translated', 'غير مُترجَمة'],

            'Documentation editorial' => ['Documentation editorial', 'تحرير الوثائق'],

            'Applies to version' => ['Applies to version', 'ينطبق على النسخة'],

            'the editorial screen note' => [
                'Every help article and the state it is in. Only published articles are visible to readers; an article changes state when that change is reviewed and deployed.',
                'كلّ مادة مساعدة والحالة التي هي فيها. المواد المنشورة فقط هي التي يراها القرّاء؛ وتتغيّر حالة المادة عندما تُراجَع تلك التغييرات وتُنشَر.',
            ],

            'the editorial table caption' => [
                'Every documentation article, with its editorial state in each language.',
                'كلّ مادة في الوثائق، مع حالتها التحريرية في كلّ لغة.',
            ],
        ];
    }

    /** @return array<string, array{0: string}> */
    private static function translations(string $locale): array
    {
        $path = base_path('resources/lang/' . $locale . '.json');

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    #[Test]
    public function test_the_language_files_still_parse(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $this->assertGreaterThan(
                1000,
                count(self::translations($locale)),
                "PREMISE: resources/lang/{$locale}.json did not parse, or lost most of its rows. "
                . 'Every assertion in this file would otherwise fail for the wrong reason.'
            );
        }
    }

    #[Test]
    #[DataProvider('pairs')]
    public function test_a_new_string_is_declared_in_english(string $key, string $arabic): void
    {
        $en = self::translations('en');

        $this->assertArrayHasKey($key, $en,
            "resources/lang/en.json has no row for:\n  \"{$key}\": \"{$key}\"\n"
            . 'Without it the string still renders (the key IS the English text), so this is the '
            . 'half that is merely untidy — the Arabic half below is the one that shows English to '
            . 'an Arabic reader.');
    }

    #[Test]
    #[DataProvider('pairs')]
    public function test_a_new_string_is_declared_in_arabic(string $key, string $arabic): void
    {
        $ar = self::translations('ar');

        $this->assertArrayHasKey($key, $ar,
            "resources/lang/ar.json has no row for this string, so it renders in ENGLISH on an "
            . "Arabic merchant's screen. Add:\n  \"{$key}\": \"{$arabic}\"");

        $this->assertSame($arabic, $ar[$key],
            "resources/lang/ar.json carries a different Arabic value for \"{$key}\" than the one "
            . 'this change asked for. If the new value is a better translation, update the array '
            . 'in this test file; if it is a mistake, restore the value above.');
    }

    /**
     * The direction a census of the key list cannot check for itself: that the keys are actually
     * USED. A key written into both language files and then removed from the view is dead weight
     * that reads as coverage.
     */
    #[Test]
    #[DataProvider('pairs')]
    public function test_a_new_string_is_actually_used_by_a_view_or_a_class(string $key, string $arabic): void
    {
        $sources = [
            'resources/views/platform/docs/editorial.blade.php',
            'resources/views/platform/docs/article.blade.php',
            'app/Support/Docs/DocumentationStatus.php',
        ];

        $found = false;
        foreach ($sources as $relative) {
            $raw = (string) file_get_contents(base_path($relative));

            /* Comments stripped. This change wrote long Blade and PHP comments that name several of
               these strings while explaining them, and CLAUDE.md records six censuses satisfied by
               prose about the thing they measure. */
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

            if (str_contains($code, "'" . $key . "'")) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found,
            "The string \"{$key}\" is declared as a new translation but no view or class in the "
            . 'documentation centre uses it. Either use it or drop it from the list.');
    }
}
