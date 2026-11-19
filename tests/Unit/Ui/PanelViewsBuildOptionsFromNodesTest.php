<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * The check is a TRIPWIRE and not a proof. It sees two spellings of the same defect: (1) the original pattern -- an `<option` whose quoted markup is followed by a `+` and a name, or a `+`
 * followed by a quoted `</option>`; and (2) a scan of every `<script>` body for a string literal that holds `<option` or `</option>` (any case) and is either a template literal with `${` or is
 * next to a `+` / `+=`. It does NOT see `.concat(...)`, `[...].join('')`, `$('<option/>', {html: x})` or a builder in a file outside resources/views: a review found the first version of this
 * ratchet missed a template literal, and the second is written to its list; the next spelling is for the next review.
 */
final class PanelViewsBuildOptionsFromNodesTest extends TestCase
{
    private const PATTERN = '/<option\b[^\'"`<>]*[\'"`]\s*\+\s*[A-Za-z_$(]|\+\s*[\'"`]<\/option>/';

    /**
     * Views the scan flags that are KNOWN and not fixed yet. An entry is a lead with a reason; it is deleted when the view is fixed, and the second test fails on an entry that is no longer
     * flagged, so the list cannot rot. A view that is not here and is flagged fails the first test.
     */
    private const OPEN = [
        'resources/views/menu/script.blade.php' => 'the menu builder writes a stored category, page, brand, product or blog NAME and a URL into a template literal that becomes markup (the work queue, P1-SEC-80 round 3, finding C-3)',
    ];

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

    /**
     * Every string literal of a script body that holds an <option> tag and is built from a value: a template literal with `${`, or a literal next to a `+` / `+=`.
     *
     * @return list<string>
     */
    private static function optionLiteralsBuiltFromValues(string $js): array
    {
        preg_match_all('/\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`/s', $js, $matches, PREG_OFFSET_CAPTURE);
        $found = [];

        foreach ($matches[0] as [$literal, $offset]) {
            if (preg_match('/<\/?option\b/i', $literal) !== 1) {
                continue;
            }

            $before = substr($js, max(0, $offset - 40), min(40, $offset));
            $after = substr($js, $offset + strlen($literal), 40);
            // an interpolation that only chooses between two constants (`${v === 'sale' ? 'selected' : ''}`) puts nothing of a value into the markup
            $withoutFlags = preg_replace('/\$\{[^{}]*\?\s*\'(?:selected|checked)\'\s*:\s*\'\'\s*\}/', '', $literal) ?? $literal;
            $template = $literal[0] === '`' && str_contains($withoutFlags, '${');
            $adjacent = preg_match('/\+=?\s*$/', $before) === 1 || preg_match('/^\s*\+/', $after) === 1;

            if ($template || $adjacent) {
                $found[] = $literal;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function scriptBodies(string $source): array
    {
        $source = preg_replace(['/\{\{--.*?--\}\}/s', '/@php\b.*?@endphp/s'], '', $source) ?? $source;
        preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $source, $blocks);

        return $blocks[1];
    }

    /** @return array<string,array{0:string,1:bool}> a snippet of a script, and whether the scan must flag it */
    public static function spellings(): array
    {
        return [
            'concatenation, single quotes' => ["\$(o).append('<option value=\"' + i + '\">' + item + '</option>');", true],
            'concatenation, the closer on its own' => ["var h = html + '</option>';", true],
            'a template literal' => ["var h = `<option value=\"\${i}\">\${item}</option>`;", true],
            'a closer added with +=' => ["h += '<option value=\"0\">'; h += item; h += '</option>';", true],
            'upper case' => ["x = '<OPTION value=\"' + v + '\">';", true],
            'a literal after the value' => ["x = item + '<option>';", true],
            'the node-building shape' => ["\$(o).append(\$('<option>').val(i).text(item));", false],
            'a constant' => ["\$('.city').html('<option value=\"0\">Select City</option>');", false],
            'a constant template literal' => ["var h = `<option value=\"0\">Select</option>`;", false],
            'a selected flag chosen by comparison' => ["var h = `<option value=\"a\" \${v === 'a' ? 'selected' : ''}>A</option>`;", false],
            'a value next to a selected flag' => ["var h = `<option value=\"\${v}\" \${v === 'a' ? 'selected' : ''}>A</option>`;", true],
            'a tag that is not an option' => ["x = '<div>' + name + '</div>';", false],
        ];
    }

    #[DataProvider('spellings')]
    public function test_the_scan_sees_every_spelling_it_lists(string $js, bool $mustFlag): void
    {
        $this->assertSame($mustFlag, self::optionLiteralsBuiltFromValues($js) !== [], $mustFlag ? 'premise: this spelling of the defect must be flagged' : 'premise: this safe shape must not be flagged');
    }

    public function test_the_pattern_sees_the_shape_it_exists_to_forbid(): void
    {
        $this->assertSame(1, preg_match(self::PATTERN, "\$(options).append('<option value=\"' + i + '\">' + item + '</option>');"), 'premise: the pattern must match the shape that was found');
        $this->assertSame(1, preg_match(self::PATTERN, "\$(option).append('<option value=\"' + item.id + '\">' +\n item.name + '</option>');"), 'premise: and when the name is on the next line');
        $this->assertSame(0, preg_match(self::PATTERN, "\$(options).append(\$('<option>').val(i).text(item));"), 'premise: and not the node-building shape');
        $this->assertSame(0, preg_match(self::PATTERN, "\$('.city').html('<option value=\"0\">{{ __('Select City') }}</option>')"), 'premise: and not a constant');
    }

    /** @return list<string> the views the ratchet flags */
    private static function flaggedViews(): array
    {
        $views = self::views();
        self::assertGreaterThanOrEqual(150, count($views), 'premise: the scan found too few views to mean anything');

        $flaggedViews = [];
        foreach ($views as $path) {
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

            $flagged = preg_match(self::PATTERN, $source) === 1;

            foreach (self::scriptBodies($source) as $body) {
                $flagged = $flagged || self::optionLiteralsBuiltFromValues($body) !== [];
            }

            if ($flagged) {
                $flaggedViews[] = ltrim(str_replace(dirname(__DIR__, 3), '', $path), '/');
            }
        }

        return $flaggedViews;
    }

    public function test_no_panel_view_builds_option_markup_from_a_value(): void
    {
        $this->assertSame([], array_values(array_diff(self::flaggedViews(), array_keys(self::OPEN))), 'these views build <option> markup from a value: build the node with $(\'<option>\').val(v).text(t), or escape what is interpolated and say why here');
    }

    public function test_every_open_entry_is_still_flagged(): void
    {
        $this->assertSame([], array_values(array_diff(array_keys(self::OPEN), self::flaggedViews())), 'these views are no longer flagged: delete their entry from OPEN');
    }
}
