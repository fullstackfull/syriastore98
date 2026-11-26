<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * MARKET-004 — THE ONE LIST THE TRUST FILTER DOES NOT COVER, RATCHETED.
 *
 * `ModuleTrust::executable()` is applied in `loadDynamicAddons()`, over the names plucked from
 * `add_on_managers` — which is where an uploaded package can appear, because
 * `ModuleController::install()` writes exactly that row. It is NOT applied to the STATIC pass in
 * `register()`, for two measured reasons, both in that method's comment: the static pass runs before
 * the database connection is treated as ready (the `app->booted()` wrapper exists for that), and
 * `$staticAddons` is a hardcoded pair of first-party names, so no upload can reach it.
 *
 * THE SECOND REASON IS A PROPERTY OF THE SOURCE, NOT AN INVARIANT OF THE CODE, so it is pinned here.
 * A third name added to that array would load without any trust check and without any route gate,
 * and nothing else in the suite would notice: CLAUDE.md records that `LynomiaChat` and `LandingPage`
 * resolve with `add_on_managers` EMPTY precisely because they are in this list.
 *
 * Both are first-party: `LandingPage` is the CMS that owns `/` and 275 routes, and `LynomiaChat` is
 * this project's own integration (the one exception to the "do not edit `packages/workdo/`" rule).
 */
class StaticAddonsStayFirstPartyTest extends TestCase
{
    use StripsSourceComments;

    #[Test]
    public function test_the_static_addon_list_is_exactly_the_two_first_party_modules(): void
    {
        $source = $this->sourceWithoutComments('app/Providers/PackageServiceProvider.php');

        $this->assertSame(
            1,
            preg_match('/\$staticAddons\s*=\s*\[(.*?)\]\s*;/s', $source, $match),
            'premise: the static list must still be a literal array in register(), or this ratchet measures nothing.'
        );

        $this->assertGreaterThan(
            0,
            preg_match_all('/([\'"])((?:(?!\1).)*)\1/s', $match[1], $names),
            'premise: the names must be string literals.'
        );

        $this->assertSame(
            ['LandingPage', 'LynomiaChat'],
            $names[2],
            'A module in $staticAddons loads WITHOUT the ModuleTrust filter and without needing an '
            . 'add_on_managers row. Only first-party modules may be there. If a third is genuinely '
            . 'first-party, add it here and say so; if it is a purchasable or uploaded add-on, it '
            . 'belongs in the dynamic list where MARKET-004 can see it.'
        );
    }

    /**
     * AND THE FILTER IS STILL APPLIED TO THE DYNAMIC LIST. Without this, moving the
     * `ModuleTrust::executable()` call out of `loadDynamicAddons()` would leave the ratchet above
     * green while the boundary was gone — the dynamic half is asserted behaviourally by
     * `tests/Feature/Marketplace/UploadedModuleDoesNotExecuteUntilReviewedTest`, and this is the
     * cheap structural companion that names the call site.
     */
    #[Test]
    public function test_the_dynamic_pass_filters_through_module_trust(): void
    {
        $source = $this->sourceWithoutComments('app/Providers/PackageServiceProvider.php');

        $start = strpos($source, 'function loadDynamicAddons(');
        $this->assertNotFalse($start, 'premise: loadDynamicAddons() must still exist.');

        $end = strpos($source, 'function boot(', $start);
        $body = substr($source, $start, ($end === false ? strlen($source) : $end) - $start);

        $this->assertStringContainsString(
            'ModuleTrust::executable(',
            $body,
            'The dynamic add-on list must pass through the MARKET-004 trust filter before loadAddons().'
        );
        $this->assertStringNotContainsString(
            '$this->loadAddons($dbAddons)',
            $body,
            'The unfiltered list must not be handed to loadAddons().'
        );
    }
}
