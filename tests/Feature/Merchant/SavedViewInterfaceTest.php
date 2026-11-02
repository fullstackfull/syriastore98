<?php

namespace Tests\Feature\Merchant;

use App\Models\SavedView;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-9 / WS E3 — the saved-views interface on the order list.
 *
 * The isolation and permission half is `SavedViewIsolationTest`, written first on the owner's
 * instruction. This is the rendered half: what a merchant actually sees, in both directions.
 */
class SavedViewInterfaceTest extends SecurityTestCase
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
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function saveView(User $as, string $name, array $filters)
    {
        return $this->actingAs($as)->post('saved-views', [
            'screen' => 'order', 'name' => $name, 'filters' => $filters,
        ]);
    }

    private function listHtml(array $query = [], ?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->merchant)->get(route('order.index', $query));

        $this->assertSame(200, $response->status(), 'premise: the list rendered');

        return $response->getContent();
    }

    public function test_a_saved_view_is_offered_on_the_list(): void
    {
        $this->saveView($this->merchant, 'Unpaid this week', ['ss_payment' => 'Unpaid']);

        $body = $this->listHtml();

        $this->assertStringContainsString('Unpaid this week', $body);
        $this->assertStringContainsString(route('saved-views.apply',
            SavedView::withoutGlobalScopes()->first()->id), $body);
    }

    public function test_a_colleagues_view_is_not_rendered(): void
    {
        $colleague = $this->makeStaff($this->merchant, ['name' => 'Someone Else']);
        $colleague->current_store = $this->store->id;
        $colleague->save();
        $this->grantPermission(User::find($colleague->id), 'Manage Order');
        $colleague = User::find($colleague->id);

        $this->saveView($colleague, 'Their private view', ['ss_payment' => 'Paid']);
        $this->saveView($this->merchant, 'My view', ['ss_payment' => 'Unpaid']);

        $body = $this->listHtml();

        $this->assertStringContainsString('My view', $body, 'premise: the block renders at all');
        $this->assertStringNotContainsString('Their private view', $body,
            'the screen must not leak a colleague\'s private arrangement');
    }

    /**
     * NO EMPTY STATE. An unfiltered list offers nothing to save, and a permanently visible save
     * box teaches merchants to stop seeing that part of the screen — the same contract P2-04-13's
     * attention block states.
     */
    public function test_the_save_box_appears_only_when_there_is_something_to_save(): void
    {
        $unfiltered = $this->listHtml();

        $this->assertStringNotContainsString('ss-saved-view-name', $unfiltered,
            'nothing is filtered, so there is no view to save');

        $filtered = $this->listHtml(['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        $this->assertStringContainsString('ss-saved-view-name', $filtered);
        $this->assertStringContainsString('name="filters[ss_payment]"', $filtered,
            'and it carries the filters actually on screen');
    }

    /** Only the screen's own vocabulary can be posted into a view from the form. */
    public function test_the_save_form_carries_no_parameter_outside_the_vocabulary(): void
    {
        $body = $this->listHtml([
            'ss_payment' => 'Unpaid', 'ss_apply' => 1,
            'page' => '3', 'redirect' => 'https://evil.test',
        ]);

        $this->assertStringContainsString('name="filters[ss_payment]"', $body);
        $this->assertStringNotContainsString('name="filters[page]"', $body);
        $this->assertStringNotContainsString('name="filters[redirect]"', $body);

        /*
         * SCOPED TO THE SAVE FORM, because asserting `evil.test` absent from the whole page fails
         * for a reason that has nothing to do with this slice: yajra reflects the CURRENT URL into
         * the DataTable's `ajax.url` inside a <script> block, so every query parameter appears
         * there. That is pre-existing and it is escaped -- `test_a_reflected_parameter_cannot_break_out_of_the_script`
         * below pins the escaping rather than leaving it assumed.
         */
        $form = substr($body, strpos($body, 'ss-filterbar__save'));
        $form = substr($form, 0, strpos($form, '</form>') ?: 2000);

        $this->assertStringNotContainsString('evil.test', $form,
            'nothing outside the vocabulary reaches the save form');
    }

    /**
     * The order list reflects every query parameter into the DataTable's `ajax.url` inside a
     * `<script>` block, so a `</script>` in a parameter would end that block early if it were
     * written raw. It is not — and the mechanism is PERCENT-ENCODING, not the `json_encode` slash
     * escaping I first assumed: the url is built encoded, so the payload arrives as
     * `%3C%2Fscript%3E` and never as markup. The premise assertion below is what corrected me;
     * without it this test would have passed on a page that simply dropped the parameter, and I
     * would have recorded the wrong reason for being safe.
     *
     * NOT A FINDING. Recorded as CHECKED, because "the order list reflects every query parameter
     * into a script block" is the kind of sentence that should not sit unexamined next to a slice
     * that adds query parameters to that screen.
     */
    public function test_a_reflected_parameter_cannot_break_out_of_the_script(): void
    {
        $body = $this->listHtml([
            'ss_payment' => 'Unpaid',
            'ss_apply' => 1,
            'x' => '</script><script>alert(1)</script>',
        ]);

        $this->assertStringContainsString('%3C', $body,
            'premise: the payload really did reach the page, percent-encoded. Without this the '
            . 'assertions below would pass on a page that dropped the parameter entirely.');

        $this->assertStringNotContainsString('alert(1)', $body,
            'the payload reached the page as executable text');
        $this->assertStringNotContainsString('</script><script>', $body,
            'a query parameter closed the script block it was written into');
    }

    /**
     * THE BLOCK IS A SIBLING OF THE FILTER FORM, NOT A CHILD. A `<form>` inside a `<form>` is
     * invalid HTML that browsers resolve by dropping the inner one — the save button would
     * silently submit the filter bar instead, and the failure would look like "saving does
     * nothing".
     */
    public function test_the_saved_view_forms_are_not_nested_inside_the_filter_form(): void
    {
        $body = $this->listHtml(['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        $barStart = strpos($body, 'ss-filterbar');
        $this->assertNotFalse($barStart, 'premise: the filter bar rendered');

        $barEnd = strpos($body, '</form>', $barStart);
        $this->assertNotFalse($barEnd, 'premise: the filter form closes');

        $savedStart = strpos($body, 'ss-filterbar__saved');
        $this->assertNotFalse($savedStart, 'premise: the saved-views block rendered');

        $this->assertGreaterThan($barEnd, $savedStart,
            'the saved-views block opens AFTER the filter form closes');
    }

    /** Every action is a POST with a token; none is a state-changing GET. */
    public function test_every_saved_view_action_is_a_posted_form(): void
    {
        $this->saveView($this->merchant, 'Queue', ['ss_payment' => 'Unpaid']);
        $id = SavedView::withoutGlobalScopes()->first()->id;

        $body = $this->listHtml();

        foreach ([route('saved-views.apply', $id), route('saved-views.destroy', $id)] as $action) {
            $this->assertStringContainsString('action="' . $action . '"', $body);
            $this->assertStringNotContainsString('href="' . $action . '"', $body,
                'a state-changing GET is the defect class F14/F30/F31 record on these screens');
        }

        $this->assertStringContainsString('name="_token"', $body);
        $this->assertStringContainsString('name="_method" value="DELETE"', $body);
    }

    // =======================================================================================
    //  BOTH DIRECTIONS
    // =======================================================================================

    /**
     * ARABIC AND ENGLISH, asserted rather than assumed. Every string this slice added must be
     * translated, or an Arabic merchant reads English controls on an otherwise Arabic screen —
     * the defect `ArabicUiCoverageTest` exists to catch, applied here to the new block.
     */
    public function test_the_block_is_translated_in_arabic(): void
    {
        $this->saveView($this->merchant, 'عرضي', ['ss_payment' => 'Unpaid']);

        /*
         * THE USER'S LANGUAGE, NOT `app()->setLocale()`. The `setlocate` middleware resolves the
         * locale per request from the session, the cookie or `users.language`, so a locale set on
         * the container before the request is overwritten before any Blade runs. The first version
         * of this test did exactly that and reported the Arabic strings missing — which was true
         * of the page it rendered, and that page was in English.
         */
        $this->merchant->language = 'ar';
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $body = $this->listHtml(['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        foreach ([
            'العروض المحفوظة',          // the group's accessible name
            'احفظ هذه المرشحات باسم',   // the save label
            'حفظ العرض',                // the save button
        ] as $arabic) {
            $this->assertStringContainsString($arabic, $body,
                'the saved-views block still shows English on an Arabic screen');
        }

        $this->assertStringContainsString('عرضي', $body, 'and the merchant\'s own name renders');

        foreach (['Save view', 'Save these filters as', 'Saved views'] as $english) {
            $this->assertStringNotContainsString('>' . $english . '<', $body,
                "the English string `{$english}` leaked onto the Arabic screen");
        }
    }

    public function test_the_block_renders_in_english(): void
    {
        $this->merchant->language = 'en';
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $body = $this->listHtml(['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        $this->assertStringContainsString('Save these filters as', $body);
        $this->assertStringContainsString('Save view', $body);
    }

    /**
     * ACCESSIBLE NAMES, because the delete control is an icon button and the apply control is a
     * chip. An icon with no accessible name is announced as "button" and nothing else — F39
     * records three order-row actions that shipped exactly that way.
     */
    public function test_the_controls_have_accessible_names(): void
    {
        $this->saveView($this->merchant, 'Queue', ['ss_payment' => 'Unpaid']);

        // The chips render on any list; the save box only on a FILTERED one, by the
        // no-empty-state contract above. Two requests, because they are two conditions.
        $chips = $this->listHtml();

        $this->assertStringContainsString('aria-label="Delete the saved view Queue"', $chips,
            'the icon-only delete button names WHICH view it deletes');
        $this->assertStringContainsString('aria-label="Saved views"', $chips,
            'and the group of chips is named');

        $withSaveBox = $this->listHtml(['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        $this->assertStringContainsString('for="ss-saved-view-name"', $withSaveBox);
        $this->assertStringContainsString('id="ss-saved-view-name"', $withSaveBox,
            'the label points at a control that exists -- F144 is 38 labels that do not');
    }
}
