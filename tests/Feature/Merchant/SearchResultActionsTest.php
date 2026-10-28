<?php

namespace Tests\Feature\Merchant;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-8 — a search result offers the actions the principal may actually perform on it.
 * =================================================================================================
 *
 * THE ACCEPTANCE IS ABOUT THE WORD "ACTUALLY". P2-06-2 already gates each SECTION of the search:
 * a merchant without `Manage Order` gets no orders section at all. An action is a finer question,
 * because the permission that lets someone FIND a product is not the permission that lets them
 * change it: `Manage Products` lists, `Edit Products` edits
 * (`ProductController::update`:1538), `Show Customer` opens a customer
 * (`CustomerController::show`:7). Offering an Edit button to a merchant who holds only
 * `Manage Products` would be an affordance that 403s — which is the defect this row exists to
 * prevent, and the ledger's required test is exactly "an ungated principal is offered none".
 *
 * EVERY ACTION IS DECIDED SERVER-SIDE, and the reason is the same one P2-06-2 recorded for the
 * sections: a palette that filtered actions client-side would ship the full action list to every
 * caller and let the browser decide what to hide. The response must not contain an action the
 * principal may not perform.
 *
 * OPERATOR FIRST, because `isAbleTo()` alone refuses the platform operator — the controller's own
 * `may()` helper already encodes that and the actions reuse it rather than spelling it again.
 *
 * NOTHING DESTRUCTIVE IS OFFERED HERE, deliberately. `product.destroy` exists and a merchant with
 * `Delete Products` may call it, so "the actions the principal may perform" could be read to
 * include it. It is left out: a delete sitting one keystroke from a search box, in a list where
 * arrow keys move the selection, is a misclick that destroys a record, and the confirmation that
 * makes it safe lives on the record's own screen. The palette offers ways IN to a record, and the
 * record's own page offers the ways to change it.
 */
class SearchResultActionsTest extends SecurityTestCase
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

        $this->grantPermission($this->merchant, 'Manage Products');
        $this->merchant = User::find($this->merchant->id);
    }

    private function url(string $term): string
    {
        return '/merchant-search?' . http_build_query(['q' => $term]);
    }

    private function makeProduct(string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);
    }

    private function makeCustomer(string $name): Customer
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Customer::forceCreate([
            'first_name' => $first,
            'last_name' => $last,
            'email' => Str::slug($name) . '-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'store_id' => $this->store->id,
        ]);
    }

    /** @return array<int, string> every action key offered anywhere in the payload */
    private function actionKeys(array $payload): array
    {
        $out = [];

        foreach ($payload['sections'] ?? [] as $section) {
            foreach ($section['items'] ?? [] as $item) {
                foreach ($item['actions'] ?? [] as $action) {
                    $out[] = $action['key'];
                }
            }
        }

        return $out;
    }

    /** PREMISE: the search itself works, so an empty action list means something. */
    public function test_premise_the_search_returns_the_product(): void
    {
        $this->makeProduct('Zaatar Blend');

        $payload = $this->actingAs($this->merchant)->getJson($this->url('Zaatar'))
            ->assertOk()->json();

        $this->assertSame(['products'], array_column($payload['sections'], 'key'));
    }

    /**
     * THE LEDGER'S REQUIRED TEST. `Manage Products` lets this merchant FIND the product; it does
     * not let them edit it. So the result must carry no actions at all.
     */
    public function test_a_principal_without_action_permissions_is_offered_none(): void
    {
        $this->makeProduct('Zaatar Blend');

        $payload = $this->actingAs($this->merchant)->getJson($this->url('Zaatar'))
            ->assertOk()->json();

        $this->assertSame([], $this->actionKeys($payload),
            'a merchant holding only Manage Products was offered an action on the result');
    }

    /** And with the edit permission, the edit action appears — and only that one. */
    public function test_the_edit_action_appears_with_the_edit_permission(): void
    {
        $this->makeProduct('Zaatar Blend');
        $this->grantPermission($this->merchant, 'Edit Products');
        $m = User::find($this->merchant->id);

        $payload = $this->actingAs($m)->getJson($this->url('Zaatar'))->assertOk()->json();

        $this->assertSame(['product.edit'], $this->actionKeys($payload));
    }

    /**
     * NOTHING DESTRUCTIVE, even for a principal who holds the permission for it. This is a design
     * decision rather than an authorisation one, so it is asserted rather than left to be
     * re-litigated: a delete beside a search box is a misclick away from losing a record.
     */
    public function test_no_destructive_action_is_offered_even_when_permitted(): void
    {
        $this->makeProduct('Zaatar Blend');
        $this->grantPermission($this->merchant, 'Edit Products');
        $this->grantPermission($this->merchant, 'Delete Products');
        $m = User::find($this->merchant->id);

        $payload = $this->actingAs($m)->getJson($this->url('Zaatar'))->assertOk()->json();

        $keys = $this->actionKeys($payload);

        $this->assertNotContains('product.destroy', $keys);
        $this->assertSame([], array_filter($keys, static fn ($k) => str_contains($k, 'destroy')
            || str_contains($k, 'delete')),
            'a destructive action is offered from the search palette');
    }

    /** A customer result offers the view action only to a principal who may open one. */
    public function test_a_customer_action_follows_show_customer(): void
    {
        $this->makeCustomer('Nadia Haddad');
        $this->grantPermission($this->merchant, 'Manage Customer');
        $m = User::find($this->merchant->id);

        $payload = $this->actingAs($m)->getJson($this->url('Nadia'))->assertOk()->json();
        $this->assertSame([], $this->actionKeys($payload),
            'a customer result offered an action to a principal without Show Customer');

        $this->grantPermission($m, 'Show Customer');
        $m = User::find($m->id);

        $payload = $this->actingAs($m)->getJson($this->url('Nadia'))->assertOk()->json();
        $this->assertSame(['customer.show'], $this->actionKeys($payload));
    }

    /**
     * ORDERS GATE ON `Show Order`, NOT ON THE SECTION'S `Manage Order` — and this test exists
     * because I first wrote the opposite into a comment: "this application has no narrower order
     * permission". It was false. `OrderController::order_view` gates on
     * `mayUseOrderScreen('Show Order')`, and P2-06-3 had already recorded the consequence on the
     * notification screen: "the notification gates on `Manage Order`; the preview gates on
     * `Show Order`. A caller can hold the first and not the second, and without this the button
     * answered 403."
     *
     * SO THE ROW ITSELF IS GATED, NOT ONLY THE ACTION, which is the part a "quick actions" change
     * would naturally miss: the section gate lets this principal SEE the order, and the preview
     * the row opens refuses them.
     */
    public function test_an_order_offers_nothing_without_show_order(): void
    {
        $this->grantPermission($this->merchant, 'Manage Order');
        $m = User::find($this->merchant->id);
        $order = $this->makeOrder($this->store, ['product_order_id' => 'SS-77001']);

        $payload = $this->actingAs($m)->getJson($this->url('SS-77001'))->assertOk()->json();

        $items = $payload['sections'][0]['items'] ?? [];
        $this->assertNotEmpty($items, 'premise: the order was not found at all');

        $this->assertSame([], $this->actionKeys($payload),
            'an order action was offered to a principal without Show Order');
        $this->assertNull($items[0]['panel'] ?? null,
            'the row still opens the preview panel, which refuses this principal with a 403');
        $this->assertStringNotContainsString('order-preview', (string) $items[0]['href'],
            'the row still points at the preview this principal may not open');
    }

    /** And with `Show Order`, both the row and the action open the preview. */
    public function test_an_order_offers_the_preview_with_show_order(): void
    {
        $this->grantPermission($this->merchant, 'Manage Order');
        $this->grantPermission($this->merchant, 'Show Order');
        $m = User::find($this->merchant->id);
        $order = $this->makeOrder($this->store, ['product_order_id' => 'SS-77002']);

        $payload = $this->actingAs($m)->getJson($this->url('SS-77002'))->assertOk()->json();
        $items = $payload['sections'][0]['items'] ?? [];

        $this->assertNotEmpty($items, 'premise: the order was not found at all');
        $this->assertSame(['order.view'], $this->actionKeys($payload));
        $this->assertSame('ss-order-preview-panel', $items[0]['panel'] ?? null);

        /* THE ACTION GOES TO THE REAL ORDER PAGE, not the preview panel, and that is a fix rather
           than a preference: `ss-order-preview-panel` exists in only three views, so a
           panel-opening action was a DEAD CLICK everywhere else — including `/product`, where the
           live check drove it. `order.view` works from any screen and its handler gates on the
           same `Show Order` checked here. */
        $this->assertArrayNotHasKey('panel', $items[0]['actions'][0],
            'the action still opens a panel that does not exist on most screens');
        $this->assertStringContainsString('order-view/', (string) $items[0]['actions'][0]['href']);
    }

    /**
     * THE PLATFORM OPERATOR, because `isAbleTo()` alone refuses them and an action list built on
     * it would be empty for the one principal who may do everything.
     */
    public function test_the_operator_is_offered_the_actions(): void
    {
        $this->makeProduct('Zaatar Blend');

        $operator = $this->makePlatformAdmin();

        $payload = $this->actingAs($operator)->getJson($this->url('Zaatar'))->assertOk()->json();

        $this->assertContains('product.edit', $this->actionKeys($payload),
            'the platform operator was offered no actions, which is isAbleTo() refusing them');
    }

    /**
     * AN ACTION'S HREF IS BUILT FROM THE RECORD THE SEARCH ALREADY AUTHORISED, never from
     * request input, and it must point at the record it is attached to. A row whose action
     * pointed at a different id would be a cross-record affordance even with the permission held.
     */
    public function test_an_action_points_at_its_own_record(): void
    {
        /* TWO PRODUCTS, BECAUSE ONE CANNOT CATCH THE DEFECT THIS NAMES. Review was right about
           this: with a single record, an implementation that gave EVERY action the first result's
           id would pass, and that is precisely the bug "points at its own record" is about. A
           changed id is caught by one record; a shared id is not. */
        $first = $this->makeProduct('Zaatar Blend');
        $second = $this->makeProduct('Zaatar Premium');

        $this->grantPermission($this->merchant, 'Edit Products');
        $m = User::find($this->merchant->id);

        $payload = $this->actingAs($m)->getJson($this->url('Zaatar'))->assertOk()->json();
        $items = $payload['sections'][0]['items'];

        $this->assertCount(2, $items, 'premise: both products must be in the result set');

        $expected = [];
        foreach ([$first, $second] as $p) {
            $expected[$p->name] = route('product.edit', $p->id);
        }

        foreach ($items as $item) {
            $this->assertSame($expected[$item['title']], $item['actions'][0]['href'],
                'the action on "' . $item['title'] . '" points at another record');
        }

        // And the two differ, so a shared id cannot satisfy the loop above.
        $hrefs = array_map(static fn (array $i) => $i['actions'][0]['href'], $items);
        $this->assertCount(2, array_unique($hrefs),
            'both results carry the same action href, so one of them is wrong');
    }

    /** Every action carries a human label, in the caller's language. */
    public function test_every_action_is_labelled(): void
    {
        $this->makeProduct('Zaatar Blend');
        $this->grantPermission($this->merchant, 'Edit Products');
        $m = User::find($this->merchant->id);

        $payload = $this->actingAs($m)->getJson($this->url('Zaatar'))->assertOk()->json();

        foreach ($payload['sections'] as $section) {
            foreach ($section['items'] as $item) {
                foreach ($item['actions'] ?? [] as $action) {
                    $this->assertNotEmpty($action['label'] ?? '',
                        'an action was offered with no label: ' . json_encode($action));
                }
            }
        }
    }
}
