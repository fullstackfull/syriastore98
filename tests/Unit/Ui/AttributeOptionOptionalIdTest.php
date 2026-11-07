<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-B — two catalogue routes answered 500 because the route said "optional" and the method
 * said "required".
 * ================================================================================================
 *
 * MEASURED, signed in as the dev merchant, both routes:
 *
 *     GET /add-attribute-option    500   ArgumentCountError: Too few arguments to function
 *                                        ProductAttributeOptionController::create(), 0 passed
 *     GET /edit-attribute-option   500   ... ::edit(), 0 passed
 *
 * `routes/web.php` declares both with `{id?}`. PHP threw before a single line of either method ran,
 * so neither the permission check nor the tenant check was reached — the request died in the
 * router.
 *
 * THE ROUTE'S `?` IS LOAD-BEARING AND MUST NOT BE REMOVED, which is why this is fixed in the
 * controller and not in the route. `resources/views/layouts/app.blade.php:829` calls
 * `route('product-attribute-option.create')` with NO id deliberately and appends `/' + i` in
 * javascript, using the result as a URL PREFIX for a dynamically built "Add Attribute Option"
 * control. Make the parameter required and that `route()` call throws `UrlGenerationException`
 * while rendering the layout — on EVERY admin page. The 500 would move from two seldom-hit routes
 * to all of them.
 *
 * THE FIX IS ONE DEFAULT PER METHOD, AND THE REFUSAL PATH ALREADY EXISTED. Both
 * `ownedAttribute($id)` and `ownedOption($id)` open with
 * `if (empty($store) || empty($id)) { return null; }`, and a null return falls into `denied()`.
 * That guard was written and was simply unreachable, because PHP threw first. So a missing id now
 * refuses rather than crashing: strictly MORE restrictive than before, never less. That is the
 * property that makes this safe, and it is the one this test pins.
 *
 * MEASURED AFTER, with a reset to a known page between probes:
 *
 *     /add-attribute-option/1        200  form fragment, 4 fields   (unchanged)
 *     /edit-attribute-option/1       200  form fragment, 4 fields   (unchanged)
 *     /add-attribute-option          refused -> /dashboard
 *     /edit-attribute-option         refused -> /dashboard
 *     /add-attribute-option/999999   refused -> /dashboard          (tenant guard, foreign id)
 *
 * THE RESET IS NOT INCIDENTAL. `denied()` is `redirect()->back()`, so without navigating somewhere
 * known first, each probe lands on the PREVIOUS probe's page and a refusal is indistinguishable
 * from a successful render. The first run of that check reported all five as 200-with-a-form and
 * would have "confirmed" a fix that refused nothing. This is the same `back()` trap that made the
 * Package B reachability probe report `/flash-sale -> /coupon`: the redirect target was never
 * meaningful, it was just wherever the probe had been a moment earlier.
 */
class AttributeOptionOptionalIdTest extends TestCase
{
    private const CONTROLLER = 'app/Http/Controllers/ProductAttributeOptionController.php';
    private const ROUTES = 'routes/web.php';
    private const LAYOUT = 'resources/views/layouts/app.blade.php';

    public function test_both_methods_accept_a_missing_id(): void
    {
        $code = $this->codeWithoutComments(self::CONTROLLER);

        foreach (['create', 'edit'] as $method) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $method . '\(\$id = null\)/',
                $code,
                "`{$method}(\$id)` with no default threw ArgumentCountError in the router, before "
                . 'the permission check and before the tenant check, for a route the application '
                . 'itself declares as optional.'
            );
        }
    }

    public function test_the_routes_still_declare_the_parameter_optional(): void
    {
        $routes = $this->codeWithoutComments(self::ROUTES);

        foreach (['add-attribute-option', 'edit-attribute-option'] as $path) {
            $this->assertStringContainsString(
                $path . '/{id?}',
                $routes,
                "Removing the `?` from `{$path}` would make `route()` throw for any caller that "
                . 'omits the id.'
            );
        }
    }

    public function test_the_layout_still_needs_the_optional_form_which_is_why_it_stays(): void
    {
        $layout = $this->read(self::LAYOUT);

        // The prefix trick, verbatim: route() with no argument, then `/` + i appended in JS.
        $this->assertMatchesRegularExpression(
            "/route\('product-attribute-option\.create'\)\s*\}\}\/'\s*\+\s*i/",
            $layout,
            'This is the caller that makes the route parameter optional load-bearing. If it ever '
            . 'goes away, the route COULD be made required — but until then, making it required '
            . 'moves the 500 from two routes to every admin page.'
        );
    }

    public function test_a_missing_id_is_refused_rather_than_served(): void
    {
        $code = $this->codeWithoutComments(self::CONTROLLER);

        // The guards that turn a null id into a refusal. Without these the default would make a
        // missing id reach the query instead of the refusal, which WOULD be a widening.
        foreach (['ownedAttribute', 'ownedOption'] as $helper) {
            $this->assertMatchesRegularExpression(
                '/private function ' . $helper . '\(\$id\)[^}]*?empty\(\$store\)\s*\|\|\s*empty\(\$id\)/s',
                $code,
                "`{$helper}()` must refuse an empty id. The signature default is only safe because "
                . 'this guard exists; together they turn a crash into a refusal.'
            );
        }

        // And the tenant predicate itself, which the null-id guard sits in front of.
        $this->assertSame(
            2,
            preg_match_all("/->where\('store_id',\s*\\\$store\)/", $code),
            'Both helpers must keep filtering by the current store.'
        );
    }

    /**
     * ORDER MATTERS: permission, THEN ownership. Reversing them lets a caller WITHOUT the
     * permission learn whether an id exists in this store, from which refusal they get.
     *
     * THE FIRST VERSION OF THIS TEST COULD NOT FAIL FOR THE THING IT CLAIMED, and an independent
     * review caught it. It ran one `/s` regex over the WHOLE comment-stripped file, so `.*?`
     * crossed method boundaries: with `create()` reordered to check ownership first, the pattern
     * still matched by borrowing `store()`'s `isAbleTo('Create Attributes Option')` at :136 and
     * its `ownedAttribute($id)` at :153. MEASURED: the exact mutant this assertion exists to catch
     * was applied and the test printed a tick.
     *
     * It now reads ONE METHOD BODY at a time, so there is nothing to borrow. Re-measured against
     * the same mutant: it fails.
     */
    public function test_the_permission_check_still_runs_first(): void
    {
        foreach ([
            ['create', 'Create Attributes Option', 'ownedAttribute'],
            ['edit', 'Edit Attributes Option', 'ownedOption'],
        ] as [$method, $permission, $helper]) {
            $body = $this->methodBody($method);

            $permissionAt = strpos($body, "isAbleTo('" . $permission . "')");
            $ownershipAt = strpos($body, $helper . '($id)');

            $this->assertNotFalse($permissionAt, "{$method}() must check isAbleTo('{$permission}').");
            $this->assertNotFalse($ownershipAt, "{$method}() must resolve ownership via {$helper}().");

            $this->assertLessThan(
                $ownershipAt,
                $permissionAt,
                "In {$method}() the permission check must come BEFORE the ownership lookup, or a "
                . 'caller without the permission learns whether an id exists in this store from '
                . 'the shape of the refusal.'
            );
        }
    }

    /**
     * One method's body, from its signature to the next method declaration.
     *
     * This exists because asserting ORDER over a whole file asserts nothing: every needle is
     * somewhere later in some other method.
     */
    private function methodBody(string $method): string
    {
        $code = $this->codeWithoutComments(self::CONTROLLER);

        $start = preg_match('/\n    public function ' . $method . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)
            ? $m[0][1]
            : false;
        $this->assertNotFalse($start, "No method {$method}() in the controller.");

        $rest = substr($code, $start + 1);
        $end = preg_match('/\n    (?:public|private|protected) function /', $rest, $m2, PREG_OFFSET_CAPTURE)
            ? $m2[0][1]
            : strlen($rest);

        $body = substr($rest, 0, $end);

        // Anti-vacuity: a body that captured nothing, or captured the whole file, proves nothing.
        $this->assertGreaterThan(80, strlen($body), "The {$method}() body looks empty.");
        $this->assertLessThan(strlen($code) - 200, strlen($body), "The {$method}() body swallowed the file.");
        $this->assertSame(1, preg_match_all('/public function ' . $method . '\s*\(/', $body));

        return $body;
    }

    public function test_this_test_is_not_vacuous(): void
    {
        $code = $this->read(self::CONTROLLER);

        $this->assertGreaterThan(2_000, strlen($code), 'This is the real controller.');
        $this->assertStringContainsString('class ProductAttributeOptionController', $code);
        $this->assertStringContainsString('private function denied()', $code);

        // `denied()` being redirect()->back() is the reason every live probe of this controller
        // must reset to a known page first. If it ever stops being back(), that note can go.
        $this->assertMatchesRegularExpression(
            '/private function denied\(\)\s*\{\s*return redirect\(\)->back\(\)/s',
            $this->codeWithoutComments(self::CONTROLLER)
        );
    }

    /**
     * Source with comments stripped.
     *
     * The docblocks added by this change quote `create($id)` and `edit($id)` — the defective forms
     * — while explaining them, so a search over the raw file matches the explanation and reports
     * the thing it forbids. That has now happened FIVE times in this phase; see M-PKG-A-10 in the
     * decision register.
     */
    private function codeWithoutComments(string $relative): string
    {
        return (string) preg_replace(
            ['#/\*.*?\*/#s', '#^\s*//.*$#m', '#\{\{--.*?--\}\}#s'],
            '',
            $this->read($relative)
        );
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
