<?php

namespace App\Support\Commerce;

use App\Models\ProductAttributeOption;

/**
 * THE STOREFRONT'S VARIANT SELECTORS WERE EMPTY FOR ANY PRODUCT THAT HAD EVER BEEN EDITED. P4-F119.
 * ================================================================================================
 *
 * `products.product_attribute` holds, per attribute, a `values` array — and **the two merchant
 * forms put different things in it**:
 *
 *   - CREATE posts option **IDs**. `resources/views/product/create.blade.php:861` builds each
 *     `<option class="option-item" value="' + key + '">`, so the browser submits the id.
 *   - EDIT posts **TERMS**. `resources/views/product/edit.blade.php:689` renders
 *     `<option @if(...) selected @endif>{{ $f }}</option>` with **no `value` attribute**, so the
 *     browser submits the option's visible text. This is the same F166 shape the merchant-side
 *     tests already record (`AttributeTermResolutionTenancyTest:178-180`).
 *
 * `ProductController` stores whichever arrives, verbatim. The storefront partial then resolved them
 * with `ProductAttributeOption::where('id', $p)` — an **id** lookup. So:
 *
 *     MEASURED on the real page, http://127.0.0.1:8899/damascus-textiles/product/damask-cotton-scarf,
 *     with the Cachable product cache flushed between each read:
 *
 *       values = ["1","2","3","4"] (ids)        Size -> [Select an option, S, M, L, M/L]
 *       values = ["S","M","L","M/L"] (terms)    Size -> [Select an option]
 *
 * AND AN EMPTY SELECTOR IS AN UNSELLABLE PRODUCT, not a cosmetic gap. The chain is:
 * `front-theme.js:1237` serialises `.variant_form` on change, posts it to the product-price
 * endpoint, and writes the `variant_id` it returns onto the add-to-cart button. With nothing to
 * select, `varint[...]` stays empty, no variant resolves, the button carries `variant_id` 0, and
 * `Cart::AddToCart` refuses at `app/Models/Cart.php:158-160` with
 * *"Please Select a variant in a product."* Nothing in the merchant UI says the product has stopped
 * selling.
 *
 * WHY THE FIX IS ON THE READ SIDE. Normalising the write side would mean converting terms to ids at
 * save time and rewriting what is already stored — a data change, on live catalogues, for a defect
 * whose symptom is a read. Resolving both shapes here fixes every already-broken product with no
 * migration. The write-side divergence is the root cause and is recorded as such; normalising it is
 * a separate, data-touching change.
 *
 * THE LOOKUP CARRIES AN EXPLICIT STORE PREDICATE. `ProductAttributeOption` has a `store_id` column
 * and is **not** in `config/tenancy.php`'s `scoped_models`, so nothing underneath adds one: a bare
 * `where('terms', $token)` would offer another tenant's option terms on this store's product page.
 * The predicate is the product's own `store_id`, and the attribute is pinned too, so a term can only
 * resolve inside the attribute it was configured under.
 *
 * TERM BEFORE ID, AND THAT ORDER IS DELIBERATE. A token can be ambiguous: a shoe-size option named
 * `38` is a numeric *term*, and option *id* 38 may well exist in the same store. Preferring the id
 * reading would silently relabel it; preferring the term reading only misreads the far rarer case
 * where a term is literally another option's id. `test_a_numeric_term_resolves_as_a_term` pins it.
 */
final class VariantOptionTerms
{
    /**
     * The option terms to offer for one attribute of one product, in the order they were configured.
     *
     * @param  \App\Models\Product  $product  the product being rendered; supplies the store predicate
     * @param  int|string  $attributeId
     * @param  mixed  $values  the `values` entry from `products.product_attribute` — ids or terms,
     *                         each element possibly a `|`- or `,`-joined group, which is how
     *                         `ProductController` stores them (`explode(',', implode('|', ...))`)
     * @return list<string>
     */
    public static function forAttribute($product, $attributeId, $values): array
    {
        $storeId = $product->store_id ?? null;

        if (empty($storeId) || empty($attributeId)) {
            return [];
        }

        $tokens = self::tokens($values);

        if ($tokens === []) {
            return [];
        }

        // One query for the attribute's whole vocabulary rather than one per token: the old code
        // ran a `->first()` inside two nested loops.
        $options = ProductAttributeOption::query()
            ->where('store_id', $storeId)
            ->where('attribute_id', $attributeId)
            ->get(['id', 'terms']);

        $byTerm = [];
        $byId = [];

        foreach ($options as $option) {
            if ($option->terms !== null && $option->terms !== '') {
                $byTerm[(string) $option->terms] = (string) $option->terms;
            }
            $byId[(string) $option->id] = (string) $option->terms;
        }

        $resolved = [];

        foreach ($tokens as $token) {
            // Term first — see the note above on numeric terms.
            $term = $byTerm[$token] ?? ($byId[$token] ?? null);

            if ($term === null || $term === '') {
                // A token naming no option of this attribute in this store offers nothing. Failing
                // closed is the existing behaviour and the safe one: echoing the raw token would
                // present a choice the cart cannot resolve to a variant.
                continue;
            }

            $resolved[$term] = $term;
        }

        return array_values($resolved);
    }

    /**
     * Flatten the stored shape into single tokens.
     *
     * @param  mixed  $values
     * @return list<string>
     */
    private static function tokens($values): array
    {
        if ($values === null || $values === '' || is_bool($values)) {
            return [];
        }

        $list = is_array($values) ? $values : [$values];
        $tokens = [];

        foreach ($list as $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            foreach (preg_split('/[|,]/', (string) $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }

        return $tokens;
    }
}
