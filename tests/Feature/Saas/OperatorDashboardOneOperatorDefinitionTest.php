<?php

namespace Tests\Feature\Saas;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ADM-001 / GAP-231 — ONE REQUEST PATH, ONE DEFINITION OF THE OPERATOR.
 * =================================================================================================
 *
 * `GAP-231` records, among what is measured about the dashboard that already exists, that *one
 * request path holds two definitions of the operator*. This is that finding, driven.
 *
 * THE TWO DEFINITIONS, in the order one request meets them:
 *
 *   1. `HomeController::index()` branched on `$user->type == 'super admin'` — a plain PHP string
 *      comparison.
 *   2. `HomeController::handleRegularUser()` asks `UserPolicy::isOperator($user)`, which is
 *      `strtolower(rtrim($type, ' '))` against the same literal.
 *
 * THEY DISAGREE FOR `'super admin '`, AND MySQL AGREES WITH THE SECOND. `utf8mb4_unicode_ci` is a
 * PAD SPACE collation: a TRAILING space is ignored, so to the database that row IS the operator —
 * which is why `isOperator()` deliberately uses `rtrim` and deliberately does NOT `trim`, since a
 * LEADING space is not folded and `' super admin'` must stay a non-operator.
 *
 * SO THE MEASURED CONSEQUENCE, before the fix: `index()` sent that row to `handleRegularUser()`,
 * which then set `$isOperator = true` — skipping the storeless-merchant logout guard and then every
 * `if (! $isOperator)` figure block — and rendered the MERCHANT dashboard, empty, to the platform
 * operator. Not a privilege escalation in either direction; a principal who is the operator to the
 * engine, to the middleware and to `User::isPlatformAdmin()` and a stranger to the one branch that
 * chooses which dashboard to draw.
 *
 * LATENT, NOT LIVE, AND THAT WAS ESTABLISHED RATHER THAN ASSUMED — the same two links
 * `PlatformOperatorOnly`'s docblock records: the only writers of `users.type` are the seeder's
 * exact `'super admin'` literal and `UserController`'s role-name path, which normalises before
 * refusing the reserved names. It needs a hand-edited row. It is fixed anyway, because the cost is
 * one call and the alternative is a dashboard that cannot agree with itself about who is reading
 * it.
 *
 * WHY THIS IS AN ADM-001 CASE AND NOT A STRAY SECURITY FIX: the recovered decision is a
 * COMPREHENSIVE PLATFORM COMMAND DASHBOARD. A command surface that resolves its own principal twice,
 * two ways, in one request is not comprehensive in the only sense engineering can test.
 */
final class OperatorDashboardOneOperatorDefinitionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The spelling MySQL's PAD SPACE folds, and PHP's `==` does not. */
    private const TRAILING_SPACE = 'super admin ';

    /** The spelling PAD SPACE does NOT fold, so it must stay a non-operator everywhere. */
    private const LEADING_SPACE = ' super admin';

    private function dashboardBody(User $user): string
    {
        (new User)->flushCache();
        Cache::forget('admin_details');

        $response = $this->actingAs(User::find($user->id))->get('/dashboard');

        return (string) $response->getContent();
    }

    /**
     * PREMISE, and it is the whole basis of the case below: the helper and a plain `==` really do
     * disagree about this one string. If they ever agree, the defect is gone and so is this file.
     */
    #[Test]
    public function test_premise_the_helper_and_a_plain_comparison_disagree_about_a_trailing_space(): void
    {
        $operator = $this->makePlatformAdmin(['type' => self::TRAILING_SPACE]);

        $this->assertTrue(UserPolicy::isOperator($operator),
            'the shared helper no longer treats a trailing space as the operator, so MySQL and PHP '
            . 'no longer disagree and this whole file is moot.');

        $this->assertFalse($operator->type == 'super admin',
            'PHP now folds a trailing space in ==, which would make the two definitions agree.');
    }

    /**
     * THE CASE. The operator dashboard is identified by a heading that exists ONLY in
     * `superadmin/dashboard.blade.php`, so the merchant dashboard cannot satisfy it — and the
     * merchant dashboard is named too, so "neither rendered" cannot pass either.
     */
    #[Test]
    public function test_a_trailing_space_operator_gets_the_operator_dashboard(): void
    {
        config(['app.debug' => false]);

        $operator = $this->makePlatformAdmin(['type' => self::TRAILING_SPACE]);
        $this->makeStore($operator, ['default_language' => 'en']);

        $body = $this->dashboardBody($operator);

        $this->assertStringContainsString('Across the whole platform', $body,
            'a principal who is the operator to MySQL, to PlatformOperatorOnly and to '
            . 'UserPolicy::isOperator() was handed the MERCHANT dashboard, because '
            . 'HomeController::index() decides with a plain `==` and everything downstream decides '
            . 'with the shared helper.');

        $this->assertStringContainsString('Total Merchants', $body,
            "the operator dashboard's own cards are absent, so something else rendered.");
    }

    /** CONTROL ONE: the exact spelling is unaffected, so the fix changes nothing for a real row. */
    #[Test]
    public function test_control_the_exact_spelling_still_gets_the_operator_dashboard(): void
    {
        config(['app.debug' => false]);

        $operator = $this->makePlatformAdmin();
        $this->makeStore($operator, ['default_language' => 'en']);

        $this->assertStringContainsString('Across the whole platform', $this->dashboardBody($operator));
    }

    /**
     * CONTROL TWO, AND IT IS THE ONE THAT MATTERS MOST. A LEADING space must NOT buy the operator
     * dashboard: PAD SPACE does not fold it, so the database refuses that row and the application
     * must too. `trim()` here instead of `rtrim()` would pass every other case in this file and
     * fail this one, which is exactly why it is written out.
     */
    #[Test]
    public function test_control_a_leading_space_is_not_the_operator(): void
    {
        config(['app.debug' => false]);

        $impostor = $this->makePlatformAdmin(['type' => self::LEADING_SPACE]);
        $store = $this->makeStore($impostor, ['default_language' => 'en']);
        $impostor->current_store = $store->id;
        $impostor->save();

        $this->assertFalse(UserPolicy::isOperator(User::find($impostor->id)),
            'the premise failed: the helper now treats a LEADING space as the operator, which the '
            . 'database does not.');

        $this->assertStringNotContainsString('Across the whole platform', $this->dashboardBody($impostor),
            'a leading-space type was handed the platform dashboard. PAD SPACE does not fold a '
            . 'leading space, so that row is a stranger to every SQL comparison in the product.');
    }

    /**
     * AND THE BRANCH IS THE SHARED HELPER, asserted on the source so a later edit cannot
     * reintroduce a second spelling that happens to agree on the four rows above.
     *
     * COMMENTS ARE STRIPPED: `HomeController` quotes operator spellings in prose in several places,
     * and CLAUDE.md records eight occasions on which a census was satisfied — or broken — by prose
     * about the thing it measures.
     */
    #[Test]
    public function test_the_dashboard_branch_asks_the_shared_helper_and_not_a_literal(): void
    {
        $stripped = '';

        foreach (token_get_all((string) file_get_contents(app_path('Http/Controllers/HomeController.php'))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $stripped .= is_array($token) ? $token[1] : $token;
        }

        $this->assertDoesNotMatchRegularExpression(
            "/type\s*[!=]=[^=]?\s*'super admin'/",
            $stripped,
            'HomeController carries an un-normalised operator comparison again. Use '
            . 'UserPolicy::isOperator(): a plain comparison disagrees with MySQL about '
            . "'super admin ' and with the rest of this very request path."
        );
    }
}
