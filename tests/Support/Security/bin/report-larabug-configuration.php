<?php

/**
 * P1-SEC-14 criterion 3 -- IS THE ERROR TRACKER KEYED IN PRODUCTION? READ ONLY, AND IT PRINTS NO KEY.
 *
 * WHY THIS SCRIPT EXISTS. The criterion is "the owner is told whether the tracker is keyed in
 * production, and the answer is RECORDED". `.env` is not in this repository and cannot be read from a
 * development container, so the row sat waiting for a reply. It does not need a reply -- it needs one
 * command run where the application actually runs, and this is that command. Its output IS the record:
 * paste it onto the issue.
 *
 * WHAT IT PRINTS, AND WHAT IT DELIBERATELY DOES NOT. Whether each key is present, its LENGTH, and the
 * resolved configuration around it. Never the value, and never the first or last characters of one: a
 * prefix is enough to identify an account, and this file's output is meant to be pasteable into a
 * public issue.
 *
 * WHAT THE ANSWER MEANS, so reading the output does not need the ledger open:
 *
 *   BOTH KEYS SET      larabug is a live outbound reporter for this install. Every reported exception
 *                      posts to `larabug.server` -- by default a third party. The blacklist and
 *                      App\Services\ScrubbedLaraBug remove request values, the exception message, the
 *                      trace string and the URL, and the user model is reduced to an id and a type.
 *                      What still goes out is the `executor` block: the twelve source lines either
 *                      side of the throw, verbatim, plus the file, line and class. That is this
 *                      application's own source, and no scrubber removes it. If the owner does not
 *                      want that, the close is to unset the keys or to drop the channel from the
 *                      stack.
 *
 *   EITHER KEY MISSING Nothing is posted. ScrubbedLaraBug::isConfigured() refuses to report unless
 *                      both are non-blank -- which is what makes this question harmless rather than
 *                      urgent. Without that gate, larabug's own Client::report() would POST with an
 *                      empty bearer token to an account nobody owns.
 *
 * A SEPARATE FACT THE OWNER NEEDS WHEN THEY DECIDE, measured under P1-PLT-05 and re-asserted by
 * LaraBugPayloadTest: enabling larabug would deliver NONE of the application's security log lines.
 * LaraBugHandler::write() discards every record whose context carries no Throwable, and all 127
 * inventoried warning/critical lines are Log::warning/critical with a plain context array. larabug is
 * an exception reporter; the observability question in P1-PLT-05 is a different one.
 *
 * Run it where the application's own .env is in force:
 *     php tests/Support/Security/bin/report-larabug-configuration.php
 *
 * Exit status: 0 whatever the answer. This reports; it does not judge.
 */

require __DIR__ . '/../../../../vendor/autoload.php';
$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

/** Present, and how long -- never the value. */
$describe = static function ($value): string {
    if ($value === null) {
        return 'NOT SET';
    }

    $trimmed = trim((string) $value);

    if ($trimmed === '') {
        return 'SET BUT BLANK';
    }

    return 'SET (' . strlen($trimmed) . ' characters)';
};

$loginKey = config('larabug.login_key');
$projectKey = config('larabug.project_key');

$bothPresent = trim((string) $loginKey) !== '' && trim((string) $projectKey) !== '';

echo "P1-SEC-14 criterion 3 -- larabug configuration as this process resolves it\n";
echo str_repeat('-', 78) . "\n";
printf("APP_ENV                 : %s\n", (string) config('app.env'));
printf("larabug.login_key       : %s\n", $describe($loginKey));
printf("larabug.project_key     : %s\n", $describe($projectKey));
printf("larabug.server          : %s\n", (string) config('larabug.server'));
printf("larabug.environments    : %s\n", implode(', ', (array) config('larabug.environments', [])));
printf("larabug in this env     : %s\n", in_array(config('app.env'), (array) config('larabug.environments', []), true) ? 'YES' : 'no');
printf("LOG_CHANNEL             : %s\n", (string) config('logging.default'));
printf("stack channels          : %s\n", implode(', ', (array) config('logging.channels.stack.channels', [])));

echo str_repeat('-', 78) . "\n";

printf("larabug.outbound_enabled: %s\n", config('larabug.outbound_enabled', false) ? 'TRUE' : 'false');

echo str_repeat('-', 78) . "\n";

if (class_exists(\App\Services\ScrubbedLaraBug::class)
    && method_exists(\App\Services\ScrubbedLaraBug::class, 'isConfigured')) {
    printf("ScrubbedLaraBug::isConfigured() : %s\n", \App\Services\ScrubbedLaraBug::isConfigured() ? 'true' : 'false');

    if (method_exists(\App\Services\ScrubbedLaraBug::class, 'mayReportOutbound')) {
        printf(
            "ScrubbedLaraBug::mayReportOutbound() : %s\n",
            \App\Services\ScrubbedLaraBug::mayReportOutbound()
                ? 'TRUE -- exception payloads WILL leave this server'
                : 'false -- nothing is posted'
        );
    }
} else {
    echo "ScrubbedLaraBug::isConfigured() : ABSENT -- the outbound gate is not in this build.\n";
    echo "  That is the condition the gate was added for. Do not read 'no keys' as 'nothing is sent'\n";
    echo "  without it: larabug's own Client::report() posts with an empty bearer token.\n";
}

echo "\nANSWER: ";

echo $bothPresent
    ? "this install IS keyed. Whether anything actually leaves depends on the SECOND gate above --\n"
        . "larabug.outbound_enabled -- which P1-SEC-14 added and defaults to false. If both read TRUE,\n"
        . "reported exceptions leave this server carrying the source lines around each throw, and no\n"
        . "scrubber removes those. Decide whether that is wanted.\n"
    : "this install is NOT keyed, so nothing is posted whatever outbound_enabled says. Criterion 3 is\n"
        . "answered by this output.\n";

echo "\nNothing was modified, and no key value was printed. Paste this output onto issue #89.\n";

exit(0);
