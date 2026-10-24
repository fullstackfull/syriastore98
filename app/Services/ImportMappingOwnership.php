<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * The row a WooCommerce / Shopify mapping points at, and only if THIS store owns it.  P1-INV-13.
 *
 * The importers keep a mapping table (`woocommerce_conections`, `shopify_conections`: remote id -> local id, per store). A re-sync resolves the
 * mapping and then rewrites the local row it names. Two things went unchecked: the mapping may not exist (the handler dereferenced null and
 * crashed, or a surrounding try/catch turned it into an unrelated message), and the local row it names was never compared with the caller's
 * store -- `Product` and `Coupon` carry no global scope that applies, and `Customer`'s scope adds no predicate where no tenant resolves, so the
 * isolation was never the handler's own. The comparison is made here, on the unscoped row, so it is the handler's check that is measured.
 *
 * @template T of Model
 */
final class ImportMappingOwnership
{
    /**
     * @param  class-string<T>  $model
     * @return T|null  null when there is no mapping, no such row, or the row belongs to another store
     */
    public static function owned(?object $mapping, string $model, $storeId): ?Model
    {
        // no mapping, or no store to compare with: a NULL `store_id` on a row must never match an unresolved store ('' === '')
        if ($mapping === null || $storeId === null || $storeId === '') {
            return null;
        }

        $row = $model::withoutGlobalScopes()->find($mapping->original_id);

        return $row !== null && (string) $row->store_id === (string) $storeId ? $row : null;
    }
}
