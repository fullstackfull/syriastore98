<?php

namespace Tests\Feature\Security;

use App\Support\ModuleRouteManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * P1-SEC-04 -- ROUTES THE MANIFEST CALLS `inert:*`, PINNED BY EXECUTION.
 * ======================================================================
 *
 * CartQuantityControl declares its routes with `'middleware' => 'web,PlanModuleCheck:CartQuantityControl'` -- ONE string. Laravel reads it as a
 * middleware called `web,PlanModuleCheck` with the parameter `CartQuantityControl`, which is neither an alias, a group nor a class, so every
 * request to the eight `cartquantitycontrol*` routes dies with BindingResolutionException before the controller (an empty scaffold) is reached --
 * for an anonymous caller and for a signed-in one alike. That is why the route census sees them as unauthenticated (no `auth` middleware is
 * among the resolved names) and why ModuleAdministrativeRouteGuard cannot protect them (the `web` group, which carries the guard, is not applied).
 *
 * "Inert" is a claim about the code at this commit, not a property of the module. This test is what keeps it honest: it drives every key the
 * manifest classifies `inert:*` and fails the moment one is SERVED, so an upstream update that repairs the middleware string turns the build red
 * and forces the route into ADMINISTRATIVE (where the guard will cover it) instead of leaving a live, unclassified route.
 */
class InertModuleRoutesTest extends SecurityTestCase
{
    use EnablesWorkdoModules;
    use RefreshDatabase;

    /** @return array<string,array{0:string,1:string}> */
    public static function inertKeys(): array
    {
        $cases = [];

        foreach (ModuleRouteManifest::ANONYMOUS_BY_DESIGN as $key => $category) {
            if (str_starts_with($category, 'inert:')) {
                $cases[$key] = [$key, $category];
            }
        }

        return $cases;
    }

    private function path(string $uri): string
    {
        return '/' . preg_replace(['/\{[^}?]+\?\}/', '/\{[^}]+\}/'], ['', '1'], $uri);
    }

    /** Was the request served by the module (any answer that is not a framework failure)? A thrown exception or a 5xx is "not served". */
    private function served(string $method, string $path): bool
    {
        try {
            $response = $this->call($method, $path);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Illuminate\Contracts\Container\BindingResolutionException::class, $e, "Unexpected failure type for {$method} {$path}: " . $e->getMessage());
            $this->assertStringContainsString('web,PlanModuleCheck', $e->getMessage(), 'It failed, but not for the reason the manifest records.');

            return false;
        }

        return $response->status() < 500;
    }

    public function test_the_premise_the_inert_routes_exist_and_carry_the_unresolvable_middleware_string(): void
    {
        $failed = $this->enableWorkdoModules(['CartQuantityControl']);

        $routes = array_filter(Route::getRoutes()->getRoutes(), fn ($r) => str_starts_with(ltrim($r->getActionName(), '\\'), 'Workdo\\CartQuantityControl\\'));
        $this->assertNotEmpty($routes, 'CartQuantityControl is not loaded, so this test reaches nothing. Failed migrations: ' . implode('; ', $failed));
        $this->assertNotEmpty(self::inertKeys(), 'No manifest key is classified inert: the pin below would be vacuous.');

        foreach ($routes as $route) {
            $this->assertSame(['web,PlanModuleCheck:CartQuantityControl'], $route->gatherMiddleware(), $route->uri() . ' no longer carries the one-string middleware.');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inertKeys')]
    public function test_an_inert_route_is_not_served_to_an_anonymous_caller(string $key): void
    {
        $this->enableWorkdoModules(['CartQuantityControl']);
        [$method, $uri] = explode(' ', $key, 2);

        $this->assertFalse($this->served($method, $this->path($uri)), "{$key} is classified inert but answered an anonymous caller: move it to ADMINISTRATIVE.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inertKeys')]
    public function test_an_inert_route_is_not_served_to_a_signed_in_merchant_either(string $key): void
    {
        $this->enableWorkdoModules(['CartQuantityControl']);
        [$method, $uri] = explode(' ', $key, 2);

        $merchant = $this->makeMerchant();
        $this->actingAs($merchant->fresh());

        $this->assertFalse($this->served($method, $this->path($uri)), "{$key} is classified inert but answered a signed-in merchant: move it to ADMINISTRATIVE.");
    }
}
