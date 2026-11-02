<?php

namespace Tests\Support\Commerce;

/**
 * Every place first-party controller code PERSISTS an order row — derived from the parsed source.
 * =============================================================================================
 *
 * THIS REPLACES A `preg_match` OVER RAW SOURCE, AND THE REPLACEMENT IS THE POINT. The census it
 * replaces (`GatewayReplayGuardCensusTest`, before 2026-10-31) derived its four headline numbers
 * — 31 order-writing controllers, 8 guarded, 19 unguarded, 4 non-gateway — from:
 *
 *     foreach (glob(base_path('app/Http/Controllers/*.php')) as $file)
 *         if (preg_match('/new\s+(\\App\\Models\\)?Order\s*\(|Order::create\s*\(/', $source))
 *
 * and its guard test from `str_contains($source, 'GuardsGatewayReturn')`. All four numbers were
 * wrong, its six tests passed, and the error ran in the direction that invents a finding:
 *
 *   1. `Order::create\s*\(` HAS NO LEFT ANCHOR, so it matches the substring inside
 *      `PlanOrder::create(`. `App\Models\PlanOrder` is a SaaS *plan* sale — a different product,
 *      a different table (`plan_orders`) and a different control (the `plan_activation`
 *      fail-closed map in `config/payments.php`). Twenty-one of the thirty-one "order-writing
 *      controllers" never wrote a customer order at all, including every one of the nineteen the
 *      census reported as the finding.
 *
 *   2. THE PATTERN RAN OVER RAW SOURCE, so two of those nineteen matched inside a docblock
 *      (`PaymentWallPaymentController:184`, `CyberSourceController:213`) — the fifth and sixth
 *      time this repository has asserted on prose; `StripsSourceComments` exists for the first four.
 *
 *   3. THE GLOB IS NON-RECURSIVE, so `app/Http/Controllers/Api/` was structurally invisible and
 *      three live customer-order writers could not be seen: `Api\ApiController::place_order`,
 *      `Api\ApiController::ordersave` and `Api\DashboardController::createOrderViaApi`.
 *
 *   4. `str_contains($source, 'GuardsGatewayReturn')` IS FILE-SCOPED, so it credits a `use`
 *      statement rather than a call. `PayUPaymentController` imports the trait at :23, uses it at
 *      :28 and never calls `claimGatewayReturn()` — it was counted among the eight guarded.
 *      `PaymentController` has two order writers and only one of them claims.
 *
 * So: resolve the class at each write site through the file's own `use` map, walk the whole tree,
 * strip comments before parsing, and ask the replay-guard question of the ENCLOSING METHOD rather
 * than the file.
 */
final class OrderWriteCensus
{
    /** The persisting forms. `insert`/`insertGetId` are here because a census that misses one
     *  reports protection the code does not have — the direction that costs money. */
    private const WRITE_METHODS = [
        'create', 'forceCreate', 'updateOrCreate', 'firstOrCreate', 'insert', 'insertGetId',
    ];

    /** @var array<int,array{file:string,class:string,line:int,method:string,form:string}>|null */
    private static ?array $sites = null;

    /**
     * @return array<int,array{file:string,class:string,line:int,method:string,form:string}>
     *         one row per write site, sorted by file then line
     */
    public static function sites(): array
    {
        if (self::$sites !== null) {
            return self::$sites;
        }

        $root = base_path('app/Http/Controllers');
        $found = [];

        foreach (self::phpFilesUnder($root) as $file) {
            $relative = ltrim(str_replace($root, '', $file), '/');

            foreach (self::sitesIn($file) as $site) {
                $found[] = ['file' => $relative] + $site;
            }
        }

        usort($found, fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

        return self::$sites = $found;
    }

    /** Write sites whose resolved class is the CUSTOMER order. */
    public static function customerOrderSites(): array
    {
        return array_values(array_filter(self::sites(), fn ($s) => $s['class'] === 'App\\Models\\Order'));
    }

    /** Write sites whose resolved class is a SaaS PLAN order. */
    public static function planOrderSites(): array
    {
        return array_values(array_filter(self::sites(), fn ($s) => $s['class'] === 'App\\Models\\PlanOrder'));
    }

    /** `File.php::method` — the key the census buckets are declared against. */
    public static function key(array $site): string
    {
        return $site['file'] . '::' . $site['method'];
    }

    /**
     * Does the named method of the named controller file CALL the replay claim?
     *
     * Per-method and comment-stripped, because both of the shortcuts are how the previous census
     * credited an inert `use` statement and a docblock.
     */
    public static function methodClaimsGatewayReturn(string $relativeFile, string $method): bool
    {
        $body = self::methodBody(base_path('app/Http/Controllers/' . $relativeFile), $method);

        return $body !== null && str_contains($body, 'claimGatewayReturn');
    }

    /** @return string[] */
    private static function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($walk as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<int,array{class:string,line:int,method:string,form:string}> */
    private static function sitesIn(string $file): array
    {
        $tokens = self::codeTokens((string) file_get_contents($file));
        $useMap = self::useMap($tokens);
        $count = count($tokens);

        $sites = [];
        $method = '{file scope}';
        $methodDepth = null;
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    $depth--;
                    if ($methodDepth !== null && $depth < $methodDepth) {
                        $method = '{file scope}';
                        $methodDepth = null;
                    }
                }

                continue;
            }

            /*
             * `"{$x}"` TOKENIZES ASYMMETRICALLY, and it cost two methods their names. The opening
             * brace of an interpolated expression is `T_CURLY_OPEN` — an ARRAY token — while its
             * closing brace is the plain string `}`. Counting only string braces therefore
             * decrements without ever having incremented, the depth drifts negative, and every
             * method declared after the first interpolated string reads as file scope.
             * MEASURED: `Api\DashboardController::createOrderViaApi` (declared at :6337, writes an
             * order at :6595) and `OrderController::store` (:80, writes at :337) both reported
             * `{file scope}` until these two token types were counted.
             */
            if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $name = self::nextName($tokens, $i + 1);
                if ($name !== null) {
                    $method = $name;
                    $methodDepth = $depth + 1;
                }

                continue;
            }

            [$class, $form] = self::writeAt($tokens, $i);

            if ($class === null) {
                continue;
            }

            $resolved = self::resolve($class, $useMap);

            /*
             * An unresolvable `Order`/`PlanOrder` is RECORDED, not dropped. Dropping it is how a
             * census under-reports: the writer is real, only the import is unreadable from here,
             * and the test below fails on any such row so somebody has to look.
             */
            $short = basename(str_replace('\\', '/', $resolved));
            $isOrderShaped = $short === 'Order' || $short === 'PlanOrder';

            if (! in_array($resolved, ['App\\Models\\Order', 'App\\Models\\PlanOrder'], true)
                && ! (str_starts_with($resolved, 'UNRESOLVED\\') && $isOrderShaped)) {
                continue;
            }

            $sites[] = [
                'class' => $resolved,
                'line' => $token[2],
                'method' => $method,
                'form' => $form,
            ];
        }

        return $sites;
    }

    /** @return array{0:?string,1:string} the written class name as spelled, and the form */
    private static function writeAt(array $tokens, int $i): array
    {
        $token = $tokens[$i];

        // `new X(` — including `new \App\Models\X(`.
        if ($token[0] === T_NEW) {
            $name = '';
            $j = $i + 1;

            while ($j < count($tokens)) {
                $next = $tokens[$j];

                if (is_array($next) && $next[0] === T_WHITESPACE) {
                    $j++;

                    continue;
                }

                if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                    $name .= $next[1];
                    $j++;

                    continue;
                }

                break;
            }

            if ($name !== '' && isset($tokens[$j]) && $tokens[$j] === '(') {
                return [$name, 'new'];
            }

            return [null, ''];
        }

        // `X::create(` and the other persisting statics.
        if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && isset($tokens[$i + 1], $tokens[$i + 2], $tokens[$i + 3])
            && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING
            && $tokens[$i + 3] === '('
            && in_array($tokens[$i + 2][1], self::WRITE_METHODS, true)
        ) {
            return [$token[1], $tokens[$i + 2][1]];
        }

        return [null, ''];
    }

    /** @param array<string,string> $useMap */
    private static function resolve(string $name, array $useMap): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $parts = explode('\\', $name);
        $head = $parts[0];

        if (isset($useMap[$head])) {
            $parts[0] = $useMap[$head];

            return implode('\\', $parts);
        }

        // Unimported and unqualified: a bare `Order` with no `use` resolves to the controller's own
        // namespace at runtime, so it is NOT App\Models\Order unless imported. Reported as spelled
        // so the census sees it and has to decide, rather than silently dropping it.
        return count($parts) > 1 ? $name : 'UNRESOLVED\\' . $name;
    }

    /**
     * @return array<string,string> short name => fully qualified
     *
     * HANDLES THE GROUPED FORM, and that is not a nicety. `NMIPayController:12` is
     * `use App\\Models\\{Store,Utility,...,Order,Customer,...};` — thirteen model imports on one
     * line. A reader that stops at the brace never learns that this file's bare `new Order()` at
     * :1040 is `App\\Models\\Order`, and a census built on it reports one writer fewer than the
     * code has. A grouped import is the cheapest place for an order writer to hide.
     */
    private static function useMap(array $tokens): array
    {
        $map = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
                continue;
            }

            // `use function x` / `use const X` import no class.
            $lookahead = self::peek($tokens, $i + 1);
            if (is_array($lookahead) && in_array($lookahead[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $prefix = '';
            $j = $i + 1;
            $grouped = false;

            for (; $j < $count; $j++) {
                $token = $tokens[$j];

                if ($token === ';' || $token === '(') {
                    break;
                }

                if ($token === '{') {
                    $grouped = true;

                    break;
                }

                if (is_array($token) && $token[0] === T_WHITESPACE) {
                    continue;
                }

                if (is_array($token) && $token[0] === T_AS) {
                    break;
                }

                $prefix .= is_array($token) ? $token[1] : $token;
            }

            if ($grouped) {
                // `use Prefix\{A, B as C, D};`
                $prefix = rtrim(ltrim($prefix, '\\'), '\\');
                $item = '';
                $alias = null;
                $expectAlias = false;

                for ($k = $j + 1; $k < $count; $k++) {
                    $token = $tokens[$k];

                    if ($token === '}') {
                        self::record($map, $prefix, $item, $alias);

                        break;
                    }

                    if ($token === ',') {
                        self::record($map, $prefix, $item, $alias);
                        $item = '';
                        $alias = null;
                        $expectAlias = false;

                        continue;
                    }

                    if (is_array($token) && $token[0] === T_WHITESPACE) {
                        continue;
                    }

                    if (is_array($token) && $token[0] === T_AS) {
                        $expectAlias = true;

                        continue;
                    }

                    $text = is_array($token) ? $token[1] : $token;

                    if ($expectAlias) {
                        $alias = $text;
                    } else {
                        $item .= $text;
                    }
                }

                $i = $k ?? $j;

                continue;
            }

            if (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                $alias = self::nextName($tokens, $j + 1);
                self::record($map, '', $prefix, $alias);
                $i = $j;

                continue;
            }

            self::record($map, '', $prefix, null);
            $i = $j;
        }

        return $map;
    }

    /** @param array<string,string> $map */
    private static function record(array &$map, string $prefix, string $item, ?string $alias): void
    {
        $item = trim($item);

        if ($item === '' || str_contains($item, '(')) {
            return;
        }

        $fq = ltrim($prefix === '' ? $item : $prefix . '\\' . ltrim($item, '\\'), '\\');
        $parts = explode('\\', $fq);

        $map[$alias ?? end($parts)] = $fq;
    }

    private static function peek(array $tokens, int $from)
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
                continue;
            }

            return $tokens[$i];
        }

        return null;
    }

    private static function nextName(array $tokens, int $from): ?string
    {
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return $token[1];
            }

            return null;
        }

        return null;
    }

    /** The source of one method, comments already gone, or null if the file has no such method. */
    private static function methodBody(string $file, string $method): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        $tokens = self::codeTokens((string) file_get_contents($file));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            if (self::nextName($tokens, $i + 1) !== $method) {
                continue;
            }

            $body = '';
            $depth = 0;
            $open = false;

            for ($j = $i; $j < $count; $j++) {
                $token = $tokens[$j];
                $body .= is_array($token) ? $token[1] : $token;

                if ($token === '{') {
                    $depth++;
                    $open = true;
                } elseif ($token === '}') {
                    $depth--;

                    if ($open && $depth === 0) {
                        return $body;
                    }
                }
            }

            return $body;
        }

        return null;
    }

    /** Tokens with comments removed — the fifth and sixth docblock match are why. */
    private static function codeTokens(string $source): array
    {
        return array_values(array_filter(
            token_get_all($source),
            fn ($token) => ! (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
        ));
    }
}
