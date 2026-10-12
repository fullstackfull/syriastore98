<?php

namespace Tests\Support\ApiContract;

use RuntimeException;

/**
 * Extracts the API endpoint surface the shipped Flutter admin app actually calls.
 *
 * SOURCE OF TRUTH: StoreSyria_app98/lib/utils/base_api.dart. Every endpoint the app
 * reaches is a `static String`/`static const String` on the `API` class, and every
 * request is built as `API.baseUrl + API.<someUrl>`. So the complete callable surface
 * is derivable from that one file without parsing 197 Dart files or running Flutter.
 *
 * PURE BY DESIGN: takes Dart source text, returns arrays. No framework, no Composer
 * autoloader beyond this class, no network. That is deliberate -- the three
 * unreachable payment SDKs mean `composer install` cannot complete on a stock runner,
 * so a contract check that needed vendor/ could never run in CI. This one runs with
 * nothing but `php`.
 *
 * See docs/development/phase-1/04-INCREMENTAL-IMPLEMENTATION-PLAN.md.
 */
final class FlutterEndpointExtractor
{
    /**
     * The `baseUrl` constant is the app's API root. Everything else is appended to it.
     * Captured separately because the path prefix it contributes (`/api/admin`) is what
     * decides which server route group an endpoint belongs to.
     */
    public const BASE_URL_FIELD = 'baseUrl';

    /**
     * Fields whose values are query-string or path fragments, not endpoints. They are
     * recognised structurally (value does not begin with `/`, or begins with `?`/`&`)
     * rather than by an allow-list, so a newly added fragment does not break the check.
     */
    private const FRAGMENT_PREFIXES = ['?', '&'];

    /**
     * @return array{base_url: string, base_path: string, endpoints: array<int, array{field: string, path: string, commented: bool}>, fragments: array<int, string>}
     */
    public static function extract(string $dartSource): array
    {
        $baseUrl   = null;
        $endpoints = [];
        $fragments = [];

        // Matches:  static String foo = '/bar';  |  static const String foo = "/bar";
        // Also matches the same line when commented out, which is captured rather than
        // skipped: a commented endpoint is a deliberate signal that the app no longer
        // calls it, and the report distinguishes the two.
        $pattern = '/^(?<comment>\s*\/\/\s*)?\s*static\s+(?:const\s+)?String\s+'
            . '(?<field>[A-Za-z_][A-Za-z0-9_]*)\s*=\s*'
            . '(?<quote>[\'"])(?<value>.*?)\k<quote>\s*;/m';

        if (preg_match_all($pattern, $dartSource, $matches, PREG_SET_ORDER) === false) {
            throw new RuntimeException('Failed to scan the Dart source for API constants.');
        }

        foreach ($matches as $m) {
            $field     = $m['field'];
            $value     = $m['value'];
            $commented = ($m['comment'] ?? '') !== '';

            if ($field === self::BASE_URL_FIELD) {
                // The live baseUrl is the first uncommented one; alternates (ngrok, local)
                // are left commented in the file and must not win.
                if (!$commented && $baseUrl === null) {
                    $baseUrl = $value;
                }

                continue;
            }

            if ($value === '' || in_array($value[0], self::FRAGMENT_PREFIXES, true)) {
                $fragments[] = $field;

                continue;
            }

            if ($value[0] !== '/') {
                // Not a path at all (a header name, a literal, a scheme).
                continue;
            }

            if ($value === '/') {
                // `deliveryBoyIdUrl = '/'` is a path separator before an interpolated id,
                // not an endpoint of its own.
                $fragments[] = $field;

                continue;
            }

            $endpoints[] = [
                'field'     => $field,
                'path'      => $value,
                'commented' => $commented,
            ];
        }

        if ($baseUrl === null) {
            throw new RuntimeException(
                'No uncommented `static String baseUrl` found in base_api.dart. The app\'s '
                . 'API root cannot be determined, so no contract can be asserted.'
            );
        }

        return [
            'base_url'  => $baseUrl,
            'base_path' => self::pathOf($baseUrl),
            'endpoints' => $endpoints,
            'fragments' => $fragments,
        ];
    }

    /**
     * The path component of the app's base URL, normalised to a leading slash and no
     * trailing slash: `https://storesyria.com/api/admin` -> `/api/admin`.
     */
    public static function pathOf(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    /**
     * The full server path an endpoint resolves to, as the app would request it.
     */
    public static function fullPath(string $basePath, string $endpointPath): string
    {
        return rtrim($basePath, '/') . '/' . ltrim($endpointPath, '/');
    }
}
