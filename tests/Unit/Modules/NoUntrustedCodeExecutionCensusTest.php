<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MARKET-004 / EX-010 — THE PLATFORM HAS NO WAY TO EXECUTE A STRING, AND NO UNDECLARED FILE INCLUDE.
 *
 * `MARKET-004` ("عدم تنفيذ كود خارجي غير موثوق داخل الإنتاج") and `EX-010` ("تشغيل كود Marketplace
 * غير موثوق في الإنتاج") are a PROHIBITION, which makes them the one requirement shape that cannot
 * be satisfied by adding a feature. The only way to deliver a prohibition is to measure that it
 * holds and to make it fail loudly when it stops holding.
 *
 * `DECISION-ID-REGISTER.md:463` records `EX-010` as holding "vacuously -- Phase 06 has not started".
 * THAT IS NOT WHAT THIS MEASURED. The platform has an upload-and-install door
 * (`ModuleController::install`, `AddonController::ThemeInstall`), `ALLOWED_ENTRY_EXTENSIONS` admits
 * `php` and `blade.php` deliberately, and until 2026-11-26 the uploaded package's service provider
 * was registered on the next request. So `EX-010` was not vacuous; it was live and unenforced. The
 * execution boundary is now `App\Support\Modules\ModuleTrust`. THIS file is the other half: the
 * platform must not grow a way to run code that does not come through a file on disk at all.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * TOKENISED, NOT GREPPED — AND THAT IS THE WHOLE RELIABILITY OF IT
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * CLAUDE.md §3 records EIGHT occasions when a census was satisfied, or broken, by PROSE about the
 * thing it measures — a docblock naming a method, a Blade comment containing the literal it looked
 * for. The usual remedy is `StripsSourceComments`, and it would not be enough here: this file's own
 * subject words are `eval` and `include`, which appear in ordinary strings
 * (`__('...include tax...')` is in two metrics classes) and in prose everywhere.
 *
 * `token_get_all()` removes the whole problem class. `T_EVAL`, `T_INCLUDE`, `T_REQUIRE_ONCE` are
 * what the PHP PARSER saw; a comment is `T_COMMENT` and a string is `T_CONSTANT_ENCAPSED_STRING`,
 * and neither can be mistaken for the other. Measured: a naive regex over `app/` reported 21
 * include/require sites and the tokeniser reports 12 — nine of the regex hits were words inside
 * strings.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE INCLUDE CENSUS ENUMERATES THE SAFE SET
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * An `include` of a path built from input is a code-execution door, and a list of dangerous
 * spellings would be short by one (CLAUDE.md §3). So the assertion is inverted: the complete set of
 * files in `app/` that include ANYTHING is declared below with a reason per file, and a new site in
 * a file not on the list fails — whatever it includes and however it spells it. Each entry is keyed
 * by FILE and COUNT rather than by line number, because CLAUDE.md records that an absolute line
 * citation goes stale on any insertion above it (four of five citations for one method were stale,
 * each by a different amount).
 */
class NoUntrustedCodeExecutionCensusTest extends TestCase
{
    /**
     * Every file in `app/` that includes or requires anything, with its site count and the reason.
     *
     * Measured 2026-11-26 by `token_get_all()` over the whole tree: 12 sites in 7 files.
     */
    private const PERMITTED_INCLUDE_SITES = [
        // The theme's own `default_data/settings.php`, guarded by `basename($theme) !== $theme`
        // immediately above the include — the containment check its own comment points at.
        'app/Support/Theme/ThemeFieldCatalog.php' => 1,

        // The same file, for the same theme, after the candidate has been checked against
        // `ThemeFieldCatalog::themes()` — "is not a theme this installation ships".
        'app/Services/Theme/ThemePublishingService.php' => 1,

        // The same file again, in `getThemeSetting()`; the path is `base_path('themes/' . $theme
        // . '/default_data/settings.php')` and falls back to `config('theme.active')`.
        'app/Helper/helper.php' => 1,

        // `resources/lang/<locale>/<file>.php`, from a `scandir()` of a `base_path()`-anchored
        // directory. First-party translation files, never an upload.
        'app/Http/Controllers/LanguageController.php' => 1,

        // `routes/channels.php`, a literal.
        'app/Providers/BroadcastServiceProvider.php' => 1,

        // `vendor/autoload.php`, a literal. This is the addon loader, and the file it requires is
        // Composer's — the PACKAGE code it then registers goes through `ModuleTrust` instead.
        'app/Providers/PackageServiceProvider.php' => 1,

        // Six `include_once` of literal filenames inside the payment library's own directory
        // (`payment.php`, `transaction.php`, ...). Relative, first-party, no variable.
        'app/Libraries/Easebuzz/Easebuzz.php' => 6,
    ];

    /**
     * Every `unserialize()` in `app/`, and why each is not a deserialization door.
     *
     * CLAUDE.md §1: `APP_KEY` is in git history, so `Crypt::encrypt()` is an encoding not a control
     * and a `decrypt()` over request bytes is a deserialization door. Both of these pass
     * `['allowed_classes' => false]`, which `test_every_unserialize_refuses_objects` asserts.
     */
    private const PERMITTED_UNSERIALIZE_SITES = [
        // P1-SEC-77. Looks at the bytes BEFORE calling unserialize, then calls it with
        // allowed_classes => false anyway. This is the container's encrypter.
        'app/Support/Security/ObjectFreeEncrypter.php' => 1,

        // The ip-api.com response, which the file's own comment and ObjectFreeEncrypter's both
        // name. `@unserialize(..., ['allowed_classes' => false])`.
        'app/Http/Controllers/SettingController.php' => 1,
    ];

    /** @return array{include: array<string,int>, eval: list<string>, unserialize: array<string,int>, create_function: list<string>, assert: list<string>} */
    private function census(string $relativeRoot): array
    {
        $root = dirname(__DIR__, 3) . '/' . $relativeRoot;
        $found = ['include' => [], 'eval' => [], 'unserialize' => [], 'create_function' => [], 'assert' => []];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = $relativeRoot . substr($file->getPathname(), strlen($root));
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));

            foreach ($tokens as $index => $token) {
                if (! is_array($token)) {
                    continue;
                }

                [$id, $text, $line] = $token;

                if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true)) {
                    $found['include'][$relative] = ($found['include'][$relative] ?? 0) + 1;

                    continue;
                }

                if ($id === T_EVAL) {
                    $found['eval'][] = $relative . ':' . $line;

                    continue;
                }

                if ($id !== T_STRING) {
                    continue;
                }

                /*
                 * A BARE FUNCTION CALL, NOT A METHOD OR A DECLARATION. `$x->assert(...)`,
                 * `Policy::assert(...)` and `function assert(...)` are different symbols that happen
                 * to share a name — `App\Services\StoredPathPolicy` has four `self::assert(...)`
                 * calls, which a name-only census would report as PHP's `assert()`. That is the
                 * GAP-229 shape and it was measured here before this check existed.
                 */
                $previous = null;

                for ($j = $index - 1; $j >= 0; $j--) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        continue;
                    }

                    $previous = $tokens[$j];
                    break;
                }

                $isBareCall = is_array($previous)
                    ? ! in_array($previous[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)
                    : ($previous !== '->' && $previous !== '::');

                if (! $isBareCall) {
                    continue;
                }

                $name = strtolower($text);

                if ($name === 'unserialize') {
                    $found['unserialize'][$relative] = ($found['unserialize'][$relative] ?? 0) + 1;
                } elseif ($name === 'create_function') {
                    $found['create_function'][] = $relative . ':' . $line;
                } elseif ($name === 'assert') {
                    $found['assert'][] = $relative . ':' . $line;
                }
            }
        }

        ksort($found['include']);
        ksort($found['unserialize']);

        return $found;
    }

    #[Test]
    public function test_no_first_party_code_can_execute_a_string(): void
    {
        $app = $this->census('app');

        $this->assertSame([], $app['eval'], 'MARKET-004: `eval()` executes a string as code. There is no legitimate use of it here.');
        $this->assertSame([], $app['create_function'], 'MARKET-004: `create_function()` is `eval()` with a nicer name, and is removed in PHP 8.');
        $this->assertSame(
            [],
            $app['assert'],
            'MARKET-004: PHP `assert()` with a string argument evaluates it. The four `self::assert()` calls in '
            . 'StoredPathPolicy are a different symbol and are excluded by the bare-call check, not by name.'
        );
    }

    /**
     * THE VENDORED PACKAGES TOO, because they ARE the marketplace content `EX-010` is about. 70
     * modules, measured: zero `eval`, zero `create_function`. If an upstream module update
     * introduces one, this fails and somebody reads it before it ships.
     */
    #[Test]
    public function test_no_vendored_module_can_execute_a_string(): void
    {
        $packages = $this->census('packages/workdo');

        $this->assertSame([], $packages['eval'], 'EX-010: a vendored module must not carry `eval()`.');
        $this->assertSame([], $packages['create_function'], 'EX-010: a vendored module must not carry `create_function()`.');
    }

    #[Test]
    public function test_every_file_include_in_the_application_is_a_declared_one(): void
    {
        $found = $this->census('app')['include'];
        $expected = self::PERMITTED_INCLUDE_SITES;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            "MARKET-004: `include`/`require` executes a PHP file. The set of files in app/ that do it is "
            . "declared in PERMITTED_INCLUDE_SITES with a reason each, so a NEW site fails here whatever "
            . "it includes. If the new one is legitimate, add it with its confinement; if the path can be "
            . "influenced by a request, it is an untrusted-code-execution door and MARKET-004 forbids it."
        );
    }

    #[Test]
    public function test_every_unserialize_in_the_application_is_a_declared_one(): void
    {
        $found = $this->census('app')['unserialize'];
        $expected = self::PERMITTED_UNSERIALIZE_SITES;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            "`unserialize()` over bytes a request can influence is an object-injection door, and APP_KEY "
            . "is in git history so an encrypted payload is forgeable. A new site must be declared and "
            . "must refuse objects."
        );
    }

    #[Test]
    public function test_every_unserialize_refuses_objects(): void
    {
        foreach (array_keys(self::PERMITTED_UNSERIALIZE_SITES) as $relative) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);

            $this->assertMatchesRegularExpression(
                "/unserialize\s*\([^;]*'allowed_classes'\s*=>\s*false/s",
                $source,
                $relative . ": every unserialize() must pass ['allowed_classes' => false]."
            );
        }
    }
}
