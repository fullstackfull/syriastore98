<?php

namespace Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * `MetricCaveat::FLOAT_PRECISION`'s BASIS IS REFUTED, AND THAT FINDING MUST NOT DRIFT BACK.
 * =================================================================================================
 *
 * WHAT WAS MEASURED, on MariaDB 10.11.14 on 2026-11-26, by
 * `tests/Support/Security/bin/verify-money-column-precision-on-mysql.php` (18 checks, 0 failed):
 * `orders.final_price` and `order_coupon_details.coupon_discount_amount` are declared
 * `$table->float()`, Laravel's `float()` emits a MySQL **`double`** (`DATA_TYPE=double`,
 * `NUMERIC_PRECISION=22`), every money value from 0.01 to 2,500,000.49 round-trips exactly, and a
 * 10,000-row accumulation -- in SQL and in PHP -- differs from the exact decimal total by
 * 0.0000111163 on 253,136,425.00. Four orders of magnitude below the "smallest units" the caveat's
 * own message names.
 *
 * WHY A UNIT TEST AS WELL AS A SCRIPT. The script needs a MySQL server and is therefore NOT in the
 * suite -- CLAUDE.md's rule, because a conditionally-skipped test would break the declared outcome
 * set. So the suite can hold the FINDING but not the measurement. What this file pins is the thing
 * that silently rots: the sentence in `MetricCaveat` that records the measurement, and the
 * prohibition on attaching the caveat to a NEW figure on the reasoning the measurement refuted.
 *
 * CLAUDE.md records this exact withdrawal having happened once already, on `plan_orders.price`
 * (`GAP-192`), and its schema section states the rule in terms: "money off such a column is NOT
 * `MetricCaveat::FLOAT_PRECISION`'s basis; a caveat was attached and withdrawn on exactly that."
 * The caveat was nonetheless propagated to more surfaces afterwards, which is why the finding is
 * now gated rather than written down.
 *
 * THIS FILE DOES NOT ASSERT THE CAVEAT IS GONE. It is still attached at four sites, deliberately:
 * the production column type is unknown (`SHOW CREATE TABLE` on production is the only thing that
 * settles it) and removing a monetary disclosure from eleven merchant-facing surfaces is the
 * owner's call, not engineering's. What the file refuses is a FIFTH site, and a `MetricCaveat`
 * that has quietly gone back to calling the column a float.
 */
final class FloatPrecisionBasisIsRecordedTest extends TestCase
{
    use StripsSourceComments;

    private const VOCABULARY = 'app/Support/Metrics/MetricCaveat.php';

    private const SCRIPT = 'tests/Support/Security/bin/verify-money-column-precision-on-mysql.php';

    /**
     * THE FOUR SITES THAT ATTACH IT, AND NOTHING MAY JOIN THEM.
     *
     * Each is a `MetricCaveat::FLOAT_PRECISION` or `MetricCaveat::MONEY_FLOAT_PRECISION` reference
     * in a position that ATTACHES the caveat to a figure -- not a docblock mentioning it, which the
     * comment stripper removes before counting. `StoreInventoryValuation` is in the list and its
     * own docblock already says its columns are `double`, which is the same unsound step taken
     * knowingly; it is counted rather than excused.
     *
     * @var array<string, int>
     */
    private const ATTACHED = [
        'app/Support/Metrics/GrossMerchandiseValue.php' => 1,
        'app/Support/Metrics/CouponRedemption.php' => 1,
        'app/Support/Reports/ReportFigureNote.php' => 3,
        'app/Support/Metrics/StoreInventoryValuation.php' => 1,
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    #[Test]
    public function test_the_premise_that_the_measurement_script_is_committed(): void
    {
        $path = self::root() . '/' . self::SCRIPT;

        $this->assertFileExists($path,
            'The real-MySQL measurement that refuted this caveat\'s basis is not committed, so the '
            . 'finding below rests on a sentence rather than on something anybody can re-run.');

        $script = (string) file_get_contents($path);

        /* The four questions it has to ask, in its own words, so a rewrite that drops one is
           visible. The accumulation question is the one the caveat should have asked and did not. */
        foreach ([
            'information_schema',
            'round-trip',
            'ACCUMULATED',
            'DECIMAL(15,2)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $script, sprintf(
                'The measurement script no longer asks about "%s". All four questions are load '
                . 'bearing: the TYPE, a single value, an accumulated SUM, and the ledger comparison '
                . 'the caveat tells a merchant to make.', $needle
            ));
        }

        /* AND IT REFUSES A SERVER THAT IS NOT THROWAWAY, like every sibling. */
        $this->assertStringContainsString('verify', $script);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS', $script);
    }

    /**
     * THE VOCABULARY RECORDS THE MEASUREMENT, AND NO LONGER CALLS THE COLUMN A FLOAT.
     */
    #[Test]
    public function test_the_vocabulary_records_the_refutation_rather_than_the_refuted_reasoning(): void
    {
        $doc = (string) file_get_contents(self::root() . '/' . self::VOCABULARY);

        foreach ([
            'REFUTED',
            'emits a MySQL `double`',
            'NUMERIC_PRECISION=22',
            '0.0000111163',
            'verify-money-column-precision-on-mysql.php',
            'SHOW CREATE TABLE',
        ] as $needle) {
            $this->assertStringContainsString($needle, $doc, sprintf(
                'MetricCaveat no longer records "%s". The measurement, the figure it produced, the '
                . 'script that produced it and the production question it cannot answer are the '
                . 'four things that stop this caveat being re-justified from first principles by '
                . 'the next reader.', $needle
            ));
        }

        /* THE REFUTED SENTENCE ITSELF MUST NOT COME BACK as a live claim. It is quoted inside the
           docblock -- deliberately, so a reader sees what was wrong -- so the assertion is about
           the TWO constants' message strings, which are what a merchant reads, and about the
           absence of the claim outside a comment. */
        $code = $this->sourceWithoutComments(self::VOCABULARY);

        $this->assertStringNotContainsString('which is FLOAT', $code,
            'the refuted reasoning is back in the code rather than in the record of it');
    }

    /**
     * EXACTLY FOUR SITES ATTACH IT, AND A FIFTH FAILS THIS TEST.
     *
     * Comments stripped first: `MetricCaveat::FLOAT_PRECISION` appears in a dozen docblocks that
     * merely DISCUSS it, and counting those would make the ratchet satisfiable by prose -- CLAUDE.md
     * §3 records eight occasions a census was satisfied or broken that way.
     */
    #[Test]
    public function test_no_new_figure_attaches_the_caveat_on_the_refuted_reasoning(): void
    {
        $measured = [];

        foreach (['app', 'resources/views'] as $directory) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walk as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(self::root()) + 1);

                if ($relative === self::VOCABULARY) {
                    continue;
                }

                $raw = (string) file_get_contents($file->getPathname());

                if (! str_contains($raw, 'FLOAT_PRECISION')) {
                    continue;
                }

                $count = preg_match_all(
                    '/MetricCaveat::(?:MONEY_)?FLOAT_PRECISION/',
                    $this->sourceWithoutComments($relative),
                    $hits
                );

                if ($count > 0) {
                    $measured[$relative] = $count;
                }
            }
        }

        ksort($measured);
        $declared = self::ATTACHED;
        ksort($declared);

        $this->assertSame($declared, $measured,
            "The set of figures attaching MetricCaveat::FLOAT_PRECISION has moved.\n\n"
            . "Its stated basis is REFUTED on a fresh schema from this repository's own migrations: "
            . "`\$table->float()` emits a MySQL `double`, every money value round-trips exactly, and "
            . "a 10,000-row accumulation differs from the exact decimal total by 0.0000111163 on "
            . "253,136,425.00. So a NEW attachment needs a NEW basis -- a column measured to be a "
            . "real `float`, or an accumulation measured to diverge at a scale a merchant could act "
            . "on wrongly. Attaching it because a migration says `float()` is the step the "
            . "measurement refuted, and CLAUDE.md records it having cost a caveat once already "
            . "(GAP-192).\n\n"
            . "If a site was REMOVED, that is the withdrawal -- which is the owner's call and needs "
            . "the production column type (`SHOW CREATE TABLE orders`). Update this list in the "
            . "same change that records the decision.\n"
        );
    }
}
