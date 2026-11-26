<?php

/**
 * GAP-294 -- WHAT A NULL `orders.final_price` MEANS, measured on real MySQL/MariaDB.
 * =================================================================================================
 *
 * THE QUESTION, from GAP-294: a NULL `orders.final_price` gives a ZERO refund ceiling, so every
 * refund on that order is refused 422. Should the column become `NOT NULL DEFAULT 0`, or should the
 * ceiling read elsewhere?
 *
 * THIS SCRIPT MEASURES AND RECOMMENDS. IT APPLIES NO MIGRATION. A persistent migration is an owner
 * escalation, and §E below is the reason that matters rather than a formality: the migration is not
 * schema-only, it REWRITES existing NULLs to 0, and a NULL total and a zero total are different
 * claims to a merchant reading a figure.
 *
 * WHAT I CANNOT MEASURE HERE, said first because it is the deciding fact. "How many production
 * orders carry a NULL" needs the production database. There is no dump in this tree and there must
 * not be -- incident #120 is an exposed database dump. So the count is NOT in this script, and
 * §F prints the one query an operator can run to settle it:
 *
 *     SELECT COUNT(*) FROM orders WHERE final_price IS NULL;
 *
 * Everything else below IS measured, on a throwaway database this script creates and drops.
 *
 * WHY MySQL AND NOT THE SUITE. Three of the five findings turn on engine behaviour the SQLite suite
 * cannot show: what `$table->float()->nullable()->default(0)` actually emits, whether an explicit
 * NULL is accepted into it under production's `'strict' => false`, and what an
 * `ALTER ... NOT NULL DEFAULT 0` does to a row that already holds NULL.
 *
 * HOW TO RUN. Needs a throwaway MySQL or MariaDB. It creates and drops its OWN database and refuses
 * a name that does not say it is throwaway:
 *
 *     php tests/Support/Security/bin/verify-null-final-price-ceiling-on-mysql.php
 *
 * NEVER point FRESH_MYSQL_DATABASE at the development or production server. Exits 2 -- not 0 and
 * not 1 -- when a precondition is unmet, because a verification that could not run has neither
 * passed nor failed.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RefundIntegrityService;
use Illuminate\Support\Facades\DB;

$checks = 0;
$failures = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;
    if ($ok) {
        echo "  ok    {$what}\n";
    } else {
        $failures++;
        echo "  FAIL  {$what}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

/**
 * A source file's CODE, comments stripped with PHP's own tokenizer.
 *
 * §D is an absence claim over `app/`, and CLAUDE.md records eight occasions where a census was
 * satisfied -- or broken -- by prose about the thing it measures. The docblocks in this very family
 * discuss `final_price = null` at length, so an unstripped search would find exactly what it is
 * looking for in a comment explaining that it does not happen.
 */
function codeOnly(string $absolute): string
{
    $out = '';

    foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/* ---------------------------------------------------------------- preconditions */

$database = getenv('FRESH_MYSQL_DATABASE') ?: 'null_final_price_verify';

if (! preg_match('/(fresh|verify)/i', $database)) {
    fwrite(STDERR, "Refusing to run against a database whose name says nothing about being throwaway: {$database}\n");
    exit(2);
}

$socket = getenv('FRESH_MYSQL_SOCKET');
if ($socket === false) {
    foreach (['/var/run/mysqld/mysqld.sock', '/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $candidate) {
        if (file_exists($candidate)) {
            $socket = $candidate;
            echo "using the unix socket at {$candidate} (set FRESH_MYSQL_SOCKET='' to force TCP)\n";
            break;
        }
    }
}

$connection = [
    'driver' => 'mysql',
    'host' => getenv('FRESH_MYSQL_HOST') ?: '127.0.0.1',
    'port' => getenv('FRESH_MYSQL_PORT') ?: '3306',
    'unix_socket' => $socket ?: '',
    'database' => $database,
    'username' => getenv('FRESH_MYSQL_USERNAME') ?: 'root',
    'password' => getenv('FRESH_MYSQL_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    /* Production's setting (config/database.php), and the subject of §B: strict mode REFUSES the
       write §B is about, so a harness in strict mode would measure a server this application
       never runs on. */
    'strict' => false,
];

$bootstrap = $connection;
$bootstrap['database'] = null;
config(['database.connections.fresh_bootstrap' => $bootstrap]);
config(['database.connections.fresh' => $connection]);
config(['database.default' => 'fresh']);

try {
    DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
    DB::connection('fresh_bootstrap')->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
} catch (Throwable $e) {
    fwrite(STDERR, "PRECONDITION NOT MET: could not create a throwaway database -- {$e->getMessage()}\n");
    exit(2);
}

$server = DB::connection('fresh')->selectOne('SELECT VERSION() AS v')->v;
echo "server  : {$server}\n";
echo "database: {$database}\n\n";

$root = dirname(__DIR__, 4);

try {
    /* The column exactly as `database/migrations/..._create_orders_table.php:26` declares it --
       `$table->float('final_price')->nullable()->default(0)` -- built through the real schema
       builder rather than hand-written SQL, so §A measures what Laravel EMITS and not what I
       guessed it emits. CLAUDE.md: the migrations are not the schema, and a migration's wording is
       not its column. */
    \Illuminate\Support\Facades\Schema::connection('fresh')->create('orders', function ($table) {
        $table->id();
        $table->unsignedBigInteger('store_id')->nullable();
        $table->string('payment_type')->nullable();
        $table->float('final_price')->nullable()->default(0);
    });

    // ─────────────────────────── [A] what the column really is
    echo "[A] THE COLUMN -- what \$table->float()->nullable()->default(0) emits here\n";

    $col = DB::connection('fresh')->selectOne(
        "SELECT COLUMN_TYPE t, IS_NULLABLE n, COLUMN_DEFAULT d FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'final_price'",
        [$database]
    );

    check(
        'final_price is a double (not a decimal), so no scale is enforced at the column',
        str_starts_with(strtolower((string) $col->t), 'double'),
        'type=' . var_export($col->t, true)
    );
    check(
        'and it is NULLABLE, which is the whole precondition of GAP-294',
        $col->n === 'YES',
        'IS_NULLABLE=' . var_export($col->n, true)
    );
    printf("        type=%s nullable=%s default=%s\n",
        $col->t, $col->n, var_export($col->d, true));

    // ─────────────────────────── [B] is a NULL accepted, and does the default protect the normal path
    echo "\n[B] REACHABILITY AT THE ENGINE -- an explicit NULL, and an omitted column\n";

    $sqlMode = DB::connection('fresh')->selectOne('SELECT @@SESSION.sql_mode AS m')->m;
    check(
        'the session is NOT in strict mode, matching config/database.php',
        ! str_contains(strtoupper((string) $sqlMode), 'STRICT_'),
        'sql_mode=' . var_export($sqlMode, true)
    );

    DB::connection('fresh')->table('orders')->insert(['store_id' => 1, 'payment_type' => 'cod', 'final_price' => null]);
    $explicit = DB::connection('fresh')->table('orders')->orderByDesc('id')->value('final_price');

    check(
        'an EXPLICIT null is accepted and stored as NULL',
        $explicit === null,
        'stored=' . var_export($explicit, true)
    );

    /* THE OTHER HALF, AND IT IS THE REASSURING ONE: an insert that simply omits the column gets the
       DEFAULT, not NULL. So the ordinary path cannot drift into this state by forgetting a field --
       only an explicit null can produce it. */
    DB::connection('fresh')->insert('INSERT INTO orders (store_id, payment_type) VALUES (1, "cod")');
    $omitted = DB::connection('fresh')->table('orders')->orderByDesc('id')->value('final_price');

    check(
        'but an OMITTED column takes the default 0, so forgetting the field cannot cause this',
        $omitted !== null && abs((float) $omitted) < 0.0001,
        'stored=' . var_export($omitted, true)
    );

    // ─────────────────────────── [C] the consequence, through the real service
    echo "\n[C] THE CONSEQUENCE -- the ceiling the refund path builds from it\n";

    $service = new RefundIntegrityService();

    $onNull = $service->cappedRefundAmount('100', null, 'gap294');
    $onReal = $service->cappedRefundAmount('100', 267.00, 'gap294');

    check(
        'a NULL total gives a ZERO ceiling, so a 100.00 refund is capped to 0.00',
        abs($onNull['amount']) < 0.0001 && abs($onNull['limit']) < 0.0001,
        'amount=' . var_export($onNull['amount'], true) . ' limit=' . var_export($onNull['limit'], true)
    );
    check(
        'the instrument: the same request against a real total is NOT capped',
        $onReal['capped'] === false && abs($onReal['amount'] - 100.00) < 0.0001,
        'a ceiling that always bit would make the line above meaningless'
    );
    printf("        NULL total -> amount %.2f (limit %.2f); the controller then answers 422\n",
        $onNull['amount'], $onNull['limit']);
    printf("        \"A refund amount must be a positive number no greater than the order total.\"\n");
    printf("        <- which names the AMOUNT, not the missing total. See the recommendation.\n");

    // ─────────────────────────── [D] can the application itself produce a NULL?
    echo "\n[D] REACHABILITY IN THE CODE -- does any first-party writer put a null there?\n";

    $candidates = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app', FilesystemIterator::SKIP_DOTS));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = codeOnly($file->getPathname());

        /*
         * LINE-BASED AND DELIBERATELY OVER-MATCHING: any line mentioning `final_price` and `null`
         * together. Two narrower patterns were tried first and BOTH under-reported, which is why
         * this one is blunt and the classification happens afterwards:
         *
         *   `= null` / `=> null` literal             -> 0 files. The real shape is `?? null`.
         *   `final_price (=>|=) [^;,)\n]* ?? null`   -> 1 file. The character class excluded `)`,
         *                                               so `getAttributes()['final_price'] ?? null`
         *                                               escaped it.
         *
         * An absence census that answers "none" because its pattern cannot see the subject reads
         * exactly like one that looked and found nothing -- and I made that mistake twice inside
         * this one script before writing this. Over-matching is the SAFE direction: a false
         * positive costs a line of output, a false negative costs the finding.
         */
        foreach (explode("\n", $code) as $i => $line) {
            if (stripos($line, 'final_price') !== false && stripos($line, 'null') !== false) {
                $candidates[] = [
                    'file' => str_replace($root . '/', '', $file->getPathname()),
                    'line' => $i + 1,
                    'text' => trim(substr($line, 0, 120)),
                ];
            }
        }
    }

    usort($candidates, static fn ($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

    /*
     * AND NOW THE CLASSIFICATION, ON THE SHAPE AND NOT ON THE FILENAME. A candidate is a WRITE to
     * the order's own attribute only if it ASSIGNS to it: `->final_price =` or `['final_price'] =`.
     * Everything else is a read with a null fallback, a null CHECK, or a value handed to another
     * function -- none of which can put a NULL in the column.
     *
     * My first classifier filtered by directory ("not Observers/, not OrderEntitlementReversal")
     * and happened to give the right answer for the wrong reason. Two of the candidates are not
     * even this column: `total_final_price` on a basket and `cartlist_final_price` in the stored
     * checkout state.
     */
    $writes = array_values(array_filter(
        $candidates,
        static fn ($c) => preg_match('/->\s*final_price\s*=[^=>]|\[[\'"]final_price[\'"]\]\s*=[^=>]/', $c['text']) === 1
    ));

    printf("        lines mentioning final_price AND null: %d, in %d file(s)\n",
        count($candidates), count(array_unique(array_column($candidates, 'file'))));

    foreach ($candidates as $c) {
        printf("          %-52s :%-6d %s\n", $c['file'], $c['line'], $c['text']);
    }

    check(
        'none of them ASSIGNS a null to the order attribute -- every one is a read, a null check '
        . 'or an argument',
        $writes === [],
        'assignments found: ' . json_encode(array_map(
            static fn ($c) => $c['file'] . ':' . $c['line'] . ' ' . $c['text'],
            $writes
        )) . ' -- if this ever lists one, GAP-294 becomes reachable through the product and its '
        . 'severity changes'
    );

    /* AND THE ONE FUNCTION EVERY ORDER WRITE PASSES ITS TOTAL THROUGH cannot return null. */
    check(
        'SetNumber() returns a number_format() string for every input, so it cannot yield null',
        is_string(SetNumber(0)) && is_string(SetNumber('abc12')) && is_string(SetNumber(12.5)),
        'Order.php writes `$Order->final_price = SetNumber($final_price)`; a null there would be the '
        . 'reachable path and it is not one'
    );

    // ─────────────────────────── [E] what the proposed migration would actually do
    echo "\n[E] THE PROPOSED MIGRATION -- measured, NOT applied to anything but this throwaway\n";

    $before = DB::connection('fresh')->table('orders')->whereNull('final_price')->count();

    DB::connection('fresh')->statement('ALTER TABLE orders MODIFY final_price DOUBLE NOT NULL DEFAULT 0');

    $after = DB::connection('fresh')->table('orders')->whereNull('final_price')->count();
    $rewritten = (int) DB::connection('fresh')->table('orders')->where('final_price', 0.0)->count();

    check(
        'the ALTER succeeds on a table that already holds a NULL rather than refusing it',
        $after === 0,
        "nulls before={$before} after={$after}"
    );
    check(
        'and it REWRITES that NULL to 0 -- the migration is not schema-only',
        $before > 0 && $rewritten >= $before,
        "nulls before={$before}; rows now reading exactly 0={$rewritten}"
    );
    printf("        %d NULL row(s) silently became 0.00. A NULL total means \"unknown\"; a 0.00\n", $before);
    printf("        total means \"nothing to pay\". They are different claims to anyone reading the\n");
    printf("        figure, and the rewrite is irreversible -- which is why this needs the owner.\n");

    // ─────────────────────────── [F] the recommendation
    echo "\n[F] RECOMMENDATION -- the measurements above, turned into advice. NOTHING IS APPLIED.\n";
    echo "        1. DO NOT change the ceiling to read elsewhere. `toAmount(null) -> 0.0` refuses;\n";
    echo "           any fallback RAISES a refund ceiling on an order whose total is unknown, which\n";
    echo "           is a money change in the dangerous direction. The zero ceiling is the safe\n";
    echo "           reading of an ambiguous total and should stay.\n";
    echo "        2. DO NOT apply `NOT NULL DEFAULT 0` on this evidence alone. §E shows it rewrites\n";
    echo "           existing NULLs to 0 irreversibly, and §D shows the state is NOT reachable\n";
    echo "           through the application -- so the migration's only subjects are rows that came\n";
    echo "           from somewhere else (an import, a vendored module, a manual edit). How many\n";
    echo "           there are decides whether it is a no-op or a data change, and that number is\n";
    echo "           NOT measurable in this tree.\n";
    echo "        3. THE DECIDING QUERY, for an operator to run against production:\n";
    echo "               SELECT COUNT(*) FROM orders WHERE final_price IS NULL;\n";
    echo "           Zero -> the migration is a no-op alignment and safe to schedule.\n";
    echo "           Non-zero -> each row is an order whose total is unknown and whose refunds are\n";
    echo "           already refused; they want reconciling, not defaulting to 0.\n";
    echo "        4. THE CHEAP, NON-MONEY IMPROVEMENT, independent of 1-3: the refusal message says\n";
    echo "           \"A refund amount must be a positive number no greater than the order total\",\n";
    echo "           which names the amount the merchant typed when the real cause is a missing\n";
    echo "           total. A merchant cannot act on that. Distinguishing the two costs one branch\n";
    echo "           and moves no money. Recommended, not implemented here.\n";
} finally {
    try {
        DB::connection('fresh')->disconnect();
        DB::connection('fresh_bootstrap')->statement("DROP DATABASE IF EXISTS `{$database}`");
        echo "\n(dropped throwaway database {$database})\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "Could not drop the throwaway database: {$e->getMessage()}\n");
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "{$checks} checks, {$failures} failures\n";
echo "NO MIGRATION WAS APPLIED and no production data was read. The deciding count is in §F.\n";

exit($failures === 0 ? 0 : 1);
