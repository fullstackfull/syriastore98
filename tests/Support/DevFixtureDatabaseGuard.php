<?php

namespace Tests\Support;

use RuntimeException;

/**
 * THE WRITE-SIDE COUNTERPART TO `TestDatabaseGuard`. P2.5-SAFETY-W, 2026-11-06.
 * =================================================================================================
 *
 * `TestDatabaseGuard` stops the TEST SUITE from running `migrate:fresh` over a real database. It
 * exists because that already happened: the development fixture was emptied through a gap in it,
 * reproduced on a throwaway copy, and its docblock carries the measured values from inside the
 * destroying run.
 *
 * NOTHING GUARDED THE OTHER DIRECTION. `docs/development/phase-2.5/tools/` holds executable tools
 * that WRITE -- a fixture seeder that inserts orders, wallet transactions and withdrawals, and two
 * scripts that GRANT PERMISSIONS to a user. Each resolves its target purely from the environment,
 * and each carried only a comment saying which environment to pass:
 *
 *     "Same DB/cache env as serve-dev.sh, or it writes to a different sqlite file and
 *      reports success."
 *
 * That is a written warning, and the owner's directive is explicit that a written warning is not a
 * control. Run with no environment at all, these tools fall through to `.env` -- which on a
 * developer's machine or a server points at the real MySQL database. The seeder would then insert
 * probe rows into live data and print success, and the permission scripts would grant a live user
 * new permissions. Neither is hypothetical: the seeder's own header records that it once "refused
 * with 'fixture is missing a store or merchant@example.test'" because it had been pointed at an
 * empty database, which is the same mistake landing on a harmless target by luck.
 *
 * THE THREE RULES, each fail-closed:
 *
 *   1. EVERY environment source is checked, not the first one that exists. This is the exact lesson
 *      `TestDatabaseGuard::assertEveryEnvironmentSourceIsSafe()` was written for: an `export`
 *      survives in `$_SERVER` and only there, Laravel's Dotenv reads `$_SERVER` FIRST, and a
 *      `??` chain stops at the first source that is merely present. A guard that reads its sources
 *      in a different precedence to the framework it protects can be blind to the decisive one.
 *
 *   2. The target must be sqlite AND its filename must be one of the known dev fixtures. A path
 *      check is what distinguishes "a sqlite file" from "the sqlite file this tool is for" --
 *      `database/database.sqlite` is a real file in this repository and is NOT the fixture.
 *
 *   3. The file must ALREADY EXIST, and SOMETHING must have named it. A dev write tool has no
 *      business creating a database: a path that does not exist means the path is wrong, and
 *      silently creating it is how a tool reports success having written nowhere. An empty
 *      environment is refused rather than allowed to fall through to `.env`.
 *
 * PURE BY DESIGN, exactly like `TestDatabaseGuard`: it takes strings, returns nothing, and throws.
 * It boots nothing, reads no config and opens no connection -- which is what makes it callable from
 * a bare script before Laravel exists, and testable with no database present. The negative tests in
 * `Tests\Unit\DevFixtureDatabaseGuardTest` drive every refusal with literals and touch no database
 * at all, which is the only safe way to test a guard whose failure mode is writing to the wrong one.
 *
 * ON LIVING UNDER `tests/`: `Tests\` is mapped by composer's **autoload-dev**, so on a production
 * install (`composer install --no-dev`) this class does not exist and the tools that require it
 * cannot run. That is deliberate and is the strongest form of the guard -- the tools refuse to
 * start in the one environment where they would do real harm.
 */
final class DevFixtureDatabaseGuard
{
    /**
     * The sqlite files these dev tools are allowed to write to, by basename.
     *
     * `database.sqlite` is deliberately ABSENT. It is a real path in this repository and is the
     * name a developer reaches for by habit; `serve-dev.sh` records in its own header that `.env`
     * points at it and that it "does not exist. The fixture is p2dev.sqlite."
     */
    public const ALLOWED_FIXTURES = ['p2dev.sqlite'];

    /**
     * @param array<string,string|null> $connectionSources name => value, one entry per source read
     * @param array<string,string|null> $databaseSources    name => value, one entry per source read
     * @param callable(string):bool|null $fileExists        injected so the negative tests need no file
     */
    public static function assertEveryEnvironmentSourceIsSafe(
        array $connectionSources,
        array $databaseSources,
        ?callable $fileExists = null
    ): void {
        $fileExists ??= static fn (string $path): bool => is_file($path);

        // RULE 3a -- nothing named a target. Refused rather than allowed to reach `.env`, which is
        // where the real database lives.
        $named = array_filter($databaseSources, static fn ($v) => $v !== null && $v !== '');

        if ($named === []) {
            throw new RuntimeException(
                'REFUSING TO WRITE: no environment source names a database. With DB_DATABASE unset '
                . "this tool would fall through to .env, which points at the real database.\n"
                . 'Run it the way serve-dev.sh does, for example:'
                . "\n  DB_CONNECTION=sqlite DB_DATABASE=\$PWD/database/p2dev.sqlite "
                . "CACHE_STORE=file SESSION_DRIVER=file php <this tool>\n"
                . 'Do NOT `export` those -- serve-dev.sh records that exporting them destroyed the '
                . 'fixture twice. Prefix the command instead.'
            );
        }

        // RULE 1 + RULE 2 -- every source that says anything must say something safe.
        foreach ($connectionSources as $source => $value) {
            if ($value !== null && $value !== '' && $value !== 'sqlite') {
                throw new RuntimeException(
                    "REFUSING TO WRITE: environment source {$source} reports DB_CONNECTION="
                    . "'{$value}', not 'sqlite'. These dev tools write rows and grant permissions; "
                    . 'against a real connection that means inserting probe data into live records. '
                    . 'EVERY source is checked, not the first one present, because an `export` '
                    . 'survives in $_SERVER alone and Laravel reads $_SERVER first.'
                );
            }
        }

        foreach ($databaseSources as $source => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $basename = basename($value);

            if (! in_array($basename, self::ALLOWED_FIXTURES, true)) {
                throw new RuntimeException(
                    "REFUSING TO WRITE: environment source {$source} reports DB_DATABASE="
                    . "'{$value}', whose filename '{$basename}' is not a known development fixture ("
                    . implode(', ', self::ALLOWED_FIXTURES) . "). 'database.sqlite' is deliberately "
                    . 'not on that list: it is the name .env carries and it is not the fixture.'
                );
            }

            // RULE 3b -- it must already be there. A dev write tool that CREATES a database has
            // written nowhere and will say it succeeded.
            if (! $fileExists($value)) {
                throw new RuntimeException(
                    "REFUSING TO WRITE: environment source {$source} names '{$value}', which does "
                    . 'not exist. This tool will not create a database -- a missing path means the '
                    . 'path is wrong, and creating it silently is how a tool reports success having '
                    . 'written nowhere. database/seed-p2dev.php is the canonical creator.'
                );
            }
        }
    }

    /**
     * The convenience form for a bare script: reads all three sources itself and asserts them.
     *
     * `getenv()` answers `false` when unset; normalised to null so "absent" is one value, exactly
     * as `tests/bootstrap.php` does it.
     */
    public static function assertCurrentEnvironmentIsADevFixture(): void
    {
        $normalise = static function ($value): ?string {
            return ($value === false || $value === null || $value === '') ? null : (string) $value;
        };

        self::assertEveryEnvironmentSourceIsSafe(
            [
                '$_ENV' => $normalise($_ENV['DB_CONNECTION'] ?? null),
                '$_SERVER' => $normalise($_SERVER['DB_CONNECTION'] ?? null),
                'getenv()' => $normalise(getenv('DB_CONNECTION')),
            ],
            [
                '$_ENV' => $normalise($_ENV['DB_DATABASE'] ?? null),
                '$_SERVER' => $normalise($_SERVER['DB_DATABASE'] ?? null),
                'getenv()' => $normalise(getenv('DB_DATABASE')),
            ]
        );
    }
}
