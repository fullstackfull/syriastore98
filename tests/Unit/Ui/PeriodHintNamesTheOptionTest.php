<?php

namespace Tests\Unit\Ui;

use App\Support\Analytics\AnalyticsPeriod;
use Tests\TestCase;

/**
 * THE HINT BESIDE A PERIOD CONTROL MUST NAME THE OPTION AS THE SELECT LABELS IT.
 * =============================================================================================
 *
 * `<x-ss.analytics-period-filter>`'s hint used to read "the two dates apply only when the period
 * is set to **Custom**" — and no option called Custom has ever existed:
 * `AnalyticsPeriod::options()` labels that key `a period I choose`. A merchant looking for
 * "Custom" in the list does not find it, and the two date fields read as broken.
 *
 * The mismatch was just as visible in Arabic («مضبوطة على مخصص» in the hint against «فترة
 * أختارها» in the option), so it was not a translation slip; the English sentence named something
 * that was never built.
 *
 * It shipped on one screen and the extraction of the shared component then carried it to a
 * second and a third before review caught it — which is precisely the failure the component's own
 * docblock cites about its four-times-copied neighbour. A ratchet is cheap and the alternative is
 * noticing it a fourth time.
 *
 * IT BOOTS THE APPLICATION, unlike most of this directory, because `AnalyticsPeriod::options()`
 * resolves its labels through `__()` and a plain PHPUnit TestCase has no `translator` binding --
 * the same shape as the `base_path()` trap this repository has already been bitten by.
 *
 * ASSERTED ON THE RENDERED CATALOGUES, NOT ON THE BLADE SOURCE. A source grep would be satisfied
 * by the words appearing in the explanatory comment that sits directly above the sentence — the
 * trap CLAUDE.md records in three syntaxes, and one this very file would walk into.
 */
class PeriodHintNamesTheOptionTest extends TestCase
{
    private const HINT = 'The two dates apply when the period is "a period I choose". A period longer than :count days is shortened, and the end is moved to today if it is later.';

    private const CUSTOM_OPTION = 'a period I choose';

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $path = dirname(__DIR__, 3) . '/resources/lang/' . $locale . '.json';

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * PREMISE: the key this test guards is the one the component actually renders. Without this
     * the file could pass against a sentence nothing uses.
     */
    public function test_premise_the_shared_control_renders_this_hint(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/views/components/ss/analytics-period-filter.blade.php'
        );

        /* Comments stripped first: the correction's own explanation quotes the OLD wording, and a
           plain search would be satisfied by prose about the thing it is measuring. */
        $markup = preg_replace('/\{\{--.*?--\}\}/s', '', $blade);

        $this->assertStringContainsString(self::HINT, (string) $markup,
            'The shared period control no longer renders the hint this test guards, so the guard '
            . 'is measuring nothing.');
    }

    public function test_the_custom_option_is_still_labelled_the_way_the_hint_quotes_it(): void
    {
        $this->assertSame(self::CUSTOM_OPTION, AnalyticsPeriod::options()[AnalyticsPeriod::CUSTOM],
            'The custom period option was relabelled and the hint beside it still quotes the old '
            . 'words, so the sentence now names an option the select does not offer.');
    }

    /**
     * @dataProvider locales
     */
    public function test_the_hint_names_the_option_in_this_locale(string $locale): void
    {
        $catalogue = $this->catalogue($locale);

        $this->assertArrayHasKey(self::HINT, $catalogue,
            "The period hint has no {$locale} translation.");

        $this->assertArrayHasKey(self::CUSTOM_OPTION, $catalogue,
            "The custom option has no {$locale} translation.");

        $this->assertStringContainsString(
            $catalogue[self::CUSTOM_OPTION],
            $catalogue[self::HINT],
            "The {$locale} hint does not contain the {$locale} label of the option it is telling "
            . 'the merchant to choose, so a reader of that language is sent looking for words '
            . 'that are not in the list.'
        );
    }

    /** @return array<string, array{string}> */
    public static function locales(): array
    {
        return ['english' => ['en'], 'arabic' => ['ar']];
    }
}
