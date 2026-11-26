<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * MARKET-005 — THE STRUCTURAL HALF: nothing in a suspension or removal path destroys merchant data.
 *
 * `SuspendingAnAppKeepsMerchantDataTest` drives the doors and asserts the rows survive. That is the
 * behavioural half, and it can only ever cover the tables a test thought to seed. This is the half
 * that covers the ones nobody thought of.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * IT ENUMERATES THE SAFE SET, AND THAT IS NOT A STYLE PREFERENCE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * CLAUDE.md §3: *"WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD. A
 * list of dangerous spellings is always short by the one nobody thought of"* — the record there is a
 * raw-SQL census that reported ONE dynamic site where there were FIFTEEN, after a live cross-tenant
 * injection had already been found among them.
 *
 * So this does not look for dangerous deletes. It finds EVERY destructive statement in the
 * suspension and removal method bodies and requires each one's target to be in
 * `PERMITTED_BOOKKEEPING`. A delete of anything else — a new table, a table that did not exist when
 * this was written, a spelling nobody anticipated — fails by DEFAULT.
 *
 * THE ALLOWLIST IS THE DECISION TEXT, NOT A CONVENIENCE. `MARKET-005` reads
 * "تعليق التطبيقات أو سحب صلاحياتها دون إتلاف بيانات التاجر" — suspend applications, or withdraw
 * **their permissions**, without destroying merchant data. So:
 *
 *   `permissions`          the module's own permission definitions. Withdrawing them is the verb the
 *                          decision uses. `RemovableModule` is what keeps `Base` out of reach.
 *   `add_on_managers`      the platform's record that the module is managed. Not merchant content.
 *   `user_active_modules`  the per-merchant entitlement. Withdrawing it is the same verb.
 *
 * Nothing else may be deleted on these paths, and the three that may are bookkeeping about
 * permission and entitlement — never a row a merchant created.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * SCOPED TO THE METHOD BODY, FOR A MEASURED REASON
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * CLAUDE.md §3 again: *"Scope the search to the block too"* — an assertion over a whole file answers
 * "does this string exist anywhere", and a mutation dropping one component's rule survived because
 * another component had the same rule. `ModuleController` is 580 lines and contains unrelated
 * deletes; a file-wide census would pass or fail for the wrong reasons. Each method body is
 * extracted by brace matching, and `test_every_named_method_was_actually_found` fails if a method is
 * renamed, so this census cannot quietly start measuring nothing.
 */
class SuspensionDestroysNothingCensusTest extends TestCase
{
    use StripsSourceComments;

    /** The only tables a suspension, withdrawal or removal path may delete from. */
    private const PERMITTED_BOOKKEEPING = [
        'Permission',
        'AddOnManager',
        'userActiveModule',
        'Addon',
    ];

    /** file => [methods that suspend, withdraw or remove an application]. */
    private const PATHS = [
        'app/Http/Controllers/ModuleController.php' => ['enable', 'remove', 'Check_Child_Module'],
        'app/Http/Controllers/AddonController.php' => ['destroy', 'ThemeEnable'],
        'app/Classes/Module.php' => ['disable'],
        'app/Models/User.php' => ['assignPlan'],
    ];

    /**
     * The schema-destroying primitives, asserted ABSENT across the whole application.
     *
     * This is the one place a blacklist is right, because the claim is global and absolute rather
     * than a judgement about a particular call: a module's tables are never dropped and its
     * migrations are never rolled back, anywhere in first-party code. Measured 2026-11-26: every one
     * of these is 0 occurrences in `app/`.
     */
    private const SCHEMA_DESTROYERS = [
        'migrate:rollback',
        'migrate:reset',
        'migrate:fresh',
        'Schema::drop',
        'dropIfExists',
        'dropColumn',
        'DROP TABLE',
    ];

    /** @return array<string,string> method name => body, comments stripped */
    private function bodies(string $file, array $methods): array
    {
        $source = $this->sourceWithoutComments($file);
        $out = [];

        foreach ($methods as $method) {
            $start = strpos($source, 'function ' . $method . '(');

            if ($start === false) {
                continue;
            }

            $open = strpos($source, '{', $start);

            if ($open === false) {
                continue;
            }

            $depth = 0;
            $length = strlen($source);

            for ($i = $open; $i < $length; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $out[$method] = substr($source, $open, $i - $open + 1);
                        break;
                    }
                }
            }
        }

        return $out;
    }

    #[Test]
    public function test_every_named_method_was_actually_found(): void
    {
        foreach (self::PATHS as $file => $methods) {
            $found = array_keys($this->bodies($file, $methods));

            $this->assertSame(
                $methods,
                $found,
                "premise: {$file} must still declare every method this census measures, or the census measures nothing."
            );
        }
    }

    #[Test]
    public function test_no_suspension_path_deletes_from_anything_but_bookkeeping(): void
    {
        $offenders = [];

        foreach (self::PATHS as $file => $methods) {
            foreach ($this->bodies($file, $methods) as $method => $body) {
                /*
                 * Every destructive terminator, with the expression that leads up to it. The
                 * expression is captured back to the start of the statement so the TARGET is
                 * visible whatever the spelling: `Model::where(...)->delete()`,
                 * `DB::table('x')->delete()`, `$var->delete()`, `Model::destroy(...)`,
                 * `->truncate()`, `->forceDelete()`.
                 */
                preg_match_all(
                    '/([^;{}]*?)->\s*(delete|forceDelete|truncate)\s*\(/s',
                    $body,
                    $matches,
                    PREG_SET_ORDER
                );

                preg_match_all('/([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::\s*destroy\s*\(/s', $body, $destroys, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $expression = trim($match[1]);

                    if ($this->targetsPermittedBookkeeping($expression, $body)) {
                        continue;
                    }

                    $offenders[] = $file . '::' . $method . ' -> ' . preg_replace('/\s+/', ' ', $expression) . '->' . $match[2] . '()';
                }

                foreach ($destroys as $destroy) {
                    if ($this->targetsPermittedBookkeeping($destroy[1], $body)) {
                        continue;
                    }

                    $offenders[] = $file . '::' . $method . ' -> ' . $destroy[1] . '::destroy()';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "MARKET-005: a suspension, withdrawal or removal path deletes from something that is not "
            . "permission or entitlement bookkeeping. The decision text allows withdrawing an app's "
            . "permissions and nothing else; merchant data must survive. Offending statements:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * `deleteDirectory` IS A FILESYSTEM DELETE AND IS A DIFFERENT QUESTION, declared here so it is
     * not mistaken for an omission.
     *
     * `ModuleController::remove()` and `AddonController::destroy()` both delete the package's own
     * directory, which is the point of an uninstall. Neither is merchant DATA, and both are confined
     * before they run — `RemovableModule::resolve()` for the module and
     * `InstallAddonRequest::safeDirectoryName()` for the theme. This asserts the confinement is
     * still what stands in front of them, because a recursive delete over an unconfined path is how
     * SEC-F5 removed arbitrary directories.
     */
    #[Test]
    public function test_every_directory_delete_on_a_removal_path_is_confined_first(): void
    {
        $module = $this->bodies('app/Http/Controllers/ModuleController.php', ['remove'])['remove'];
        $theme = $this->bodies('app/Http/Controllers/AddonController.php', ['destroy'])['destroy'];

        $this->assertStringContainsString('RemovableModule::resolve(', $module);
        $this->assertStringContainsString('$this->deleteDirectory($removable->directory)', $module);
        $this->assertStringNotContainsString(
            "deleteDirectory(base_path('packages/workdo/' . \$moduleName",
            $module,
            'The directory must come from the resolved, confined module and never from the route segment.'
        );

        $this->assertStringContainsString('InstallAddonRequest::safeDirectoryName(', $theme);
        $this->assertStringContainsString("File::deleteDirectory(base_path('themes/' . \$safeId))", $theme);
    }

    #[Test]
    public function test_no_first_party_code_drops_a_table_or_rolls_a_migration_back(): void
    {
        $hits = [];

        foreach ($this->phpFilesUnder(dirname(__DIR__, 3) . '/app') as $path) {
            $relative = 'app' . substr($path, strlen(dirname(__DIR__, 3) . '/app'));
            $code = $this->sourceWithoutComments($relative);

            foreach (self::SCHEMA_DESTROYERS as $needle) {
                if (str_contains($code, $needle)) {
                    $hits[] = $relative . ': ' . $needle;
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "MARKET-005: a module's tables must never be dropped and its migrations must never be "
            . "rolled back by application code — that is how a suspension would destroy merchant "
            . "data. If one of these is genuinely needed, it is an owner escalation, not a code "
            . "change. Found:\n  " . implode("\n  ", $hits)
        );
    }

    /**
     * Is this statement's target one of the permitted bookkeeping models?
     *
     * A BARE VARIABLE IS RESOLVED BACK TO ITS ASSIGNMENT, and the first version of this census had
     * to learn that from a failure: `AddonController::destroy()` ends in `$theme->delete()`, where
     * `$theme` is an `Addon` loaded four lines earlier. That is precisely the spelling CLAUDE.md
     * warns a dangerous-pattern census misses — the raw-SQL census that reported one site of fifteen
     * was blind to `sprintf`, `implode` and "a bare variable as the whole expression".
     *
     * UNRESOLVABLE STILL MEANS NOT PERMITTED. The lookup is a single assignment inside the SAME
     * method body; a variable assigned from a parameter, a relation, a helper or another method
     * cannot be resolved here and the statement is reported. That is the right direction: a census
     * that guessed would be a census that passes.
     */
    private function targetsPermittedBookkeeping(string $expression, string $body = ''): bool
    {
        foreach (self::PERMITTED_BOOKKEEPING as $permitted) {
            if (preg_match('/(^|[^A-Za-z0-9_\\\\])' . preg_quote($permitted, '/') . '\s*::/', $expression)) {
                return true;
            }
        }

        if ($body !== '' && preg_match('/^(\$[A-Za-z_][A-Za-z0-9_]*)$/', $expression, $variable)) {
            $assignment = '/' . preg_quote($variable[1], '/') . '\s*=\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\s*::/';

            if (preg_match($assignment, $body, $assigned)) {
                return in_array($assigned[1], self::PERMITTED_BOOKKEEPING, true);
            }
        }

        return false;
    }

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
