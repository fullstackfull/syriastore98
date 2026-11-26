<?php

namespace Tests\Unit\Routing;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * TWO DECLARATIONS OF ONE METHOD+URI: THE SECOND WINS AND THE FIRST VANISHES WITH ITS MIDDLEWARE.
 * =================================================================================================
 *
 * `RouteCollection::addToCollections()` keys on method + domain + uri, so a later declaration
 * REPLACES an earlier one — the earlier route's action, its middleware and its name binding all go,
 * with no warning at declare time, nothing at boot and nothing in any log. CLAUDE.md records this
 * as a standing hazard; this file measures it.
 *
 * FOUND BY ACCIDENT, WHICH IS THE ARGUMENT FOR HAVING IT. While withdrawing GAP-268's dead routes I
 * duplicated a line by mistake (`POST ticket/{id}/conversion`, declared twice in one edit). Same
 * action and same middleware, so nothing broke and no test would have said a word.
 *
 * IT TAKES TWO MECHANISMS, AND THE FIRST VERSION OF THIS FILE HAD ONLY ONE — which is recorded here
 * because the hole read exactly like coverage. Censusing the BUILT route table finds a duplicate
 * only when the two declarations carry different METHOD SETS: `Route::any` stores its route in
 * `allRoutes` under its last method (`OPTIONS<uri>`) while `Route::post` stores under `POST<uri>`,
 * so both objects survive iteration and a per-method count sees POST twice. But two `Route::post`
 * declarations of one URI share that key, the second OVERWRITES the first, and the built table
 * cannot show what is no longer in it. MEASURED: with a deliberate `Route::post('duplicate-probe-
 * ws4', ...)` added twice, the table-based assertion stayed GREEN. So the source files are censused
 * too, comment-stripped.
 *
 * WHAT THE SOURCE CENSUS CANNOT SEE, stated plainly because an overstated guard is how this
 * project's defects survive: it matches the literal first argument of `Route::<verb>(` and does NOT
 * resolve `Route::prefix()` groups. So it produces FALSE POSITIVES — two identical literals in
 * groups with different prefixes are different URIs — and it is blind to a real collision assembled
 * from different literals, or spread across two files. Both false positives below are therefore
 * recorded with the measured effective URIs that make them harmless, rather than filtered out by a
 * rule that would also hide a real one.
 *
 * THE ONE WITH A LIVE CONSEQUENCE, and I did not fix it on purpose:
 *
 *     routes/web.php:857  Route::post('/plan-pay-with-esewa', [...::class, 'failure'])
 *                             ->name('plan.esewa.payment.failure');
 *     routes/web.php:858  Route::any ('/plan-pay-with-esewa', [...::class, 'success'])
 *                             ->name('plan.esewa.payment.success');
 *
 * `Route::any` includes POST, so the second takes the `POST /plan-pay-with-esewa` slot. MEASURED:
 * that POST dispatches `ESewaPaymentController@success`, and `route('plan.esewa.payment.failure')`
 * still resolves BY NAME to the same URL — so the eSewa failure callback is unreachable and anything
 * generating the failure URL sends the payer to the success handler. Present verbatim at this
 * branch's merge base (`dc9a01b1f`), so it is not new. The Sofort pair three lines above uses two
 * DIFFERENT URIs (`/plan-pay-with-soforts` vs `/plan-pay-with-sofort`), so eSewa looks like a
 * missing `s` — but "looks like" is no basis for re-routing a payment callback: the fix would make a
 * handler that has never run start running on a real gateway's POST. That is a change to payment
 * behaviour and an owner decision, so it is reported, not changed.
 *
 * THE FOUR EXACT RE-DECLARATIONS are harmless TODAY and are left alone for the same reason in
 * reverse — deleting them changes nothing observable, and they are not this batch's subject. They
 * are declared so they are visible: each is the same action, the same route name and the same
 * middleware twice over, verified line by line with no group boundary between the pair. The hazard
 * is the next edit, which will change the copy that loses.
 */
class NoAccidentalDuplicateRouteDeclarationsTest extends TestCase
{
    use StripsSourceComments;

    /**
     * `"<METHOD> <literal uri>" => measured status`. Asserted as an exact map, both directions.
     *
     * @var array<string, string>
     */
    private const KNOWN_DUPLICATE_LITERALS = [
        'GET getAvailableLanguages' => 'routes/api.php:267 and :335, both inside '
            . "Route::prefix('admin') -> api/admin/getAvailableLanguages twice. EXACT "
            . 're-declaration: same action, same middleware [custom.auth, AdminApiLog]. No '
            . 'middleware lost.',
        'GET pricing' => 'FALSE POSITIVE of a prefix-blind census. routes/web.php:433 is inside '
            . "->prefix('platform')->name('platform.') so it is platform/pricing (platform."
            . 'pricing); :460 is pricing (apps.pricing). Different URIs, both live.',
        'POST abandon-wish-emailsend' => 'routes/web.php:1498 and :1516, no group boundary '
            . 'between them. EXACT re-declaration: same action, same name wish.emailsend, same '
            . 'middleware. No middleware lost.',
        'POST adminlogin' => 'FALSE POSITIVE of a prefix-blind census. routes/api.php:83 is at '
            . "top level (api/adminlogin); :183 is inside Route::prefix('admin') "
            . '(api/admin/adminlogin). Different URIs, both live, both [AdminApiLog, '
            . 'throttle:auth].',
        'POST products/attribute_combination' => 'routes/web.php:1077 and :1094, no group '
            . 'boundary between them. EXACT re-declaration: same action, same name, same '
            . 'middleware. No middleware lost.',
        'POST updateStoreLanguage' => 'routes/api.php:266 and :332, both inside '
            . "Route::prefix('admin') -> api/admin/updateStoreLanguage twice. EXACT "
            . 're-declaration: same action, same middleware [custom.auth, AdminApiLog]. No '
            . 'middleware lost.',
        'RESOURCE stores' => 'routes/web.php:988 and :1014, no group boundary between them -- '
            . 'the TENANT management surface, and the widest shape of this defect because one '
            . 'declaration generates seven routes. EXACT re-declaration: measured from the built '
            . 'table, each of the ten stores.* routes appears ONCE with identical middleware '
            . '[web, web, auth, xss, setlocate, verified], so nothing is lost today. Found while '
            . 'measuring the platform-admin surface for GAP-178, by which point this census had '
            . 'been green over it for a whole commit -- `Route::resource` is not '
            . '`Route::<verb>(`, so the first version of the regex could not see it.',
    ];

    /**
     * The cross-method duplicates the BUILT table can see, as `"<METHOD> <uri>"`.
     *
     * @var list<string>
     */
    private const KNOWN_DUPLICATES_IN_TABLE = [
        'POST plan-pay-with-esewa',
    ];

    /** @return list<string> "METHOD uri" appearing more than once in the built table */
    private function duplicatesInBuiltTable(): array
    {
        $counts = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                /* HEAD is auto-added alongside every GET and duplicates nothing of its own. */
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method . ' ' . $route->uri();
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $dups = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
        sort($dups);

        return $dups;
    }

    /** @return list<string> "METHOD literal-uri" declared more than once in one route file */
    private function duplicateLiteralsInSource(): array
    {
        /*
         * `resource` AND `apiResource` ARE IN THIS LIST BECAUSE LEAVING THEM OUT WAS A REAL
         * HOLE, found while measuring the platform-admin surface for `GAP-178`:
         * `Route::resource('stores', StoreController::class)` is declared TWICE in
         * `routes/web.php` and this census could not see it, because `Route::resource` is
         * not `Route::<verb>(`. One declaration generates seven routes, so a duplicate
         * resource is the widest version of this defect, not a narrower one.
         */
        $verbs = 'get|post|put|patch|delete|options|any|resource|apiResource';
        $dups = [];

        foreach (['api', 'auth', 'customer', 'web'] as $file) {
            $src = $this->sourceWithoutComments('routes/' . $file . '.php');

            preg_match_all(
                '/Route::(' . $verbs . ')\s*\(\s*([\'"])([^\'"]*)\2/i',
                $src,
                $matches,
                PREG_SET_ORDER
            );

            $counts = [];

            foreach ($matches as $m) {
                $key = strtoupper($m[1]) . ' ' . ltrim($m[3], '/');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }

            foreach (array_filter($counts, static fn (int $n): bool => $n > 1) as $key => $_) {
                $dups[] = $key;
            }
        }

        $dups = array_values(array_unique($dups));
        sort($dups);

        return $dups;
    }

    /**
     * PREMISE. Both mechanisms read something. Without this they pass against nothing — the failure
     * mode that let the first GAP-268 ratchet examine 0 of 1,500 routes and go green.
     */
    #[Test]
    public function test_premise_both_the_table_and_the_source_were_actually_read(): void
    {
        $routes = 0;

        foreach (Route::getRoutes() as $route) {
            $routes++;
        }

        $this->assertGreaterThan(1000, $routes, 'PREMISE: only ' . $routes . ' routes were read.');

        $declarations = preg_match_all(
            '/Route::(get|post|put|patch|delete|options|any)\s*\(\s*[\'"]/i',
            $this->sourceWithoutComments('routes/web.php')
        );

        $this->assertGreaterThan(
            200,
            $declarations,
            'PREMISE: only ' . $declarations . ' literal route declarations were found in '
            . 'routes/web.php, so the source census is reading almost nothing.'
        );
    }

    /** THE SOURCE CENSUS — catches the same-verb copy-paste the built table cannot show. */
    #[Test]
    public function test_the_set_of_duplicate_literal_declarations_is_the_measured_one(): void
    {
        $observed = $this->duplicateLiteralsInSource();

        $declared = array_keys(self::KNOWN_DUPLICATE_LITERALS);
        sort($declared);

        $this->assertSame($declared, $observed, sprintf(
            "The set of duplicate literal route declarations changed.\n  observed: %s\n  declared: %s\n\n"
            . 'A NEW entry means one method+URI is declared twice in one file: the LAST declaration '
            . 'wins and the earlier one disappears along with its middleware, silently. Check '
            . 'whether the two carry the SAME middleware before deciding it is harmless — if they '
            . 'differ, a guard has just been dropped. A MISSING entry means a duplicate was '
            . 'resolved; delete it from KNOWN_DUPLICATE_LITERALS, and if it is one of the two '
            . 'recorded there as prefix-blind false positives, check you have not removed a live '
            . 'route by mistake.',
            json_encode($observed),
            json_encode($declared)
        ));
    }

    /** THE BUILT-TABLE CENSUS — catches the cross-method case, which is the one with a consequence. */
    #[Test]
    public function test_the_set_of_duplicates_in_the_built_table_is_the_measured_one(): void
    {
        $observed = $this->duplicatesInBuiltTable();

        $declared = self::KNOWN_DUPLICATES_IN_TABLE;
        sort($declared);

        $this->assertSame($declared, $observed, sprintf(
            "The set of cross-method duplicate declarations changed.\n  observed: %s\n  declared: %s\n\n"
            . 'This is the shape with real consequences: a `Route::any` and a `Route::post` on one '
            . 'URI both reach the table, and the later one takes the POST slot while the earlier '
            . "route's name still resolves to that URL. Do not add an entry here to get green.",
            json_encode($observed),
            json_encode($declared)
        ));
    }

    /**
     * The consequence itself, driven rather than described — so the eSewa entry cannot be retired by
     * editing a comment.
     */
    #[Test]
    public function test_the_esewa_failure_callback_is_still_the_one_that_loses_the_slot(): void
    {
        $post = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/plan-pay-with-esewa', 'POST')
        );

        $this->assertSame(
            \App\Http\Controllers\ESewaPaymentController::class . '@success',
            $post->getActionName(),
            'POST /plan-pay-with-esewa no longer dispatches @success. If it now reaches @failure, '
            . 'the duplicate was resolved by reordering — which changes which handler a real '
            . 'gateway POST runs. Confirm that was intended, then update this case and the '
            . 'declared sets.'
        );

        $this->assertNotNull(
            Route::getRoutes()->getByName('plan.esewa.payment.failure'),
            'the failure route NAME is gone. That is an improvement only if nothing generates it; '
            . 'otherwise `route()` throws at render time.'
        );
    }
}
