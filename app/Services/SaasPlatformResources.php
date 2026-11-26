<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * GAP-195 / OPS-002 — "قياسات موارد حقيقية" / REAL RESOURCE MEASUREMENTS.
 * =================================================================================================
 *
 * The recovered requirement is one line long and the load-bearing word is REAL. What was measured
 * against it in `docs/development/MASTER-PLAN-GAP-REGISTER.md` was an absence: `sys_get_loadavg`,
 * `disk_free_space` and `memory_get_usage` occurred ZERO times in `app/`. Every "system" figure
 * this product showed an operator before this slice was a count of its own rows.
 *
 * THE SHAPE OF EVERY ROW IS THE POINT, not the list of measurements. Each one carries:
 *
 *   value    the figure, already spelled for the page — or NULL, which means "this host would not
 *            tell us" and must never be rendered as a zero. `resources/views/components/ss/
 *            stat.blade.php` sets that contract for every figure in this application.
 *   period   what span the figure covers. The stat component refuses to render a figure without
 *            one, because a number with no period is the commonest way a dashboard misleads.
 *   source   the function or file that produced it, so an operator can re-derive it by hand.
 *   reason   present only when `value` is null: the sentence that says what was refused.
 *
 * NO VERDICTS. No threshold, no traffic light, no "healthy". OPS-003 — incidents, alerts and
 * diagnosis — is a separate recovered requirement and is not started; "85% of the disk is a
 * warning" is an operational policy nobody has set, and this workstream's standing instruction is
 * to refuse to invent one rather than to pick a figure that will then be quoted back as the
 * platform's rule. `PlatformResourceMeasurementTest::test_no_measurement_carries_a_verdict` fails
 * if a later change adds one, so the absence is on the record.
 *
 * NO CACHE, EVER. A cached load average is read as the current one. `Cachable` models and the
 * memoised `remember` of the platform admin's row are everywhere in this codebase and both would be
 * wrong here, so `PlatformResourceMeasurementTest` greps this file for a cache facade call and
 * fails on one. That sentence is deliberately written WITHOUT the token it greps for: CLAUDE.md
 * records four occasions in one day on which a census was satisfied -- or broken -- by prose about
 * the thing it measures, and this census reads the file whole, comments included.
 *
 * THIS CLASS IS NOT AN AUTHORIZATION BOUNDARY. The machine is shared by every tenant, so these
 * figures belong to the platform operator alone and each caller's own gate is the whole control.
 * Its one caller is `SuperAdminResourcesController`, gated by `PlatformOperatorOnly` on the route.
 *
 * WHAT IT DELIBERATELY DOES NOT MEASURE, so the next reader does not take the absence for an
 * oversight: queue depth, database size, and integration reachability are OPS-001, and incident
 * handling is OPS-003. Neither is in the actionable set this slice was authorised from, and a
 * half-built monitor is worse than a stated gap.
 */
final class SaasPlatformResources
{
    /**
     * @return array{
     *     context: array{hostname: string|null, measured_at: CarbonImmutable},
     *     processor: list<array<string, mixed>>,
     *     memory: list<array<string, mixed>>,
     *     disks: list<array{key: string, label: string, path: string, rows: list<array<string, mixed>>}>
     * }
     */
    public static function measure(?SaasResourceProbe $probe = null): array
    {
        $probe = $probe ?? new SaasResourceProbe();

        return [
            'context' => [
                'hostname' => $probe->hostname(),
                'measured_at' => CarbonImmutable::now(),
            ],
            'processor' => self::processor($probe),
            'memory' => self::memory($probe),
            'disks' => self::disks($probe),
        ];
    }

    /**
     * THE LOAD AVERAGES AND THE NUMBER THEY HAVE TO BE READ AGAINST.
     *
     * The processor count is in this group and not in a footnote because a load average without it
     * is not interpretable: 4.0 is saturation on one processor and half-idle on eight. Stating the
     * relationship is a definition, not a threshold — this method says what the figure means and
     * never whether it is acceptable.
     *
     * @return list<array<string, mixed>>
     */
    private static function processor(SaasResourceProbe $probe): array
    {
        $load = $probe->loadAverage();
        $source = $probe->loadAverageSource();
        $cpus = $probe->cpuCount();

        $unavailable = __('This host exposes no load average: sys_get_loadavg() is not defined in this PHP build and /proc/loadavg could not be read.');

        $windows = [
            1 => ['key' => 'load_1', 'label' => __('Load average (1 minute)'), 'period' => __('the last minute')],
            5 => ['key' => 'load_5', 'label' => __('Load average (5 minutes)'), 'period' => __('the last 5 minutes')],
            15 => ['key' => 'load_15', 'label' => __('Load average (15 minutes)'), 'period' => __('the last 15 minutes')],
        ];

        $rows = [];

        foreach ($windows as $minutes => $window) {
            $rows[] = self::row(
                $window['key'],
                $window['label'],
                $load === null ? null : number_format($load[$minutes], 2),
                $window['period'],
                __('The kernel\'s own figure: the average number of processes waiting to run over the window. A value equal to the processor count means the processors were fully occupied on average; this screen states the number and never judges it.'),
                $source !== '' ? $source : 'sys_get_loadavg() / /proc/loadavg',
                $load === null ? $unavailable : null,
            );
        }

        $rows[] = self::row(
            'cpus',
            __('Processors'),
            $cpus === null ? null : (string) $cpus,
            __('now'),
            __('Counted from the kernel\'s own list. The load averages above are only readable against this number.'),
            '/proc/cpuinfo',
            $cpus === null ? __('The processor count could not be read from /proc/cpuinfo on this host.') : null,
        );

        return $rows;
    }

    /**
     * TWO DIFFERENT THINGS CALLED MEMORY, AND KEEPING THEM APART IS THE WHOLE OF THIS METHOD.
     *
     * The machine's memory comes from the kernel. The next three rows are THIS PHP WORKER's own
     * footprint while it renders this page, which is a useful figure about the application and a
     * dangerously misleading one about the server: 40 MB against a 16 GB machine is not "the
     * server is nearly idle", it is one request. Each row names its own source and the
     * per-request rows say so in their hint.
     *
     * @return list<array<string, mixed>>
     */
    private static function memory(SaasResourceProbe $probe): array
    {
        $system = $probe->systemMemory();
        $process = $probe->processMemory();
        $limit = $probe->processMemoryLimit();

        $systemUnavailable = __('The machine\'s memory could not be read: /proc/meminfo is unavailable on this host, or its kernel publishes no MemAvailable line.');

        $used = $system === null ? null : max(0, $system['total'] - $system['available']);

        $rows = [
            self::row(
                'system_memory_total',
                __('Memory on the machine'),
                $system === null ? null : self::bytes($system['total']),
                __('now'),
                __('MemTotal as the kernel reports it.'),
                '/proc/meminfo MemTotal',
                $system === null ? $systemUnavailable : null,
            ),
            self::row(
                'system_memory_available',
                __('Memory available'),
                $system === null ? null : self::bytes($system['available']),
                __('now'),
                __('MemAvailable, the kernel\'s own estimate of what a new workload could claim. Deliberately not MemFree, which is small on every busy Linux host because the remainder is in use as cache, and would read as a machine out of memory.'),
                '/proc/meminfo MemAvailable',
                $system === null ? $systemUnavailable : null,
            ),
            self::row(
                'system_memory_used',
                __('Memory in use'),
                $used === null ? null : self::bytes($used),
                __('now'),
                __('Total less available, as reported above. Nothing else is subtracted.'),
                '/proc/meminfo MemTotal - MemAvailable',
                $system === null ? $systemUnavailable : null,
            ),
            self::share(
                'system_memory_used_percent',
                __('Memory in use (share)'),
                $used,
                $system === null ? null : $system['total'],
                __('now'),
                __('Memory in use as a share of the memory on the machine.'),
                '/proc/meminfo',
                $systemUnavailable,
            ),
            self::row(
                'process_memory_current',
                __('Memory used by this request'),
                self::bytes($process['current']),
                __('this request'),
                __('This PHP worker\'s own footprint while it built this page — NOT the machine\'s. One request is not the server, and reading it as the server is the misreading this row exists to prevent.'),
                'memory_get_usage(true)',
                null,
            ),
            self::row(
                'process_memory_peak',
                __('Peak memory of this request'),
                self::bytes($process['peak']),
                __('this request'),
                __('The highest the figure above reached while this page was built.'),
                'memory_get_peak_usage(true)',
                null,
            ),
        ];

        $rows[] = self::row(
            'process_memory_limit',
            __('Memory limit for one request'),
            $limit['unlimited']
                ? __('Unlimited')
                : ($limit['bytes'] === null ? null : self::bytes($limit['bytes'])),
            __('now'),
            __('The memory_limit directive this process is running under. It caps one request, not the machine. Unlimited is a real configuration and is reported as such rather than as a figure nobody set.'),
            'ini_get(\'memory_limit\')',
            $limit['unlimited'] || $limit['bytes'] !== null
                ? null
                : __('No memory_limit directive could be read on this host.'),
        );

        return $rows;
    }

    /**
     * THE TWO PATHS THAT CAN STOP THE PRODUCT, measured separately and each naming its own path.
     *
     * `storage_path()` is where the application WRITES — uploads, logs, sessions, compiled views —
     * so that filesystem filling up is a live outage with a quiet beginning. `base_path()` is
     * where it is installed. They are frequently the same filesystem and sometimes not, which is
     * exactly why both are measured and neither is assumed.
     *
     * @return list<array{key: string, label: string, path: string, rows: list<array<string, mixed>>}>
     */
    private static function disks(SaasResourceProbe $probe): array
    {
        $targets = [
            ['key' => 'storage', 'label' => __('Where the application writes'), 'path' => storage_path()],
            ['key' => 'install', 'label' => __('Where the application is installed'), 'path' => base_path()],
        ];

        $disks = [];

        foreach ($targets as $target) {
            $measured = $probe->disk($target['path']);

            $unavailable = __('The filesystem holding :path did not answer disk_total_space() or disk_free_space() on this host.', ['path' => $target['path']]);

            $used = $measured === null ? null : max(0, $measured['total'] - $measured['free']);

            $disks[] = [
                'key' => $target['key'],
                'label' => $target['label'],
                'path' => $target['path'],
                'rows' => [
                    self::row(
                        'total',
                        __('Total space'),
                        $measured === null ? null : self::bytes($measured['total']),
                        __('now'),
                        __('The whole filesystem holding this path, not the directory\'s own size.'),
                        'disk_total_space()',
                        $measured === null ? $unavailable : null,
                    ),
                    self::row(
                        'free',
                        __('Free space'),
                        $measured === null ? null : self::bytes($measured['free']),
                        __('now'),
                        __('As the filesystem reports it to this process.'),
                        'disk_free_space()',
                        $measured === null ? $unavailable : null,
                    ),
                    self::row(
                        'used',
                        __('Used space'),
                        $used === null ? null : self::bytes($used),
                        __('now'),
                        __('Total less free. On a filesystem that reserves blocks for root this is larger than the sum of the files.'),
                        'disk_total_space() - disk_free_space()',
                        $measured === null ? $unavailable : null,
                    ),
                    self::share(
                        'used_percent',
                        __('Used space (share)'),
                        $used,
                        $measured === null ? null : $measured['total'],
                        __('now'),
                        __('Used space as a share of the whole filesystem.'),
                        'disk_total_space()',
                        $unavailable,
                    ),
                ],
            ];
        }

        return $disks;
    }

    /**
     * A SHARE, OR NOTHING — never a division by a zero total.
     *
     * `disk_total_space()` answers 0 for some virtual filesystems, and in PHP 8 dividing by it
     * throws `DivisionByZeroError`. A 0% printed there would also be the opposite of what an
     * operator would conclude from a total of zero.
     *
     * @return array<string, mixed>
     */
    private static function share(
        string $key,
        string $label,
        ?int $part,
        ?int $whole,
        string $period,
        string $hint,
        string $source,
        string $unavailable,
    ): array {
        if ($part === null || $whole === null) {
            return self::row($key, $label, null, $period, $hint, $source, $unavailable);
        }

        if ($whole <= 0) {
            return self::row($key, $label, null, $period, $hint, $source,
                __('The total reported for this measurement is zero, so a share of it is not a figure.'));
        }

        return self::row($key, $label, number_format($part / $whole * 100, 1) . '%', $period, $hint, $source, null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        string $key,
        string $label,
        ?string $value,
        string $period,
        string $hint,
        string $source,
        ?string $reason,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'period' => $period,
            'hint' => $hint,
            'source' => $source,
            'reason' => $reason,
        ];
    }

    /**
     * Bytes, spelled the way the rest of this application spells a size.
     *
     * `UploadLimits::humanKb()` and NOT a second formatter: it is already the spelling the upload
     * limits screen uses, it is covered by its own tests, and two size formatters is how two
     * screens come to disagree about what a gigabyte is. It takes kilobytes, so the conversion is
     * here and the rounding is its.
     */
    private static function bytes(int $bytes): string
    {
        return UploadLimits::humanKb((int) round($bytes / 1024));
    }
}
