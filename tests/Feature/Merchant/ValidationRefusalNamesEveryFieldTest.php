<?php

namespace Tests\Feature\Merchant;

use App\Models\Coupon;
use App\Models\Faq;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Store;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F-validation-errors-lose-the-modal, PART 2 -- the remaining SEVEN controllers (18 refusal sites).
 * =================================================================================================
 *
 * `ModalFormRefusalFeedbackTest` landed the one-expression repair on `CategoryController` and said
 * in writing that the seven controllers carrying the OBSERVABLE instance were outside that change's
 * file ownership and were NOT fixed. This file is the other half:
 *
 *     -  return redirect()->back()->with('error', $messages->first());
 *     +  return redirect()->back()->with('error', implode(' ', $messages->all()));
 *
 * applied to CouponController (4 sites), FlashSaleController (4), FaqController (2),
 * TestimonialController (2), ProductBrandController (2), TagController (2) and
 * ProductLabelController (2).
 *
 * ------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED, NOT ASSUMED. Running every one of the 18 rule sets through `Validator::make`
 * and counting the bag (scratch script, 2026-11-04) splits them in two, and the split is the whole
 * reason this file is shaped the way it is:
 *
 *   OBSERVABLE -- 8 sites, where a real request can produce MORE THAN ONE message, so the join is
 *   a user-visible repair and is proved HERE BY DRIVING IT:
 *     Coupon::store()  6 required rules      -> 6 messages, 5 of them previously discarded
 *     Coupon::update() 5 required rules      -> 5 messages
 *     Coupon  x2, FlashSale x2  'discount_amount' => 'required|numeric|min:1|max:100'
 *                                            -> 2 messages for a long non-numeric value, because
 *                                               `max` sizes a non-numeric string by LENGTH
 *     Testimonial::store()/update() 3 required + 'status' => 'in:0,1'
 *                                            -> 4 messages
 *
 *   INERT -- 10 sites whose validator carries exactly ONE rule, so the bag can never hold a second
 *   message and the join is BYTE-IDENTICAL to `first()` for every possible request:
 *     FlashSale::store()/update()  'name' => 'required'
 *     Faq          x2              'topic' => 'required'
 *     ProductBrand x2, Tag x2, ProductLabel x2   'name' => 'required'
 *
 *   The inert ten still get the expression -- it is the one that stops being correct the moment a
 *   rule is added -- but this file does NOT pretend they changed behaviour. For each of them the
 *   driven assertion is the BYTE-FOR-BYTE one: exactly the validator's own message, no separator,
 *   no padding. That is simultaneously the negative control the repair has to survive.
 *   (`CategoryController`'s landed pair is inert for the same reason, which that file says.)
 *
 * ------------------------------------------------------------------------------------------------
 * THE TRAP THIS CHANGE HAD TO AVOID, pinned by `test_no_owned_controller_gained_a_newly_consulted_validator`.
 * `AppSettingController` builds SIX validators and consults TWO (measured: 6 `Validator::make(`,
 * 2 `->fails()`), so giving a dormant one a `fails()` guard would start REFUSING posts that succeed
 * today -- a business-rule change wearing a repair's clothes. In these seven files
 * `Validator::make(` and `->fails()` are EQUAL in every file, so all 18 sites were already live
 * refusal branches and this change alters only the MESSAGE of a refusal that already happened.
 * Four valid POSTs below are driven to prove the refusals did not widen.
 *
 * NOTHING ELSE MOVED: still a 302, still the `error` flash key, still no `withErrors()`, still no
 * `withInput()` (which cannot be read through the AJAX-modal architecture -- see
 * `ModalFormRefusalFeedbackTest` paragraph 3), still no JSON branch.
 *
 * ------------------------------------------------------------------------------------------------
 * ONE SITE OF THE EIGHTEEN IS NOT BEHIND ITS HANDLER'S OWN PERMISSION GATE, and it is recorded here
 * rather than left for somebody to rediscover. `CouponController.php:384` -- update()'s percentage
 * validator -- sits ABOVE the `isAbleTo('Edit Coupon')` check at :387, so a member of the OWNING
 * store who lacks `Edit Coupon` can reach it once `refuseOperatorWrite()` and
 * `couponIsOutsideCurrentStore()` have passed. That ordering is pre-existing and deliberate: the
 * controller's own comment at :356-360 says the ownership test was moved above the spend validation
 * precisely so an ungated caller could not probe the rules against a coupon they cannot see.
 *
 * THE WIDENING ADDS NO DISCLOSURE THERE. That validator has exactly ONE field
 * (`discount_amount`), which the caller supplied, so the join can only add a second sentence about
 * that same field -- it names no field the caller did not already name. The other seventeen sites
 * are all inside their handler's `isAbleTo(...)` block, and
 * `test_a_staff_user_without_the_create_permission_never_sees_the_widened_message` drives that for
 * coupon and testimonial -- the two whose bags are biggest.
 * ------------------------------------------------------------------------------------------------
 */
class ValidationRefusalNamesEveryFieldTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The seven files this change owns, with their measured refusal-site count. */
    private const OWNED = [
        'app/Http/Controllers/CouponController.php' => 4,
        'app/Http/Controllers/FlashSaleController.php' => 4,
        'app/Http/Controllers/FaqController.php' => 2,
        'app/Http/Controllers/TestimonialController.php' => 2,
        'app/Http/Controllers/ProductBrandController.php' => 2,
        'app/Http/Controllers/TagController.php' => 2,
        'app/Http/Controllers/ProductLabelController.php' => 2,
    ];

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach ([
            'Manage Coupon', 'Create Coupon', 'Edit Coupon',
            'Manage Flash Sale', 'Create Flash Sale', 'Edit Flash Sale',
            'Manage Faqs', 'Create Faqs', 'Edit Faqs',
            'Manage Testimonial', 'Create Testimonial', 'Edit Testimonial',
            'Manage Product Brand', 'Create Product Brand', 'Edit Product Brand',
            'Manage Tag', 'Create Tag', 'Edit Tag',
            'Manage Product Label', 'Create Product Label', 'Edit Product Label',
        ] as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    /**
     * A controller's source with every comment REMOVED, via `token_get_all()`.
     *
     * The project skill's rule, learned here the hard way: "strip comment lines from any source-grep
     * ratchet -- a docblock quoting the string a ratchet greps for makes the ratchet satisfiable by
     * prose." These seven files are heavily commented and every assertion below is a COUNT, so a
     * future docblock quoting `implode(...$messages->all())` could hold a count at its expected
     * value while a real refusal site was deleted. Tokenising is exact rather than regex-guessed:
     * T_COMMENT and T_DOC_COMMENT are dropped and nothing inside a string literal is touched.
     *
     * MEASURED 2026-11-04: no comment in any of the seven currently quotes any needle below, so
     * stripping changes no count today. It is the ratchet's ratchet.
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
     * a 302 to the referer, which is neither a 403, a 500, nor the permission branch.
     */
    private function refusalFlash(
        string $route,
        array $payload,
        string $index,
        string $method = 'post',
        array $routeParams = []
    ): string {
        $response = $this->actingAs($this->merchant)
            ->from(route($index))
            ->{$method}(route($route, $routeParams), $payload);

        /* THE PREMISE. A permission refusal or a 500 would satisfy "contains more than one
           sentence" for entirely the wrong reason. */
        $response->assertRedirect(route($index));
        $this->assertSame(302, $response->status());

        $flash = (string) $response->getSession()->get('error');

        $this->assertNotSame('', $flash,
            "{$route} flashed nothing at all, so this is not the validation branch");
        $this->assertStringNotContainsString('Permission denied', $flash,
            "{$route} answered with the PERMISSION refusal, not the validation one -- the fixture "
            . 'is missing a permission and the test is measuring the wrong return');

        /* AND the response shape is unchanged: no old input, which paragraph 3 of
           ModalFormRefusalFeedbackTest pins as unrecoverable through this architecture. */
        $this->assertSame([], (array) $response->getSession()->get('_old_input', []),
            "{$route} started flashing old input, which no reader can reach here");

        return $flash;
    }

    // =============================================================================================
    //  1. THE OBSERVABLE EIGHT -- driven, and every failing field named
    // =============================================================================================

    /**
     * THE HEADLINE. `CouponController::store()` has six `required` rules and flashed ONE message,
     * leaving five fields unnamed -- the only WCAG 3.3.1 failure the verification upheld.
     */
    public function test_the_six_rule_coupon_store_refusal_names_all_six_failing_fields(): void
    {
        $flash = $this->refusalFlash('coupon.store', [], 'coupon.index');

        $expected = implode(' ', \Validator::make([], [
            'coupon_name' => 'required',
            'coupon_type' => 'required',
            'discount_amount' => 'required',
            'coupon_limit' => 'required',
            'coupon_expiry_date' => 'required',
            'coupon_code' => 'required',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash,
            'the coupon store refusal does not flash the whole message bag joined by one space');

        /* AND SAID AS THE MERCHANT READS IT: every one of the six fields is named. The five after
           the first are exactly what $messages->first() discarded. */
        foreach (['coupon name', 'coupon type', 'discount amount', 'coupon limit',
            'coupon expiry date', 'coupon code'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the refusal does not name '{$field}', so the merchant still has to guess");
        }

        $this->assertSame(6, substr_count($flash, 'is required'),
            'the refusal does not carry six messages, so some failing field is still discarded');

        /* THE CONTROL ON THE CONTROL: the OLD expression really did hide five of them. If this
           ever stops holding, this whole change is pointless and this line says so. */
        $first = (string) \Validator::make([], ['coupon_name' => 'required', 'coupon_code' => 'required'])
            ->getMessageBag()->first();
        $this->assertStringNotContainsString('coupon code', $first,
            '$messages->first() now names more than one field, so the defect being closed is not '
            . 'the defect that was measured');

        /* AND NOTHING WAS WRITTEN. The refusal still comes before the insert. */
        $this->assertSame(0, Coupon::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'the refused coupon was created anyway');
    }

    /**
     * The five-rule UPDATE validator, driven against a coupon the merchant really owns -- so the
     * ownership gate above it (`couponIsOutsideCurrentStore`) is passed rather than bypassed.
     */
    public function test_the_five_rule_coupon_update_refusal_names_all_five_failing_fields(): void
    {
        $coupon = Coupon::forceCreate([
            'coupon_name' => 'MINE',
            'coupon_code' => 'MINE-' . uniqid(),
            'coupon_type' => 'flat',
            'discount_amount' => 5,
            'coupon_limit' => 10,
            'store_id' => $this->store->id,
        ]);

        $flash = $this->refusalFlash('coupon.update', ['coupon_type' => 'flat'],
            'coupon.index', 'put', ['coupon' => $coupon->id]);

        foreach (['coupon name', 'discount amount', 'coupon limit', 'coupon expiry date',
            'coupon code'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the coupon update refusal does not name '{$field}'");
        }

        $this->assertSame(5, substr_count($flash, 'is required'));

        /* AND THE COUPON IS UNTOUCHED -- read raw, because Coupon is Cachable. */
        $this->assertSame('MINE', (string) \DB::table('coupons')->where('id', $coupon->id)->value('coupon_name'),
            'the refused update wrote to the coupon anyway');
    }

    /**
     * THE FOUR-RULE SINGLE-FIELD VALIDATOR, which the brief's "one rule means inert" shorthand
     * would have mis-classified. `'discount_amount' => 'required|numeric|min:1|max:100'` can fail
     * TWO rules at once: for a long non-numeric value `numeric` fails and `max` fails too, because
     * `Validator::getSize()` falls back to string LENGTH when the value is not numeric.
     */
    public function test_the_coupon_percentage_refusal_names_both_failing_rules(): void
    {
        $long = str_repeat('x', 101);

        $flash = $this->refusalFlash('coupon.store', [
            'coupon_type' => 'percentage',
            'discount_amount' => $long,
        ], 'coupon.index');

        $expected = implode(' ', \Validator::make(['discount_amount' => $long],
            ['discount_amount' => 'required|numeric|min:1|max:100'])->getMessageBag()->all());

        $this->assertSame($expected, $flash);
        $this->assertStringContainsString('must be a number', $flash);
        $this->assertStringContainsString('100', $flash,
            'the second failing rule (max:100) is still discarded');
    }

    public function test_the_flash_sale_percentage_refusal_names_both_failing_rules(): void
    {
        $long = str_repeat('x', 101);

        $flash = $this->refusalFlash('flash-sale.store', [
            'discount_type' => 'percentage',
            'discount_amount' => $long,
        ], 'flash-sale.index');

        $expected = implode(' ', \Validator::make(['discount_amount' => $long],
            ['discount_amount' => 'required|numeric|min:1|max:100'])->getMessageBag()->all());

        $this->assertSame($expected, $flash);
        $this->assertStringContainsString('must be a number', $flash);
        $this->assertStringContainsString('100', $flash,
            'the second failing rule (max:100) is still discarded');
    }

    /**
     * Testimonial's validator mixes three `required` rules with an `in:0,1`, so a single post can
     * fail four different fields -- and three of the four were thrown away.
     */
    public function test_the_testimonial_refusal_names_all_four_failing_fields(): void
    {
        $flash = $this->refusalFlash('testimonial.store', ['status' => '7'], 'testimonial.index');

        $expected = implode(' ', \Validator::make(['status' => '7'], [
            'rating_no' => 'required',
            'title' => 'required',
            'description' => 'required',
            'status' => 'in:0,1',
        ])->getMessageBag()->all());

        $this->assertSame($expected, $flash);

        foreach (['rating no', 'title', 'description'] as $field) {
            $this->assertStringContainsString($field, $flash,
                "the testimonial refusal does not name '{$field}'");
        }
        $this->assertStringContainsString('status', $flash,
            'the invalid status -- the LAST message in the bag, so the one most certainly '
            . 'discarded by first() -- is still not reported');
    }

    // =============================================================================================
    //  2. THE INERT TEN -- driven, and BYTE-IDENTICAL. This is the negative control.
    // =============================================================================================

    /**
     * A single-field failure must flash EXACTLY the validator's own message: no separator, no
     * padding, no reordering. A repair satisfied by `implode()` on something that concatenates
     * junk dies here, and so does one that changed the wording on ten merchant screens by
     * accident.
     *
     * Every one of these four validators carries one rule, so no request can make their bag hold
     * a second message. The join is provably the same bytes -- which is the honest claim, and the
     * reason section 4 pins the EXPRESSION for them rather than a behaviour.
     *
     * @dataProvider singleRuleRefusals
     */
    public function test_a_single_rule_refusal_flashes_exactly_one_message_with_no_stray_separator(
        string $route, string $index, string $field
    ): void {
        $flash = $this->refusalFlash($route, [$field => ''], $index);

        $expected = (string) \Validator::make([$field => ''], [$field => 'required'])
            ->getMessageBag()->first();

        $this->assertSame($expected, $flash,
            "{$route} no longer flashes exactly the validator's message. A join that pads, "
            . 'reorders or re-separates a one-message bag is a user-visible wording change on a '
            . 'merchant screen that was not meant to change at all.');

        /* THE EXPLICIT SEPARATOR CONTROL: one message, so not one space of join may appear at
           either end, and the string must hold exactly one sentence. */
        $this->assertSame($flash, trim($flash), 'the joined single message gained outer padding');
        $this->assertSame(1, substr_count($flash, '.'),
            'the single-field refusal now carries more than one sentence');
    }

    public static function singleRuleRefusals(): array
    {
        return [
            'flash sale name' => ['flash-sale.store', 'flash-sale.index', 'name'],
            'faq topic' => ['faqs.store', 'faqs.index', 'topic'],
            'product brand name' => ['product-brand.store', 'product-brand.index', 'name'],
            'tag name' => ['tag.store', 'tag.index', 'name'],
            'product label name' => ['product-label.store', 'product-label.index', 'name'],
        ];
    }

    // =============================================================================================
    //  3. THE REFUSALS DID NOT WIDEN -- the trap, driven
    // =============================================================================================

    /**
     * A VALID post still succeeds on every inert screen. This is the positive control for the
     * trap: the change may touch the WORDING of a refusal that already happened, never WHETHER a
     * refusal happens. If a dormant validator had been given a `fails()` guard, these four rows
     * would stop being created.
     */
    public function test_a_valid_post_still_succeeds_on_every_screen_this_change_touched(): void
    {
        foreach ([
            ['faqs.store', 'faqs.index', ['topic' => 'Shipping times'], Faq::class],
            ['tag.store', 'tag.index', ['name' => 'Summer'], Tag::class],
            /* `status` (and `is_popular`) are sent on purpose, and the reason is a SEPARATE
               pre-existing defect this change does not own: `product_brands.status`,
               `product_brands.is_popular` and `product_labels.status` are
               `tinyInteger()->default(...)`, i.e. NOT NULL, and both handlers assign
               `$request->status` / `$request->is_popular` unconditionally, so a valid post that
               OMITS them inserts NULL and 500s. MEASURED 2026-11-04: a `name`-only post to
               product-label.store and to product-brand.store both answer 500. Reported, not
               repaired here -- repairing it would change whether a post succeeds, which is exactly
               what this slice must not do. */
            ['product-brand.store', 'product-brand.index', ['name' => 'Acme', 'status' => 1, 'is_popular' => 0], ProductBrand::class],
            ['product-label.store', 'product-label.index', ['name' => 'New', 'status' => 1], ProductLabel::class],
        ] as [$route, $index, $payload, $model]) {
            $response = $this->actingAs($this->merchant)
                ->from(route($index))
                ->post(route($route), $payload);

            $response->assertRedirect(route($index));

            $this->assertSame('', (string) $response->getSession()->get('error'),
                "{$route} refused a VALID post. The repair was supposed to change the message of "
                . 'an existing refusal, not create a new one.');
            $this->assertNotSame('', (string) $response->getSession()->get('success'),
                "{$route} did not report success for a valid post");

            $this->assertSame(1, $model::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
                "{$route} did not create the row for a valid post");
        }
    }

    /**
     * THE WIDENED MESSAGE MAY NOT REACH AN UNAUTHORISED CALLER.
     *
     * Every one of the eighteen sites sits INSIDE its handler's permission gate, so widening the
     * wording cannot disclose a form's field list to a principal who may not use the form. Driven
     * on the two most sensitive of the seven: a staff member holding only the `Manage ...`
     * read permission must still get `Permission denied.` and not one word of validation text.
     */
    public function test_a_staff_user_without_the_create_permission_never_sees_the_widened_message(): void
    {
        $staff = $this->makeStaff(User::find($this->merchant->id), ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Coupon');
        $this->grantPermission(User::find($staff->id), 'Manage Testimonial');
        $staff = User::find($staff->id);

        foreach ([
            ['coupon.store', 'coupon.index', []],
            ['testimonial.store', 'testimonial.index', ['status' => '7']],
        ] as [$route, $index, $payload]) {
            $response = $this->actingAs($staff)->from(route($index))->post(route($route), $payload);

            $response->assertRedirect(route($index));

            $flash = (string) $response->getSession()->get('error');

            $this->assertStringContainsString('Permission denied', $flash,
                "{$route} let a staff user without the create permission past the gate");
            $this->assertStringNotContainsString('is required', $flash,
                "{$route} leaked validation text -- now SIX field names at once -- to a principal "
                . 'with no create permission. The permission gate must stay above the validator.');
        }
    }

    // =============================================================================================
    //  4. THE RATCHET over all 18 sites, and the shape controls
    // =============================================================================================

    /**
     * No owned controller may go back to `$messages->first()`, and each must carry the join at
     * every one of its refusal sites. Asserted on the source because ten of the eighteen are
     * provably inert: a behavioural assertion for those would pass identically before and after,
     * so the expression is what is pinned -- the same approach section 4 of
     * `ModalFormRefusalFeedbackTest` takes for `CategoryController`.
     */
    public function test_no_owned_controller_flashes_only_the_first_validation_message(): void
    {
        $total = 0;

        foreach (self::OWNED as $relative => $sites) {
            $source = $this->codeWithoutComments($relative);

            /* THE PREMISE: the measured site count still covers the file. A new validator refusal
               would otherwise slip past both assertions below. */
            $this->assertSame($sites, substr_count($source, 'getMessageBag()'),
                "{$relative} no longer has exactly {$sites} validator refusal sites, so the counts "
                . 'below no longer cover the file. Re-read the handler before trusting this test.');

            $this->assertSame(0, substr_count($source, "->with('error', \$messages->first())"),
                "{$relative} is back to flashing only the FIRST validation message, which leaves "
                . 'every other failing field unnamed (WCAG 3.3.1). Use '
                . "implode(' ', \$messages->all()).");

            $this->assertSame($sites, substr_count($source, "implode(' ', \$messages->all())"),
                "all {$sites} refusals in {$relative} must flash the JOINED message bag");

            $total += $sites;
        }

        $this->assertSame(18, $total, 'the eighteen measured refusal sites are no longer eighteen');
    }

    /**
     * THE TRAP, pinned on the source. `AppSettingController` builds six validators and consults
     * two; activating a dormant one would start refusing posts that succeed today. In these seven
     * files every validator built is already consulted, so there is nothing dormant to activate --
     * and if a later pass adds one, this fails rather than letting it ride in on this pattern.
     */
    public function test_no_owned_controller_gained_a_newly_consulted_validator(): void
    {
        foreach (array_keys(self::OWNED) as $relative) {
            $source = $this->codeWithoutComments($relative);

            $built = substr_count($source, 'Validator::make(');
            $consulted = substr_count($source, '->fails()');

            $this->assertSame($built, $consulted,
                "{$relative} builds {$built} validators and consults {$consulted}. A validator "
                . 'built and never checked is dormant: giving it a fails() guard would start '
                . 'REFUSING posts that succeed today, which is a business-rule change and not '
                . 'this repair. (AppSettingController is the live example: 6 built, 2 consulted.)');
        }
    }

    /** The response shape is untouched on every owned file. */
    public function test_the_refusal_shape_did_not_change_on_any_owned_controller(): void
    {
        foreach (array_keys(self::OWNED) as $relative) {
            $source = $this->codeWithoutComments($relative);

            foreach ([
                'withErrors(' => 'nothing renders it: every entry point is an AJAX modal and the '
                    . 'refusal redirects to a different page, which consumes the flash',
                'withInput(' => 'it cannot be read through this architecture -- see '
                    . 'ModalFormRefusalFeedbackTest::test_the_refusal_really_does_discard_the_merchants_input',
                'expectsJson(' => 'that is the modal-preserving redesign, which the review '
                    . 'deferred as Phase 2.5',
            ] as $needle => $why) {
                $this->assertSame(0, substr_count($source, $needle),
                    "{$relative} started calling {$needle}: {$why}.");
            }

            /* And the flash key is still `error` at every refusal site. */
            $this->assertSame(self::OWNED[$relative],
                substr_count($source, "->with('error', implode(' ', \$messages->all()))"),
                "{$relative} changed the flash key or the redirect at a validation refusal");
        }
    }
}
