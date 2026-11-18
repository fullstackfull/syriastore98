<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * The Eloquent builder of an append-only model: it refuses the mass statements BY NAME, with a message that says the table
 * is append-only. The model events of such a model guard the INSTANCE path (`$event->save()`, `$event->delete()`); a mass
 * statement such as `Model::where(...)->update([...])` never loads an instance and so never fires them. This closes the
 * ones listed here.
 *
 * IT IS NOT THE WHOLE GUARANTEE, and an earlier version of this comment said it was. A list of method names cannot be
 * exhaustive (`touch()`, `updateOrInsert()`, `incrementEach()` and `decrementEach()` were missing), so the model also
 * returns {@see AppendOnlyQueryBuilder}, which refuses at the statement level where all of them end. This class remains for
 * the clearer message. Neither is a defence against raw SQL.
 */
class AppendOnlyEloquentBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('This table is append-only: update() is not available.');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw new LogicException('This table is append-only: upsert() is not available.');
    }

    public function delete()
    {
        throw new LogicException('This table is append-only: delete() is not available.');
    }

    public function forceDelete()
    {
        throw new LogicException('This table is append-only: forceDelete() is not available.');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This table is append-only: increment() is not available.');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw new LogicException('This table is append-only: decrement() is not available.');
    }

    public function truncate()
    {
        throw new LogicException('This table is append-only: truncate() is not available.');
    }
}
