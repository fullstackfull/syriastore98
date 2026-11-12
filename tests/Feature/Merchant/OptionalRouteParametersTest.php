<?php

namespace Tests\Feature\Merchant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * P2.5 -- a route that declares a parameter optional must have a controller that agrees.
 * =================================================================================================
 *
 * FOUND BY DRIVING `/manage-language`. `routes/web.php:1391` declares
 * `manage-language/{lang?}/{module?}`, so Laravel routes a bare `/manage-language` to
 * `LanguageController::manageLanguage` with no argument -- and the signature was
 * `manageLanguage($currantLang, $module = 'General')`. A missing argument for a parameter with no
 * default is an **ArgumentCountError before a line of the body runs**, which Laravel renders as
 * HTTP 500. Measured on the dev fixture in all four language/direction/theme combinations, while
 * `/manage-language/en` answered 200 throughout.
 *
 * No shipped link reaches it that way -- `partision/header.blade.php:410` passes the user's
 * language and `language/index.blade.php:48,55` pass `$currantLang` -- so it takes a trimmed URL
 * or a stale bookmark. A white error page is still the wrong answer to one.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE WAYS THIS TEST PASSED FOR THE WRONG REASON BEFORE IT WORKED
 * -------------------------------------------------------------------------------------------------
 *
 * Each was found by the project's revert step -- put the defect back, confirm the test reddens --
 * and each is worth keeping written down, because all three are invisible in a green run.
 *
 *  1. IT DROVE THE ROUTES AND MEASURED THE LOGIN REDIRECT. The first version issued
 *     `$this->get($uri)` and asserted the status was under 500. Every such route sits behind
 *     `auth`; an unauthenticated GET is a 302 to the sign-in screen and the controller is never
 *     reached. Authenticating would only have moved the problem -- a bare `User::factory()` row
 *     has no store, and `partision/header.blade.php` then 500s on
 *     `getStoreById(getCurrentStore())`, which is the condition behind this project's declared
 *     accepted failure `ProfileTest::test_profile_page_is_displayed`.
 *
 *  2. IT SKIPPED THE ONE ROUTE IT WAS WRITTEN FOR. `manage-language/{lang?}/{module?}` has TWO
 *     trailing optionals, and the filter dropped any path still holding a `{` after stripping one.
 *     Hence the premise case below, which names that route rather than counting candidates.
 *
 *  3. IT ASKED `allowsNull()`. An UNTYPED parameter reports `allowsNull() === true` -- no type, so
 *     null is acceptable -- and `manageLanguage($currantLang, ...)` is untyped, so the condition
 *     could never fire for the exact signature it was written against. **A missing argument is not
 *     a null argument:** PHP raises ArgumentCountError whether or not null would have been
 *     tolerated. Nullability is irrelevant; only a default or `...$variadic` fills a position.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY IT SIMULATES THE DISPATCHER INSTEAD OF READING THE SIGNATURE
 * -------------------------------------------------------------------------------------------------
 *
 * A signature read on its own cannot answer two questions that decide whether the defect is real:
 *
 *   - **Can the parameter actually be omitted?** Symfony compiles a placeholder as droppable only
 *     when everything after it is droppable too, so `{storeSlug?}/login` -- optional segment,
 *     required static text -- CANNOT be reached without a slug, while
 *     `manage-language/{lang?}/{module?}` can. Both look identical to a signature reader. This
 *     test builds the bare URL and asks the real router, so the answer is measured.
 *
 *   - **Which route answers the bare URL?** Later declarations replace earlier ones for the same
 *     method and URI in this codebase, and a shadowed route's mismatch is not reachable. `match()`
 *     names the winner.
 *
 * The resolution itself is `Illuminate\Routing\ResolvesRouteDependencies::resolveMethodDependencies`
 * and it is **POSITIONAL, not by name**: `$values[$key - $instanceCount]`. The dispatcher hands it
 * `$route->parametersWithoutNulls()`, so an omitted optional is *removed from the array* and every
 * later position shifts down. A parameter with no value at its position and no default is never
 * spliced in, the final argument list comes up short, and PHP throws. That is the whole mechanism,
 * and `unfilledArguments()` below mirrors it line for line.
 */
class OptionalRouteParametersTest extends TestCase
{
    /**
     * Mirror of ResolvesRouteDependencies::resolveMethodDependencies -- which arguments would be
     * left unfilled, and therefore fatal, for this bound parameter set.
     *
     * @param  array<string, mixed>  $bound  what `parametersWithoutNulls()` would return
     * @return array<int, string>
     */
    private function unfilledArguments(\ReflectionFunctionAbstract $reflector, array $bound): array
    {
        /* Laravel takes `$values` ONCE, before the loop, and never recomputes it -- the splices
           inside the loop mutate `$parameters`, not `$values`. So the original bound ordering is
           what every position is read against. */
        $values = array_values($bound);
        $boundNames = array_keys($bound);

        $instanceCount = 0;
        $missing = [];

        foreach ($reflector->getParameters() as $key => $parameter) {
            $type = $parameter->getType();

            /* A parameter the container fills (`Request $request`, a service) does not consume a
               route value, and `$instanceCount` is how Laravel keeps the positions aligned after
               one. A class-typed parameter whose NAME is a bound route key is the implicit
               model-binding case instead: that value is already in `$parameters`, so it is
               consumed positionally like any other. Distinguishing the two by name is what keeps
               a model-bound route from being reported as missing an argument it really receives. */
            $injected = $type instanceof \ReflectionNamedType
                && ! $type->isBuiltin()
                && ! in_array($parameter->getName(), $boundNames, true);

            if ($injected) {
                $instanceCount++;
                continue;
            }

            if (isset($values[$key - $instanceCount])) {
                continue;   // a value sits at this position
            }

            /* NULLABILITY IS NOT CONSIDERED, and that is deliberate -- see failure 3 above. Only a
               default value or a variadic fills a position that has no value. */
            if ($parameter->isDefaultValueAvailable() || $parameter->isVariadic()) {
                continue;
            }

            $missing[] = '$' . $parameter->getName();
        }

        return $missing;
    }

    /**
     * Every URL this route can be reached by, from the most-stripped to the fully-qualified one.
     *
     * Trailing optionals come off one at a time, because that is the only direction Symfony allows
     * them to be dropped. Any placeholder left after that is given the value `1`, which satisfies
     * both a numeric and a slug `where` constraint, so a route with a NON-trailing optional is
     * still measured at its shortest reachable form rather than being skipped.
     *
     * @return array<int, string>
     */
    private function barestFirst(string $uri): array
    {
        $stripped = [];
        $current = $uri;

        $trailingOptional = '#/?\{[A-Za-z_][A-Za-z0-9_]*\?\}$#';

        while (preg_match($trailingOptional, $current)) {
            $current = (string) preg_replace($trailingOptional, '', $current);
            $stripped[] = $current;
        }

        $stripped = array_reverse($stripped);     // barest first
        $stripped[] = $uri;                       // ...and the untouched form last

        return array_map(
            static fn (string $u) => '/' . ltrim(
                (string) preg_replace('#\{[A-Za-z_][A-Za-z0-9_]*\??\}#', '1', $u),
                '/'
            ),
            $stripped,
        );
    }

    /**
     * @return array<int, array{uri: string, bare: string, method: string, action: string, missing: array<int, string>}>
     */
    private function mismatches(): array
    {
        $bad = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_contains($uri, '?}')) {
                continue;               // nothing optional to omit
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;               // a closure takes its own arguments; nothing to compare
            }

            [$class, $method] = explode('@', $action, 2);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                continue;               // a route pointing at nothing is a different finding
            }

            $reflected = new \ReflectionMethod($class, $method);

            $verb = collect($route->methods())
                ->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true)) ?: 'GET';

            foreach ($this->barestFirst($uri) as $candidate) {
                try {
                    $matched = Route::getRoutes()->match(Request::create($candidate, $verb));
                } catch (\Throwable) {
                    continue;           // this shortening is not a URL the router accepts
                }

                if ($matched->uri() !== $uri || $matched->getActionName() !== $action) {
                    continue;           // another route owns that URL -- shadowing, a different finding
                }

                $missing = $this->unfilledArguments($reflected, $matched->parametersWithoutNulls());

                if ($missing !== []) {
                    $bad[] = [
                        'uri' => $uri,
                        'bare' => $candidate,
                        'method' => $verb,
                        'action' => $action,
                        'missing' => $missing,
                    ];
                }

                break;                  // the barest reachable form is the one that matters
            }
        }

        return $bad;
    }

    // ---------------------------------------------------------------------------------------------
    // Premise. The detector is self-tested, so it cannot quietly stop detecting.
    // ---------------------------------------------------------------------------------------------

    public function test_premise_the_detector_fires_on_a_known_mismatch_and_not_on_a_known_good_one(): void
    {
        /* THREE GREEN RUNS OF THIS FILE PROVED NOTHING, so the detector now proves itself against
           fabricated signatures that do not depend on any route staying broken. */
        $subject = new class
        {
            // The real shape of the defect: untyped, no default, nothing bound.
            public function broken(Request $request, $currantLang, $module = 'General') {}

            // The repair.
            public function fixed(Request $request, $currantLang = null, $module = 'General') {}

            // Nullable but still required -- the `allowsNull()` trap that made this file vacuous.
            public function nullableButRequired(?string $lang) {}
        };

        $this->assertSame(
            ['$currantLang'],
            $this->unfilledArguments(new \ReflectionMethod($subject, 'broken'), []),
            'the detector no longer recognises the defect it exists for'
        );

        $this->assertSame(
            [],
            $this->unfilledArguments(new \ReflectionMethod($subject, 'fixed'), []),
            'the detector reports a correctly-defaulted signature as broken'
        );

        $this->assertSame(
            ['$lang'],
            $this->unfilledArguments(new \ReflectionMethod($subject, 'nullableButRequired'), []),
            'a NULLABLE parameter with no default still has no value to receive: PHP raises '
            . 'ArgumentCountError before nullability is ever consulted'
        );

        $this->assertSame(
            [],
            $this->unfilledArguments(new \ReflectionMethod($subject, 'broken'), ['lang' => 'en']),
            'a bound value at position 0 fills $currantLang -- Laravel binds POSITIONALLY, so the '
            . 'route key need not match the parameter name'
        );
    }

    public function test_premise_the_sweep_reaches_the_route_it_was_written_for(): void
    {
        $seen = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), '?}')) {
                $seen[] = $route->uri();
            }
        }

        $this->assertContains(
            'manage-language/{lang?}/{module?}',
            $seen,
            'the route this test was written for is no longer registered in this shape, so the '
            . 'case below is measuring something else. ' . count($seen) . ' candidates were found.'
        );

        /* And the bare URL really does reach it -- the whole premise of the finding. */
        $this->assertSame(
            '/manage-language',
            $this->barestFirst('manage-language/{lang?}/{module?}')[0],
            'the barest form of the route is not what was measured in the browser'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // The sweep.
    // ---------------------------------------------------------------------------------------------

    public function test_no_reachable_url_leaves_a_controller_argument_unfilled(): void
    {
        $lines = array_map(
            static fn (array $b) => $b['method'] . ' ' . $b['bare']
                . '  ->  ' . $b['action'] . '  (' . $b['uri'] . ')'
                . '  leaves ' . implode(', ', $b['missing']) . ' unfilled',
            $this->mismatches(),
        );

        sort($lines);

        $this->assertSame(
            [],
            $lines,
            "A URL the router accepts resolves to a controller method that cannot be called with "
            . "the arguments it will be given. Laravel's resolver is POSITIONAL and the dispatcher "
            . "strips omitted optionals, so the argument list comes up short and PHP raises "
            . "ArgumentCountError BEFORE the body runs -- HTTP 500, not a redirect and not a 404.\n  "
            . implode("\n  ", $lines)
            . "\n\nGive the parameter a default that matches what the route's own callers pass."
        );
    }
}
