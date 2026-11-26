<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-125 / P04-FL-01 / P4-F114 — NO 401 IN THE ADMIN API IS AN *AUTHORIZATION* REFUSAL.
 * =================================================================================================
 *
 * This is the durable form of the third thing the GAP-125 row asks for: *"sweep the file's other
 * explicit 401s for the same shape"*. A sweep whose result lives only in a report is not evidence —
 * it is a claim about a 12,000-line file that seven concurrent workstreams are editing. So the
 * sweep is a ratchet instead, and it fails when the shape comes back.
 *
 * -------------------------------------------------------------------------------------------------
 * THE SHAPE, AND WHY IT IS A DEFECT RATHER THAN A STYLE PREFERENCE.
 *
 * `updateCustomerInfo()` used to open with:
 *
 *     if (!$adminUser || !in_array($adminUser->type, ['admin', 'super admin'])) { ... 401 }
 *
 * `users.type` is `'staff'` for every staff member, so that was true for all of them — and the
 * shipped Flutter admin app maps ANY 401 onto `_handleUnauthorized()`, which clears preferences and
 * forces a re-login (`repo-B lib/network/network.dart:285-288`). A staff member who tapped "save"
 * on a customer was not told they lacked a right: **they were signed out of the whole app**, with
 * their draft lost.
 *
 * So the rule this file enforces is a two-part one, and both halves matter:
 *
 *   An **authentication** branch — nothing resolved, no principal at all — may answer 401. That is
 *   what 401 means, and `updateCustomerInfo`'s `!$adminUser` limb is deliberately kept (a
 *   `Customer` or `DeliveryBoy` token passes `custom.auth` but logs into a DIFFERENT guard by
 *   tokenable class, so `auth()->user()` is null while the middleware passed).
 *
 *   An **authorization** branch — a principal resolved and was found to lack a right, a store, or
 *   a role — must answer **403**, never 401. The principal's session is valid; it is the action
 *   that is refused. `ApiResponser::error()` answers HTTP **200** by default and 401 has to be
 *   passed deliberately, so every 401 in this file is somebody's explicit decision.
 *
 * -------------------------------------------------------------------------------------------------
 * HOW THE CENSUS AVOIDS THE TWO WAYS THIS KIND OF TEST PASSES FOR THE WRONG REASON.
 *
 * **(1) PROSE ABOUT 401s.** This is the single worst file in the repository for it: it carries
 * roughly forty comment lines discussing the force-logout rule, several of which quote the historic
 * defective condition verbatim. A census that read comments would be measuring the explanations of
 * the fix rather than the code, and CLAUDE.md records this failure mode eight times over. The
 * source therefore comes through `sourceWithoutComments()` (PHP's own tokenizer — not a pattern,
 * which has been measured eating real code in this repository), and
 * `test_the_census_reads_code_and_not_the_comments_about_it` proves the stripping actually
 * happened rather than assuming it.
 *
 * **(2) A SCAN THAT FOUND NOTHING TO LOOK AT.** A census that stops seeing its subject reads
 * exactly like one that looked and found nothing. Two defences: a **count floor**, so a rename or a
 * refactor cannot make this pass vacuously, and a **self-test** that runs the classifier over the
 * historic F114 condition and asserts it is FLAGGED — a detector that cannot fail is not a
 * detector.
 *
 * **NO LINE NUMBERS ARE PINNED, DELIBERATELY.** CLAUDE.md records that an absolute line citation
 * goes stale on any insertion above it and that only the ones a test covers ever say so. A list of
 * 27 line numbers in this file would be wrong by the end of the week and would teach whoever fixed
 * it to renumber rather than to read. The property is pinned; the positions are not.
 */
final class Gap125LogoutStatusCensusTest extends TestCase
{
    use StripsSourceComments;

    /**
     * The admin API's own controller — the one the shipped Flutter app talks to, and the one
     * P4-F114 was found in.
     */
    private const SUBJECT = 'app/Http/Controllers/Api/DashboardController.php';

    /**
     * The census found this many 401 sites when it was written (2026-11-26). The floor is a little
     * below that so ordinary deletion does not fail the build, while a refactor that hides the
     * subject from the scan does.
     */
    private const SITES_FLOOR = 24;

    /**
     * Tokens that mean "this guard asked an AUTHORIZATION question" — a principal had already
     * resolved and the branch is deciding what they may do.
     *
     * `->type` is first because it is the one F114 actually used, and it is the subtlest: a string
     * comparison on `users.type` LOOKS like an identity test and is really a permission test, and
     * it was wrong in both directions — it admitted every `type = 'admin'` row (which public
     * registration hands every self-registered merchant) whatever they had been granted, and
     * refused every staff member whatever they had been granted.
     */
    private const AUTHORIZATION_TOKENS = [
        '->type',
        'in_array',
        'isAbleTo',
        'allowsAny',
        'MerchantPermissions',
        'callerMayActInStore',
        'resolveOwnedStore',
        'PERMISSION_',
        'refuseWithoutPermission',
        'storePermissionDenied',
        /*
         * A RAW CROSS-STORE COMPARISON, added 2026-11-26 after finding the shape in the sibling
         * file. The storefront `Api\ApiController` answers 401 at two sites guarded by
         * `if ($customer->store_id !== null && (int) $customer->store_id !== (int) $store->id)` —
         * a resolved principal refused for belonging to another tenant, which is a 403 question.
         * It is NOT fixed here: that file is the storefront API, which CLAUDE.md records as called
         * by no client in either repository, so it is a lead for the storefront workstream and not
         * a shipped-app logout. It is listed because the token list above would have missed it —
         * none of those names appears in that guard — and a classifier whose blind spot is known
         * only to its author is the census failure this project keeps recording.
         */
        'store_id !==',
        'store_id !=',
        'store_id ==',
    ];

    /** How far above a 401 to look for its guarding `if (`. Generous; the real ones are within 8. */
    private const GUARD_SEARCH_DEPTH = 30;

    /** @return list<string> the subject's code, one entry per ORIGINAL line number (1-based index). */
    private function codeLines(): array
    {
        return explode("\n", $this->sourceWithoutComments(self::SUBJECT));
    }

    /**
     * Every line of CODE that passes 401 as an HTTP status, by original line number.
     *
     * @param list<string> $lines
     *
     * @return list<int>
     */
    private function sites(array $lines): array
    {
        $sites = [];

        foreach ($lines as $index => $line) {
            if (preg_match('/\b401\b/', $line) === 1) {
                $sites[] = $index + 1;
            }
        }

        return $sites;
    }

    /**
     * The text of the branch guarding the 401 at `$site`: from the nearest `if (` above it down to
     * the 401 itself, so a condition spread over several lines is read whole.
     *
     * @param list<string> $lines
     */
    private function guardFor(array $lines, int $site): ?string
    {
        for ($line = $site - 1; $line >= max(1, $site - self::GUARD_SEARCH_DEPTH); $line--) {
            if (preg_match('/\bif\s*\(/', $lines[$line - 1] ?? '') === 1) {
                return implode(' ', array_slice($lines, $line - 1, $site - $line + 1));
            }
        }

        return null;
    }

    /**
     * The authorization tokens present in a guard. Empty means the guard asked an authentication
     * question, which is what a 401 is for.
     *
     * @return list<string>
     */
    private function authorizationTokensIn(string $guard): array
    {
        $found = [];

        foreach (self::AUTHORIZATION_TOKENS as $token) {
            if (str_contains($guard, $token)) {
                $found[] = $token;
            }
        }

        return $found;
    }

    // ═════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /**
     * THE STRIPPING REALLY HAPPENED.
     *
     * `force-clears` is the phrase this file's own comments use when explaining the force-logout
     * rule, and it appears nowhere in its code. If it survives stripping, the census is reading
     * prose and every assertion below is a free pass — which is exactly how this family of test
     * has failed eight times in this repository.
     */
    public function test_the_census_reads_code_and_not_the_comments_about_it(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/' . self::SUBJECT);
        $code = $this->sourceWithoutComments(self::SUBJECT);

        $this->assertStringContainsString('force-clears', $raw,
            'premise: the subject no longer explains the force-logout rule in its comments, so this '
            . 'test can no longer prove that comments are being stripped. Pick another '
            . 'comment-only phrase rather than deleting the check.');

        $this->assertStringNotContainsString('force-clears', $code,
            'the comment stripper did not strip. This file carries ~40 comment lines about the 401 '
            . 'force-logout rule, several quoting the historic defective condition verbatim, so a '
            . 'census that reads them is measuring the explanation of the fix and not the code.');
    }

    /**
     * THE CENSUS CAN STILL SEE ITS SUBJECT.
     *
     * A scan that matches nothing passes every "none of them are bad" assertion. CLAUDE.md's
     * census section is four recorded instances of exactly this.
     */
    public function test_the_census_still_finds_the_401_sites_it_is_meant_to_judge(): void
    {
        $lines = $this->codeLines();
        $sites = $this->sites($lines);

        $this->assertGreaterThanOrEqual(self::SITES_FLOOR, count($sites), sprintf(
            'the 401 census found only %d sites in %s, below its floor of %d. Either the admin API '
            . 'has been split up — in which case this census needs to follow it, and the floor '
            . 'lowered deliberately — or the scan has stopped seeing its subject, which reads '
            . 'exactly like a clean sweep.',
            count($sites),
            self::SUBJECT,
            self::SITES_FLOOR
        ));
    }

    /**
     * THE CLASSIFIER CAN FAIL.
     *
     * Run it over the real historic F114 condition and over the two other authorization shapes
     * this codebase uses, and assert each is FLAGGED. Without this, "no site is flagged" is
     * consistent with a classifier that flags nothing at all — and a detector that cannot fail is
     * not evidence. The skill's rule: a second pair of hands attacks the guard before its result
     * counts.
     */
    public function test_the_classifier_flags_the_historic_defect_and_its_relatives(): void
    {
        $historic = "if (!\$adminUser || !in_array(\$adminUser->type, ['admin', 'super admin'])) { "
            . "return response()->json(['status' => 0], 401);";

        $this->assertNotSame([], $this->authorizationTokensIn($historic),
            'the classifier did not flag P4-F114\'s own condition, so "no site is flagged" below '
            . 'means nothing. This is the exact code that signed merchant staff out of the app.');

        foreach ([
            'permission' => "if (! \$user->isAbleTo('Manage Customer')) { return \$this->error([], 401);",
            'merchant-permissions' => "if (! MerchantPermissions::allowsAny(\$u, ['x'], \$s)) { ... 401);",
            'ownership' => "if (! \$this->resolveOwnedStore(\$storeId)) { ... 401);",
            // the real guard from Api\ApiController, which the original token list missed
            'raw cross-store' => "if (\$customer->store_id !== null && (int) \$customer->store_id "
                . "!== (int) \$store->id) { return \$this->error([], 401);",
        ] as $name => $shape) {
            $this->assertNotSame([], $this->authorizationTokensIn($shape),
                'the classifier did not flag the ' . $name . ' shape');
        }

        $this->assertSame([], $this->authorizationTokensIn('if (!$adminUser) { ... 401);'),
            'the classifier flagged a bare authentication guard, which is the one shape that is '
            . 'legitimately a 401 — it would make the census unsatisfiable and teach the next '
            . 'reader to delete it');
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE CENSUS

    /**
     * EVERY 401 IN THE ADMIN API IS GUARDED BY AN AUTHENTICATION TEST, NOT AN AUTHORIZATION ONE.
     *
     * This is the sweep. Measured 2026-11-26: 27 sites, 27 of them `!$user` / `!$adminUser` /
     * token-parse failures, 0 asking an authorization question. P4-F114 was the only one of its
     * kind in the file and it is fixed.
     */
    public function test_no_401_in_the_admin_api_refuses_an_authenticated_principal(): void
    {
        $lines = $this->codeLines();
        $offenders = [];

        foreach ($this->sites($lines) as $site) {
            $guard = $this->guardFor($lines, $site);

            $this->assertNotNull($guard, sprintf(
                '%s:%d answers 401 with no `if (` within %d lines above it, so this census cannot '
                . 'tell whether it is an authentication branch or an authorization one. Give it a '
                . 'readable guard rather than widening the search.',
                self::SUBJECT,
                $site,
                self::GUARD_SEARCH_DEPTH
            ));

            $tokens = $this->authorizationTokensIn($guard);

            if ($tokens !== []) {
                $offenders[] = sprintf('%s:%d (guard mentions %s)', self::SUBJECT, $site, implode(', ', $tokens));
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "AN AUTHORIZATION REFUSAL ANSWERS 401 IN THE ADMIN API, WHICH LOGS THE MERCHANT OUT.\n\n"
            . "%s\n\n"
            . "The shipped Flutter admin app maps ANY 401 onto _handleUnauthorized(), which clears "
            . "preferences and forces a re-login (repo-B lib/network/network.dart:285-288). A "
            . "principal who resolved and was refused has a VALID session: answer 403 and leave it "
            . "intact. That is P4-F114 — `update-customer` answered 401 to merchant staff, so a "
            . "staff member who tapped save was signed out of the whole app with their draft lost, "
            . "rather than being told they lacked a right.\n\n"
            . "If the branch really is an authentication one (no principal resolved at all), it "
            . "should not be asking about a type, a permission or a store — move that question "
            . "below the 401 and answer it with a 403.",
            implode("\n", $offenders)
        ));
    }

    /**
     * AND EVERY ONE OF THEM POSITIVELY LOOKS LIKE AN AUTHENTICATION TEST.
     *
     * The assertion above is an absence, and an absence is satisfied by a guard that asks nothing
     * recognisable at all — `if (true)`, or a flag set somewhere else. Every legitimate 401 here
     * negates a variable that should have held a resolved principal or a parsed token, so that is
     * asserted positively. The two assertions together say "this is an authentication branch",
     * which neither says alone.
     */
    public function test_every_401_negates_something_that_should_have_resolved(): void
    {
        $lines = $this->codeLines();
        $unrecognised = [];

        foreach ($this->sites($lines) as $site) {
            $guard = (string) $this->guardFor($lines, $site);

            if (preg_match('/!\s*\$\w+/', $guard) !== 1) {
                $unrecognised[] = sprintf(
                    '%s:%d — %s',
                    self::SUBJECT,
                    $site,
                    trim((string) preg_replace('/\s+/', ' ', substr($guard, 0, 120)))
                );
            }
        }

        $this->assertSame([], $unrecognised, sprintf(
            "A 401 IS GUARDED BY SOMETHING THAT IS NOT A \"NOTHING RESOLVED\" TEST.\n\n%s\n\n"
            . "Every legitimate 401 in this file negates a variable that should have held a "
            . "resolved principal or a parsed token (`!\$user`, `!\$adminUser`, `!\$tokenId`). A "
            . "401 guarded by anything else is either an authorization refusal in disguise — which "
            . "force-logs-out the shipped app — or a condition no reader can classify.",
            implode("\n", $unrecognised)
        ));
    }
}
