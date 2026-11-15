<?php

namespace Tests\Feature\Theme;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\ThemeCustomizeOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-9 — `updateOrder()` VALIDATES THE POSTED VALUES AGAINST THE THEME.
 * =================================================================================================
 *
 * Both values came straight from hidden inputs the client filled (`order.blade.php:54-55`) and went
 * into the row unexamined, so any authorised merchant could store an arbitrary page name and
 * arbitrary ordering content.
 *
 * THE INTERESTING CONSTRAINT IS NOT THE REFUSAL, IT IS WHAT MUST STILL BE ACCEPTED. The themes'
 * own shipped `orders` strings DO NOT MATCH their real section keys -- measured:
 *
 *     stylique/home   declared 9   sections 10   phantom [top_category, article, logos]
 *                                                missing [logo, collection, blog, service]
 *     techzonix/home  declared 9   sections  9   phantom [top_category, bestseller, logos]
 *     greentic        declares NO orderable page at all
 *
 * So validating tokens against the SECTIONS -- the obvious rule -- would make the product refuse
 * its own default ordering the first time a merchant reordered anything. The accepted set is the
 * UNION of the declared ordering and the real sections, and
 * `test_the_themes_own_shipped_ordering_is_accepted` is the test that would catch anyone
 * "tightening" it later.
 */
class ThemeOrderValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::forceCreate([
            'name' => 'Ord M', 'email' => 'ord-m@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);
        $this->store = Store::create([
            'name' => 'Ord Store', 'email' => 'ord-s@example.test', 'slug' => 'ord-store',
            'theme_id' => 'stylique', 'created_by' => $this->owner->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();

        $p = Permission::firstOrCreate(['name' => 'Manage Themes'], ['display_name' => 'Manage Themes']);
        $role = Role::forceCreate([
            'name' => 'ord-role', 'display_name' => 'Ord', 'guard_name' => 'web',
            'created_by' => $this->owner->creatorId(), 'store_id' => $this->store->id,
        ]);
        $role->givePermission($p);
        $this->owner->addRole($role);
        $this->owner->flushCache();
        $this->owner = $this->owner->fresh();
    }

    private function postOrder(array $payload)
    {
        return $this->actingAs($this->owner)
            ->post('/theme-customize/stylique/order/update', $payload);
    }

    private function stored(): ?string
    {
        return ThemeCustomizeOrder::withoutGlobalScopes()
            ->where('store_id', $this->store->id)->value('orders');
    }

    /* ======================================== what must still be accepted (the sharp case) */

    /**
     * THE THEME'S OWN DEFAULT, UNCHANGED. If this ever fails, somebody tightened the rule to the
     * section keys and the product now refuses the ordering it ships with.
     */
    #[Test]
    public function test_the_themes_own_shipped_ordering_is_accepted(): void
    {
        $pages = json_decode(file_get_contents(base_path('themes/stylique/theme_json/pages.json')), true);
        $home = collect($pages)->firstWhere('slug', 'home');

        $this->assertNotEmpty($home['orders'] ?? null,
            'PREMISE: stylique/home declares an ordering; without one this proves nothing.');

        $this->postOrder(['page_slug' => 'home', 'orders' => $home['orders']]);

        $this->assertSame($home['orders'], $this->stored(),
            'The theme\'s own shipped ordering was refused. Three of its nine tokens name no '
            . 'section at all, so a rule checking tokens against sections rejects the product\'s '
            . 'own default.');
    }

    /** A real section absent from the shipped list is still a legitimate value. */
    #[Test]
    public function test_a_real_section_missing_from_the_shipped_list_is_accepted(): void
    {
        $this->postOrder(['page_slug' => 'home', 'orders' => 'blog,service,slider']);

        $this->assertSame('blog,service,slider', $this->stored(),
            'A real section of stylique/home was refused because the shipped `orders` string does '
            . 'not mention it. Four of its ten sections are in that position.');
    }

    /* ================================================================== what must be refused */

    #[Test]
    public function test_an_invented_token_is_refused(): void
    {
        $this->postOrder(['page_slug' => 'home', 'orders' => 'slider,not_a_real_section']);

        $this->assertNull($this->stored(),
            'A token naming nothing the theme knows was stored.');
    }

    #[Test]
    public function test_an_unknown_page_is_refused(): void
    {
        $this->postOrder(['page_slug' => 'no-such-page', 'orders' => 'slider']);

        $this->assertNull($this->stored(), 'An ordering was stored for a page the theme has not.');
    }

    /**
     * A page the theme declares but does NOT mark orderable. Ordering applies to orderable pages
     * only, and `footer` is a real page that carries no `is_order`.
     */
    #[Test]
    public function test_a_page_that_is_not_orderable_is_refused(): void
    {
        $this->postOrder(['page_slug' => 'footer', 'orders' => 'footer']);

        $this->assertNull($this->stored(),
            'An ordering was stored for a page the theme does not mark `is_order`.');
    }

    #[Test]
    public function test_an_empty_ordering_is_refused(): void
    {
        $this->postOrder(['page_slug' => 'home', 'orders' => '']);

        $this->assertNull($this->stored(), 'An empty ordering was stored.');
    }

    /** Reordering is a permutation, so a repeated token is a client bug, not an intent. */
    #[Test]
    public function test_duplicate_tokens_collapse(): void
    {
        $this->postOrder(['page_slug' => 'home', 'orders' => 'slider,slider,category']);

        $this->assertSame('slider,category', $this->stored(),
            'A duplicated token was stored twice, which would render a section twice.');
    }
}
