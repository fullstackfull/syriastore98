<?php

namespace Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;

/**
 * CLAUDE.md: "DO NOT RESTATE THIS FIGURE ELSEWHERE." NOTHING ASSERTED IT, AND IT DRIFTED BACK.
 * ===========================================================================================
 *
 * `6e882d2b3` removed the model-cache census figure from eleven `app/` docblocks, because a figure
 * duplicated in twelve places is eleven copies nobody re-measures -- the same rule as citing a
 * METHOD rather than a line. `tests/Unit/Tenancy/ClaudeMdCachableModelClaimTest` asserts the one
 * surviving copy in CLAUDE.md, in both directions.
 *
 * It asserts nothing about the other eleven, so on 2026-11-27 SIX `app/` files carried the retired
 * figure again: four arrived with workstream branches merged AFTER the de-stating commit, which
 * undid part of it silently, and two were missed by that commit's own census. Every one of the six
 * was wrong -- the real figure is 78 of 114 with 36 exceptions -- and the paragraph they were
 * quoting tells a reader to ASSUME CACHED and TRUST THE LIST, so a reader believing a stale list
 * skips a `flushCache()` that is needed or adds one that does nothing (CLAUDE.md records three
 * measured false readings from exactly that).
 *
 * THE CENSUS READS ONLY COMMENTS, WHICH IS THE OPPOSITE OF THIS PROJECT'S USUAL RULE AND IS
 * DELIBERATE. CLAUDE.md §3 records eight occasions where a census was satisfied by prose about the
 * thing it measures, and the remedy is normally to strip comments. Here the prose IS the subject:
 * a restated figure can only live in a comment, so the tokenizer is used to keep everything else
 * out instead.
 *
 * AND IT IS NARROWED BY SUBJECT, NOT BY SPELLING. A list of spellings of the figure would be short
 * by the one nobody thought of -- measured on this very finding: a first grep for "of the 95
 * models" found FOUR of the six, missing `79-of-95` and `95 models in`. So the rule is "a comment
 * that talks about the model-cache census must not carry a count", which no new spelling escapes.
 */
class CachableFigureIsNotRestatedTest extends TestCase
{
    /**
     * A COUNT TIED TO THE NOUN, not a count near the subject.
     *
     * Two earlier versions were wrong in opposite directions and both were measured against the
     * tree. "A count in a comment that mentions the census anywhere" flagged
     * `DeliverySyriaOrderController`'s "refused 27 of 27 untranslated pairs" and
     * `SettingOwnership`'s "39 of the 41 `settings` writers" -- two false positives out of nine.
     * Replacing that with a bare `\d{2,3}` inside a 30-character window flagged every LINE
     * NUMBER in every docblock in `app/`, hundreds of them. The count has to be bound to the word
     * it counts, which is what both retired spellings actually have in common.
     *
     * @var list<string>
     */
    /**
     * AND THE COMMENT MUST BE ABOUT THIS CENSUS, which is the other half of the narrowing.
     *
     * Shape alone was still too wide, measured: `BulkDeleteTenantGuard`'s "65 request-type keys
     * resolving to 61 model classes" and `PaymentTypeLabel`'s "49-of-52 lowercase" are real
     * censuses of their own, each asserted by its own test, and neither is this figure. The
     * conjunction -- a count bound to the noun, in a comment that names `Cachable` or
     * `app/Models/` -- lands on exactly the restatements of THIS claim.
     *
     * WHAT THIS DOES NOT CATCH, said rather than implied: a restatement that names neither, or
     * that spells the figure without a digit beside the noun ("the sixteen exceptions"). The
     * copy that anything depends on is CLAUDE.md's, and `ClaudeMdCachableModelClaimTest` measures
     * that one against the tree in both directions. This test only stops the duplication coming
     * back, which is how it got wrong by 11 files and 12 exceptions the first time.
     */
    private const SUBJECT = '/Cachable|app\/Models\//i';

    private const COUNT_SHAPES = [
        // "95 models", "16 models", "78 models", "the other 79 models" -- and, because the noun
        // follows the pair, "79 of the 95 models" and "78 of 95 models" too.
        '/\b\d{2,3}\s+models?\b/i',
        // "a departure from the 79-of-95 majority in `app/Models/`" -- the one spelling with no
        // noun after it, and the one a grep for "95 models" missed.
        '/\b\d{2,3}\s*-\s*of\s*-\s*\d{2,3}\b/i',
    ];

    /** @return array<string, list<string>> every comment in `app/`, keyed by file */
    private function commentsUnderApp(): array
    {
        $root = dirname(__DIR__, 3) . '/app';
        $found = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $comments = [];

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    $comments[] = $token[1];
                }
            }

            if ($comments !== []) {
                $found[str_replace($root . '/', 'app/', $file->getPathname())] = $comments;
            }
        }

        return $found;
    }

    /** THE PREMISE, both halves: the walk reaches the tree and the pattern really fires. */
    public function test_premise_the_census_reads_app_and_its_pattern_discriminates(): void
    {
        $comments = $this->commentsUnderApp();

        $this->assertGreaterThan(300, count($comments), 'The walk over app/ is broken.');

        $this->assertSame(
            ['95 models'],
            $this->restatementsIn(' * **79 of the 95 models in `app/Models/` are `Cachable`** (CLAUDE.md).'),
            'The detector must catch the retired sentence.'
        );

        $this->assertSame(
            ['79-of-95'],
            $this->restatementsIn(' * a departure from the 79-of-95 majority in `app/Models/`.'),
            'A first grep for "of the 95 models" missed this spelling, and so did a second for '
            . '"95 models". The detector must not.'
        );

        $this->assertSame(
            ['16 models'],
            $this->restatementsIn(' * `DeliveryEarning` is one of the 16 models that are NOT `Cachable`.'),
            'A bare count of the exceptions is the same claim and must be caught too.'
        );

        $this->assertSame(
            [],
            $this->restatementsIn(' * CLAUDE.md records that most models in `app/Models/` carry that trait.'),
            'The replacement wording must PASS, or this ratchet forbids its own remedy.'
        );

        /* The four real-world false positives three earlier versions of this detector produced,
           kept verbatim so a future widening has to get past them. Each is a census of its own
           with its own test. */
        foreach ([
            ' * refused **27 of 27** untranslated pairs, including `delivered_status` 7 and 8.',
            ' * Meanwhile 39 of the 41 `settings` writers put `created_by` into the write.',
            ' * a map of 65 request-type keys resolving to 61 model classes (counted from source).',
            ' * are 49-of-52 lowercase, `paymentList` has 15 non-lowercase names.',
        ] as $notThisCensus) {
            $this->assertSame([], $this->restatementsIn($notThisCensus),
                'Another census entirely; it must stay out of scope: ' . trim($notThisCensus));
        }
    }

    /**
     * Every count in one comment that is tied to this census, in the order found.
     *
     * @return list<string>
     */
    private function restatementsIn(string $comment): array
    {
        $found = [];

        if (preg_match(self::SUBJECT, $comment) !== 1) {
            return [];
        }

        foreach (self::COUNT_SHAPES as $shape) {
            preg_match_all($shape, $comment, $hits);

            foreach ($hits[0] as $hit) {
                $found[] = trim(preg_replace('/\s+/', ' ', $hit));
            }
        }

        return $found;
    }

    /**
     * THE RATCHET. No comment under `app/` states a count of models.
     *
     * If this fails, do NOT raise a number: there is no number here to raise. Replace the figure
     * with "most models" and a pointer to CLAUDE.md, which is the only copy anything asserts.
     */
    public function test_no_comment_under_app_restates_the_model_cache_census(): void
    {
        $offenders = [];

        foreach ($this->commentsUnderApp() as $file => $comments) {
            foreach ($comments as $comment) {
                foreach ($this->restatementsIn($comment) as $hit) {
                    $offenders[] = $file . ' -- ' . trim($hit);
                }
            }
        }

        $offenders = array_values(array_unique($offenders));

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "A comment under app/ restates the model-cache census. CLAUDE.md says DO NOT RESTATE "
            . "THIS FIGURE ELSEWHERE, and every restatement found on 2026-11-27 was already wrong "
            . "by 11 files and 12 exceptions. Say \"most models\" and point at CLAUDE.md, whose "
            . "copy `ClaudeMdCachableModelClaimTest` asserts in both directions:\n  "
            . implode("\n  ", $offenders)
        );
    }
}
