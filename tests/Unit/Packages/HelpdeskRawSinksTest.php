<?php

namespace Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;

/**
 * P1-SEC-75 -- THE HELPDESK MODULE'S RAW SINKS, AS A LEDGER THAT THE UNIT SUITE ENFORCES.
 * ===========================================================================================
 *
 * The module is vendored and is not edited. What the host does about its raw output is a set of overrides and subclasses (resources/views/vendor/helpdesk/*, App\DataTables\SafeHelpdesk*),
 * and each is a COPY of, or a replacement for, something upstream. A copy goes stale silently: after a module update the host would keep serving the old markup, and a new raw print upstream
 * would be nobody's business. HelpdeskStoredXssTest pins the two views against their vendored originals, but it is a Feature test, and the Feature suite does not run when the Unit suite is red.
 * So the part that does not need a database is here:
 *
 *   1. the number of `{!! !!}` prints in every view of the module and of `rawColumns(` calls in every class, per file (a new one, or one that moved, is a change to read);
 *   2. the hash of each vendored list class the host re-implements a cell of (a change upstream is the prompt to re-diff the subclass against it);
 *   3. that nothing initialises Summernote on the module's textareas, which hold a ticket's stored HTML: Summernote copies a textarea's DECODED value into an editable element, so the moment a
 *      script initialises it there, a merchant's markup runs in the operator's session on the edit page (executed by a reviewer: no initialiser, no dialog; an initialiser, one dialog).
 *
 * A failure here is not a bug in the host. It is the module changing under overrides that were written against the old one: read the module's diff, update the host's copy, then update this ledger.
 */
final class HelpdeskRawSinksTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../packages/workdo/Helpdesk/src';

    /** per file: how many `{!!` the module prints, and what the host does about each */
    private const RAW_PRINTS = [
        // the flashed success/error message inside a single-quoted JS string. NOT covered: no request-controlled text reaches it today (the controllers flash fixed sentences and validator
        // messages; the one path that appends exception text calls a mail helper the host does not define). Recorded in the work queue, P1-SEC-75 (e).
        'resources/views/helpdesk_ticket/master.blade.php' => 2,
        // the ticket's description and each reply: covered by resources/views/vendor/helpdesk/helpdesk_ticket/{show,ticket_reply}.blade.php (RichText::clean)
        'resources/views/helpdesk_ticket/show.blade.php' => 2,
        'resources/views/helpdesk_ticket/ticket_reply.blade.php' => 2,
    ];

    /** per file: how many `rawColumns(` calls, every one covered by a subclass bound in AppServiceProvider */
    private const RAW_COLUMNS = [
        'DataTables/HelpdeskData.php' => 1,           // App\DataTables\SafeHelpdeskData (rebuilt: nothing of the vendored dataTable() runs)
        'DataTables/HelpdeskCategoryData.php' => 1,   // App\DataTables\SafeHelpdeskCategoryData (the colour swatch)
        'DataTables/HelpdeskStatusData.php' => 1,     // App\DataTables\SafeHelpdeskStatusData (the colour swatch)
    ];

    /** sha256 of each vendored list class, line endings normalised */
    private const PINNED = [
        'DataTables/HelpdeskData.php' => 'e51d79e857f23971000fb6ae496e294bdd9492ac4f7871fb7054c386429a36cb',
        'DataTables/HelpdeskCategoryData.php' => '7647237a2593e1c285270fc908e3501e8bab723643797d419b49701258aaa882',
        'DataTables/HelpdeskStatusData.php' => '0a43f3ac0b8fa9059839296e330982ebcf9e6fd682b5f6d60dc651c5453bb8ca',
    ];

    /** @return array<string, string> relative path => contents, for every file under $dir with this extension */
    private static function filesUnder(string $dir, string $extension): array
    {
        $base = realpath(self::MODULE);
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $found[ltrim(substr($file->getRealPath(), strlen($base)), '/')] = (string) file_get_contents($file->getRealPath());
            }
        }

        ksort($found);

        return $found;
    }

    public function test_the_module_is_where_the_ledger_looks(): void
    {
        $this->assertDirectoryExists(self::MODULE, 'Premise: the vendored Helpdesk module is present, or every count below is vacuously zero.');
        $this->assertNotEmpty(self::filesUnder(self::MODULE . '/resources/views', '.blade.php'));
        $this->assertNotEmpty(self::filesUnder(self::MODULE . '/DataTables', '.php'));
    }

    public function test_the_modules_raw_prints_are_the_ones_the_host_knows_about(): void
    {
        $counts = [];

        foreach (self::filesUnder(self::MODULE, '.blade.php') as $path => $source) {
            if (($n = substr_count($source, '{!!')) > 0) {
                $counts[$path] = $n;
            }
        }

        $expected = self::RAW_PRINTS;
        ksort($expected);

        $this->assertSame($expected, $counts, 'The Helpdesk module prints a different number of `{!! !!}` than the host was written against. A new raw print is a new way for stored text to reach the operator\'s browser: read it, cover it (resources/views/vendor/helpdesk/..., RichText::clean) and update HelpdeskRawSinksTest::RAW_PRINTS.');
    }

    public function test_the_modules_raw_columns_are_the_ones_the_host_knows_about(): void
    {
        $counts = [];

        foreach (self::filesUnder(self::MODULE, '.php') as $path => $source) {
            if (($n = substr_count($source, 'rawColumns(')) > 0) {
                $counts[$path] = $n;
            }
        }

        $expected = self::RAW_COLUMNS;
        ksort($expected);

        $this->assertSame($expected, $counts, 'The Helpdesk module declares a different set of `rawColumns(` than the host was written against. A raw column prints stored text as markup: cover it with a subclass bound in AppServiceProvider and update HelpdeskRawSinksTest::RAW_COLUMNS.');
    }

    public function test_the_vendored_list_classes_are_the_ones_the_host_subclasses_were_read_against(): void
    {
        foreach (self::PINNED as $path => $hash) {
            $source = str_replace("\r\n", "\n", (string) file_get_contents(self::MODULE . '/' . $path));

            $this->assertSame($hash, hash('sha256', $source), "{$path} changed upstream. Re-diff the host's App\\DataTables\\SafeHelpdesk*Data against it (SafeHelpdeskData rebuilds dataTable(): a column added or reshaped upstream is not in the host's copy), then update the hash here.");
        }
    }

    // ---- nothing initialises Summernote on the module's textareas ---------------------------------

    /** `$('.summernote').summernote(` and the other selectors that reach the Helpdesk textareas; `.summernote-simple` is a different class and is not one of them */
    private const INITIALISER = '/\$\(\s*([\'"])(?:\.summernote|textarea(?:\.summernote)?|#note|#help-desc|#reply_description|#description|\[name=[\'"]?(?:note|description|reply_description)[\'"]?\])\1\s*\)\s*\.summernote\s*\(/';

    public function test_the_initialiser_pattern_sees_what_it_is_there_to_see(): void
    {
        foreach (["$('.summernote').summernote({", '$("textarea.summernote").summernote(', "$('#note').summernote()", "\$('#help-desc')\n  .summernote({", '$("#description").summernote('] as $init) {
            $this->assertSame(1, preg_match(self::INITIALISER, $init), $init);
        }

        foreach (["$('.summernote-simple').summernote({", "$('.summernote-simple-product').summernote({", '$("#notesform .summernote").val();', "$('.summernote').val()"] as $other) {
            $this->assertSame(0, preg_match(self::INITIALISER, $other), $other);
        }
    }

    public function test_no_script_initialises_summernote_on_a_ticket_textarea(): void
    {
        $root = __DIR__ . '/../../..';
        $sources = [
            'public/js/custom.js' => (string) file_get_contents($root . '/public/js/custom.js'),
        ];

        foreach (glob($root . '/resources/views/layouts/*.blade.php') ?: [] as $layout) {
            $sources['resources/views/layouts/' . basename($layout)] = (string) file_get_contents($layout);
        }

        foreach (self::filesUnder(self::MODULE . '/resources/views', '.blade.php') as $path => $source) {
            $sources['packages/workdo/Helpdesk/src/' . $path] = $source;
        }

        foreach (glob($root . '/resources/views/vendor/helpdesk/helpdesk_ticket/*.blade.php') ?: [] as $override) {
            $sources['resources/views/vendor/helpdesk/helpdesk_ticket/' . basename($override)] = (string) file_get_contents($override);
        }

        $this->assertStringContainsString('summernote-simple', $sources['public/js/custom.js'], 'Premise: custom.js is the file that initialises the host\'s editors, or this scan looks at nothing.');

        $found = [];

        foreach ($sources as $name => $source) {
            if (preg_match(self::INITIALISER, $source) === 1) {
                $found[] = $name;
            }
        }

        $this->assertSame([], $found, 'A script now initialises Summernote on a textarea that holds a ticket\'s stored HTML. Summernote copies a textarea\'s DECODED value into an editable element, so a merchant\'s markup would run in the operator\'s session on the edit page. Sanitise the value with RichText::clean() before it goes into the textarea (an override of the view, pinned like the other two) before initialising an editor there.');
    }
}
