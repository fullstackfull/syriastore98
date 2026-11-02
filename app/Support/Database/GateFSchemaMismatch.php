<?php

namespace App\Support\Database;

use RuntimeException;

/**
 * GATE F -- thrown when a live table's structure contradicts the measured production structure.
 *
 * THROWING IS THE SAFE OUTCOME, and that is why this is an exception rather than a log line.
 * It is raised from a migration's `up()` BEFORE anything is altered, so:
 *
 *   - nothing is created, altered, dropped or truncated;
 *   - the migration is NOT recorded in the `migrations` table, so `migrate:status` keeps telling
 *     the truth instead of claiming a money table is managed when it is not;
 *   - `migrate` stops, which is the point: the next migration in the batch does not get to build
 *     on a schema nobody has verified.
 *
 * The message names every disagreement field by field, because "incompatible" is not actionable
 * and `decimal(18,4)` where production has `decimal(15,2)` is.
 */
final class GateFSchemaMismatch extends RuntimeException
{
    /**
     * @param  list<string>  $differences
     * @param  list<string>  $notes
     */
    public function __construct(
        public readonly string $table,
        public readonly array $differences,
        public readonly array $notes = [],
    ) {
        $money = GateFProductionSchema::isMoneyTable($table);

        $lines = [];
        $lines[] = sprintf(
            'GATE F STOPPED: `%s` already exists but its structure CONTRADICTS the measured production structure.',
            $table
        );
        $lines[] = '';
        $lines[] = $money
            ? 'THIS IS ONE OF THE SIX MONEY-BEARING TABLES. An incompatible financial schema must '
                . 'never be silently accepted: the wrong decimal scale silently rounds money, and a '
                . 'missing unique key turns "one financial row per order" from a guarantee into a '
                . 'hopeful observation about today\'s rows.'
            : 'Nothing was created, altered or dropped, and this migration was NOT recorded.';
        $lines[] = '';
        $lines[] = sprintf('%d disagreement%s:', count($differences), count($differences) === 1 ? '' : 's');

        foreach ($differences as $i => $difference) {
            $lines[] = sprintf('  %2d. %s', $i + 1, $difference);
        }

        if ($notes !== []) {
            $lines[] = '';
            $lines[] = 'What the comparison did and did not cover:';
            foreach ($notes as $note) {
                $lines[] = '  - ' . $note;
            }
        }

        $lines[] = '';
        $lines[] = 'THE PRODUCTION STRUCTURE IS THE AUTHORITY, not this repository. Do NOT "fix" the '
            . 'live table to match the migration: re-measure production, correct '
            . 'docs/GATE-F-VERIFIED-SCHEMA.md against a RESTORED COPY, and let '
            . 'App\\Support\\Database\\GateFProductionSchema follow it. Altering a live money column is '
            . 'a separate, deliberate change with its own rounding consequences for rows already stored.';

        parent::__construct(implode("\n", $lines));
    }
}
