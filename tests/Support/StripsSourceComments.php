<?php

namespace Tests\Support;

/**
 * Read a source file with its comments removed, for tests that assert on code.
 * ============================================================================
 *
 * THIS EXISTS BECAUSE THE SAME MISTAKE HAS NOW BEEN MADE FIVE TIMES IN THIS REPOSITORY, each
 * time in a different file and each time by somebody who had just read about the previous one:
 *
 *   - `BreadcrumbContractTest` matched the `<ul class="breadcrumb">` quoted in its own docblock;
 *   - the ajax-popup ratchet counted an attribute spelled out in a layout comment;
 *   - `VariantDeleteScopeTest` failed three assertions at once on the comments written while
 *     fixing the defect it ratchets;
 *   - `VariantKeySelectorSafetyTest` matched the comment quoting the selector it had removed;
 *   - `VariantCombinationVocabularyTest` passed with the code deleted, because the `{{-- was:
 *     ... --}}` comments left behind still spelled out what it was looking for.
 *
 * The pattern is always the same and it always reads as success: a comment describing a defect is
 * evidence that somebody understood it, not evidence that it is still in the code. A ratchet that
 * cannot tell those apart passes forever.
 *
 * Nine files had their own private copy of this stripper. A tenth copy was about to be written,
 * so it is shared instead.
 */
trait StripsSourceComments
{
    /**
     * The file's CODE — Blade comments, PHP block and line comments, and JavaScript line
     * comments, in that order.
     *
     * Blade first, because `{{-- ... --}}` can legally contain `/* *\/` and `//`.
     */
    protected function sourceWithoutComments(string $relativePath): string
    {
        /*
         * THE ROOT IS COMPUTED, NOT RESOLVED THROUGH THE CONTAINER.
         *
         * This trait is used by plain `PHPUnit\Framework\TestCase` tests as well as by booted
         * ones. In a plain one no application exists, `app()` returns a bare `Container`, and
         * `base_path()` fatals with
         * "Call to undefined method Illuminate\Container\Container::basePath()".
         *
         * That fatal stayed hidden for weeks: running the FULL Unit suite leaves an application
         * bound in the container from an earlier booted test, so `base_path()` resolved off that
         * and every consumer passed. Running one such file ALONE -- `php artisan test <file>`,
         * which is what a developer does while editing it -- errored before a single assertion.
         * Found 2026-11-22 while running StorefrontMessagesAreDeclaredTest under `--filter`.
         *
         * `dirname(__DIR__, 2)` is this file's own `tests/Support` -> `tests` -> repository root.
         * It needs no framework, which is correct for a helper whose whole job is reading files.
         */
        $path = str_starts_with($relativePath, '/')
            ? $relativePath
            : dirname(__DIR__, 2) . '/' . $relativePath;

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        /*
         * BLADE FIRST, ALWAYS. A `{{-- ... --}}` comment can legally contain PHP comment syntax,
         * so it has to go before anything that looks for PHP comments.
         */
        $stripped = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

        $stripped = $this->isTokenizablePhp($relativePath)
            ? $this->phpCodeOnly($stripped)
            : $this->commentsStrippedByPattern($stripped);

        /*
         * A stripper that eats the whole file turns every `assertDoesNotContain` into a free
         * pass, which is the failure mode of the fix rather than of the defect.
         */
        $this->assertNotSame('', trim($stripped), $relativePath . ': stripping removed the whole file');

        return $stripped;
    }

    /**
     * Is this a file PHP's own tokenizer can read?
     *
     * A plain `.php` file is PHP by definition, inline HTML included (the tokenizer reports that
     * as `T_INLINE_HTML` and we keep it). A `.blade.php` file is NOT: `@extends`, `@if` and
     * `{{ $x }}` are template syntax that the tokenizer sees as one undifferentiated blob of
     * inline HTML, so tokenizing it would be pointless at best. Blade therefore keeps the pattern
     * path, and `StripsSourceCommentsTest` pins both sides of this boundary.
     */
    private function isTokenizablePhp(string $relativePath): bool
    {
        return str_ends_with($relativePath, '.php') && ! str_ends_with($relativePath, '.blade.php');
    }

    /**
     * A PHP file's code, via PHP's own tokenizer.
     *
     * WHY NOT A PATTERN. The pattern path below guards its block-comment opener with a negative
     * lookbehind listing characters that cannot precede a real comment. That list is a GUESS
     * about PHP's grammar, and it was wrong: it excludes a word character, either quote and a
     * slash, but not a closing brace, so `File::glob("{$dir}/*.php")` was read as opening a
     * comment and the lazy match ran to the next real terminator several lines down.
     *
     * MEASURED on `app/Http/Middleware/XSS.php`: seven code tokens vanished -- `$pendingMigrations`
     * twice, `diff`, `count`, `redirect`, `route` and `'LaravelUpdater::welcome'`. A stripper that
     * eats code makes every `assertStringNotContainsString` a free pass, which is worse than the
     * problem the trait exists to solve, and it is invisible: a census that stops looking at part
     * of its subject reads exactly like one that looked and found nothing.
     *
     * AND EXTENDING THE LIST IS NOT THE ANSWER. Adding a brace fixes this file and nothing else:
     * a closing bracket, a closing parenthesis and a semicolon can all legally precede a real
     * comment, so each addition starts DISCARDING real comments and reintroduces the original
     * failure mode. The tokenizer does not guess -- it knows that a slash-star inside a string
     * literal is part of the string.
     *
     * NEWLINES ARE PRESERVED, deliberately. Each comment is replaced by exactly the newlines it
     * contained, so every surviving line keeps its original number. A reader sent to the wrong
     * line by a stripped-and-renumbered file is the second-order version of this same defect; it
     * also means a `//` comment, whose trailing newline PHP includes in the token, does not join
     * its line to the next.
     */
    private function phpCodeOnly(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));

                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * The pattern path, for Blade and anything else the tokenizer cannot read.
     *
     * A NEGATIVE LOOKBEHIND BEFORE THE OPENER, AND IT IS LOAD-BEARING HERE. A naive opener
     * pattern treats the slash-star inside `accept="image/-"` (read that as a wildcard) as the
     * start of a block comment and runs to the next closer. MEASURED at 5678 characters: it
     * swallowed the whole downloadable-file block of two variant views, and two ratchets failed
     * with "this view does not emit the id it looks up". The attribute is correct HTML; the
     * stripper was wrong.
     *
     * The sequences are not written out literally in this paragraph, because a closer inside a
     * PHP block comment ends it -- a mistake made once while fixing this very line, and again
     * while writing the test that covers it.
     *
     * This path keeps the known limitation that PHP files no longer have: a line comment is
     * recognised only at the start of a line, after optional whitespace. Blade's own comment form
     * is the one that matters in a Blade file, and that is handled before this is reached.
     */
    private function commentsStrippedByPattern(string $source): string
    {
        $stripped = preg_replace('#(?<![\w"\'/])/\*.*?\*/#s', '', $source);

        return (string) preg_replace('#^\s*//.*$#m', '', (string) $stripped);
    }
}
