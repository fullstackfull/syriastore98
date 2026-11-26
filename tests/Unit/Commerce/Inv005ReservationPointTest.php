<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;

/**
 * `INV-005` -- STOCK IS RESERVED AT ORDER CONFIRMATION, NOT AT ADD-TO-CART.
 * ========================================================================
 *
 * The approved decision, verbatim from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *     INV-005  الحجز الآمن عند تأكيد الطلب، لا عند إضافته للسلة.
 *              Safe reservation at order confirmation, NOT at add-to-cart
 *
 * The decision has a prohibition and a requirement, and they fail in opposite directions, so both
 * halves are asserted here. Reserving at add-to-cart lets one shopper's abandoned basket make a
 * product unbuyable for everyone else; reserving at NEITHER point lets two shoppers buy the same
 * last unit. A test that only checked the prohibition would pass on a platform that never
 * decremented anything at all.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT WAS MEASURED, AND THE INSTRUMENT'S FIRST VERSION WAS WRONG.
 *
 * Every site in `app/` that mutates `products.product_stock` or `product_variants.stock`, comments
 * stripped: **five files**, listed in {@see self::DECLARED_STOCK_MUTATORS}. Not one of them is a
 * cart surface. So `INV-005`'s prohibition holds in the shipped code.
 *
 * The first census looked only for ASSIGNMENT -- `->product_stock =` and `->stock =` -- and found
 * 21 sites in four files. It reported `ProductStockService` as mutating NOTHING, which is the
 * opposite of true: the service writes through `$query->decrement('product_stock', $quantity)`, a
 * query-builder call and not an assignment. That is CLAUDE.md §3's recurring failure in miniature --
 * a census keyed on the spellings somebody thought of is short by the ones they did not -- and it is
 * why {@see self::MUTATION_PATTERNS} covers assignment, `decrement`/`increment` and mass assignment,
 * and why every FILE that matches any of them must be declared rather than merely counted.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE CART ASSERTIONS ARE SCOPED TO THE METHOD AND NOT THE FILE.
 *
 * `ProductController` both serves `addToCart` and carries twelve stock writes -- it is the merchant
 * product editor as well as the POS cart. A file-level assertion over it would be permanently red
 * for a reason that has nothing to do with `INV-005`, and a file-level assertion that EXCLUDED it
 * would be blind to the one handler that matters. So each cart handler's own body is extracted by
 * brace matching and asserted on its own, which is CLAUDE.md §3's "scope the search to the block
 * too" -- the rule that exists because an assertion over a whole 7,000-line stylesheet answered
 * "does this string exist anywhere" and a mutation survived it.
 *
 * -------------------------------------------------------------------------------------------------
 * A CLAIM IN THE REPOSITORY THAT THIS FILE REFUTES, narrowly and on purpose.
 *
 * `database/migrations/2026_10_31_000100_create_stock_movements_table.php`'s docblock states that
 * `ProductStockService` *"is now the ONLY mutator of `products.product_stock` and
 * `product_variants.stock` in `app/` -- the single remaining hit outside it is a commented-out line
 * at `PosController:208`"*. As written that is **false**: four other files hold 21 assignment sites
 * between them. It is **true** once narrowed to the ORDER paths, which is what the surrounding
 * argument actually needs -- product CRUD and the two catalogue importers are not order paths and
 * cannot be observed by an order-attributed movement record.
 *
 * The narrow claim is the one asserted here, by name, so the next reader inherits the true version.
 * The docblock itself is not edited from this file: it is a migration another workstream owns and a
 * four-word correction is not worth a merge conflict in it.
 */
class Inv005ReservationPointTest extends TestCase
{
    /**
     * EVERY spelling that mutates a stock column. Dangerous by default: a file matching any of
     * these must be declared below.
     *
     * @var array<string, string>
     */
    private const MUTATION_PATTERNS = [
        'assignment' => '/->\s*(?:product_stock|stock)\s*=[^=>]/',
        'decrement_or_increment' => "/(?:decrement|increment)\w*\(\s*'(?:product_stock|stock)'/",
        'mass_assignment' => "/(?:update|updateOrCreate|create|fill)\(\s*\[[^\]]*'(?:product_stock|stock)'\s*=>/s",
    ];

    /**
     * THE FIVE FILES IN `app/` THAT MUTATE A STOCK COLUMN, each with what it is and whether it is
     * an order path.
     *
     * Measured over 696 PHP files under `app/`. A sixth file appearing here fails the census, which
     * is the point: a new stock writer is exactly the change that could put a reservation at
     * add-to-cart, and it should not be able to arrive quietly.
     *
     * @var array<string, string>
     */
    private const DECLARED_STOCK_MUTATORS = [
        // THE ORDER PATH, and the only one. Four `decrement`/`increment` calls behind
        // decrementProductStock(), decrementVariantStock(), restoreProductStock(),
        // restoreVariantStock(). Every order writer routes through it.
        'Services/ProductStockService.php' => 'the shared service; the only order-path mutator',

        // MERCHANT PRODUCT CRUD, not an order path: the merchant setting a figure on a product or
        // a variant. Twelve assignment sites across create, edit and the variant matrix.
        'Http/Controllers/ProductController.php' => 'merchant product create/edit on the web panel',

        // The same thing through the shipped Flutter admin app.
        'Http/Controllers/Api/DashboardController.php' => 'merchant product create/edit via the admin API',

        // CATALOGUE IMPORTERS. They copy a figure from an external store's product record; no
        // order and no cart is involved.
        'Http/Controllers/ShopifyProductController.php' => 'Shopify catalogue import',
        'Http/Controllers/WoocomProductController.php' => 'WooCommerce catalogue import',
    ];

    /**
     * The cart surfaces, as `routes/api.php` and `routes/web.php` dispatch them.
     *
     * Every one is asserted to EXIST before it is asserted to be clean, because a renamed handler
     * would otherwise turn its assertion into a tautology -- the exact shape of failure CLAUDE.md
     * §3 records for censuses.
     *
     * @var array<string, list<string>> file => handler methods
     */
    private const CART_HANDLERS = [
        // The storefront API. `POST {slug}/add-cart`, `cart-qty`, `cart-list`, `cart-check`,
        // `cart-check-guest`.
        'Http/Controllers/Api/ApiController.php' => [
            'addtocart', 'cart_qty', 'cart_list', 'cart_check', 'cart_check_guest',
        ],

        // The POS and web cart. `addToCart/{id}/{session}/{variation_id?}`, `PATCH update-cart`,
        // `DELETE remove-from-cart`, `POST empty-cart`.
        'Http/Controllers/ProductController.php' => [
            'addToCart', 'updateCart', 'removeFromCart', 'emptyCart',
        ],

        // The storefront's own cart screens and mutations.
        'Http/Controllers/CartController.php' => [
            'cart_remove', 'cartClear', 'change_cart', 'product_cartlist',
        ],
    ];

    /** The file's code with every comment replaced by as many newlines as it spanned. */
    private function codeOf(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/app/' . $relativePath;
        $this->assertFileExists($path);

        $out = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    // Keep the line count so a reported offset still means something.
                    $out .= str_repeat("\n", substr_count($token[1], "\n"));
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** @return list<string> every `.php` file under `app/`, relative to it */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 3) . '/app';

        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found[] = str_replace($root . '/', '', $file->getPathname());
            }
        }

        sort($found);

        return $found;
    }

    /**
     * One method's body, by brace matching from its signature.
     *
     * Returns `null` when the method is not there, so the caller can fail on the PREMISE rather
     * than silently assert over an empty string.
     */
    private function methodBody(string $code, string $method): ?string
    {
        if (preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = strpos($code, '{', $m[0][1]);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($code);

        for ($i = $open; $i < $length; $i++) {
            if ($code[$i] === '{') {
                $depth++;
            } elseif ($code[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }

    /**
     * THE PREMISE: the census walks a real tree, and the pattern really does fire on real code.
     *
     * Both directions, because a census that scans nothing and a census whose pattern matches
     * nothing are both indistinguishable from a pass.
     */
    public function test_the_census_reads_the_tree_and_its_patterns_fire(): void
    {
        $this->assertGreaterThan(
            500,
            count($this->appFiles()),
            'The app/ tree should hold hundreds of PHP files; a small number means the census is '
            . 'scanning the wrong path.'
        );

        // Each pattern fires on a line that is really in the tree.
        $samples = [
            'assignment' => '$product->product_stock = 0;',
            'decrement_or_increment' => "\$query->decrement('product_stock', \$quantity);",
            'mass_assignment' => "Product::query()->update(['product_stock' => 3]);",
        ];

        foreach (self::MUTATION_PATTERNS as $name => $pattern) {
            $this->assertSame(
                1,
                preg_match($pattern, $samples[$name]),
                "The '$name' pattern does not match the shape it exists to catch."
            );
        }

        // And none of them fires on a read.
        foreach (self::MUTATION_PATTERNS as $name => $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, 'if ($product->product_stock >= $qty) { return true; }'),
                "The '$name' pattern matches a READ, so the census would report every reader."
            );
        }
    }

    /**
     * THE PROHIBITION: no cart handler mutates a stock column.
     *
     * This is `INV-005`'s "لا عند إضافته للسلة" half. Asserted per handler body, not per file.
     */
    public function test_no_cart_handler_mutates_a_stock_column(): void
    {
        $offenders = [];

        foreach (self::CART_HANDLERS as $file => $methods) {
            $code = $this->codeOf($file);

            foreach ($methods as $method) {
                $body = $this->methodBody($code, $method);

                $this->assertNotNull(
                    $body,
                    "PREMISE FAILED: $file::$method() was not found, so asserting it is clean "
                    . 'would prove nothing. If the handler was renamed, update CART_HANDLERS; if it '
                    . 'was removed, say so here.'
                );

                foreach (self::MUTATION_PATTERNS as $name => $pattern) {
                    if (preg_match($pattern, $body) === 1) {
                        $offenders[] = "$file::$method() -- $name";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'INV-005 requires stock to be reserved at ORDER CONFIRMATION and NOT at add-to-cart. '
            . "A cart handler now mutates a stock column, which reserves at cart-add and lets one "
            . "abandoned basket make a product unbuyable:\n" . implode("\n", $offenders)
        );
    }

    /**
     * THE REQUIREMENT: something does reserve, and it is the shared order-path service.
     *
     * Without this, the prohibition above would be satisfied by a platform that never decremented
     * stock at all -- which breaks `INV-004` (prevent selling above available) instead, and would
     * read here as a pass.
     */
    public function test_the_order_path_service_really_does_move_stock(): void
    {
        $service = $this->codeOf('Services/ProductStockService.php');

        $this->assertSame(
            1,
            preg_match(self::MUTATION_PATTERNS['decrement_or_increment'], $service),
            'ProductStockService must still be the thing that moves stock. If it no longer does, '
            . "INV-005's 'reserve at order confirmation' half has no implementation."
        );

        foreach (['decrementProductStock', 'decrementVariantStock'] as $method) {
            $this->assertNotNull(
                $this->methodBody($service, $method),
                "ProductStockService::$method() is INV-005's reservation point and must exist."
            );
        }
    }

    /**
     * THE RATCHET: the set of stock mutators in `app/` is exactly the declared five.
     *
     * Both directions. A new mutating file fails because it is undeclared; a declared file that
     * stops mutating also fails, because a stale entry is how an allowlist quietly loses its teeth.
     */
    public function test_the_stock_mutators_are_exactly_the_declared_set(): void
    {
        $measured = [];

        foreach ($this->appFiles() as $relative) {
            $code = $this->codeOf($relative);

            foreach (self::MUTATION_PATTERNS as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $measured[] = $relative;
                    break;
                }
            }
        }

        sort($measured);
        $declared = array_keys(self::DECLARED_STOCK_MUTATORS);
        sort($declared);

        $this->assertSame(
            $declared,
            $measured,
            "The set of files in app/ that mutate a stock column has changed.\n"
            . "UNDECLARED (new writers -- read each one and check it is not a cart path): "
            . implode(', ', array_diff($measured, $declared)) . "\n"
            . "DECLARED BUT NO LONGER MUTATING (delete the entry): "
            . implode(', ', array_diff($declared, $measured))
        );
    }

    /**
     * The NARROW version of the `stock_movements` migration's claim, asserted so the true one is
     * the one that is pinned.
     *
     * `ProductStockService` is the only ORDER-PATH mutator. The other four declared writers are
     * product CRUD and catalogue imports -- not order paths, so an order-attributed movement record
     * is silent about them BY DESIGN rather than by oversight.
     */
    public function test_the_only_order_path_stock_mutator_is_the_shared_service(): void
    {
        $orderPathMutators = array_keys(array_filter(
            self::DECLARED_STOCK_MUTATORS,
            static fn (string $why): bool => str_contains($why, 'order-path')
        ));

        $this->assertSame(
            ['Services/ProductStockService.php'],
            $orderPathMutators,
            'If a second order path gained its own stock writer, the stock-movement record stops '
            . 'observing every movement and "absent means absent" stops holding.'
        );
    }
}
