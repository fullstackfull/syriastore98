<?php

namespace Tests\Feature\Security;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\DeliveryBoy;
use App\Models\Faq;
use App\Models\FlashSale;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Role;
use App\Models\Shipping;
use App\Models\Store;
use App\Models\Tag;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Security\DetectsActiveMarkup;
use Tests\Support\Security\MinimalRows;
use Tests\Support\Security\ReadsDataTableCells;

/**
 * P1-SEC-80 (the same-tenant half) -- a name or a title a STAFF MEMBER or the OWNER typed, printed raw on the owner's lists.
 * ==========================================================================================================================
 *
 * PanelListStoredXssTest holds the lists where the attacker is somebody ELSE (a shopper, a visitor, a merchant against the operator). These are the lists where the attacker is a member
 * of the same tenant: a staff member who may create a tag, a blog category or a role -- and not manage settings -- writes a name, and the owner's list printed it as markup in the owner's
 * session. That is a privilege escalation inside the tenant (a small one, and a real one), and for the owner's own text a self-inflicted one. The fix is the same and costs nothing: the
 * column is plain text, so it is not a raw column and yajra escapes it.
 *
 * One entry per list. Each builds the rows that list reads with the payload in EVERY text column the list prints raw, through the real route, as a user who holds the list's own
 * permission. The oracle is the DOM (DetectsActiveMarkup), and the premise (a row is visible and the payload is in the cells, in some form) is checked first.
 */
class TenantListCellEscapingTest extends SecurityTestCase
{
    use DetectsActiveMarkup;
    use MinimalRows;
    use ReadsDataTableCells;
    use RefreshDatabase;

    private const PAYLOAD = '<img src=x onerror=XSS-1>';

    /**
     * @return array<string, array{0:string, 1:string, 2:\Closure}>  list => [path, permission, builder(test, store, owner)]
     */
    public static function lists(): array
    {
        $p = self::PAYLOAD;

        return [
            'blog categories' => ['/blog-category', 'Manage Blog Category',
                fn (self $t, Store $s) => $t->minimalRow(BlogCategory::class, ['store_id' => $s->id, 'name' => $p])],

            'blogs' => ['/blog', 'Manage Blog',
                function (self $t, Store $s) use ($p) {
                    $category = $t->minimalRow(BlogCategory::class, ['store_id' => $s->id, 'name' => $p]);
                    $t->minimalRow(Blog::class, ['store_id' => $s->id, 'category_id' => $category->id, 'title' => $p, 'slug' => $p, 'short_description' => $p]);
                }],

            'faqs' => ['/faqs', 'Manage Faqs',
                fn (self $t, Store $s) => $t->minimalRow(Faq::class, ['store_id' => $s->id, 'topic' => $p])],

            'flash sales' => ['/flash-sale', 'Manage Flash Sale',
                fn (self $t, Store $s) => $t->minimalRow(FlashSale::class, ['store_id' => $s->id, 'created_by' => 1, 'name' => $p])],

            'pages' => ['/pages', 'Manage Page',
                fn (self $t, Store $s) => $t->minimalRow(Page::class, ['store_id' => $s->id, 'page_name' => $p, 'page_slug' => $p])],

            'tags' => ['/tag', 'Manage Tag',
                fn (self $t, Store $s) => $t->minimalRow(Tag::class, ['store_id' => $s->id, 'created_by' => 1, 'name' => $p, 'slug' => 'tag-x'])],

            'shipping classes' => ['/shipping', 'Manage Shipping Class',
                fn (self $t, Store $s) => $t->minimalRow(Shipping::class, ['store_id' => $s->id, 'name' => $p, 'description' => $p])],

            'testimonials' => ['/testimonial', 'Manage Testimonial',
                function (self $t, Store $s) use ($p) {
                    $category = $t->minimalRow(Category::class, ['store_id' => $s->id, 'name' => $p]);
                    $product = $t->minimalRow(Product::class, ['store_id' => $s->id, 'name' => $p]);
                    $t->minimalRow(Testimonial::class, ['store_id' => $s->id, 'category_id' => $category->id, 'product_id' => $product->id, 'username' => $p, 'title' => 'T', 'description' => 'D']);
                }],

            'delivery boys' => ['/deliveryboy-list', 'Manage Deliveryboy',
                fn (self $t, Store $s) => $t->minimalRow(DeliveryBoy::class, ['store_id' => $s->id, 'created_by' => $s->created_by, 'name' => $p, 'email' => $p, 'contact' => $p])],

            'roles' => ['/roles', 'Manage Role',
                fn (self $t, Store $s) => $t->minimalRow(Role::class, ['store_id' => $s->id, 'created_by' => $s->created_by, 'name' => $p, 'display_name' => 'x', 'guard_name' => 'web'])],

            'coupons' => ['/coupon', 'Manage Coupon',
                fn (self $t, Store $s) => $t->minimalRow(Coupon::class, ['store_id' => $s->id, 'coupon_name' => $p, 'coupon_code' => $p])],

            'products (category, brand and label names)' => ['/product', 'Manage Products',
                function (self $t, Store $s) use ($p) {
                    $category = $t->minimalRow(Category::class, ['store_id' => $s->id, 'name' => $p]);
                    $brand = $t->minimalRow(ProductBrand::class, ['store_id' => $s->id, 'name' => $p]);
                    $label = $t->minimalRow(ProductLabel::class, ['store_id' => $s->id, 'name' => $p]);
                    $t->minimalRow(Product::class, ['store_id' => $s->id, 'name' => 'Plain product', 'category_id' => $category->id, 'brand_id' => $brand->id, 'label_id' => $label->id, 'product_type' => null]);
                }],
        ];
    }

    #[DataProvider('lists')]
    public function test_a_list_does_not_print_what_a_member_of_the_tenant_typed_as_markup(string $path, string $permission, \Closure $build): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, $permission);

        $build($this, $store);

        $envelope = $this->listEnvelope($owner, $path);

        $this->assertGreaterThanOrEqual(1, (int) $envelope['recordsTotal'], 'premise: the owner must see the rows, or an empty list passes for the wrong reason');
        $this->assertStringContainsString('XSS-1', $this->cellsOf($envelope), 'premise: the list must print the stored string in some form (raw before the fix, escaped after it)');
        $this->assertSame([], $this->activeMarkupIn($this->cellsOf($envelope)), $path . ' printed text a member of the tenant typed as markup');
    }
}
