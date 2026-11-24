<?php

namespace App\Models\Concerns;

use Illuminate\Database\Query\Builder;
use LogicException;

/**
 * THE QUERY BUILDER UNDER AN APPEND-ONLY MODEL. Every UPDATE and DELETE statement Eloquent can issue ends here.
 *
 * {@see AppendOnlyEloquentBuilder} refuses the mass statements it knows by name, but it is a blocklist on the Eloquent
 * builder, and an independent review found four UPDATE paths it did not name -- `touch($column)`, `updateOrInsert()`,
 * `incrementEach()` and `decrementEach()` -- plus `toBase()`, which hands out the query builder itself. They all reach
 * the database through `Query\Builder::update()`, `updateFrom()`, `upsert()`, `delete()` or `truncate()`, so refusing
 * THOSE five closes the paths by construction instead of by enumeration: a statement added to Laravel later still has
 * to end in one of them.
 *
 * Inserts are untouched, because an append-only table is written by inserting. WHAT THIS DOES NOT COVER is the same as
 * before and is said so on the model: `DB::table()` is a different builder and a person with database access needs no
 * builder at all. That is closed by a server-side trigger or by restricting the application's grants, and that is a
 * production decision that belongs with the owner's migration authorisation.
 */
class AppendOnlyQueryBuilder extends Builder
{
    public function update(array $values)
    {
        throw $this->refuse('update');
    }

    public function updateFrom(array $values)
    {
        throw $this->refuse('updateFrom');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw $this->refuse('upsert');
    }

    public function delete($id = null)
    {
        throw $this->refuse('delete');
    }

    public function truncate()
    {
        throw $this->refuse('truncate');
    }

    private function refuse(string $statement): LogicException
    {
        return new LogicException("This table is append-only: {$statement}() is not available.");
    }
}
