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
}
