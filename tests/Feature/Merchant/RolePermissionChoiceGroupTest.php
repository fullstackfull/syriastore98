<?php

namespace Tests\Feature\Merchant;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-060 / P02-I4 — THE ROLE PERMISSION GRID, MEASURED IN THE DOM THE MERCHANT GETS.
 * =================================================================================================
 *
 * `ChoiceGuidanceCoverageTest` (Unit) covers all eleven screens this row touches, but it reads
 * SOURCE for nine of them and renders only `coupon/create`, which is the one partial in the set
 * that needs no fixtures. The role screens are the ones where that gap matters most:
 *
 *   - the group is a `<fieldset>` wrapping a tab bar and a table, not two radios, so whether it
 *     really ENCLOSES the checkboxes is a question about what Blade emits across ~140 lines of
 *     `@foreach`/`@if`, and source scoping can only say the tags are in the right order;
 *
 *   - the grid is built from `get_permission_by_module()` and filtered by the controller's
 *     `$grantable` set (P1-SEC-02), so the checkboxes exist only for a principal who really holds
 *     the permissions. A fieldset around an empty grid would announce a group with no members, and
 *     the view's `@if (!empty($permissions))` guard is what prevents that — a guard no source
 *     assertion exercises.
 *
 * WHY NOT IN THE UNIT FILE: this needs a tenant, a signed-in merchant, granted permissions and
 * permission rows, which is a database. It is deliberately NOT in the known-outcome manifest,
 * because it is expected to pass.
 */
class RolePermissionChoiceGroupTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function permissionId(string $name): int
    {
        return (int) Permission::firstOrCreate(
            ['name' => $name],
            ['display_name' => $name, 'guard_name' => 'web']
        )->id;
    }

    /**
     * The role form, rendered for a merchant who really holds something grantable.
     *
     * THE LOCALE IS SET ON THE PRINCIPAL'S `language` COLUMN, not with `app()->setLocale()`:
     * `SetLocate` re-resolves the locale inside the request, so a locale set in the test does not
     * survive it and the page comes back in English while the test reads as a pass. The row is
     * written through Eloquent and re-read, because `User` is `Cachable` and a raw write would
     * leave the pre-write row in the array cache for the rest of the test — the same pattern
     * `SettingsChoiceGroupGuidanceTest` uses and for the same two reasons.
     */
    private function roleFormHtml(?string $language = null): string
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Create Role');
        $this->grantPermission($merchant, 'Manage Product');
        $this->permissionId('Manage Product');

        if ($language !== null) {
            $merchant->language = $language;
            $merchant->save();
            $merchant = User::find($merchant->id);
        }

        $response = $this->actingAs($merchant)->get('/roles/create');

        $this->assertSame(200, $response->status(),
            'the role create form no longer renders, so every assertion below is vacuous. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $html = (string) $response->getContent();

        /* PREMISE. The grid lives inside `@if (!empty($permissions))`, so without a grantable
           permission the screen has no checkboxes and every assertion below would be measuring a
           guard rather than a group. */
        $this->assertStringContainsString('name="permissions[]"', $html,
            'premise: the permission grid rendered no checkboxes');

        return $html;
    }

    /** The group is a real fieldset, it is named, and its instruction is bound to it. */
    public function test_the_permission_grid_is_a_described_fieldset(): void
    {
        $html = $this->roleFormHtml();

        $this->assertMatchesRegularExpression(
            '~<fieldset[^>]*data-ss-choicegroup="permissions"[^>]*aria-describedby="permissions-group-help"~',
            $html,
            'the permission grid is not a fieldset carrying its own instruction, so a screen '
            . 'reader meets a check-all, a table and fifty checkboxes with no group name'
        );

        $this->assertStringContainsString('id="permissions-group-help"', $html,
            'the instruction the fieldset points at is on no element, and a reader announces '
            . 'nothing for a dangling reference');
    }

    /** And it ENCLOSES the checkboxes, which is what makes the description mean anything. */
    public function test_the_fieldset_encloses_the_permission_checkboxes_and_its_legend(): void
    {
        $html = $this->roleFormHtml();

        $start = strpos($html, 'data-ss-choicegroup="permissions"');
        $this->assertNotFalse($start, 'premise: the group is missing');

        $end = strpos($html, '</fieldset>', $start);
        $this->assertNotFalse($end, 'premise: the fieldset is unclosed');

        $group = substr($html, $start, $end - $start);

        $this->assertStringContainsString('<legend', $group,
            'the group has no legend, so its name is not in the accessibility tree');

        $this->assertStringContainsString('name="permissions[]"', $group,
            'the checkboxes are OUTSIDE the fieldset, so the instruction describes a group that '
            . 'does not contain them — the one defect a source-order check cannot see');

        $this->assertStringContainsString('id="pills-tab"', $group,
            'the module tab bar is outside the group, so the merchant can reach checkboxes the '
            . 'legend does not cover');
    }

    /** The one pattern that would turn this presentational wrapper into data loss. */
    private const DISABLED = '~<fieldset[^>]*data-ss-choicegroup="permissions"[^>]*\sdisabled~';

    /**
     * THE FIELDSET IS NOT DISABLED. That attribute on a `<fieldset>` disables every descendant
     * control, so it is the one way this wrapper could stop being presentational and start
     * dropping the whole form's data — silently, with the page still rendering and the merchant
     * seeing a saved role with no permissions on it.
     *
     * AND THIS ASSERTION HAS NO AVAILABLE MUTANT, WHICH IS WORTH STATING RATHER THAN LEAVING AS A
     * GREEN LINE. Putting `disabled` on the `<x-ss.choice-group>` tag in the view does NOT reach
     * the rendered fieldset: `components/ss/choice-group.blade.php` declares its props and writes
     * its own `<fieldset>` tag without spreading `$attributes`, so the attribute is swallowed.
     * MEASURED by rendering the component with it — the output contains no `disabled` at all, so
     * the obvious mutation changes nothing and survives for a reason that is not this test's
     * fault.
     *
     * The detector is therefore pinned by a POSITIVE CONTROL instead, which is the honest
     * substitute: the regex is shown to fire on the markup it exists to catch, so a later edit
     * that weakens it fails here even though no edit to the view can.
     */
    public function test_the_wrapper_disables_nothing(): void
    {
        $html = $this->roleFormHtml();

        $this->assertDoesNotMatchRegularExpression(self::DISABLED, $html,
            'the permission fieldset is disabled, which disables every checkbox inside it and '
            . 'posts an empty `permissions[]`');
    }

    /** POSITIVE CONTROL for the assertion above: the detector really recognises the offence. */
    public function test_the_disabled_detector_fires_on_a_disabled_fieldset(): void
    {
        $this->assertMatchesRegularExpression(self::DISABLED,
            '<fieldset class="ss-choicegroup" data-ss-choicegroup="permissions" disabled>',
            'the detector no longer recognises a disabled permission fieldset, so the assertion '
            . 'it backs is green for no reason');

        $this->assertDoesNotMatchRegularExpression(self::DISABLED,
            '<fieldset class="ss-choicegroup" data-ss-choicegroup="permissions">'
            . '<input type="checkbox" name="permissions[]" disabled>',
            'the detector fires on a disabled CONTROL inside the group, which is a different and '
            . 'legitimate thing — it must only object to the fieldset itself');
    }

    /**
     * The instruction renders in Arabic for an Arabic merchant, not as the English key — and the
     * ENGLISH case is asserted beside it, because "contains Arabic script" alone would also pass
     * on a page that renders Arabic for everybody.
     */
    public function test_the_instruction_renders_in_arabic(): void
    {
        $html = $this->roleFormHtml('ar');

        $at = strpos($html, 'id="permissions-group-help"');
        $this->assertNotFalse($at, 'premise: the instruction is missing');

        $text = trim(strip_tags(substr($html, $at, 400)));

        $this->assertMatchesRegularExpression('~\p{Arabic}~u', $text,
            'the group instruction renders with no Arabic script, so `__()` fell back to the '
            . 'English key and ar.json is missing the entry');

        $english = $this->roleFormHtml('en');
        $at = strpos($english, 'id="permissions-group-help"');
        $this->assertNotFalse($at, 'premise: the English instruction is missing');

        $this->assertDoesNotMatchRegularExpression('~\p{Arabic}~u',
            trim(strip_tags(substr($english, $at, 400))),
            'the group instruction renders Arabic for an ENGLISH merchant, so the Arabic '
            . 'assertion above proves nothing about the locale');
    }
}
