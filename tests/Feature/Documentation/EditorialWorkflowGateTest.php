<?php

namespace Tests\Feature\Documentation;

use App\Support\Docs\DocumentationLibrary;
use App\Support\Docs\DocumentationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-107 (P03-D-04) — THE EDITORIAL WORKFLOW, DRIVEN THROUGH HTTP.
 * =================================================================================================
 *
 * Two halves, and they fail in opposite directions.
 *
 * HALF ONE — AN UNPUBLISHED ARTICLE IS NOT PUBLIC. A draft must be absent from the article URL, the
 * index, its category page and the search results. Four surfaces, because the library has four read
 * paths and a filter applied to one of them reads exactly like a filter applied to all four until
 * somebody checks. Search is the one most likely to leak: it does not go through `article()` at all,
 * it iterates `articles()`.
 *
 * HALF TWO — THE PEOPLE WHO RUN THE WORKFLOW CAN SEE IT. A reviewer who cannot read the Arabic draft
 * rendered cannot review it, so "who reviews Arabic" in the process document would be a sentence
 * with nothing behind it. That surface is an AUTHORIZATION surface, and this codebase has three
 * specific traps in it, all of which are driven here:
 *
 *   - `@can()` and `$user->can()` are ALWAYS FALSE (`permissions_as_gates => false`), so a gate
 *     written with them refuses everybody including the operator.
 *   - THERE IS NO SUPER-ADMIN BYPASS. `isAbleTo()` alone refuses the platform operator, so a
 *     permission-name gate on an operator-only screen refuses the only person who may use it.
 *   - A PERMISSION NAME IS THE WRONG SHAPE OF GATE HERE ANYWAY, and that is why this screen takes
 *     `PlatformOperatorOnly` like `platform/analytics`, `platform/resources` and `platform/revenue`
 *     before it. A merchant creates their OWN roles, so a permission is substantially
 *     self-assignable; the documentation centre is the PLATFORM's own content, not tenant content.
 *     `test_a_merchant_who_has_granted_themselves_every_permission_is_still_refused` is the
 *     differentiating case: every other principal here would also be refused by a careless gate.
 *
 * `' super admin'` WITH A LEADING SPACE IS DRIVEN TOO. MySQL's PAD SPACE ignores a TRAILING space
 * and not a leading one, so `trim()` and `rtrim()` disagree about that row — the defect
 * `PlatformOperatorOnly`'s docblock records. The gate must refuse it.
 */
final class EditorialWorkflowGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const EDITORIAL_URI = '/platform/documentation';

    /** Fixture pairs are written into the real tree and removed in tearDown. */
    private const FIXTURES = [
        ['dir' => '01-getting-started', 'file' => '97-draft-fixture.md',  'slug' => 'draft-fixture'],
        ['dir' => '01-getting-started', 'file' => '96-review-fixture.md', 'slug' => 'review-fixture'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->writeFixture(self::FIXTURES[0], DocumentationStatus::DRAFT);
        $this->writeFixture(self::FIXTURES[1], DocumentationStatus::REVIEW);

        DocumentationLibrary::forget();
    }

    protected function tearDown(): void
    {
        foreach (self::FIXTURES as $fixture) {
            foreach (DocumentationLibrary::LOCALES as $locale) {
                @unlink(base_path('resources/docs/' . $locale . '/' . $fixture['dir'] . '/' . $fixture['file']));
            }
        }

        DocumentationLibrary::forget();

        parent::tearDown();
    }

    /** One unpublished article, in BOTH locales, so no request is a locale fallback. */
    private function writeFixture(array $fixture, string $status): void
    {
        $titles = [
            'en' => 'Unpublished Fixture ' . $status,
            'ar' => 'مادة غير منشورة ' . $status,
        ];
        $bodies = [
            'en' => "## Fixture Heading\n\nUnpublished fixture body text.\n",
            'ar' => "## عنوان تجريبي\n\nنص تجريبي غير منشور.\n",
        ];

        foreach (DocumentationLibrary::LOCALES as $locale) {
            file_put_contents(
                base_path('resources/docs/' . $locale . '/' . $fixture['dir'] . '/' . $fixture['file']),
                "---\ntitle: {$titles[$locale]}\norder: 97\nsummary: A fixture.\n"
                . "updated: 2026-11-26\nstatus: {$status}\n---\n\n{$bodies[$locale]}"
            );
        }
    }

    private function publicUri(array $fixture): string
    {
        return '/platform/docs/getting-started/' . $fixture['slug'];
    }

    private function previewUri(array $fixture): string
    {
        return self::EDITORIAL_URI . '/getting-started/' . $fixture['slug'];
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Half one: an unpublished article is not public
     * ---------------------------------------------------------------------------------------------
     */

    /** PREMISE: the fixtures parse and are really unpublished, or nothing below measures anything. */
    #[Test]
    public function test_the_fixtures_exist_and_are_unpublished(): void
    {
        foreach (self::FIXTURES as $i => $fixture) {
            $all = DocumentationLibrary::all();
            $id  = 'getting-started/' . $fixture['slug'];

            $this->assertArrayHasKey($id, $all['en']['articles'],
                'PREMISE: the ' . $fixture['slug'] . ' fixture did not parse.');
            $this->assertArrayHasKey($id, $all['ar']['articles'],
                'PREMISE: the Arabic half of the ' . $fixture['slug'] . ' fixture did not parse.');

            $this->assertFalse(
                DocumentationStatus::isPublic($all['en']['articles'][$id]['status']),
                'PREMISE: the fixture is published, so the gate is not being tested.'
            );
        }
    }

    #[Test]
    public function test_a_published_article_is_still_served(): void
    {
        /* The control. Without it, a filter that hid EVERYTHING would pass every assertion below. */
        $this->get('/platform/docs/getting-started/create-your-account')->assertOk();
    }

    #[Test]
    public function test_an_unpublished_article_is_not_served_to_a_reader(): void
    {
        foreach (self::FIXTURES as $fixture) {
            foreach (['en', 'ar'] as $locale) {
                $response = $this->withSession(['LANGUAGE' => $locale])->get($this->publicUri($fixture));

                $this->assertSame(404, $response->status(),
                    "{$locale}: /platform/docs/getting-started/{$fixture['slug']} answered "
                    . $response->status() . '. An article that has not been approved is public '
                    . '(GAP-107).');
            }
        }
    }

    #[Test]
    public function test_an_unpublished_article_is_absent_from_the_index_and_its_category(): void
    {
        foreach (['/platform/docs', '/platform/docs/getting-started'] as $uri) {
            $response = $this->get($uri);

            /*
             * `assertOk()` AND A POSITIVE CONTROL, BOTH ADDED AFTER AN INDEPENDENT REVIEW FOUND
             * THIS ONE-DIRECTIONAL. Without them the assertions below are satisfied by a page
             * that 500s, renders empty, or lost its listing entirely — "the draft is absent" is
             * trivially true of a page with nothing on it. The review's words: it does fail on a
             * revert of the filter, so it was never a test that cannot fail; it was a test that
             * could only fail one way.
             */
            $response->assertOk();

            $html = $response->getContent();

            $this->assertStringContainsString('create-your-account', $html,
                "{$uri} did not list a PUBLISHED article, so the absence asserted below proves "
                . 'nothing about the draft filter.');

            foreach (self::FIXTURES as $fixture) {
                $this->assertStringNotContainsString($fixture['slug'], $html,
                    "{$uri} lists the unpublished article {$fixture['slug']} (GAP-107).");
            }
        }
    }

    /**
     * SEARCH IS THE LEAK NOBODY WOULD FIND BY HAND. It does not call `article()`; it iterates
     * `articles()` and ranks in PHP, so a filter attached to the article lookup alone would leave
     * every draft title, summary and body searchable from the public form.
     */
    #[Test]
    public function test_an_unpublished_article_cannot_be_found_by_search(): void
    {
        $response = $this->get('/platform/docs/search?q=' . urlencode('Unpublished fixture'));

        // Same reason as the index sweep above: if the `q` key is ever renamed, or the search page
        // starts answering 500, every "is absent" assertion below passes for free.
        $response->assertOk();

        $control = $this->get('/platform/docs/search?q=' . urlencode('account'));
        $control->assertOk();
        $this->assertStringContainsString('create-your-account', $control->getContent(),
            'the search form returned nothing for a term that matches a PUBLISHED article, so the '
            . 'absences asserted below are not evidence that drafts are filtered.');

        $html = $response->getContent();

        foreach (self::FIXTURES as $fixture) {
            $this->assertStringNotContainsString($fixture['slug'], $html,
                'the public search returned the unpublished article ' . $fixture['slug']
                . ' (GAP-107).');
        }

        $this->assertStringNotContainsString('Unpublished fixture body text', $html,
            'the public search page printed the body of an unpublished article.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Half two: the door of the editorial surface
     * ---------------------------------------------------------------------------------------------
     */

    /** @return list<array{0: string}> */
    public static function editorialUris(): array
    {
        return [
            'the index'     => [self::EDITORIAL_URI],
            'a preview'     => [self::EDITORIAL_URI . '/getting-started/draft-fixture'],
        ];
    }

    #[Test]
    public function test_a_signed_out_visitor_is_sent_to_sign_in_and_never_told_401(): void
    {
        foreach (self::editorialUris() as $label => [$uri]) {
            $response = $this->get($uri);

            $this->assertTrue($response->isRedirect(),
                "{$label}: a guest got status " . $response->status() . ' rather than the sign-in '
                . 'redirect, so the editorial surface is reachable without an account.');

            /* repo B's network layer maps ANY 401 to a forced logout that clears stored
               preferences, so `auth` must own the anonymous case and redirect. */
            $this->assertNotSame(401, $response->status(),
                "{$label}: 401 forces a logout in the shipped Flutter client.");
        }
    }

    #[Test]
    public function test_a_merchant_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (self::editorialUris() as $label => [$uri]) {
            $this->assertSame(403, $this->actingAs($merchant->fresh())->get($uri)->status(),
                "{$label}: a merchant reached the platform's own editorial surface.");
        }
    }

    /**
     * THE DIFFERENTIATING CASE. A merchant mints their own roles, so any permission name is
     * substantially self-assignable: a gate written as a permission check would admit this user.
     */
    #[Test]
    public function test_a_merchant_who_has_granted_themselves_every_permission_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();

        foreach (['Manage Documentation', 'Manage Setting', 'Manage Landing Page Section'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $merchant = $merchant->fresh();

        foreach (self::editorialUris() as $label => [$uri]) {
            $this->assertSame(403, $this->actingAs($merchant)->get($uri)->status(),
                "{$label}: a merchant holding self-granted permissions reached the editorial "
                . 'surface. The gate must be identity (the platform operator), not a permission '
                . 'name.');
        }
    }

    #[Test]
    public function test_a_staff_member_of_a_merchant_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        foreach (self::editorialUris() as $label => [$uri]) {
            $this->assertSame(403, $this->actingAs($staff->fresh())->get($uri)->status(),
                "{$label}: a merchant's staff member reached the editorial surface.");
        }
    }

    /**
     * THE LOOKALIKE ROW. MySQL's PAD SPACE ignores a TRAILING space and not a LEADING one, so
     * `' super admin'` is a NON-operator to the engine and to `UserPolicy::isOperator()`, and was
     * an OPERATOR to a gate written with `trim()`.
     */
    #[Test]
    public function test_a_leading_space_type_is_not_an_operator(): void
    {
        $lookalike = $this->makeMerchant(['type' => ' super admin']);

        foreach (self::editorialUris() as $label => [$uri]) {
            $this->assertSame(403, $this->actingAs($lookalike->fresh())->get($uri)->status(),
                "{$label}: a user whose type is ' super admin' (leading space) was admitted. The "
                . 'engine does not read that row as an operator and neither may the gate.');
        }
    }

    /**
     * A PRINCIPAL OF THE WRONG CLASS IS REFUSED, EVEN ONE CARRYING THE OPERATOR'S OWN `type`.
     *
     * `UserPolicy::isOperator()` takes `$actor` untyped and reads `$actor->type`, and `customers`
     * HAS a `type` column with `'type'` in `Customer::$fillable` — so the value is decided by
     * whatever a registration path mass-assigns. Reaching this route as a `Customer` would also
     * need the DEFAULT guard to be `customers`, which these routes never make it, so this is
     * hardening rather than a live hole. `actingAs($customer, 'customers')` is the one thing that
     * does make the customers guard the default, which is why it is what this drives — the exact
     * shape CLAUDE.md records as hiding guard confusion in tests.
     */
    #[Test]
    public function test_a_customer_carrying_the_operators_type_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Nour',
            'last_name'  => 'Saleh',
            'email'      => 'shopper-' . $this->uniqueFixtureRef() . '@example.test',
            'password'   => bcrypt('x'),
            'store_id'   => $store->id,
            'type'       => 'super admin',
        ]);

        foreach (self::editorialUris() as $label => [$uri]) {
            $response = $this->actingAs($customer, 'customers')->get($uri);

            $this->assertSame(
                403,
                $response->status(),
                // EXACTLY 403, not merely "not 200", and an independent review asked for the
                // change. `Customer` is not `MustVerifyEmail`, so the route's `verified`
                // middleware cannot redirect it and the request really does reach the handler —
                // which means the refusal has one correct value. "not 200" would also be
                // satisfied by a 500, i.e. by the guard being removed and the handler merely
                // breaking on a principal it cannot render. Measured on the sibling middleware
                // test: without the class check that path answers 500, not 200.
            );
        }
    }

    #[Test]
    public function test_the_platform_operator_sees_every_article_and_its_state(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->get(self::EDITORIAL_URI);

        $response->assertOk();
        $html = $response->getContent();

        /* Every state is on the screen, published and unpublished alike: the point of the screen is
           to see the pipeline, and a screen showing only drafts cannot answer "is this live?". */
        $this->assertStringContainsString('draft-fixture', $html,
            'the editorial screen does not list the draft article, so nobody can see what is '
            . 'waiting (GAP-107).');
        $this->assertStringContainsString('review-fixture', $html,
            'the editorial screen does not list the article waiting for review.');
        $this->assertStringContainsString('create-your-account', $html,
            'the editorial screen lists only unpublished articles, so it cannot answer "what is '
            . 'live?".');

        $this->assertStringContainsString(__('In review'), $html,
            'the screen does not name the review state.');
        $this->assertStringContainsString(__('Draft'), $html,
            'the screen does not name the draft state.');
    }

    #[Test]
    public function test_the_platform_operator_can_read_an_unpublished_article(): void
    {
        $operator = $this->makePlatformAdmin();

        $response = $this->actingAs($operator)->get($this->previewUri(self::FIXTURES[0]));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Unpublished fixture body text', $html,
            'the preview does not render the draft body, so a reviewer cannot review it.');

        /* AND IT SAYS SO. A draft rendered identically to a published article is how somebody
           quotes an unapproved page to a merchant. */
        $this->assertStringContainsString(__('Not published yet'), $html,
            'the preview renders an unapproved article with nothing to say it is unapproved.');
    }

    /**
     * THE ARABIC DRAFT, which is the case the process document's "who reviews Arabic" depends on.
     */
    #[Test]
    public function test_the_operator_can_read_the_arabic_draft_in_arabic(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator)
            ->withSession(['LANGUAGE' => 'ar'])
            ->get($this->previewUri(self::FIXTURES[0]))
            ->getContent();

        $this->assertStringContainsString('نص تجريبي غير منشور', $html,
            'the Arabic draft was not rendered from the Arabic file, so an Arabic reviewer is '
            . 'reviewing the English text.');

        /* THE ABSENT DIRECTION, which is the one that can fail: `__()` returns its KEY when a
           translation is missing, and the key here is the English sentence. `Not published yet` is
           deliberately an existing bilingual key, so this passes on the day it ships. */
        $this->assertStringContainsString('لم تُنشر بعد', $html,
            'the Arabic preview does not carry the Arabic "not published yet" notice.');

        $this->assertStringNotContainsString('Not published yet', $html,
            'the English draft notice survived onto the Arabic page, so its translation is '
            . 'missing.');
    }

    /**
     * AN UNAPPROVED TRANSLATION FALLS BACK TO THE APPROVED ENGLISH, it does not 404.
     *
     * This is the one state the two halves of this change produce together, and it is worth pinning
     * because both plausible alternatives are wrong. A 404 would take a working English page away
     * from an Arabic reader because its TRANSLATION is unfinished; serving the unapproved Arabic
     * would defeat the gate. The existing fallback notice already says the page is in English.
     *
     * `DocumentationEditorialStatusTest` forbids this state in the SHIPPED corpus (the two locales
     * must agree), so it is reachable only while somebody is working — which is exactly when a
     * reviewer needs it to behave sensibly.
     */
    #[Test]
    public function test_an_unapproved_translation_falls_back_to_the_approved_english(): void
    {
        $fixture = ['dir' => '01-getting-started', 'file' => '95-half-translated.md', 'slug' => 'half-translated'];

        file_put_contents(base_path('resources/docs/en/' . $fixture['dir'] . '/' . $fixture['file']),
            "---\ntitle: Half Translated Fixture\norder: 95\nsummary: A fixture.\n"
            . "updated: 2026-11-26\nstatus: published\n---\n\n## Heading\n\nApproved English body.\n");

        file_put_contents(base_path('resources/docs/ar/' . $fixture['dir'] . '/' . $fixture['file']),
            "---\ntitle: مادة نصف مترجمة\norder: 95\nsummary: تجربة.\n"
            . "updated: 2026-11-26\nstatus: draft\n---\n\n## عنوان\n\nترجمة غير معتمدة.\n");

        try {
            DocumentationLibrary::forget();

            $response = $this->withSession(['LANGUAGE' => 'ar'])
                ->get('/platform/docs/getting-started/half-translated');

            $response->assertOk();
            $html = $response->getContent();

            $this->assertStringContainsString('Approved English body.', $html,
                'an article whose Arabic translation is not approved yet 404s for an Arabic '
                . 'reader instead of falling back to the approved English.');

            $this->assertStringNotContainsString('ترجمة غير معتمدة', $html,
                'the UNAPPROVED Arabic text was served to a reader.');

            $this->assertStringContainsString('لم تُترجَم هذه الصفحة بعد', $html,
                'the reader was given English with nothing to say why.');
        } finally {
            foreach (DocumentationLibrary::LOCALES as $locale) {
                @unlink(base_path('resources/docs/' . $locale . '/' . $fixture['dir'] . '/' . $fixture['file']));
            }
            DocumentationLibrary::forget();
        }
    }

    #[Test]
    public function test_a_preview_of_something_that_does_not_exist_is_a_404_for_the_operator(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->assertSame(404, $this->actingAs($operator)
            ->get(self::EDITORIAL_URI . '/getting-started/no-such-article')->status());

        /*
         * A PATH SEGMENT IS NOT A FILESYSTEM PATH — AND THIS COMMENT USED TO NAME THE WRONG
         * CONTROL, which an independent review caught. It said the 404 comes from `slugOf()`
         * stripping everything but `[A-Za-z0-9-]`. It does not: `slugOf()` is only ever applied
         * to `glob()` output, so it never sees a request value at all.
         *
         * TWO REAL DEFENCES, and the probe below is stopped by the first:
         *   1. the ROUTER. Laravel rawurldecodes the path before matching, so this URI becomes
         *      seven segments and the four-segment pattern simply does not match.
         *   2. the HANDLER, which is the one that would matter if a probe ever survived routing:
         *      `{category}` and `{article}` are concatenated into an ARRAY KEY and looked up in
         *      an index built from `glob()`. No filesystem call anywhere takes a request value,
         *      so even a surviving key is a miss and a 404. Double-encoding (`..%252F..`) arrives
         *      as a literal and still only misses a key.
         *
         * Corrected rather than deleted: a comment naming a control that is not there is how the
         * real one gets removed later by someone tidying up.
         */
        $this->assertSame(404, $this->actingAs($operator)
            ->get(self::EDITORIAL_URI . '/getting-started/..%2F..%2F..%2Fcomposer')->status());
    }

    /**
     * THE ROUTE-LEVEL MIDDLEWARE IS PINNED, BECAUSE OTHERWISE IT IS A COMMENT.
     *
     * An independent review made the point out of the builder's own observation: removing
     * `PlatformOperatorOnly` from both routes left all sixteen tests in this file passing,
     * because the in-handler check carries the refusal on its own. That is the right PRIMARY
     * control — it survives a route being moved — but it means the declared second layer is
     * asserted by nothing, while two docblocks state that it exists. An unpinned layer is not
     * defence in depth.
     *
     * Asserted against the RESOLVED stack rather than the route file's text, because CLAUDE.md
     * records that a route's declared middleware is not its resolved middleware: `bootstrap/app.php`
     * appends guards to the `web` group that the route declaration never shows.
     */
    #[Test]
    public function test_both_editorial_routes_carry_the_operator_middleware_in_their_resolved_stack(): void
    {
        $routes = app('router')->getRoutes();

        foreach (['platform.documentation.index', 'platform.documentation.preview'] as $name) {
            $route = $routes->getByName($name);

            $this->assertNotNull($route, "The route {$name} does not exist.");

            $this->assertContains(
                \App\Http\Middleware\PlatformOperatorOnly::class,
                $route->gatherMiddleware(),
                "The route {$name} no longer carries PlatformOperatorOnly. The handler's own "
                . 'operator check still refuses, so nothing else in this file would have gone '
                . 'red — which is exactly why this assertion exists.'
            );
        }
    }
}
