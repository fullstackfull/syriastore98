<?php

namespace Tests\Feature\Support;

use App\Models\SupportConversion;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A `$fillable` KEY WITH NO COLUMN BEHIND IT IS A SILENT WRITE FAILURE, AND THE SUPPORT DESK HAS ONE.
 * =================================================================================================
 *
 * `App\Models\SupportTicket::$fillable` lists **`answer`**. Neither
 * `create_support_tickets_table` nor any later migration declares such a column, and no handler in
 * the application reads or writes it (`grep -rn "'answer'" app/` finds the `$fillable` entry and four
 * unrelated local variables in the payment verifiers).
 *
 * WHY THAT MATTERS RATHER THAN BEING TIDY. `answer` is the obvious column name for "the merchant's
 * reply to a ticket", which is exactly the next thing anyone implementing SUPPORT-001 would write;
 * `$fillable` is where they would look to confirm it exists. Mass-assigning it writes NOTHING -- no
 * exception on MySQL and none on SQLite, because `Model::fill()` only consults `$fillable` and then
 * hands the attribute to the query builder, which... would in fact raise "no such column" on an
 * INSERT. So the failure mode depends on the path: an `update()` of only that attribute is a no-op
 * with no error, and a `create()` including it throws a database error naming a column the model
 * SAYS is fillable. Either way the model is lying about its own table.
 *
 * ASSERTED FOR BOTH MODELS AND IN ONE DIRECTION ONLY. This says every declared fillable key is a real
 * column; it deliberately does NOT require every column to be fillable, because `id`, the timestamps
 * and (on these two models) `created_by` are written explicitly on purpose.
 */
class SupportModelFillableMatchesSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: class-string<Model>}> */
    public static function supportModels(): array
    {
        return [
            'SupportTicket' => [SupportTicket::class],
            'SupportConversion' => [SupportConversion::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportModels')]
    public function test_every_fillable_key_is_a_real_column(string $model): void
    {
        /** @var Model $instance */
        $instance = new $model;
        $table = $instance->getTable();

        $this->assertTrue(Schema::hasTable($table),
            "PREMISE: {$table} does not exist on the test schema, so this case cannot see a "
            . 'missing column.');

        $fillable = $instance->getFillable();

        $this->assertNotEmpty($fillable,
            "PREMISE: {$model} declares no fillable keys, so the comparison below is vacuous.");

        $missing = array_values(array_filter(
            $fillable,
            fn (string $key) => ! Schema::hasColumn($table, $key)
        ));

        $this->assertSame([], $missing,
            "{$model} declares these as mass-assignable and {$table} has no such column, so a "
            . 'handler that trusts $fillable writes nothing or errors: ' . implode(', ', $missing));
    }
}
