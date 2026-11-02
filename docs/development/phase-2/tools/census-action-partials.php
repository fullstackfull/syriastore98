<?php
/**
 * D-25 STEP 2 — the row-action partials' contract, as DATA, so a 30-file mechanical conversion can
 * be PROVED not to have moved anything it must not move.
 *
 * WHY A CENSUS AND NOT A DIFF. The conversion touched 30 files and 75 controls. `git diff` shows
 * the text that changed; it does not show that no permission gate, route, data-attribute, JS
 * handler class or form boundary changed along with it — and a dropped `@permission` or a renamed
 * `data-ajax-popup` is invisible in a diff full of class-attribute churn. This reduces each file
 * to the things that must be invariant, so "identical" becomes a measurement.
 *
 * IT EARNED ITS KEEP THREE TIMES IN ONE RUN, which is the reason it is committed rather than left
 * in a scratch directory:
 *
 *   1. It caught `aria-label` being inserted INSIDE `data-title="`. The converter anchored on
 *      `\btitle="`, which also matches the tail of `data-title="`, so 22 of 30 files received a
 *      junk `data-aria-label` attribute and NO accessible name — the exact opposite of the fix,
 *      and invisible in a diff that was already full of new attributes.
 *   2. It caught `btn-badge` vanishing from a field declared invariant, which forced the question
 *      of whether that class was load-bearing. It is not: grepped across every first-party and
 *      vendored stylesheet and script, it is selected by nothing. It now has its OWN field, so
 *      dropping it reads as a named, intentional change.
 *   3. Its gate-stack pass answered the one question the field census cannot: not "are the same
 *      permission names present" but "is each control still inside the same gate". Both are
 *      needed; the first was identical in a run where the second would have been the real test.
 *
 * usage:
 *   php census-action-partials.php                      # print the working tree's census
 *   php census-action-partials.php <git-ref>            # compare the working tree AGAINST that ref
 *
 * Exits non-zero if any invariant field differs.
 */

$repo = dirname(__DIR__, 4);
chdir($repo);

/* Classes that are a CONTRACT: JS selects them, so losing one leaves the markup looking right and
   the control dead. Bound by delegated handlers in `public/js/custom.js` and the theme's own js. */
const HANDLER = ['show_confirm', 'delete-comment', 'deleteRecord'];

/* Classes VERIFIED DEAD and therefore droppable — tracked in their own field so that dropping one
   is a NAMED difference instead of a silent change inside HANDLER. */
const DEAD = ['btn-badge'];

/** Fields that must be byte-identical: the conversion is presentational, so anything that gates
 *  access, targets a route, hooks JS or bounds a form may not move. */
const INVARIANT = [
    'permissions', 'permission_count', 'endpermission_count', 'routes', 'data_attrs',
    'handler_classes', 'ajax_popup', 'form_open', 'form_close', 'buttons', 'anchors',
    'icons', 'titles', 'data_titles', 'gates',
];

/** Fields the conversion is MEANT to move, each in a stated direction. */
const INTENDED = ['aria_labels', 'ds_classes', 'bootstrap_btn_classes', 'dead_classes'];

/** Blade comments out, so a docblock describing a defect is not counted as the defect. */
function code(string $raw): string
{
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
}

/**
 * Every route a control targets, with the stack of `@permission` gates enclosing it.
 *
 * THIS IS THE QUESTION THE FIELD CENSUS DOES NOT ANSWER. The `permissions` field proves the SET of
 * gate names is unchanged and `permission_count` proves the directives still balance. Neither
 * proves the DELETE button is still inside `@permission('Delete Tag')` — a conversion could move a
 * control across a gate boundary and leave both of those identical.
 *
 * @return list<array{route: string, gates: list<string>}>
 */
function gateStacks(string $src): array
{
    $pattern = "/@permission\('([^']+)'\)|@endpermission|route\(\s*'([^']+)'|'route'\s*=>\s*\[\s*'([^']+)'/";
    preg_match_all($pattern, $src, $events, PREG_SET_ORDER);

    $stack = [];
    $rows = [];
    foreach ($events as $event) {
        if (($event[1] ?? '') !== '') {
            $stack[] = $event[1];
            continue;
        }
        if ($event[0] === '@endpermission') {
            array_pop($stack);
            continue;
        }
        $route = ($event[2] ?? '') !== '' ? $event[2] : ($event[3] ?? '');
        if ($route !== '') {
            $rows[] = ['route' => $route, 'gates' => array_values($stack)];
        }
    }

    return $rows;
}

/** @return array<string, array<string, mixed>> */
function census(callable $read, array $relativePaths): array
{
    $out = [];
    foreach ($relativePaths as $relative) {
        $raw = $read($relative);
        if ($raw === null) {
            continue;
        }
        $src = code($raw);

        $classes = [];
        preg_match_all('/\bclass="([^"]*)"/', $src, $attrs);
        foreach ($attrs[1] as $attr) {
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $class) {
                if ($class !== '') {
                    $classes[] = $class;
                }
            }
        }
        $unique = static function (array $v): array {
            $v = array_values(array_unique($v));
            sort($v);

            return $v;
        };

        preg_match_all("/@permission\('([^']+)'\)/", $src, $perms);
        preg_match_all("/route\(\s*'([^']+)'/", $src, $r1);
        preg_match_all("/'route'\s*=>\s*\[\s*'([^']+)'/", $src, $r2);
        preg_match_all('/(data-[a-z-]+)=/', $src, $dataAttrs);
        preg_match_all('/class="ti ti-([a-z0-9-]+)"/', $src, $icons);
        preg_match_all('/(ss-btn[a-z-]*)/', $src, $ds);
        preg_match_all('/(btn-(?:info|warning|danger|primary|secondary|success))/', $src, $bs);

        $out[$relative] = [
            'permissions' => $unique($perms[1]),
            'permission_count' => preg_match_all('/@permission\(/', $src),
            'endpermission_count' => preg_match_all('/@endpermission/', $src),
            'routes' => $unique(array_merge($r1[1], $r2[1])),
            'data_attrs' => $unique($dataAttrs[1]),
            'handler_classes' => $unique(array_filter(
                $classes,
                static fn (string $c): bool => in_array($c, HANDLER, true) || str_starts_with($c, 'data-')
            )),
            'dead_classes' => $unique(array_filter($classes, static fn (string $c): bool => in_array($c, DEAD, true))),
            'ajax_popup' => preg_match_all('/data-ajax-popup/', $src),
            'form_open' => preg_match_all('/Form::open|<form\b/', $src),
            'form_close' => preg_match_all('#Form::close|</form>#', $src),
            'buttons' => preg_match_all('/<button\b/', $src),
            'anchors' => preg_match_all('/<a\b/', $src),
            'icons' => $icons[1],
            'aria_labels' => preg_match_all('/aria-label=/', $src),
            /* `(?<![-\w])` so `data-title="` is not counted as a `title="`. The converter's first
               version lacked exactly this lookbehind and wrote into the wrong attribute. */
            'titles' => preg_match_all('/(?<![-\w])title="/', $src),
            'data_titles' => preg_match_all('/data-title="/', $src),
            'ds_classes' => $unique(array_filter($ds[1], static fn (string $c): bool => $c !== '')),
            'bootstrap_btn_classes' => $unique($bs[1]),
            'gates' => gateStacks($src),
        ];
    }

    return $out;
}

$paths = array_map(
    static fn (string $p): string => str_replace($repo . '/', '', $p),
    glob($repo . '/resources/views/*/action.blade.php') ?: []
);
sort($paths);

if ($paths === []) {
    fwrite(STDERR, "no action partials found — run this from inside the repository\n");
    exit(2);
}

$now = census(static fn (string $p): ?string => is_file($p) ? (string) file_get_contents($p) : null, $paths);

$summary = static function (string $label, array $c): void {
    $onDs = array_filter($c, static fn (array $v): bool => $v['ds_classes'] !== []);
    printf(
        "%-10s %d partials; %d on the DS; %d still carrying bootstrap button colours; "
        . "%d aria-labels; %d titles\n",
        $label,
        count($c),
        count($onDs),
        count(array_filter($c, static fn (array $v): bool => $v['bootstrap_btn_classes'] !== [])),
        array_sum(array_column($c, 'aria_labels')),
        array_sum(array_column($c, 'titles'))
    );
};

$ref = $argv[1] ?? null;
if ($ref === null) {
    $summary('working:', $now);
    exit(0);
}

$before = census(static function (string $p) use ($ref): ?string {
    $out = shell_exec('git show ' . escapeshellarg($ref . ':' . $p) . ' 2>/dev/null');

    return ($out === null || $out === '') ? null : $out;
}, $paths);

$summary($ref . ':', $before);
$summary('working:', $now);

$missing = array_diff(array_keys($now), array_keys($before));
if ($missing !== []) {
    echo "\nfiles not present at {$ref} (new, so not compared): " . implode(', ', $missing) . "\n";
}

$bad = [];
foreach (array_keys($before) as $file) {
    foreach (INVARIANT as $field) {
        if ($before[$file][$field] !== $now[$file][$field]) {
            $bad[] = sprintf(
                '  !! %s  %s:  %s  ->  %s',
                $file,
                $field,
                json_encode($before[$file][$field]),
                json_encode($now[$file][$field])
            );
        }
    }
}

printf("\ninvariant fields differing: %d\n", count($bad));
echo implode("\n", $bad) . ($bad === [] ? '' : "\n");

echo "\nintended movement:\n";
foreach (INTENDED as $field) {
    if ($field === 'aria_labels') {
        printf(
            "  %-22s %d -> %d\n",
            $field,
            array_sum(array_column($before, $field)),
            array_sum(array_column($now, $field))
        );
        continue;
    }
    printf(
        "  %-22s files with any: %d -> %d\n",
        $field,
        count(array_filter($before, static fn (array $v) => $v[$field] !== [])),
        count(array_filter($now, static fn (array $v) => $v[$field] !== []))
    );
}

exit($bad === [] ? 0 : 1);
