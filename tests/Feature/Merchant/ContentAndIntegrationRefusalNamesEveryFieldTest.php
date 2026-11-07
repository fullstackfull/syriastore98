<?php

namespace Tests\Feature\Merchant;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\Contact;
use App\Models\EmailTemplate;
use App\Models\Page;
use App\Models\PixelFields;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-validation-errors-lose-the-modal, THE CONTENT + INTEGRATIONS GROUP (11 refusal sites, 7 files).
 * =================================================================================================
 *
 * The same one-expression repair `ModalFormRefusalFeedbackTest` landed on `CategoryController` and
 * `ValidationRefusalNamesEveryFieldTest` landed on seven more:
 *
 *     -  return redirect()->back()->with('error', $messages->first());
 *     +  return redirect()->back()->with('error', implode(' ', $messages->all()));
 *
 * applied here to BlogController (2 sites), PageController (2), ContactController (1 of its 2),
 * PixelFieldsController (2), WebhookController (2), EmailTemplateController (1) and
 * AppSettingController (1). Same flash key, same 302, nothing added, no `fails()` guard created,
 * no validator rule touched.
 *
 * ------------------------------------------------------------------------------------------------
 * EVERY BAG SIZE BELOW WAS COUNTED BY RUNNING `Validator::make`, not by counting rule keys
 * (scratch scripts, 2026-11-04; the webhook pair against a real `webhooks` table, because `unique`
 * needs one). TEN OF THE ELEVEN ARE OBSERVABLE -- a real request can produce more than one message,
 * so the join is a user-visible repair and is proved here BY DRIVING IT:
 *
 *   BlogController::store()   :64   4 messages (title, short description, content, category)
 *   BlogController::update()  :147  4 messages
 *   PageController::store()   :54   2 messages (page name, page slug)
 *   PageController::update()  :111  2 messages
 *   ContactController::update()     :118  6 messages
 *   PixelFieldsController::store()  :58   2 messages (platform, pixel id)
 *   PixelFieldsController::update() :116  2 messages
 *   WebhookController::store()      :47   3 messages -- and 3 again for a DUPLICATE module, where
 *                                         `unique` fires first and used to be the only one shown
 *   WebhookController::update()     :101  3 messages
 *   EmailTemplateController::updateEmailSettings() :73  3 messages (from, subject, content)
 *
 * THE ELEVENTH IS INERT. `AppSettingController::SiteSetting()` :1306 carries exactly one rule
 * (`date_format => required`), measured bag = 1, so the join is BYTE-IDENTICAL to `first()` for
 * every possible request. It gets the expression anyway -- it is the one that stays correct the
 * moment a second rule is added -- but this file does not pretend it changed behaviour: its driven
 * assertion is the byte-for-byte one, no separator and no padding, which is simultaneously the
 * negative control the repair has to survive.
 *
 * ------------------------------------------------------------------------------------------------
 * THE CENSUS THAT SELECTED THESE FILES WAS WRONG TWICE IN `AppSettingController`, and both
 * corrections are pinned below rather than left as prose:
 *
 *   (1) IT NAMED :588 AND THAT IS NOT THE DESCRIBED SITE. :588 reads
 *       `->with('error', $validator->errors()->first())` -- no `$messages` variable exists in
 *       `ThemeSettings()` at all -- and it already carries `->withErrors($validator)` and
 *       `->withInput(...)` at :589-590 from the earlier P2.5 Class A pass. Its validator is ONE
 *       field (`store_slug`), and `if ($request->store_slug)` at :566 means the block is only
 *       entered for a TRUTHY value, so the `required` limb is unreachable and the bag is always
 *       exactly 1 (measured: 1 for 'Bad Slug!', 1 for '', 1 for [] and 1 for ['a']). Repairing it
 *       would mean inventing a SECOND expression shape (`$validator->errors()->all()`) for a site
 *       that is provably byte-identical, so it is LEFT and
 *       `test_the_theme_settings_site_the_census_misnamed_is_left_exactly_as_it_was` pins that.
 *
 *   (2) IT MISSED :1306, which is the file's ONLY `$messages->first()`. That is the one repaired.
 *
 * ------------------------------------------------------------------------------------------------
 * ONE CENSUS SITE IS OUT OF SCOPE AND IS DELIBERATELY STILL BROKEN: `ContactController::store()`
 * :56, whose bag is SIX (measured). It is not the merchant inbox editor -- it is the PUBLIC
 * STOREFRONT contact form. `Route::resource('contacts', ...)->except(['store'])` at
 * routes/web.php:1352 removes the resource's own store route and routes/web.php:1554 re-declares
 * `POST contacts/{slug?}` OUTSIDE the admin `auth` group; it is entry 1 of
 * `UnauthenticatedRouteBoundaryTest::PUBLIC_ROUTE_ALLOWLIST`, and its only caller is
 * resources/views/front_end/jQueryRoute.blade.php:344. The storefront is PHASE 03, so the defect
 * is classified, measured, reported and LEFT -- see
 * `test_the_public_storefront_contact_form_is_deliberately_left_for_phase_03`.
 *
 * ------------------------------------------------------------------------------------------------
 * THE TRAP THIS CHANGE HAD TO AVOID, and it lives in a file this group owns.
 * `AppSettingController` builds SIX validators and consults TWO (measured on the comment-stripped
 * source: 6 `Validator::make(`, 2 `->fails()`). Giving a DORMANT validator a `fails()` guard would
 * start REFUSING posts that succeed today -- a business-rule change wearing a repair's clothes, and
 * one that would redden `HostClaimsTest:490/:510`. No `fails()` guard was added anywhere;
 * `test_no_dormant_validator_was_activated_in_any_owned_file` pins the 6/2 split and the equality
 * of built-to-consulted in the other six files.
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT THE WIDENING DISCLOSES: nothing new, at any of the eleven. Four of the seven handlers have
 * NO permission gate of their own (PixelFields, Webhook, EmailTemplate, AppSetting::SiteSetting --
 * their routes carry only auth/xss/setlocate/verified), so that was checked rather than assumed:
 * every message those validators can emit names a FORM FIELD THE CALLER POSTED TO, and the webhook
 * `unique` rule is already scoped to the caller's own `store_id`. The three gated handlers (Blog,
 * Page, Contact) keep their `isAbleTo()` check ABOVE the validator, which
 * `test_a_staff_user_without_the_permission_never_sees_the_widened_message` drives.
 *
 * ------------------------------------------------------------------------------------------------
 * DEFECTS FOUND IN THESE FILES AND NOT REPAIRED HERE -- recorded so nobody has to rediscover them.
 * Each would change WHETHER a request is accepted or WHICH row it touches, which this slice must
 * not do:
 *   - **HALF OF THIS ENTRY IS NOW REPAIRED (P2.5-PKG-B). Read it as a record of what was found,
 *     not of what is still open.** The shape was: a bare id with no store predicate, where the
 *     model is absent from `config/tenancy.php`'s `scoped_models` (asserted below), and -- on Page
 *     and PixelFields -- a following `store_id = getCurrentStore()` that MOVED a foreign row into
 *     the caller's store rather than merely editing it.
 *
 *     REPAIRED, with the cross-tenant access measured before and after by
 *     `tests/Feature/Security/SettingsAndContentCrossTenantTest`:
 *       * `PageController` -- edit, update, destroy, show, AND `updateStatus()`, which had no
 *         authorization of any kind and let any authenticated principal holding zero permissions
 *         unpublish any store's live storefront page.
 *       * `PixelFieldsController` -- edit, update, destroy, show.
 *       * (siblings outside this file: ProductBrand, ProductLabel, Tax, Category::show.)
 *     Both takeover assignments were DELETED rather than guarded, so a future weakening of a
 *     guard re-opens an unauthorised edit and not a transfer. Line numbers in this entry are from
 *     before that change and no longer resolve.
 *
 *     STILL OPEN, same shape, not repaired: `BlogController:150` `Blog::find($id)` and
 *     `ContactController::update()`'s bound `Contact`. Both are in the census at
 *     `docs/development/phase-2.5/tools/census-unowned-model-resolution.php` and neither has been
 *     driven by a request yet, so each is a SHAPE and not yet a finding.
 *     `WebhookController::update()` was already repaired earlier (P1-SEC-33, :111).
 *   - PageController::update()'s gate is `isAbleTo('Edit Menu')`, not 'Edit Page' -- and
 *     `resources/views/page/action.blade.php` gates the Edit BUTTON on `Edit Page`, so the two
 *     disagree. Left alone: changing which permission the handler demands is a business-rule
 *     change. Recorded for the owner.
 *   - PageController:63 `implode(',', $request->page_meta_keywords)` is a TypeError on PHP 8.3 for
 *     a valid post that omits the field, so the positive control below must send it.
 *   - WebhookController:34 reads `$store->id` BEFORE the validator, so a caller with no
 *     `current_store` 500s before any refusal.
 */
class ContentAndIntegrationRefusalNamesEveryFieldTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The seven owned files, with the measured number of REPAIRED refusal sites in each.
     *
     * ContactController carries TWO `getMessageBag()` sites and only ONE is repaired, and
     * AppSettingController carries one repaired site plus the `$validator->errors()->first()`
     * site the census misnamed. `test_every_owned_controller_flashes_the_whole_message_bag`
     * reconciles both against the file's total, so neither can drift silently.
     */
    private const OWNED = [
        'app/Http/Controllers/BlogController.php' => 2,
        'app/Http/Controllers/PageController.php' => 2,
        'app/Http/Controllers/ContactController.php' => 1,
        'app/Http/Controllers/PixelFieldsController.php' => 2,
        'app/Http/Controllers/WebhookController.php' => 2,
        'app/Http/Controllers/EmailTemplateController.php' => 1,
        'app/Http/Controllers/AppSettingController.php' => 1,
    ];

    /** Total `getMessageBag()` sites per owned file, repaired or not. */
    private const TOTAL_BAG_SITES = [
        'app/Http/Controllers/BlogController.php' => 2,
        'app/Http/Controllers/PageController.php' => 2,
        'app/Http/Controllers/ContactController.php' => 2,
        'app/Http/Controllers/PixelFieldsController.php' => 2,
        'app/Http/Controllers/WebhookController.php' => 2,
        'app/Http/Controllers/EmailTemplateController.php' => 1,
        'app/Http/Controllers/AppSettingController.php' => 1,
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant(['language' => 'en']);
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach ([
            'Manage Blog', 'Create Blog', 'Edit Blog',
            'Manage Page', 'Create Page', 'Edit Page', 'Edit Menu',
            'Manage Contact Us', 'Edit Contact Us',
            'Manage Store Setting',
        ] as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  0. PREMISES. Every claim this file makes about a route is measured from the router.
    // =============================================================================================

    /**
     * THE ELEVEN SITES ARE REACHABLE, AND THEY ARE THE ROUTES NAMED. A row assertion satisfied by
     * a 404 is the second of the five ways a test here passes for the wrong reason, and the skill's
     * census rule says to key on `getActionName()` rather than on a uri spelling -- so that is what
     * this does.
     */
    public function test_every_repaired_site_sits_on_a_live_route_reaching_the_named_handler(): void
    {
        $expected = [
            'blog.store' => ['POST', 'BlogController@store'],
            'blog.update' => ['PUT', 'BlogController@update'],
            'pages.store' => ['POST', 'PageController@store'],
            'pages.update' => ['PUT', 'PageController@update'],
            'contacts.update' => ['PUT', 'ContactController@update'],
            'pixel-setting.store' => ['POST', 'PixelFieldsController@store'],
            'pixel-setting.update' => ['PUT', 'PixelFieldsController@update'],
            'webhook.store' => ['POST', 'WebhookController@store'],
            'webhook.update' => ['PUT', 'WebhookController@update'],
            'updateEmail.settings' => ['PUT', 'EmailTemplateController@updateEmailSettings'],
            'site.setting' => ['POST', 'AppSettingController@SiteSetting'],
        ];

        $this->assertCount(11, $expected, 'the eleven repaired sites are no longer eleven');

        foreach ($expected as $name => [$method, $action]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "route {$name} does not exist, so nothing below drives it");
            $this->assertContains($method, $route->methods(),
                "route {$name} no longer answers {$method}");
            $this->assertStringEndsWith($action, $route->getActionName(),
                "route {$name} no longer reaches {$action}");

            /* AND IT IS THE MERCHANT SURFACE, not a public one: `auth` is gathered. This is the
               line that separates the ten repaired sites from ContactController::store(). */
            $this->assertContains('auth', $route->gatherMiddleware(),
                "route {$name} is not behind `auth`; it is a public surface and this group does "
                . 'not own public surfaces');
        }
    }

    /**
     * FOUR OF THE SEVEN HANDLERS HAVE NO PERMISSION GATE, which is why the disclosure question was
     * asked at all. Measured here so the paragraph about it in the docblock cannot go stale: these
     * routes gather no permission middleware, and their handlers hold no `isAbleTo` of their own.
     */
    public function test_the_ungated_handlers_really_are_ungated(): void
    {
        foreach ([
            'pixel-setting.store' => 'app/Http/Controllers/PixelFieldsController.php',
            'webhook.store' => 'app/Http/Controllers/WebhookController.php',
            'updateEmail.settings' => 'app/Http/Controllers/EmailTemplateController.php',
        ] as $name => $relative) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            $this->assertSame(
                ['web', 'auth', 'xss', 'setlocate', 'verified'],
                array_values(array_map('strval', $middleware)),
                "route {$name}'s middleware has changed; the disclosure argument in this file's "
                . 'docblock was measured against auth/xss/setlocate/verified and nothing else'
            );

            $this->assertStringNotContainsString('isAbleTo', $this->codeWithoutComments($relative),
                "{$relative} gained an isAbleTo() gate. That is an improvement, but this file "
                . 'states in writing that it has none -- update the docblock.');
        }
    }

    /**
     * THE FOUR MODELS THESE HANDLERS ADDRESS BY BARE ID ARE NOT SCOPED, which is the reported
     * (and NOT repaired) cross-tenant write. `StoreScope::isActiveFor()` short-circuits for a
     * model absent from `scoped_models`, so absence from that list is what decides.
     */
    public function test_the_models_these_handlers_address_by_bare_id_are_not_tenant_scoped(): void
    {
        $scoped = (array) config('tenancy.scoped_models');

        $this->assertNotEmpty($scoped, 'premise: scoped_models is configured at all');

        foreach ([Blog::class, Page::class, Contact::class, PixelFields::class] as $model) {
            $this->assertNotContains($model, $scoped,
                "{$model} is now on scoped_models. The cross-tenant note in this file's docblock "
                . 'is stale -- re-read it before trusting it.');
        }
    }

    // =============================================================================================
    //  1. THE OBSERVABLE TEN -- driven over HTTP, every failing field named
    // =============================================================================================

    /** @dataProvider observableRefusals */
    public function test_an_observable_refusal_flashes_every_failing_field(
        string $case
    ): void {
        [$route, $method, $params, $payload, $rules, $messages, $expectedCount, $fragments]
            = $this->observableCase($case);

        $flash = $this->refusalFlash($route, $method, $params, $payload);

        $bag = \Validator::make($payload, $rules, $messages)->getMessageBag();

        $this->assertSame($expectedCount, $bag->count(),
            "{$case}: the measured bag size has changed, so the expectation below no longer "
            . 'describes this validator. Re-measure before trusting this test.');

        $this->assertSame(implode(' ', $bag->all()), $flash,
            "{$case} does not flash the whole message bag joined by one space");

        /* AND SAID AS THE MERCHANT READS IT: every field is named, which is what
           $messages->first() threw away. */
        foreach ($fragments as $fragment) {
            $this->assertStringContainsString($fragment, $flash,
                "{$case}'s refusal does not name '{$fragment}', so the merchant still has to guess");
        }

        /* THE CONTROL ON THE CONTROL: first() really did hide them. If this stops holding, the
           defect being closed is not the defect that was measured. */
        $this->assertNotSame($flash, (string) $bag->first(),
            "{$case}: first() and the join are the same string, so this site is NOT observable "
            . 'and belongs in the inert section instead');
    }

    public static function observableRefusals(): array
    {
        return [
            'blog store (4)' => ['blog.store'],
            'blog update (4)' => ['blog.update'],
            'page store (2)' => ['pages.store'],
            'page update (2)' => ['pages.update'],
            'contact update (6)' => ['contacts.update'],
            'pixel store (2)' => ['pixel-setting.store'],
            'pixel update (2)' => ['pixel-setting.update'],
            'webhook store (3)' => ['webhook.store'],
            'webhook store, duplicate module (3)' => ['webhook.store.duplicate'],
            'webhook update (3)' => ['webhook.update'],
            'email template (3)' => ['updateEmail.settings'],
        ];
    }

    /**
     * The eleven driven cases, each with the rule set copied from the handler's own
     * `Validator::make` call and the measured bag size.
     *
     * @return array{0:string,1:string,2:array,3:array,4:array,5:array,6:int,7:array}
     */
    private function observableCase(string $case): array
    {
        $blogRules = [
            'title' => 'required',
            'short_description' => 'required',
            'content' => 'required',
            'category_id' => 'required',
            'cover_image' => 'nullable',
        ];
        $blogMessages = ['category_id.required' => __('Please select category')];
        $blogFields = ['title', 'short description', 'content', 'category'];

        $contactRules = [
            'first_name' => 'required',
            'last_name' => 'required',
            'email' => 'required',
            'contact' => 'required',
            'subject' => 'required',
            'description' => 'required',
        ];
        $contactFields = ['first name', 'last name', 'email', 'contact', 'subject', 'description'];

        $pixelRules = ['platform' => 'required', 'pixel_id' => 'required'];
        $pixelFields = ['platform', 'pixel id'];

        switch ($case) {
            case 'blog.store':
                /* `cover_image => nullable` is in the rule set and can never emit a message, which
                   is why the bag is FOUR and not the five rule keys the census counted. */
                return ['blog.store', 'post', [], [], $blogRules, $blogMessages, 4, $blogFields];

            case 'blog.update':
                $blog = Blog::forceCreate([
                    'title' => 'MINE', 'slug' => 'mine-' . uniqid(),
                    'short_description' => 'S', 'content' => 'C',
                    'category_id' => $this->blogCategory()->id, 'store_id' => $this->store->id,
                ]);

                $rules = $blogRules;
                unset($rules['cover_image']);

                return ['blog.update', 'put', ['blog' => $blog->id], [],
                    $rules, $blogMessages, 4, $blogFields];

            case 'pages.store':
                return ['pages.store', 'post', [], [],
                    ['page_name' => 'required', 'page_slug' => 'required'], [], 2,
                    ['page name', 'page slug']];

            case 'pages.update':
                $page = Page::forceCreate([
                    'page_name' => 'Mine', 'page_slug' => 'mine-' . uniqid(),
                    'store_id' => $this->store->id,
                ]);

                return ['pages.update', 'put', ['page' => $page->id], [],
                    ['page_name' => 'required', 'page_slug' => 'required'], [], 2,
                    ['page name', 'page slug']];

            case 'contacts.update':
                $contact = Contact::forceCreate([
                    'first_name' => 'Mine', 'last_name' => 'Row', 'email' => 'mine@example.test',
                    'contact' => '123', 'subject' => 'S', 'description' => 'D',
                    'store_id' => $this->store->id,
                ]);

                return ['contacts.update', 'put', ['contact' => $contact->id], [],
                    $contactRules, [], 6, $contactFields];

            case 'pixel-setting.store':
                return ['pixel-setting.store', 'post', [], [], $pixelRules, [], 2, $pixelFields];

            case 'pixel-setting.update':
                $pixel = PixelFields::forceCreate([
                    'platform' => 'facebook', 'pixel_id' => 'PX-1', 'store_id' => $this->store->id,
                ]);

                return ['pixel-setting.update', 'put', ['pixel_setting' => $pixel->id], [],
                    $pixelRules, [], 2, $pixelFields];

            case 'webhook.store':
                return ['webhook.store', 'post', [], [], $this->webhookRules(null), [], 3,
                    ['module', 'method', 'webbbook url']];

            case 'webhook.store.duplicate':
                /* THE ONE CASE WHERE `first()` WAS NOT A `required` MESSAGE. `unique` fires first,
                   so the merchant used to be told only that the module was taken -- and never that
                   the method and the address were missing too. The rule is already scoped to the
                   caller's own store_id, so the join adds only the caller's own omitted fields. */
                Webhook::forceCreate([
                    'module' => 'New Order', 'url' => 'https://a.test', 'method' => 'POST',
                    'store_id' => $this->store->id,
                ]);

                return ['webhook.store', 'post', [], ['module' => 'New Order'],
                    $this->webhookRules(null), [], 3,
                    ['already been taken', 'method', 'webbbook url']];

            case 'webhook.update':
                $webhook = Webhook::forceCreate([
                    'module' => 'New Product', 'url' => 'https://b.test', 'method' => 'POST',
                    'store_id' => $this->store->id,
                ]);

                return ['webhook.update', 'put', ['webhook' => $webhook->id], [],
                    $this->webhookRules($webhook->id), [], 3,
                    ['module', 'method', 'webbbook url']];

            case 'updateEmail.settings':
                $template = $this->emailTemplate();

                return ['updateEmail.settings', 'put', ['id' => $template->id], [],
                    ['from' => 'required', 'subject' => 'required', 'content' => 'required'], [], 3,
                    ['from', 'subject', 'content']];
        }

        $this->fail("unknown observable case {$case}");
    }

    // =============================================================================================
    //  2. THE INERT ONE -- byte-identical. This is the negative control.
    // =============================================================================================

    /**
     * `AppSettingController::SiteSetting()` carries exactly one rule, so no request can make its
     * bag hold a second message and the join is provably the same bytes as `first()`.
     *
     * This assertion passes BEFORE and AFTER the change by design -- that is what makes it the
     * control. A repair satisfied by something that concatenates junk, pads, or re-separates a
     * one-message bag dies here, and so does one that changed the wording on a merchant screen by
     * accident. The EXPRESSION for this site is pinned in section 4 instead.
     */
    public function test_the_inert_site_setting_refusal_flashes_exactly_one_unpadded_message(): void
    {
        $flash = $this->refusalFlash('site.setting', 'post', [], ['app_setting_tab' => 'pills-home-tab']);

        $bag = \Validator::make([], ['date_format' => 'required'])->getMessageBag();

        $this->assertSame(1, $bag->count(),
            'date_format is no longer the only rule in SiteSetting, so this site is no longer '
            . 'inert and needs the driven multi-message assertion the other ten get');

        $this->assertSame((string) $bag->first(), $flash,
            'site-setting no longer flashes exactly the validator\'s own message. A join that '
            . 'pads, reorders or re-separates a one-message bag is a user-visible wording change '
            . 'on a merchant screen that was not meant to change at all.');

        $this->assertSame($flash, trim($flash), 'the joined single message gained outer padding');
        $this->assertSame(1, substr_count($flash, '.'),
            'the single-rule refusal now carries more than one sentence');

        /* AND THE BAG STILL REACHES THE PAGE. This site already had withErrors()/withInput() from
           the earlier P2.5 Class A pass, and ProfileValidationFeedbackTest:378 asserts it. The
           expression change must not have displaced either. */
        $this->assertNotEmpty(
            session('errors') ? session('errors')->getBag('default')->get('date_format') : [],
            'site-setting stopped flashing a real error bag, which AppSetting/mobile_content.blade.php '
            . 'needs for its @error blocks'
        );
    }

    // =============================================================================================
    //  3. THE NEGATIVE CONTROL ON A CHANGED LINE, and the positive controls
    // =============================================================================================

    /**
     * A SINGLE-MESSAGE REFUSAL ON A LINE THIS CHANGE REALLY TOUCHED. `BlogController::store()` is
     * one of the repaired sites, so this is the assertion that catches a join which pads or
     * re-separates. The payload supplies everything but `category_id`, whose bag is measured at
     * exactly ONE -- and whose message is the controller's own custom string
     * `__('Please select category')`, which carries NO full stop. A trailing separator or an added
     * period would be visible immediately.
     */
    public function test_a_one_message_refusal_on_a_repaired_line_is_byte_for_byte_unchanged(): void
    {
        $payload = ['title' => 'T', 'short_description' => 'S', 'content' => 'C'];

        $flash = $this->refusalFlash('blog.store', 'post', [], $payload);

        $bag = \Validator::make($payload, [
            'title' => 'required', 'short_description' => 'required', 'content' => 'required',
            'category_id' => 'required', 'cover_image' => 'nullable',
        ], ['category_id.required' => __('Please select category')])->getMessageBag();

        $this->assertSame(1, $bag->count(), 'premise: only category_id fails for this payload');

        $this->assertSame((string) $bag->first(), $flash,
            'a one-message bag on a REPAIRED line no longer flashes exactly the validator message');
        $this->assertSame($flash, trim($flash), 'the single message gained outer padding');
        $this->assertSame(0, substr_count($flash, '.'),
            'the custom category message has no full stop; a period here means the join added one');

        $this->assertSame(0, Blog::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'the refused blog was created anyway');
    }

    /**
     * A VALID POST STILL SUCCEEDS. The positive control for the trap: this change may touch the
     * WORDING of a refusal that already happened, never WHETHER a refusal happens. If a dormant
     * validator had been given a `fails()` guard, or a rule had been tightened, these rows would
     * stop being created.
     */
    public function test_a_valid_post_still_succeeds_on_the_screens_this_change_touched(): void
    {
        /* PAGES. `page_meta_keywords[]` is sent on purpose: PageController:63 does
           `implode(',', $request->page_meta_keywords)`, which is a TypeError on PHP 8.3 for a
           valid post that omits the field. That is a SEPARATE pre-existing defect this slice does
           not own -- repairing it would change whether a post succeeds. */
        $this->assertSuccess('pages.store', 'post', [], [
            'page_name' => 'About', 'page_slug' => 'about',
            'page_meta_keywords' => ['store', 'syria'],
        ]);
        $this->assertSame(1, Page::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'pages.store did not create the row for a valid post');

        /* BLOG. */
        $this->assertSuccess('blog.store', 'post', [], [
            'title' => 'Hello', 'short_description' => 'S', 'content' => 'C',
            'category_id' => $this->blogCategory()->id,
        ]);
        $this->assertSame(1, Blog::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'blog.store did not create the row for a valid post');

        /* PIXEL. */
        $this->assertSuccess('pixel-setting.store', 'post', [], [
            'platform' => 'facebook', 'pixel_id' => 'PX-9',
        ]);
        $this->assertSame(1, PixelFields::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'pixel-setting.store did not create the row for a valid post');

        /* SITE SETTING -- the inert site, whose expression also changed. */
        $this->assertSuccess('site.setting', 'post', [], [
            'app_setting_tab' => 'pills-home-tab', 'date_format' => 'd-m-Y',
        ]);
        $this->assertSame('d-m-Y', (string) \DB::table('settings')
            ->where('name', 'date_format')->where('store_id', $this->store->id)->value('value'),
            'site-setting did not persist a valid date_format');

        /* EMAIL TEMPLATE. */
        $template = $this->emailTemplate();
        $this->assertSuccess('updateEmail.settings', 'put', ['id' => $template->id], [
            'from' => 'noreply@example.test', 'subject' => 'Hi', 'content' => 'Body',
            'language' => 'en', 'lang' => 'en',
        ]);
        $this->assertSame('noreply@example.test', (string) \DB::table('email_templates')
            ->where('id', $template->id)->value('from'),
            'the email template update did not persist for a valid post');
    }

    /**
     * WEBHOOK'S POSITIVE CONTROL STOPS ONE STATEMENT PAST THE VALIDATOR, and deliberately so.
     * `WebhookController::store()` hands the address to `SafeWebhook::allows()` immediately after
     * the refusal branch, and that judge resolves DNS for a named host -- not something to drive
     * in a unit-test container. A NUMERIC host is refused without any lookup, so this posts a
     * complete, valid payload and asserts the refusal that comes back is the ADDRESS one and
     * carries no validation text at all. That proves the validator passed, which is the only thing
     * the trap needs proving.
     */
    public function test_a_valid_webhook_payload_passes_the_validator(): void
    {
        $response = $this->actingAs($this->merchant)
            ->from(route('webhook.index'))
            ->post(route('webhook.store'), [
                'module' => 'New Order', 'method' => 'POST',
                'webbbook_url' => 'http://127.0.0.1/hook',
            ]);

        $this->assertSame(302, $response->status());

        $flash = (string) $response->getSession()->get('error');

        $this->assertStringContainsString('public http or https address', $flash,
            'webhook.store did not reach SafeWebhook, so the validator refused a valid payload');
        $this->assertStringNotContainsString('is required', $flash,
            'webhook.store refused a VALID payload on validation. The repair was supposed to '
            . 'change the message of an existing refusal, not create a new one.');
        $this->assertStringNotContainsString('already been taken', $flash,
            'the unique rule fired for a module the caller has not used');
    }

    /**
     * THE WIDENED MESSAGE MAY NOT REACH AN UNAUTHORISED CALLER on the three handlers that do have
     * a gate. Each keeps its `isAbleTo()` check ABOVE the validator, so a staff member holding
     * only the read permission gets `Permission denied.` and not one word of validation text --
     * which matters most for `contacts.update`, whose bag is six field names.
     */
    public function test_a_staff_user_without_the_permission_never_sees_the_widened_message(): void
    {
        $staff = $this->makeStaff(User::find($this->merchant->id), [
            'current_store' => $this->store->id, 'language' => 'en',
        ]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');
        $this->grantPermission(User::find($staff->id), 'Manage Page');
        $this->grantPermission(User::find($staff->id), 'Manage Contact Us');
        $staff = User::find($staff->id);

        $contact = Contact::forceCreate([
            'first_name' => 'Mine', 'last_name' => 'Row', 'email' => 'mine@example.test',
            'contact' => '123', 'subject' => 'S', 'description' => 'D',
            'store_id' => $this->store->id,
        ]);
        $page = Page::forceCreate([
            'page_name' => 'Mine', 'page_slug' => 'mine-' . uniqid(), 'store_id' => $this->store->id,
        ]);

        foreach ([
            ['blog.store', 'post', [], 'blog.index'],
            ['pages.store', 'post', [], 'pages.index'],
            ['pages.update', 'put', ['page' => $page->id], 'pages.index'],
            ['contacts.update', 'put', ['contact' => $contact->id], 'contacts.index'],
        ] as [$route, $method, $params, $index]) {
            $response = $this->actingAs($staff)
                ->from(route($index))
                ->{$method}(route($route, $params), []);

            $this->assertSame(302, $response->status(), "{$route} did not redirect for staff");

            $flash = (string) $response->getSession()->get('error');

            $this->assertStringContainsString('Permission denied', $flash,
                "{$route} let a staff user without the write permission past the gate");
            $this->assertStringNotContainsString('is required', $flash,
                "{$route} leaked validation text -- now every field name at once -- to a "
                . 'principal with no write permission. The gate must stay above the validator.');
        }
    }

    // =============================================================================================
    //  4. THE RATCHET over all eleven sites, and the two sites deliberately left
    // =============================================================================================

    /**
     * No owned controller may go back to `$messages->first()` at a repaired site, and each must
     * carry the join at every one of them. Asserted on the comment-stripped source because one of
     * the eleven is provably inert: a behavioural assertion for that one passes identically before
     * and after, so the expression is what gets pinned.
     */
    public function test_every_owned_controller_flashes_the_whole_message_bag(): void
    {
        $total = 0;

        foreach (self::OWNED as $relative => $repaired) {
            $source = $this->codeWithoutComments($relative);

            /* THE PREMISE: the measured site count still covers the file. A newly added validator
               refusal would otherwise slip past both assertions below. */
            $this->assertSame(self::TOTAL_BAG_SITES[$relative], substr_count($source, 'getMessageBag()'),
                "{$relative} no longer has exactly " . self::TOTAL_BAG_SITES[$relative]
                . ' validator refusal sites, so the counts below no longer cover the file. '
                . 'Re-read the handler before trusting this test.');

            $this->assertSame($repaired, substr_count($source, "implode(' ', \$messages->all())"),
                "all {$repaired} repaired refusals in {$relative} must flash the JOINED message bag");

            /* AND THE FLASH KEY AND REDIRECT ARE UNCHANGED at every repaired site. */
            $this->assertSame($repaired,
                substr_count($source, "->with('error', implode(' ', \$messages->all()))"),
                "{$relative} changed the flash key or the redirect at a validation refusal");

            $leftBehind = self::TOTAL_BAG_SITES[$relative] - $repaired;

            $this->assertSame($leftBehind, substr_count($source, "->with('error', \$messages->first())"),
                "{$relative} has " . substr_count($source, "->with('error', \$messages->first())")
                . " sites still flashing only the FIRST validation message; exactly {$leftBehind} "
                . 'is expected (the public storefront contact form, which is Phase 03). Every '
                . 'other one leaves failing fields unnamed -- WCAG 3.3.1.');

            $total += $repaired;
        }

        $this->assertSame(11, $total, 'the eleven measured refusal sites are no longer eleven');
    }

    /**
     * THE CENSUS CORRECTION, PINNED. `AppSettingController:588` is not the described site: it reads
     * `$validator->errors()->first()` -- `$messages` does not exist in `ThemeSettings()` -- it is
     * already followed by `withErrors()`/`withInput()`, and its one-field validator is guarded by
     * `if ($request->store_slug)`, which makes the `required` limb unreachable and the bag always
     * exactly 1. Repairing it would mean a SECOND expression shape for a provably byte-identical
     * site, so it is left as it was and that is asserted rather than assumed.
     */
    public function test_the_theme_settings_site_the_census_misnamed_is_left_exactly_as_it_was(): void
    {
        $source = $this->codeWithoutComments('app/Http/Controllers/AppSettingController.php');

        $this->assertSame(1, substr_count($source, "->with('error', \$validator->errors()->first())"),
            'AppSettingController no longer carries the ThemeSettings store_slug refusal in the '
            . 'shape this file measured. Re-read :588 before trusting the census correction.');

        $this->assertSame(0, substr_count($source, "\$validator->errors()->all()"),
            'a SECOND join idiom appeared in AppSettingController. The established repair is '
            . "implode(' ', \$messages->all()) and only that.");

        /* AND ITS BAG REALLY IS ALWAYS ONE, for every spelling the guard admits.
           ONLY TRUTHY SPELLINGS ARE DRIVEN, and that is the point of the guard: `if
           ($request->store_slug)` at :566 is PHP truthiness, so '', '0', null and an absent key
           skip the block entirely and the `required` limb is unreachable from a real request.
           (A first draft of this test asserted bag == 1 for '0' and FAILED with bag == 0 -- '0'
           satisfies `required` AND matches the regex. The correction is this comment.) */
        $rules = ['store_slug' => ['required', 'regex:/^[a-z0-9-]+$/']];

        foreach ([
            ['store_slug' => 'Bad Slug!'],
            ['store_slug' => ['a']],
            ['store_slug' => 'UPPER'],
        ] as $payload) {
            $this->assertSame(1, \Validator::make($payload, $rules)->getMessageBag()->count(),
                'the store_slug validator can now hold more than one message, so :588 has become '
                . 'OBSERVABLE and needs repairing after all -- which needs a decision about which '
                . 'expression to use, because there is no $messages variable there.');
        }
    }

    /**
     * THE PUBLIC STOREFRONT CONTACT FORM IS STILL BROKEN, ON PURPOSE. Its bag is SIX and only the
     * first message is shown, which is a real, user-visible WCAG 3.3.1 failure -- but it is the
     * Phase 03 storefront, not Phase 02 Merchant Experience, so it is classified and left. This
     * pins all three facts so that "left alone" cannot quietly become "quietly fixed" or "quietly
     * forgotten".
     */
    public function test_the_public_storefront_contact_form_is_deliberately_left_for_phase_03(): void
    {
        $route = Route::getRoutes()->getByName('contacts.store');

        $this->assertNotNull($route, 'premise: contacts.store exists');
        $this->assertSame('contacts/{slug?}', $route->uri(),
            'contacts.store is no longer the storefront re-declaration at routes/web.php:1554');
        $this->assertSame(['web'], array_values(array_map('strval', $route->gatherMiddleware())),
            'contacts.store gained middleware. If it is now behind `auth` it has become a '
            . 'merchant surface and belongs in this repair after all.');

        $this->assertContains('contacts.store', (new \ReflectionClass(
            \Tests\Feature\Security\UnauthenticatedRouteBoundaryTest::class
        ))->getConstant('PUBLIC_ROUTE_ALLOWLIST'),
            'contacts.store left the public-route allowlist, so it is no longer public and this '
            . 'exemption no longer applies');

        /* AND THE DEFECT IS REAL: six messages, one shown. Measured, not asserted away. */
        $bag = \Validator::make([], [
            'first_name' => 'required', 'last_name' => 'required', 'email' => 'required',
            'contact' => 'required', 'subject' => 'required', 'description' => 'required',
        ])->getMessageBag();

        $this->assertSame(6, $bag->count());
        $this->assertSame(1, substr_count((string) $bag->first(), '.'),
            'the storefront form still discards five of its six messages (Phase 03)');
    }

    // =============================================================================================
    //  5. THE TRAP
    // =============================================================================================

    /**
     * NO DORMANT VALIDATOR WAS ACTIVATED. `AppSettingController` builds SIX validators and consults
     * TWO; the other six owned files build and consult the same number, so every site this change
     * touched was already a live refusal branch and only its MESSAGE moved.
     *
     * Adding a `fails()` guard to a dormant validator would start REFUSING posts that succeed
     * today -- an empty Facebook pixel, a blank store_state, a cleared custom domain -- which is a
     * business-rule change needing an owner decision, and it would redden
     * tests/Feature/Security/HostClaimsTest.php:490/:510.
     */
    public function test_no_dormant_validator_was_activated_in_any_owned_file(): void
    {
        foreach (array_keys(self::OWNED) as $relative) {
            $source = $this->codeWithoutComments($relative);

            $built = substr_count($source, 'Validator::make(');
            $consulted = substr_count($source, '->fails()');

            if ($relative === 'app/Http/Controllers/AppSettingController.php') {
                $this->assertSame(6, $built,
                    'AppSettingController no longer builds six validators; re-measure the '
                    . 'dormant split before trusting the 6/2 claim');
                $this->assertSame(2, $consulted,
                    'AppSettingController now consults ' . $consulted . ' validators, not two. '
                    . 'Somebody activated a dormant one: that REFUSES posts that succeed today '
                    . '(an empty Facebook pixel, a blank store_state) and removes the merchant\'s '
                    . 'ability to blank a custom domain, which HostClaimsTest:490/:510 protect.');
                continue;
            }

            $this->assertSame($built, $consulted,
                "{$relative} builds {$built} validators and consults {$consulted}. A validator "
                . 'built and never checked is dormant: giving it a fails() guard would start '
                . 'REFUSING posts that succeed today, which is a business-rule change and not '
                . 'this repair.');
        }
    }

    /** The response shape is untouched on the six files that had no bag before this change. */
    public function test_the_refusal_shape_did_not_change_on_the_six_modal_controllers(): void
    {
        foreach (array_keys(self::OWNED) as $relative) {
            if ($relative === 'app/Http/Controllers/AppSettingController.php') {
                /* AppSettingController is the exception and was BEFORE this change: the earlier
                   P2.5 Class A pass added withErrors()/withInput() to both its consulted sites,
                   and ProfileValidationFeedbackTest:343/:378 assert them. */
                continue;
            }

            $source = $this->codeWithoutComments($relative);

            foreach (['withErrors(', 'withInput(', 'expectsJson('] as $needle) {
                $this->assertSame(0, substr_count($source, $needle),
                    "{$relative} started calling {$needle}. Nothing in this slice adds a second "
                    . 'channel: the modal-preserving redesign is Phase 2.5, and a bare withInput() '
                    . 'is refused by tests/Feature/Security/CredentialSinksTest.php:260.');
            }
        }
    }

    // =============================================================================================
    //  helpers
    // =============================================================================================

    /**
     * A controller's source with every comment REMOVED, via `token_get_all()`.
     *
     * The project skill's rule: "strip comment lines from any source-grep ratchet -- a docblock
     * quoting the string a ratchet greps for makes the ratchet satisfiable by prose". These seven
     * files are heavily commented, every assertion above is a COUNT, and this very file's docblock
     * quotes both needles -- so a future comment in the CONTROLLER could hold a count at its
     * expected value while a real refusal site was deleted. Tokenising is exact rather than
     * regex-guessed: T_COMMENT and T_DOC_COMMENT are dropped and nothing inside a string literal
     * is touched.
     */
    private function codeWithoutComments(string $relative): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($relative))) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }

            $code .= $token;
        }

        return $code;
    }

    /**
     * Drive a refusal and hand back the flashed `error` string, having first proved the premise:
     * a 302 that is neither a 404, nor a 500, nor the permission branch.
     */
    private function refusalFlash(string $route, string $method, array $params, array $payload): string
    {
        $response = $this->actingAs($this->merchant)
            ->{$method}(route($route, $params), $payload);

        $this->assertNotSame(404, $response->status(),
            "{$route} answered 404, so the handler was never reached and nothing below measures "
            . 'anything (way 2 of the five false greens)');
        $this->assertNotSame(500, $response->status(),
            "{$route} answered 500, so this is not the validation branch");
        $this->assertSame(302, $response->status(),
            "{$route} did not redirect; the refusal branch returns redirect()->back()");

        $flash = (string) $response->getSession()->get('error');

        $this->assertNotSame('', $flash,
            "{$route} flashed nothing at all, so this is not the validation branch");
        $this->assertStringNotContainsString('Permission denied', $flash,
            "{$route} answered with the PERMISSION refusal, not the validation one -- the fixture "
            . 'is missing a permission and the test is measuring the wrong return');

        return $flash;
    }

    /** Drive a VALID request and prove it was accepted. */
    private function assertSuccess(string $route, string $method, array $params, array $payload): void
    {
        $response = $this->actingAs($this->merchant)
            ->{$method}(route($route, $params), $payload);

        $this->assertSame(302, $response->status(), "{$route} did not redirect for a valid post");

        $this->assertSame('', (string) $response->getSession()->get('error'),
            "{$route} refused a VALID post with: " . (string) $response->getSession()->get('error')
            . '. The repair was supposed to change the message of an existing refusal, not create '
            . 'a new one.');
        $this->assertNotSame('', (string) $response->getSession()->get('success'),
            "{$route} did not report success for a valid post");
    }

    /** `module` is unique per store, so the rule string carries the caller's own store id. */
    private function webhookRules(?int $ignoreId): array
    {
        return [
            'module' => 'required|unique:webhooks,module,' . ($ignoreId ?? 'NULL')
                . ',id,store_id,' . $this->store->id,
            'method' => 'required',
            'webbbook_url' => 'required',
        ];
    }

    private function blogCategory(): BlogCategory
    {
        /* MEASURED, not copied from the model's $fillable: `blog_categories` is
           (id, name, status, store_id, timestamps) -- there is no `slug` column, and `status` is
           `tinyInteger()` with NO default, i.e. NOT NULL, so it has to be supplied. */
        return BlogCategory::forceCreate([
            'name' => 'News ' . uniqid(),
            'status' => 1,
            'store_id' => $this->store->id,
        ]);
    }

    private function emailTemplate(): EmailTemplate
    {
        return EmailTemplate::forceCreate([
            'name' => 'New Order ' . uniqid(),
            'from' => 'old@example.test',
            'created_by' => $this->merchant->id,
        ]);
    }
}
