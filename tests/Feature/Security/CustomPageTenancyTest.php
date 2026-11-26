<?php

namespace Tests\Feature\Security;

use App\Models\Page;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ONE STORE'S CUSTOM PAGE RENDERED UNDER ANY OTHER STORE'S SLUG.
 *
 * `HomeController::customPage()` resolves the store from the route slug on its first line — and
 * then never uses it as a predicate:
 *
 *     $store = getStore($request->route('storeSlug'));
 *     if ($store && $request->route('page_slug')) {
 *         $page = Page::where('page_slug', $request->route('page_slug'))
 *             ->where('page_status', 1)->first();
 *
 * So `GET /store-b/page/<a-slug-belonging-to-store-a>` answered 200 and rendered store A's
 * `page_content` inside store B's theme. No authentication, no cookie, no token: the route is
 * `routes/customer.php:132`, in the open storefront group.
 *
 * NOTHING UNDERNEATH CONTAINED IT, and the reason is the one CLAUDE.md warns about twice.
 * `Page` is `use HasFactory, Cachable;` — no `BelongsToStore`, no `StoreScope` — and it appears
 * ZERO times in `config/tenancy.php`'s `scoped_models`. This is the "no scope at all" variety
 * rather than the inert-scope variety that `Product` and `Category` have, so there was no
 * predicate for any principal and nothing for `null_tenant => 'open'` to even decide about.
 *
 * ITS OWN SIBLING HAD THE CLAUSE. `article_page` reads
 * `Blog::where('slug', $blogSlug)->where('store_id', $store_id)` at `HomeController:1235`. So this
 * was a gap in one handler of a pair, not a platform design — the same shape as the review
 * deletion door and the web shipping-method read before it.
 *
 * FOUND BY THE INDEPENDENT REVIEW OF THE GAP-117 SEO CHANGE, which did not introduce it. The SEO
 * work's contribution was to classify `page.custom` as "a store's public content" and print
 * `index, follow` and a self-canonical on it — so a pre-existing leak was about to be ratified as
 * publishable. The reviewer was right to separate those: the crawler directive changes nothing on
 * its own (absence of a robots meta already means indexable), but the classification was a claim
 * about tenancy that was false.
 */
class CustomPageTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Store, 1: Store, 2: Page}
     */
    private function twoStoresAndAPage(): array
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $page = Page::create([
            'page_name' => 'Store A Private Terms',
            'page_slug' => 'store-a-terms-' . bin2hex(random_bytes(3)),
            'page_content' => 'CONFIDENTIAL-A: the body of store A custom page.',
            'page_status' => 1,
            'store_id' => $storeA->id,
        ]);

        return [$storeA, $storeB, $page];
    }

    /** Guards the assertion below: the page must really be store A's and really be reachable there. */
    #[Test]
    public function test_the_premise_the_page_belongs_to_store_a_and_renders_on_store_a(): void
    {
        [$storeA, , $page] = $this->twoStoresAndAPage();

        $this->assertSame(
            $storeA->id,
            (int) $page->fresh()->store_id,
            'premise: the fixture page must belong to store A.'
        );

        $response = $this->get('/' . $storeA->slug . '/page/' . $page->page_slug);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'premise: store A must be able to serve its OWN page, or the refusal asserted below '
            . 'would be satisfied by the page being broken for everybody.'
        );

        $this->assertStringContainsString(
            'CONFIDENTIAL-A',
            $response->getContent(),
            'premise: store A\'s own page must actually render its body here.'
        );
    }

    /**
     * THE DEFECT.
     */
    #[Test]
    public function test_another_stores_custom_page_is_not_served_under_this_stores_slug(): void
    {
        [, $storeB, $page] = $this->twoStoresAndAPage();

        $response = $this->get('/' . $storeB->slug . '/page/' . $page->page_slug);

        $this->assertStringNotContainsString(
            'CONFIDENTIAL-A',
            (string) $response->getContent(),
            'Store A\'s custom page body was rendered under store B\'s slug. `customPage()` '
            . 'resolves the store from the route and then reads `Page` without a `store_id` '
            . 'predicate; `Page` carries no scope and is not in `scoped_models`, so nothing '
            . 'underneath contains it. Its sibling `article_page` has the clause.'
        );
    }

    /**
     * AND THE SAME ACROSS A DELIBERATELY COLLIDING SLUG, because a page slug is merchant-chosen
     * free text and two stores may legitimately both have `terms` or `about-us` — which is the
     * ordinary case rather than an exotic one. Whichever row `first()` happens to return, each
     * store must get its own.
     */
    #[Test]
    public function test_two_stores_with_the_same_page_slug_each_get_their_own(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $shared = 'about-us-' . bin2hex(random_bytes(3));

        Page::create([
            'page_name' => 'About A', 'page_slug' => $shared,
            'page_content' => 'BODY-FROM-STORE-A', 'page_status' => 1, 'store_id' => $storeA->id,
        ]);
        Page::create([
            'page_name' => 'About B', 'page_slug' => $shared,
            'page_content' => 'BODY-FROM-STORE-B', 'page_status' => 1, 'store_id' => $storeB->id,
        ]);

        $a = (string) $this->get('/' . $storeA->slug . '/page/' . $shared)->getContent();
        $b = (string) $this->get('/' . $storeB->slug . '/page/' . $shared)->getContent();

        $this->assertStringContainsString('BODY-FROM-STORE-A', $a, 'store A got the wrong page.');
        $this->assertStringNotContainsString('BODY-FROM-STORE-B', $a, 'store A was served store B\'s page.');

        $this->assertStringContainsString('BODY-FROM-STORE-B', $b, 'store B got the wrong page.');
        $this->assertStringNotContainsString('BODY-FROM-STORE-A', $b, 'store B was served store A\'s page.');
    }
}
