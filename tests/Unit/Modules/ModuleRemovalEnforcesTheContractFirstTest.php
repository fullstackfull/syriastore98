<?php

namespace Tests\Unit\Modules;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * THE ORDER OF `ModuleController::remove()`, PINNED STRUCTURALLY BECAUSE IT CANNOT BE DRIVEN.
 *
 * The requirement this file exists for is an ordering one: "the security contract must be enforced
 * BEFORE any database modification, permission deletion, module removal or filesystem operation."
 * `ModuleRemovalRefusesWhatItCannotRemoveTest` proves the two REFUSAL paths change nothing, and
 * `RemovableModuleTest` proves the contract itself against disposable directories. Neither can
 * prove anything about the ACCEPT path, because driving it for the only kind of input it accepts --
 * a really installed module -- recursively deletes that module out of the vendored tree. There is
 * no safe behavioural probe, so the alternative to a structural one is no coverage at all.
 *
 * THIS IS A RATCHET, NOT A DESCRIPTION. It fails when a future edit adds a destructive statement
 * above the resolve, or quietly restores the relative path, or takes the deletes back out of the
 * transaction. Two mutations earned it: reverting `$removable->directory` to
 * `'packages/workdo/' . $removable->name` SURVIVED the whole suite, and the transaction wrapper had
 * no test at all.
 *
 * WHY THE RELATIVE PATH MATTERS EVEN THOUGH THE NAME IS NOW SAFE. By the time the controller has a
 * `$removable`, the name holds only `[A-Za-z0-9_-]`, so a traversal can no longer be smuggled
 * through it -- the relative form is not a traversal hole any more. It is a SILENT-PARTIAL hole:
 * `deleteDirectory()` returns false at its own `is_dir` check when the working directory is not the
 * repository root, and `public/index.php` performs no `chdir`. The rows would be deleted and the
 * files left behind, with the handler reporting success -- which is the "silently proceeding after
 * partial validation" the requirement forbids, arrived at from the other end.
 *
 * COMMENTS ARE STRIPPED AND THE SEARCH IS SCOPED TO THE METHOD, both because CLAUDE.md records four
 * censuses in one day that were satisfied by prose about the thing they measured -- and this method
 * is commented heavily enough to satisfy every assertion below on its explanatory text alone. The
 * scoping matters just as much: `ModuleController` has three OTHER `deleteDirectory()` calls, in the
 * install path, and an unscoped assertion would read them instead.
 *
 * WHAT THIS FILE STILL DOES NOT PIN, named by independent review and recorded rather than papered
 * over. These assertions read the method's SHAPE, so a mutation that leaves the shape intact and
 * changes the OUTCOME survives: most concretely, a `remove()` that always takes the `catch` branch
 * would still contain the resolve, the transaction and the confined path in the right order. The
 * route's accept path is behaviourally untested because `RemovableModule::resolve()` takes no
 * injectable root (`verifiedName()` does), so a route test cannot be pointed at a disposable
 * directory, and pointing it at a real module deletes that module.
 *
 * NOT CLOSED BY ADDING A TEST-ONLY PARAMETER TO PRODUCTION CODE, deliberately. The residual risk is
 * that a future edit breaks removal for every module -- which fails CLOSED, is not a security
 * failure, and shows up the first time an operator uses the screen. The cost of the alternative is a
 * root parameter on a destructive API that exists only so a test can aim it somewhere else, and that
 * is a worse trade on this particular method.
 */
class ModuleRemovalEnforcesTheContractFirstTest extends TestCase
{
    use StripsSourceComments;

    private const CONTROLLER = 'app/Http/Controllers/ModuleController.php';

    /** The body of `remove()` only, with every comment already removed. */
    private function removeMethodBody(): string
    {
        $code = $this->sourceWithoutComments(self::CONTROLLER);

        $start = strpos($code, 'public function remove(');
        $this->assertNotFalse($start, 'premise: remove() must exist in ' . self::CONTROLLER);

        // The method runs to the next declaration at the same level.
        $end = strpos($code, 'public function ', $start + 10);
        $this->assertNotFalse($end, 'premise: another method must follow remove()');

        $body = substr($code, $start, $end - $start);

        $this->assertStringNotContainsString('/*', $body, 'premise: comments must really be stripped');
        $this->assertGreaterThan(200, strlen($body), 'premise: the extracted body is not empty');

        return $body;
    }

    /**
     * EVERY DESTRUCTIVE STATEMENT MUST SIT BELOW THE RESOLVE.
     *
     * Asserted as positions rather than as presence, because presence is what the original defect
     * already satisfied: `Module::find()` was *there*, above the deletes, and was not a guard.
     */
    #[Test]
    public function test_the_contract_is_resolved_before_any_destructive_statement(): void
    {
        $body = $this->removeMethodBody();

        $resolve = strpos($body, 'RemovableModule::resolve(');
        $this->assertNotFalse($resolve, 'remove() no longer resolves the removal contract at all.');

        $destructive = [
            'the permission mass delete' => "Permission::where('module'",
            'the add-on row delete'      => 'AddOnManager::where(',
            'the recursive directory delete' => 'deleteDirectory(',
            'the module disable'         => '->disable()',
        ];

        foreach ($destructive as $what => $needle) {
            $at = strpos($body, $needle);

            $this->assertNotFalse($at, 'premise: ' . $what . ' must still be in remove()');
            $this->assertGreaterThan(
                $resolve,
                $at,
                $what . ' now runs BEFORE RemovableModule::resolve(). The contract has to hold '
                . 'before the first write, or a refused identifier has already changed something '
                . 'by the time it is refused.'
            );
        }
    }

    /**
     * The refusal must RETURN, not fall through. A `catch` that logged and continued would satisfy
     * the ordering assertion above while leaving every destructive line reachable.
     */
    #[Test]
    public function test_a_refusal_returns_instead_of_continuing(): void
    {
        $body = $this->removeMethodBody();

        $catch = strpos($body, 'catch (ModuleNotRemovable');
        $this->assertNotFalse($catch, 'remove() no longer catches the refusal.');

        $afterCatch = substr($body, $catch, strpos($body, 'DB::transaction') - $catch);

        $this->assertStringContainsString(
            'return redirect()',
            $afterCatch,
            'The refusal branch does not return. A caught refusal that falls through leaves every '
            . 'destructive statement below it reachable with an unverified identifier.'
        );
    }

    /**
     * THE DIRECTORY ARGUMENT IS THE CONFINED PATH, NOT A STRING BUILT IN THE CONTROLLER.
     */
    #[Test]
    public function test_the_directory_delete_uses_the_confined_canonical_path(): void
    {
        $body = $this->removeMethodBody();

        $this->assertStringContainsString(
            '$this->deleteDirectory($removable->directory)',
            $body,
            'The recursive delete no longer takes the canonical root-confined directory that '
            . 'RemovableModule::confineWithin() proved sits inside packages/workdo.'
        );

        $this->assertStringNotContainsString(
            "'packages/workdo/'",
            $body,
            'remove() builds a RELATIVE module path again. deleteDirectory() resolves it from the '
            . "server's working directory, and public/index.php performs no chdir -- so the rows "
            . 'are deleted and the files are not, with the handler reporting success.'
        );
    }

    /**
     * THE ADD-ON ROW DELETE MUST READ `module`, NOT `name`.
     *
     * `add_on_managers` keeps two different strings: `ModuleController` writes
     * `$addon->module = $data['name']` and `$addon->name = $data['alias']` at :142-143, :173-174
     * and :414-415. MEASURED over all 70 vendored manifests: `name` equals the directory for
     * 70/70, `alias` differs for 62 of 70. So `where('name', <directory>)` matched 8 modules at
     * most and orphaned the row for the other 62, which then blocks re-installation because :411
     * finds the module by `module` and skips creation.
     *
     * Asserted structurally for the same reason as the rest of this file: the accept path cannot be
     * driven without deleting a real vendored module. Found by independent review.
     */
    #[Test]
    public function test_the_addon_row_is_deleted_by_the_module_column_not_the_alias(): void
    {
        $body = $this->removeMethodBody();

        $this->assertStringContainsString(
            "AddOnManager::where('module', \$removable->name)",
            $body,
            'The add-on delete no longer reads the `module` column. `name` holds the manifest '
            . 'ALIAS, which differs from the directory name for 62 of the 70 installed modules, so '
            . 'that predicate leaves an orphan row that blocks re-installation.'
        );

        $this->assertStringNotContainsString(
            "AddOnManager::where('name'",
            $body,
            'The add-on delete reads the alias column again.'
        );
    }

    /** The two row deletes stay together, so a failure in the second cannot leave the first applied. */
    #[Test]
    public function test_the_two_row_deletes_stay_inside_one_transaction(): void
    {
        $body = $this->removeMethodBody();

        $open = strpos($body, 'DB::transaction(');
        $this->assertNotFalse($open, 'The two row deletes are no longer wrapped in a transaction.');

        $closed = strpos($body, '});', $open);
        $this->assertNotFalse($closed, 'premise: the transaction closure must close');

        $inside = substr($body, $open, $closed - $open);

        $this->assertStringContainsString("Permission::where('module'", $inside,
            'The permission mass delete moved OUT of the transaction.');
        $this->assertStringContainsString('AddOnManager::where(', $inside,
            'The add-on row delete moved OUT of the transaction.');

        /*
         * And the filesystem delete stays out of it: an unlink cannot join a database transaction,
         * so including it would make a rollback leave a module whose files are already gone.
         */
        $this->assertStringNotContainsString('deleteDirectory(', $inside,
            'The recursive directory delete is now inside the transaction. A filesystem unlink '
            . 'cannot be rolled back, so a rolled-back transaction would leave a module whose rows '
            . 'survive and whose files do not.');
    }
}
