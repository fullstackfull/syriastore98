<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80 -- a panel view does not build `<option>` markup by concatenating a value into a string.
 * =================================================================================================
 *
 * The flash-sale forms, the product editor and the country screen built their lists with `'<option value="' + i + '">' + item.name + '</option>'`. The value is a product's, a category's
 * or an attribute's NAME -- text a staff member typed -- and the string becomes markup when it is appended, so a name with a tag in it ran in the owner's page. `$('<option>').val(i).text(name)`
 * cannot do that, and costs the same.
 *
 * Scope: every Blade view under resources/views except the storefront (`front_end`, `theme_customize`, which are a different reader and a different slice) and the vendored installer views.
 * The check is a pattern, so it is the tripwire and not a proof: it looks for an `<option` whose quoted markup is followed by a `+` and a name, or a `+` followed by a quoted `</option>`.
 */
final class PanelViewsBuildOptionsFromNodesTest extends TestCase
{
    private const PATTERN = '/<option\b[^\'"`<>]*[\'"`]\s*\+\s*[A-Za-z_$(]|\+\s*[\'"`]<\/option>/';

    /** @return list<string> */
    private static function views(): array
    {
        $root = dirname(__DIR__, 3) . '/resources/views';
        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = $file->getPathname();
            $relative = ltrim(str_replace($root, '', $path), '/');

            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_starts_with($relative, 'front_end/') || str_starts_with($relative, 'theme_customize/') || str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    public function test_the_pattern_sees_the_shape_it_exists_to_forbid(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, "\$(options).append('<option value=\"' + i + '\">' + item + '</option>');"), 'premise: the pattern must match the shape that was found');
        $this->assertSame(1, preg_match(self::PATTERN, "\$(option).append('<option value=\"' + item.id + '\">' +\n item.name + '</option>');"), 'premise: and when the name is on the next line');
        $this->assertSame(0, preg_match(self::PATTERN, "\$(options).append(\$('<option>').val(i).text(item));"), 'premise: and not the node-building shape');
        $this->assertSame(0, preg_match(self::PATTERN, "\$('.city').html('<option value=\"0\">{{ __('Select City') }}</option>')"), 'premise: and not a constant');
    }

    public function test_no_panel_view_builds_option_markup_from_a_value(): void
    {
        $views = self::views();
        $this->assertGreaterThanOrEqual(150, count($views), 'premise: the scan found too few views to mean anything');

        $offenders = [];
        foreach ($views as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

            if (preg_match(self::PATTERN, $source) === 1) {
                $offenders[] = ltrim(str_replace(dirname(__DIR__, 3), '', $path), '/');
            }
        }

        $this->assertSame([], $offenders, 'these views concatenate a value into <option> markup: build the node with $(\'<option>\').val(v).text(t)');
    }
}
