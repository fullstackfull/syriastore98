<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * NO FIRST-PARTY DECLARATION MAY PUT AN OPTIONAL PARAMETER BEFORE A REQUIRED ONE.
 * =================================================================================================
 *
 * `function f($a = 0, $b)` does not make `$a` optional. PHP treats BOTH as required, says so with
 * "Optional parameter $a declared before required parameter $b is implicitly treated as a required
 * parameter", and in PHP 9 the declaration is a FATAL ERROR. So the shape is a dormant upgrade
 * break, and one that reads as working code.
 *
 * HOW IT WAS FOUND, WHICH IS THE PART WORTH KEEPING. Nothing reported it for the lifetime of this
 * codebase: the notice fires at COMPILE time, so whichever test first autoloads the class absorbs
 * it, and the suite's deprecation markers had always landed on tests that load `App\Models\Cart`
 * incidentally. On 2026-11-24 `ClaudeMdScopedModelClaimTest` was added, which instantiates every
 * model CLAUDE.md's scoped-model table names, and the Unit suite's summary went from
 * `1 skipped, 6069 passed` to `1 deprecated, 1 skipped, 6070 passed` -- a NEW test attracting an
 * OLD defect. Chasing that one marker found four sites, three of which no deprecation had ever been
 * attributed to.
 *
 * WHY THE FIX IS SAFE, MEASURED RATHER THAN ARGUED. Removing the leading defaults is
 * arity-identical, because PHP was already ignoring them. Both shapes of each signature were put
 * through Reflection and called:
 *
 *   `($a = 0, $b = 0, $c = 0, $d, $e = '')` and `($a, $b, $c, $d, $e = '')`
 *       -> required=4, total=5, ArgumentCountError at three arguments, for both.
 *   `($a = null, $b, $c, $d, $e, $f, $g)`  and `($a, $b, $c, $d, $e, $f, $g)`
 *       -> required=7, total=7, and a call with EIGHT arguments succeeds for both, the extra one
 *          dropped -- which matters, because `GAP-211` records callers that pass eight.
 *
 * THE OTHER WAY OF SILENCING IT WAS REFUSED. Giving the trailing parameter a default instead
 * (`$store_id = 0`) also removes the notice, and would have let a three-argument call write a cart
 * row with `store_id` 0 -- a row belonging to no tenant. A tenant id is never defaulted here.
 *
 * SCOPE, AND WHAT IS DELIBERATELY OUT OF IT. The roots below are the code this repository owns.
 * `packages/workdo/` is vendored and is not edited, and it carries three sites of its own --
 * `FrequentlyProduct/src/app/Models/FrequentlyProduct.php` `addtocart_cookie()`,
 * `PreOrder/src/app/Http/Controllers/PreOrderController.php` `managePreOrderEmailLang()`, and one
 * more measured by the same scan -- which this test must NOT fail on and does not scan. Naming them
 * is the point: an exclusion nobody can see is how a count becomes a lie. `LynomiaChat` IS
 * first-party and IS scanned.
 *
 * TOKENS, NOT REFLECTION, so nothing is loaded and no side effect can fire: a reflection-based
 * sweep would have to autoload all 639 files in `app/` to ask the question.
 */
class NoOptionalBeforeRequiredParameterTest extends TestCase
{
    /**
     * The roots this repository owns. Every one measured at 0 sites on 2026-11-24, after the four
     * in `app/Models/` were fixed.
     */
    private const ROOTS = [
        'app',
        'routes',
        'database',
        'config',
        'bootstrap',
        'tests',
        'packages/workdo/LynomiaChat',
    ];

    /**
     * @return list<array{line: int, name: string}>
     */
    private function offendersIn(string $code): array
    {
        $tokens = token_get_all($code);
        $total = count($tokens);
        $found = [];

        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || ($token[0] !== T_FUNCTION && $token[0] !== T_FN)) {
                continue;
            }

            $line = $token[2];
            $name = '{closure}';

            // Walk to the opening parenthesis. `use function foo;` never reaches one.
            $j = $i + 1;
            while ($j < $total) {
                $ahead = $tokens[$j];

                if (is_array($ahead)) {
                    if ($ahead[0] === T_STRING && $name === '{closure}') {
                        $name = $ahead[1];
                    }

                    if (in_array($ahead[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING], true)) {
                        $j++;
                        continue;
                    }

                    break;
                }

                if ($ahead === '&') {
                    $j++;
                    continue;
                }

                break;
            }

            if ($j >= $total || $tokens[$j] !== '(') {
                continue;
            }

            $sawDefault = false;

            foreach ($this->parameters($tokens, $j, $total) as $parameter) {
                if ($parameter['default']) {
                    $sawDefault = true;
                    continue;
                }

                if ($parameter['variadic'] || ! $sawDefault) {
                    continue;
                }

                $found[] = ['line' => $line, 'name' => $name];
                break;
            }
        }

        return $found;
    }

    /**
     * Splits one parameter list at depth-1 commas. Depth is tracked over `(`, `[` and `{` so that a
     * comma inside a default value, an attribute or a `new Foo(1, 2)` default is not a separator; a
     * bare `=` token is a default, while `=>`, `==` and `>=` are their own tokens and cannot be
     * mistaken for one.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<array{default: bool, variadic: bool}>
     */
    private function parameters(array $tokens, int $open, int $total): array
    {
        $depth = 0;
        $parameters = [['default' => false, 'variadic' => false, 'empty' => true]];

        for ($k = $open; $k < $total; $k++) {
            $token = $tokens[$k];
            $last = count($parameters) - 1;

            if (! is_array($token)) {
                if (in_array($token, ['(', '[', '{'], true)) {
                    $depth++;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($token, [')', ']', '}'], true)) {
                    $depth--;

                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && $token === ',') {
                    $parameters[] = ['default' => false, 'variadic' => false, 'empty' => true];

                    continue;
                } elseif ($depth === 1 && $token === '=') {
                    $parameters[$last]['default'] = true;
                }

                if ($depth >= 1) {
                    $parameters[$last]['empty'] = false;
                }

                continue;
            }

            if ($depth === 1 && $token[0] === T_ELLIPSIS) {
                $parameters[$last]['variadic'] = true;
            }

            if ($depth >= 1 && $token[0] !== T_WHITESPACE) {
                $parameters[$last]['empty'] = false;
            }
        }

        return array_values(array_filter($parameters, fn (array $p): bool => ! $p['empty']));
    }

    /**
     * THE PREMISE, AND THIS TEST IS WORTHLESS WITHOUT IT. A scanner that silently matches nothing
     * reports a clean repository, so the shapes it must catch and the shapes it must not are driven
     * through it directly. The negative cases are the ones that would make it cry wolf: a variadic
     * after a default is legal, and so is a comma or an `=>` inside a default value.
     */
    public function test_the_scanner_catches_the_shape_and_only_that_shape(): void
    {
        $offends = [
            'the simplest form' => 'function f($a = 0, $b) {}',
            'a method' => 'class C { public function f($a = 1, $b) {} }',
            'a static method' => 'class C { public static function f($a = null, $b, $c) {} }',
            'a closure' => '$f = function ($a = 0, $b) {};',
            'an arrow function' => '$f = fn ($a = 0, $b) => $b;',
            'typed and nullable' => 'function f(?int $a = null, string $b) {}',
            'a default holding a comma' => 'function f($a = [1, 2], $b) {}',
            'a by-reference parameter' => 'function f(&$a = null, $b) {}',
            'the second of three' => 'function f($a, $b = 2, $c) {}',
        ];

        foreach ($offends as $what => $code) {
            $this->assertNotEmpty($this->offendersIn('<?php ' . $code),
                "The scanner does not catch {$what}, so a clean report means nothing: {$code}");
        }

        $allowed = [
            'all required' => 'function f($a, $b) {}',
            'all optional' => 'function f($a = 1, $b = 2) {}',
            'defaults last' => 'function f($a, $b = 2) {}',
            'a variadic after a default' => 'function f($a = 1, ...$rest) {}',
            'a variadic after a required' => 'function f($a, ...$rest) {}',
            'no parameters' => 'function f() {}',
            'an array default with arrows' => "function f(\$a, \$b = ['x' => 1, 'y' => 2]) {}",
            'a new in a default' => 'function f($a, $b = new \stdClass()) {}',
            'a constructor promoting properties' => 'class C { public function __construct(private int $a, private int $b = 2) {} }',
            'a function imported by name' => 'use function strlen; function f($a, $b) {}',
            'a call with a default-looking argument' => 'function f($a, $b) { g($c = 1, $d); }',
            'an attribute carrying a comma' => 'function f(#[Foo(1, 2)] $a, $b = 1) {}',

            /*
             * THIS ONE IS NOT HYPOTHETICAL. The first draft of this scanner declared `$sawDefault`
             * outside the per-declaration loop, so one legitimate default anywhere in a file made
             * every later required parameter in that file an offender. It reported 14 sites in
             * `app/Helper/helper.php` alone, and every single-declaration case above still passed,
             * because none of them has a SECOND declaration for the state to leak into.
             */
            'a clean declaration following one with defaults' => 'function a($x = 1) {} function b($y, $z) {}',
            'a clean method following one with defaults' => 'class C { function a($x = 1) {} function b($y, $z) {} }',
        ];

        foreach ($allowed as $what => $code) {
            $this->assertSame([], $this->offendersIn('<?php ' . $code),
                "The scanner falsely flags {$what}, so it will fail on correct code: {$code}");
        }
    }

    public function test_no_first_party_declaration_puts_an_optional_parameter_before_a_required_one(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $scanned = 0;

        foreach (self::ROOTS as $relative) {
            $directory = $root . '/' . $relative;

            $this->assertDirectoryExists($directory,
                "A scanned root no longer exists: {$relative}. Re-point this test rather than "
                . 'dropping the root, or the scan quietly covers less than it says.');

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $scanned++;

                foreach ($this->offendersIn((string) file_get_contents($file->getPathname())) as $offender) {
                    $offenders[] = sprintf(
                        '%s:%d  %s()',
                        substr($file->getPathname(), strlen($root) + 1),
                        $offender['line'],
                        $offender['name']
                    );
                }
            }
        }

        $this->assertGreaterThan(600, $scanned,
            'Far fewer files were scanned than this repository has, so the walk is broken and a '
            . 'clean result is meaningless.');

        sort($offenders);

        $this->assertSame([], $offenders,
            "A declaration puts an optional parameter before a required one. PHP already treats "
            . "every parameter up to the last required one as REQUIRED -- the defaults are a lie to "
            . "the reader -- and PHP 9 makes the declaration a fatal error. Remove the leading "
            . "default(s); that is arity-identical. Do NOT instead give the trailing parameter a "
            . "default, which changes what a short call does.\n  " . implode("\n  ", $offenders));
    }
}
