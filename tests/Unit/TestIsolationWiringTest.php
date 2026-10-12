<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the WIRING as file content, not behaviour.
 *
 * The guard's logic and the handful of lines that attach it fail in different ways and
 * need different tests. A tests/bootstrap.php that exists but is not referenced by
 * phpunit.xml is not a control at all, and a bootstrap= reverted in a merge is the
 * single most likely way this protection silently disappears.
 *
 * See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md §7.2.
 */
class TestIsolationWiringTest extends TestCase
{
    private function root(string $path): string
    {
        return file_get_contents(__DIR__ . '/../../' . $path);
    }

    public function test_phpunit_xml_forces_sqlite_and_uses_the_bootstrap_guard(): void
    {
        $xml = $this->root('phpunit.xml');

        $this->assertStringContainsString(
            'bootstrap="tests/bootstrap.php"',
            $xml,
            'phpunit.xml must load Layer 0; without this the primary control never runs.'
        );
        $this->assertMatchesRegularExpression(
            '/<env name="DB_CONNECTION" value="sqlite"[^>]*force="true"/',
            $xml,
            'DB_CONNECTION must be forced to sqlite, or the ambient environment can win.'
        );
        $this->assertMatchesRegularExpression(
            '/<env name="DB_DATABASE" value=":memory:"[^>]*force="true"/',
            $xml,
            'DB_DATABASE must be forced to :memory:.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<!--[^>]*env name="DB_(CONNECTION|DATABASE)"/',
            $xml,
            'Neither override may sit inside an XML comment -- that was the original defect.'
        );
    }

    public function test_the_shared_guard_is_still_wired_into_both_layers(): void
    {
        $this->assertStringContainsString(
            'assertSqliteInMemory',
            $this->root('tests/bootstrap.php'),
            'Layer 0 must call the shared assertion.'
        );
        $this->assertStringContainsString(
            'assertSqliteInMemory',
            $this->root('tests/TestCase.php'),
            'Layer 3 must call the shared assertion.'
        );
    }

    public function test_env_testing_pins_sqlite_and_carries_a_key(): void
    {
        $env = $this->root('.env.testing');

        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('DB_DATABASE=:memory:', $env);
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=base64:.+$/m',
            $env,
            'A blank APP_KEY makes Laravel throw for anything touching sessions or cookies.'
        );
    }
}
