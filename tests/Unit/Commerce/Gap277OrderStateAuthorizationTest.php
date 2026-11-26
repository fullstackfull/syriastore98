<?php

namespace Tests\Unit\Commerce;

use App\Support\Orders\OrderStateChangeAuthorization as Authz;
use Tests\TestCase;

/**
 * THE ORDER-STATE PERMISSION PREDICATE, with no HTTP and no principal.  GAP-277.
 * =================================================================================================
 *
 * The Feature twins drive the four doors with real tokens. This file exists for the two arms those
 * cannot reach, and both are fail-closed defaults that a mutation would otherwise survive:
 *
 *   - `allows(null)`. No call site passes an empty order -- all four resolve ownership first and
 *     answer their own not-found before asking this -- so a mutant that turns that arm into
 *     `return true` survives every Feature case in the repository. It is pinned here because
 *     "unreachable today" is not the same claim as "safe if a fifth door forgets to resolve first",
 *     and the whole point of this predicate is to be the thing a new door can call.
 *   - NOBODY SIGNED IN. `MerchantPermissions::names(null)` answers `[]`, so an unauthenticated
 *     caller must come back false rather than falling through any limb. `custom.auth` refuses a
 *     missing token before either storefront door runs, so again no Feature case can see it.
 */
class Gap277OrderStateAuthorizationTest extends TestCase
{
    use \Tests\Support\StripsSourceComments;

    public function test_no_order_is_refused_rather_than_waved_through(): void
    {
        foreach ([null, 0, '', []] as $nothing) {
            $this->assertFalse(Authz::allows($nothing),
                'OrderStateChangeAuthorization::allows(' . var_export($nothing, true) . ') answered '
                . 'true. Every caller resolves ownership first, so this arm is unreachable today -- '
                . 'which is exactly why it has to be fail-closed: it is the default a future door '
                . 'inherits.');
        }
    }

    public function test_nobody_signed_in_may_not_change_an_order(): void
    {
        $this->assertNull(auth()->user(), 'Premise: this case needs the default guard empty.');

        $order = new \App\Models\Order();
        $order->store_id = 1;
        $order->deliveryboy_id = 0;

        $this->assertFalse(Authz::allows($order),
            'An unauthenticated caller was admitted. MerchantPermissions::names(null) answers [], '
            . 'so the permission limb must refuse rather than fall through.');
    }

    public function test_the_store_owner_limb_refuses_a_missing_store_and_a_missing_principal(): void
    {
        $this->assertFalse(Authz::callerOwnsStore(null));
        $this->assertFalse(Authz::callerOwnsStore(0));
        $this->assertFalse(Authz::callerOwnsStore(999999),
            'A store id that names no row was treated as owned.');
    }

    /**
     * THE PERMISSION NAME IS THE ADMIN DOORS' OWN, not a new one.
     *
     * A permission no row exists for can be held by no role, so a gate on a misspelt name is a
     * permanent 403 for everyone but the owner and the operator -- and it would read as working,
     * because both of those limbs come first.
     */
    public function test_the_permission_asked_for_is_the_one_the_platform_really_seeds(): void
    {
        $this->assertSame('Manage Order', Authz::PERMISSION);

        /*
         * `Utility::addNewData()` IS THE HOST'S PERMISSION SEEDER, and it was found by looking
         * rather than assumed: `database/seeders/` does not name this permission at all, so a test
         * that scanned that directory FAILED while the permission was perfectly well seeded. It is
         * a flat list of names inside one method -- the skill's note that "each vendored module
         * seeds its own from its own PermissionTableSeeder, which the enable path runs" is about the
         * module names, not this one.
         */
        /*
         * BOUNDED TO THE METHOD BODY AND COMMENT-STRIPPED, both on the independent review's
         * finding. The first version took `strpos(..., 'function addNewData()')` and asserted
         * against `substr($source, $offset)` -- the declaration to the END of a ~3,400-line file,
         * comments intact. It was true, and it would have been equally satisfied by a LATER method
         * or by a comment quoting the name: the census trap CLAUDE.md records eight times, in its
         * most ordinary form. Brace-matching the body also keeps `"Manage Order"` from being
         * satisfied by the neighbouring `"Manage Orders"` entries if the singular is ever dropped.
         */
        $source = $this->sourceWithoutComments('app/Models/Utility.php');
        $offset = strpos($source, 'function addNewData()');

        $this->assertNotFalse($offset,
            'Utility::addNewData() no longer exists, so this test can no longer say whether the '
            . 'permission this gate asks for is seeded at all.');

        $open = strpos($source, '{', $offset);
        $this->assertNotFalse($open, 'Utility::addNewData() has no body to read.');

        $depth = 0;
        $end = null;
        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        $this->assertNotNull($end, 'Utility::addNewData() body is not brace-balanced after '
            . 'comment stripping, so this census cannot bound itself and would silently read the '
            . 'rest of the file.');

        $body = substr($source, $open, $end - $open + 1);

        $this->assertStringContainsString(
            '"' . Authz::PERMISSION . '"',
            $body,
            'Utility::addNewData() no longer seeds `' . Authz::PERMISSION . '`. A permission no row '
            . 'exists for can be held by no role, so this gate would be a permanent refusal for '
            . 'every principal except the store owner and the platform operator -- and it would '
            . 'still READ as working, because both of those limbs come first.'
        );
    }
}
