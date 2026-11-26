<?php

namespace App\Support\Commerce;

/**
 * MASTER PLAN `INV-006` (GAP-182) — A BACKORDER / PRE-ORDER PERMISSION IS A CHOICE A MERCHANT MAKES,
 * NOT SOMETHING THAT HAPPENS WHEN NOBODY SENDS A FIELD.
 * ==================================================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:270`, section
 * 04-B (المخزون والمستودعات) — «الطلب المسبق الاختياري والصريح.» / *optional and explicit pre-order*
 * — read with its clause-mate at :268, `INV-004` «منع البيع فوق المتاح افتراضياً.» / *prevent selling
 * above available by default*. Together they say: ordering beyond available stock is permitted only
 * when the merchant has said so, and refused otherwise.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A VALUE CLASS AND NOT A VALIDATION RULE.
 *
 * The column is written from **six** sites across two controllers, three of which take their key
 * from an UNBOUNDED family — `stock_order_status_<combination>`, where the suffix is
 * `str_replace('.', '_', $variant)`, arbitrary merchant text such as `stock_order_status_S-Red`. A
 * validator cannot enumerate that family (the same argument `ShippingClassOwnership` records for
 * `shipping_id_*`), so the question is asked where the value is read, once, here:
 *
 *     ProductController::store()         parent limb, variant limb
 *     ProductController::update()        parent limb, variant limb
 *     Api\DashboardController::AddProduct()       — wrote the column NOWHERE before this
 *     Api\DashboardController::UpdateProduct()    — likewise
 *
 * -------------------------------------------------------------------------------------------------
 * THE READER THIS EXISTS FOR, QUOTED, BECAUSE IT IS WHY AN UNRECOGNISED VALUE IS NOT HARMLESS.
 *
 * `App\Services\ProductStockService::mustNotOversell()`:
 *
 *     return ($model->stock_order_status ?? null) === 'not_allow';
 *
 * One exact word refuses. **Everything else permits** — NULL, `''`, the integer `0` the variant
 * writers used to default to, a typo, a word from a future release. So a product nobody made a
 * decision about oversells silently, which is INV-004's default inverted and INV-006's "explicit"
 * absent. `App\Models\Cart` and `Api\ApiController` compare against the same literal at 27 further
 * sites.
 *
 * -------------------------------------------------------------------------------------------------
 * THE STRICT CHOICE IS NOT INVENTED HERE. IT IS THE SHIPPED FORM'S OWN DEFAULT.
 *
 * `resources/views/product/create.blade.php` renders the "Allow BackOrders:" group with
 * `value="not_allow" … checked="checked"`, so a product created by clicking already lands
 * `not_allow`. This class makes the other writers agree with the screen rather than deciding
 * anything new, and `BackorderChoiceIsExplicitTest::test_the_web_create_form_default_is_still_what_lands`
 * fails if that default ever moves, so the alignment cannot drift silently.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO — each one reserved to the owner, not an oversight.
 *
 *   1. **It does not touch rows that already exist.** Reading a stored NULL as "refuse" would change
 *      the behaviour of every product the Flutter admin app has ever created, on live data. That is
 *      a stock business-rule change on real stock, which CLAUDE.md §2 reserves to the owner. The
 *      reader is untouched; only writes from here on are made explicit.
 *   2. **It does not change the untracked limb.** `track_stock == 0` stores the literal `''` and the
 *      cart branches on `stock_status == 'out_of_stock'` instead; {@see untracked()} keeps that
 *      exact literal.
 *   3. **It does not remove the permissive answers.** `notify_customer` and `allow` are preserved
 *      verbatim. INV-006 asks for the pre-order to be OPTIONAL as well as explicit, and
 *      `ProductStockService`'s docblock warns that tightening it away "would silently remove the
 *      backorder feature, which is a merchant-facing behaviour".
 */
final class BackorderPolicy
{
    /** Refuse an order beyond available stock. The strict choice, and the create form's default. */
    public const NOT_ALLOW = 'not_allow';

    /** Permit it, and tell the shopper. */
    public const NOTIFY_CUSTOMER = 'notify_customer';

    /** Permit it silently. */
    public const ALLOW = 'allow';

    /**
     * The complete set of words a stock-tracked product's column may hold.
     *
     * These are the three radio values in `resources/views/product/create.blade.php`,
     * `edit.blade.php` and both `attribute_combinations*.blade.php` views. The order is the form's.
     *
     * @var list<string>
     */
    public const CHOICES = [self::NOT_ALLOW, self::NOTIFY_CUSTOMER, self::ALLOW];

    /**
     * The literal a product that does not track stock has always carried.
     *
     * NOT `not_allow`: that limb is governed by `stock_status`, and `ProductController` has written
     * the empty string there since the column existed. Changing it would move a surface INV-006 does
     * not speak about.
     */
    public const UNTRACKED = '';

    /**
     * What to store for a stock-TRACKED product, given whatever the request offered.
     *
     * A recognised word is kept exactly. Anything else — an absent key, `null`, `''`, the integer
     * `0`, an array, a typo — becomes the strict choice, because "nobody chose" must not read as
     * "oversell permitted".
     *
     * @param  mixed  $posted  the raw request value, un-normalised
     */
    public static function forCreate(mixed $posted): string
    {
        return self::recognised($posted) ?? self::NOT_ALLOW;
    }

    /**
     * What to store on an UPDATE, given the request and the value already on the row.
     *
     * The difference from {@see forCreate()} is the absent-key case, and it matters: a PUT that says
     * nothing about backorders must KEEP the merchant's stored decision, not replace it with a
     * default. `ProductController::update()` assigned the request value unconditionally, so an
     * omitted key wrote NULL over an explicit `not_allow` and demoted the product to oversellable —
     * a stored choice lost, which is a different defect from a missing default.
     *
     * A stored value that is itself unrecognised (a legacy NULL, or the `0` the variant writers
     * wrote) falls to the strict choice, because this is a WRITE the merchant asked for: the row is
     * being rewritten either way, so leaving it in the ambiguous state would carry the defect
     * forward. Rows nobody updates are not touched — see the class docblock, point 1.
     *
     * @param  mixed  $posted   the raw request value, un-normalised
     * @param  mixed  $current  the value currently on the row
     */
    public static function forUpdate(mixed $posted, mixed $current): string
    {
        return self::recognised($posted)
            ?? self::recognised($current)
            ?? self::NOT_ALLOW;
    }

    /** The literal for a product that does not track stock. */
    public static function untracked(): string
    {
        return self::UNTRACKED;
    }

    /**
     * The posted value if it is one of the three words, else null.
     *
     * Compared with `in_array(..., true)` against a cast string, so `0`, `false`, `[]` and an object
     * can never match: a loose comparison against a non-empty string list is exactly how an
     * unexpected type slips through.
     */
    private static function recognised(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $value = trim((string) $value);

        return in_array($value, self::CHOICES, true) ? $value : null;
    }
}
