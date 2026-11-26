<?php

namespace Tests\Feature\Platform;

use App\Models\Setting;
use App\Support\Platform\PlatformContentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-104 (P03-C-05) — THE OFFICIAL SITE'S CONTENT IS EDITABLE WITHOUT A DEPLOY.
 * =================================================================================================
 *
 * The gap: "all seven pages are Blade templates with literal copy wrapped in `__()`… changing a
 * headline, an FAQ answer or the legal text requires a code change, review, merge and deploy."
 * That is the capability this file measures, end to end and through HTTP: the operator saves on
 * the editor screen, and a VISITOR to the public page sees the change on the next request.
 *
 * THE SPLIT THIS FILE KEEPS. The capability is engineering's; the CONTENT is the owner's. So the
 * fixtures below are obviously-fake strings, there is no fixture anywhere in this repository that
 * reads like a real privacy notice or a real postal address, and
 * `test_an_unsupplied_legal_document_still_says_it_is_not_published` asserts the owed state is
 * SHOWN rather than filled.
 *
 * WHY THE ASSERTIONS GO THROUGH A SECOND HTTP REQUEST and not through the store's reader: the
 * reader is memoised per request and `Setting` is `Cachable`, so a same-request read would answer
 * from memory and prove nothing about what a visitor gets. CLAUDE.md records three measured false
 * readings from exactly that.
 */
final class PlatformContentPublishingTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const EDIT_LEGAL   = '/platform/site-content/legal';
    private const EDIT_HOME    = '/platform/site-content/home';
    private const EDIT_CONTACT = '/platform/site-content/contact';
    private const EDIT_FAQ     = '/platform/site-content/faq';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        PlatformContentStore::forget();
    }

    /**
     * PREMISE: with nothing supplied, every page renders exactly the copy it renders today.
     *
     * Without this the whole file could pass against a capability that had replaced the site's
     * content with blanks.
     */
    #[Test]
    public function test_with_no_overrides_the_pages_render_their_built_in_copy(): void
    {
        $this->get('/platform')->assertOk()
            ->assertSee('Open your shop online, and run the whole business from one place.', false);

        $this->get('/platform/faq')->assertOk()
            ->assertSee('What is Store Syria?', false);

        $this->get('/platform/legal')->assertOk()
            ->assertSee('Terms of service', false)
            ->assertSee('Not published yet', false);
    }

    #[Test]
    public function test_the_operator_changes_a_headline_and_a_visitor_sees_it_without_a_deploy(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)
            ->post(self::EDIT_HOME, ['en' => ['home.hero.title' => 'FIXTURE HEADLINE EN']])
            ->assertRedirect();

        PlatformContentStore::forget();

        /* THE VISITOR, signed out, on a second request. */
        $html = $this->get('/platform')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE HEADLINE EN', $html,
            'the operator saved a headline and the public page still renders the code-owned one, '
            . 'so the official site still needs a deploy to change (GAP-104).');

        $this->assertStringNotContainsString(
            'Open your shop online, and run the whole business from one place.',
            $html,
            'the built-in headline survived beside the override, so the page now shows both.'
        );
    }

    /** The override is PER LOCALE: an English edit does not overwrite the Arabic page. */
    #[Test]
    public function test_an_override_is_per_locale(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_HOME, [
            'en' => ['home.hero.title' => 'FIXTURE HEADLINE EN'],
            'ar' => ['home.hero.title' => 'عنوان تجريبي'],
        ])->assertRedirect();

        PlatformContentStore::forget();

        $en = $this->get('/platform')->getContent();
        $ar = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform')->getContent();

        $this->assertStringContainsString('FIXTURE HEADLINE EN', $en);
        $this->assertStringNotContainsString('عنوان تجريبي', $en,
            'the Arabic override leaked onto the English page.');

        $this->assertStringContainsString('عنوان تجريبي', $ar);
        $this->assertStringNotContainsString('FIXTURE HEADLINE EN', $ar,
            'the English override leaked onto the Arabic page — the exact direction CLAUDE.md '
            . 'records as the bilingual failure mode, since `__()` returns its English key on a '
            . 'miss.');
    }

    /**
     * SAVING AN EMPTY VALUE RESTORES THE BUILT-IN COPY rather than blanking the page.
     *
     * One rule, stated once: an empty field means "no override". A capability that could only add
     * copy and never undo it would make a bad edit permanent without a deploy, which is the defect
     * this row exists to remove, in reverse.
     */
    #[Test]
    public function test_clearing_a_field_restores_the_built_in_copy(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)
            ->post(self::EDIT_HOME, ['en' => ['home.hero.title' => 'FIXTURE HEADLINE EN']]);

        PlatformContentStore::forget();
        $this->get('/platform')->assertSee('FIXTURE HEADLINE EN', false);

        $this->actingAs($operator)
            ->post(self::EDIT_HOME, ['en' => ['home.hero.title' => '   ']]);

        PlatformContentStore::forget();

        $this->get('/platform')->assertOk()
            ->assertSee('Open your shop online, and run the whole business from one place.', false)
            ->assertDontSee('FIXTURE HEADLINE EN', false);
    }

    /**
     * AN FAQ ANSWER IS EDITABLE, which the gap names explicitly.
     */
    #[Test]
    public function test_an_faq_answer_is_editable(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)
            ->post(self::EDIT_FAQ, ['en' => ['faq.1.answer' => 'FIXTURE ANSWER ONE']]);

        PlatformContentStore::forget();

        $html = $this->get('/platform/faq')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE ANSWER ONE', $html);
        $this->assertStringContainsString('What is Store Syria?', $html,
            'the question lost its built-in copy when only the answer was overridden.');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The owner's content: shown as OWED, never invented
     * ---------------------------------------------------------------------------------------------
     */

    #[Test]
    public function test_an_unsupplied_legal_document_still_says_it_is_not_published(): void
    {
        $html = $this->get('/platform/legal')->assertOk()->getContent();

        $this->assertSame(5, substr_count($html, 'Not published yet'),
            'the legal page does not mark all five documents as unpublished. Until the owner '
            . 'supplies the approved text, every one of them is owed.');
    }

    #[Test]
    public function test_a_supplied_legal_document_is_published_and_stops_saying_it_is_not(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_LEGAL, [
            'en' => ['legal.terms.text' => "## Fixture heading\n\nFIXTURE TERMS BODY.\n"],
        ])->assertRedirect();

        PlatformContentStore::forget();

        $html = $this->get('/platform/legal')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE TERMS BODY.', $html,
            'the operator published the terms and the public page does not carry them.');

        /* Markdown, rendered by the documentation centre's renderer: HTML in the source is
           STRIPPED and unsafe links refused, so an operator cannot put script into a public page. */
        $this->assertStringContainsString('<h2 id="fixture-heading">Fixture heading</h2>', $html,
            'the published legal text was not rendered as Markdown.');

        $this->assertSame(4, substr_count($html, 'Not published yet'),
            'a published document still carries the "not published yet" tag, or the other four '
            . 'lost theirs.');
    }

    /**
     * THE OPERATOR CANNOT PUT HTML INTO A PUBLIC PAGE. The text is theirs, the rendering is not.
     */
    #[Test]
    public function test_html_in_a_published_legal_document_is_stripped(): void
    {
        $operator = $this->makePlatformAdmin();

        /*
         * THE SCRIPT IS ON ITS OWN LINE AND THE PROSE IS A SEPARATE PARAGRAPH, and the first draft
         * of this test had them on one line and failed for the RIGHT reason: CommonMark treats
         * `<script>` as an HTML block that runs to the line carrying `</script>`, and
         * `html_input => 'strip'` removes the whole block — so the prose sharing that line was
         * stripped with it. Separating them is what makes this test measure stripping rather than
         * block boundaries, and it now drives BOTH a block-level tag and an inline one.
         */
        $this->actingAs($operator)->post(self::EDIT_LEGAL, [
            'en' => ['legal.privacy.text' => "<script>alert(1)</script>\n\nFIXTURE <b>PRIVACY</b> BODY.\n"],
        ]);

        PlatformContentStore::forget();

        $html = $this->get('/platform/legal')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE PRIVACY BODY.', $html,
            'the prose did not survive, so the absences below prove nothing.');

        $this->assertStringNotContainsString('alert(1)', $html,
            'raw HTML from the content editor reached a public page.');

        $this->assertStringNotContainsString('<b>PRIVACY</b>', $html,
            'inline raw HTML from the content editor was rendered rather than stripped.');
    }

    #[Test]
    public function test_contact_details_are_absent_until_supplied_and_then_published(): void
    {
        $html = $this->get('/platform/contact')->assertOk()->getContent();

        $this->assertStringContainsString('Our published contact details are being confirmed', $html,
            'the contact page does not say the published details are outstanding, yet nothing '
            . 'has been supplied.');

        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_CONTACT, [
            'en' => ['contact.telephone' => 'FIXTURE TELEPHONE'],
        ]);

        PlatformContentStore::forget();

        $after = $this->get('/platform/contact')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE TELEPHONE', $after,
            'a supplied telephone number is not published.');

        $this->assertStringNotContainsString('Our published contact details are being confirmed', $after,
            'the page still says the details are outstanding after one was supplied.');
    }

    /**
     * A MULTI-LINE CONTACT DETAIL IS ESCAPED BEFORE ITS NEWLINES BECOME `<br>`.
     *
     * The contact page is the only other place operator text reaches a public page through an
     * unescaped echo, because an address has to keep its line breaks. The order is `nl2br(e($v))`
     * and it is the whole control: `e(nl2br($v))` would print the `<br>` tags as text and let the
     * markup through, and a bare `nl2br($v)` would render whatever the operator typed. Nothing
     * measured that until this test.
     */
    #[Test]
    public function test_a_multi_line_contact_detail_is_escaped_before_its_newlines_are_rendered(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_CONTACT, [
            'en' => ['contact.address' => "FIXTURE LINE ONE <b>x</b>\nFIXTURE LINE TWO"],
        ])->assertRedirect();

        PlatformContentStore::forget();

        $html = $this->get('/platform/contact')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE LINE ONE', $html,
            'PREMISE: the supplied address was not published at all.');

        $this->assertStringContainsString("FIXTURE LINE ONE &lt;b&gt;x&lt;/b&gt;<br />\nFIXTURE LINE TWO", $html,
            'the address was not escaped before its newlines were turned into `<br>`, so markup '
            . "the operator typed reached a public page — or the line break was lost.");
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The write path refuses what it does not know
     * ---------------------------------------------------------------------------------------------
     */

    #[Test]
    public function test_a_key_the_registry_does_not_declare_is_not_stored(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_HOME, [
            'en' => [
                'home.hero.title'    => 'FIXTURE HEADLINE EN',
                'not.a.real.key'     => 'FIXTURE SMUGGLED',
                'legal.terms.text'   => 'FIXTURE WRONG PAGE',
            ],
        ]);

        PlatformContentStore::forget();

        $stored = Setting::withoutGlobalScopes()
            ->where('name', 'like', 'platform_site_content%')
            ->pluck('value')
            ->implode(' ');

        $this->assertStringContainsString('FIXTURE HEADLINE EN', $stored,
            'PREMISE: the legitimate field was not stored either, so the absences below prove '
            . 'nothing.');

        $this->assertStringNotContainsString('FIXTURE SMUGGLED', $stored,
            'an undeclared key was written into the content row.');

        /* A field belonging to ANOTHER page is refused on this page's form: the form posts only
           the fields it renders, so accepting a foreign key would let one screen's POST rewrite
           content the operator was not looking at. */
        $this->assertStringNotContainsString('FIXTURE WRONG PAGE', $stored,
            "a field belonging to another page was accepted by this page's form.");
    }

    #[Test]
    public function test_an_unknown_locale_is_not_stored(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_HOME, [
            'en' => ['home.hero.title' => 'FIXTURE HEADLINE EN'],
            'fr' => ['home.hero.title' => 'FIXTURE SMUGGLED FR'],
        ]);

        PlatformContentStore::forget();

        $names = Setting::withoutGlobalScopes()
            ->where('name', 'like', 'platform_site_content%')
            ->pluck('name')
            ->all();

        $this->assertNotContains('platform_site_content_fr', $names,
            'a locale the official site is not published in was accepted.');
    }

    /**
     * A DOCUMENT APPROVED IN ENGLISH IS NOT PUBLISHED TO AN ARABIC READER.
     *
     * `PlatformContent` states this as a design rule and nothing measured it until a mutation run
     * showed why that mattered: adding an English fallback to the reader left every other test in
     * this file green, because they all supply both locales. An un-measured rule is a comment.
     *
     * AND THE RULE IS NOT A PREFERENCE. An approved Arabic privacy notice and an approved English
     * one are two documents, not one document in two spellings. Falling back would publish an
     * unapproved translation of binding text under the company's name — which is the single thing
     * this whole capability must not do. The Documentation Centre's opposite choice (serve the
     * English with a notice) is right for a help article and wrong here, and the difference is
     * that nobody is bound by a help article.
     */
    #[Test]
    public function test_a_document_approved_in_one_language_is_not_published_in_the_other(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_LEGAL, [
            'en' => ['legal.refunds.text' => 'FIXTURE ENGLISH REFUND TERMS.'],
        ])->assertRedirect();

        PlatformContentStore::forget();

        $en = $this->get('/platform/legal')->assertOk()->getContent();
        $ar = $this->withSession(['LANGUAGE' => 'ar'])->get('/platform/legal')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE ENGLISH REFUND TERMS.', $en,
            'PREMISE: the English document was not published either.');

        $this->assertStringNotContainsString('FIXTURE ENGLISH REFUND TERMS.', $ar,
            'the English text of a binding document was published to an Arabic reader, who never '
            . 'approved it and may not read it.');

        $this->assertSame(5, substr_count($ar, 'لم تُنشر بعد'),
            'the Arabic page does not mark all five documents as unpublished. Only the English '
            . 'text has been approved, so in Arabic every one of them is still owed.');
    }

    /**
     * THE OPERATOR'S WORDS ARRIVE AS THE OPERATOR TYPED THEM.
     *
     * FOUND BY THE AUTHORIZATION REVIEW OF THIS CHANGE, and it is a content defect rather than a
     * security one. `App\Http\Middleware\XSS` deletes four substrings from EVERY string in EVERY
     * request it handles -- `['&lt;', '&gt;', 'javascript', 'alert']` (XSS.php:35, :116) -- and
     * `xss` is on the admin group these routes join. So before this was fixed, an operator writing
     *
     *     "you may not inject javascript into your storefront"   (an acceptable-use document)
     *     "we will alert you by email"                           (a privacy notice)
     *
     * SAVED, reported success, and published "you may not inject  into your storefront" on the
     * company's own website. `str_replace` is case-sensitive, so "JavaScript" survives and
     * "javascript" does not, which makes the corruption look random rather than systematic.
     *
     * For a CONTENT editor that is not a cosmetic bug: the five legal documents are binding text,
     * and silently deleting a word from them is worse than refusing the save. The fix is one
     * `withoutMiddleware` on the write route, and it removes no protection this path relies on --
     * a `line` or `block` field is escaped by Blade and a `markdown` field goes through
     * `DocumentationRenderer::html()`, whose `html_input => 'strip'` is what
     * `test_html_in_a_published_legal_document_is_stripped` actually measures (proved by the
     * mutation that replaces the renderer with a raw echo and reddens it).
     */
    #[Test]
    public function test_the_words_alert_and_javascript_survive_a_save(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator)->post(self::EDIT_LEGAL, [
            'en' => ['legal.acceptable-use.text' => 'You may not inject javascript, and we will alert you.'],
        ])->assertRedirect();

        PlatformContentStore::forget();

        $html = $this->get('/platform/legal')->assertOk()->getContent();

        $this->assertStringContainsString(
            'You may not inject javascript, and we will alert you.',
            $html,
            'the official site published text the operator did not write. `App\Http\Middleware\XSS` '
            . 'deletes the substrings `javascript` and `alert` from every request string '
            . '(XSS.php:35), so a word the operator typed was silently removed from the company\'s '
            . 'own published copy.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The STORE's own filters, with the controller out of the picture
     * ---------------------------------------------------------------------------------------------
     *
     * ADDED AFTER A MUTATION RUN SHOWED THEM UNPINNED. `PlatformContentStore::put()` filters keys
     * against the registry and locales against the two the site is published in — and the
     * controller already does both, so removing either filter from the store left every HTTP test
     * above passing. The store is the last thing before the row and will acquire other callers; an
     * unpinned layer is not defence in depth.
     */

    #[Test]
    public function test_the_store_refuses_a_key_the_registry_does_not_declare(): void
    {
        // ACTING AS the operator, not merely creating one: `PlatformContentWriteGuard` refuses a
        // write to these names from any principal that is not the operator, and from no principal
        // at all. This test drives `put()` directly, so it needs the actor as well as the row.
        $this->actingAs($this->makePlatformAdmin());

        PlatformContentStore::put('en', [
            'home.hero.title' => 'FIXTURE HEADLINE EN',
            'not.a.real.key'  => 'FIXTURE SMUGGLED',
        ]);

        $stored = Setting::withoutGlobalScopes()
            ->where('name', 'platform_site_content_en')
            ->pluck('value')
            ->implode(' ');

        $this->assertStringContainsString('FIXTURE HEADLINE EN', $stored,
            'PREMISE: the legitimate key was not stored either.');

        $this->assertStringNotContainsString('FIXTURE SMUGGLED', $stored,
            'the store wrote a key the registry does not declare, so no page would ever render it '
            . 'and nothing would ever remove it.');
    }

    #[Test]
    public function test_the_store_refuses_a_locale_the_site_is_not_published_in(): void
    {
        $this->makePlatformAdmin();

        PlatformContentStore::put('fr', ['home.hero.title' => 'FIXTURE SMUGGLED FR']);

        $this->assertSame(
            0,
            Setting::withoutGlobalScopes()->where('name', 'like', 'platform_site_content%')->count(),
            'the store wrote a row for a locale the official site is not published in.'
        );
    }

    /**
     * THE READ PREDICATE IGNORES A ROW THAT IS NOT THE OPERATOR'S.
     *
     * THIS TEST'S SCOPE WAS NARROWED AFTER AN INDEPENDENT REVIEW SHOWED ITS CLAIM WAS FALSE. It
     * used to say it pinned "two independent reasons a merchant cannot write the platform's row",
     * and it measured neither: it planted rows with `Setting::create()`, drove no
     * merchant-reachable writer, and never made `getCurrentStore()` return null — so it stayed
     * green against a live exploit in which a storeless merchant replaced the published text of
     * the company's own website, legal documents included, through `POST /cookie-setting`.
     *
     * What it DOES pin is still worth having, so it is kept rather than deleted: the reader's
     * `created_by` predicate. Revert that and this reddens.
     *
     * THE EXPLOIT ITSELF IS DRIVEN IN
     * `tests/Feature/Security/PlatformSiteContentWriteGuardTest`, through the real route with a
     * real merchant principal, against the guard in `Setting::booted()` that now closes it.
     *
     * The fixtures below act as the operator because that guard refuses these names from anybody
     * else — including from no principal at all.
     */
    public function test_a_merchants_own_settings_row_cannot_become_the_platforms_content(): void
    {
        /*
         * AN OPERATOR EXISTS, AND THAT IS THE WHOLE POINT OF THE FIXTURE. The first draft of this
         * test created only a merchant — and passed for the wrong reason: with no `super admin`
         * row, `PlatformContentStore::ownerId()` is null and the reader returns nothing whatever
         * the ownership predicate says. With an operator present the reader really does run the
         * predicate, and the operator's own override below is the positive control that proves it.
         */
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->makeTenant();

        /*
         * ACTING AS **THIS** OPERATOR, not merely as an operator. PlatformContentWriteGuard
         * refuses these names from any other principal, so the fixtures below need an actor --
         * and it must be this one, because PlatformContentStore::ownerId() resolves the FIRST
         * super admin by id. Creating a second operator to act as would make ownerId() return
         * that one while these rows were planted under $operator, and the reader would then
         * correctly ignore all three. Measured: that is exactly what my first attempt did.
         */
        $this->actingAs($operator);

        /* Three rows of the same name: one the platform owns, one a merchant owns in their store,
           and one a merchant owns with no store at all (the shape that would collide on name
           alone). Only the first may be published. */
        Setting::create([
            'name'       => 'platform_site_content_en',
            'value'      => json_encode(['home.hero.title' => 'FIXTURE OPERATOR HEADLINE']),
            'store_id'   => null,
            'created_by' => (string) $operator->id,
        ]);

        Setting::create([
            'name'       => 'platform_site_content_en',
            'value'      => json_encode(['home.hero.title' => 'FIXTURE MERCHANT INJECTION']),
            'store_id'   => $store->id,
            'created_by' => (string) $merchant->id,
        ]);

        Setting::create([
            'name'       => 'platform_site_content_en',
            'value'      => json_encode(['home.hero.title' => 'FIXTURE STORELESS INJECTION']),
            'store_id'   => null,
            'created_by' => (string) $merchant->id,
        ]);

        PlatformContentStore::forget();

        $html = $this->get('/platform')->assertOk()->getContent();

        $this->assertStringContainsString('FIXTURE OPERATOR HEADLINE', $html,
            'PREMISE: the row the PLATFORM owns was not published either, so the absences below '
            . 'prove nothing about the ownership predicate.');

        $this->assertStringNotContainsString('FIXTURE MERCHANT INJECTION', $html,
            "a merchant's own store-scoped settings row was published on the platform's official "
            . 'website.');

        $this->assertStringNotContainsString('FIXTURE STORELESS INJECTION', $html,
            'a row of the right name and no store, owned by a merchant, was published on the '
            . "platform's official website. The reader's predicate is ownership, not the name.");
    }
}
