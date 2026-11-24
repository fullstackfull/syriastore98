<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-80, round 4 -- a dialog's title is written as TEXT.
 * ===========================================================
 *
 * Every dialog the panel opens takes its title from the clicked element's `data-title` attribute, and the shared handlers in `public/js/custom.js` (three of them) and three per-view copies
 * wrote it with `.html(title)`. An attribute is escaped where Blade prints it and DECODED again where the browser reads it, so `data-title="{{ $name }}"` hands the handler the name as it
 * is stored, and `.html()` parses it as markup. Measured when this was written: 146 distinct titles, every one a translated constant with no markup in any language file, plus an order
 * number in two views -- so nothing was exploitable through a first-party panel view, and nothing is lost by writing a title as text. The next `data-title="{{ $something->name }}"` is
 * then harmless instead of a stored-XSS that no test would see.
 *
 * A SOURCE PIN, and it says so: nothing in the suite runs a page's JavaScript. It does not see a handler in a theme's own script or in a vendored module.
 */
final class ModalTitlesAreWrittenAsTextTest extends TestCase
{
    /** A dialog title written as markup: `<anything>modal-title<quote>).html(<not a quote and not a closing bracket>`. */
    private const TITLE_AS_MARKUP = '/modal-title[\'"]\s*\)\s*\.html\(\s*[^\'")\s]/';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string,string> path => source, for the shared script and every panel view (the storefront and the installer's views are a different reader) */
    private static function sources(): array
    {
        $root = self::root();
        $sources = ['public/js/custom.js' => (string) file_get_contents($root . '/public/js/custom.js')];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/resources/views', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');

            if (! $file->isFile() || ! str_ends_with($relative, '.php')) {
                continue;
            }
            if (str_starts_with($relative, 'resources/views/front_end/') || str_starts_with($relative, 'resources/views/theme_customize/') || str_starts_with($relative, 'resources/views/vendor/')) {
                continue;
            }

            $sources[$relative] = preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($file->getPathname())) ?? '';
        }

        return $sources;
    }

    public function test_the_pattern_sees_the_shape_it_exists_to_forbid(): void
    {
        $this->assertSame(1, preg_match(self::TITLE_AS_MARKUP, '$("#commanModel .modal-title").html(title);'), 'premise: the pattern must match the handler as it was');
        $this->assertSame(1, preg_match(self::TITLE_AS_MARKUP, "\$('#commonModal .modal-title').html( data.title );"), 'premise: and its single-quoted spelling');
        $this->assertSame(0, preg_match(self::TITLE_AS_MARKUP, '$("#commanModel .modal-title").text(title);'), 'premise: and not the text shape');
        $this->assertSame(0, preg_match(self::TITLE_AS_MARKUP, "\$('#commonModal .modal-title').html('');"), 'premise: and not a constant');
    }

    public function test_the_shared_handlers_write_the_title_as_text(): void
    {
        $script = (string) file_get_contents(self::root() . '/public/js/custom.js');

        foreach (['#commanModel', '#commanModelOver', '#commonModal'] as $dialog) {
            $this->assertStringContainsString('$("' . $dialog . ' .modal-title").text(title);', $script, 'the shared handler of ' . $dialog . ' must write its title as text');
        }
    }

    public function test_no_panel_script_writes_a_dialog_title_as_markup(): void
    {
        $sources = self::sources();
        $this->assertGreaterThanOrEqual(150, count($sources), 'premise: the scan found too few files to mean anything');

        $offenders = [];
        foreach ($sources as $path => $source) {
            if (preg_match(self::TITLE_AS_MARKUP, $source) === 1) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'these scripts write a dialog title with .html(): a title is text, write it with .text()');
    }
}
