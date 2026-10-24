<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-24 -- the two scopes every basket delete goes through, and the census that keeps raw ones out.
 *
 * `Cart::forGuestCookie()` and `Cart::forCustomer()` exist to refuse an input that SELECTS TOO MUCH: a missing cookie
 * (`where(col, null)` is `IS NULL`, i.e. every logged-in customer's rows) and a customer id that is `''` (MySQL, non-strict:
 * `= 0`, i.e. every guest's rows) or merely typed. See NullCookieCartTest and GatewayReturnBasketClearTest for the routes.
 */
class CartScopesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function rows(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $product = Product::forceCreate([
            'name' => 'cs-' . uniqid(), 'slug' => 'cs-' . uniqid(), 'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 1, 'sale_price' => 1, 'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
        $customer = Customer::forceCreate([
            'first_name' => 'Cs', 'last_name' => 'C', 'email' => 'cs-' . uniqid() . '@example.test', 'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 1, 'store_id' => $store->id]);
        Cart::forceCreate(['customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 1, 'store_id' => $store->id, 'cookie_session_id' => 'cs-guest']);

        return [$customer];
    }

    public function test_for_customer_matches_nothing_unless_given_a_positive_integer(): void
    {
        [$customer] = $this->rows();

        foreach ([null, '', 0, '0', -1, '-1', 'abc', '5abc', ' 5', '5 ', 1.5, '1.5', '1e3', [], true, false, new \stdClass()] as $bad) {
            $this->assertSame(0, Cart::withoutGlobalScopes()->forCustomer($bad)->count(), 'forCustomer selected rows for ' . var_export($bad, true));
        }

        $this->assertSame(1, Cart::withoutGlobalScopes()->forCustomer($customer->id)->count(), 'Control: an int id selects that customer\'s basket.');
        $this->assertSame(1, Cart::withoutGlobalScopes()->forCustomer((string) $customer->id)->count(), 'Control: a digit string selects it too.');
        $this->assertSame(0, Cart::withoutGlobalScopes()->forCustomer($customer->id + 1000)->count());
    }

    public function test_for_customer_never_selects_the_guest_rows_that_carry_customer_id_zero(): void
    {
        $this->rows();

        $this->assertSame(1, (int) DB::table('carts')->where('customer_id', 0)->count(), 'Premise: a guest row with customer_id 0 exists.');
        $this->assertSame(0, Cart::withoutGlobalScopes()->forCustomer('')->count());
        $this->assertSame(0, Cart::withoutGlobalScopes()->forCustomer(0)->count());
        $this->assertSame(0, Cart::withoutGlobalScopes()->forCustomer(null)->count());
    }

    /**
     * RATCHET. A basket delete or update keyed on `customer_id` must name the AUTHENTICATED principal's id, or go through
     * Cart::forCustomer(). The only raw ones allowed are listed below, each derived from the principal; a new
     * `Cart::where('customer_id', ...)->delete()` is the defect again. Comments are stripped with the tokenizer (a
     * docblock quoting the old statement is not a statement) and the argument is read with balanced parentheses.
     */
    public function test_no_host_basket_delete_is_keyed_on_a_raw_customer_id(): void
    {
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());
            $offset = 0;

            while (preg_match("/Cart::where\(\s*'customer_id'\s*,/", $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $argStart = $m[0][1] + strlen($m[0][0]);
                $depth = 1;
                $i = $argStart;
                for ($n = strlen($code); $i < $n && $depth > 0; $i++) {
                    $depth += $code[$i] === '(' ? 1 : ($code[$i] === ')' ? -1 : 0);
                }
                $argument = preg_replace('/\s+/', ' ', trim(substr($code, $argStart, $i - 1 - $argStart)));

                $end = strpos($code, ';', $i);
                $statement = substr($code, $i, ($end === false ? strlen($code) : $end) - $i);

                if (preg_match('/->(delete|update|forceDelete)\(/', $statement)) {
                    $found[] = $relative . ' :: ' . $argument;
                }

                $offset = $i;
            }
        }

        sort($found);

        $this->assertSame([
            // ordersave() and its sibling: the customer is the token's own, from resolveStoreCustomer() (S19).
            'app/Http/Controllers/Api/ApiController.php :: $customerId',
            'app/Http/Controllers/Api/ApiController.php :: $customer_id',
            // cartClear(): the logged-in arm.
            "app/Http/Controllers/CartController.php :: auth('customers')->id()",
        ], $found, 'A basket delete is keyed on a raw customer id. Use Cart::forCustomer(auth(\'customers\')->id()).');
    }

    /**
     * RATCHET, the other half. `forCustomer()` refuses a NON-id; it cannot refuse an id that is the wrong person's.
     * That is decided at the call site, so the call sites' arguments are pinned: the authenticated principal's id, the
     * order writers' pre-computed owner, or a queued line's id (which the queue sites set from the principal). A new
     * `forCustomer($request->customer_id)` would pass the scope and be the defect again, so it fails here.
     */
    public function test_every_for_customer_call_names_the_authenticated_principal(): void
    {
        $arguments = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $offset = 0;
            while (preg_match('/(?:->|::)forCustomer\(/', $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $start = $m[0][1] + strlen($m[0][0]);
                $depth = 1;
                for ($i = $start, $n = strlen($code); $i < $n && $depth > 0; $i++) {
                    $depth += $code[$i] === '(' ? 1 : ($code[$i] === ')' ? -1 : 0);
                }
                $arguments[] = preg_replace('/\s+/', ' ', trim(substr($code, $start, $i - 1 - $start)));
                $offset = $i;
            }
        }

        $allowed = [
            "auth('customers')->id()",      // the gateway returns, cart-remove, change-cart
            '$cartOwnerId',                  // getWhatsappUrl: (int) auth('customers')->id()
            "(int) \$line['customer_id']",   // clearCartLines: lines queued with auth('customers')->id()
        ];

        $this->assertNotEmpty($arguments, 'Premise: the scan found the call sites.');
        $this->assertSame([], array_values(array_diff($arguments, $allowed)),
            'forCustomer() is called with something other than the authenticated principal: ' . implode(' | ', array_diff($arguments, $allowed)));
    }
}
