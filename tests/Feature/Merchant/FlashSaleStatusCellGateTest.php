<?php

namespace Tests\Feature\Merchant;

use App\Models\FlashSale;
use App\Models\FlashSaleCondition;
use App\Models\Store;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * The flash-sale status cell renders a switch exactly when the handler would accept one.
 * =================================================================================================
 *
 * `resources/views/flash-sale/status.blade.php` offers a live toggle, and
 * `FlashSaleController::updateStatus()` (:703) answers **403** unless the caller is a non-operator
 * holding `Edit Flash Sale`:
 *
 *     if (! $user || UserPolicy::isOperator($user) || ! $user->isAbleTo('Edit Flash Sale'))
 *
 * Before this was repaired the cell asked nothing, so a viewer without that permission -- and the
 * platform operator, whom that gate refuses by name -- got a switch that flips, fires the XHR,
 * takes a 403 and reverts.
 *
 * WHY THIS TEST EXISTS AND WHY IT IS NOT A SCREENSHOT. The whole value of that repair is a PROMISE
 * THAT TWO CONDITIONS STAY IDENTICAL -- one in a Blade file, one in a controller, with nothing
 * structural holding them together. A capture proves the cell looked right on the day; it says
 * nothing about the day someone edits one of the two. An independent review of the change asked the
 * project's own question of it -- *if this were reverted, would a test fail?* -- and the answer was
 * no: `FlashSaleTenancyTest` drives `flash-sale.update`, not `updateStatus`, and nothing asserted
 * the equivalence. This is that assertion.
 *
 * It is written as a GRID over the three principals rather than as three separate cases, because
 * the property being pinned is the agreement itself: each row drives the real list page AND the
 * real route, and asserts that the presence of a switch and the acceptance of a write move together.
 * A one-sided test would pass if both sides broke the same way.
 */
class FlashSaleStatusCellGateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private Store $store;

    private FlashSale $sale;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = $this->makeMerchant();
        $this->store = $this->makeStore($owner, ['default_language' => 'en']);
        $owner->current_store = $this->store->id;
        $owner->save();

        $this->sale = FlashSale::forceCreate([
            'name' => 'Damascus Week',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'start_time' => '00:00',
            'end_time' => '23:59',
            'sale_product' => 'all',
            'discount_amount' => '15',
            'discount_type' => 'percentage',
            'store_id' => $this->store->id,
            // `flash_sales.created_by` is NOT NULL with no default.
            'created_by' => (int) $this->store->created_by,
        ]);

        FlashSaleCondition::forceCreate([
            'flashsale_id' => $this->sale->id,
            'store_id' => $this->store->id,
        ]);

        (new FlashSale)->flushCache();
    }

    /**
     * The cell as the application would render it for this user.
     *
     * RENDERED DIRECTLY RATHER THAN THROUGH THE LIST PAGE, and the reason is recorded in CLAUDE.md:
     * the status column is a DataTable `addColumn` callback, so the cell's markup arrives over the
     * table's own AJAX and never appears in the HTML of `GET /flash-sale`. Asserting on the page
     * body would assert on a table that has not loaded -- a test that passes whatever this view
     * does, which is the failure mode this file exists to avoid.
     */
    private function cellFor(User $user): string
    {
        $this->actingAs($user);

        return (string) view('flash-sale.status', ['flashsale' => $this->sale->fresh()])->render();
    }

    /** What `updateStatus()` would answer this user for this sale. */
    private function writeStatusAs(User $user): int
    {
        return $this->actingAs($user)
            ->post('/update-flashsale-status', [
                'flashsaleId' => $this->sale->id,
                'isActivated' => 'true',
            ])
            ->getStatusCode();
    }

    private function staffOf(Store $store, array $permissions): User
    {
        $staff = $this->makeMerchant();
        $staff->created_by = (int) $store->created_by;
        $staff->current_store = $store->id;
        $staff->type = 'staff';
        $staff->save();

        foreach ($permissions as $permission) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    public function test_premise_the_two_gates_are_asked_of_the_same_three_principals(): void
    {
        /* A GRID OVER PRINCIPALS PROVES NOTHING IF THE PRINCIPALS ARE NOT WHAT THEY CLAIM. Each of
           the three below has been built wrong at least once in this project: a "staff" row whose
           `creatorId()` was its own id, and an "operator" whose `type` did not satisfy
           `isOperator()` because of case or a trailing space. */
        $holder = $this->staffOf($this->store, ['Manage Flash Sale', 'Edit Flash Sale']);
        $viewer = $this->staffOf($this->store, ['Manage Flash Sale']);

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);
        $this->grantPermission($operator, 'Manage Flash Sale');
        $this->grantPermission($operator, 'Edit Flash Sale');

        $this->assertTrue($holder->isAbleTo('Edit Flash Sale'), 'the holder does not hold it');
        $this->assertFalse($viewer->isAbleTo('Edit Flash Sale'), 'the viewer holds it after all');
        $this->assertTrue(UserPolicy::isOperator($operator), 'the operator is not recognised as one');
        $this->assertFalse(UserPolicy::isOperator($holder), 'the holder reads as an operator');

        /* AND THE OPERATOR HOLDS THE PERMISSION. That is the whole point of including them: the
           question is not "can they do anything" but "does THIS gate refuse them anyway". An
           operator without `Edit Flash Sale` would be refused for the ordinary reason and would
           prove nothing about the operator limb. */
        $this->assertTrue($operator->isAbleTo('Edit Flash Sale'), 'the operator case is vacuous');
    }

    public function test_the_cell_offers_a_switch_exactly_when_the_route_accepts_a_write(): void
    {
        $holder = $this->staffOf($this->store, ['Manage Flash Sale', 'Edit Flash Sale']);
        $viewer = $this->staffOf($this->store, ['Manage Flash Sale']);

        $operator = $this->makeMerchant();
        $operator->type = 'super admin';
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);
        $this->grantPermission($operator, 'Manage Flash Sale');
        $this->grantPermission($operator, 'Edit Flash Sale');

        $observed = [];

        foreach ([
            'staff holding Edit Flash Sale' => $holder,
            'staff without Edit Flash Sale' => $viewer,
            'the platform operator' => $operator,
        ] as $label => $user) {
            $cell = $this->cellFor($user);

            $observed[$label] = [
                'cell offers a switch' => str_contains($cell, 'flashsale-toggle'),
                'route accepts the write' => $this->writeStatusAs($user) < 400,
            ];
        }

        /* ONE assertSame OVER THE WHOLE GRID, so a failure prints the disagreement rather than the
           first half of it. */
        $this->assertSame(
            [
                'staff holding Edit Flash Sale' => [
                    'cell offers a switch' => true,
                    'route accepts the write' => true,
                ],
                'staff without Edit Flash Sale' => [
                    'cell offers a switch' => false,
                    'route accepts the write' => false,
                ],
                'the platform operator' => [
                    'cell offers a switch' => false,
                    'route accepts the write' => false,
                ],
            ],
            $observed,
            "flash-sale/status.blade.php and FlashSaleController::updateStatus() disagree about who "
            . "may change a flash sale's status. A cell that offers a control the route refuses is a "
            . "switch that flips, fires, takes a 403 and reverts; a cell that withholds one the "
            . "route would accept hides a feature. Keep the view's condition a mirror of the "
            . "handler's -- including its operator limb -- or change both."
        );
    }

    public function test_the_withheld_case_still_shows_the_state_it_will_not_let_you_change(): void
    {
        /* WITHHOLDING THE CONTROL IS NOT WITHHOLDING THE INFORMATION. The permission governs the
           ability to change the status, not the right to know it -- and an empty cell under a
           `__('Status')` header is the defect repaired one screen over in customer/status.blade.php
           during PKG-G. */
        $viewer = $this->staffOf($this->store, ['Manage Flash Sale']);

        $this->sale->is_active = 1;
        $this->sale->save();
        (new FlashSale)->flushCache();

        $active = $this->cellFor($viewer);

        $this->assertStringContainsString('ss-badge', $active, 'no badge was rendered');
        $this->assertStringContainsString(__('Active'), $active, 'the active state is not stated');

        $this->sale->is_active = 0;
        $this->sale->save();
        (new FlashSale)->flushCache();

        $inactive = $this->cellFor($viewer);

        $this->assertStringContainsString(__('Inactive'), $inactive, 'the inactive state is not stated');
        $this->assertNotSame($active, $inactive, 'the badge does not move with the stored state');
    }

    public function test_the_switch_carries_an_accessible_name_that_names_the_sale(): void
    {
        /* It was an `<input type="checkbox">` with no label, no aria-label and no text -- announced
           as "checkbox" and nothing else, once per row, in a table where every row looks the same.
           The name must identify WHICH sale, because a screen-reader user arriving by column has no
           row context; it must NOT carry the state, which the checkbox role already conveys. */
        $holder = $this->staffOf($this->store, ['Manage Flash Sale', 'Edit Flash Sale']);

        $cell = $this->cellFor($holder);

        $this->assertStringContainsString('aria-label', $cell, 'the switch has no accessible name');
        $this->assertStringContainsString('Damascus Week', $cell, 'the name does not identify the sale');

        foreach (['Active', 'Inactive'] as $state) {
            $this->assertStringNotContainsString(
                'aria-label="' . __('Flash sale status for :name', ['name' => 'Damascus Week']) . ' ' . $state,
                $cell,
                'the accessible name carries the state, which the checkbox role already announces'
            );
        }
    }
}
