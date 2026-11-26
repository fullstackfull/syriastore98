<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ModuleResourceOwnershipGuard;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * AN UNAUTHENTICATED CROSS-TENANT PRODUCT EXPORT, CLOSED BY A GUARD THAT ALREADY RAN ON IT.
 * =================================================================================================
 *
 * `packages/workdo/ProductCSV/src/routes/web.php:20-21`:
 *
 *     Route::middleware(['web'])->group(function () {              // web ALONE
 *         Route::get('{storeslug}/csvExport/{id}', [ProductCSVController::class, 'csvExport']);
 *     });
 *
 * No `auth`. No `PlanModuleCheck`. `csvExport()` asks no permission question and reads
 * `Product::where('id', $id)->first()` with no store predicate — on a model CLAUDE.md records as
 * carrying NO effective global scope for any principal (it is in `StoreScope`'s registry but NOT
 * in `config/tenancy.php`'s `scoped_models`, so the scope short-circuits). The `{storeslug}`
 * segment is decoration: the handler resolves a store and never uses it.
 *
 * So any visitor could export any store's product by integer id, and the CSV carries price, sale
 * price, tax, weight, **product stock**, stock status and the low-stock threshold.
 *
 * LATENT RATHER THAN LIVE, for a reason that is not a control: `ProductCSV` is not in
 * `PackageServiceProvider`'s `$staticAddons`, so the route does not exist until a merchant enables
 * the module — measured, `route:list` returns nothing for it. A merchant-facing toggle is not a
 * security boundary, so this is closed rather than noted.
 *
 * WHY THIS TEST DRIVES THE GUARD DIRECTLY INSTEAD OF OVER HTTP. The route is absent from the
 * router in this environment, so an HTTP test would 404 and pass while asserting nothing — the
 * vacuity CLAUDE.md warns about, where a status code photographs the wrong screen. The guard is
 * first-party code and its decision is the thing being added, so the decision is what is driven:
 * a synthetic `Route` whose `uri()` is the vendored one, which is how `handle()` looks a target up
 * (`self::TARGETS[$request->method() . ' ' . $route->uri()]`).
 *
 * The URI is also asserted against the vendored file itself, so an upstream rename breaks this
 * test rather than silently removing the protection.
 */
class ProductCsvExportOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '{storeslug}/csvExport/{id}';

    private function runGuard(?User $principal, int $productId, string $slug = 'any-slug')
    {
        if ($principal !== null) {
            $this->actingAs($principal, 'web');
        }

        $request = Request::create('/' . $slug . '/csvExport/' . $productId, 'GET');

        $route = new RoutingRoute(['GET'], self::URI, ['uses' => fn () => 'handler reached']);
        $route->bind($request);
        $route->setParameter('storeslug', $slug);
        $route->setParameter('id', (string) $productId);
        $request->setRouteResolver(fn () => $route);

        return (new ModuleResourceOwnershipGuard())->handle(
            $request,
            fn () => response('handler reached', 200)
        );
    }

    /**
     * The guard REFUSES BY ABORTING, not by returning a response, so a direct `handle()` call
     * raises rather than handing back a status. That is worth capturing precisely: the first
     * version of this file asserted `assertNotSame(200, $response->getStatusCode())` and the
     * refusal arrived as an uncaught HttpException, which reads as a broken test rather than a
     * working guard. Keying on the STATUS of the abort also means a refusal for the RIGHT reason
     * is distinguishable from a handler that merely failed to run.
     *
     * @return int the HTTP status the guard refused with, or 200 if it let the request through
     */
    private function refusalStatus(?User $principal, int $productId, string $slug): int
    {
        try {
            return $this->runGuard($principal, $productId, $slug)->getStatusCode();
        } catch (HttpException $refused) {
            return $refused->getStatusCode();
        }
    }

    #[Test]
    public function test_premise_the_vendored_route_still_declares_the_uri_this_guard_targets(): void
    {
        $routeFile = base_path('packages/workdo/ProductCSV/src/routes/web.php');

        $this->assertFileExists($routeFile, 'premise: the ProductCSV module is still vendored here');

        $source = (string) file_get_contents($routeFile);

        $this->assertStringContainsString(
            "'" . self::URI . "'",
            $source,
            'the vendored export route no longer declares the URI this guard targets. A target '
            . 'keyed on a URI that no longer exists protects nothing — re-point it rather than '
            . 'deleting this premise.'
        );
    }

    #[Test]
    public function test_premise_the_handler_still_reads_the_product_without_a_store_predicate(): void
    {
        $controller = base_path('packages/workdo/ProductCSV/src/app/Http/Controllers/ProductCSVController.php');

        $this->assertFileExists($controller);

        $this->assertStringContainsString(
            "Product::where('id', \$id)->first()",
            (string) file_get_contents($controller),
            'the vendored handler no longer carries the unscoped read this guard exists to '
            . 'compensate for. If upstream scoped it, say so here rather than assuming the guard '
            . 'is now redundant — the anonymous limb is still doing work.'
        );
    }

    #[Test]
    public function test_an_anonymous_visitor_cannot_export_any_stores_product(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $product = Product::forceCreate([
            'name' => 'Victim Product', 'slug' => 'victim-product',
            'price' => 99, 'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $this->assertSame(
            403,
            $this->refusalStatus(null, $product->id, $store->slug),
            'an unauthenticated visitor reached the export handler. This is the whole finding: '
            . 'the route carries only `web`, so nothing but this guard stands between a stranger '
            . 'and another store\'s stock and pricing.'
        );
    }

    #[Test]
    public function test_a_merchant_cannot_export_another_stores_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $this->assertNotSame($storeA->id, $storeB->id, 'premise: two distinct tenants');

        $victim = Product::forceCreate([
            'name' => 'A Product', 'slug' => 'a-product',
            'price' => 99, 'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $storeA->id, 'created_by' => $merchantA->id,
        ]);

        $this->assertSame(
            403,
            $this->refusalStatus($merchantB->fresh(), $victim->id, $storeB->slug),
            'store B\'s merchant exported store A\'s product. `Product` carries no effective '
            . 'global scope, so the guard is the only thing comparing the row\'s store to the '
            . 'caller\'s.'
        );
    }

    #[Test]
    public function test_the_owning_merchant_still_reaches_their_own_export(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $own = Product::forceCreate([
            'name' => 'Own Product', 'slug' => 'own-product',
            'price' => 99, 'product_stock' => 7, 'track_stock' => 1, 'variant_product' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        $this->assertSame(
            200,
            $this->refusalStatus($merchant->fresh(), $own->id, $store->slug),
            'the owning merchant was refused their own export. A guard that refuses everyone is '
            . 'not a fix, and this is the control that says so.'
        );
    }
}
