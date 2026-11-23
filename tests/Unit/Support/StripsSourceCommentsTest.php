<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * The shared comment stripper must remove comments and NOTHING ELSE.
 * =============================================================================================
 *
 * `Tests\Support\StripsSourceComments` is the shared helper that 47 census tests use to read a
 * source file with its comments removed, so that an assertion about code cannot be satisfied by
 * prose ABOUT the code. CLAUDE.md records that failure mode hitting four times on 2026-11-21
 * alone, in three syntaxes.
 *
 * This file tests the helper itself, which nothing did. It has a defect in the OTHER direction,
 * and that direction is worse: a stripper that eats CODE turns every `assertStringNotContainsString`
 * into a free pass and every `assertStringContainsString` into a false failure. There is no
 * symmetry here -- a census that silently stops looking at part of its own subject reads exactly
 * like a census that looked and found nothing.
 *
 * THE DEFECT, MEASURED on `app/Http/Middleware/XSS.php` before the fix. The block-comment pass
 * guards its opener with a negative lookbehind, `(?<![\w"\'/])`, which excludes a word character,
 * either quote and a slash. It does NOT exclude a closing brace. So in
 *
 *     File::glob("{$dir}/*.php")            <- the slash-star here is INSIDE a string literal
 *
 * the slash-star is read as a comment opener, and the lazy match runs to the next real terminator
 * -- several lines later, swallowing every line between. Seven code tokens vanished from that
 * file: `$pendingMigrations` (twice), `diff`, `count`, `redirect`, `route`, and the literal
 * `'LaravelUpdater::welcome'`.
 *
 * WHY A STRING SEARCH COULD NOT FIND THIS, which is the lesson worth keeping. An earlier note
 * claimed the eaten code was `->map(fn ($path) => File::name($path))` and checked it with
 * `str_contains($stripped, 'File::name')`. That check PASSED -- because `File::name` occurs twice
 * in that file and the second occurrence is outside the swallowed span. The string was present;
 * the code was gone. Only a token-level comparison against `token_get_all()` found it. This is
 * the same "scope the search to the block" rule CLAUDE.md states for censuses, turned on the
 * census tooling itself.
 *
 * ADDING `}` TO THE LOOKBEHIND IS NOT THE FIX, and that is why this is a test and not a one-line
 * patch. The character class is unbounded: `$a[0]/* real comment *\/` is a genuine comment
 * preceded by a closing bracket, so excluding `]` would start DISCARDING real comments, which
 * reintroduces the original failure mode. Any list of "characters that cannot precede a comment"
 * is a guess about PHP's grammar. PHP ships a tokenizer that knows the grammar exactly, so the
 * PHP path uses it. Blade is not valid PHP and cannot be tokenized, so it keeps the regex path --
 * and the last test here pins that boundary so nobody "simplifies" it away.
 */
class StripsSourceCommentsTest extends TestCase
{
    use StripsSourceComments;

    /** @var string[] */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporary = [];

        parent::tearDown();
    }

    private function fixture(string $basename, string $contents): string
    {
        $path = sys_get_temp_dir() . '/ssc-' . getmypid() . '-' . uniqid() . '-' . $basename;
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return $path;
    }

    // =============================================================================================
    // THE DEFECT
    // =============================================================================================

    /**
     * A slash-star inside a STRING LITERAL is not a comment opener.
     *
     * The terminator below is a real comment's, several lines down -- which is what makes the span
     * large in real code. Both sentinels sit inside that span.
     */
    public function test_a_glob_pattern_in_a_string_does_not_open_a_comment(): void
    {
        $path = $this->fixture('glob.php', <<<'PHP'
            <?php

            $files = collect(File::glob("{$dir}/*.php"));
            $firstSentinel = 'KEEP_THIS_AFTER_THE_GLOB';
            $secondSentinel = 'KEEP_THIS_TOO';

            /* An ordinary block comment, whose terminator is the first one in the file. */

            $third = 'AFTER_THE_REAL_COMMENT';
            PHP);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringContainsString('KEEP_THIS_AFTER_THE_GLOB', $stripped,
            'a slash-star inside a string literal was treated as a comment opener, and the code '
            . 'after it was swallowed up to the next real terminator.');
        $this->assertStringContainsString('KEEP_THIS_TOO', $stripped);
        $this->assertStringContainsString('AFTER_THE_REAL_COMMENT', $stripped);
    }

    /**
     * The real production instance. If `XSS.php` is ever rewritten this test becomes vacuous
     * rather than wrong, so it asserts its own premise first.
     */
    public function test_no_code_token_is_lost_from_the_file_that_exposed_this(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Http/Middleware/XSS.php');

        $this->assertStringContainsString('File::glob("{$dir}/*.php")', $raw,
            'premise failed: this file no longer contains the glob interpolation that exposed the '
            . 'defect, so this test proves nothing. Point it at a file that does, or delete it.');

        $this->assertSame([], $this->codeTokensMissingFrom('app/Http/Middleware/XSS.php'));
    }

    /**
     * And not whack-a-mole: the same invariant across every first-party middleware, so a fix
     * tailored to one file does not pass.
     */
    public function test_no_code_token_is_lost_from_any_first_party_middleware(): void
    {
        $dir = dirname(__DIR__, 3) . '/app/Http/Middleware';
        $files = glob($dir . '/*.php') ?: [];

        $this->assertGreaterThan(10, count($files),
            'premise failed: the middleware directory should hold more than ten files; the scan '
            . 'was pointed somewhere wrong and an empty result would read as a pass.');

        $offenders = [];

        foreach ($files as $file) {
            $missing = $this->codeTokensMissingFrom('app/Http/Middleware/' . basename($file));

            if ($missing !== []) {
                $offenders[basename($file)] = array_slice($missing, 0, 5);
            }
        }

        $this->assertSame([], $offenders,
            "the stripper removed CODE, not just comments, from these files:\n"
            . json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // =============================================================================================
    // AND IT MUST STILL DO ITS ACTUAL JOB -- these are the oracles
    // =============================================================================================

    public function test_it_still_removes_the_comment_forms_it_exists_to_remove(): void
    {
        $path = $this->fixture('comments.php', <<<'PHP'
            <?php

            /**
             * A docblock mentioning FORBIDDEN_IN_DOCBLOCK.
             */

            /* A block comment mentioning FORBIDDEN_IN_BLOCK. */

            // A line comment mentioning FORBIDDEN_IN_LINE.

            $live = 'PRESENT_IN_CODE';
            PHP);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringNotContainsString('FORBIDDEN_IN_DOCBLOCK', $stripped);
        $this->assertStringNotContainsString('FORBIDDEN_IN_BLOCK', $stripped);
        $this->assertStringNotContainsString('FORBIDDEN_IN_LINE', $stripped);
        $this->assertStringContainsString('PRESENT_IN_CODE', $stripped,
            'the stripper removed code along with the comments.');
    }

    /**
     * A trailing line comment, not at the start of a line. The regex pass anchored a line comment
     * to the start of a line, after optional whitespace, so it never removed one of these --
     * meaning a census could be satisfied by a trailing comment. The tokenizer removes it, which
     * is strictly better; this pins that improvement so it is not lost.
     *
     * The anchor pattern is described in words rather than quoted, because writing it out puts a
     * star immediately before a slash and ends this very comment. That is not a hypothetical: it
     * is how the first draft of this file failed to parse, and it is the second time in one
     * session that this file's subject has bitten the file itself.
     */
    public function test_it_removes_a_trailing_line_comment_too(): void
    {
        $path = $this->fixture('trailing.php', <<<'PHP'
            <?php

            $live = 'PRESENT_IN_CODE'; // and FORBIDDEN_IN_TRAILING here
            PHP);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringNotContainsString('FORBIDDEN_IN_TRAILING', $stripped);
        $this->assertStringContainsString('PRESENT_IN_CODE', $stripped);
    }

    /**
     * EVERY SURVIVING LINE KEEPS ITS ORIGINAL NUMBER.
     *
     * CLAUDE.md states the second-order version of this defect: a stripper that deletes a
     * comment's newlines along with its text renumbers the file and sends a reader to the wrong
     * place. A census that reports an offender's line number from stripped text would then be
     * confidently wrong. So each comment is replaced by exactly the newlines it contained.
     */
    public function test_a_surviving_line_keeps_its_original_number(): void
    {
        $path = $this->fixture('lines.php', <<<'PHP'
            <?php

            /**
             * A docblock
             * spanning
             * several lines.
             */

            /* and a one-line block comment */

            $marker = 'ON_LINE_ELEVEN';
            PHP);

        $lines = explode("\n", $this->sourceWithoutComments($path));

        $found = null;

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'ON_LINE_ELEVEN')) {
                $found = $index + 1;
                break;
            }
        }

        $this->assertSame(11, $found,
            'the marker is on line 11 of the fixture; stripping moved it to line '
            . var_export($found, true) . ', so comment newlines were discarded and every line '
            . 'number a census reports from stripped text would be wrong.');
    }

    /**
     * The hazard the lookbehind was originally added for, which must stay fixed: a wildcard MIME
     * attribute is correct HTML and is not a comment. Measured at 5,678 characters when it broke
     * -- it swallowed the downloadable-file block of two variant views.
     */
    public function test_a_wildcard_mime_attribute_does_not_open_a_comment(): void
    {
        $path = $this->fixture('accept.blade.php', <<<'BLADE'
            <input type="file" accept="image/*">
            <span id="KEEP_THE_ATTRIBUTE_BLOCK">x</span>

            {{-- FORBIDDEN_IN_BLADE_COMMENT --}}

            <span id="AFTER_THE_BLADE_COMMENT">y</span>
            BLADE);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringContainsString('KEEP_THE_ATTRIBUTE_BLOCK', $stripped,
            'a wildcard MIME attribute was treated as a comment opener.');
        $this->assertStringContainsString('AFTER_THE_BLADE_COMMENT', $stripped);
        $this->assertStringNotContainsString('FORBIDDEN_IN_BLADE_COMMENT', $stripped,
            'Blade comments must still be removed -- that is the helper\'s original purpose.');
    }

    /**
     * Blade is not valid PHP, so it CANNOT go through the tokenizer, and this pins that the
     * helper keeps a working path for it. A `.blade.php` file carrying Blade directives would
     * make `token_get_all()` produce nonsense (it is all inline HTML to the tokenizer), so the
     * assertion here is about behaviour, not implementation: comments out, markup intact.
     */
    public function test_a_blade_file_with_directives_is_still_stripped_correctly(): void
    {
        $path = $this->fixture('directives.blade.php', <<<'BLADE'
            @extends('layouts.app')

            @section('content')
                {{-- FORBIDDEN_IN_BLADE --}}
                <div class="KEEP_THIS_CLASS">{{ $order->id }}</div>
                @if ($order->is_guest)
                    <span>KEEP_THIS_BRANCH</span>
                @endif
            @endsection
            BLADE);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringNotContainsString('FORBIDDEN_IN_BLADE', $stripped);
        $this->assertStringContainsString('KEEP_THIS_CLASS', $stripped);
        $this->assertStringContainsString('KEEP_THIS_BRANCH', $stripped);
        $this->assertStringContainsString("@extends('layouts.app')", $stripped,
            'a Blade directive was removed; the helper must not route Blade through a PHP parser.');
    }

    /**
     * WHAT THE BLADE BOUNDARY ACTUALLY BUYS, and the test that pins it.
     *
     * An earlier version of this file "proved" the boundary with a fixture of directives and
     * markup — and a mutation that routed Blade through the PHP tokenizer SURVIVED it. The reason
     * is worth writing down: to `token_get_all()`, a file with no PHP open tag is one single
     * `T_INLINE_HTML` token, so it comes back byte-identical and both paths agree. The Blade
     * comment had already been removed by the unconditional first pass, before either branch.
     * That test was decorative; mutation testing is what exposed it.
     *
     * The real difference is INLINE SCRIPT AND STYLE comments, which the trait has always removed
     * from templates (its docblock lists "JavaScript line comments") and which the tokenizer
     * cannot see, because to it they are just more inline HTML. So this is the assertion that
     * distinguishes the two paths, and it fails if Blade is ever routed through the tokenizer.
     */
    public function test_a_blade_file_still_has_its_inline_script_comments_removed(): void
    {
        $path = $this->fixture('inline.blade.php', <<<'BLADE'
            <script>
                // FORBIDDEN_IN_JS_LINE
                /* FORBIDDEN_IN_JS_BLOCK */
                const keep = 'KEEP_THE_SCRIPT_BODY';
            </script>
            BLADE);

        $stripped = $this->sourceWithoutComments($path);

        $this->assertStringNotContainsString('FORBIDDEN_IN_JS_LINE', $stripped,
            'an inline script line comment survived, so a census over a Blade file can still be '
            . 'satisfied by prose about the thing it measures.');
        $this->assertStringNotContainsString('FORBIDDEN_IN_JS_BLOCK', $stripped);
        $this->assertStringContainsString('KEEP_THE_SCRIPT_BODY', $stripped,
            'the script body itself was removed.');
    }

    // =============================================================================================
    // HELPER
    // =============================================================================================

    /**
     * Every CODE token of the file that does NOT survive stripping.
     *
     * `token_get_all()` is the ground truth: it knows PHP's grammar, so it knows a slash-star in a
     * string literal is a string, not a comment. Short tokens are skipped because a one- or
     * two-character token such as `;` or `=>` occurs everywhere and a containment check on it is
     * meaningless -- the point is to catch identifiers and literals going missing.
     */
    private function codeTokensMissingFrom(string $relativePath): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        $stripped = $this->sourceWithoutComments($relativePath);

        $missing = [];

        foreach (token_get_all($raw) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $text = trim($token[1]);

            if ($text === '' || strlen($text) < 4) {
                continue;
            }

            if (! str_contains($stripped, $text)) {
                $missing[] = 'line ' . $token[2] . ': ' . $text;
            }
        }

        return $missing;
    }
}
