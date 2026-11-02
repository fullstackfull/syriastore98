<?php

namespace Tests\Feature\Merchant;

use App\Models\Category;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-05-4 — the category and attribute row actions: named at runtime, and gated to match the
 * handler.
 *
 * TWO PROPERTIES, and they fail in opposite directions, so both are driven here.
 *
 * 1. **The accessible name.** Both controls previously carried only `title`, which is not a
 *    name: `data-bs-toggle="tooltip"` makes Bootstrap MOVE the title into
 *    `data-bs-original-title` and blank the attribute when it initialises, so after page load
 *    each was a control wrapping an unlabelled `<i>`. Asserted here as `aria-label`, which is
 *    what assistive technology announces when both exist — the live browser check is what
 *    confirms the resolved name in the running page, per the accname order.
 *
 * 2. **The permission gate.** `CategoryController::update`/`::destroy` and
 *    `ProductAttributeController::update`/`::destroy` are gated on `Edit`/`Delete` permissions,
 *    while the markup showed both controls to anyone who could reach the list. The pilot screen
 *    (`attribute_option/index`) gates its own row actions, so this brings the parent lists into
 *    line with the reference. **It removes no functionality** — the handler already refused
 *    these users — and that claim is what the negative tests below actually check.
 *
 * WHY THE PARTIAL IS RENDERED DIRECTLY rather than driving `GET /category`: the row-action
 * partial is the whole of what changed, and the full page additionally renders
 * `partision/header.blade.php`, which reads `->slug` on the user's Store and raises a 500 when a
 * test user has none. Driving the page would measure that fixture gap rather than this change.
 * The live browser check covers the assembled page.
 */
class CategoryAttributeRowActionsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function categoryNamed(string $name): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => 'c-' . uniqid(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => 1,
        ]);
    }

    private function attributeNamed(string $name): ProductAttribute
    {
        return ProductAttribute::forceCreate([
            'name' => $name,
            'slug' => 'a-' . uniqid(),
            'store_id' => 1,
        ]);
    }

    private function render(string $view, array $data): string
    {
        return (string) view($view, $data)->render();
    }

    public function test_the_category_actions_are_named_for_the_record_they_act_on(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Edit Product Category');
        $this->grantPermission($user, 'Delete Product Category');
        $this->actingAs($user);

        $html = $this->render('category.action', ['category' => $this->categoryNamed('Scarves')]);

        $this->assertStringContainsString('aria-label="Edit category Scarves"', $html,
            'the edit control must name the category, not just the verb: a screen-reader user '
            . 'moving through the table hears a row\'s actions without the row\'s context.');
        $this->assertStringContainsString('aria-label="Delete category Scarves"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html,
            'the icon must be hidden from assistive technology, or it is announced beside the label.');
    }

    public function test_the_attribute_actions_are_named_for_the_record_they_act_on(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Edit Attributes');
        $this->grantPermission($user, 'Delete Attributes');
        $this->actingAs($user);

        $html = $this->render('attributes.action', ['attribute' => $this->attributeNamed('Size')]);

        $this->assertStringContainsString('aria-label="Edit attribute Size"', $html);
        $this->assertStringContainsString('aria-label="Delete attribute Size"', $html);
    }

    /** A name carrying markup must not break out of the attribute it is written into. */
    public function test_a_name_containing_markup_cannot_break_out_of_the_label(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Edit Product Category');
        $this->actingAs($user);

        $html = $this->render('category.action', ['category' => $this->categoryNamed('"><img src=x>')]);

        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;img', $html);
    }

    public function test_the_controls_use_the_design_system_not_the_vendored_theme(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Edit Product Category');
        $this->grantPermission($user, 'Delete Product Category');
        $this->actingAs($user);

        $html = $this->render('category.action', ['category' => $this->categoryNamed('Scarves')]);

        $this->assertStringContainsString('ss-btn', $html);
        $this->assertStringNotContainsString('btn-info', $html,
            'btn-info is the vendored theme\'s; P2-04-4 measured that family below AA in 44 of '
            . '56 readings across LTR/RTL x light/dark.');
        $this->assertStringNotContainsString('btn-danger', $html);
    }

    /**
     * THE GATE, in the direction that proves it is a gate. A user who can reach the list but
     * holds neither Edit nor Delete sees no control at all — which is what the handler already
     * enforced, so nothing they could do has been taken away.
     */
    public function test_a_user_without_the_permissions_is_shown_no_category_control(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Manage Product Category');
        $this->actingAs($user);

        $html = $this->render('category.action', ['category' => $this->categoryNamed('Scarves')]);

        $this->assertStringNotContainsString('aria-label="Edit category', $html);
        $this->assertStringNotContainsString('aria-label="Delete category', $html);
        $this->assertStringNotContainsString('category.destroy', $html);
    }

    public function test_a_user_without_the_permissions_is_shown_no_attribute_control(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Manage Attributes');
        $this->actingAs($user);

        $html = $this->render('attributes.action', ['attribute' => $this->attributeNamed('Size')]);

        $this->assertStringNotContainsString('aria-label="Edit attribute', $html);
        $this->assertStringNotContainsString('aria-label="Delete attribute', $html);
    }

    /**
     * EACH GATE IS SEPARATE. One permission must not carry the other — a user allowed to edit
     * but not delete must see exactly one control.
     */
    public function test_edit_permission_alone_does_not_reveal_the_delete_control(): void
    {
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Edit Product Category');
        $this->actingAs($user);

        $html = $this->render('category.action', ['category' => $this->categoryNamed('Scarves')]);

        $this->assertStringContainsString('aria-label="Edit category Scarves"', $html);
        $this->assertStringNotContainsString('aria-label="Delete category Scarves"', $html);
    }
}
