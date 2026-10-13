<?php

namespace Tests\Unit\ApiContract;

use PHPUnit\Framework\TestCase;

/**
 * THE CROSS-REPO CONTRACT CHECK WAS CHECKING A STALE SNAPSHOT.
 * =============================================================================================
 *
 * WHAT THE CI STEP CLAIMS. .github/workflows/ci.yml runs
 * tests/Support/ApiContract/bin/check-contract.php on every push and pull request, under the
 * heading "the shipped Flutter app must not be broken", and the reasoning is sound: the admin
 * app compiles its endpoint paths into a binary that is already on merchants' phones, so a
 * server-side rename breaks every installed copy and shows up only as 404s in a log.
 *
 * WHAT IT ACTUALLY CHECKED. Only the endpoints listed in the COMMITTED manifest
 * tests/Support/ApiContract/manifests/flutter-endpoints.json, which has no Flutter checkout to
 * compare itself against -- that is the whole point of committing it, and it is also its
 * weakness. MEASURED on this branch, by copying repo B's lib/utils/base_api.dart from
 * p1f/agent4-flutter into a fixture directory and re-running the generator:
 *
 *     committed manifest : 89 endpoints
 *     regenerated        : 92 endpoints
 *     MISSING FROM THE COMMITTED MANIFEST:
 *         createOrderNoteUrl     /orders/notes/create
 *         deleteOrderNoteUrl     /orders/notes/delete
 *         updateOrderStatusUrl   /orders/update-status
 *
 * So three endpoints the app really calls were outside the gate. Renaming or removing any of the
 * three in routes/api.php would have passed CI and 404'd on every installed phone -- and these
 * are not obscure: they are the order-note write, the order-note delete and the order-status
 * update, and all three were newly gated with custom.auth this phase, i.e. exactly the endpoints
 * that moved.
 *
 * The committed server-side snapshot was stale in the same way: server-api-routes.json recorded
 * 209 routes including /api/delivery-syria/zid-statuses-test, which repo A had already deleted
 * (it is present at main:routes/api.php:381 and absent from the integration branch). 208 now.
 *
 * NOTHING WOULD HAVE NOTICED. The CI comment on the sibling auth-coverage step says
 * "AdminApiSurfaceTest now PINS the manifest's contents, so the file cannot drift from reality
 * again without a test failing". That is true, and it is true of a DIFFERENT manifest:
 * AdminApiSurfaceTest reads admin-auth-coverage-baseline.json (AdminApiSurfaceTest.php:430) and
 * nothing else. Measured: the full Security suite passes both before and after this
 * regeneration, so no test was pinning flutter-endpoints.json at all.
 *
 * WHAT THIS FILE CAN AND CANNOT DO -- stated plainly, because an overstated guard is how the
 * drift above survived.
 *
 *   IT CAN catch a hand-edit, a partial regeneration, or a dropped entry: the field-to-path map
 *   is pinned whole, so any change to the committed manifest that was not deliberate reddens.
 *
 *   IT CANNOT tell whether the manifest is CURRENT with repo B. There is no Flutter checkout in
 *   this repository and no network in this job, so "the app added an endpoint and nobody
 *   regenerated" is undetectable from here, and this file must not be read as covering it.
 *   Closing that gap needs a step with BOTH repositories present:
 *       php tests/Support/ApiContract/bin/generate-manifests.php --app-repo=<repo B>
 *       git diff --exit-code tests/Support/ApiContract/manifests/
 *   which is a cross-repo CI job, and is recorded as an owner action in
 *   docs/STABILIZATION-REGISTER.md rather than claimed as done.
 */
class FlutterEndpointManifestTest extends TestCase
{
    private function manifestPath(): string
    {
        return dirname(__DIR__, 3) . '/tests/Support/ApiContract/manifests/flutter-endpoints.json';
    }

    private function manifest(): array
    {
        $path = $this->manifestPath();

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, 'flutter-endpoints.json is not valid JSON: ' . json_last_error_msg());

        return $decoded;
    }

    /**
     * The three that were missing, named individually rather than counted. A count alone would
     * go green again if three different entries were dropped and three others added.
     */
    public function test_the_three_endpoints_the_manifest_had_lost_are_present(): void
    {
        $paths = array_column($this->manifest()['endpoints'], 'path', 'field');

        foreach ([
            'createOrderNoteUrl'   => '/orders/notes/create',
            'deleteOrderNoteUrl'   => '/orders/notes/delete',
            'updateOrderStatusUrl' => '/orders/update-status',
        ] as $field => $path) {
            $this->assertArrayHasKey(
                $field,
                $paths,
                $field . ' is missing from flutter-endpoints.json. The app calls it, so the '
                . 'cross-repo contract check is not covering it and a rename of ' . $path
                . ' would 404 on every installed phone without failing CI. Regenerate: '
                . 'php tests/Support/ApiContract/bin/generate-manifests.php --app-repo=<repo B>'
            );

            $this->assertSame($path, $paths[$field], $field . ' no longer points at ' . $path . '.');
        }
    }

    public function test_the_endpoint_count_is_the_measured_one(): void
    {
        $this->assertCount(
            92,
            $this->manifest()['endpoints'],
            'flutter-endpoints.json no longer holds 92 endpoints. That is not automatically '
            . 'wrong -- repo B may legitimately have added or removed one -- but it must be a '
            . 'REGENERATION rather than an edit, and this number moves in the same commit. If it '
            . 'DROPPED, find out which endpoint left the gate before accepting it.'
        );
    }

    /**
     * The whole field-to-path map, pinned. This is the assertion that would have caught the
     * three missing entries, and it is deliberately the entire map rather than a sample.
     */
    public function test_the_field_to_path_map_is_pinned_whole(): void
    {
        $actual = array_column($this->manifest()['endpoints'], 'path', 'field');
        ksort($actual);

        $hash = hash('sha256', json_encode($actual, JSON_UNESCAPED_SLASHES));

        $this->assertSame(
            '433efc06fd20dcfba8b98e39261b8a5d2f6c63cde5a88b5792d50a45bb413aa5',
            $hash,
            "The committed field-to-path map has changed.\n\n"
            . "If you regenerated from repo B, this is expected: recompute the hash with\n"
            . "  php -r '\$m=json_decode(file_get_contents(\"tests/Support/ApiContract/manifests/flutter-endpoints.json\"),true);\n"
            . "          \$a=array_column(\$m[\"endpoints\"],\"path\",\"field\"); ksort(\$a);\n"
            . "          echo hash(\"sha256\", json_encode(\$a, JSON_UNESCAPED_SLASHES)), PHP_EOL;'\n"
            . "and update it in the SAME commit as the manifest, with the endpoint delta in the\n"
            . "message.\n\n"
            . "If you did NOT regenerate, something edited a generated file by hand. The header\n"
            . "says not to: check-contract.php is the only consumer, and a hand-edit silently\n"
            . "narrows what it protects -- which is exactly how three order endpoints spent this\n"
            . "phase outside the gate."
        );
    }

    public function test_every_entry_is_shaped_like_an_endpoint(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('/api/admin', $manifest['base_path']);

        foreach ($manifest['endpoints'] as $i => $entry) {
            $this->assertArrayHasKey('field', $entry, "endpoint #{$i} has no field name.");
            $this->assertArrayHasKey('path', $entry, "endpoint #{$i} has no path.");

            $this->assertStringStartsWith(
                '/',
                (string) $entry['path'],
                $entry['field'] . ' has a path that does not start with "/": ' . $entry['path']
                . '. check-contract.php concatenates base_path and path, so a missing slash '
                . 'produces a URI that matches nothing and is reported as unresolved.'
            );
        }
    }

    /**
     * The manifest is GENERATED, and saying so inside the file is what stops the next person
     * hand-editing it. Asserted so the warning cannot be dropped along with the discipline.
     */
    public function test_the_manifest_still_declares_itself_generated(): void
    {
        $manifest = $this->manifest();

        $this->assertStringContainsString(
            'GENERATED',
            (string) ($manifest['_comment'] ?? ''),
            'flutter-endpoints.json no longer says it is generated.'
        );

        $this->assertSame(
            'StoreSyria_app98/lib/utils/base_api.dart',
            $manifest['_source'] ?? null,
            'The manifest no longer records which file in repo B it was extracted from, which is '
            . 'the only pointer anyone has to what to regenerate it against.'
        );
    }
}
