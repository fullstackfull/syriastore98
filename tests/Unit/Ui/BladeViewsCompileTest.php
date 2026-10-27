<?php

namespace Tests\Unit\Ui;

use Tests\TestCase;

/**
 * Every first-party Blade view compiles to PARSEABLE PHP.
 * =================================================================================================
 *
 * WHY THIS EXISTS, and it is not hypothetical: a comment added during P2-05-5 broke
 * `roles/create.blade.php` with an HTTP 500 on the create-role modal, and three separate checks
 * said it was fine.
 *
 *   - `php -l resources/views/roles/create.blade.php` PASSES. A Blade file is mostly HTML, so the
 *     PHP linter has almost nothing to parse and almost nothing to object to.
 *   - `BladeCompiler::compileString()` PASSES. Compiling is not parsing; it happily emits PHP that
 *     will not run.
 *   - The view only fails when RENDERED, which no unit test did.
 *
 * THE TRAP ITSELF IS WORTH KNOWING. Blade extracts raw-PHP blocks in `storeUncompiledBlocks()`
 * BEFORE it strips comments, so writing that directive's name inside a `{{-- --}}` comment opens a
 * real PHP block and swallows everything up to the next closing directive. Demonstrated:
 * `{{-- a @` . `php block --}}` compiles to `{{-- a <?php block --}}` — the comment is never
 * stripped and the tag is live. (The directive name is split in that sentence for exactly the
 * reason the sentence describes.)
 *
 * So this test compiles each view and asks PHP to PARSE the result, which is the step that was
 * missing. It is cheap — no database, no HTTP, no rendering — and it covers every first-party
 * view rather than the handful a feature test happens to render.
 */
class BladeViewsCompileTest extends TestCase
{
    /**
     * First-party views only. `packages/workdo/` is vendored and not ours to fix; including it
     * would make this ratchet fail for reasons nobody here may act on.
     */
    private function views(): array
    {
        $root = base_path('resources/views');
        $out = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    public function test_every_first_party_blade_view_compiles_to_parseable_php(): void
    {
        $views = $this->views();

        /* PREMISE: the sweep found views at all. A glob that matches nothing passes every
           assertion below, which is the shape this project keeps paying for. */
        $this->assertGreaterThan(200, count($views),
            'the view sweep found only ' . count($views) . ' files, so it is not scanning what it '
            . 'claims to scan');

        $compiler = app('blade.compiler');
        $broken = [];

        foreach ($views as $path) {
            $compiled = $compiler->compileString((string) file_get_contents($path));

            /*
             * `token_get_all()` with TOKEN_PARSE raises a ParseError on invalid PHP, which is
             * exactly the question being asked, and does it IN PROCESS -- no temp file, no shell.
             */
            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $broken[] = str_replace(base_path() . '/', '', $path) . ' — ' . $e->getMessage();
            }
        }

        $this->assertSame([], $broken,
            "these Blade views compile to PHP that will not parse, so they 500 when rendered:\n  "
            . implode("\n  ", $broken)
            . "\n\nThe usual cause is a Blade directive name written inside a {{-- comment --}}: "
            . 'raw-PHP blocks are extracted BEFORE comments are stripped, so the directive inside '
            . 'the comment opens a real block.');
    }
}
