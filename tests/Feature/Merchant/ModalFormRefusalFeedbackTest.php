<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F274 — the WS F claim about coupon and flash-sale validation feedback, MEASURED AND REFUTED.
 * =================================================================================================
 *
 * The carried-forward note said these two screens "fail silently" and needed
 * `withErrors($validator)->withInput()`, an `x-ss.error-summary` and a redirect back to the failing
 * form — the same prescription F271 applied to the manual order form. Acting on it would have been
 * wrong in all three parts, and this file is the measurement that says so. It is the same shape as
 * F265: a WS F claim that reads plausibly and does not survive being driven.
 *
 * ------------------------------------------------------------------------------------------------
 * 1. THE MERCHANT *IS* TOLD. These controllers do not use `withErrors()`; they flash a message:
 *
 *        return redirect()->back()->with('error', $messages->first());
 *
 *    and `layouts/app.blade.php:758` turns `Session::get('error')` into a toast on the next page.
 *    That is a different mechanism from F271's inline messages, not an absent one. The assertions
 *    below drive a real refusal and read the flash.
 *
 * 2. THERE IS NO "FAILING FORM" TO REDIRECT BACK TO. Every entry point to these forms is an AJAX
 *    modal — `coupon/index.blade.php:11`, `flash-sale/index.blade.php:9`, `dashboard.blade.php:296`
 *    and `partision/header.blade.php:211` all carry `data-ajax-popup="true"`. `coupon/create` and
 *    `flash-sale/create` are therefore FRAGMENTS BY DESIGN, and their missing `@extends` is correct
 *    rather than a defect. Adding a layout would render a full page inside a modal body.
 *
 * 3. AND `withInput()` HERE WOULD BE A CHANGE THAT LOOKS LIKE A FIX AND DOES NOTHING. The input is
 *    genuinely lost — no call site passes it, which this file pins — but the conventional remedy
 *    cannot recover it through this architecture. Old input survives exactly ONE request. The
 *    refusal redirects to the INDEX, which consumes it; the merchant must then click "Add Coupon"
 *    again, and THAT is a second request which fetches the form fresh with the flash already gone.
 *    So `->withInput()` would flash data that nothing can ever read.
 *
 * WHAT IS GENUINELY RESIDUAL, recorded rather than quietly fixed: a refused coupon or flash sale
 * loses the merchant's typed input. Making that survivable means keeping the modal open and
 * rendering the errors inside it — a redesign of the AJAX-modal submit path, which is Phase 2.5
 * polish and not this slice. The test below pins the loss so the claim stays true, and fails if
 * somebody adds a `withInput()` that cannot work.
 *
 * ------------------------------------------------------------------------------------------------
 * ADDENDUM, F-validation-errors-lose-the-modal (Phase 2.5, 2026-11-04). The carried-forward
 * finding asked for the modal-preserving redesign again -- an opt-in AJAX submit path, a JSON
 * refusal branch on every early return and an inline note. The adversarial review REDUCED it to
 * one expression per refusal site, and that reduction is what section 4 below pins:
 *
 *     -  return redirect()->back()->with('error', $messages->first());
 *     +  return redirect()->back()->with('error', implode(' ', $messages->all()));
 *
 * `$messages->first()` emits ONE message however many rules failed, so a six-`required` validator
 * such as `CouponController::store()`'s names one field and leaves five unnamed -- the only
 * WCAG 3.3.1 failure the verification upheld. The join closes it without touching the response
 * shape: still a 302, still the `error` flash key, still no `withInput()`, so sections 1-3 above
 * and `MerchantCategoryOwnershipTest`'s 302 contract stay exactly as they were.
 *
 * THE REDESIGN IS STILL NOT DONE AND PARAGRAPH 3 ABOVE IS STILL TRUE. A refused modal form still
 * loses the merchant's typed input; `test_the_refusal_really_does_discard_the_merchants_input`
 * still pins that. Only the WORDING of the refusal changed.
 *
 * AND THE JOIN IS APPLIED HERE TO `CategoryController` ONLY, which is the slice this change owns.
 * Both category validators carry exactly one rule (`'name' => 'required'`), so on these two screens
 * the join is provably BYTE-IDENTICAL to what shipped before -- which is why section 4 proves the
 * expression on a real multi-rule bag (`test_the_joined_message_really_differs_from_first`) rather
 * than pretending the category screens demonstrate it. The seven controllers that carry the
 * observable instance (Coupon, FlashSale, Faq, Testimonial, ProductBrand, Tag, ProductLabel --
 * 18 further sites) are outside this change's file ownership and are NOT fixed here.
 * ------------------------------------------------------------------------------------------------
 */
class ModalFormRefusalFeedbackTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        foreach (['Manage Coupon', 'Create Coupon', 'Manage Flash Sale', 'Create Flash Sale'] as $permission) {
            $this->grantPermission(User::find($this->merchant->id), $permission);
        }

        $this->merchant = User::find($this->merchant->id);
    }

    // =============================================================================================
    //  1. THE MERCHANT IS TOLD -- driven, not read
    // =============================================================================================

    /**
     * A percentage coupon with a discount over 100 is refused, and the refusal reaches the session
     * as a flash message the layout renders.
     */
    public function test_a_refused_coupon_flashes_a_message_the_layout_turns_into_a_toast(): void
    {
        $response = $this->actingAs($this->merchant)
            ->from(route('coupon.index'))
            ->post(route('coupon.store'), [
                'name' => 'Spring sale',
                'code' => 'SPRING',
                'coupon_type' => 'percentage',
                'discount_amount' => 500,
                'limit' => 10,
            ]);

        /* THE PREMISE. A 403 would flash nothing and satisfy nothing below; a 500 would flash a
           different thing entirely. */
        $response->assertRedirect(route('coupon.index'));

        $flash = (string) $response->getSession()->get('error');

        $this->assertNotSame('', $flash,
            'the refused coupon flashed no message at all, so the merchant really is told nothing');
        $this->assertStringNotContainsString('Permission denied', $flash,
            'this is the permission refusal, not the validation one, so the test is measuring the '
            . 'wrong branch');

        /* AND THE LAYOUT RENDERS IT. Without this the flash is a value nobody displays. */
        $layout = (string) file_get_contents(base_path('resources/views/layouts/app.blade.php'));
        $this->assertStringContainsString("Session::get('error')", $layout,
            'the layout stopped rendering the flash error, so these controllers now really do '
            . 'fail silently and F274 has to be reopened');
        $this->assertStringContainsString('show_toastr', $layout);
    }

    public function test_a_refused_flash_sale_flashes_a_message_too(): void
    {
        $response = $this->actingAs($this->merchant)
            ->from(route('flash-sale.index'))
            ->post(route('flash-sale.store'), [
                'name' => '',
                'discount_type' => 'percentage',
            ]);

        $response->assertRedirect(route('flash-sale.index'));

        $this->assertNotSame('', (string) $response->getSession()->get('error'),
            'the refused flash sale flashed no message at all');
    }

    // =============================================================================================
    //  2. THE FRAGMENTS ARE FRAGMENTS BY DESIGN
    // =============================================================================================

    /**
     * `create` and `edit` must NOT carry `@extends`, and the reason is the trigger markup: every
     * entry point opens them in a modal. A layout inside a modal body renders a whole page —
     * sidebar, header and all — inside the dialog.
     */
    public function test_the_modal_fragments_have_no_layout_and_their_triggers_say_why(): void
    {
        foreach ([
            'resources/views/coupon/create.blade.php',
            'resources/views/coupon/edit.blade.php',
            'resources/views/flash-sale/create.blade.php',
            'resources/views/flash-sale/edit.blade.php',
        ] as $fragment) {
            $this->assertStringNotContainsString('@extends', (string) file_get_contents(base_path($fragment)),
                "{$fragment} gained a layout. Every trigger for it is `data-ajax-popup`, so the "
                . 'whole page would now render inside the modal body.');
        }

        /* THE PREMISE FOR THAT REASONING: the triggers really are AJAX modals. If one ever becomes
           an ordinary link, the fragment above stops being correct and this test says so. */
        foreach ([
            'resources/views/coupon/index.blade.php' => 'coupon.create',
            'resources/views/flash-sale/index.blade.php' => 'flash-sale.create',
        ] as $index => $route) {
            $source = (string) file_get_contents(base_path($index));

            $this->assertMatchesRegularExpression(
                '/data-ajax-popup="true"[^>]*' . preg_quote($route, '/') . '|'
                . preg_quote($route, '/') . '[^>]*data-ajax-popup="true"/s',
                $source,
                "{$index} no longer opens {$route} in a modal, so that view needs a layout after "
                . 'all and this test is now the thing that is wrong'
            );
        }
    }

    // =============================================================================================
    //  3. THE RESIDUAL, PINNED
    // =============================================================================================

    /**
     * THE INPUT IS LOST, and `withInput()` cannot recover it through this architecture.
     *
     * Old input survives one request. The refusal redirects to the index, which consumes it; the
     * merchant then clicks "Add Coupon" again, and that second request fetches the form fresh with
     * nothing left to repopulate from. So a `withInput()` added here would flash data no reader can
     * reach — a change that looks like a fix and is not one.
     *
     * This asserts the loss rather than asserting it away, so the ledger's claim stays honest and
     * so a future pass that fixes it properly (keeping the modal open) has to come here and say so.
     */
    public function test_the_refusal_really_does_discard_the_merchants_input(): void
    {
        $response = $this->actingAs($this->merchant)
            ->from(route('coupon.index'))
            ->post(route('coupon.store'), [
                'name' => 'Spring sale',
                'code' => 'SPRING',
                'coupon_type' => 'percentage',
                'discount_amount' => 500,
                'limit' => 10,
            ]);

        $old = (array) $response->getSession()->get('_old_input', []);

        $this->assertSame([], $old,
            'the refusal now flashes old input. If that is intended, note that the merchant '
            . 'cannot reach it: the redirect to the index consumes the flash, and re-opening the '
            . 'modal is a second request. Making it survivable means keeping the modal open, '
            . 'which is a redesign of the submit path (F274).');
    }

    // =============================================================================================
    //  4. F-validation-errors-lose-the-modal -- THE REFUSAL NAMES EVERY FAILING FIELD
    // =============================================================================================

    /**
     * THE RATCHET. Neither `CategoryController` refusal may go back to `$messages->first()`.
     *
     * This is asserted on the SOURCE rather than on a response, and the reason is measured rather
     * than stylistic: both category validators carry exactly one rule, so no request to
     * `category.store` or `category.update` can ever produce a second message, and a behavioural
     * assertion would therefore pass identically before and after the change. The expression is
     * what is being fixed, so the expression is what is pinned -- the same approach
     * `AjaxPopupContractRatchetTest` and `DesignSystemStylesheetScopeTest` take.
     */
    public function test_the_category_refusals_name_every_failing_field_not_just_the_first(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/CategoryController.php'));

        /* THE PREMISE. Two validator refusal sites, store() and update(). If a third appears, this
           count is what notices -- otherwise the two assertions below would silently stop covering
           the whole file. */
        $this->assertSame(2, substr_count($source, 'getMessageBag()'),
            'CategoryController no longer has exactly two validator refusal sites, so the counts '
            . 'below no longer cover the file. Re-read the handler before trusting this test.');

        $this->assertSame(0, substr_count($source, "->with('error', \$messages->first())"),
            'A CategoryController refusal is back to flashing only the FIRST validation message. '
            . "With one rule per validator that is byte-identical today, but \$messages->first() "
            . 'is the expression that leaves five of six failing fields unnamed the moment a rule '
            . 'is added (CouponController::store is the live instance). Use '
            . "implode(' ', \$messages->all()).");

        $this->assertSame(2, substr_count($source, "implode(' ', \$messages->all())"),
            'Both CategoryController refusals must flash the JOINED message bag.');

        /* AND THE RESPONSE SHAPE IS UNTOUCHED: still a redirect, still the `error` flash key,
           still no withInput(). A repair that reached for 422 JSON or withErrors() would show up
           here, and it would break MerchantCategoryOwnershipTest's 302 contract. */
        $this->assertSame(0, substr_count($source, 'withErrors('),
            'CategoryController started calling withErrors(). Nothing renders it: every entry '
            . 'point to these forms is an AJAX modal and the refusal redirects to a different '
            . 'page, which consumes the flash (see paragraph 3 of this file).');
        $this->assertSame(0, substr_count($source, 'withInput('),
            'CategoryController started calling withInput(), which cannot be read through this '
            . 'architecture -- see test_the_refusal_really_does_discard_the_merchants_input.');
        $this->assertSame(0, substr_count($source, 'expectsJson('),
            'CategoryController grew a JSON refusal branch. That is the modal-preserving redesign, '
            . 'which the review deferred: it is inert unless the submit path is converted too, and '
            . 'MerchantCategoryOwnershipTest:561 pins 302-on-success for this exact route.');
    }

    /**
     * THE CONTROL THAT PROVES THE EXPRESSION IS NOT COSMETIC.
     *
     * The category screens cannot demonstrate the defect -- one rule, one message -- so the
     * difference between the two expressions is measured directly, on a bag shaped like the live
     * instance (`CouponController::store()`'s six `required` rules, five of which go unnamed).
     * If this ever stops holding, the change above is pointless and this says so.
     */
    public function test_the_joined_message_really_differs_from_first_on_a_multi_rule_bag(): void
    {
        $bag = \Validator::make([], [
            'coupon_name' => 'required',
            'coupon_type' => 'required',
            'discount_amount' => 'required',
            'coupon_limit' => 'required',
            'coupon_expiry_date' => 'required',
            'coupon_code' => 'required',
        ])->getMessageBag();

        $this->assertCount(6, $bag->all(), 'the premise: six rules, six messages');

        $first = (string) $bag->first();
        $joined = implode(' ', $bag->all());

        /* WHAT THE OLD EXPRESSION HID: five of the six field names. */
        foreach (['coupon_type', 'discount_amount', 'coupon_limit', 'coupon_expiry_date', 'coupon_code'] as $unnamed) {
            $this->assertStringNotContainsString(str_replace('_', ' ', $unnamed), $first,
                "\$messages->first() unexpectedly names {$unnamed}, so the defect this change "
                . 'closes is not the defect that was measured.');
            $this->assertStringContainsString(str_replace('_', ' ', $unnamed), $joined,
                "the joined message fails to name {$unnamed}");
        }

        $this->assertStringContainsString('coupon name', $joined);
    }

    /**
     * THE BYTE-FOR-BYTE CONTROL. On a single-rule validator the join must change NOTHING -- not a
     * trailing space, not a separator, not the order. This is what stops the repair being
     * satisfied by `implode()` on something that adds padding, and it is why the two category
     * screens are safe to convert ahead of the other seven controllers.
     */
    public function test_the_single_rule_category_refusal_message_is_unchanged_byte_for_byte(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Product Category');
        $this->grantPermission(User::find($merchant->id), 'Create Product Category');
        $merchant = User::find($merchant->id);

        $response = $this->actingAs($merchant)
            ->from(route('category.index'))
            ->post(route('category.store'), ['name' => '', 'parent_id' => 0]);

        /* THE PREMISE: the validator refusal, not the permission one and not a 500. */
        $response->assertRedirect(route('category.index'));

        $flash = (string) $response->getSession()->get('error');

        $expected = (string) \Validator::make(['name' => ''], ['name' => 'required'])
            ->getMessageBag()->first();

        $this->assertSame($expected, $flash,
            'the single-rule category refusal no longer flashes exactly the validator message. A '
            . 'join that pads, reorders or re-separates a one-message bag is a user-visible '
            . 'wording change on every merchant screen it touches.');
        $this->assertStringNotContainsString('Permission denied', $flash,
            'this is the permission branch, not the validation branch -- the test is measuring '
            . 'the wrong return');

        /* AND NOTHING WAS WRITTEN. The validator still refuses before resolveParentId(), before
           updateStorageLimit() and before the insert. */
        $this->assertSame(0, \App\Models\Category::withoutGlobalScopes()
            ->where('store_id', $store->id)->count(),
            'the refused category was created anyway');
    }

    /**
     * THE PERMISSION GATE STAYS FIRST AND STAYS A REDIRECT.
     *
     * `isAbleTo('Create Product Category')` wraps the whole handler, so the validator -- and
     * therefore the new wording -- must be unreachable for a principal holding only
     * `Manage Product Category`. A refusal that started answering the validation surface would
     * disclose it to an unauthorised caller.
     */
    public function test_a_staff_user_without_the_create_permission_is_still_refused_before_the_validator(): void
    {
        $owner = $this->makeMerchant();
        $store = $this->makeStore($owner, ['default_language' => 'en']);
        $owner->current_store = $store->id;
        $owner->save();

        $staff = $this->makeStaff(User::find($owner->id), ['current_store' => $store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Product Category');
        $staff = User::find($staff->id);

        $response = $this->actingAs($staff)
            ->from(route('category.index'))
            ->post(route('category.store'), ['name' => '', 'parent_id' => 0]);

        $response->assertRedirect(route('category.index'));

        $flash = (string) $response->getSession()->get('error');

        $this->assertStringContainsString('Permission denied', $flash,
            'a staff user without Create Product Category reached the validator. The permission '
            . 'gate must come first: otherwise the refusal wording tells an unauthorised caller '
            . 'which fields the form has.');
        $this->assertStringNotContainsString('required', $flash,
            'the refusal leaked a validation message to a principal with no create permission');
    }

    /**
     * THE NON-AJAX LEG IS THE ONLY LEG. A header-less POST -- which is what the real browser form
     * sends, `Form::open` at category/create.blade.php -- still gets a 302 and an empty
     * `_old_input`, and so does one that announces itself as AJAX. The second half is the control
     * that would catch an `expectsJson()` branch slipped in later: nothing in this change may make
     * the response depend on a request header.
     */
    public function test_both_legs_still_redirect_and_still_flash_the_same_thing(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Product Category');
        $this->grantPermission(User::find($merchant->id), 'Create Product Category');
        $merchant = User::find($merchant->id);

        $plain = $this->actingAs($merchant)
            ->from(route('category.index'))
            ->post(route('category.store'), ['name' => '', 'parent_id' => 0]);

        $ajax = $this->actingAs($merchant)
            ->from(route('category.index'))
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->post(route('category.store'), ['name' => '', 'parent_id' => 0]);

        $this->assertSame(302, $plain->status());
        $this->assertSame(302, $ajax->status(),
            'the category refusal now answers an AJAX request differently. That is the deferred '
            . 'redesign, and it is inert while nothing intercepts the submit: public/js/custom.js '
            . 'calls no preventDefault on this form path, so the browser still navigates and the '
            . 'merchant would see nothing at all.');

        $this->assertSame(
            (string) $plain->getSession()->get('error'),
            (string) $ajax->getSession()->get('error'),
            'the two legs flash different text'
        );

        $this->assertSame([], (array) $plain->getSession()->get('_old_input', []),
            'the category refusal now flashes old input, which no reader can reach through this '
            . 'architecture -- see test_the_refusal_really_does_discard_the_merchants_input.');
    }
}
