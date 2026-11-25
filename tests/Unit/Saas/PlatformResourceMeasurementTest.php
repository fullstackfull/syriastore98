<?php

namespace Tests\Unit\Saas;

use App\Services\SaasPlatformResources;
use App\Services\SaasResourceProbe;
use Tests\TestCase;

/**
 * GAP-195 / OPS-002 -- "قياسات موارد حقيقية" / REAL RESOURCE MEASUREMENTS.
 * =================================================================================================
 *
 * The requirement is one word long in the recovered plan and that word is REAL. Before this slice
 * `sys_get_loadavg`, `disk_free_space`, `disk_total_space` and `memory_get_usage` appeared ZERO
 * times in `app/` -- the measurement recorded against GAP-195 in the gap register. So the thing
 * this file has to be able to fail on is not "is there a screen" but "is the number on it taken
 * from the machine".
 *
 * WHY A PROBE IS INJECTED. The figures must come from the operating system, and an assertion
 * against the operating system's own answer can only ever restate it: on this container
 * `sys_get_loadavg()` returns a real triple, so `assertSame(sys_get_loadavg(), ...)` proves the
 * call was made and nothing about the two cases that actually mislead an operator --
 *
 *   - a measurement the host REFUSES (open_basedir over /proc, `disable_functions`, a Windows
 *     host where `sys_get_loadavg()` is not implemented), which must read as "we cannot say" and
 *     never as a zero; `resources/views/components/ss/stat.blade.php` states that contract for
 *     every figure in this application ("value of null means the system cannot produce this
 *     figure ... must not look like a zero").
 *   - a measurement that arrives but is MEANINGLESS (`disk_total_space` of 0 would make a
 *     percentage a division by zero).
 *
 * Neither can be produced on demand from the real host, so `SaasResourceProbe` is a seam and the
 * tests below drive both sides of it. `test_the_load_average_is_the_hosts_own_figure` keeps the
 * other half honest: the default probe really does call the OS and does not return a constant.
 *
 * NO VERDICTS, AND THAT IS ASSERTED. OPS-003 (incidents, alerts, diagnosis) is NOT in this slice
 * and is not started; a threshold -- "80% is a warning" -- is a policy nobody has set, and
 * CLAUDE.md's standing rule for this workstream is to refuse to invent commercial or operational
 * policy rather than to pick a number. `test_no_measurement_carries_a_verdict` fails if a later
 * change adds one, so the absence is a decision on the record rather than an omission.
 *
 * `Tests\TestCase` AND NOT `PHPUnit\Framework\TestCase`, which most of this project's unit tests
 * use. The producer calls `__()` for every label and `storage_path()` for the path it measures, and
 * both need the booted container; against the bare PHPUnit case the whole file errored inside the
 * first `__()` call. It touches no database.
 */
final class PlatformResourceMeasurementTest extends TestCase
{
    /** The exact key set of a measurement row. A new key has to be added here deliberately. */
    private const ROW_KEYS = ['key', 'label', 'value', 'period', 'hint', 'source', 'reason'];

    /**
     * A probe that reports every measurement as refused by the host, which is what an
     * `open_basedir` restriction over /proc plus `disable_functions=sys_get_loadavg` looks like.
     */
    private function blindProbe(): SaasResourceProbe
    {
        return new class extends SaasResourceProbe
        {
            public function loadAverage(): ?array
            {
                return null;
            }

            public function cpuCount(): ?int
            {
                return null;
            }

            public function systemMemory(): ?array
            {
                return null;
            }

            public function disk(string $path): ?array
            {
                return null;
            }
        };
    }

    /** A probe with known figures, so arithmetic can be asserted against numbers I chose. */
    private function fixedProbe(): SaasResourceProbe
    {
        return new class extends SaasResourceProbe
        {
            public function loadAverage(): ?array
            {
                return [1 => 0.5, 5 => 1.25, 15 => 2.0];
            }

            public function cpuCount(): ?int
            {
                return 4;
            }

            public function systemMemory(): ?array
            {
                // 8 GiB total, 2 GiB available -> 6 GiB used, 75%.
                return ['total' => 8589934592, 'available' => 2147483648];
            }

            public function disk(string $path): ?array
            {
                // 100 GiB total, 25 GiB free -> 75 GiB used, 75%.
                return ['total' => 107374182400, 'free' => 26843545600];
            }
        };
    }

    /** @return array<string, array<string, mixed>> every row of every group, keyed by row key */
    private function rowsByKey(array $measured): array
    {
        $rows = [];

        foreach (['processor', 'memory'] as $group) {
            foreach ($measured[$group] as $row) {
                $rows[$row['key']] = $row;
            }
        }

        foreach ($measured['disks'] as $disk) {
            foreach ($disk['rows'] as $row) {
                $rows[$disk['key'] . '.' . $row['key']] = $row;
            }
        }

        return $rows;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The figures are the host's own
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE PREMISE OF THE WHOLE SLICE: the default probe asks the operating system.
     *
     * Asserted against the kernel's own answer, read HERE in the test, so it cannot pass by the
     * probe returning a constant that happens to match.
     *
     * AND THE REASON THERE ARE TWO SOURCES IS THIS CONTAINER. `sys_get_loadavg()` is **not
     * defined** in the PHP build these tests run on -- measured: `function_exists()` is false
     * while `disable_functions` is empty, so it is absent from the build rather than switched
     * off -- and calling it unguarded is a fatal error, not a false. `/proc/loadavg` is readable
     * and is the same kernel figure that function reads on Linux. A probe that knew only the
     * function would report "we cannot measure the load" on a host whose load is one `file_get_
     * contents` away, so it knows both, names the one it used in the row's `source`, and this
     * test follows whichever the host has.
     */
    public function test_the_load_average_is_the_hosts_own_figure(): void
    {
        $os = null;

        if (function_exists('sys_get_loadavg') && is_array(sys_get_loadavg())) {
            $os = array_values(sys_get_loadavg());
        } elseif (is_readable('/proc/loadavg')) {
            $os = array_map('floatval', array_slice(preg_split('/\s+/', trim((string) file_get_contents('/proc/loadavg'))), 0, 3));
        }

        $probe = new SaasResourceProbe();

        $measured = $probe->loadAverage();

        /*
         * THE NO-LOAD-AVERAGE HOST IS ASSERTED, NOT SKIPPED. A skip here proves nothing on the one
         * host where the probe's refusal path is the thing under test, and the sibling Feature case
         * cannot be skipped at all (the known-outcome gate declares the Feature skip set in both
         * directions). So the branch asserts the refusal instead: null, and a source that names why.
         */
        if ($os === null) {
            $this->assertNull($measured,
                'this host exposes no load average, yet the probe produced one -- so the figure is '
                . 'not the operating system\'s');
            $this->assertNotSame('', $probe->loadAverageSource(),
                'the probe refused to measure and did not say why');

            return;
        }

        $this->assertIsArray($measured, 'the probe reported no load average on a host that has one');
        $this->assertSame([1, 5, 15], array_keys($measured),
            'the three windows are keyed by their own minute count, so a view cannot mislabel one');

        foreach ([1 => 0, 5 => 1, 15 => 2] as $window => $index) {
            $this->assertEqualsWithDelta((float) $os[$index], $measured[$window], 0.5,
                "the {$window}-minute figure is not the operating system's own");
        }

        $this->assertNotSame('', $probe->loadAverageSource(),
            'the probe produced a figure without naming where it came from');
    }

    /** Disk space is measured on a path the application really uses, not on a guess. */
    public function test_the_disk_is_measured_on_the_paths_the_application_installs_and_writes_to(): void
    {
        $measured = SaasPlatformResources::measure($this->fixedProbe());

        $paths = array_column($measured['disks'], 'path');

        $this->assertContains(storage_path(), $paths,
            'the writable path was not measured; a full storage directory is the failure an '
            . 'operator needs to see before uploads and logs start failing');
        $this->assertContains(base_path(), $paths,
            'the installation path was not measured');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * A refused measurement is not a zero
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_measurement_the_host_refuses_is_null_and_says_why(): void
    {
        $measured = SaasPlatformResources::measure($this->blindProbe());

        $rows = $this->rowsByKey($measured);

        $this->assertNotEmpty($rows, 'premise: the measurement produced no rows at all');

        foreach ($rows as $key => $row) {
            if ($row['value'] !== null) {
                continue;
            }

            $this->assertNotSame('', (string) $row['reason'],
                "row '{$key}' has no value and no reason, so the screen will show a blank where a "
                . 'sentence belongs');
        }

        $this->assertNull($rows['load_1']['value'],
            'a host that refuses sys_get_loadavg() must not be reported as having load 0');
        $this->assertNotSame('', (string) $rows['load_1']['reason']);
    }

    /** A zero is a measurement and must survive as one. The opposite error of the test above. */
    public function test_a_real_zero_is_reported_as_zero_and_not_as_unavailable(): void
    {
        $probe = new class extends SaasResourceProbe
        {
            public function loadAverage(): ?array
            {
                return [1 => 0.0, 5 => 0.0, 15 => 0.0];
            }
        };

        $rows = $this->rowsByKey(SaasPlatformResources::measure($probe));

        $this->assertSame('0.00', $rows['load_1']['value'],
            'an idle machine really does have a load average of zero, and reading that as '
            . '"unavailable" would hide the one state an operator can act on with confidence');
        $this->assertNull($rows['load_1']['reason']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Arithmetic
     * ---------------------------------------------------------------------------------------------
     */

    public function test_used_space_and_its_percentage_are_derived_from_total_and_free(): void
    {
        $measured = SaasPlatformResources::measure($this->fixedProbe());

        $disk = $measured['disks'][0];
        $rows = [];
        foreach ($disk['rows'] as $row) {
            $rows[$row['key']] = $row;
        }

        $this->assertSame('100 GB', $rows['total']['value']);
        $this->assertSame('25 GB', $rows['free']['value']);
        $this->assertSame('75 GB', $rows['used']['value']);
        $this->assertSame('75.0%', $rows['used_percent']['value']);
    }

    public function test_system_memory_used_is_total_minus_available(): void
    {
        $rows = $this->rowsByKey(SaasPlatformResources::measure($this->fixedProbe()));

        $this->assertSame('8 GB', $rows['system_memory_total']['value']);
        $this->assertSame('2 GB', $rows['system_memory_available']['value']);
        $this->assertSame('6 GB', $rows['system_memory_used']['value']);
        $this->assertSame('75.0%', $rows['system_memory_used_percent']['value']);
    }

    /**
     * A TOTAL OF ZERO IS NOT A FULL DISK. `disk_total_space()` answers 0 for some virtual
     * filesystems, and `used/total` would then be a division by zero -- which PHP 8 throws on.
     */
    public function test_a_total_of_zero_withholds_the_percentage_instead_of_dividing_by_it(): void
    {
        $probe = new class extends SaasResourceProbe
        {
            public function disk(string $path): ?array
            {
                return ['total' => 0, 'free' => 0];
            }
        };

        $disk = SaasPlatformResources::measure($probe)['disks'][0];

        $rows = [];
        foreach ($disk['rows'] as $row) {
            $rows[$row['key']] = $row;
        }

        $this->assertNull($rows['used_percent']['value'],
            'a percentage of a zero total is not a figure; it must be withheld, not printed as 0%');
        $this->assertNotSame('', (string) $rows['used_percent']['reason']);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the rows must say about themselves
     * ---------------------------------------------------------------------------------------------
     */

    /** Every row names the function it was measured with, so the figure can be re-derived. */
    public function test_every_row_names_its_own_measurement_source_and_period(): void
    {
        $rows = $this->rowsByKey(SaasPlatformResources::measure($this->fixedProbe()));

        foreach ($rows as $key => $row) {
            $this->assertSame(self::ROW_KEYS, array_keys($row),
                "row '{$key}' does not carry exactly the declared keys");
            $this->assertNotSame('', (string) $row['label'], "row '{$key}' has no label");
            $this->assertNotSame('', (string) $row['period'],
                "row '{$key}' states no period; `x-ss.stat` refuses to render a figure without one "
                . 'because a count with no period is the commonest way a dashboard misleads');
            $this->assertNotSame('', (string) $row['source'],
                "row '{$key}' does not name what measured it");
        }
    }

    /**
     * THE PER-PROCESS FIGURE MUST NOT READ AS THE MACHINE'S.
     *
     * `memory_get_usage()` is this PHP worker's own footprint while it renders this page. An
     * operator reading it as "the server is using 40 MB of memory" would draw the opposite
     * conclusion from the truth. The two are separate rows with separate sources, and the
     * per-process one says so in its hint.
     */
    public function test_process_memory_is_distinguished_from_the_machines_memory(): void
    {
        $rows = $this->rowsByKey(SaasPlatformResources::measure($this->fixedProbe()));

        $this->assertArrayHasKey('process_memory_current', $rows);
        $this->assertArrayHasKey('system_memory_total', $rows);

        $this->assertStringContainsString('memory_get_usage', $rows['process_memory_current']['source']);
        $this->assertStringNotContainsString('memory_get_usage', $rows['system_memory_total']['source']);

        $this->assertNotSame('', (string) $rows['process_memory_current']['hint'],
            'the per-request figure needs the sentence that stops it being read as the machine');
    }

    /**
     * NO THRESHOLDS, NO ALERTS, NO TRAFFIC LIGHTS -- OPS-003 is not in this slice.
     *
     * A verdict key here would be a policy this project has never set. The row shape is asserted
     * exactly above; this states the intent in the one place a reviewer will look for it.
     */
    public function test_no_measurement_carries_a_verdict(): void
    {
        $rows = $this->rowsByKey(SaasPlatformResources::measure($this->fixedProbe()));

        foreach ($rows as $key => $row) {
            foreach (['status', 'severity', 'ok', 'healthy', 'alert', 'threshold', 'colour', 'color'] as $verdict) {
                $this->assertArrayNotHasKey($verdict, $row,
                    "row '{$key}' carries a '{$verdict}', which is an operational judgement "
                    . 'nobody has authorised. OPS-003 (incidents and alerts) is not started.');
            }
        }
    }

    /** The measurement is fresh by construction: there is no cache in front of it. */
    public function test_the_measurement_is_taken_now_and_not_served_from_a_cache(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Services/SaasPlatformResources.php');

        $this->assertStringNotContainsString('Cache::', $source,
            'a cached resource measurement is a stale one, and a stale load average is worse than '
            . 'none: it is read as current');
    }
}
