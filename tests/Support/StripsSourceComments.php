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
        $path = str_starts_with($relativePath, '/')
            ? $relativePath
            : base_path($relativePath);

        $this->assertFileExists($path);

        $raw = (string) file_get_contents($path);

        $stripped = preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
        $stripped = preg_replace('#/\*.*?\*/#s', '', (string) $stripped);
        $stripped = preg_replace('#^\s*//.*$#m', '', (string) $stripped);

        /*
         * A stripper that eats the whole file turns every `assertDoesNotContain` into a free
         * pass, which is the failure mode of the fix rather than of the defect.
         */
        $this->assertNotSame('', trim((string) $stripped), $relativePath . ': stripping removed the whole file');

        return (string) $stripped;
    }
}
