<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * A builder that only ever reads and inserts. The model events of an append-only model guard the
 * INSTANCE path (`$event->save()`, `$event->delete()`); a mass statement such as
 * `Model::where(...)->update([...])` never loads an instance and so never fires them. This closes
 * that path. It is a guard against accidents and against well-meaning code, not against raw SQL.
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
