<?php

namespace Tests\Unit\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * THE ONE MIGRATION IN THIS REPOSITORY WHOSE `down()` IS MEANT TO WORK, SO SOMETHING HAS TO RUN IT.
 * =============================================================================================
 *
 * Every GATE F migration's `down()` is a documented no-op, and CLAUDE.md records why: two drafts
 * dropped a column *because* it existed and a rollback destroyed 45 real `cost_price` values.
 * `2026_11_25_000200` departs from that convention deliberately — the columns it adds do not exist
 * in production at all, the table's own migration already drops the whole table, and a no-op would
 * leave a rollback unable to re-run `up()`.
 *
 * THAT ARGUMENT REQUIRES THE ROLLBACK TO ACTUALLY RUN, AND THE FIRST VERSION DID NOT. Dropping an
 * indexed column on SQLite — the engine the entire suite runs on — fails:
 *
 *     SQLSTATE[HY000]: General error: 1 error in index
 *     plan_purchase_intents_acknowledged_at_index after drop column: no such column: acknowledged_at
 *
 * SQLite leaves the index behind and then finds it invalid; MySQL auto-drops a single-column index,
 * so the production engine never showed it. Found by an independent review that EXECUTED the
 * method rather than reading it.
 *
 * NOTHING ELSE IN CI WOULD CATCH IT. `MigrationPortabilityTest` drives `migrate:fresh` only, and no
 * CI step runs `migrate:rollback` — so a rollback that cannot run would have sat there until
 * somebody needed it, which is the one moment it must not fail.
 *
 * Both directions, because a `down()` that runs but leaves the table unable to take `up()` again is
 * the same problem with a later symptom.
 */
class AcknowledgementMigrationRollsBackTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_25_000200_add_acknowledgement_to_plan_purchase_intents_table.php';

    private const COLUMNS = ['acknowledged_at', 'acknowledged_by', 'acknowledged_reason'];

    public function test_the_acknowledgement_columns_can_be_rolled_back_and_re_added(): void
    {
        $migration = require base_path(self::MIGRATION);

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('plan_purchase_intents', $column),
                "premise: up() has not run, so this test would pass against any down() at all ({$column})"
            );
        }

        $migration->down();

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('plan_purchase_intents', $column), "down() left {$column} behind");
        }

        /* AND UP() MUST WORK AFTERWARDS -- the whole justification for not writing a no-op. */
        $migration->up();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('plan_purchase_intents', $column), "up() could not re-add {$column} after a rollback");
        }
    }

    /** Idempotent in both directions: a half-applied state must not stop either from completing. */
    public function test_both_directions_are_idempotent(): void
    {
        $migration = require base_path(self::MIGRATION);

        $migration->up();          // already applied by RefreshDatabase
        $migration->down();
        $migration->down();        // nothing left to drop

        foreach (self::COLUMNS as $column) {
            $this->assertFalse(Schema::hasColumn('plan_purchase_intents', $column));
        }

        $migration->up();
        $migration->up();

        foreach (self::COLUMNS as $column) {
            $this->assertTrue(Schema::hasColumn('plan_purchase_intents', $column));
        }
    }
}
