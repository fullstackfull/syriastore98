<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 3 (finding C-1's census) -- a panel view prints with Blade, not with `<?=` or `<?php echo`.
 * =================================================================================================
 *
 * `<?= $color ?>` printed the store's colour, unescaped, inside the admin layout's `<style>` on every panel page, and round 1's census counted `{!!` and `rawColumns(` and never saw it: a PHP echo is
 * a raw print that is spelled differently. Blade's `{{ }}` escapes; `{!! !!}` is in the census. The views that still echo with PHP are listed here with what they print, and a view that stops is
 * deleted from the list (the second test).
 */
final class NoRawShortEchoInPanelViewsTest extends TestCase
{
    private const OPEN = [
        'resources/views/plans/stripe.blade.php' => 'the OPERATOR\'s Stripe publishable key and the locale, printed into a JavaScript string on the operator\'s own plan-payment page; no store or shopper text',
    ];

    /** @return list<string> */
    private static function offenders(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/resources/views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            $relative = ltrim(str_replace($root, '', $path), '/');

            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($relative, '/vendor/') || str_starts_with($relative, 'resources/views/front_end/')) {
                continue;
            }

            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

            if (preg_match('/<\?=|<\?php\s+echo\b/i', (string) $source) === 1) {
                $found[] = $relative;
            }
        }

        sort($found);

        return $found;
    }

    public function test_the_scan_sees_the_spellings_it_exists_for(): void
    {
        foreach (['<?= $color ?>', '<?=$color?>', '<?php echo $color; ?>', '<?PHP ECHO $color ?>'] as $sample) {
            $this->assertSame(1, preg_match('/<\?=|<\?php\s+echo\b/i', $sample), 'premise: ' . $sample);
        }
        $this->assertSame(0, preg_match('/<\?=|<\?php\s+echo\b/i', '{{ $color }} <?php $x = 1; ?>'), 'premise: and not Blade, nor a PHP block that prints nothing');
    }

    public function test_no_panel_view_echoes_with_php(): void
    {
        $this->assertSame([], array_values(array_diff(self::offenders(), array_keys(self::OPEN))), 'a view prints with `<?=` or `<?php echo`, which escapes nothing: use {{ }}, or list it in OPEN with what it prints');
    }

    public function test_every_open_entry_still_echoes(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(self::OPEN), self::offenders())), 'these views no longer echo with PHP: delete their entry from OPEN');
    }
}
