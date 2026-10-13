<?php

namespace Tests\Support\ApiContract;

/**
 * Asserts that every endpoint the shipped Flutter app calls resolves to a route the
 * server actually registers.
 *
 * THE RISK THIS CLOSES. There is no OpenAPI spec, no Postman collection, no API
 * versioning and no contract test anywhere in either repo. The Flutter app pins its
 * endpoint paths as string constants compiled into a binary that is already on
 * merchants' phones. A server-side route rename is therefore not a deploy -- it is a
 * silent, unrecoverable break of every installed copy of the app, with no error visible
 * on the server side beyond a 404 in the logs. This check turns that class of break
 * into a failing build.
 *
 * DIRECTION OF THE ASSERTION, deliberately one-way: every APP endpoint must exist on
 * the SERVER. The reverse is not an error -- the server legitimately serves the web
 * storefront, the 69 WorkDo addon modules and the customer-facing API, none of which
 * the admin app calls. Server routes with no app caller are reported as informational
 * only and never fail the check.
 */
final class ContractChecker
{
    /**
     * @param array<int, array{field: string, path: string, commented: bool}> $endpoints
     * @param array<int, string>                                              $serverUris
     *
     * @return array{missing: array<int, array{field: string, path: string, full: string}>, matched: array<int, array{field: string, full: string}>, skipped: array<int, array{field: string, path: string}>, server_only: array<int, string>, counts: array<string, int>}
     */
    public static function check(string $basePath, array $endpoints, array $serverUris): array
    {
        $index = self::index($serverUris);

        $missing  = [];
        $matched  = [];
        $skipped  = [];
        $consumed = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint['commented']) {
                // The app no longer calls it. Recorded so a reviewer can see the
                // endpoint was considered, not quietly dropped from the report.
                $skipped[] = ['field' => $endpoint['field'], 'path' => $endpoint['path']];

                continue;
            }

            $full = FlutterEndpointExtractor::fullPath($basePath, $endpoint['path']);
            $hit  = self::resolve($full, $index);

            if ($hit === null) {
                $missing[] = [
                    'field' => $endpoint['field'],
                    'path'  => $endpoint['path'],
                    'full'  => $full,
                ];

                continue;
            }

            $matched[] = [
                'field' => $endpoint['field'],
                'full'  => $full,
                'route' => $hit['uri'],
                'kind'  => $hit['kind'],
            ];

            $consumed[$hit['uri']] = true;
        }

        $serverOnly = array_values(array_filter(
            array_keys($index),
            static fn ($uri) => !isset($consumed[$uri])
        ));
        sort($serverOnly);

        return [
            'missing'     => $missing,
            'matched'     => $matched,
            'skipped'     => $skipped,
            'server_only' => $serverOnly,
            'counts'      => [
                'app_endpoints' => count($endpoints),
                'matched'       => count($matched),
                'missing'       => count($missing),
                'skipped'       => count($skipped),
                'server_routes' => count($index),
            ],
        ];
    }

    /**
     * @param array<int, string> $serverUris
     *
     * @return array<string, true>
     */
    private static function index(array $serverUris): array
    {
        $index = [];

        foreach ($serverUris as $uri) {
            $index['/' . trim($uri, '/')] = true;
        }

        return $index;
    }

    /**
     * Resolves one requested path against the registered set.
     *
     * @return array{uri: string, kind: string}|null the registered route that serves the
     *                                               path, and how it was matched
     *
     * Three forms are accepted, because all three are how the app actually calls:
     *
     *   'exact'      the path matches a registered route segment for segment, allowing a
     *                server route parameter (`{slug}`, `{id}`) to stand for any one
     *                concrete segment -- which is what Laravel's router does.
     *
     *   'path-id'    the app appends a record id as a trailing path segment at call
     *                time, so the constant alone is one segment short of the route.
     *                VERIFIED against the Dart source, not assumed:
     *                  review_repository.dart:32     API.deleteReviewUrl + reviewId
     *                  delivery_boy_repository.dart:34,48,61
     *                                                '${API.xUrl}${API.deliveryBoyIdUrl}$id...'
     *                                                where deliveryBoyIdUrl is '/'
     *                Accepted ONLY when the extra server segment is a route parameter.
     *                A concrete extra segment is a genuine mismatch and stays missing,
     *                so this cannot mask a renamed endpoint.
     *
     * Without the 'path-id' form these four real endpoints were reported as broken when
     * they are not -- a contract check that cries wolf gets switched off, so the
     * distinction is kept rather than loosened into a blanket prefix match.
     */
    private static function resolve(string $path, array $index): ?array
    {
        $path = '/' . trim($path, '/');

        if (isset($index[$path])) {
            return ['uri' => $path, 'kind' => 'exact'];
        }

        $wanted = explode('/', trim($path, '/'));

        // Pass 1: same segment count.
        foreach (array_keys($index) as $uri) {
            $have = explode('/', trim($uri, '/'));

            if (count($have) === count($wanted) && self::segmentsMatch($have, $wanted)) {
                return ['uri' => $uri, 'kind' => 'exact'];
            }
        }

        // Pass 2: the app appends one trailing path id, so the route is one segment
        // longer and that final segment must be a parameter.
        foreach (array_keys($index) as $uri) {
            $have = explode('/', trim($uri, '/'));

            if (count($have) !== count($wanted) + 1) {
                continue;
            }

            if (!self::isParam($have[count($have) - 1])) {
                continue;
            }

            if (self::segmentsMatch(array_slice($have, 0, -1), $wanted)) {
                return ['uri' => $uri, 'kind' => 'path-id'];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $have   registered route segments
     * @param array<int, string> $wanted requested path segments
     */
    private static function segmentsMatch(array $have, array $wanted): bool
    {
        if (count($have) !== count($wanted)) {
            return false;
        }

        foreach ($have as $i => $segment) {
            if (self::isParam($segment)) {
                continue;
            }

            if ($segment !== $wanted[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function isParam(string $segment): bool
    {
        return str_starts_with($segment, '{') && str_ends_with($segment, '}');
    }
}
