<?php

namespace Tests\Feature\Ui;

use App\DataTables\TagDataTable;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE OWNER'S VISUAL REVIEW OF THE 24 PREMIUM SURFACES, 2026-11-12.
 *
 * Twelve bounded corrections were requested. This file pins the ones a test can hold: the
 * terminology, the display-only mappings, and the Action column. The dark-theme and avatar
 * corrections are pinned by their own rendered captures instead, because a stylesheet rule is
 * not evidence that a pixel changed.
 *
 * ONE FINDING SHAPES THE WHOLE FILE: SOME OF WHAT THE OWNER SAW WAS A STALE PHOTOGRAPH, NOT A
 * LIVE DEFECT. The Action column the review found empty on the Tag list renders Edit and Delete
 * today, because the dev fixture gained `Edit Tag` / `Delete Tag` after that capture was taken;
 * the merchant footer reading "2026 Laravel" is fixed in source and guarded by
 * MerchantFooterBrandTest, and only survives in captures taken before the `.env` caught up with
 * `.env.example`. So every correction below was confirmed against a RENDERED PAGE first, and
 * the two that were already correct were re-captured rather than "fixed" -- changing code to
 * repair a photograph would have been the worse outcome.
 *
 * What remained true of the Tag list after that correction is the part this file pins: the
 * column was declared unconditionally while its cells were permission-gated, so a merchant
 * holding neither permission really did get a column headed "Action" with nothing in it.
 */
class VisualAcceptanceCorrectionsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A merchant with a store, acting, so Laratrust resolves against a real principal. */
    private function actingMerchant(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->actingAs($merchant);

        return [$merchant, $store];
    }

    private function actionColumnIsDeclared(): bool
    {
        $titles = array_map(
            fn ($c): string => (string) ($c->toArray()['data'] ?? ''),
            (new TagDataTable())->getColumns()
        );

        return in_array('action', $titles, true);
    }

    /* ===================================================================================
     * ITEM 4 — the Action column
     * =================================================================================== */

    /**
     * THE DEFECT ITSELF. `tag/action.blade.php` wraps Edit in `@permission('Edit Tag')` and
     * Delete in `@permission('Delete Tag')`, so a merchant with neither gets two empty cells --
     * under a column header that was declared unconditionally.
     */
    #[Test]
    public function test_the_tag_action_column_is_not_declared_for_a_merchant_who_can_do_nothing_in_it(): void
    {
        $this->actingMerchant();

        $this->assertFalse(
            $this->actionColumnIsDeclared(),
            'The Tag list still declares an Action column for a merchant holding neither '
            . '`Edit Tag` nor `Delete Tag`. Both row controls are `@permission`-gated, so every '
            . 'cell in that column is empty for this principal -- a header over nothing, which '
            . 'is exactly what the owner\'s 2026-11-06 capture shows.'
        );
    }

    /** The other direction, which is what stops the fix from being "hide it always". */
    #[Test]
    public function test_the_tag_action_column_returns_for_a_merchant_who_can_edit(): void
    {
        [$merchant] = $this->actingMerchant();
        $this->grantPermission($merchant, 'Edit Tag');
        $this->actingAs($merchant->fresh());

        $this->assertTrue(
            $this->actionColumnIsDeclared(),
            'A merchant who holds `Edit Tag` has lost the Action column, so the Edit control '
            . 'has nowhere to render. Hiding the column must depend on the permission, not on '
            . 'the column being inconvenient.'
        );
    }

    /** Delete alone is enough: the predicate is "at least one", not "both". */
    #[Test]
    public function test_delete_alone_is_enough_to_keep_the_tag_action_column(): void
    {
        [$merchant] = $this->actingMerchant();
        $this->grantPermission($merchant, 'Delete Tag');
        $this->actingAs($merchant->fresh());

        $this->assertTrue(
            $this->actionColumnIsDeclared(),
            'A merchant who can delete a tag but not edit one lost the column that holds the '
            . 'delete control. `hasPermission()` takes an array with $requireAll = false; this '
            . 'must stay an OR.'
        );
    }

    /**
     * THE INVARIANT THAT MATTERS MORE THAN THE COLUMN. The header's predicate must be the
     * directive's own call, or the two can disagree and the empty column comes back for
     * whichever principal they answer differently about.
     */
    #[Test]
    public function test_the_header_predicate_is_the_same_call_the_cells_make(): void
    {
        $source = file_get_contents(base_path('app/DataTables/TagDataTable.php'));

        $this->assertStringContainsString(
            "app('laratrust')->hasPermission(['Edit Tag', 'Delete Tag'])",
            $source,
            'The Action column is no longer gated on `app(\'laratrust\')->hasPermission()`. '
            . '`@permission(X)` compiles to exactly that call (LaratrustServiceProvider:102), '
            . 'so anything else -- `isAbleTo()`, an operator bypass, a policy -- can disagree '
            . 'with the cells it is supposed to be describing.'
        );

        $this->assertStringContainsString(
            '@permission(\'Edit Tag\')',
            file_get_contents(base_path('resources/views/tag/action.blade.php')),
            'The Edit control lost its own permission guard. Hiding the column is a tidiness '
            . 'fix; the cell guard is the control, and it must stay.'
        );
    }

    /* ===================================================================================
     * ITEMS 2, 5, 9, 10 — Arabic terminology
     * =================================================================================== */

    /**
     * Each of these was read off a RENDERED Arabic page before it was changed. The old values
     * are named so the test says what was wrong, not merely what is expected.
     *
     * @return array<string, array{0:string, 1:string, 2:string}>
     */
    public static function corrected(): array
    {
        return [
            // key                  expected                was                 why the old one was wrong
            'Slug' => ['Slug', 'المعرّف', 'السناجب'],
            'Avatar' => ['Avatar', 'الصورة الرمزية', 'الأفتار'],
            'Rating' => ['Rating', 'التقييم', 'تصنيف'],
            'Parent Category' => ['Parent Category', 'الفئة الرئيسية', 'فئة الأبوة'],
            'Trending' => ['Trending', 'رائج', 'اتجاهات'],
            'Permissions' => ['Permissions', 'الصلاحيات', 'التراخيص'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('corrected')]
    public function test_the_arabic_terminology_is_corrected(string $key, string $expected, string $wrong): void
    {
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertArrayHasKey($key, $ar);
        $this->assertSame($expected, $ar[$key], "The Arabic for '{$key}' changed again.");
        $this->assertNotSame(
            $wrong,
            $ar[$key],
            "The Arabic for '{$key}' is back to {$wrong}, which was measured on a rendered page "
            . 'and rejected by the owner.'
        );
    }

    /**
     * THE COLLISION THAT NEARLY BROKE A DIFFERENT SCREEN, and the reason item 9 added a key
     * rather than changing one.
     *
     * `Terms` has two call sites and they do not mean the same thing:
     * `ProductAttributeDataTable` used it for a product attribute's terms (commerce values),
     * and `front_end/sections/payment_list.blade.php:61` uses it for "Terms & Conditions" on
     * the storefront checkout, where الشروط is exactly right. Translating the shared key to
     * القيم would have relabelled the legal link "Values".
     */
    #[Test]
    public function test_the_legal_terms_key_was_not_repurposed_for_attribute_values(): void
    {
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $en = json_decode(file_get_contents(base_path('resources/lang/en.json')), true);

        $this->assertSame('الشروط', $ar['Terms'], 'The legal "Terms & Conditions" wording changed.');

        $this->assertStringContainsString(
            '__(\'Terms\')',
            file_get_contents(base_path('resources/views/front_end/sections/payment_list.blade.php')),
            'The checkout consent line no longer uses the shared Terms key, so this control is '
            . 'guarding nothing.'
        );

        $this->assertSame('القيم', $ar['Attribute Terms']);
        $this->assertSame(
            'Terms',
            $en['Attribute Terms'],
            'The attribute column\'s ENGLISH header must still read exactly "Terms". The new key '
            . 'exists to fix the Arabic without touching English or the legal string.'
        );

        $this->assertStringContainsString(
            "__('Attribute Terms')",
            file_get_contents(base_path('app/DataTables/ProductAttributeDataTable.php'))
        );
    }

    /* ===================================================================================
     * ITEMS 3, 5, 6, 7 — display-only corrections
     * =================================================================================== */

    /**
     * The grid view shipped a hardcoded English ternary while `ProductDataTable:63` -- the LIST
     * view of the same data -- already went through `__()`. So the same product read "no variant"
     * in the grid and بلا متغيّرات in the table, on the same Arabic page.
     */
    #[Test]
    public function test_the_product_grid_variant_label_goes_through_the_translator(): void
    {
        $blade = file_get_contents(base_path('resources/views/product/list.blade.php'));

        $this->assertStringContainsString(
            "__('has variant') : __('no variant')",
            $blade,
            'The product grid is printing its variant label as a bare English literal again.'
        );
        $this->assertStringNotContainsString("? 'has variant' : 'no variant'", $blade);

        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertSame('بلا متغيّرات', $ar['no variant'], 'The translation the grid now reaches.');
    }

    /** The breadcrumb named a different noun from the page title, on the same screen. */
    #[Test]
    public function test_the_product_label_breadcrumb_matches_its_own_page_title(): void
    {
        $blade = file_get_contents(base_path('resources/views/product_label/index.blade.php'));

        $this->assertStringContainsString("@section('page-title', __('Product Label'))", $blade);
        $this->assertStringContainsString(
            "class=\"ss-breadcrumb__item\">{{ __('Product Label') }}</li>",
            $blade,
            'The breadcrumb is back to __(\'Label\'), which renders العلامة under a page titled '
            . 'ملصق المنتج -- two different Arabic nouns for one screen.'
        );
    }

    /**
     * DISPLAY ONLY, AND THE STORED VALUE IS THE POINT. `FlashSaleController:163` and `:418`
     * branch on `$request->discount_type == 'percentage'`, so the stored strings decide pricing.
     * The list had no `editColumn` at all and printed them raw.
     */
    #[Test]
    public function test_the_flash_sale_discount_type_is_mapped_for_display_only(): void
    {
        $source = file_get_contents(base_path('app/DataTables/FlashSaleDataTable.php'));

        $this->assertStringContainsString("->editColumn('discount_type'", $source);
        $this->assertStringContainsString("'percentage' => __('Percentage')", $source);
        $this->assertStringContainsString("'flat'       => __('Flat')", $source);
        $this->assertStringContainsString(
            'default      => (string) $flashsale->discount_type,',
            $source,
            'An unrecognised discount type must fall through to its own raw text, not to an '
            . 'empty cell -- a value added later should look untranslated, not missing.'
        );

        foreach ([base_path('resources/views/flash-sale/create.blade.php'),
            base_path('resources/views/flash-sale/edit.blade.php')] as $form) {
            $blade = file_get_contents($form);
            $this->assertStringContainsString(
                "'percentage' => __('Percentage'), 'flat' => __('Flat')",
                $blade,
                'The form option LABELS are translated; the option VALUES stay percentage/flat.'
            );
        }

        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertSame('نسبة مئوية', $ar['Percentage']);
        $this->assertSame('مبلغ ثابت', $ar['Flat']);
    }

    /** A column headed "Trending" whose cells read Yes/No renders in Arabic as a bare لا. */
    #[Test]
    public function test_the_category_trending_cell_names_the_state(): void
    {
        $source = file_get_contents(base_path('app/DataTables/CategoryDataTable.php'));

        $this->assertStringContainsString(
            "__('Trending') : __('Not Trending')",
            $source,
            'The Trending column is back to Yes/No.'
        );

        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $this->assertSame('غير رائج', $ar['Not Trending']);
    }

    /**
     * THE RATCHET THIS WORK HAD TO RESPECT. Every key added to ar.json needs its en.json twin or
     * TranslationFileIntegrityTest's orphan count rises; the ceiling was not raised for this.
     */
    #[Test]
    public function test_every_key_added_by_this_work_exists_in_both_files(): void
    {
        $ar = json_decode(file_get_contents(base_path('resources/lang/ar.json')), true);
        $en = json_decode(file_get_contents(base_path('resources/lang/en.json')), true);

        foreach (['Attribute Terms', 'Flat', 'Percentage', 'Not Trending'] as $key) {
            $this->assertArrayHasKey($key, $ar, "ar.json lost {$key}.");
            $this->assertArrayHasKey($key, $en, "{$key} is an ar.json orphan -- add it to en.json.");
        }
    }
}
