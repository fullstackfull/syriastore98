<?php

namespace Tests\Unit\Dashboard;

use Tests\TestCase;

/**
 * Every permission name the Phase 02 merchant views gate on is a name that EXISTS.
 * =============================================================================================
 *
 * WHAT THIS CAUGHT. `dashboard/overview.blade.php` gated the out-of-stock link, the top-products
 * list and the product onboarding step on `isAbleTo('Manage Product')` -- SINGULAR. The seeded
 * name is `Manage Products`, and the singular form exists in no seeder and in no live
 * permissions table. Laratrust answers false for a name nothing holds, so those three sections
 * were hidden from EVERY principal, the tenant owner included, and nothing reported an error.
 *
 * WHY NO EARLIER CHECK SAW IT. The preview harness hands the view a `$GRANTS` array rather than
 * asking Laratrust, so a permission name is never resolved against a real set there; it is a
 * string the harness looks up in its own fixture. The browser checks therefore rendered the
 * "permitted" variants happily with a name that can never be granted. That is the project's
 * "a precondition was never met" shape, with the precondition being the permission's existence.
 *
 * SO THIS CHECKS THE NAME AGAINST THE SEEDER, not against a hand-written list. The seeder is
 * what creates the rows, so it is the only source that can answer "could a role ever hold this".
 */
class DashboardPermissionNamesTest extends TestCase
{
    /** The views whose permission gates are checked. */
    private const VIEWS = [
        'resources/views/dashboard/overview.blade.php',
        'resources/views/partision/menu-zones.blade.php',
        'resources/views/partision/workspace-chip.blade.php',
    ];

    /**
     * Permission names `App\Models\Utility` seeds, plus the ones module seeders add.
     *
     * Read from the source rather than listed by hand: a hand-written list is a second place to
     * forget, and would have been written from the same wrong assumption that produced the bug.
     *
     * @return list<string>
     */
    private function grantableNames(): array
    {
        $names = [];

        $sources = array_merge(
            [base_path('app/Models/Utility.php')],
            glob(base_path('database/seeders/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/database/seeders/PermissionTableSeeder.php')) ?: [],
            glob(base_path('packages/workdo/*/src/Database/Seeders/PermissionTableSeeder.php')) ?: []
        );

        foreach ($sources as $file) {
            if (! is_file($file)) {
                continue;
            }

            /*
             * COMMENT-PROOFED, like the view side already was -- and the asymmetry was a real
             * hole, not a tidiness point. This scan builds the ALLOWLIST, so a comment anywhere
             * in `Utility.php` or any seeder quoting `'Manage Product'` would have ADDED that
             * name to the grantable set and the ratchet would have stopped biting on the exact
             * defect it exists for. The view side strips comments so prose cannot satisfy it;
             * this side strips them so prose cannot widen it. Raised by the tenant/authz review
             * as hardening; it is the repository's own rule ("strip comment lines from any
             * source-grep ratchet") applied to the half that had been missed.
             */
            $source = (string) file_get_contents($file);
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

            if (preg_match_all('/[\'"]((?:Manage|Create|Edit|Delete|Show|Reset|Replay|Module)\s[A-Za-z0-9 ]+)[\'"]/', $source, $m)) {
                foreach ($m[1] as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /** @return list<string> */
    private function gatedNames(): array
    {
        $names = [];

        foreach (self::VIEWS as $relative) {
            $path = base_path($relative);

            if (! is_file($path)) {
                continue;
            }

            // Comment-proofed: a docblock naming the WRONG permission (this file's own history
            // does exactly that) must not satisfy or pollute the scan.
            $source = (string) file_get_contents($path);
            $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

            if (preg_match_all("/isAbleTo\(\s*'([^']+)'/", $source, $m)) {
                foreach ($m[1] as $name) {
                    $names[$name] = $relative;
                }
            }
        }

        return $names;
    }

    public function test_every_permission_the_merchant_views_gate_on_can_actually_be_granted(): void
    {
        $gated = $this->gatedNames();

        // REACHABILITY. An empty scan satisfies the loop vacuously, and these views definitely
        // gate on something.
        $this->assertNotEmpty($gated,
            'No isAbleTo() call found in the Phase 02 merchant views. The scan has stopped '
            . 'matching, which would make this test pass over anything.');

        $grantable = $this->grantableNames();

        $this->assertNotEmpty($grantable, 'No permission names were read from the seeders.');

        $unknown = [];
        foreach ($gated as $name => $view) {
            if (! in_array($name, $grantable, true)) {
                $unknown[] = "{$name}  ({$view})";
            }
        }

        $this->assertSame([], $unknown,
            "These views gate on permission names that NO SEEDER CREATES, so no role can hold "
            . "them and the gate is permanently closed for every principal:\n  - "
            . implode("\n  - ", $unknown)
            . "\n\nThat is not a 403 anybody sees -- it is a section of the dashboard silently "
            . 'absent. Check the plural: the seeded name is `Manage Products`.');
    }
}
