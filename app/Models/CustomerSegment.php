<?php

namespace App\Models;

use App\Support\Segments\SegmentDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * MKT-004 — a named set of one store's customers, static or dynamic.
 *
 * NOT `Cachable`, and that is deliberate rather than an omission. 79 of the 95 models in
 * `app/Models/` carry the trait, and CLAUDE.md records three measured false readings caused by it:
 * a raw write leaves Eloquent reads, route-model binding included, serving the pre-write row, and
 * under `CACHE_STORE=array` that stale row lives for the rest of a test. A segment's membership is
 * edited row by row and then counted immediately on the same screen, so a cached count would be
 * wrong at exactly the moment the merchant is looking at it. The sixteen uncached models include
 * `SavedView` for the same reason.
 *
 * NOT IN `config/tenancy.php`'s `scoped_models` EITHER, so no global scope filters this and
 * `forStore()` is the only control. That is the same posture as `AnalyticsReportSchedule`: a scope
 * that adds no predicate when no tenant resolves (`null_tenant => 'open'`) is not a tenant check,
 * and a console command resolves no tenant at all.
 */
class CustomerSegment extends Model
{
    public const KIND_STATIC = 'static';

    public const KIND_DYNAMIC = 'dynamic';

    protected $table = 'customer_segments';

    /**
     * `name`, `kind` and `definition` ONLY.
     *
     * `store_id` and `created_by` are set as properties by the controller, never filled: a fillable
     * `store_id` is one mass-assignment away from a merchant placing a segment in another tenant,
     * which CLAUDE.md names as this codebase's most productive defect class.
     */
    protected $fillable = ['name', 'kind', 'definition'];

    public static function kinds(): array
    {
        return [
            self::KIND_STATIC => 'A list you curate',
            self::KIND_DYNAMIC => 'Everyone who matches a rule',
        ];
    }

    /** Every read goes through here, so the tenant predicate cannot be forgotten at a call site. */
    public static function forStore(int $storeId): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()->where('customer_segments.store_id', $storeId);
    }

    public function isDynamic(): bool
    {
        return $this->kind === self::KIND_DYNAMIC;
    }

    /** The stored rule, re-sanitised against the vocabulary the code currently knows. */
    public function definition(): SegmentDefinition
    {
        return SegmentDefinition::fromStored($this->definition);
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerSegmentMember::class, 'segment_id');
    }

    /**
     * The customers in this segment, whichever kind it is.
     *
     * ONE METHOD FOR BOTH KINDS, which is the point of the single table: whatever later counts a
     * segment, lists it or sends to it asks this and does not branch. A static segment is its rows;
     * a dynamic one is its rule evaluated now.
     *
     * WHICH PREDICATE IS THE CONTROL, stated precisely because an earlier version of this comment
     * overstated it and a mutation run proved the overstatement. The predicate on
     * `customers.store_id` is what stops a membership row resolving to another tenant's customer --
     * ids are reused across tenants in a shared database, so a row naming a foreign id (a restored
     * dump, a hand-written row) finds no customer HERE. Dropping it is caught by
     * `CustomerSegmentDefinitionTest::test_a_membership_row_naming_another_stores_customer_does_not_resolve`.
     *
     * The predicate on `customer_segment_members.store_id` is NOT a second independent control for
     * that case -- measured: removing it changes no answer, because the outer predicate has already
     * excluded the foreign customer. It earns its place for two smaller reasons: it keeps the
     * `csegm_store_customer` index usable, and it excludes a mis-stamped row whose `store_id`
     * disagrees with its segment's. Calling it a tenancy control would be the kind of claim that
     * makes the next reader trust the wrong line.
     */
    public function customers(): \Illuminate\Database\Eloquent\Builder
    {
        $storeId = (int) $this->store_id;

        /*
         * `disableCache()`, AND IT IS LOAD-BEARING. MEASURED 2026-11-21, in this feature's own test:
         * two dynamic segments differing ONLY in a numeric bound (`min_orders >= 2` then
         * `min_orders >= 1`) returned THE SAME ROWS -- the second answered with the first's result,
         * and `(new Customer)->flushCache()` between them changed the answer. So the collision is
         * the model cache and not the predicate.
         *
         * WHY THE KEY CANNOT SEE THE DIFFERENCE. `Customer` is one of the 79 `Cachable` models.
         * `CacheKey::getBindingsSlug()` reads `$this->model->query()->getBindings()` -- a FRESH
         * query on the model, not this builder's -- so a value bound into an `Expression` column
         * contributes nothing to the key, and the count subquery this class builds IS an Expression
         * column. Ordinary wheres are keyed by `getWhereClauses()` and do not collide; a comparison
         * against a subquery does.
         *
         * WHAT IT WOULD HAVE DONE IN PRODUCTION. `CACHE_STORE=database` there, so the stale answer
         * outlives the request: a merchant opening one segment and then another would read the
         * FIRST segment's members under the second's name, until some unrelated `Customer` write
         * flushed the tag. CLAUDE.md records three measured false readings from this trait; this is
         * a fourth, and the only honest fix is not to cache a query whose correctness depends on a
         * part of itself the key cannot see.
         */
        $query = Customer::disableCache()->where('customers.store_id', $storeId);

        if ($this->isDynamic()) {
            return $this->definition()->applyTo($query, $storeId);
        }

        return $query->whereExists(function ($sub) use ($storeId): void {
            $sub->selectRaw('1')
                ->from('customer_segment_members')
                ->whereColumn('customer_segment_members.customer_id', 'customers.id')
                ->where('customer_segment_members.segment_id', $this->id)
                ->where('customer_segment_members.store_id', $storeId);
        });
    }
}
