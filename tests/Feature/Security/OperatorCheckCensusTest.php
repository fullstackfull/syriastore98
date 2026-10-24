<?php

namespace Tests\Feature\Security;

use App\Policies\UserPolicy;
use Tests\TestCase;

/**
 * P1-SEC-11 criterion 3 -- "the operator is defined once: normalised comparison, one helper".
 * =============================================================================================
 *
 * WHY NORMALISED, measured on MySQL 8.0.46 under the project collation (`utf8mb4_unicode_ci`):
 *
 *     SELECT 'Super Admin'  = 'super admin';   -- 1
 *     SELECT 'super admin ' = 'super admin';   -- 1
 *
 * Those are the SAME VALUE to the database. So a `users` row typed `'Super Admin'` is the operator to
 * every SQL query -- `User::where('type', 'super admin')` returns it -- while a PHP `===` against the
 * literal refuses it. The layers then disagree about who the operator is, in the direction that locks
 * the operator out of their own dashboard; `FinancePermission` records that lockout happening once
 * already for six unseeded finance names.
 *
 * HOW LIVE IT IS, AND THEREFORE WHAT THIS FILE DOES. Latent, not live: the only writers of
 * `users.type` are `'admin'`, `'customer'`, the seeder's exact `'super admin'`, and a role NAME at
 * `UserController:154`/`:305` -- and that last is gated by `RolePolicy::RESERVED_NAMES` through a
 * check that lowercases the needle, so no tenant can name a role into any case variant of operator
 * status. Reaching the lockout needs a hand-edited row.
 *
 * So the fix is one helper applied at the three sites the criterion names, plus THIS CENSUS, rather
 * than a conversion of every comparison in the application. The census is the part that matters over
 * time: it pins the number of non-normalised comparisons so the count cannot grow quietly, and it
 * fails if a NEW one is added.
 *
 * THE COUNT IS A CEILING THAT MAY ONLY FALL. If a conversion lands, these numbers go down and this
 * test fails -- which is the intended signal, not a regression. Lower the constants and say what was
 * converted.
 */
class OperatorCheckCensusTest extends TestCase
{
    /**
     * The non-normalised comparisons that existed when this census was written. Measured, not
     * estimated: 24 strict against the constant, 64 loose against the literal, 13 strict against the
     * literal. The first version of this census reported "24 and 24, half and half" -- wrong by a
     * factor of four, because it was keyed on the two spellings I happened to think of. Census-failure
     * mode 1 from the project skill, and the reason the patterns below are enumerated explicitly.
     */
    private const CEILING = 101;

    /** every spelling of an un-normalised operator comparison, as a PCRE over app/ source */
    private const PATTERNS = [
        'strict against the constant' => '/type\s*[!=]==\s*[^;,)]*PLATFORM_ADMIN/',
        'loose against the literal' => "/type\s*[!=]=[^=]\s*'super admin'/",
        'strict against the literal' => "/type\s*[!=]==\s*'super admin'/",
    ];

    /** @return list<string> every .php under app/, comment-stripped */
    private function sources(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // comments are stripped: this file and the helper's own docblock quote the forbidden
            // spellings to explain them, and a census a sentence of prose can inflate measures prose
            $stripped = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $stripped .= is_array($token) ? $token[1] : $token;
            }

            $files[$file->getPathname()] = $stripped;
        }

        return $files;
    }

    public function test_the_number_of_un_normalised_operator_comparisons_has_not_grown(): void
    {
        $sources = $this->sources();

        // reachability: if app/ stopped being readable, every count below would be 0 and pass vacuously
        $this->assertGreaterThan(200, count($sources), 'app/ was not scanned -- this census is vacuous');

        $total = 0;
        $perPattern = [];

        foreach (self::PATTERNS as $label => $pattern) {
            $n = 0;
            foreach ($sources as $source) {
                $n += preg_match_all($pattern, $source);
            }
            $perPattern[$label] = $n;
            $total += $n;
        }

        $this->assertLessThanOrEqual(
            self::CEILING,
            $total,
            "A NEW un-normalised operator comparison was added. Use UserPolicy::isOperator(). Counts: " . json_encode($perPattern)
        );

        // and the ceiling is not stale by a wide margin -- if a conversion landed, say so and lower it
        $this->assertGreaterThan(
            self::CEILING - 20,
            $total,
            'Un-normalised comparisons fell well below the ceiling: lower CEILING and record what was converted. Counts: ' . json_encode($perPattern)
        );
    }

    /**
     * The three sites the criterion names all go through the one helper -- AND carry no second
     * spelling of the decision beside it.
     *
     * THE FIRST VERSION OF THIS TEST ONLY ASKED WHETHER THE FILE MENTIONS THE HELPER, and that is
     * how a drift hid inside a file that passed it: `OrderController` used `UserPolicy::isOperator`
     * in fileExport while `mayUseOrderScreen` (:510) carried its own
     * `strtolower(trim(...)) === OrderPolicy::PLATFORM_ADMIN`. MEASURED: for `users.type` =
     * ' super admin' the helper answers FALSE (a leading space is not ignored by PAD SPACE, so the
     * engine refuses it too) and that line answered TRUE -- the order screen admitting a principal
     * the document guard refuses, which is the "two gates disagree about who the operator is"
     * defect this criterion exists to end. So the assertion now keys on the CONSTRUCT.
     */
    public function test_the_three_named_sites_use_the_shared_helper(): void
    {
        foreach ([
            'app/Policies/OrderPolicy.php' => 'OrderPolicy::isPlatformAdmin',
            'app/Http/Middleware/OrderDocumentAccessGuard.php' => 'the order-document guard',
            'app/Http/Controllers/OrderController.php' => 'OrderController::fileExport',
        ] as $relative => $what) {
            $source = (string) file_get_contents(base_path($relative));

            $this->assertStringContainsString(
                'UserPolicy::isOperator',
                $source,
                "{$what} does not use the shared operator helper"
            );

            $code = $this->withoutComments($source);

            $this->assertDoesNotMatchRegularExpression(
                '/(?:strtolower|strtoupper|trim|rtrim|ltrim)\s*\([^;]{0,160}?(?:PLATFORM_ADMIN|[\'"]super admin[\'"])/i',
                $code,
                "{$relative} still normalises the operator type beside the shared helper, which is how "
                . 'the gates drifted apart once already. Call UserPolicy::isOperator() instead.'
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\$\w+(?:->\w+)*->type\s*(?:===|==|!==|!=)\s*(?:self::PLATFORM_ADMIN|[\w\\\\]*OrderPolicy::PLATFORM_ADMIN|[\'"]super admin[\'"])/i',
                $code,
                "{$relative} compares users.type against the operator literal directly."
            );
        }
    }

    /** Comments stripped: prose naming the forbidden construct must not trip this census. */
    private function withoutComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @dataProvider operatorSpellingProvider */
    public function test_the_helper_accepts_every_spelling_the_engine_treats_as_equal(string $type): void
    {
        $this->assertTrue(UserPolicy::isOperator($type), "[{$type}] is the operator to MySQL but not to the helper");
    }

    public static function operatorSpellingProvider(): array
    {
        return [
            'canonical' => ['super admin'],
            'title case' => ['Super Admin'],
            'upper case' => ['SUPER ADMIN'],
            'mixed case' => ['sUpEr AdMiN'],
            'trailing space' => ['super admin '],
            'several trailing spaces' => ['super admin   '],
        ];
    }

    /** @dataProvider nonOperatorProvider */
    public function test_the_helper_refuses_everything_else(?string $type, string $why): void
    {
        $this->assertFalse(UserPolicy::isOperator($type), $why);
    }

    public static function nonOperatorProvider(): array
    {
        return [
            ['admin', 'a merchant is not the operator -- the distinction creatorId() turns on'],
            ['customer', 'a shopper'],
            ['Administrator', 'a different word'],
            ['superadmin', 'no space: a DIFFERENT string to the engine too, so not folded'],
            ['super  admin', 'two inner spaces: the engine does not fold inner whitespace either'],
            ['super admin x', 'a longer string'],
            [' super admin', 'a LEADING space is NOT ignored by PAD SPACE, so the engine refuses it too -- measured'],
            ["super admin\t", 'a trailing TAB is not a space; PAD SPACE does not ignore it'],
            ['', 'empty'],
            [null, 'no principal at all'],
        ];
    }

    /** null and a principal-shaped object both work, since the three sites pass different things */
    public function test_the_helper_takes_a_principal_or_a_type_or_null(): void
    {
        $this->assertFalse(UserPolicy::isOperator(null));

        $operator = new \stdClass();
        $operator->type = 'Super Admin';
        $this->assertTrue(UserPolicy::isOperator($operator), 'an object carrying ->type');

        $merchant = new \stdClass();
        $merchant->type = 'admin';
        $this->assertFalse(UserPolicy::isOperator($merchant));

        $typeless = new \stdClass();
        $this->assertFalse(UserPolicy::isOperator($typeless), 'an object with no ->type must not throw');
    }
}
