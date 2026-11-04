<?php

namespace Tests\Feature\Merchant;

use App\Models\OrderNote;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5 — detail-note-form-labels-unassociated.
 * ============================================================================================
 *
 * EVERY FORM CONTROL THE MERCHANT OPERATES ON THE ORDER DETAIL PAGE HAS AN ACCESSIBLE NAME,
 * AND EVERY `<label for=X>` POINTS AT AN ELEMENT THAT EXISTS.
 *
 * What was measured before the repair: `resources/views/order/view.blade.php` made the only two
 * `Form::label()` calls on the page with an EMPTY first argument, and konekt/html interpolates
 * that argument straight into the `for` attribute (vendor/konekt/html/src/FormBuilder.php:259), so
 * both rendered `for=""` — present, matching nothing. The textarea is a SIBLING of its label, not
 * nested, so there was no implicit association either, and the note-type `<select>` had no `id` at
 * all because `getIdAttribute()` (FormBuilder.php:1268-1277) hands back the field name as an id
 * only when that name was pushed by a previous `label()` call. The two selects in the order-action
 * row (`delivery_boy`, `payment_status`) had no `<label>`, no `aria-label` and no `title`, so they
 * announced as their selected option only.
 *
 * WHY THIS IS NOT A CASE IN `OrderDetailContractTest`. That file's header (:22-24) states it
 * "DELIBERATELY PINS NO MARKUP, CLASS, ORDER OR LABEL". A label-association census is exactly a
 * markup-and-label pin, so it lives here instead of contradicting that charter.
 *
 * WHY SCRIPTS ARE STRIPPED FIRST, and why the census is REGION-SCOPED rather than page-wide:
 *  - `resources/views/layouts/app.blade.php:666` emits the literal text
 *    `<label for="choice_attributes" ...>` inside an unconditional `<script>` block, and
 *    `grep -rn 'id="choice_attributes"'` over resources/, app/ and public/ returns ZERO. A
 *    page-wide `for`/`id` census therefore fails on every admin page WITH A PERFECT REPAIR
 *    APPLIED. Same class of mistake as matching the layout's `<meta name>` tags — see the note at
 *    `OrderDetailContractTest.php:139-145`, which strips scripts for the same reason.
 *  - the page also renders `partision/settingPopup.blade.php`'s own labels, which this slice does
 *    not repair; the page-wide ratchet belongs to the separate label-sweep rows (the product
 *    editor's 17 + 21 measured `for=""` labels are recorded, deferred, in
 *    `ChoiceGroupGuidanceTest.php:161-171`).
 *
 * KNOWN BLIND SPOT, STATED AS A LIMIT AND NOT CLAIMED AS COVERAGE. Three WorkDo view composers
 * push into the three `@stack`s at `view.blade.php:686-688`, i.e. AFTER the note form:
 * `AdditionalFieldInCheckout/src/app/Providers/ViewComposer.php:21,40`,
 * `CheckoutAttachment/src/app/Providers/ViewComposer.php:46,63` and
 * `DigitalProduct/src/app/Providers/DigitalProductimageProvider.php:50`. All three pushed partials
 * emit zero `id=` today (measured), so `id="note"` is unique. But no WorkDo module loads in any
 * test run (CLAUDE.md), so a module-contributed second control named `note` would duplicate the id
 * invisibly to CI. The uniqueness assertion below cannot see that, and does not pretend to.
 */
class OrderDetailControlNamesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;
    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        /*
         * LARATRUST, NOT SPATIE. The note form sits inside `@permission('Create Order Note')`
         * (view.blade.php:656, closed :682). `@can('Create Order Note')` is permanently FALSE here
         * (`permissions_as_gates => false`), so the permission has to be granted through a real
         * role or every assertion below would run against an empty div and pass vacuously.
         */
        foreach (['Show Order', 'Manage Order', 'Manage Order Note', 'Create Order Note'] as $p) {
            $this->grantPermission(User::find($this->merchant->id), $p);
        }

        $this->merchant = User::find($this->merchant->id);

        $order = $this->makeOrder($this->store, ['product_order_id' => 'NAMES-1']);
        $this->orderId = $order->id;

        OrderNote::forceCreate([
            'order_id' => $order->id, 'customer_id' => null,
            'notes' => 'Existing note', 'note_type' => 'private_note',
            'status' => '', 'store_id' => $this->store->id,
        ]);
    }

    /** The script-stripped document, as a merchant who really holds the permission. */
    private function markup(?User $as = null): string
    {
        $response = $this->actingAs($as ?? $this->merchant)
            ->get('/order-view/' . Crypt::encrypt($this->orderId));

        $this->assertSame(200, $response->status(),
            'premise: the detail page rendered. Every assertion below is vacuous on a redirect.');

        return (string) preg_replace(
            '/<script\b[^>]*>.*?<\/script>/is', '', (string) $response->getContent()
        );
    }

    /** Region A — the note form: its own `<form>`, start to `</form>`. */
    private function noteForm(string $markup): string
    {
        $start = strpos($markup, 'action="' . route('order-note.store') . '"');
        $this->assertNotFalse($start,
            'premise: the note form rendered. Without it the census has nothing to look at, so a '
            . 'missing @permission grant would read as a pass.');

        $end = strpos($markup, '</form>', $start);
        $this->assertNotFalse($end, 'premise: the note form closed');

        return substr($markup, $start, $end - $start);
    }

    /** Region B — the order-action row that carries the two order-mutating selects. */
    private function actionRow(string $markup): string
    {
        $db = strpos($markup, 'id="delivery_boy"');
        $this->assertNotFalse($db, 'premise: the delivery-boy select rendered');

        $start = strrpos(substr($markup, 0, $db), '<div class="row');
        $this->assertNotFalse($start, 'premise: the enclosing row was found');

        $ps = strpos($markup, 'id="payment_status"', $db);
        $this->assertNotFalse($ps, 'premise: the payment-status select rendered');

        $end = strpos($markup, '</select>', $ps);
        $this->assertNotFalse($end, 'premise: the payment-status select closed');

        return substr($markup, $start, ($end + strlen('</select>')) - $start);
    }

    /**
     * THE REPAIRED BEHAVIOUR. No `<label for="">` survives in the note form, and every `for` it
     * does carry names exactly one element in the document.
     *
     * The capture group is `([^"]*)` — STAR, not plus. The pattern this was modelled on
     * (`tests/Feature/Security/UserInfoDisclosureTest.php:296`) uses `+`, which does not capture
     * `for=""` at all and would therefore have passed straight over this very defect.
     */
    public function test_every_label_in_the_note_form_points_at_an_element_that_exists(): void
    {
        $markup = $this->markup();
        $region = $this->noteForm($markup);

        // PREMISES, so this cannot pass vacuously.
        $this->assertStringContainsString('name="note"', $region,
            'premise: the note textarea is in the region');
        $this->assertStringContainsString('name="note_type"', $region,
            'premise: the note-type select is in the region');

        preg_match_all('/<label\b[^>]*\bfor="([^"]*)"/i', $region, $m);

        $this->assertGreaterThanOrEqual(2, count($m[1]),
            'premise: both note labels rendered');

        foreach ($m[1] as $for) {
            $this->assertNotSame('', trim($for),
                'A `<label for="">` in the note form. `Form::label()`\'s FIRST argument becomes '
                . 'the `for` attribute verbatim (konekt/html FormBuilder.php:259), so passing '
                . "'' renders a `for` that matches nothing and the control has no name.");

            // COUNTED, not merely present, and against the whole script-stripped document so a
            // duplicate contributed elsewhere on the page is still caught.
            $this->assertSame(1, substr_count($markup, 'id="' . $for . '"'),
                "`<label for=\"{$for}\">` must match exactly one `id=\"{$for}\"` in the document.");
        }
    }

    /**
     * THE PROPERTY THE LABEL CENSUS ALONE CANNOT SEE: a control with NO label at all. A census of
     * `<label for>` is structurally blind to `delivery_boy` and `payment_status`, which announced
     * as their selected option only — and both MUTATE THE ORDER (driver assignment, payment
     * status), so an unnamed combo box there is a mis-set, not only an announcement defect.
     */
    public function test_every_operable_control_in_both_regions_has_an_accessible_name(): void
    {
        $markup = $this->markup();
        $regions = ['note form' => $this->noteForm($markup), 'order action row' => $this->actionRow($markup)];

        // Every `for` on the page (script-stripped) can name a control, wherever the label sits.
        preg_match_all('/<label\b[^>]*\bfor="([^"]*)"/i', $markup, $lm);
        $labelled = array_filter(array_map('trim', $lm[1]), static fn ($v) => $v !== '');

        $checked = 0;

        foreach ($regions as $where => $region) {
            preg_match_all('/<(input|select|textarea)\b[^>]*>/i', $region, $cm);

            foreach ($cm[0] as $tag) {
                preg_match('/\btype="([^"]*)"/i', $tag, $t);
                if (in_array(strtolower($t[1] ?? ''), ['hidden', 'submit', 'button', 'reset'], true)) {
                    continue;
                }

                $checked++;

                preg_match('/\bid="([^"]*)"/i', $tag, $i);
                preg_match('/\baria-label="([^"]*)"/i', $tag, $a);
                preg_match('/\bname="([^"]*)"/i', $tag, $n);

                $hasAria = trim($a[1] ?? '') !== '';
                $hasLabel = ($i[1] ?? '') !== '' && in_array($i[1], $labelled, true);

                $this->assertTrue($hasAria || $hasLabel,
                    "The control `" . ($n[1] ?? $tag) . "` in the {$where} has no accessible name: "
                    . 'no non-empty `aria-label`, and no `id` that any `<label for>` matches. It '
                    . 'announces as its selected option (or its placeholder) only.');
            }
        }

        $this->assertGreaterThanOrEqual(4, $checked,
            'premise: the four operable controls (note, note_type, delivery_boy, payment_status) '
            . 'were examined');
    }

    /**
     * NO DUPLICATE IDS — scoped to the two repaired regions, deliberately. A page-wide assertion
     * would span vendored module markup this phase may not edit, and would be green-and-blind in
     * CI (no WorkDo module loads in a test run) while being unfixable if it ever went red in
     * production. See the blind-spot note on the class.
     */
    public function test_the_repaired_regions_contribute_no_duplicate_id(): void
    {
        $markup = $this->markup();
        $ids = [];

        foreach ([$this->noteForm($markup), $this->actionRow($markup)] as $region) {
            preg_match_all('/\sid="([^"]+)"/', $region, $m);
            $ids = array_merge($ids, $m[1]);
        }

        $this->assertNotEmpty($ids, 'premise: the regions carry ids');

        $dupes = array_keys(array_filter(array_count_values($ids), static fn ($c) => $c > 1));

        $this->assertSame([], $dupes,
            'Duplicate id(s) in the repaired regions: ' . implode(', ', $dupes));
    }

    /**
     * NEGATIVE CONTROL 1 — THE POSTED PAYLOAD IS UNCHANGED. An `id` is never submitted, so a
     * correct labelling repair changes zero bytes of the request. This is what catches an
     * over-reach that renamed the textarea or switched the option KEYS to their translated labels:
     * such a change would satisfy every labelling assertion above and silently break the write.
     * `OrderNoteController::store` validates `note_type` as `in:private_note,to_customer`.
     */
    public function test_the_note_still_posts_and_persists_with_its_unchanged_payload(): void
    {
        $this->markup(); // premise: the page renders for this principal

        $response = $this->actingAs($this->merchant)->post(route('order-note.store'), [
            'order_id' => $this->orderId,
            'note' => 'Payload control note',
            'note_type' => 'private_note',
        ]);

        $this->assertContains($response->status(), [200, 201, 302],
            'premise: the note store route accepted the post');

        $note = OrderNote::withoutGlobalScopes()
            ->where('order_id', $this->orderId)
            ->where('notes', 'Payload control note')
            ->first();

        $this->assertNotNull($note, 'the note was not written — the posted payload changed');
        $this->assertSame('private_note', $note->note_type);
    }

    /**
     * NEGATIVE CONTROL 2 — `id="choice_form"` SURVIVES. `OrderDetailTest.php:178` uses that
     * literal as the END anchor of its notes-panel region; rename it and that region runs on to
     * the end of the page, which contains the header greeting with the READER's name — the exact
     * proxy-literal trap. The `.autogrow` and `form-control` class hooks must also survive:
     * `public/js/custom.js:31` is `$(document).on('input', '.autogrow', ...)`, delegated on the
     * CLASS, so adding an id is inert for it but dropping the class stops the box growing. And
     * `ss-select` was ADDED, not substituted, because those options measured 4.03:1 in dark mode.
     */
    public function test_the_repair_took_away_no_hook_a_caller_relies_on(): void
    {
        $markup = $this->markup();

        $this->assertSame(1, substr_count($markup, 'id="choice_form"'),
            'id="choice_form" is an anchor OrderDetailTest.php:178 depends on.');

        $region = $this->noteForm($markup);

        $this->assertStringContainsString('class="autogrow form-control"', $region,
            'the textarea lost a class hook: custom.js:31 delegates on `.autogrow`.');
        $this->assertStringContainsString('form-control ss-select', $region,
            'the note-type select lost `ss-select`, the dark-mode contrast fix.');
        $this->assertStringContainsString('placeholder="Enter Message here..."', $region,
            'the placeholder is deliberately UNCHANGED in this slice: it is absent from '
            . 'resources/lang/ar.json, and ArabicUiCoverageTest scans resources/views/order/ with '
            . 'EXEMPT = [], so wrapping it in __() without adding the key would fail that test. A '
            . 'placeholder is not a label anyway (WCAG 3.3.2) — the visible <label> is the fix.');
        $this->assertStringContainsString('private_note', $region,
            'the option KEY is what gets posted and stored; it must not become a translated label.');
    }

    /**
     * THE OPERATOR IS A SEPARATE PATH. There is no super-admin bypass in Laratrust here —
     * operator status is a STRING COMPARISON on `users.type`, so a platform operator holds no
     * role and `isAbleTo()` alone refuses them. Whatever the page decides to render for them, the
     * labelling property must hold there too; if the operator cannot reach the panel at all, that
     * is recorded rather than asserted, because a vacuous pass is worse than a skip.
     */
    public function test_the_operator_path_is_labelled_too(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => $this->store->id]);

        $this->assertSame('super admin', strtolower(trim((string) $operator->type)),
            'premise: this principal is the platform operator by the string comparison the '
            . 'application really makes');

        $response = $this->actingAs($operator)
            ->get('/order-view/' . Crypt::encrypt($this->orderId));

        // MEASURED, not assumed: the operator reaches this page with status 200 (printed during
        // development). So this is a real assertion, not a conditional that passes on a redirect.
        $this->assertSame(200, $response->status(),
            'premise: the platform operator reached the order detail page');

        $markup = (string) preg_replace(
            '/<script\b[^>]*>.*?<\/script>/is', '', (string) $response->getContent()
        );

        preg_match_all('/<label\b[^>]*\bfor="([^"]*)"/i', $markup, $m);

        foreach ($m[1] as $for) {
            $this->assertNotSame('', trim($for),
                'A `<label for="">` on the operator\'s render of the order page.');
        }

        // The operator holds no role, so `@permission('Create Order Note')` hides the note form
        // from them -- which is why the two order-action selects are what this path really pins.
        $row = $this->actionRow($markup);

        $this->assertStringContainsString('aria-label="' . __('Delivery Boy') . '"', $row,
            'the driver select is unnamed on the operator\'s render');
        $this->assertStringContainsString('aria-label="' . __('Payment Status') . '"', $row,
            'the payment-status select is unnamed on the operator\'s render');
    }
}
