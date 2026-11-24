<?php

namespace App\Support\Metrics;

use InvalidArgumentException;

/**
 * THE TENANT PREDICATE, as a value a metric cannot be built without.
 *
 * `config/tenancy.php` does not list `Order` in `scoped_models`, so `StoreScope::isActiveFor()`
 * short-circuits and **no global scope adds `store_id` to an order query for any principal** --
 * not a merchant, not the operator. `null_tenant => 'open'` means an unresolved tenant adds no
 * predicate either. A money query that forgets its `store_id` therefore does not fail, and does
 * not return nothing: it silently sums every tenant on the platform and reports the figure as the
 * merchant's own.
 *
 * That is why this class exists and why **there is no `all()` constructor**. An operator who wants
 * a cross-tenant figure must enumerate the stores, which makes the breadth of the read visible at
 * the call site and in the test that drives it. The one way to get a scope spanning the platform
 * is to ask for every store id by name.
 *
 * An empty scope is a programming error, not an empty result. Laravel compiles
 * `whereIn('store_id', [])` to `0 = 1`, so an empty scope would quietly report zero -- a figure
 * indistinguishable from a store that genuinely took no orders. It throws instead.
 */
final class MetricScope
{
    /** @var int[] */
    private array $storeIds;

    /**
     * @param int[] $storeIds
     */
    private function __construct(array $storeIds)
    {
        $normalised = [];

        foreach ($storeIds as $storeId) {
            if (! is_numeric($storeId)) {
                throw new InvalidArgumentException(
                    'A metric scope takes store ids; received ' . get_debug_type($storeId) . '.'
                );
            }

            $id = (int) $storeId;

            if ($id <= 0) {
                throw new InvalidArgumentException(
                    'A metric scope takes positive store ids; received ' . $id . '.'
                );
            }

            $normalised[$id] = $id;
        }

        if ($normalised === []) {
            throw new InvalidArgumentException(
                'A metric scope must name at least one store. An empty scope would compile to '
                . '"0 = 1" and report zero, which is indistinguishable from a store that took no '
                . 'orders.'
            );
        }

        sort($normalised);

        $this->storeIds = array_values($normalised);
    }

    /** The ordinary merchant case: one tenant. */
    public static function store(int $storeId): self
    {
        return new self([$storeId]);
    }

    /**
     * The operator case: several named tenants.
     *
     * Deliberately explicit. Reading across tenants is a decision; it should look like one here
     * and in whatever authorization test covers the caller.
     *
     * @param int[] $storeIds
     */
    public static function stores(array $storeIds): self
    {
        return new self($storeIds);
    }

    /** @return int[] */
    public function storeIds(): array
    {
        return $this->storeIds;
    }

    public function isCrossTenant(): bool
    {
        return count($this->storeIds) > 1;
    }

    public function describe(): string
    {
        return 'store_id in (' . implode(', ', $this->storeIds) . ')';
    }
}
