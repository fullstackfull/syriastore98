<?php

namespace App\Services;

/**
 * GAP-195 / OPS-002 — WHERE THE REAL NUMBERS COME FROM, AND NOTHING ELSE.
 * =================================================================================================
 *
 * One job: ask the host. No formatting, no arithmetic, no labels, no translation. Every method
 * returns either the machine's own figure or `null`, and `null` means "this host would not tell
 * us", never zero. `SaasPlatformResources` does the presenting; keeping the two apart is what
 * lets the tests drive a refusing host and a full disk, neither of which can be produced on
 * demand from the container the suite runs in.
 *
 * NOT `final`, DELIBERATELY, and this is the only reason: `tests/Unit/Saas/
 * PlatformResourceMeasurementTest` substitutes a subclass to simulate the two states that matter
 * and cannot otherwise be reached — a host that refuses the measurement, and a filesystem whose
 * total is zero. Nothing in the application subclasses it.
 *
 * THIS CLASS IS NOT AN AUTHORIZATION BOUNDARY. It reports facts about the machine every tenant
 * shares, so every caller's own gate is load-bearing. Its one caller is
 * `SuperAdminResourcesController`, behind `PlatformOperatorOnly` on the route.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * `sys_get_loadavg()` IS NOT DEFINED IN EVERY PHP BUILD, AND AN UNGUARDED CALL IS A FATAL ERROR
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Measured on the build these tests run on: `function_exists('sys_get_loadavg')` is **false**
 * while `ini_get('disable_functions')` is the empty string — so it is missing from the build, not
 * switched off by configuration, and `sys_get_loadavg()` raises `Error: Call to undefined
 * function`. The PHP manual's note is only that it is "not implemented on Windows"; that is not
 * the whole of it.
 *
 * So the load average has TWO sources and the row says which one answered:
 *
 *   1. `sys_get_loadavg()` where the build has it.
 *   2. `/proc/loadavg`, which is what that function reads on Linux anyway — the same three
 *      kernel figures, in the same order.
 *
 * A probe that knew only the function would have reported "we cannot measure the load" on a host
 * whose load average is one `file_get_contents` away. The fallback is not a workaround for this
 * container; it is the measurement OPS-002 asks for, on hosts where the convenient wrapper is
 * absent.
 *
 * EVERY READ IS GUARDED BY `is_readable()` AND TOLERATES A SHORT OR ABSENT FILE. `open_basedir`
 * can hide `/proc` entirely, a non-Linux host has no `/proc` at all, and `/proc/meminfo` on an
 * old kernel has no `MemAvailable:` line. Each of those is a `null` with a reason, not an
 * exception and not a zero.
 */
class SaasResourceProbe
{
    /** Set by `loadAverage()` to the name of whichever source answered. */
    private string $loadAverageSource = '';

    /**
     * The 1-, 5- and 15-minute load averages, keyed by their own minute count.
     *
     * KEYED BY THE WINDOW AND NOT BY POSITION, because the three figures are
     * indistinguishable once they are in a list and a view that mislabels the 15-minute figure as
     * the 1-minute one is unfalsifiable by eye.
     *
     * @return array{1: float, 5: float, 15: float}|null
     */
    public function loadAverage(): ?array
    {
        if (function_exists('sys_get_loadavg')) {
            $averages = sys_get_loadavg();

            if (is_array($averages) && count($averages) >= 3) {
                $this->loadAverageSource = 'sys_get_loadavg()';

                return [
                    1 => (float) $averages[0],
                    5 => (float) $averages[1],
                    15 => (float) $averages[2],
                ];
            }
        }

        $raw = $this->readFile('/proc/loadavg');

        if ($raw !== null) {
            $fields = preg_split('/\s+/', trim($raw)) ?: [];

            if (count($fields) >= 3 && is_numeric($fields[0]) && is_numeric($fields[1]) && is_numeric($fields[2])) {
                $this->loadAverageSource = '/proc/loadavg';

                return [
                    1 => (float) $fields[0],
                    5 => (float) $fields[1],
                    15 => (float) $fields[2],
                ];
            }
        }

        return null;
    }

    /** Which source answered `loadAverage()`, for the row to print. Empty until it is called. */
    public function loadAverageSource(): string
    {
        return $this->loadAverageSource;
    }

    /**
     * How many processors the kernel reports, which is what a load average has to be read against.
     *
     * A load of 4 is saturation on one core and half-idle on eight, so the count is a measurement
     * in its own right and not decoration. Counted from `/proc/cpuinfo`'s `processor` lines; no
     * shell is invoked, because `shell_exec` is commonly disabled and a measurement screen must
     * not depend on it.
     */
    public function cpuCount(): ?int
    {
        $raw = $this->readFile('/proc/cpuinfo');

        if ($raw === null) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/mi', $raw);

        return $count > 0 ? $count : null;
    }

    /**
     * The machine's memory, in bytes, from `/proc/meminfo`.
     *
     * `MemAvailable` AND NOT `MemFree`. Free memory on a busy Linux host is nearly always small
     * because the kernel uses the rest as cache, so `MemFree` reads as "the server is out of
     * memory" when it is not. `MemAvailable` is the kernel's own estimate of what a new workload
     * could claim. Where the kernel is too old to publish it (pre-3.14) this returns null rather
     * than silently substituting the other figure, which would answer a different question under
     * the same label.
     *
     * @return array{total: int, available: int}|null
     */
    public function systemMemory(): ?array
    {
        $raw = $this->readFile('/proc/meminfo');

        if ($raw === null) {
            return null;
        }

        $total = $this->meminfoBytes($raw, 'MemTotal');
        $available = $this->meminfoBytes($raw, 'MemAvailable');

        if ($total === null || $available === null) {
            return null;
        }

        return ['total' => $total, 'available' => $available];
    }

    /**
     * THIS PHP WORKER'S OWN MEMORY while it renders this page — not the machine's.
     *
     * `true` asks for the memory actually allocated from the system rather than the part PHP is
     * currently using inside it, which is the figure that can be compared with `memory_limit`.
     *
     * @return array{current: int, peak: int}
     */
    public function processMemory(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ];
    }

    /**
     * The `memory_limit` directive, as bytes, plus whether it is uncapped.
     *
     * UNLIMITED IS A MEASUREMENT, NOT A MISSING ONE. `-1` is a real and deliberate configuration
     * (it is what this container ships), and collapsing it to null would make it indistinguishable
     * from a directive the probe could not read.
     *
     * @return array{bytes: int|null, unlimited: bool, raw: string}
     */
    public function processMemoryLimit(): array
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '-1') {
            return ['bytes' => null, 'unlimited' => true, 'raw' => $raw];
        }

        if ($raw === '') {
            return ['bytes' => null, 'unlimited' => false, 'raw' => ''];
        }

        /*
         * `UploadLimits::iniKb()` and not a second parser. It already reads the `K`/`M`/`G`
         * suffixes and a bare byte count, it is covered by its own tests, and two copies of a
         * size parser is how the upload screen and this one would come to disagree about what
         * `2M` means. It answers in KILOBYTES and returns 0 for an unset or unlimited directive,
         * which is why both of those cases are settled above rather than here.
         */
        $kb = UploadLimits::iniKb($raw);

        return [
            'bytes' => $kb > 0 ? $kb * 1024 : null,
            'unlimited' => false,
            'raw' => $raw,
        ];
    }

    /**
     * Total and free bytes of the filesystem holding `$path`.
     *
     * Both functions answer `false` on failure — a path inside an `open_basedir` restriction, a
     * stat that the host refuses — and `false` casts to 0, which would be reported as a full
     * disk. So the falses are checked before anything is cast.
     *
     * @return array{total: int, free: int}|null
     */
    public function disk(string $path): ?array
    {
        if (! is_dir($path)) {
            return null;
        }

        $total = @disk_total_space($path);
        $free = @disk_free_space($path);

        if ($total === false || $free === false) {
            return null;
        }

        return ['total' => (int) $total, 'free' => (int) $free];
    }

    /** The machine this was measured on, so two screenshots cannot be confused. */
    public function hostname(): ?string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : null;
    }

    /** `MemTotal:  16473316 kB` -> bytes. Null when the key is absent or not in kB. */
    private function meminfoBytes(string $meminfo, string $key): ?int
    {
        if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s*kB$/mi', $meminfo, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] * 1024;
    }

    /** A readable file's contents, or null — never an exception and never a partial read's false. */
    private function readFile(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
