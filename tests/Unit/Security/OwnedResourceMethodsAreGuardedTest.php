<?php

namespace Tests\Unit\Security;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * P2.5-PKG-B RATCHET. An empty controller body is an existence oracle.
 *
 * Route-model binding resolves the row BEFORE the action runs. For a model absent from
 * `config/tenancy.php`'s `scoped_models`, `StoreScope::isActiveFor()` short-circuits and the
 * global scope adds NO predicate for any principal, so the binding reaches EVERY tenant's rows.
 * A method whose body is `{ // }` therefore answers 200 for a row in any store and 404 only for
 * one that exists nowhere -- it enumerates other tenants' primary keys without returning a byte
 * of their data, which is why eleven instances of it survived every earlier sweep.
 *
 * MEASURED HISTORY of this one shape: five instances closed in F-SEC-02, one in F-SEC-05, and
 * five more in F-SEC-07 (FlashSale::show, ShippingMethod::show, ShippingMethod::destroy,
 * Testimonial::show, ProductAttribute::show, Webhook::show, Wishlist::edit). Each was found by
 * hand, after the previous sweep had declared the shape closed. This test is the reason a twelfth
 * cannot be introduced quietly: it reddens the blocking Unit suite, which CI runs first.
 *
 * WHY THE RULE IS "BODY IS EMPTY" AND NOT "HAS A GUARD": a name-based check for a guard is what
 * failed four times in the census that preceded this file -- it matched assignments as checks,
 * `creatorId()` as a tenant predicate, and its own comment. "Empty" is not a heuristic: adding
 * any guard makes the body non-empty, so the ratchet is satisfied by the fix and by nothing else.
 * It deliberately does NOT try to judge whether a non-empty body's guard is CORRECT -- that is
 * what `tenant-authz-reviewer` and the cross-tenant Feature tests are for.
 *
 * KNOWN LIMITATION, stated rather than hidden: no WorkDo module loads in any test run (see
 * CLAUDE.md §1), so module routes are absent from the route table this test walks. It covers
 * first-party `app/Http/Controllers` only. `$examined` is asserted against a floor below so the
 * test cannot start passing by silently walking an empty route table -- the failure mode that
 * made three earlier censuses report zero.
 */
class OwnedResourceMethodsAreGuardedTest extends TestCase
{
    /**
     * Models that are route-model-bound on a routed action but carry no tenant at all, so an
     * empty body reveals nothing about any store. Every entry needs a measured reason.
     */
    private const PLATFORM_GLOBAL_MODELS = [
        // `plans` has no `store_id` column: a SaaS plan is a platform object offered TO stores,
        // not owned BY one, and the plan list is visible to every merchant by design.
        \App\Models\Plan::class => 'plans has no store_id column; a plan belongs to the platform',
    ];

    /**
     * The floor exists so an empty or broken walk fails instead of reporting success. Measured at
     * the time of writing; raise it, never lower it, unless routes were genuinely removed.
     */
    private const MINIMUM_BOUND_METHODS_EXAMINED = 20;

    public function test_no_routed_route_model_bound_action_on_an_unscoped_model_has_an_empty_body(): void
    {
        $scoped = array_map(
            static fn (string $class): string => ltrim($class, '\\'),
            (array) config('tenancy.scoped_models', [])
        );

        $this->assertNotEmpty(
            $scoped,
            'config/tenancy.php scoped_models came back empty, so every model would look unscoped '
            .'and this test would be asserting against the wrong premise.'
        );

        $examined = 0;
        $violations = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue; // closure or invokable; nothing to reflect a parameter list from
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue; // a module controller whose prefix is not registered in tests
            }

            $reflection = new ReflectionMethod($class, $method);

            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $model = ltrim($type->getName(), '\\');

                if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                    continue;
                }

                $examined++;

                if (in_array($model, $scoped, true)) {
                    continue; // StoreScope really does add a predicate for this one
                }

                if (array_key_exists($model, self::PLATFORM_GLOBAL_MODELS)) {
                    continue;
                }

                if ($this->bodyIsEmpty($reflection)) {
                    $violations[] = sprintf(
                        '%s::%s(%s $%s) -- routed as [%s %s]',
                        class_basename($class),
                        $method,
                        class_basename($model),
                        $parameter->getName(),
                        implode('|', $route->methods()),
                        $route->uri()
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_BOUND_METHODS_EXAMINED,
            $examined,
            'This test walked fewer route-model-bound parameters than the measured floor, so it '
            .'proved nothing. Either the route table did not load or the reflection stopped '
            .'resolving model types -- investigate before changing the floor.'
        );

        $this->assertSame(
            [],
            $violations,
            "A routed action is route-model-bound to a model that `scoped_models` does NOT scope, "
            ."and its body is empty. Route-model binding has already loaded another tenant's row "
            ."by the time the method runs, so the response is an existence oracle over every "
            ."store's primary keys. Add an ownership guard that `abort(404)`s for a foreign row "
            ."-- 404, not 403, so refusal and absence are indistinguishable. Offenders:\n  - "
            .implode("\n  - ", $violations)
        );
    }

    /**
     * True when the method contains nothing but whitespace and comments.
     *
     * Tokenises rather than regexing: `//` inside a string literal and a `}` inside a comment
     * both defeated the first draft of this helper, and the census that preceded this file
     * matched its OWN explanatory comment as evidence of a guard.
     */
    private function bodyIsEmpty(ReflectionMethod $reflection): bool
    {
        $file = $reflection->getFileName();

        if ($file === false) {
            return false; // internal or evaluated code; not something a diff can regress
        }

        $lines = array_slice(
            file($file),
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        );

        $source = implode('', $lines);
        $open = strpos($source, '{');
        $close = strrpos($source, '}');

        if ($open === false || $close === false || $close <= $open) {
            return false; // abstract or interface declaration: no body to be empty
        }

        $body = substr($source, $open + 1, $close - $open - 1);

        foreach (token_get_all('<?php '.$body) as $token) {
            if (! is_array($token)) {
                return false; // a single-character token such as ';' is real code
            }

            if (! in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return false;
            }
        }

        return true;
    }
}
