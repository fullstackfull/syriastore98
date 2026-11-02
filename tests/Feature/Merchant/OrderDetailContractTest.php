<?php

namespace Tests\Feature\Merchant;

use App\Models\OrderNote;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-6 — THE ORDER DETAIL PAGE'S CONTRACT, pinned BEFORE the design-system migration.
 * ============================================================================================
 *
 * The order is deliberate and is the lesson the settings panels taught: a migration that renames
 * a field or moves a form's target breaks the save SILENTLY — the screen still renders, the button
 * still submits, and the handler reads a key that is no longer posted. F117 met one name that
 * crossed a boundary that way, found only by driving the real save.
 *
 * SO THIS PINS THE SET OF NAMES, THE ROUTES AND THE CONTROLS, AND DELIBERATELY PINS NO MARKUP,
 * CLASS, ORDER OR LABEL — all of which the migration is meant to change. A test that pinned those
 * would simply be rewritten by the change it is supposed to guard, which makes it theatre.
 *
 * WHAT IT DOES NOT COVER, because other files already do and duplicating them would rot: the
 * correctness defects (`OrderDetailTest`), the note author (`OrderNoteAuthorTest`), the order
 * source row (`OrderSourceAttributionTest`), and authorization (`OrderNoteAuthorizationTest`,
 * `OrderDocumentAccessTest`).
 */
class OrderDetailContractTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Every field name the page posts. A migration must not add to or remove from this set. */
    private const FIELD_NAMES = ['delivery_boy', 'note', 'note_type', 'order_id', 'payment_status'];

    /** Every route the page targets. */
    private const ROUTES = [
        'order.assign', 'order.index', 'order.payment.status', 'order.receipt',
        'order.return', 'order.status.change', 'shippinglabel.pdf',
    ];

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

        foreach (['Show Order', 'Manage Order', 'Manage Order Note', 'Create Order Note',
                  'Delete Order Note'] as $p) {
            $this->grantPermission(User::find($this->merchant->id), $p);
        }

        $this->merchant = User::find($this->merchant->id);

        $product = Product::forceCreate([
            'name' => 'Contract Scarf', 'slug' => 'contract-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->merchant->id,
        ]);

        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'CONTRACT-1',
            'product_json' => json_encode([[
                'product_id' => $product->id, 'name' => $product->name, 'qty' => 1,
                'price' => 10.0, 'total_orignal_price' => 10.0, 'final_price' => 10.0,
            ]]),
            'product_price' => 10.0,
            'product_id' => (string) $product->id,
        ]);

        $this->orderId = $order->id;

        // One note of each shape the page branches on, so every branch is rendered.
        foreach ([
            ['Stock Manage', 'private_note'],
            ['Order Created', 'created'],
            ['Order status change', 'created'],
            ['', 'private_note'],
            ['', 'to_customer'],
        ] as [$status, $type]) {
            OrderNote::forceCreate([
                'order_id' => $order->id, 'customer_id' => null,
                'notes' => 'Note for ' . ($status ?: $type),
                'note_type' => $type, 'status' => $status,
                'store_id' => $this->store->id,
            ]);
        }
    }

    private function html(): string
    {
        $response = $this->actingAs($this->merchant)
            ->get('/order-view/' . Crypt::encrypt($this->orderId));

        $this->assertSame(200, $response->status(),
            'premise: the detail page rendered. Every assertion below is vacuous on a redirect.');

        return $response->getContent();
    }

    public function test_every_posted_field_name_is_unchanged(): void
    {
        $body = $this->html();

        foreach (self::FIELD_NAMES as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $body,
                "The field `{$name}` is no longer posted by this page. A renamed field breaks the "
                . 'save SILENTLY: the screen renders, the button submits, and the handler reads a '
                . 'key that is not there.');
        }
    }

    /**
     * AND NO NEW FIELD EITHER. These handlers write what they are posted; a new name is a
     * behaviour decision and should be a visible edit to this list rather than a side effect of
     * restyling.
     */
    public function test_no_field_name_was_added(): void
    {
        $body = $this->html();

        /*
         * FORM CONTROLS ONLY. The first version matched every `name="..."` attribute in the
         * document and reported `viewport, author, title, keywords, description` as new posted
         * fields -- those are the layout's `<meta>` tags. A scan of the whole document answers a
         * question about the document; this one has to answer about the form.
         */
        /*
         * SCRIPTS STRIPPED FIRST. A shared include builds product-attribute markup as JS strings
         * (`'visible_attribute_' + i + '"'`), and those were reported as new posted fields --
         * they are not fields this page posts, they are text inside a script. Same class of
         * mistake as matching the layout's <meta name> tags, one layer in.
         */
        $markup = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $body);

        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"/i', (string) $markup, $m);

        $found = array_map(
            // `filters[ss_payment]` style names reduce to their base; none is expected here, but
            // a future repeater must not slip past as a distinct name.
            static fn (string $n): string => preg_replace('/\[.*$/', '', $n),
            $m[1]
        );

        // The framework's own, which every form carries.
        $framework = ['_token', '_method'];
        $unexpected = array_values(array_diff(array_unique($found), self::FIELD_NAMES, $framework));

        $this->assertSame([], $unexpected,
            'New posted field name(s) on the order detail page: ' . implode(', ', $unexpected)
            . '. If intended, add them to self::FIELD_NAMES in the same commit.');
    }

    public function test_every_route_the_page_targets_is_still_targeted(): void
    {
        $body = $this->html();

        /*
         * BY PATH PREFIX, NOT BY FULL URL, AND THE REASON IS NOT STYLE. `Crypt::encrypt()` is
         * randomised -- it carries a fresh IV per call -- so `route('order.receipt',
         * Crypt::encrypt($id))` produces a DIFFERENT string every time and can never be compared
         * against what the page rendered. The first version of this test did exactly that and
         * failed for a reason that had nothing to do with the page.
         *
         * (That randomness is also why CLAUDE.md calls an encrypted id in a url an encoding
         * rather than a control: the APP_KEY is in git history, so the value is forgeable even
         * though it is unguessable.)
         */
        /*
         * THE PREFIXES ARE DERIVED FROM `route()`, not written out here. Hand-written paths were
         * wrong on the first attempt (`/shippinglabel-pdf/` against the real
         * `/shippinglabel/pdf/`) -- and a hand-written path is a second copy of the routing table
         * that drifts the moment a uri changes, which is the opposite of what a contract test is
         * for. Generating with a placeholder and cutting at it gives the real prefix for a
         * parameterised route without depending on the parameter.
         */
        foreach (self::ROUTES as $name) {
            if ($name === 'order.index') {
                continue;
            }

            /*
             * A PARAMETERLESS ROUTE APPENDS THE PLACEHOLDER AS A QUERY STRING, which made the
             * expected prefix `/order-assign?` -- a string the page correctly does not contain.
             * So try the bare url first and only fall back to the placeholder for routes that
             * require a parameter.
             */
            try {
                $prefix = route($name);
            } catch (\Throwable) {
                $prefix = explode('SSPLACEHOLDER', route($name, 'SSPLACEHOLDER'))[0];
            }

            $this->assertNotSame('', $prefix, "premise: `{$name}` generated a url");

            $this->assertStringContainsString($prefix, $body,
                "the page no longer references `{$name}` (expected a url beginning `{$prefix}`)");
        }

        $this->assertStringContainsString(route('order.index'), $body,
            'and the way back to the list');
    }

    public function test_the_note_form_still_posts_to_the_note_store_route(): void
    {
        $body = $this->html();

        $this->assertStringContainsString('action="' . route('order-note.store') . '"', $body);
        $this->assertStringContainsString('name="note_type"', $body);
        $this->assertStringContainsString('name="note"', $body);
    }

    /**
     * ONE DELETE CONTROL PER NOTE, whatever the markup becomes. The page has five near-identical
     * branches today; collapsing them is the obvious simplification and this is what stops the
     * collapse losing a control.
     */
    public function test_every_note_has_exactly_one_delete_control(): void
    {
        $body = $this->html();

        $notes = OrderNote::withoutGlobalScopes()->where('order_id', $this->orderId)->get();

        $this->assertCount(5, $notes, 'premise: all five note shapes are present');

        foreach ($notes as $note) {
            $this->assertStringContainsString('Note for', $body,
                'premise: notes render at all');

            $this->assertSame(1,
                substr_count($body, route('order-note.destroy', $note->id)),
                "note {$note->id} must have exactly one delete control -- not zero (the control "
                . 'was lost) and not two (a branch was duplicated)');
        }
    }

    /** Every note's text reaches the page, whichever branch renders it. */
    public function test_every_note_shape_still_renders_its_text(): void
    {
        $body = $this->html();

        foreach (['Stock Manage', 'Order Created', 'Order status change', 'private_note', 'to_customer'] as $shape) {
            $this->assertStringContainsString('Note for ' . $shape, $body,
                "the branch rendering `{$shape}` notes stopped showing their text");
        }
    }

    /**
     * The order's own figures. A restyle that re-rendered the summary from a different source
     * would be a correctness change dressed as a visual one.
     */
    public function test_the_order_summary_still_shows_its_figures(): void
    {
        $body = $this->html();

        $this->assertStringContainsString('CONTRACT-1', $body, 'the order reference');
        $this->assertStringContainsString('Contract Scarf', $body, 'the line item');

        foreach (['Payment Type', 'Order Status', 'Source'] as $label) {
            $this->assertStringContainsString($label, $body, "the `{$label}` row");
        }
    }
}
