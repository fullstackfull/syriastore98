<?php

namespace Tests\Feature\DesignSystem;

use App\Models\ProductAttribute;
use App\Models\ProductAttributeOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE DESIGN SYSTEM'S MISSING AUTOMATED VALIDATION.
 *
 * The imported P01B design-system work ships two real verification tools --
 * docs/design-system/tools/check-contrast.py (40 WCAG pairs, pure Python) and
 * browser-checks.cjs (66 checks, Playwright + Chromium) -- and both were re-run during this
 * handover and passed. But neither is a PHPUnit test and neither was wired into CI, so nothing in
 * the pipeline notices if the pilot screen regresses. That is the gap this file closes, in the
 * cheapest place that works without a browser.
 *
 * WHAT THIS COVERS AND WHAT IT DOES NOT, stated so it is not mistaken for the browser suite:
 *   it does     -- the pilot renders; the stylesheet is actually linked and exists; the functional
 *                  contract the redesign had to preserve (routes, ajax hooks, delete forms, one
 *                  sortable list) is intact; the N^2 duplicate loop stays fixed; the Arabic and
 *                  English strings the redesign added still resolve.
 *   it does not -- contrast ratios, focus rings, tab order, RTL geometry, overflow. Those need a
 *                  real engine and stay in browser-checks.cjs.
 */
class PilotScreenContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: ProductAttribute, 2: array<int, ProductAttributeOption>} */
    private function pilotFixture(int $options = 5): array
    {
        [$owner, $store] = $this->makeTenant();

        foreach ([
            'Manage Attributes Option',
            'Create Attributes Option',
            'Edit Attributes Option',
            'Delete Attributes Option',
        ] as $permission) {
            $this->grantPermission($owner, $permission);
        }

        $name = 'pilot-attr-' . uniqid();

        $attribute = ProductAttribute::forceCreate([
            'name' => $name,
            // product_attributes.slug is declared string() with neither nullable() nor a default.
            'slug' => $name,
            'store_id' => $store->id,
        ]);

        $created = [];

        for ($i = 0; $i < $options; $i++) {
            $created[] = ProductAttributeOption::forceCreate([
                'attribute_id' => $attribute->id,
                'terms' => 'pilot-term-' . $i,
                'order' => $i,
                'store_id' => $store->id,
            ]);
        }

        return [$owner->fresh(), $attribute, $created];
    }

    public function test_the_pilot_screen_renders_and_links_the_design_system_stylesheet(): void
    {
        [$owner, $attribute] = $this->pilotFixture();

        $response = $this->actingAs($owner)->get('/attribute-option/' . $attribute->id);

        $response->assertStatus(200);

        // The opt-in include must actually emit the link, or the screen renders unstyled and
        // every contrast and focus measurement taken against it is meaningless.
        $response->assertSee('css/store-syria-ds.css', false);

        $this->assertFileExists(
            public_path('css/store-syria-ds.css'),
            'The design-system stylesheet the pilot links does not exist on disk.'
        );
    }

    /**
     * THE PRESERVED-FUNCTIONALITY CONTRACT -- the same claims browser-checks.cjs section D makes,
     * asserted against the rendered HTML so CI can see them without a browser.
     */
    public function test_the_pilot_preserves_the_functional_contract_of_the_screen_it_replaced(): void
    {
        [$owner, $attribute, $options] = $this->pilotFixture(5);

        $html = $this->actingAs($owner)->get('/attribute-option/' . $attribute->id)->getContent();

        // Exactly one sortable list. The pre-redesign view nested its @foreach inside another,
        // emitting one list per record and 25 li[data-id] nodes for 5 records; the redesign fixed
        // that and this is the regression test for the fix.
        //
        // MATCHED ON THE DESIGN-SYSTEM CLASS, not on `class="sortable` and not on a bare
        // `data-id=`. Two measured reasons: the real class attribute is
        // `class="ss-sort-list sortable stage list-unstyled"`, so `sortable` is mid-string and an
        // anchored match finds nothing; and this test renders through the REAL layouts.app
        // chrome, which carries `data-id` attributes of its own -- counting those gave 3 rows for
        // an attribute with zero options. Scoping to the `ss-`-prefixed classes the design system
        // owns is the only count that means what it says.
        $this->assertSame(1, substr_count($html, 'ss-sort-list'),
            'There is not exactly one design-system sortable list.');

        $this->assertSame(count($options), substr_count($html, 'class="ss-sort-item"'),
            'Sortable rows != records -- the N^2 duplicate loop may be back.');

        // The routes the screen drives: its contract with the controller.
        foreach ([
            'product-attribute/option',
            'add-attribute-option',
            'edit-attribute-option',
            'delete-attribute-option',
        ] as $fragment) {
            $this->assertStringContainsString($fragment, $html,
                "The pilot no longer references {$fragment}.");
        }

        // THE JS HOOKS THE HOST'S OWN SCRIPTS BIND TO, counted by ROUTE rather than by
        // `data-ajax-popup="true"`. Measured: the real page carries 10 of those attributes, only
        // 7 of which belong to the pilot -- layouts.app's own chrome contributes the rest. The
        // route fragments are unambiguous, so they are what gets counted.
        //   add-attribute-option   2  (the header button and the empty-state button)
        //   edit-attribute-option  1 per record
        $this->assertSame(2, substr_count($html, 'add-attribute-option'),
            'The pilot should offer exactly two create affordances: the header and the empty state.');

        $this->assertSame(count($options), substr_count($html, 'edit-attribute-option'),
            'One edit affordance per record is no longer emitted.');

        $this->assertSame(count($options), substr_count($html, 'delete-attribute-option'),
            'One delete affordance per record is no longer emitted.');

        // And the pilot's own popup hooks are still a subset of the page's: if the pilot stopped
        // emitting them entirely this drops below the host's own baseline.
        $this->assertGreaterThanOrEqual(count($options) + 2, substr_count($html, 'data-ajax-popup="true"'),
            'The pilot appears to have stopped emitting ajax-popup hooks.');

        $this->assertSame(count($options), substr_count($html, 'show_confirm'),
            'The delete confirmation hook is missing from at least one row.');

        $this->assertSame(count($options), substr_count($html, 'name="_method"'),
            'One DELETE form per row is no longer emitted.');
    }

    public function test_the_pilot_still_renders_with_no_records(): void
    {
        [$owner, $attribute] = $this->pilotFixture(0);

        $response = $this->actingAs($owner)->get('/attribute-option/' . $attribute->id);

        $response->assertStatus(200);

        // Again scoped to the design system's own classes: the host chrome this renders inside
        // carries unrelated data-id attributes.
        $this->assertSame(0, substr_count($response->getContent(), 'class="ss-sort-item"'),
            'Rows were rendered for an attribute with no options.');
        $this->assertSame(0, substr_count($response->getContent(), 'ss-sort-list'),
            'The sortable list was rendered with nothing to sort.');
    }

    /**
     * THE STRINGS THE REDESIGN ADDED MUST RESOLVE IN BOTH LOCALES. A missing key renders the key
     * itself, which is how an untranslated admin screen ships without anyone noticing.
     */
    public function test_every_string_the_redesign_added_resolves_in_arabic_and_english(): void
    {
        $en = json_decode((string) file_get_contents(base_path('resources/lang/en.json')), true);
        $ar = json_decode((string) file_get_contents(base_path('resources/lang/ar.json')), true);

        $this->assertIsArray($en, 'resources/lang/en.json is not valid JSON.');
        $this->assertIsArray($ar, 'resources/lang/ar.json is not valid JSON.');

        foreach (['Order saved.', 'Order could not be saved.'] as $key) {
            $this->assertArrayHasKey($key, $en, "en.json is missing the pilot string '{$key}'.");
            $this->assertArrayHasKey($key, $ar, "ar.json is missing the pilot string '{$key}'.");
            $this->assertNotSame('', trim((string) $ar[$key]),
                "ar.json has an empty translation for '{$key}'.");
            $this->assertNotSame($key, $ar[$key],
                "ar.json's '{$key}' is still the English source string.");
        }
    }

    /**
     * The verification tools are part of the deliverable. If one is deleted, the numbers quoted in
     * docs/design-system/README.md become unreproducible -- the stale-citation failure this
     * project has already hit more than once.
     */
    public function test_the_design_system_verification_tools_are_present(): void
    {
        foreach ([
            'docs/design-system/tools/check-contrast.py',
            'docs/design-system/tools/browser-checks.cjs',
            'docs/design-system/tools/render-preview.php',
            'docs/design-system/tools/preview-server.php',
            'docs/design-system/README.md',
            'docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md',
        ] as $path) {
            $this->assertFileExists(base_path($path),
                "{$path} is part of the design-system deliverable.");
        }
    }
}
