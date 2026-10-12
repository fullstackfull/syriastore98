<?php

namespace Tests\Support\ApiContract;

/**
 * Parses routes/api.php into the set of registered API paths, WITHOUT booting Laravel.
 *
 * WHY STATIC PARSING. The authoritative answer is the framework router, and
 * bin/generate-server-manifest.php produces exactly that when a vendor/ exists. But
 * `composer install` cannot complete on a stock runner (three dependencies are served
 * from hosts the build network does not reach -- see the Agent 5 report), so a check
 * that only worked with vendor/ would never actually run. This parser is the
 * zero-dependency path: it runs with nothing but `php`, and it is validated against the
 * real router's output by bin/check-contract.php whenever both are available.
 *
 * SCOPE AND HONESTY. This understands the two constructs routes/api.php actually uses:
 * top-level `Route::<verb>('path', ...)` calls, and a single-level
 * `Route::prefix('x')->...->group(function () { ... })` wrapper. It does NOT implement
 * Laravel's router. If routes/api.php grows nested groups, resource routes or
 * controller-group syntax, the parser reports its own coverage so the gap is visible
 * rather than silently producing a short list -- see parse()'s `unsupported` key.
 */
final class RouteFileParser
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    /**
     * @return array{routes: array<int, array{uri: string, methods: array<int,string>, line: int}>, unsupported: array<int, array{line: int, snippet: string}>}
     */
    public static function parse(string $source): array
    {
        $lines       = preg_split('/\R/', $source) ?: [];
        $routes      = [];
        $unsupported = [];

        // Prefix-group tracking. routes/api.php uses one level, but depth is tracked
        // properly so a nested group is handled rather than silently flattened.
        $prefixStack = [];
        $braceDepth  = 0;
        $groupDepths = [];

        $verbs = implode('|', self::VERBS);

        // A route call may span several lines (routes/api.php has multi-line
        // Route::post( ... ) calls), so the whole file is also scanned as one string for
        // the URI argument. Line tracking stays per-line for useful diagnostics.
        foreach ($lines as $i => $line) {
            $lineNo  = $i + 1;
            $trimmed = ltrim($line);

            // Skip whole-line comments so commented-out routes are never counted.
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                $braceDepth += self::netBraces(self::stripStrings($line));

                continue;
            }

            $code = self::stripStrings($line);

            // Does a prefix group open on this line?
            if (preg_match('/Route::(?:[A-Za-z]+\([^)]*\)->)*group\s*\(\s*function/', $line) === 1) {
                $prefix = '';

                if (preg_match('/prefix\s*\(\s*(?<pq>[\'"])(?<p>[^\'"]*)\k<pq>\s*\)/', $line, $pm) === 1) {
                    $prefix = trim($pm['p'], '/');
                }

                $prefixStack[] = $prefix;
                // The group body lives one brace deeper than the depth at the open line.
                $groupDepths[] = $braceDepth + self::netBraces($code);
            }

            // Route registrations on this line.
            if (preg_match('/Route::(?<verb>' . $verbs . ')\s*\(/i', $line, $vm) === 1) {
                $verb = strtolower($vm['verb']);
                $uri  = self::uriArgument($lines, $i);

                if ($uri === null) {
                    $unsupported[] = ['line' => $lineNo, 'snippet' => trim($line)];
                } else {
                    $routes[] = [
                        'uri'     => self::join('api', self::currentPrefix($prefixStack), $uri),
                        'methods' => $verb === 'any' ? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] : [strtoupper($verb)],
                        'line'    => $lineNo,
                    ];
                }
            }

            // Constructs this parser does not model. Flagged, never silently dropped.
            if (preg_match('/Route::(resource|apiResource|match|controller|redirect|view|fallback)\s*\(/', $line) === 1) {
                $unsupported[] = ['line' => $lineNo, 'snippet' => trim($line)];
            }

            $braceDepth += self::netBraces($code);

            // Close any groups whose body we have now left.
            while ($groupDepths !== [] && $braceDepth < $groupDepths[count($groupDepths) - 1]) {
                array_pop($groupDepths);
                array_pop($prefixStack);
            }
        }

        return ['routes' => $routes, 'unsupported' => $unsupported];
    }

    /**
     * The URI literal passed as the first argument of a Route::verb( ... ) call that
     * starts on $startIndex. Looks ahead a few lines because routes/api.php wraps some
     * calls across lines. Returns null when the first argument is not a plain string
     * literal (a variable or concatenation), so the caller can flag it.
     */
    private static function uriArgument(array $lines, int $startIndex): ?string
    {
        $window = '';

        for ($i = $startIndex, $max = min($startIndex + 4, count($lines) - 1); $i <= $max; $i++) {
            $window .= $lines[$i] . "\n";

            if (preg_match(
                '/Route::(?:[A-Za-z]+)\s*\(\s*(?<q>[\'"])(?<uri>[^\'"]*)\k<q>/',
                $window,
                $m
            ) === 1) {
                return $m['uri'];
            }

            // A non-string first argument: stop looking, it is unsupported.
            if (preg_match('/Route::(?:[A-Za-z]+)\s*\(\s*[\$A-Za-z]/', $window) === 1) {
                return null;
            }
        }

        return null;
    }

    private static function currentPrefix(array $stack): string
    {
        $parts = array_values(array_filter($stack, static fn ($p) => $p !== ''));

        return implode('/', $parts);
    }

    private static function join(string ...$parts): string
    {
        $clean = [];

        foreach ($parts as $part) {
            $part = trim($part, '/');

            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return '/' . implode('/', $clean);
    }

    /**
     * Brace delta for a line of code with string literals already removed, so a brace
     * inside a quoted route path never shifts the depth.
     */
    private static function netBraces(string $code): int
    {
        return substr_count($code, '{') - substr_count($code, '}');
    }

    /**
     * Blanks out single- and double-quoted literals so their contents cannot be mistaken
     * for code (braces, comment markers).
     */
    private static function stripStrings(string $line): string
    {
        $line = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/', "''", $line) ?? $line;

        return preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $line) ?? $line;
    }
}
