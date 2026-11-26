<?php

namespace Tests\Unit\Governance;

use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * THE CONVENTION THAT A CITATION NAMES A METHOD WAS NOT ASSERTED, AND IT WAS ALREADY BROKEN.
 * =================================================================================================
 *
 * CLAUDE.md §3 makes this the project's rule for pointing at code:
 *
 *     BETTER, AND NOW THE CONVENTION: cite the METHOD, not the line. It cannot drift and a grep
 *     finds it.
 *
 * The whole value of that rule is the second half. A line number goes stale silently; a method name
 * is supposed to be checkable by anyone with a grep. **But a method name that was never right is
 * not checkable either -- it is a line number that never pointed anywhere, dressed as a fact.** A
 * reader greps, finds nothing, and cannot tell whether the method moved, was renamed, was deleted,
 * or never existed. That is strictly worse than a stale line, because a stale line at least lands
 * in the right file.
 *
 * MEASURED 2026-11-26, the day this was written: **22 citations -- in first-party source, in test
 * and route and migration comments, and in the traceability matrix -- named a method that does not
 * exist.** Eleven were plain errors, corrected here to the real method: a dispatcher cited as
 * `processPayment` when the method is `process`, a tile cited by what it measures rather than by
 * what it is called, a vendored module's controller cited as the host's. Eight were sentences whose
 * subject really is an absent method -- a withdrawn route's action, a private helper P1-INV-09
 * deleted, a design deliberately rejected -- and FOUR of those had already been written down by
 * their own authors as claims about absent code. One migration docblock said in as many words that
 * "a docblock making a claim about code that does not exist is the same defect as a comment that
 * contradicts it", and then made the claim in the citation shape, which is indistinguishable from a
 * live citation; nothing could tell the two apart. The remaining three named a call the class
 * MAKES rather than a method it HAS. Thirteen of the 22 named a controller.
 *
 * WHAT THIS FILE ASSERTS. Every `Class::method()` written anywhere in the corpus below, where the
 * class resolves to a first-party class for which `method_exists()` is authoritative, names a
 * method that exists. Nothing more: it does not check line citations, does not check that the
 * citation is the RIGHT method for the sentence (no test can), and does not check vendored or
 * framework classes.
 *
 * ON COMMENTS, AGAINST THE USUAL RULE. CLAUDE.md warns eight times over that a census reading
 * comments can be satisfied by prose about the thing it measures, and prescribes
 * `StripsSourceComments`. **This census deliberately does NOT strip comments, because comments are
 * where citations live** -- stripping them would leave almost nothing to check. The trap is
 * therefore real here and is handled head-on instead: this file is itself inside the corpus
 * (asserted below), so a bad citation in this very docblock fails this very test, and the one case
 * that needs to demonstrate a failing citation BUILDS IT AT RUN TIME from fragments rather than
 * writing it down (also asserted below, in both directions).
 *
 * WHY THE SCOPE IS WHAT IT IS -- all three decisions were measured, not assumed
 * =================================================================================================
 *
 * **1. A CLASS IS IN SCOPE ONLY IF IT CANNOT ANSWER AN UNKNOWN METHOD.** `method_exists()` is not
 * the same question as "does this call work". An Eloquent model answers `find`, `where`,
 * `withoutGlobalScopes` and `truncate` through `__callStatic`, declaring none of them, so a census
 * that trusted `method_exists()` over all of `app/` reported **1055 bad citations out of 3168** --
 * almost every one a model call that is perfectly real. That is why models are out.
 *
 * **BUT "NOT A MODEL" IS THE WRONG RULE AND IS NOT WHAT THIS USES.** Laravel's own
 * `Illuminate\Routing\Controller` declares `__call` TOO -- and it only throws. Measured: excluding
 * every class with a magic handler drops 302 of the 698 first-party declarations, controllers
 * included, and takes thirteen of the 22 real defects out of the census with them. So the test is structural and behavioural, not a category: a
 * class leaves scope only if its `__call`/`__callStatic` body can RETURN or YIELD a value, which
 * is the only way an unknown call can succeed. Measured in both directions below. A model that
 * stops extending Eloquent, or a service that gains a forwarding handler, changes scope by itself.
 *
 * **2. A SHORT NAME IS RESOLVED, NOT GUESSED.** `Request::all()` means `Illuminate\Http\Request`,
 * but this repository also declares `App\Xendit\ApiOperations\Request`, so a naive short-name map
 * blamed the wrong class nineteen times over. Resolution order is: a fully-qualified name as
 * written (or a unique namespace-boundary suffix of one), then the citing file's own `use` imports,
 * then its own namespace, then a short name unique within `app/`.
 *
 * **3. AND NOTHING IS REPORTED WHILE ANY CLASS THAT COULD BE MEANT SATISFIES IT.** Enumerating the
 * safe set rather than the dangerous one, per CLAUDE.md: before a citation is reported, every other
 * class of that short name the project could mean -- the ones `app/` declares, the ones the corpus
 * imports, and every `<ShortName>.php` the autoloader can reach under `vendor/`, `packages/` and
 * `app/` -- is asked whether IT has the method. `yajra's Request::searchableColumnIndex()` is a
 * correct citation of a vendored class and is suppressed by that step, not by a hand-written
 * exception list. The lookup is built only when something is about to be reported, so the green
 * steady state never pays for walking `vendor/`.
 *
 * WHAT IS DELIBERATELY NOT IN THE CORPUS, and why it is not an oversight
 * =================================================================================================
 *
 * **DATED REPORTS UNDER `docs/development/<phase>/` AND `docs/security/`.** Measured: extending the
 * corpus to all of `docs/` recursively adds **21** citations of absent methods, and every one is a
 * dated record of code that WAS there -- five of a private `isInsideUploadsRoot()` that
 * `ProductController` once carried and P1-INV-09 deleted, and seven of a customer-creation twin the
 * owner authorised the removal of on 2026-11-07, under a heading that says "the method is deleted".
 * Re-wording a dated report to satisfy a census would falsify the record. Living documents are in:
 * the top-level `docs/development/*.md`, which includes the traceability matrix and the gap
 * register.
 *
 * AND THE SENTENCE ABOVE IS WHY THE SELF-COVERAGE CASE IS NOT DECORATION. On its first run, this
 * file FAILED ON ITS OWN DOCBLOCK: the paragraph above had named that deleted helper in the
 * citation shape while explaining that it no longer exists, and the census read its own prose and
 * reported it (measured 2026-11-26, one finding, at the line this paragraph replaced). That is
 * CLAUDE.md's comment-census trap arriving on schedule, and it was caught in the first second of
 * the first run rather than by a reader -- because this file is in its own corpus and because the
 * demonstration case below writes no citation down.
 *
 * **CITATIONS CARRYING ARGUMENTS.** `Class::method($a, $b)` is not checked. Measured: it roughly
 * doubles the verified set but its first finding was a REGEX LITERAL in another census
 * (`'/BillingGeography::resolve(CityId|Billing|Ids)\s*\(/'`), which is not a citation at all. The
 * convention's shape is the empty-parenthesis form, and that is what this asserts. Two real leads
 * it surfaced are recorded in the handoff rather than silently swept in here.
 *
 * HOW TO SATISFY THIS TEST WHEN THE METHOD REALLY IS ABSENT. Do not delete the sentence, and do not
 * invent a plausible name -- a wrong citation that reads as verified is worse than an obvious gap.
 * Write the absence AS an absence, outside the citation shape: name the class and the method
 * separately, and say it does not exist. Eight sites in the corpus now read that way.
 */
class CitedMethodsExistTest extends TestCase
{
    /**
     * The citation shape this project's convention uses. Anchored on a non-identifier, non-`\`,
     * non-`$`, non-`>` character so `$this->Foo::x()` and the tail of an already-matched
     * fully-qualified name cannot start a second match. The method must begin lower-case, which
     * keeps `Foo::CONST`, `Foo::class` and `self::`/`static::`/`parent::` out.
     */
    private const CITATION = '/(?<![\\\\\w$>-])((?:[A-Z][A-Za-z0-9_]*\\\\)*[A-Z][A-Za-z0-9_]*)::([a-z_][A-Za-z0-9_]*)\(\)/';

    /** First-party source whose comments and strings carry citations. */
    private const CORPUS_DIRS = ['app', 'tests', 'routes', 'database', 'config'];

    /** Living project documents. Dated phase reports are excluded -- see the class docblock. */
    private const CORPUS_DOCS = 'docs/development/*.md';

    /**
     * FLOORS, NOT EXACT COUNTS, so that honest work does not redden the gate -- but low enough
     * that a corpus or scope collapse cannot hide behind them. Measured at the first green run, by
     * raising each floor until the premise printed its own figure: **2,271 corpus files, 549 classes
     * in scope, 3,755 citations verified.**
     */
    private const MIN_CORPUS_FILES = 1800;

    private const MIN_IN_SCOPE_CLASSES = 450;

    private const MIN_VERIFIED_CITATIONS = 3000;

    /**
     * THE SILENT-ROT DIRECTION, AND THE ONLY ONE A FINDINGS-COUNT CANNOT SEE.
     *
     * A class that leaves scope stops being checked, and the census goes green BECAUSE it stopped
     * looking. Every class below carries at least one citation this gate verifies today, and the
     * eight that follow the first two are exactly the classes whose citations were wrong on
     * 2026-11-26; if one of them gains a forwarding `__call`, moves, or is renamed, that is a
     * decision someone must take deliberately rather than discover later.
     *
     * @var list<class-string>
     */
    private const MUST_BE_IN_SCOPE = [
        'App\Http\Controllers\ProductController',
        'App\Services\ProductStockService',
        'App\Services\PaymentService',
        'App\Services\OrderReplacement',
        'App\Services\FavouritePages',
        'App\Services\Payments\Reconciliation\PlanPaymentReconciler',
        'App\Support\Metrics\MetricResult',
        'App\Http\Controllers\ReportController',
        'App\Http\Controllers\SettingController',
        'App\Http\Controllers\MerchantAnalyticsController',
        'App\Http\Controllers\ShopifyProductController',
        'App\Http\Controllers\PaytmPaymentController',
        'App\Http\Controllers\AccountProfileController',
        'App\Http\Controllers\MyFatoorahController',
    ];

    /** @var array<string, true>|null */
    private static ?array $declared = null;

    /** @var array<string, true>|null */
    private static ?array $scope = null;

    /** @var array<string, list<string>>|null */
    private static ?array $universe = null;

    // ---------------------------------------------------------------- scope

    /**
     * Can an unknown method call on this class SUCCEED? Only if a magic handler can hand a value
     * back, so the test is whether its body can `return` or `yield`. Eloquent's and `Macroable`'s
     * both can; `Illuminate\Routing\Controller`'s can only throw, which is why `method_exists()`
     * is authoritative for a controller and is not for a model.
     *
     * Anything that cannot be read by reflection is treated as forwarding -- the conservative
     * direction, because it drops a class out of scope rather than blaming it wrongly.
     */
    private function forwardsUnknownCalls(string $class): bool
    {
        foreach (['__call', '__callStatic'] as $magic) {
            if (! method_exists($class, $magic)) {
                continue;
            }

            try {
                $method = new ReflectionMethod($class, $magic);
            } catch (\Throwable $e) {
                return true;
            }

            $file = $method->getFileName();

            if ($file === false || ! is_file($file)) {
                return true;
            }

            $lines = file($file);

            if ($lines === false) {
                return true;
            }

            $body = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            foreach (token_get_all('<?php ' . $body) as $token) {
                if (is_array($token) && in_array($token[0], [T_RETURN, T_YIELD, T_YIELD_FROM], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isResolvable(string $class): bool
    {
        try {
            return class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Every class, trait, interface and enum declared under `app/`, read from source rather than
     * from the class map so a file that cannot be autoloaded is still counted as declared.
     *
     * @return array<string, true>
     */
    private function declaredFirstParty(): array
    {
        if (self::$declared !== null) {
            return self::$declared;
        }

        $out = [];

        foreach ($this->phpFilesIn(base_path('app')) as $path) {
            $source = (string) file_get_contents($path);

            if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
                continue;
            }

            $pattern = '/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|trait|interface|enum)\s+(\w+)/m';

            if (preg_match_all($pattern, $source, $names) < 1) {
                continue;
            }

            foreach ($names[1] as $name) {
                $out[trim($namespace[1]) . '\\' . $name] = true;
            }
        }

        return self::$declared = $out;
    }

    /** @return array<string, true> */
    private function inScope(): array
    {
        if (self::$scope !== null) {
            return self::$scope;
        }

        $out = [];

        foreach (array_keys($this->declaredFirstParty()) as $class) {
            if (! $this->isResolvable($class) || $this->forwardsUnknownCalls($class)) {
                continue;
            }

            $out[$class] = true;
        }

        return self::$scope = $out;
    }

    // ---------------------------------------------------------------- corpus

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $out = [];
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[] = $file->getPathname();
            }
        }

        sort($out);

        return $out;
    }

    /** @return list<string> */
    private function corpusFiles(): array
    {
        $out = [];

        foreach (self::CORPUS_DIRS as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path) {
                $out[] = $path;
            }
        }

        foreach ((array) glob(base_path(self::CORPUS_DOCS)) as $path) {
            $out[] = $path;
        }

        return array_values(array_unique($out));
    }

    /**
     * Every `<ShortName>.php` the autoloader can reach, read ONCE and only when a citation is
     * about to be reported. In the green steady state this never runs.
     *
     * @return list<class-string|string>
     */
    private function universeClassesNamed(string $short): array
    {
        if (self::$universe === null) {
            self::$universe = [];

            foreach (['vendor', 'packages', 'app'] as $dir) {
                if (! is_dir(base_path($dir))) {
                    continue;
                }

                $walk = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
                );

                foreach ($walk as $file) {
                    if (! $file->isFile()) {
                        continue;
                    }

                    $name = $file->getFilename();

                    if (str_ends_with($name, '.php')) {
                        self::$universe[substr($name, 0, -4)][] = $file->getPathname();
                    }
                }
            }
        }

        $out = [];

        foreach (self::$universe[$short] ?? [] as $path) {
            $source = @file_get_contents($path);

            if ($source === false) {
                continue;
            }

            $out[] = preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1
                ? trim($m[1]) . '\\' . $short
                : $short;
        }

        return array_values(array_unique($out));
    }

    /**
     * `use` imports of one file, keyed by the name the file refers to the class as (its alias when
     * it has one). Also used across the corpus to learn what short names this project really uses
     * for which classes.
     *
     * @return array<string, string>
     */
    private function importsIn(string $source): array
    {
        $out = [];
        $pattern = '/^use\s+((?:\w+\\\\)*\w+)(?:\s+as\s+(\w+))?\s*;/mi';

        if (preg_match_all($pattern, $source, $uses, PREG_SET_ORDER) < 1) {
            return $out;
        }

        foreach ($uses as $use) {
            $class = $use[1];
            $cut = strrpos($class, '\\');
            $tail = $cut === false ? $class : substr($class, $cut + 1);
            $out[($use[2] ?? '') !== '' ? $use[2] : $tail] = $class;
        }

        return $out;
    }

    /** @return array<string, list<string>> */
    private function corpusShortNameMeanings(): array
    {
        $out = [];

        foreach ($this->corpusFiles() as $path) {
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            foreach ($this->importsIn((string) file_get_contents($path)) as $short => $class) {
                $out[$short][$class] = true;
            }
        }

        foreach (array_keys($this->declaredFirstParty()) as $class) {
            $out[substr($class, strrpos($class, '\\') + 1)][$class] = true;
        }

        return array_map(fn (array $set): array => array_keys($set), $out);
    }

    // ---------------------------------------------------------------- the census itself

    /**
     * The one routine every assertion goes through, so a synthetic text and a real file are read
     * by exactly the same code.
     *
     * @param  array<string, string>  $imports  the citing file's own `use` map
     * @param  array<string, list<string>>  $meanings  short name => classes this project uses it for
     * @return list<array{citation: string, class: class-string, method: string, line: int}>
     */
    private function unsatisfiedCitations(string $text, array $imports, ?string $namespace, array $meanings): array
    {
        $declared = $this->declaredFirstParty();
        $scope = $this->inScope();
        $out = [];

        foreach (explode("\n", $text) as $index => $line) {
            if (preg_match_all(self::CITATION, $line, $hits, PREG_SET_ORDER) < 1) {
                continue;
            }

            foreach ($hits as [$citation, $cited, $method]) {
                $cut = strrpos($cited, '\\');
                $short = $cut === false ? $cited : substr($cited, $cut + 1);
                $class = null;

                if ($cut !== false) {
                    // Fully qualified as written, or a unique namespace-boundary suffix of one.
                    $written = ltrim($cited, '\\');

                    if (isset($declared[$written])) {
                        $class = $written;
                    } else {
                        $suffix = array_values(array_filter(
                            array_keys($declared),
                            fn (string $candidate): bool => str_ends_with($candidate, '\\' . $written)
                        ));

                        if (count($suffix) === 1) {
                            $class = $suffix[0];
                        }
                    }
                } elseif (isset($imports[$short])) {
                    $class = $imports[$short];
                } elseif ($namespace !== null && isset($declared[$namespace . '\\' . $short])) {
                    $class = $namespace . '\\' . $short;
                } else {
                    $sameName = array_values(array_filter(
                        array_keys($declared),
                        fn (string $candidate): bool => substr($candidate, strrpos($candidate, '\\') + 1) === $short
                    ));

                    if (count($sameName) === 1) {
                        $class = $sameName[0];
                    }
                }

                // Not a first-party class this gate can speak for.
                if ($class === null || ! isset($scope[$class]) || ! $this->isResolvable($class)) {
                    continue;
                }

                if (method_exists($class, $method)) {
                    continue;
                }

                // ENUMERATE THE SAFE SET: anything else that could be meant and DOES have it.
                $alternatives = array_merge(
                    $meanings[$short] ?? [],
                    $this->universeClassesNamed($short)
                );

                foreach ($alternatives as $other) {
                    if ($other !== $class && $this->isResolvable($other) && method_exists($other, $method)) {
                        continue 2;
                    }
                }

                $out[] = [
                    'citation' => $citation,
                    'class' => $class,
                    'method' => $method,
                    'line' => $index + 1,
                ];
            }
        }

        return $out;
    }

    /**
     * @return array{findings: list<string>, verified: int, files: int}
     */
    private function census(): array
    {
        $meanings = $this->corpusShortNameMeanings();
        $declared = $this->declaredFirstParty();
        $scope = $this->inScope();
        $findings = [];
        $verified = 0;
        $files = 0;

        foreach ($this->corpusFiles() as $path) {
            $files++;
            $source = (string) file_get_contents($path);
            $isPhp = str_ends_with($path, '.php');
            $imports = $isPhp ? $this->importsIn($source) : [];
            $namespace = null;

            if ($isPhp && preg_match('/^namespace\s+([^;]+);/m', $source, $m) === 1) {
                $namespace = trim($m[1]);
            }

            foreach ($this->unsatisfiedCitations($source, $imports, $namespace, $meanings) as $bad) {
                $findings[] = sprintf(
                    '%s:%d  %s  — %s has no %s()',
                    str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path),
                    $bad['line'],
                    $bad['citation'],
                    $bad['class'],
                    $bad['method']
                );
            }

            // Counted separately and cheaply: a citation that resolved into scope and was satisfied.
            if (preg_match_all(self::CITATION, $source, $hits, PREG_SET_ORDER) > 0) {
                foreach ($hits as [, $cited, $method]) {
                    $cut = strrpos($cited, '\\');
                    $short = $cut === false ? $cited : substr($cited, $cut + 1);
                    $class = $imports[$short] ?? null;

                    if ($class === null) {
                        $sameName = array_values(array_filter(
                            array_keys($declared),
                            fn (string $candidate): bool => substr($candidate, strrpos($candidate, '\\') + 1) === $short
                        ));

                        $class = count($sameName) === 1 ? $sameName[0] : null;
                    }

                    if ($class !== null && isset($scope[$class]) && method_exists($class, $method)) {
                        $verified++;
                    }
                }
            }
        }

        return ['findings' => $findings, 'verified' => $verified, 'files' => $files];
    }

    // ---------------------------------------------------------------- premises

    /** Guards every assertion below against a corpus that silently did not load. */
    #[Test]
    public function test_the_premise_the_corpus_and_the_scope_were_actually_read(): void
    {
        $census = $this->census();

        $this->assertGreaterThanOrEqual(
            self::MIN_CORPUS_FILES,
            $census['files'],
            'PREMISE: only ' . $census['files'] . ' corpus files were read, below the floor of '
            . self::MIN_CORPUS_FILES . '. The census below would pass against almost nothing. Either a '
            . 'directory in CORPUS_DIRS has moved, or the doc glob matches nothing.'
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_IN_SCOPE_CLASSES,
            count($this->inScope()),
            'PREMISE: only ' . count($this->inScope()) . ' first-party classes are in scope, below the '
            . 'floor of ' . self::MIN_IN_SCOPE_CLASSES . '. A scope collapse makes this gate green by '
            . 'making it blind -- the likeliest cause is that the forwarding rule now rejects a whole '
            . 'family of classes (controllers were lost exactly this way once).'
        );

        $this->assertGreaterThanOrEqual(
            self::MIN_VERIFIED_CITATIONS,
            $census['verified'],
            'PREMISE: only ' . $census['verified'] . ' citations were verified against a real method, '
            . 'below the floor of ' . self::MIN_VERIFIED_CITATIONS . '. The census is no longer reading '
            . 'the citations it is supposed to police.'
        );
    }

    /**
     * THE SCOPE RULE, IN BOTH DIRECTIONS. A model must be out and a controller must be in -- and
     * the controller is the half that matters, because it HAS `__call` and must still be checked.
     */
    #[Test]
    public function test_the_premise_the_scope_rule_tells_a_forwarder_from_a_refuser(): void
    {
        $scope = $this->inScope();

        $this->assertTrue(
            method_exists('App\Models\Product', '__callStatic'),
            'PREMISE: the model no longer has __callStatic, so this case is no longer measuring what '
            . 'it was written to measure.'
        );
        $this->assertTrue(
            $this->forwardsUnknownCalls('App\Models\Product'),
            'An Eloquent model forwards unknown static calls to the query builder, so method_exists() '
            . 'is NOT authoritative for it and it must be out of scope.'
        );
        $this->assertArrayNotHasKey('App\Models\Product', $scope, 'A model must not be in scope.');

        $this->assertTrue(
            method_exists('App\Http\Controllers\ProductController', '__call'),
            'PREMISE: the framework controller no longer declares __call, so this case no longer '
            . 'proves that the rule is behavioural rather than "has no magic method".'
        );
        $this->assertFalse(
            $this->forwardsUnknownCalls('App\Http\Controllers\ProductController'),
            'Illuminate\Routing\Controller::__call can only throw, so a controller REFUSES an unknown '
            . 'call and method_exists() is authoritative for it. Excluding controllers would drop the '
            . 'family that carried eight of the nineteen defects this gate was written for.'
        );
        $this->assertArrayHasKey(
            'App\Http\Controllers\ProductController',
            $scope,
            'A controller must be in scope.'
        );
    }

    /**
     * THE DIRECTION A FINDINGS COUNT CANNOT SEE: a class quietly leaving scope takes its citations
     * out of the census with it, and everything goes green.
     */
    #[Test]
    public function test_the_premise_the_classes_todays_citations_name_are_still_in_scope(): void
    {
        $scope = $this->inScope();

        foreach (self::MUST_BE_IN_SCOPE as $class) {
            $this->assertTrue(
                $this->isResolvable($class),
                $class . ' no longer resolves, so every citation of it has stopped being checked. If '
                . 'it moved, update MUST_BE_IN_SCOPE and the citations together.'
            );
            $this->assertArrayHasKey(
                $class,
                $scope,
                $class . ' has left the census scope, so its citations are no longer checked and this '
                . 'gate is green because it stopped looking. The usual cause is a new forwarding '
                . '__call/__callStatic on it or on something it extends.'
            );
        }
    }

    /**
     * NO BLIND SPOT FOR THIS FILE. A census that exempted itself could carry the very defect it
     * polices, so this file is an ordinary member of the corpus -- which is also why the case below
     * assembles its example citation at run time instead of writing one down.
     */
    #[Test]
    public function test_the_premise_this_file_is_itself_inside_the_corpus(): void
    {
        $this->assertContains(
            (string) realpath(__FILE__),
            array_map(fn (string $path): string => (string) realpath($path), $this->corpusFiles()),
            'This test file is not in its own corpus, so a bad citation in its own prose would go '
            . 'unchecked. Do not exempt it; write absence claims outside the citation shape instead.'
        );
    }

    /**
     * THE DETECTOR ITSELF, BOTH WAYS, ON TEXT THAT IS NOT IN THIS FILE.
     *
     * CLAUDE.md records eight occasions on which a census was satisfied -- or broken -- by prose
     * about the thing it measures. This census reads comments on purpose, so the only safe way to
     * demonstrate a failing citation is to never write one: both strings below are assembled from
     * fragments, so neither exists as a citation in this file's source and neither can be found by
     * the census that scans it.
     */
    #[Test]
    public function test_the_detector_fires_on_an_absent_method_and_not_on_a_present_one(): void
    {
        $meanings = $this->corpusShortNameMeanings();
        $class = 'App\Support\Metrics\MetricResult';

        $absent = 'the figure comes from `' . 'MetricResult' . '::' . 'noSuchMethodAnywhere()' . '`.';
        $found = $this->unsatisfiedCitations($absent, [], null, $meanings);

        $this->assertCount(
            1,
            $found,
            'The detector did not report a citation of a method that does not exist. Everything else '
            . 'in this file passes vacuously if this does.'
        );
        $this->assertSame($class, $found[0]['class']);
        $this->assertSame('noSuchMethodAnywhere', $found[0]['method']);

        $present = 'the figure comes from `' . 'MetricResult' . '::' . 'metric()' . '`.';

        $this->assertSame(
            [],
            $this->unsatisfiedCitations($present, [], null, $meanings),
            'The detector reported a citation of a method that DOES exist, so it would redden on '
            . 'correct prose and the gate would be weakened to get it green again.'
        );

        // AND IT MUST NOT BLAME A CLASS IT ONLY GUESSED AT. `Request` names a first-party class
        // here as well as the framework's, and the framework's is the one every such citation
        // means; a census that resolved the short name and stopped reported nineteen of these.
        $foreign = 'read with `' . 'Request' . '::' . 'header()' . '`.';

        $this->assertSame(
            [],
            $this->unsatisfiedCitations($foreign, [], null, $meanings),
            'A citation satisfied by another class of the same short name was reported. The '
            . 'suppression step has stopped consulting the classes this project really uses.'
        );
    }

    // ---------------------------------------------------------------- the defect

    /**
     * THE DEFECT ITSELF: a citation that names a method nothing defines.
     */
    #[Test]
    public function test_every_cited_method_exists_on_the_class_the_citation_names(): void
    {
        $findings = $this->census()['findings'];

        $this->assertSame(
            [],
            $findings,
            count($findings) . " citation(s) name a method that does not exist on the class they name.\n"
            . "A method citation is only worth writing because a grep finds it; one that names nothing is\n"
            . "a line number that never pointed anywhere (CLAUDE.md §3).\n\n"
            . implode("\n", $findings)
            . "\n\nFIX IT ONE OF THESE TWO WAYS, never by relaxing this test:\n"
            . "  - The method was renamed, moved or mis-remembered: cite the real one. Grep for the\n"
            . "    BEHAVIOUR the sentence describes, not for the name -- and do not invent a plausible\n"
            . "    name, because a wrong citation reads as verified.\n"
            . "  - The method genuinely does not exist and the sentence is ABOUT its absence (a withdrawn\n"
            . "    route, a deleted helper, a design that was rejected): keep the sentence and take it out\n"
            . "    of the citation shape -- name the class and the method separately and say it does not\n"
            . "    exist. Eight sites in this corpus already read that way."
        );
    }
}
