<?php

namespace App\Support\Modules;

use App\Support\Filesystem\ConfinedDirectory;
use App\Support\Filesystem\PathNotConfined;
use Illuminate\Support\Facades\File;

/**
 * THE ONE PLACE THAT DECIDES WHETHER A MODULE MAY BE DESTROYED, AND WHERE ITS FILES ARE.
 *
 * `ModuleController::remove()` took a raw route segment and, with no validation beyond
 * `if (Module::find($name))`, ran three destructive statements against it: a mass
 * `Permission::where('module', $name)->delete()`, an `AddOnManager` delete, and a recursive
 * `deleteDirectory('packages/workdo/' . $name)`.
 *
 * `Module::find()` IS NOT AN EXISTENCE CHECK. Measured in `app/Classes/Module.php`: its final
 * `else` branch assigns `$this->name = $name` and returns `$this` for any string, with no
 * `module.json` and no `add_on_managers` row. So the guard was true for every input the router
 * could deliver, and two losses followed.
 *
 *  - `Base` IS NOT A MODULE. `packages/workdo/Base` does not exist; `permissions.module = 'Base'`
 *    is the CORE permission set that `helper.php:1751` reads to render the whole role-permission
 *    UI. Worse, `permissions.module` is `->default('Base')`
 *    (`2023_12_08_100022_laratrust_setup_tables.php:36`) and Laratrust's `Permission` declares its
 *    own `$fillable`, so EVERY permission created without an explicitly assigned module is a
 *    `Base` row -- which `Utility::addNewData()` confirms at `app/Models/Utility.php:3114`, a
 *    `create(['name' => ..., 'guard_name' => 'web'])` with no `module`, and that file calls itself
 *    the only permission-creation mechanism in the application. And
 *    `permission_role.permission_id` carries `onDelete('cascade')`, so deleting those rows strips
 *    the grants from every role of every tenant.
 *
 *    A CORRECTION TO AN EARLIER DRAFT OF THIS DOCBLOCK, made by independent review: it cited line
 *    23 and called the column `nullable()`. Line 23 is `roles.module`, which IS nullable; the
 *    `permissions.module` at line 36 is NOT NULL. The load-bearing half -- the `'Base'` default --
 *    was right, which is exactly how a wrong citation survives.
 *  - The directory path was RELATIVE and unvalidated -- no `basename()`, no `base_path()` -- while
 *    `Module::json()` in that same class does use `base_path()`. Whether a traversal resolves
 *    therefore depends on the server's working directory, and `public/index.php` performs no
 *    `chdir`, so that half is deployment-dependent rather than universally live.
 *
 * WHY THIS IS A CLASS AND NOT AN INLINE `if`. The contract has to hold BEFORE any database write,
 * so it must be one decision made once and reusable by any future removal door (the API, a console
 * command). Splitting it across a controller would let one door drift from another, which is how
 * `AddonController::destroy` ended up with the identical defect under its own number.
 *
 * NO NEW ENGINE. Both halves already exist in this codebase and are reused:
 *  - `InstallAddonRequest::safeDirectoryName()` -- added when `AddonController::destroy` was fixed
 *    for exactly this shape (recorded there as SEC-F5: "`$id` was interpolated straight into a
 *    recursive directory delete, so a traversal id removed arbitrary directories").
 *  - `Module::has()` -- `in_array($name, array_column($this->allModules(), 'name'))`, grounded in
 *    `File::directories(base_path('packages/workdo'))`. Measured against the booted container, it
 *    answers false for `Base`, `..`, `%2e%2e`, `../..` and `''`, and true for a real module.
 */
final class RemovableModule
{
    /**
     * Identifiers that must never be treated as a removable module.
     *
     * `Base` is here as a FAIL-SAFE, not as the primary control. The manifest check below is
     * already false for `Base` because no such directory exists -- but if anyone ever creates
     * `packages/workdo/Base/module.json`, it starts answering true and the core permission set
     * becomes deletable again. A name this consequential should not depend on a directory's
     * absence. The protected test therefore runs BEFORE the existence test, so creating that
     * directory cannot re-open the door.
     *
     * WHY THE LIST IS ONLY `Base`, asked and answered by independent review rather than assumed.
     * Two other names look like they belong here and own ZERO rows:
     *   - `General` is a UI LABEL for `Base`, not a stored value: `helper.php:1750-1751` maps
     *     'General' onto `where('module','Base')`. The manifest check refuses it anyway.
     *   - `Finance` is pushed into the module list unconditionally by `helper.php:2113-2115`, so
     *     the role editor renders a Finance tab although `packages/workdo/Finance` does not exist.
     *     Its six permissions are created by the module-less `create()` above, so they are `Base`
     *     rows and are ALREADY protected by this entry.
     * Adding `Finance` would be a fail-safe of the same kind and would protect nothing today. If
     * a `packages/workdo/Finance` is ever created, revisit this.
     *
     * @var list<string>
     */
    public const PROTECTED_MODULES = ['Base'];

    private function __construct(
        public readonly string $name,
        public readonly string $directory,
    ) {
    }

    /**
     * The modules root. A parameter so the confinement logic can be exercised against disposable
     * fixtures; production and the controller always take the default.
     */
    public static function root(): string
    {
        return base_path('packages/workdo');
    }

    /**
     * @throws ModuleNotRemovable when the candidate is anything but a verified, installed,
     *                            non-protected module whose directory is confined to the root.
     */
    public static function resolve(mixed $candidate): self
    {
        $root = self::root();
        $name = self::verifiedName($candidate, $root);

        return new self($name, self::confineWithin($root, $name));
    }

    /**
     * The identity checks, in order, with no database or filesystem effect.
     *
     * @throws ModuleNotRemovable
     */
    public static function verifiedName(mixed $candidate, ?string $root = null): string
    {
        $root ??= self::root();

        try {
            $candidate = ConfinedDirectory::safeSegment($candidate, 'module identifier');
        } catch (PathNotConfined $refusal) {
            throw new ModuleNotRemovable($refusal->getMessage(), 0, $refusal);
        }

        /*
         * REFUSED ON MISMATCH, NOT SANITISED AND CONTINUED. `safeDirectoryName()` strips every
         * character that could alter a path, so `..` becomes '' and `Api/../Other` becomes
         * `ApiOther`. ACTING on the sanitised value would destroy a module the caller did not
         * name, which is a worse outcome than refusing. So the only accepted identifier is one
         * that survives sanitising UNCHANGED.
         *
         * COMPARED AGAINST THE RAW STRING, AND THAT IS NOT PEDANTRY -- THE FIRST DRAFT OF THIS
         * METHOD TRIMMED FIRST AND WAS WRONG. PHP's `trim()` strips "\0" among its default
         * characters, so `"Api\0"` became `"Api"` and then passed every check below: a caller
         * naming a NUL-bearing identifier would have had the real `Api` module destroyed. That is
         * precisely the sanitise-and-continue behaviour the paragraph above forbids, committed in
         * the same method that forbids it. Caught by this class's own null-byte data case.
         *
         * A consequence worth stating: surrounding whitespace is refused too (`" Api "` does not
         * survive unchanged). That is intended. Guessing which module a caller meant is the
         * behaviour being removed.
         */
        foreach (self::PROTECTED_MODULES as $protected) {
            if (strcasecmp($candidate, $protected) === 0) {
                throw new ModuleNotRemovable('"' . $protected . '" is protected and is not a removable module');
            }
        }

        /*
         * THE REAL EXISTENCE CHECK: a module is installed when it has a MANIFEST, not merely when
         * a directory of that name exists. By this line `$candidate` holds only `[A-Za-z0-9_-]`,
         * so interpolating it into a path is safe, and `$root` is a parameter so this is pure --
         * no database, no container state, and exercisable against a disposable fixture.
         *
         * WHY NOT `Module::has()`, which was the first choice, and why not `Module::json()`, which
         * was the second. Both are grounded in the same listing and both answer correctly -- but
         * `has()` reaches the DATABASE (`allModules()` -> `moduleArr()` -> `find()` ->
         * `AddOnManager::where(...)->first()`, once per module), which made a Unit test of it die
         * on "no such table: add_on_managers"; and `json()` hardcodes `base_path()`, so neither
         * could be pointed at a fixture. THAT MATTERED: with the check untestable, a mutation
         * reverting it to `Module::find()` truthiness SURVIVED both route tests, because the
         * protected list catches `Base` and the confinement check catches an unknown name. The
         * guard was real and nothing pinned it. One `File::exists` against an injectable root is
         * not a parallel engine; it is the same question asked where it can be answered.
         *
         * It is also STRICTER than a directory check: a bare directory with no manifest -- a
         * half-extracted upload, a leftover temp folder -- is not a module. Measured: all 70
         * installed modules carry `module.json`, so nothing removable is refused by requiring one.
         *
         * Case is deliberately not normalised: the filesystem this ships on may be case-sensitive,
         * so accepting a different casing would hand a non-existent path onward.
         */
        if (! File::exists($root . DIRECTORY_SEPARATOR . $candidate . DIRECTORY_SEPARATOR . 'module.json')) {
            throw new ModuleNotRemovable('no installed module is named "' . $candidate . '"');
        }

        return $candidate;
    }

    /**
     * The canonical, symlink-resolved directory for `$name`, proven to sit inside `$root`.
     *
     * `realpath()` does the work that matters: it resolves `.`, `..` AND symlinks, and returns
     * false for a path that does not exist. So a module directory that is a symlink pointing
     * outside the root fails the prefix test rather than being followed.
     *
     * WHAT THIS DOES **NOT** COVER, stated because an earlier draft of this docblock claimed the
     * whole "unsafe symlink resolution" requirement and only ever held one end of it: a symlink
     * NESTED INSIDE an accepted module. This method canonicalises `$root/$name` and nothing below
     * it. That half is closed where it actually bites, in `ModuleController::deleteDirectory()`,
     * which now unlinks a link instead of recursing through it.
     *
     * PRECONDITION: `$name` is a single path segment that `verifiedName()` has returned. Called
     * directly with a nested path, this method accepts it -- `confineWithin($root, 'Api/src')`
     * resolves inside the root and passes every check. `resolve()` composes the two in the right
     * order so that cannot happen; a new caller must do the same.
     *
     * @throws ModuleNotRemovable
     */
    public static function confineWithin(string $root, string $name): string
    {
        try {
            return ConfinedDirectory::within($root, $name, 'modules root', 'module directory');
        } catch (PathNotConfined $refusal) {
            throw new ModuleNotRemovable($refusal->getMessage(), 0, $refusal);
        }
    }
}
