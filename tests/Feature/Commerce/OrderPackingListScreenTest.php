<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MASTER PLAN `ORD-008` (GAP-185) — THE PICKING AND PACKING LIST ON THE ORDER SCREEN.
 * ===================================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:287`,
 * section 04-C — «Picking وPacking والباركود والملصقات والفواتير.» The label and invoice nouns were
 * already two buttons at the top of this very page (`shippinglabel.pdf`, `order.receipt`); picking
 * and packing were absent from the whole codebase, measured by whole-word census.
 *
 * `App\Support\Orders\PackingSlip` is unit-tested in `PackingSlipTest`. This file asserts the half
 * that cannot be: that the document reaches the merchant's screen, with the quantities on it, in
 * both reading directions, and that it prints alone.
 *
 * BOTH SURFACES ARE NOW HERE: the list on the order detail screen, and the standalone
 * `order-packing-list/{id}` document beside the receipt and the label. The route was initially
 * declined because this workstream did not own `routes/web.php`; ownership was handed over and the
 * two edits (the route, and the `OrderDocumentAccessGuard::TARGETS` entry) landed in one change
 * with the cases below. The guard's map is keyed on the URI, so the resemblance to a guarded
 * sibling guards nothing — that entry is what makes the new path safe.
 *
 * ---------------------------------------------------------------------------------------------
 * THIS FILE'S CASES LEAVE FILES IN THE REPOSITORY, AND `.gitignore` DOES NOT COVER THEM.
 *
 * Nothing here uploads, but the neighbouring product-create Feature tests do
 * (`ProductShippingClassOwnershipTest`, `BackorderChoiceIsExplicitTest`), and running these
 * directories therefore leaves real image files under the repository-root `uploads/<store id>/`
 * and `uploads/uploads/<store id>/`. `Utility::upload_file()` prepends `uploads/` to a `$dir` the
 * caller has already built as `uploads/<store id>`, which is why there are two trees.
 *
 * **`.gitignore` covers `/uploads/1/` and `/uploads/uploads/1/` and DELIBERATELY NOT store 2+**,
 * because store 2 is a real store with 188 tracked files at `uploads/2/` and 84 at
 * `uploads/uploads/2/` — adding a rule for it would be a defect, not a fix. So the leaked files
 * look exactly like committable ones. Measured on 2026-11-26 during integration: 12 such untracked
 * files were removed from one workstream's tree, 12 from the integration tree and 6 from another.
 *
 * **Before committing after running these directories: `git status --short | grep uploads`, remove
 * the untracked ones, and `git checkout -- uploads/` afterwards** — `rm -rf uploads/2` deletes the
 * TRACKED files too, which is how this was nearly committed as a deletion.
 */
class OrderPackingListScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private \App\Models\Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        /* Burn store id 1: `getCurrentStore()` answers the literal 1 for an operator with an empty
           `current_store`, and a store under test that IS store 1 collapses distinct numbers. */
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        /* `OrderController::order_view()` gates on these; a 403 would satisfy an absence assertion
           without the document existing at all. */
        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Show Order');
        $this->merchant = User::find($this->merchant->id);
    }

    private function orderWithTwoLines(): Order
    {
        $simple = Product::forceCreate([
            'name' => 'Woollen scarf',
            'slug' => 'woollen-scarf-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'trending' => 0,
            'status' => 1,
            'variant_product' => 0,
            'track_stock' => 1,
            'product_stock' => 50,
            'stock_status' => 'in_stock',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);
        (new Product)->flushCache();

        return $this->makeOrder($this->store, [
            'product_id' => (string) $simple->id,
            'delivered_status' => 4,
            'payment_status' => 'Paid',
            /* `qty` AND `quantity` are both sent, because `Order::order_detail()` keeps only one of
               them depending on `payment_type` and the fixture must survive either branch. */
            'product_json' => json_encode([
                [
                    'product_id' => $simple->id,
                    'name' => 'Woollen scarf as recorded',
                    'qty' => 3,
                    'quantity' => 3,
                    'price' => 10,
                    'orignal_price' => 10,
                    'final_price' => 10,
                    'variant_id' => null,
                    'variant_name' => null,
                ],
                [
                    'product_id' => $simple->id,
                    'name' => 'Linen wrap as recorded',
                    'qty' => 4,
                    'quantity' => 4,
                    'price' => 20,
                    'orignal_price' => 20,
                    'final_price' => 20,
                    'variant_id' => null,
                    'variant_name' => 'Large / Ecru',
                ],
            ]),
        ]);
    }

    /** The rendered order screen, with the premise that it rendered at all. */
    private function screen(Order $order): string
    {
        $response = $this->actingAs($this->merchant)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the order screen did not render, so nothing below measures the packing list. Body: '
            . substr((string) $response->getContent(), 0, 400));

        $body = (string) $response->getContent();

        /* THE PREMISE THE WHOLE FILE RESTS ON. A screen rendering zero line items satisfies every
           assertion here for free -- the exact vacuity `OrderPanelProductScopeTest` records three
           green tests against. */
        $this->assertStringContainsString('as recorded', $body,
            'the screen rendered no line items, so the packing list has nothing to list');

        return $body;
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE DOCUMENT

    /** The list is on the screen, with every line and the unit total. */
    public function test_the_packing_list_is_rendered_with_its_quantities(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString('id="ss-packing-slip"', $body,
            'the picking and packing list is not on the order screen. ORD-008 names Picking and '
            . 'Packing beside the barcode, label and invoice nouns this page already has.');

        $this->assertStringContainsString('Woollen scarf as recorded', $body);
        $this->assertStringContainsString('Linen wrap as recorded', $body);
        $this->assertStringContainsString('Large / Ecru', $body,
            'the variant name is missing, so a packer cannot tell which variant to pull');

        /* 3 + 4 = 7 units. Asserted against the slip's own footer cell rather than anywhere on the
           page, because "7" appears in a date and a price too -- the scoping rule CLAUDE.md §3
           records (an assertion over a whole document answers "does this string exist anywhere"). */
        $footer = $this->slipFooter($body);
        $this->assertStringContainsString('7', $footer,
            'the unit total is not 7 (3 + 4). Footer cell: ' . $footer);
    }

    /** The printed tick box is there, and it is not a form control that posts nowhere. */
    public function test_the_list_offers_a_tick_box_that_is_not_an_input(): void
    {
        $body = $this->slipMarkup($this->screen($this->orderWithTwoLines()));

        $this->assertStringContainsString('ss-packing-slip__box', $body,
            'there is no tick box, so the document is a packing slip but not a PICKING list');
        $this->assertStringNotContainsString('<input', $body,
            'the tick box is a real input. It posts nowhere and has no label, so it would be an '
            . 'unlabelled control on the page — it is printed for a pen.');
    }

    /** No money on the slip — the same contract `PackingSlipTest` pins on the builder. */
    public function test_the_rendered_list_carries_no_money(): void
    {
        $body = $this->slipMarkup($this->screen($this->orderWithTwoLines()));

        foreach (['$', '10.00', '20.00', '70.00'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the rendered packing list contains ' . $needle . '. The slip travels in the '
                . 'parcel; the priced document is the receipt, and putting money here would mean '
                . 'choosing which figure to print.');
        }
    }

    /**
     * IT PRINTS ALONE, AND IN THE RIGHT DIRECTION.
     *
     * The print rule is asserted on the stylesheet it ships with, comments stripped — CLAUDE.md §3
     * records eight occasions where a census was satisfied by prose about the thing it measured, one
     * of them a CSS comment recording a property a draft had used.
     */
    public function test_the_print_rule_isolates_the_slip_and_uses_a_logical_offset(): void
    {
        $css = $this->slipStylesheet($this->screen($this->orderWithTwoLines()));

        $this->assertStringContainsString('@media print', $css,
            'there is no print rule, so printing the slip prints the whole admin page');
        $this->assertStringContainsString('body.ss-print-packing * { visibility: hidden; }', $css,
            'the rest of the page is not blanked while the slip prints');
        $this->assertStringContainsString('inset-inline-start', $css,
            'the printed slip is positioned with a physical offset. This page renders '
            . 'right-to-left in Arabic, so `left` would push it off the page.');
        $this->assertStringNotContainsString('left: 0', $css,
            'a physical `left` offset is still in the print rule');
    }

    /** The class is removed again, so a cancelled print cannot leave the page blank. */
    public function test_the_print_class_is_cleared_after_printing(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString("window.addEventListener('afterprint'", $body,
            'nothing clears the print class, so cancelling the print dialog leaves every element '
            . 'on the order screen invisible');
        $this->assertStringContainsString("classList.remove('ss-print-packing')", $body);
    }

    // ══════════════════════════════════════════════════════════════════════════════ BILINGUAL

    /**
     * RIGHT-TO-LEFT: the list is there, and the page really is in RTL.
     *
     * THE DIRECTION IS A SETTING, NOT THE LOCALE, and the first version of this case got that wrong.
     * `layouts/app.blade.php` sets `dir` from `$setting['SITE_RTL']`, which
     * `Utility::GetValueByName('SITE_RTL')` reads out of the `settings` table for the current
     * store -- so `app()->setLocale('ar')` in a test body changed nothing and the case failed
     * against a page that had rendered `dir="ltr"`. The row is inserted here instead, and the
     * `dir="rtl"` assertion is the premise that proves it took.
     */
    public function test_the_list_renders_right_to_left(): void
    {
        $order = $this->orderWithTwoLines();
        $this->turnRtlOn();

        $body = $this->screen($order);

        $this->assertStringContainsString('dir="rtl"', $body,
            'premise: the page did not render right-to-left, so this case is not measuring the '
            . 'RTL direction at all');
        $this->assertStringContainsString('id="ss-packing-slip"', $body,
            'the packing list disappeared when the page was rendered right-to-left');
        $this->assertStringContainsString('Woollen scarf as recorded', $body,
            'the line items disappeared when the page was rendered right-to-left');

        /*
         * AND THE HEADING IS IN ARABIC. `ArabicUiCoverageTest` caught all six of this partial's
         * strings missing from `resources/lang/ar.json` -- they rendered in English on an Arabic
         * merchant's screen -- so the one a reader sees first is pinned behaviourally here as well
         * as by that census.
         */
        app()->setLocale('ar');
        $this->assertSame('قائمة التجهيز والتغليف', __('Picking and packing list'),
            'the packing list heading has no Arabic translation, so it renders in English on an '
            . 'Arabic merchant\'s screen');
        app()->setLocale('en');

        /* The one property that would break here, asserted on the slip's own stylesheet rather than
           anywhere on the page: a physical `text-align: right` on the quantity column reads as the
           WRONG edge once the page flips. */
        $css = $this->slipStylesheet($body);
        $this->assertStringContainsString('text-align: end', $css,
            'the quantity column is aligned with a physical edge, which lands on the wrong side of '
            . 'a right-to-left slip');
        $this->assertStringNotContainsString('text-align: right', $css);
    }

    /** LEFT-TO-RIGHT — the control for the case above, and the suite's default state. */
    public function test_the_list_renders_left_to_right(): void
    {
        $body = $this->screen($this->orderWithTwoLines());

        $this->assertStringContainsString('Picking and packing list', $body);
        $this->assertStringNotContainsString('dir="rtl"', $body,
            'premise: the default page already rendered right-to-left, so the RTL case above '
            . 'proves nothing about direction');
        $this->assertStringContainsString('id="ss-packing-slip"', $body);
    }

    /**
     * `settings.SITE_RTL = on` for the store under test, with every cache the readers consult
     * cleared.
     *
     * `Setting` is `Cachable` and `Utility::Setting()` memoises per store, so a raw insert alone
     * leaves the layout reading the pre-fixture value -- the measured false reading CLAUDE.md
     * records three times.
     */
    private function turnRtlOn(): void
    {
        \App\Models\Setting::create([
            'name' => 'SITE_RTL',
            'value' => 'on',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        (new \App\Models\Setting)->flushCache();
        \Illuminate\Support\Facades\Cache::flush();
    }

    // ══════════════════════════════════════════════════════════════════════════════ AN EMPTY ORDER

    /** An order with no line items says so rather than rendering an empty table. */
    public function test_an_order_with_no_lines_says_so(): void
    {
        $order = $this->makeOrder($this->store, [
            'delivered_status' => 4,
            'payment_status' => 'Paid',
            'product_json' => '[]',
        ]);

        $response = $this->actingAs($this->merchant)
            ->get(route('order.view', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the order screen did not render for an order with no line items');

        $body = (string) $response->getContent();

        $this->assertStringContainsString('id="ss-packing-slip"', $body);
        $this->assertStringContainsString('no line items to pick', $body,
            'an order with no lines rendered a table with no rows instead of saying so');
    }

    // ════════════════════════════════════════════════════════════ THE STANDALONE DOCUMENT ROUTE

    /**
     * THE PREMISE: the route's RESOLVED stack carries the guard and `Authenticate`.
     *
     * The behavioural cases below would notice its absence (they would read 200 instead of 403),
     * but they would not say WHY, and "the route is in the right group" is the kind of claim that
     * is true when written and silently false after a group boundary moves — `routes/web.php`'s own
     * comments record that boundary moving. Keyed on the uri exactly as the guard's map is.
     */
    public function test_the_document_route_carries_the_guard_and_authentication(): void
    {
        $match = null;

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if ($route->uri() === 'order-packing-list/{id}' && in_array('GET', $route->methods(), true)) {
                $match = $route;
            }
        }

        $this->assertNotNull($match, 'order-packing-list/{id} is not registered');

        $stack = app('router')->gatherRouteMiddleware($match);

        $this->assertContains(\App\Http\Middleware\OrderDocumentAccessGuard::class, $stack,
            'the new document route does not carry OrderDocumentAccessGuard, so nothing decides '
            . 'whose order it serves');
        $this->assertContains(\Illuminate\Auth\Middleware\Authenticate::class, $stack,
            'the new document route is ANONYMOUS. The guard refuses a null principal outright, so '
            . 'this is not a leak — but it means the route has left the host\'s authenticated '
            . 'group, which is a change somebody should answer for.');
        $this->assertArrayHasKey('order-packing-list/{id}',
            (new \ReflectionClass(\App\Http\Middleware\OrderDocumentAccessGuard::class))->getConstant('TARGETS'),
            'the guard no longer targets this uri. Its map is keyed on the URI, so carrying the '
            . 'middleware is not enough — without the entry the guard is inert on this route.');
    }

    /**
     * `order-packing-list/{id}` — THE THIRD SIBLING DOCUMENT.
     *
     * `shippinglabel/pdf/{id}` and `order-receipt/{id}` are the other two, and this one is
     * declared in the same group, with the same `themelanguage` middleware, reached by the same
     * `Crypt::encrypt($order->id)` segment and derived by the same four reads. A reader of
     * `routes/web.php` should see three siblings, not two plus an oddity.
     *
     * THE SEGMENT IS DECRYPTED AND THE ROW IS THE GUARD'S TO DECIDE. The handler asks no ownership
     * question of its own — `OrderDocumentAccessGuard` owns that for every order document, by uri,
     * so one rule covers them all and cannot drift between them. The refusal cases below are what
     * make that safe rather than merely stated.
     */
    public function test_the_owning_merchant_reaches_the_standalone_document(): void
    {
        $order = $this->orderWithTwoLines();

        $response = $this->actingAs($this->merchant)
            ->get(route('order.packing_list', Crypt::encrypt($order->id)));

        $this->assertNotSame(404, $response->status(),
            'order-packing-list/{id} is not routed, so every assertion below would be satisfied '
            . 'by a 404');
        $this->assertSame(200, $response->getStatusCode(),
            'the document did not render for the order\'s own merchant. Body: '
            . substr((string) $response->getContent(), 0, 400));

        $body = (string) $response->getContent();

        $this->assertStringContainsString('Woollen scarf as recorded', $body);
        $this->assertStringContainsString('Linen wrap as recorded', $body);
        $this->assertStringContainsString('Large / Ecru', $body);

        /* A STANDALONE DOCUMENT, not the order screen: its own <html>, and none of the admin
           chrome the screen carries. Without this the case passes against a handler that just
           re-rendered `order.view`. */
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
        $this->assertStringNotContainsString('id="ss-packing-slip-card"', $body,
            'the route rendered the ORDER SCREEN rather than a standalone document');

        /* AND NO MONEY ON IT, the same contract `PackingSlipTest` pins on the builder and the
           screen case pins on the partial. The standalone document is a third print of the same
           lines, so it needs its own assertion -- a contract pinned on two of three prints is a
           contract the third can break. */
        foreach (['10.00', '20.00', '70.00'] as $needle) {
            $this->assertStringNotContainsString($needle, $body,
                'the standalone packing document contains ' . $needle . '. The slip travels in '
                . 'the parcel; the priced document is the receipt.');
        }

        /*
         * THE SAME THREE PROPERTIES THE SCREEN CASES PIN, ASSERTED HERE TOO — and these exist
         * because the mutation run proved they were needed: a physical `text-align`, a zeroed unit
         * total and a real `<input>` tick box all SURVIVED on this document while the screen's
         * assertions stayed green, because those are scoped to the partial's block and this is a
         * different print of the same lines. A contract pinned on two of three prints is a
         * contract the third can break.
         */
        $this->assertMatchesRegularExpression('/<th[^>]*>\s*' . preg_quote(__('Total units to pack'), '/')
            . '\s*<\/th>\s*<td class="ss-doc__qty">\s*7\s*<\/td>/', $body,
            'the standalone document\'s unit total is not 7 (3 + 4), asserted on the footer cell '
            . 'rather than anywhere on the page');

        $this->assertStringContainsString('ss-doc__box', $body,
            'the standalone document has no tick box, so it is a packing slip but not a PICKING '
            . 'list');
        $this->assertStringNotContainsString('<input', $body,
            'the standalone document\'s tick box is a real form control. It posts nowhere and has '
            . 'no label — it is printed for a pen.');

        $this->assertStringContainsString('text-align: end', $body,
            'the standalone document aligns a column with a physical edge, which lands on the '
            . 'wrong side once the document is rendered right-to-left');
        $this->assertStringNotContainsString('text-align: right', $body);
    }

    /**
     * ANOTHER STORE'S ORDER IS REFUSED, AND THE ASSERTION IS KEYED ON THE REASON.
     *
     * 403 is the ONLY thing `OrderDocumentAccessGuard::deny()` produces for a web caller
     * (`abort(403)`), and the handler itself cannot produce one — it has no ownership check, and
     * its own failure branches are a 302 back with a flash. So a 403 here is the guard and nothing
     * else. `test_the_handlers_own_failure_is_a_302_and_not_a_403` below is the other half of that
     * claim: without it, "it was refused" could be the handler's not-found branch, and CLAUDE.md
     * records a 302 from a missing row and a 302 from a gate being the same status and opposite
     * facts.
     */
    public function test_another_stores_order_is_refused_by_the_guard(): void
    {
        $order = $this->orderWithTwoLines();

        [$outsider] = $this->makeTenant();
        $outsider = User::find($outsider->id);
        /* The outsider holds the permission, so a permission failure cannot be what refuses. */
        $this->grantPermission($outsider, \App\Http\Middleware\OrderDocumentAccessGuard::PERMISSION);

        /*
         * THE PREMISE THAT MAKES 403 MEAN OWNERSHIP, and it is needed because of what the case
         * below measured: the guard FAILS CLOSED on a missing row, so a 403 alone is also what an
         * order that does not exist produces. The row must still be there, and the owner must get
         * 200 from the identical request, or "it was refused" says nothing about tenancy.
         */
        $this->assertNotNull(\DB::table('orders')->where('id', $order->id)->first(),
            'premise: the order is gone, so the 403 below would be the guard failing closed on a '
            . 'missing row rather than refusing another tenant');

        $this->assertSame(200, $this->actingAs($this->merchant)
            ->get(route('order.packing_list', Crypt::encrypt($order->id)))->getStatusCode(),
            'premise: the OWNER cannot read this document either, so the outsider\'s refusal is '
            . 'not about ownership');

        $response = $this->actingAs($outsider)
            ->get(route('order.packing_list', Crypt::encrypt($order->id)));

        $this->assertNotSame(404, $response->status(), 'the route does not exist');
        $this->assertSame(403, $response->getStatusCode(),
            'ANOTHER STORE\'S PACKING LIST WAS SERVED. The new route reaches the same document '
            . 'without the order screen around it, so it needs its own '
            . '`OrderDocumentAccessGuard::TARGETS` entry — that map is keyed on the URI, so a new '
            . 'order-document surface is NOT guarded by implication however closely it resembles '
            . 'one that is.');

        $this->assertStringNotContainsString('Woollen scarf as recorded', (string) $response->getContent(),
            'the refused response still carried the other store\'s line items');
    }

    /**
     * THE GUARD REFUSES A MISSING ORDER TOO, SO THE HANDLER'S OWN NOT-FOUND BRANCH IS UNREACHABLE
     * OVER HTTP — and this case exists because I assumed the opposite and the test said otherwise.
     *
     * The plan was to key the cross-tenant refusal on 403-versus-302: 403 the guard's, 302 the
     * handler's. Measured: an id that decrypts to no order answers **403**, because the guard
     * reads the row to get its `store_id`, finds none, and FAILS CLOSED rather than deferring —
     * which its own docblock says in as many words and which is the right behaviour. So the two
     * statuses do NOT separate "not yours" from "not there", and the cross-tenant case above is
     * keyed on the premise pair instead: the row exists, and the owner gets 200 from the identical
     * request.
     *
     * That leaves a claim no HTTP test can make, which is CLAUDE.md's rule in the mirror — a
     * handler-level branch behind an `abort()`ing middleware is invisible to an HTTP test — so the
     * handler is CALLED DIRECTLY, the documented remedy. The point is not academic: if the
     * handler's branch ever became a 403 of its own, the HTTP assertions here would not notice.
     */
    public function test_a_missing_order_is_refused_by_the_guard_before_the_handler_is_reached(): void
    {
        $missing = (int) \DB::table('orders')->max('id') + 5000;

        $response = $this->actingAs($this->merchant)
            ->get(route('order.packing_list', Crypt::encrypt($missing)));

        $this->assertNotSame(404, $response->status(), 'the route does not exist');
        $this->assertNotSame(200, $response->getStatusCode(),
            'an id naming no order rendered a document');
        $this->assertSame(403, $response->getStatusCode(),
            'a missing order is no longer refused by the guard. If it now reaches the handler, the '
            . 'cross-tenant case above must be re-read: it is keyed on the row existing and the '
            . 'owner getting 200, not on this status.');

        /*
         * AND THE HANDLER'S OWN BRANCH, which that 403 hides. Called directly, with the default
         * guard put back first: `actingAs()` would leave `web` resolved for this merchant, and a
         * direct call then runs under a tighter scope than production (CLAUDE.md trap 5). Nothing
         * on this path needs a principal — the guard is not in it.
         */
        $this->app['auth']->shouldUse('web');
        auth('web')->logout();
        $this->assertNull(auth()->user(), 'premise: a principal is still resolved, so this direct '
            . 'call is not running under production\'s guard state — the HTTP request above left '
            . 'the merchant signed into the web guard');

        $direct = app(\App\Http\Controllers\OrderController::class)
            ->order_packing_list(Crypt::encrypt($missing));

        $this->assertInstanceOf(\Illuminate\Http\RedirectResponse::class, $direct,
            'the handler\'s not-found branch is no longer the redirect-back its two sibling '
            . 'documents use. Nothing over HTTP can see this, because the guard answers 403 first.');
        $this->assertSame('Order Not Found.', $direct->getSession()->get('error'),
            'the handler redirected without saying why, so a future reader cannot tell its '
            . 'not-found branch from its exception branch');
    }

    /** A forged segment the encrypter refuses is the same answer, and for the same reason. */
    public function test_an_undecryptable_segment_is_not_a_document(): void
    {
        $response = $this->actingAs($this->merchant)->get('/order-packing-list/not-a-ciphertext');

        $this->assertNotSame(404, $response->status(), 'the route does not exist');
        $this->assertNotSame(200, $response->getStatusCode(),
            'an undecryptable segment rendered a document');

        /* 403: `resolveOrderId()` cannot read an order id out of it, so the guard denies before
           the handler's own DecryptException arm is reached. Asserted rather than assumed, because
           the opposite reading is what this file got wrong once already. */
        $this->assertSame(403, $response->getStatusCode());
    }

    /** The standalone document renders right-to-left too, from the same setting as the screen. */
    public function test_the_standalone_document_renders_right_to_left(): void
    {
        $order = $this->orderWithTwoLines();
        $this->turnRtlOn();

        $response = $this->actingAs($this->merchant)
            ->get(route('order.packing_list', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the document did not render. Body: ' . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('dir="rtl"', $body,
            'the standalone document is not right-to-left for an RTL store. It reads the direction '
            . 'from the ORDER\'s store through DocumentDirection, exactly as the receipt and the '
            . 'label do (P1-SEC-11 criterion 4) — not from the viewer\'s locale.');
        $this->assertStringContainsString('Woollen scarf as recorded', $body);
    }

    /**
     * THE OPERATOR PRINTING A MERCHANT'S DOCUMENT IS THE ONLY CASE THAT CAN SEE WHOSE STORE THE
     * DIRECTION AND THE LOGO COME FROM.
     *
     * P1-SEC-11 criteria 1 and 4, and the reason this case exists rather than being folded into
     * the RTL one above: for the order's OWN merchant, "the order's store" and "the viewer's
     * store" are ONE number, so a handler that resolved the direction from `getCurrentStore()`
     * would pass every assertion in this file. The platform operator separates them — their
     * `current_store` is a different store entirely — so a viewer-derived direction reads LTR here
     * and a viewer-derived logo is the operator's.
     *
     * That collapse is the one CLAUDE.md records costing four surviving mutants in
     * `PlatformUploaderDoorsTest`, and `shippinglabel()`'s own history is the same defect: the
     * operator printing a merchant's label got the OPERATOR's logo on it.
     */
    public function test_the_operator_prints_the_orders_store_direction_and_logo(): void
    {
        $order = $this->orderWithTwoLines();
        $this->turnRtlOn();

        \App\Models\Setting::create([
            'name' => 'invoice_logo',
            'value' => 'uploads/ord008-merchant-logo.png',
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);
        (new \App\Models\Setting)->flushCache();
        \Illuminate\Support\Facades\Cache::flush();

        $operator = $this->makePlatformAdmin();
        $this->satisfyUpdaterGate();

        $this->assertNotSame((int) $this->store->id, (int) getCurrentStore($operator->id),
            'premise: the operator resolves the SAME store as the order, so this case cannot tell '
            . 'an order-derived document from a viewer-derived one');

        $response = $this->actingAs($operator)
            ->get(route('order.packing_list', Crypt::encrypt($order->id)));

        $this->assertSame(200, $response->getStatusCode(),
            'the operator was refused their own platform\'s document. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();

        $this->assertStringContainsString('dir="rtl"', $body,
            'the document rendered LEFT-TO-RIGHT for the operator although the ORDER\'s store is '
            . 'right-to-left, so the direction is coming from the VIEWER rather than the order');
        $this->assertStringContainsString('uploads/ord008-merchant-logo.png', $body,
            'the operator\'s own logo was printed on the merchant\'s document, or none was — the '
            . 'logo must come from the ORDER\'s store (P1-SEC-11 criterion 1)');
        $this->assertStringContainsString('Woollen scarf as recorded', $body);
    }

    // ══════════════════════════════════════════════════════════════════════════════ HELPERS

    /** Just the slip's own markup, so an assertion cannot be answered by the rest of the page. */
    private function slipMarkup(string $body): string
    {
        $start = strpos($body, 'id="ss-packing-slip"');
        $this->assertNotFalse($start, 'the slip is not on the page, so it cannot be scoped to');

        /* The partial emits `<!-- /ss-packing-slip -->` for exactly this: without a hard end
           anchor the scope ran on for 905 lines and the no-money and no-input assertions were
           answering about the whole order screen, which has prices and form controls of its own. */
        $end = strpos($body, '<!-- /ss-packing-slip -->', $start);
        $this->assertNotFalse($end, 'the slip\'s end anchor is missing, so these assertions would '
            . 'be answered by the rest of the order screen rather than by the slip');

        return substr($body, $start, ($end === false ? strlen($body) : $end) - $start);
    }

    /** The footer cell holding the unit total. */
    private function slipFooter(string $body): string
    {
        $markup = $this->slipMarkup($body);
        $start = strpos($markup, '<tfoot>');
        $this->assertNotFalse($start, 'the slip has no footer, so the unit total is not printed');

        return substr($markup, $start, (strpos($markup, '</tfoot>') ?: strlen($markup)) + 8 - $start);
    }

    /** The slip's own stylesheet, with CSS comments stripped. */
    private function slipStylesheet(string $body): string
    {
        $start = strpos($body, '.ss-packing-slip__head');
        $this->assertNotFalse($start, 'the slip\'s stylesheet is not on the page');

        $end = strpos($body, '</style>', $start);
        $css = substr($body, $start, ($end === false ? strlen($body) : $end) - $start);

        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
