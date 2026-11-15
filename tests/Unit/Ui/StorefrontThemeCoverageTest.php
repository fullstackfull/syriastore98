<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M1 — EVERY SHIPPED THEME MUST BE COVERED BY THE BASELINE HARNESS.
 *
 * `themes/` ships `stylique`, `techzonix` and `greentic`. Every fixture store carried
 * `theme_id = 'stylique'`, so the M0 baseline measured ONE theme three times and said nothing
 * about the other two — and nothing in the repository would have told anyone.
 *
 * A theme is not a skin here. Each has its own `main_file`, its own layout, its own section markup
 * and its own ~530-key `default_data/settings.php` (13, 15 and 16 blade files respectively), so a
 * defect in one is invisible from the others.
 *
 * This is a RATCHET, not a measurement: it reads the filesystem and the harness, so a fourth theme
 * added to `themes/` fails it until the harness knows which store renders that theme and what to
 * expect. That is the only way the "three themes covered" claim can stay true without someone
 * remembering to re-check it.
 *
 * It deliberately asserts nothing about the dev fixture's CONTENTS — the suite runs on
 * `:memory:` and the fixture is a gitignored developer artefact. What is checkable here is that
 * the harness's expectation map names every theme on disk.
 */
class StorefrontThemeCoverageTest extends TestCase
{
    private const HARNESS = 'docs/development/phase-3/tools/storefront-matrix.cjs';

    /**
     * The repository root WITHOUT `base_path()`.
     *
     * A static data provider runs BEFORE the application boots, so `base_path()` there throws
     * "Call to undefined method Illuminate\Container\Container::basePath()" and PHPUnit reports
     * the provider as invalid rather than the test as failing — which is a confusing way to learn
     * this. `__DIR__` is `tests/Unit/Ui`, so three levels up is the root.
     */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> every directory under themes/ that is a real theme */
    private static function shippedThemes(): array
    {
        $themes = [];

        foreach (glob(self::root() . '/themes/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_file($dir . '/views/main_file.blade.php')) {
                $themes[] = basename($dir);
            }
        }

        sort($themes);

        return $themes;
    }

    /** The premise. If this list is empty or short, every assertion below is vacuous. */
    #[Test]
    public function test_the_three_known_themes_are_on_disk_with_their_own_layout(): void
    {
        $themes = self::shippedThemes();

        $this->assertSame(['greentic', 'stylique', 'techzonix'], $themes,
            'The set of shipped themes changed. That is not necessarily wrong, but the Phase 03 '
            . 'baseline claims to cover every one of them, so re-read this test and the harness '
            . 'before changing this list.');
    }

    /** @return array<string, array{0: string}> */
    public static function themes(): array
    {
        $out = [];
        foreach (self::shippedThemes() as $t) {
            $out[$t] = [$t];
        }

        return $out ?: ['none found' => ['']];
    }

    #[Test]
    #[DataProvider('themes')]
    public function test_each_theme_has_the_parts_a_storefront_capture_needs(string $theme): void
    {
        $this->assertNotSame('', $theme, 'No themes were found at all.');

        $this->assertFileExists(self::root() . "/themes/{$theme}/views/main_file.blade.php",
            "{$theme} has no main_file, so a store declaring it would render the FALLBACK theme "
            . 'and its captures would be mislabelled.');

        $this->assertFileExists(self::root() . "/themes/{$theme}/default_data/settings.php",
            "{$theme} ships no default settings, so getThemeSetting() returns a key-less array "
            . 'and every view that indexes it answers 500 — the measured empty-theme_id failure.');

        $defaults = include self::root() . "/themes/{$theme}/default_data/settings.php";

        $this->assertIsArray($defaults);
        $this->assertGreaterThan(400, count($defaults),
            "{$theme} ships only " . count($defaults) . ' default keys; the three shipped themes '
            . 'carry 500+, so something is truncated.');
    }

    /**
     * THE RATCHET. The harness's expectation map must name every theme on disk, or a theme is
     * being captured with no assertion that the page really rendered it — which is exactly the
     * mislabelled-evidence failure the map exists to prevent.
     */
    #[Test]
    public function test_the_harness_expects_a_theme_for_every_shipped_theme(): void
    {
        $harness = file_get_contents(base_path(self::HARNESS));

        $this->assertStringContainsString('EXPECTED_THEME', $harness,
            'The harness no longer carries a theme expectation map, so a store whose theme fails '
            . 'to resolve would be captured as that theme anyway.');

        // The map's VALUES are theme names; every shipped theme must appear as one.
        $block = substr($harness, strpos($harness, 'const EXPECTED_THEME'));
        $block = substr($block, 0, strpos($block, '};'));

        foreach (self::shippedThemes() as $theme) {
            $this->assertStringContainsString("'{$theme}'", $block,
                "No fixture store is declared to render '{$theme}', so the Phase 03 baseline does "
                . 'not cover it. Add a store for it to the fixture and to EXPECTED_THEME.');
        }
    }

    /**
     * And the fixture tool must actually create a store for each theme, or the map above names a
     * theme no capture ever drives.
     */
    #[Test]
    public function test_the_fixture_creates_a_store_for_every_shipped_theme(): void
    {
        $seeder = file_get_contents(
            base_path('docs/development/phase-3/tools/seed-storefront-fixture.php'));

        foreach (self::shippedThemes() as $theme) {
            $this->assertMatchesRegularExpression(
                "/'{$theme}'/",
                $seeder,
                "The fixture tool never creates a store on '{$theme}', so the harness's "
                . 'expectation for it can never be exercised.'
            );
        }
    }
}
