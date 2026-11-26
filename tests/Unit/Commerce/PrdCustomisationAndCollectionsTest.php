<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * `PRD-003`, `PRD-004`, `PRD-006` -- PRODUCT CUSTOMISATION AND COLLECTIONS, MEASURED PIECE BY PIECE.
 * ==================================================================================================
 *
 * Three approved decisions, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     PRD-003  متغيرات وخيارات وتخصيصات نصية وملفات وألوان.
 *              Variants, options, and text/file/colour customisations
 *
 *     PRD-004  حفظ خيارات التخصيص والسعر في سجل الطلب.
 *              Store the customisation choice and its price in the order record
 *
 *     PRD-006  مجموعات منتجات يدوية وذكية وربطها بالثيمات.
 *              Manual and smart product collections, linked to themes
 *
 * None of the three appears in `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` or
 * `MASTER-PLAN-GAP-REGISTER.md`. Each names several distinct things, and the honest answer differs
 * per thing -- so a single status label for any of them would be wrong whichever label was chosen.
 * This file pins the measured state of each named piece so the gaps are exact and cannot drift.
 *
 * -------------------------------------------------------------------------------------------------
 * `PRD-003`, PIECE BY PIECE.
 *
 *   VARIANTS   BUILT. `product_variants` (variant, stock, price, variation_option) plus
 *              `products.variant_product`.
 *
 *   OPTIONS    BUILT AS A VOCABULARY, and they carry NO PRICE. `product_attributes` holds
 *              name/slug; `product_attribute_options` holds `terms` and a sort `order` and nothing
 *              else. So an option can be offered but cannot cost anything, which is the half
 *              `PRD-004` then asks to be stored in the order.
 *
 *   TEXT       ORDER-LEVEL ONLY, and not per product. The vendored `AdditionalFieldInCheckout`
 *              offers `text`, `textarea`, `number` and `select` at checkout and stores the answers
 *              in `checkout_additional_fields(order_id, field_name, value)`. There is no product
 *              link and no price.
 *
 *   FILE       ORDER-LEVEL ONLY. `CheckoutAttachment` stores
 *              `checkout_attachments(product_order_id, attachment)` -- ONE attachment per order,
 *              with no product or variant column, so it cannot be a per-product customisation.
 *
 *   COLOUR     ABSENT. Neither field-type vocabulary offers a colour input: the product-field
 *              module offers text/textarea/date/select/radio/checkbox and the checkout module
 *              offers text/textarea/number/select.
 *
 * ONE SURFACE LOOKS LIKE THE ANSWER AND IS NOT, which is why it is named here. The vendored
 * `AdditionalCustomFields` module has a `type` column and an `options` JSON column and looks like a
 * shopper customisation system. It is not: its storefront view renders the values as a read-only
 * `<dt>/<dd>` list under the heading **"Product Specifications" / "Detailed technical specifications
 * and features"**, and the value in `product_additional_custom_fields.value` is authored by the
 * MERCHANT per product. It is a spec sheet. Reading its `text` type as `PRD-003`'s "تخصيصات نصية"
 * would report a customisation system that no shopper can type into.
 *
 * -------------------------------------------------------------------------------------------------
 * `PRD-004`: THE CHOICE IS PARTLY STORED. THE PRICE IS NOT STORED AT ALL.
 *
 *   CHOICE  PARTIAL. `checkout_additional_fields` persists the order-level answers against
 *           `order_id`, and `carts.size_data` (the `SizeGuideline` module's size selection) rides
 *           the cart line into `orders.product_json`.
 *
 *   PRICE   ABSENT, measured in both schema and code. **Not one** of the seven customisation and
 *           option tables carries a price, amount, surcharge or cost column, and `app/` contains no
 *           customisation-price concept under any spelling. So a customisation cannot cost anything
 *           anywhere in the platform, and `PRD-004`'s second half has no implementation to verify.
 *
 * -------------------------------------------------------------------------------------------------
 * `PRD-006`: NO COLLECTION ENTITY EXISTS.
 *
 * There is no `collections` or `product_collections` table. What exists is taxonomy and one
 * rule engine that is not reusable:
 *
 *   - `categories` -- and `products.category_id` is SINGLE-VALUED, so a product belongs to one
 *     category. That is a tree, not a curated list a product can be in several of.
 *   - `tags`, `product_brands` -- more taxonomy.
 *   - `flash_sale_conditions` -- a genuine RULE-BASED product selector (its `condition` column
 *     documents Shop / Product / Category / Product price), which is what `PRD-006` means by
 *     "ذكية". But it is bound to `flashsale_id`, so the rules can only select products for a flash
 *     SALE. They cannot define a collection.
 *   - `theme_customizes(store_id, theme_id, name, value)` -- a key/value store, so there is nothing
 *     for `PRD-006`'s "ربطها بالثيمات" to link a collection TO.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY SEVERAL ASSERTIONS HERE PROVE AN ABSENCE, AND WHAT HAPPENS WHEN ONE IS BUILT.
 *
 * They fail. That is the design. An absence that nothing asserts is an absence the register can go
 * on describing after it has stopped being true -- and the whole reason this family needed
 * measuring is that its decisions were never tracked at all. When a colour field type, a
 * customisation price or a collections table arrives, the assertion that says "absent" fails, and
 * whoever built it has to come here, read the decision text and update its disposition. That is the
 * outcome wanted: a decision moving forward should cost somebody five minutes of bookkeeping, not
 * pass unnoticed.
 */
class PrdCustomisationAndCollectionsTest extends TestCase
{
    /** Column names that would let a customisation or an option cost money. */
    private const PRICE_LIKE = '/(^|_)(price|amount|surcharge|cost|fee|extra)(_|$)/i';

    /**
     * The seven tables that model a customisation, an option or a checkout answer.
     *
     * @var list<string>
     */
    private const CUSTOMISATION_TABLES = [
        'product_attributes',
        'product_attribute_options',
        'additional_fields',
        'checkout_additional_fields',
        'checkout_attachments',
        'additional_custom_fields',
        'product_additional_custom_fields',
    ];

    /** @return list<string> every host and vendored migration path */
    private function migrationFiles(): array
    {
        $root = dirname(__DIR__, 3);

        $files = array_merge(
            glob($root . '/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/database/migrations/*.php') ?: [],
            glob($root . '/packages/workdo/*/src/Database/Migrations/*.php') ?: []
        );

        sort($files);

        return $files;
    }

    /**
     * The column names a table is created with, or `null` when no migration creates it.
     *
     * Reads the `Schema::create('<table>'` block to its closing `});`, comments stripped, so prose
     * about a column cannot be mistaken for one.
     *
     * @return list<string>|null
     */
    private function columnsOf(string $table): ?array
    {
        foreach ($this->migrationFiles() as $file) {
            $code = php_strip_whitespace($file);

            $position = strpos($code, "Schema::create('" . $table . "'");
            if ($position === false) {
                continue;
            }

            $block = substr($code, $position);
            $end = strpos($block, '});');
            if ($end !== false) {
                $block = substr($block, 0, $end);
            }

            $columns = [];

            if (preg_match_all("/->\s*[a-zA-Z]+\(\s*'([^']+)'/", $block, $matches) > 0) {
                $columns = array_values(array_unique($matches[1]));
            }

            return $columns;
        }

        return null;
    }

    /** @return list<string> the quoted field-type literals a module's PHP offers */
    private function fieldTypeLiteralsIn(string $packageRelativePath): array
    {
        $root = dirname(__DIR__, 3) . '/packages/workdo/' . $packageRelativePath;

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $code = php_strip_whitespace($file->getPathname());

            if (preg_match_all(
                "/'(text|textarea|number|select|radio|checkbox|date|file|upload|image|color|colour)'/i",
                $code,
                $matches
            ) > 0) {
                foreach ($matches[1] as $literal) {
                    $found[strtolower($literal)] = true;
                }
            }
        }

        $types = array_keys($found);
        sort($types);

        return $types;
    }

    /** THE PREMISE: the migration set is real, and the schema reader really reads a schema. */
    public function test_the_schema_reader_works(): void
    {
        $this->assertGreaterThan(
            200,
            count($this->migrationFiles()),
            'PREMISE: host and vendored migrations together should be several hundred files.'
        );

        // A table that certainly exists, read for a column that certainly exists.
        $this->assertContains(
            'product_stock',
            (array) $this->columnsOf('products'),
            'PREMISE: the reader must be able to find a known column on a known table.'
        );

        // And a table that certainly does not.
        $this->assertNull(
            $this->columnsOf('a_table_that_does_not_exist'),
            'PREMISE: the reader must answer null rather than an empty list for a missing table.'
        );
    }

    /** `PRD-003`: variants and options exist. The BUILT half, asserted so a regression shows. */
    public function test_variants_and_options_exist(): void
    {
        $variants = $this->columnsOf('product_variants');
        $this->assertNotNull($variants, 'PRD-003 requires variants; product_variants is missing.');

        foreach (['variant', 'stock', 'price'] as $column) {
            $this->assertContains($column, $variants, "product_variants.$column");
        }

        $this->assertNotNull(
            $this->columnsOf('product_attributes'),
            'PRD-003 requires options; product_attributes is missing.'
        );

        $options = $this->columnsOf('product_attribute_options');
        $this->assertNotNull($options, 'PRD-003 requires options; the options table is missing.');
        $this->assertContains('terms', $options);
    }

    /**
     * `PRD-003`/`PRD-004`: an option carries no price.
     *
     * This is the join between the two decisions. `PRD-003` offers options; `PRD-004` asks for the
     * choice AND ITS PRICE in the order record. An option that cannot cost anything gives `PRD-004`
     * nothing to store.
     */
    public function test_an_option_cannot_carry_a_price(): void
    {
        $options = (array) $this->columnsOf('product_attribute_options');

        $priced = array_values(array_filter(
            $options,
            fn (string $column): bool => preg_match(self::PRICE_LIKE, $column) === 1
        ));

        $this->assertSame(
            [],
            $priced,
            'product_attribute_options has gained a price column. PRD-004 asks for the '
            . 'customisation price to be stored in the ORDER record, so if options can now cost '
            . "money, PRD-004's disposition must be updated and the order record checked for it: "
            . implode(', ', $priced)
        );
    }

    /**
     * `PRD-004`, THE PRICE HALF: not one customisation table can hold a price.
     *
     * The sharpest measurement in this file, and the one that makes `PRD-004` unverifiable rather
     * than merely partial.
     */
    public function test_no_customisation_table_can_hold_a_price(): void
    {
        $found = [];
        $missing = [];

        foreach (self::CUSTOMISATION_TABLES as $table) {
            $columns = $this->columnsOf($table);

            if ($columns === null) {
                $missing[] = $table;
                continue;
            }

            foreach ($columns as $column) {
                if (preg_match(self::PRICE_LIKE, $column) === 1) {
                    $found[] = "$table.$column";
                }
            }
        }

        // The declared set must be real, or the assertion below is about nothing.
        $this->assertSame(
            [],
            $missing,
            'PREMISE: a declared customisation table no longer exists, so this assertion would be '
            . 'vacuous for it: ' . implode(', ', $missing)
        );

        $this->assertSame(
            [],
            $found,
            "PRD-004 requires the customisation's PRICE to be stored in the order record. A "
            . "customisation table can now carry a price, so PRD-004 has moved from "
            . "'no implementation' to 'partially implemented' and its disposition must be "
            . 'updated: ' . implode(', ', $found)
        );
    }

    /**
     * `PRD-003`, THE COLOUR HALF: neither field-type vocabulary offers a colour input.
     */
    public function test_no_field_type_vocabulary_offers_a_colour_customisation(): void
    {
        foreach ([
            'AdditionalCustomFields/src' => 'the product-field module',
            'AdditionalFieldInCheckout/src' => 'the checkout-field module',
        ] as $package => $description) {
            $types = $this->fieldTypeLiteralsIn($package);

            $this->assertNotEmpty(
                $types,
                "PREMISE: no field-type literals were found in $description, so asserting the "
                . 'absence of a colour type would prove nothing.'
            );

            foreach (['color', 'colour'] as $colour) {
                $this->assertNotContains(
                    $colour,
                    $types,
                    "PRD-003 names colour customisations and $description now offers a '$colour' "
                    . "field type. PRD-003's disposition must be updated."
                );
            }
        }
    }

    /**
     * `PRD-003`, THE FILE HALF: the only file a shopper can attach is attached to the ORDER, not to
     * a product line.
     *
     * Asserted by the ABSENCE of a product column, which is what makes it an order attachment
     * rather than a per-product customisation.
     */
    public function test_the_shopper_attachment_is_order_level_and_not_per_product(): void
    {
        $columns = $this->columnsOf('checkout_attachments');

        $this->assertNotNull(
            $columns,
            'PREMISE: checkout_attachments is the only shopper file upload in the tree.'
        );

        $this->assertContains('product_order_id', $columns);

        foreach (['product_id', 'variant_id'] as $productColumn) {
            $this->assertNotContains(
                $productColumn,
                $columns,
                "checkout_attachments.$productColumn now exists, so a shopper file can be attached "
                . "to a product line. That is PRD-003's file customisation and its disposition "
                . 'must be updated.'
            );
        }
    }

    /**
     * `PRD-006`: there is no collection entity, manual or smart.
     */
    public function test_no_product_collection_entity_exists(): void
    {
        foreach (['collections', 'product_collections', 'smart_collections'] as $table) {
            $this->assertNull(
                $this->columnsOf($table),
                "A `$table` table now exists. PRD-006 asks for manual and smart product "
                . 'collections linked to themes; its disposition must be updated and the '
                . 'theme link checked.'
            );
        }
    }

    /**
     * `PRD-006`: the one rule-based product selector that exists is bound to a flash sale, so it
     * cannot define a reusable collection.
     *
     * Asserted rather than described, because "smart collections are missing" and "a rule engine
     * exists but is not reusable" are different dispositions and lead to different work.
     */
    public function test_the_rule_based_selector_is_bound_to_a_flash_sale(): void
    {
        $conditions = $this->columnsOf('flash_sale_conditions');

        $this->assertNotNull(
            $conditions,
            'PREMISE: flash_sale_conditions is the rule engine this assertion is about.'
        );

        $this->assertContains(
            'condition',
            $conditions,
            'The rule column PRD-006 would need is `condition`.'
        );

        $this->assertContains(
            'flashsale_id',
            $conditions,
            'PRD-006 asks for SMART collections. The only rule-based product selector in the tree '
            . 'is bound to flashsale_id, which is why it cannot define a collection. If that '
            . "binding is gone the engine may now be reusable and PRD-006's disposition changes."
        );
    }

    /**
     * `PRD-006`: a product belongs to ONE category, so the existing taxonomy cannot stand in for a
     * manual collection.
     *
     * A curated collection is many-to-many by definition -- a product can be in "Summer" and in
     * "Gifts". `products.category_id` is a single column, so the taxonomy cannot express that.
     */
    public function test_a_product_belongs_to_one_category_so_taxonomy_is_not_a_collection(): void
    {
        $products = (array) $this->columnsOf('products');

        $this->assertContains(
            'category_id',
            $products,
            'PREMISE: products.category_id is the single-valued link this assertion is about.'
        );

        $this->assertNull(
            $this->columnsOf('category_product'),
            'A `category_product` pivot now exists, so a product can be in several categories. '
            . "That is closer to PRD-006's manual collection and its disposition should be "
            . 're-read.'
        );
    }
}
