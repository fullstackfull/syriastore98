<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripsSourceComments;

/**
 * GAP-299 — THE DEAD COOKIE CART WRITERS MUST STAY DEAD, AND THIS SAYS WHAT REVIVING ONE COSTS.
 * =================================================================================================
 *
 * `Cart::cookie_to_cart()` writes `product_id` straight out of a `json_decode`'d, client-supplied
 * `cart` cookie with no gate of any kind — no store predicate, no ownership check, nothing. It has
 * no caller anywhere, which is the whole of its mitigation. `Cart::addtocart_cookie()` is the same
 * shape and equally uncalled.
 *
 * THE DELIVERABLE IS A RATCHET RATHER THAN A FIX, AND THE ROW'S OWN PREFERENCE IS DECLINED ON A
 * MEASUREMENT. `GAP-299` says "deleting is the better answer". Deleting these methods from
 * `Cart.php` would shift every line below them — and `Cart.php` carries **206** absolute
 * `file:line` citations across this repository, **148** of them below `cart_list_cookie`'s first
 * line. So the cheap remedy costs up to 148 stale citations and buys nothing functional, because
 * nothing calls the code. The row's preference was stated without that figure. A pin that forbids
 * reviving the function without its gates is worth more than deleting something nothing calls,
 * which is the shape another workstream used for a route that must not be made to resolve.
 *
 * THE CENSUS STRIPS COMMENTS, and that is not pedantry here: the row itself records that these
 * methods are "referenced only by comments", so a naive grep for the name is satisfied by the very
 * prose that documents them being dead. `CustomerLoginController` names `cookie_to_cart` in a
 * docblock while deliberately NOT calling it.
 *
 * WHAT REVIVAL WOULD RESTORE IS MEASURED, NOT DESCRIBED. `test_what_reviving_it_would_restore`
 * calls the method directly with a forged cookie naming ANOTHER store's product and asserts the
 * cross-tenant row lands, priced from that store's catalogue. That is a statement about the
 * method, not about any reachable request — nothing calls it, so nothing in the product does this
 * today. It is here so that whoever revives it has the consequence in front of them rather than a
 * warning they have to take on trust.
 *
 * THE THREE GATES A REVIVAL NEEDS, which the two LIVE writers already meet and this one does not:
 *
 *   1. The resolved `Product` must belong to the store the row is being written for.
 *      `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so `Product::find()` reaches
 *      every tenant's catalogue — `ApiController::addtocart` hard-matches `store_id` and refuses
 *      foreign and null; `CartController::product_cartlist` uses the provable-mismatch rule.
 *   2. The same for `ProductVariant`, which is resolved by id on the variant arm.
 *   3. The existing-row lookup must carry a store predicate. It is
 *      `Cart::where('customer_id', …)->where('product_id', …)->where('variant_id', …)` with no
 *      store term, so it can ADD quantity to a row belonging to a different store of the same
 *      customer.
 *
 * And one tell that this code is unmaintained rather than merely unused: `$store =
 * getStoreById($store_id)` is assigned on the first line of the body and never read.
 *
 * @see \App\Models\Cart::cookie_to_cart()
 * @see \App\Models\Cart::addtocart_cookie()
 */
class DeadCookieCartWriterStaysDeadTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** The two methods this file keeps dead. */
    private const DEAD = ['cookie_to_cart', 'addtocart_cookie'];

    /** Where a caller could appear. `packages/` included: a module may call a host model. */
    private const SEARCHED = ['app', 'routes', 'resources', 'packages', 'database', 'config'];

    /**
     * Every file that MENTIONS either name, with its comments stripped.
     *
     * TWO STAGES, AND THE NARROWING IS NOT ONLY SPEED. `StripsSourceComments` asserts that
     * stripping did not remove the whole file -- a deliberate guard, because a stripper that eats
     * everything turns every absence assertion into a free pass. But a legitimately comment-only
     * file exists (`resources/views/front_end/hooks/cart_sidebar.blade.php`), so stripping the
     * whole tree trips that guard on a file that has nothing to do with this census. Finding
     * candidates by name first and stripping only those is both faster and correct: the only
     * question that matters is whether a MENTION is code or prose.
     *
     * @return array<string, string>
     */
    private function candidateSources(): array
    {
        $out = [];

        foreach (self::SEARCHED as $root) {
            $dir = base_path($root);

            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($it as $file) {
                if (! $file->isFile() || ! preg_match('/\.(php|js)$/', $file->getPathname())) {
                    continue;
                }

                $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

                /* The declaration itself is not a caller. */
                if ($relative === 'app/Models/Cart.php') {
                    continue;
                }

                $raw = (string) file_get_contents($file->getPathname());

                foreach (self::DEAD as $method) {
                    if (str_contains($raw, $method)) {
                        $out[$relative] = $this->sourceWithoutComments($relative);
                        break;
                    }
                }
            }
        }

        return $out;
    }

    /*
    |--------------------------------------------------------------------------
    | THE RATCHET
    |--------------------------------------------------------------------------
    */

    /**
     * THE PIN: neither writer has a caller, measured over code with comments removed.
     *
     * This is the assertion that fires the day somebody revives one. The failure message is the
     * deliverable — it names the three gates to add first, so the next person does not have to
     * find this docblock.
     */
    public function test_the_dead_cookie_cart_writers_have_no_callers(): void
    {
        $sources = $this->candidateSources();

        /*
         * THE CENSUS MUST FIND THE MENTIONS IT IS ABOUT TO JUDGE. The row records that these
         * methods are "referenced only by comments", so at least one file mentions a name -- and
         * zero candidates would mean the scan is broken, not that nothing refers to them.
         */
        $this->assertNotSame([], $sources,
            'the census found NO file mentioning either name, which cannot be right: '
            . 'CustomerLoginController names cookie_to_cart in a docblock. The scan is finding '
            . 'nothing rather than finding no callers.');

        $callers = [];

        foreach (self::DEAD as $method) {
            foreach ($sources as $relative => $code) {
                if (preg_match('/(?:Cart::|->|\$this->|self::|static::)\s*' . preg_quote($method, '/') . '\s*\(/', $code)) {
                    $callers[] = $relative . ' calls ' . $method . '()';
                }
            }
        }

        $this->assertSame([], $callers,
            "GAP-299: a dead cookie cart writer has been REVIVED, and it is not safe to call yet.\n"
            . "Before wiring either of these up, add all three gates the two LIVE writers already\n"
            . "meet -- see ApiController::addtocart and CartController::product_cartlist:\n"
            . "  1. the resolved Product must belong to the store the row is written for\n"
            . "     (Product is NOT in config/tenancy.php's scoped_models, so Product::find()\n"
            . "      reaches every tenant's catalogue);\n"
            . "  2. the same for ProductVariant, resolved by id on the variant arm;\n"
            . "  3. a store predicate on the existing-row lookup, which today is\n"
            . "     Cart::where(customer_id)->where(product_id)->where(variant_id) with no store\n"
            . "     term, so it can add quantity to another store's row for the same customer.\n"
            . 'The cookie is client-supplied. Found: ' . implode(', ', $callers));
    }

    /** And the premise that makes the census meaningful: the methods still exist to be revived. */
    public function test_premise_both_methods_still_exist(): void
    {
        foreach (self::DEAD as $method) {
            $this->assertTrue(method_exists(Cart::class, $method),
                'Cart::' . $method . '() is gone. If it was DELETED that is the row\'s own first '
                . 'preference and this file should be deleted with it — not left asserting the '
                . 'absence of callers of a method that no longer exists.');
        }
    }

    /**
     * AND THE GATES ARE GENUINELY ABSENT TODAY, so the ratchet is not guarding a solved problem.
     *
     * Asserted on the method's own source with comments stripped — this file's prose names
     * `store_id` repeatedly, and a census that reads comments can be satisfied by prose about the
     * thing it measures.
     */
    public function test_the_three_gates_are_still_missing_from_the_body(): void
    {
        $code = $this->sourceWithoutComments('app/Models/Cart.php');

        $start = strpos($code, 'function cookie_to_cart');
        $end = strpos($code, 'function CartPageBestseller', (int) $start);

        $this->assertNotFalse($start, 'cookie_to_cart() is gone from the source');
        $this->assertNotFalse($end, 'the method after cookie_to_cart() is gone');

        $body = substr($code, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString('Product::find($product->product_id)', $body,
            'the product lookup changed shape; re-read whether it is now store-scoped before '
            . 'relying on this file');

        $this->assertStringNotContainsString("where('store_id'", $body,
            'cookie_to_cart() now carries a store predicate. If it was GATED rather than revived, '
            . 'that is good — update this file to assert the gate instead of its absence.');
    }

    /*
    |--------------------------------------------------------------------------
    | WHAT REVIVAL WOULD RESTORE — measured, on the method, not on any request
    |--------------------------------------------------------------------------
    */

    /**
     * THE CONSEQUENCE, DRIVEN. A forged cookie naming another store's product writes a cart row
     * for this store, priced from the other store's catalogue.
     *
     * NOTHING IN THE PRODUCT DOES THIS. The method has no caller; this calls it directly, which is
     * the only way to reach it. The case exists so the ratchet above is backed by a measurement
     * rather than by a warning — and so that if a future change makes the method SAFE, this case
     * fails and says so instead of leaving a stale claim in a docblock.
     */
    public function test_what_reviving_it_would_restore(): void
    {
        [$ownerA, $storeA] = $this->makeTenant();
        [$ownerB, $storeB] = $this->makeTenant();

        $foreign = Product::forceCreate([
            'name' => 'victim-' . uniqid(), 'slug' => 'victim-' . uniqid(),
            'price' => 999.00, 'sale_price' => 999.00,
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 0, 'status' => 1,
            'store_id' => $storeB->id, 'created_by' => (int) $ownerB->id,
        ]);

        $shopper = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B',
            'email' => 'gap299-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $storeA->id, 'status' => 1, 'register_type' => 'email',
        ]);

        /* The client's own cookie, in the JSON shape this method still expects. */
        request()->cookies->set('cart', json_encode([[
            'product_id' => $foreign->id,
            'variant_id' => 0,
            'qty' => 2,
        ]]));

        $before = (int) DB::table('carts')->count();

        Cart::cookie_to_cart((int) $shopper->id, (int) $storeA->id);

        $row = DB::table('carts')->where('customer_id', $shopper->id)->first();

        $this->assertSame($before + 1, (int) DB::table('carts')->count(),
            'the method wrote nothing, so this case is not measuring what revival would restore. '
            . 'If the cookie shape or the signature changed, re-derive it rather than deleting '
            . 'this case.');

        $this->assertNotNull($row);

        $this->assertSame((int) $foreign->id, (int) $row->product_id,
            'GAP-299: the forged product id did not survive into the row, so something now bounds '
            . 'it — re-read the method, because the ratchet above is written on the assumption '
            . 'that nothing does.');

        $this->assertSame((int) $storeA->id, (int) $row->store_id,
            'the row carries store A while naming store B\'s product: the store comes from the '
            . 'CALLER and the product from the COOKIE, and nothing reconciles them');

        $this->assertEquals(999.00, (float) $row->price,
            'and it is priced from the FOREIGN store\'s catalogue, so reviving this would let a '
            . 'client put any tenant\'s product into a basket at that tenant\'s price');
    }
}
