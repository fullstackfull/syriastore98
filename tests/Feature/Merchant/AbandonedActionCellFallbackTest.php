<?php

namespace Tests\Feature\Merchant;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE ACTION COLUMN MUST NOT BE A HEADER OVER AN EMPTY CELL.  P2.5-PKG-I
 * =================================================================================================
 *
 * FOUND BY LOOKING AT A CAPTURE, which is the only reason it was found: a 105-agent review had
 * called this surface "REACHABLE -- all three controls have a registered route, a real handler and
 * a rendered partial". True of the source, false of the running screen. The table rendered
 *
 *     HEADERS: ["", "العميل", "البريد الإلكتروني", "المنتجات", "العمل"]
 *     ROW 0:   ["", "Nour Saleh", "nour.saleh@example.test", "5", ""]
 *
 * -- a column titled "Action" whose every cell was LITERALLY EMPTY for a viewer holding none of
 * `Abandon Cart` / `Delete Cart` (and the wishlist equivalents). `@permission` compiles to
 * Laratrust's `hasPermission()`, which has NO super-admin bypass, so the PLATFORM OPERATOR met the
 * same empty column.
 *
 * WHAT IS ASSERTED, AND WHAT IS DELIBERATELY NOT. The fallback must appear, and the three controls
 * must STAY ABSENT -- the repair tells a viewer they cannot act; it does not let them. A test that
 * only checked for the dash would pass a "fix" that granted everybody the buttons, which is a
 * permission-model change and is not authorised.
 */
class AbandonedActionCellFallbackTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];

    private function query(): array
    {
        $q = ['draw' => 1, 'start' => 0, 'length' => 10];
        foreach (['customer_id', 'email', 'product', 'action'] as $i => $name) {
            $q["columns[{$i}][data]"] = $name;
            $q["columns[{$i}][name]"] = $name;
            $q["columns[{$i}][searchable]"] = 'true';
            $q["columns[{$i}][orderable]"] = 'true';
            $q["columns[{$i}][search][value]"] = '';
            $q["columns[{$i}][search][regex]"] = 'false';
        }
        $q['order[0][column]'] = 0;
        $q['order[0][dir]'] = 'asc';
        $q['search[value]'] = '';
        $q['search[regex]'] = 'false';

        return $q;
    }

    /** @return array{0:\App\Models\User} */
    private function arrange(array $extraPermissions): array
    {
        [$merchant, $store] = $this->makeTenant();

        // The LIST permission only -- without it the handler answers redirect()->back() and there
        // is no table to measure. The ACTION permissions are the variable under test.
        foreach (array_merge(['Manage Cart', 'Manage Wishlist'], $extraPermissions) as $p) {
            $this->grantPermission($merchant, $p);
        }

        $customer = Customer::forceCreate([
            'first_name' => 'Fallback', 'last_name' => 'Shopper',
            'email' => 'fallback-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'regiester_date' => now(),
        ]);

        $catName = 'Fallback Cat ' . uniqid();
        $category = Category::forceCreate([
            'name' => $catName, 'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ]);
        $product = Product::forceCreate([
            'name' => 'Fallback Product', 'slug' => 'fallback-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0, 'category_id' => $category->id,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $store->id]);
        Wishlist::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'status' => 1, 'store_id' => $store->id]);

        return [$merchant];
    }

    private function actionCell(\App\Models\User $merchant, string $path): string
    {
        $response = $this->actingAs($merchant)->withHeaders(self::AJAX)
            ->getJson($path . '?' . http_build_query($this->query()));

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('draw', $payload, "{$path}: yajra served the HTML leg, not AJAX");
        $this->assertGreaterThan(0, (int) ($payload['recordsTotal'] ?? 0),
            "premise: the merchant must see a row on {$path}, or there is no cell to measure");

        return (string) ($payload['data'][0]['action'] ?? '');
    }

    /** @return array<string,array{0:string,1:array<int,string>}> */
    public static function reports(): array
    {
        return [
            'abandoned carts' => ['/abandon-carts-handled', ['Abandon Cart', 'Delete Cart']],
            'abandoned wishlists' => ['/wishlist', ['Abandon Wishlist', 'Delete Wishlist']],
        ];
    }

    /**
     * @dataProvider reports
     */
    public function test_a_viewer_with_no_action_permissions_is_told_so_rather_than_shown_a_blank_cell(
        string $path,
        array $actionPermissions
    ): void {
        [$merchant] = $this->arrange([]); // deliberately WITHOUT the action permissions

        $cell = $this->actionCell($merchant, $path);

        $this->assertNotSame('', trim(strip_tags($cell)),
            "{$path}: the action cell is still empty under its own column header");

        $this->assertStringContainsString('No actions available with your permissions', $cell,
            "{$path}: the cell carries no explanation, so a screen-reader user meets an empty "
            . 'cell with nothing said about why');

        // AND THE CONTROLS MUST STAY GONE. The repair tells a viewer they cannot act; it must not
        // let them. Checking only for the explanation would pass a fix that granted the buttons.
        $this->assertStringNotContainsString('show_confirm', $cell,
            "{$path}: the delete control appeared for a viewer without the permission — the "
            . 'fallback granted capability instead of explaining its absence');
        $this->assertDoesNotMatchRegularExpression('/<form\b/', $cell,
            "{$path}: a destructive form was rendered for a viewer without the permission");
    }

    /**
     * POSITIVE CONTROL. With the permissions the real controls come back and the fallback does
     * not, so the assertions above cannot be satisfied by a cell that renders the explanation
     * unconditionally.
     *
     * @dataProvider reports
     */
    public function test_a_viewer_with_the_permissions_gets_the_controls_and_not_the_fallback(
        string $path,
        array $actionPermissions
    ): void {
        [$merchant] = $this->arrange($actionPermissions);

        $cell = $this->actionCell($merchant, $path);

        $this->assertStringContainsString('show_confirm', $cell,
            "{$path}: the delete control is missing for a viewer who holds the permission");
        $this->assertStringNotContainsString('No actions available with your permissions', $cell,
            "{$path}: the no-permission explanation is shown to a viewer who CAN act");
    }

    /**
     * The destructive dialog must name what it removes. `abandon_carts_destroy()` and
     * `WishlistController::destroy()` both take a CUSTOMER id and delete EVERY row for them, so a
     * message saying only "this action cannot be undone" understates the blast radius.
     *
     * @dataProvider reports
     */
    public function test_the_delete_dialog_names_the_customer_and_the_count(
        string $path,
        array $actionPermissions
    ): void {
        [$merchant] = $this->arrange($actionPermissions);

        $cell = $this->actionCell($merchant, $path);

        $this->assertStringContainsString('Fallback Shopper', $cell,
            "{$path}: the confirmation does not name whose data is about to be deleted");
        $this->assertDoesNotMatchRegularExpression(
            '/data-text="This action can not be undone\. Do you want to continue\?"/',
            $cell,
            "{$path}: still the generic message that never says what goes"
        );
    }
}
