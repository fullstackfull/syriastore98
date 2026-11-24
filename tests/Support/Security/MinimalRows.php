<?php

namespace Tests\Support\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A row with only what the schema insists on, so a test names the columns it is ABOUT and nothing else.  P1-SEC-80.
 *
 * Why: the lists under test read rows of a dozen models, and each model's table has columns that are NOT NULL with no default. A fixture that spells them all out buries the one
 * column the test is about (the one that carries the payload). The values are filler of the right type; the caller's `$overrides` win. It writes through Eloquent, not DB::table():
 * most of these models are `Cachable`, and a raw write leaves Eloquent reads serving the row as it was before (CLAUDE.md, "Model caching").
 */
trait MinimalRows
{
    /**
     * @template T of Model
     * @param  class-string<T>      $modelClass
     * @param  array<string,mixed>  $overrides
     * @return T
     */
    private function minimalRow(string $modelClass, array $overrides = []): Model
    {
        $table = (new $modelClass())->getTable();
        $values = [];

        foreach (Schema::getColumns($table) as $column) {
            $name = $column['name'];

            if (array_key_exists($name, $overrides) || $column['auto_increment'] || $column['nullable'] || $column['default'] !== null) {
                continue;
            }

            $type = strtolower((string) $column['type_name']);
            $values[$name] = match (true) {
                in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'boolean', 'bool', 'decimal', 'numeric', 'float', 'double', 'real'], true) => 1,
                $type === 'date' => now()->toDateString(),
                in_array($type, ['datetime', 'timestamp'], true) => now()->toDateTimeString(),
                $type === 'json' => '[]',
                default => 'fixture',
            };
        }

        return $modelClass::unguarded(static fn () => $modelClass::create($values + $overrides));
    }
}
