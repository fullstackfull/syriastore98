<?php

namespace Tests\Feature\Security;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\Newsletter;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B — the seven remaining controllers of the F-SEC-01 shape, DRIVEN.
 * ================================================================================================
 *
 * F-SEC-04 recorded these as CONFIRMED BY ANALYSIS and explicitly **not yet findings**: ten
 * controllers were read independently and every claimed hole was put to two adversarial verifiers
 * with distinct lenses. Eight survived 2/2; one of those (`TestimonialController`) was fixed in
 * F-SEC-03. These are the other seven.
 *
 * THIS FILE IS WHAT TURNS THEM INTO FINDINGS. Static analysis plus two refuters is a strong reason
 * to look; this project has measured repeatedly that it is not a substitute for looking. The
 * census was wrong four times before it was believed, and three of the F-SEC-03 "failures" turned
 * out to be a fixture inventing a column.
 *
 * THE SHAPE, identical in all seven. Two binding styles, one consequence:
 *
 *     bare $id        Blog, BlogCategory, ProductQuestion, Tag   -> Model::find($id)
 *     route-bound     Contact, Faq, Newsletter                   -> Model $x
 *
 * Neither is tenant-scoped: none of these models is in `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and the global scope adds no predicate for ANY
 * principal. A Laratrust permission is checked, which says what the caller may do to a row of that
 * kind -- never WHICH row -- and then the handler writes.
 *
 * EVERY GROUP CARRIES A POSITIVE CONTROL, and it is load-bearing rather than ceremonial: a
 * controller that refused everyone would satisfy every cross-tenant assertion here. In F-SEC-03 a
 * fixture that omitted a NOT NULL column made the OWNER's own update fail, which would have made
 * three cross-tenant "passes" meaningless -- the control is what caught it.
 *
 * FIXTURE FACTS MEASURED FROM THE LIVE SCHEMA, not assumed:
 *   blogs.category_id            FK -> blog_categories(id)
 *   product_questions.customer_id FK -> customers(id), .created_by FK -> users(id),
 *                                 .product_id FK -> products(id)
 *   the rest are free-standing.
 */
class ContentFamilyCrossTenantTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Raw, because these models are Cachable and an Eloquent read can serve a pre-write row. */
    private function raw(string $table, int $id): ?object
    {
        return \DB::table($table)->where('id', $id)->first();
    }

    // ---------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------

    private function makeBlogCategory(int $storeId, string $name): BlogCategory
    {
        return BlogCategory::forceCreate([
            'name' => $name, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    private function makeBlog(int $storeId, string $title): Blog
    {
        $category = $this->makeBlogCategory($storeId, 'Host ' . uniqid());

        return Blog::forceCreate([
            'title' => $title,
            'slug' => \Illuminate\Support\Str::slug($title) . '-' . uniqid(),
            'short_description' => 'short',
            'content' => 'content',
            'category_id' => $category->id,
            'store_id' => $storeId,
        ]);
    }

    private function makeContact(int $storeId, string $first): Contact
    {
        return Contact::forceCreate([
            'first_name' => $first, 'last_name' => 'Last', 'email' => uniqid() . '@example.test',
            'contact' => '0100000000', 'subject' => 'Subject', 'description' => 'Body',
            'store_id' => $storeId,
        ]);
    }

    private function makeFaq(int $storeId, string $topic): Faq
    {
        return Faq::forceCreate([
            'topic' => $topic,
            'description' => json_encode([['question' => 'q', 'answer' => 'a']]),
            'store_id' => $storeId,
        ]);
    }

    private function makeNewsletter(int $storeId, string $email): Newsletter
    {
        return Newsletter::forceCreate(['email' => $email, 'store_id' => $storeId]);
    }

    private function makeProductQuestion(int $storeId, int $userId, string $question): ProductQuestion
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Q', 'last_name' => 'Asker',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);

        $product = Product::forceCreate([
            'name' => 'QProduct ' . uniqid(),
            'slug' => 'qp-' . uniqid(), 'description' => 'x', 'price' => 10,
            'track_stock' => 0, 'trending' => 0, 'stock_status' => 'in_stock',
            'product_stock' => 5, 'product_type' => null, 'status' => 1, 'variant_product' => 0,
            'store_id' => $storeId, 'created_by' => $userId,
        ]);

        return ProductQuestion::forceCreate([
            'question' => $question, 'answers' => null,
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'created_by' => $userId, 'store_id' => $storeId,
        ]);
    }

    private function makeTag(int $storeId, int $userId, string $name): Tag
    {
        return Tag::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'store_id' => $storeId, 'created_by' => $userId,
        ]);
    }

    // =============================================================================================
    // BLOG -- the sharpest of the seven, and the one my census UNDER-reported: `edit()` has no
    // permission check whatsoever, so any authenticated web user, staff holding zero permissions
    // included, reads any tenant's full post by id.
    // =============================================================================================

    public function test_a_merchant_cannot_read_write_or_delete_another_tenants_blog(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Blog');
        $this->grantPermission($attacker, 'Delete Blog');

        $foreign = $this->makeBlog($victimStore->id, 'VictimBlogTitle');

        $this->actingAs($attacker)->get('/blog/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/blog/' . $foreign->id, [
            'title' => 'RewrittenByAttacker', 'short_description' => 's',
            'content' => 'c', 'category_id' => $foreign->category_id,
        ]);
        $this->assertSame('VictimBlogTitle', $this->raw('blogs', $foreign->id)->title,
            "A merchant rewrote another tenant's blog post.");

        $this->actingAs($attacker)->delete('/blog/' . $foreign->id);
        $this->assertNotNull($this->raw('blogs', $foreign->id),
            "A merchant deleted another tenant's blog post.");
    }

    public function test_the_blog_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Blog');
        $this->grantPermission($owner, 'Delete Blog');

        $own = $this->makeBlog($store->id, 'OwnBlogTitle');

        $this->actingAs($owner)->get('/blog/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/blog/' . $own->id, [
            'title' => 'RenamedByOwner', 'short_description' => 's',
            'content' => 'c', 'category_id' => $own->category_id,
        ]);
        $this->assertSame('RenamedByOwner', $this->raw('blogs', $own->id)->title,
            'The owner can no longer rename their own blog post.');

        $this->actingAs($owner)->delete('/blog/' . $own->id);
        $this->assertNull($this->raw('blogs', $own->id),
            'The owner can no longer delete their own blog post.');
    }

    // =============================================================================================
    // BLOG CATEGORY
    // =============================================================================================

    public function test_a_merchant_cannot_read_write_or_delete_another_tenants_blog_category(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Blog Category');
        $this->grantPermission($attacker, 'Delete Blog Category');

        $foreign = $this->makeBlogCategory($victimStore->id, 'VictimBlogCategory');

        $this->actingAs($attacker)->get('/blog-category/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/blog-category/' . $foreign->id,
            ['name' => 'RewrittenByAttacker', 'status' => 1]);
        $this->assertSame('VictimBlogCategory', $this->raw('blog_categories', $foreign->id)->name,
            "A merchant renamed another tenant's blog category.");

        $this->actingAs($attacker)->delete('/blog-category/' . $foreign->id);
        $this->assertNotNull($this->raw('blog_categories', $foreign->id),
            "A merchant deleted another tenant's blog category.");
    }

    public function test_the_blog_category_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Blog Category');
        $this->grantPermission($owner, 'Delete Blog Category');

        $own = $this->makeBlogCategory($store->id, 'OwnBlogCategory');

        $this->actingAs($owner)->get('/blog-category/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/blog-category/' . $own->id,
            ['name' => 'RenamedByOwner', 'status' => 1]);
        $this->assertSame('RenamedByOwner', $this->raw('blog_categories', $own->id)->name,
            'The owner can no longer rename their own blog category.');

        $this->actingAs($owner)->delete('/blog-category/' . $own->id);
        $this->assertNull($this->raw('blog_categories', $own->id),
            'The owner can no longer delete their own blog category.');
    }

    // =============================================================================================
    // CONTACT -- contact-form submissions, i.e. the names, email addresses and messages of people
    // who wrote to another merchant. Personal data, not catalogue data.
    // =============================================================================================

    public function test_a_merchant_cannot_read_write_or_delete_another_tenants_contact(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Contact Us');
        $this->grantPermission($attacker, 'Delete Contact Us');

        $foreign = $this->makeContact($victimStore->id, 'VictimContactFirst');

        $this->actingAs($attacker)->get('/contacts/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/contacts/' . $foreign->id, [
            'first_name' => 'RewrittenByAttacker', 'last_name' => 'L',
            'email' => 'a@example.test', 'contact' => '1', 'subject' => 's', 'description' => 'd',
        ]);
        $this->assertSame('VictimContactFirst', $this->raw('contacts', $foreign->id)->first_name,
            "A merchant rewrote another tenant's contact-form submission.");

        $this->actingAs($attacker)->delete('/contacts/' . $foreign->id);
        $this->assertNotNull($this->raw('contacts', $foreign->id),
            "A merchant deleted another tenant's contact-form submission.");
    }

    public function test_the_contact_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Contact Us');
        $this->grantPermission($owner, 'Delete Contact Us');

        $own = $this->makeContact($store->id, 'OwnContactFirst');

        $this->actingAs($owner)->get('/contacts/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/contacts/' . $own->id, [
            'first_name' => 'RenamedByOwner', 'last_name' => 'L',
            'email' => 'o@example.test', 'contact' => '1', 'subject' => 's', 'description' => 'd',
        ]);
        $this->assertSame('RenamedByOwner', $this->raw('contacts', $own->id)->first_name,
            'The owner can no longer edit their own contact submission.');

        $this->actingAs($owner)->delete('/contacts/' . $own->id);
        $this->assertNull($this->raw('contacts', $own->id),
            'The owner can no longer delete their own contact submission.');
    }

    // =============================================================================================
    // FAQ
    // =============================================================================================

    public function test_a_merchant_cannot_read_write_or_delete_another_tenants_faq(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Faqs');
        $this->grantPermission($attacker, 'Delete Faqs');

        $foreign = $this->makeFaq($victimStore->id, 'VictimFaqTopic');

        $this->actingAs($attacker)->get('/faqs/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/faqs/' . $foreign->id, [
            'topic' => 'RewrittenByAttacker',
            'kt_docs_repeater_basic' => [['question' => 'q', 'answer' => 'a']],
        ]);
        $this->assertSame('VictimFaqTopic', $this->raw('faqs', $foreign->id)->topic,
            "A merchant rewrote another tenant's FAQ.");

        $this->actingAs($attacker)->delete('/faqs/' . $foreign->id);
        $this->assertNotNull($this->raw('faqs', $foreign->id),
            "A merchant deleted another tenant's FAQ.");
    }

    public function test_the_faq_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Faqs');
        $this->grantPermission($owner, 'Delete Faqs');

        $own = $this->makeFaq($store->id, 'OwnFaqTopic');

        $this->actingAs($owner)->get('/faqs/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/faqs/' . $own->id, [
            'topic' => 'RenamedByOwner',
            'kt_docs_repeater_basic' => [['question' => 'q', 'answer' => 'a']],
        ]);
        $this->assertSame('RenamedByOwner', $this->raw('faqs', $own->id)->topic,
            'The owner can no longer rename their own FAQ.');

        $this->actingAs($owner)->delete('/faqs/' . $own->id);
        $this->assertNull($this->raw('faqs', $own->id),
            'The owner can no longer delete their own FAQ.');
    }

    // =============================================================================================
    // NEWSLETTER -- destroy only. `update()` has no validator and writes nothing, so there is no
    // write path to drive; the census and the review both flagged only `destroy`.
    // Subscriber email addresses are personal data.
    // =============================================================================================

    public function test_a_merchant_cannot_delete_another_tenants_newsletter_subscriber(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Newsletter');

        $foreign = $this->makeNewsletter($victimStore->id, 'victim-subscriber@example.test');

        $this->actingAs($attacker)->delete('/newsletter/' . $foreign->id);

        $this->assertNotNull($this->raw('newsletters', $foreign->id),
            "A merchant deleted another tenant's newsletter subscriber.");
    }

    public function test_the_newsletter_owner_can_still_delete_their_own(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Delete Newsletter');

        $own = $this->makeNewsletter($store->id, 'own-subscriber@example.test');

        $this->actingAs($owner)->delete('/newsletter/' . $own->id);

        $this->assertNull($this->raw('newsletters', $own->id),
            'The owner can no longer delete their own newsletter subscriber.');
    }

    // =============================================================================================
    // PRODUCT QUESTION -- the reply modal has no permission check AND no tenant predicate, so the
    // answer a merchant publishes can be written onto another store's question.
    // =============================================================================================

    public function test_a_merchant_cannot_read_answer_or_delete_another_tenants_product_question(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Replay Product Question');
        $this->grantPermission($attacker, 'Delete Product Question');

        $foreign = $this->makeProductQuestion($victimStore->id, $victim->id, 'VictimQuestionText');

        $this->actingAs($attacker)->get('/product-question/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/product-question/' . $foreign->id, [
            'question' => 'VictimQuestionText', 'answers' => 'AnsweredByAttacker',
        ]);
        $this->assertNull($this->raw('product_questions', $foreign->id)->answers,
            "A merchant published an answer on another tenant's product question.");

        $this->actingAs($attacker)->delete('/product-question/' . $foreign->id);
        $this->assertNotNull($this->raw('product_questions', $foreign->id),
            "A merchant deleted another tenant's product question.");
    }

    public function test_the_product_question_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Replay Product Question');
        $this->grantPermission($owner, 'Delete Product Question');

        $own = $this->makeProductQuestion($store->id, $owner->id, 'OwnQuestionText');

        $this->actingAs($owner)->get('/product-question/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/product-question/' . $own->id, [
            'question' => 'OwnQuestionText', 'answers' => 'AnsweredByOwner',
        ]);
        $this->assertSame('AnsweredByOwner', $this->raw('product_questions', $own->id)->answers,
            'The owner can no longer answer their own product question.');

        $this->actingAs($owner)->delete('/product-question/' . $own->id);
        $this->assertNull($this->raw('product_questions', $own->id),
            'The owner can no longer delete their own product question.');
    }

    // =============================================================================================
    // TAG
    // =============================================================================================

    public function test_a_merchant_cannot_read_write_or_delete_another_tenants_tag(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Tag');
        $this->grantPermission($attacker, 'Delete Tag');

        $foreign = $this->makeTag($victimStore->id, $victim->id, 'VictimTagName');

        $this->actingAs($attacker)->get('/tag/' . $foreign->id . '/edit')
            ->assertStatus(404);

        $this->actingAs($attacker)->put('/tag/' . $foreign->id, ['name' => 'RewrittenByAttacker']);
        $this->assertSame('VictimTagName', $this->raw('tags', $foreign->id)->name,
            "A merchant renamed another tenant's tag.");

        $this->actingAs($attacker)->delete('/tag/' . $foreign->id);
        $this->assertNotNull($this->raw('tags', $foreign->id),
            "A merchant deleted another tenant's tag.");
    }

    public function test_the_tag_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Tag');
        $this->grantPermission($owner, 'Delete Tag');

        $own = $this->makeTag($store->id, $owner->id, 'OwnTagName');

        $this->actingAs($owner)->get('/tag/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/tag/' . $own->id, ['name' => 'RenamedByOwner']);
        $this->assertSame('RenamedByOwner', $this->raw('tags', $own->id)->name,
            'The owner can no longer rename their own tag.');

        $this->actingAs($owner)->delete('/tag/' . $own->id);
        $this->assertNull($this->raw('tags', $own->id),
            'The owner can no longer delete their own tag.');
    }
}
