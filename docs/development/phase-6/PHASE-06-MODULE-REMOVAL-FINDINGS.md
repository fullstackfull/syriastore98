# Phase 06 — the module-removal door, and what it taught about guards

**Status: FIXED.** This document describes a defect that is closed in the same change that added it
to the repository. It is written after the patch, not before, because this repository is public.

Scope: `ModuleController::remove()`, the handler behind `POST /remove-modules/{module}`. Phase 06
`CTRL-002` is "إطلاق تدريجي وإيقاف آمن" — gradual rollout and **safe shutdown** — and `CTRL-004` is
"do not delete a feature's data merely because its availability is switched off". This handler is the
shutdown door and it had no validation of any kind.

---

## 1. `Module::find()` is not an existence check

`app/Classes/Module.php`'s `find()` ends in an `else` branch that assigns `$this->name = $name` and
returns `$this` — for any string, with no `module.json` and no `add_on_managers` row. The handler
read:

```php
$module = Module::find($moduleName);
if ($module) { /* three destructive statements */ }
```

so the guard was **true for every input the router could deliver**. Verified by execution against
the booted container, not inferred.

What followed it, in order, with no transaction:

| | |
|---|---|
| `Permission::where('module', $moduleName)->delete()` | a mass delete keyed on the route segment |
| `AddOnManager::where('name', $moduleName)->delete()` | see §5 — this one read the wrong column |
| `$this->deleteDirectory('packages/workdo/' . $moduleName)` | a recursive delete on a **relative** path |

### 1.1 `Base` is not a module — it is the core permission set

`packages/workdo/Base` does not exist. `permissions.module = 'Base'` is what `helper.php:1751` reads
to render the entire role-permission UI. Two facts make that worse than it first looks:

- `permissions.module` is `->default('Base')`
  (`database/migrations/2023_12_08_100022_laratrust_setup_tables.php:36`) and Laratrust's
  `Permission` declares its own `$fillable`, which Laravel honours over the subclass's
  `$guarded = []`. So `module` is silently dropped from a `create()` array and the default applies.
  `Utility::addNewData()` — which that file calls the only permission-creation mechanism in the
  application — does exactly that at `app/Models/Utility.php:3114`. **Every permission created
  without an explicitly assigned module is a `Base` row.**
- `permission_role.permission_id` carries `onDelete('cascade')` (same migration, `:70-71`), so
  deleting those rows **also strips the grant from every role of every tenant**.

One request, platform-wide authorization loss. Confirmed by driving the real route: the permission
row was gone, the tenant role's grant was gone with it, and the handler reported success.

### 1.2 Severity, stated honestly

The route is gated by `isAbleTo('Module remove')`, which `Utility.php`'s `$superadminNewPermission`
seeds only to the `super admin` role, and `RolePolicy::isGrantableBy` refuses every
`config/superadminaccess.php` name — so a merchant cannot grant it to themselves. **This is an
operator-reachable destructive door, not a privilege escalation.** It is worth closing because an
operator typo, a stale bookmark or a crawled form should not be able to delete the platform's
authorization model.

### 1.3 The traversal half is deployment-dependent

`deleteDirectory('packages/workdo/' . $moduleName)` had no `basename()` and no `base_path()`, while
`Module::json()` in the same class *does* use `base_path()`. Whether a traversal resolves therefore
depends on the server's working directory, and `public/index.php` performs no `chdir`. Recorded as
deployment-dependent rather than claimed as universally live.

---

## 2. The fix: one decision point, before the first write

`app/Support/Modules/RemovableModule.php` throws `ModuleNotRemovable` unless the candidate is a
verified, installed, non-protected module whose directory canonically resolves inside
`packages/workdo`:

| Check | What it refuses |
|---|---|
| `is_string`, non-empty | a non-string or empty identifier |
| `safeDirectoryName($c) === $c` | anything but `^[A-Za-z0-9_-]+$`, **compared against the raw string** |
| `PROTECTED_MODULES`, case-insensitively | `Base` in every spelling |
| `module.json` exists under the root | a directory with no manifest — a half-extracted upload is not a module |
| `realpath()` + prefix test | a path resolving to the root itself or outside it, symlinks included |

A typed exception rather than a falsy return, because a falsy-return contract is exactly the shape
that failed. The handler catches it and returns the same generic message the old `else` branch
returned, so a refusal does not tell the caller which check they tripped.

**No new engine.** `InstallAddonRequest::safeDirectoryName()` already existed — it was added when
`AddonController::destroy` was fixed for this identical shape, recorded there as SEC-F5.

---

## 3. Three things my own first draft got wrong

Recorded because each is a reusable lesson, and two were caught by tooling rather than by reading.

**A NUL byte normalised into a valid module name.** The first draft compared
`safeDirectoryName(trim($candidate))` against `trim($candidate)`. PHP's `trim()` strips `"\0"` among
its defaults, so `"Api\0"` became `"Api"` and passed every check below — a caller naming a NUL-bearing
identifier would have had the real `Api` module destroyed. That is precisely the
sanitise-and-continue behaviour the method's own comment forbids, committed in the method that
forbids it. The fix compares the **raw** string; surrounding whitespace is now refused too, which is
intended, because guessing which module a caller meant is the behaviour being removed.

**Two tests passed and proved nothing.** CLAUDE.md records that Laratrust's `permissions_as_gates`
is false and there is no super-admin bypass, so `isAbleTo()` alone **refuses the platform operator**.
A `type = 'super admin'` fixture with no Laratrust role got `isAbleTo('Module remove') = false` and
the handler answered "Permission denied." — every destructive line was unreachable and the
assertions were satisfied by the gate. The fixture now builds the role the way production does and
**asserts `isAbleTo` is true as a premise**.

**A fixture was vacuous.** `Permission::create([... 'module' => 'NotAModule'])` stored
`module = 'Base'`, for the `$fillable` reason in §1.1 — so the row the test meant to protect was
never in the module it claimed, the delete matched nothing, and the assertion passed while the
handler was wide open. Found by dumping the raw row. The fixture now assigns `module` directly and
asserts the stored value.

---

## 4. The hole the first fix left, found by independent review

`confineWithin()` canonicalises the **module directory** and proves it sits inside the root. It says
nothing about a symlink **nested inside** an accepted module — and `deleteDirectory()` decided with
`is_dir($path)`, which follows links. So a link inside an otherwise legitimate module had every file
under its target unlinked *through* it, and the link itself survived (`rmdir` on a symlink fails
ENOTDIR), so the only symptom was a removal that "did not finish".

**Latent, not live, and measured rather than assumed:** `find packages/workdo -type l` returns zero
across all 70 modules; no first-party code calls `symlink()`; and neither way a module arrives can
plant one — `ZipArchive::extractTo()` writes a symlink entry as a regular *file* holding the target
path, and `moveExtractedFiles()` moves with `rename()`, which preserves that.

Closed with **one** `is_link` check at `deleteDirectory()`'s single entry point, which also covers
the three install-path callers and the top-level TOCTOU (the module directory swapped for a link
after `realpath()` proved it real). A first version also tested each scanned child; a mutation showed
dropping that changed nothing — `is_dir` follows the link, the recursion re-enters with the link as
`$dirPath`, and the entry check fires there. **The child check was code no test could distinguish
from its absence, so it was removed rather than left to rot.**

---

## 5. A wrong-column delete, pre-existing, fixed here

`AddOnManager::where('name', ...)` read the alias, not the identifier. `ModuleController` keeps them
apart itself: `$addon->module = $data['name']` and `$addon->name = $data['alias']` at `:142-143`,
`:173-174` and `:414-415`.

**Measured over all 70 vendored manifests:** `name` equals the directory name for **70 of 70**;
`alias` differs for **62 of 70** (`ProductReview` → "Product Review", `LandingPage` → "CMS",
`BackupRestore` → "Backup & Restore"). So the old predicate matched at most 8 modules and orphaned
the row for the other 62 — which then **blocks re-installation**, because `:411` finds the module by
`module` and skips creation, leaving the re-installed module disabled.

This is a behaviour change and is meant to be: the row now really goes. `module` is the canonical
identifier in this very file — `:423` passes it to `Module::moduleCacheForget()`.

---

## 6. Evidence

Failing-first for every test. **15 mutations applied, 15 CAUGHT:**

| Mutation | |
|---|---|
| the trimmed-value comparison (my own first-draft bug) | CAUGHT |
| drop the protected-module list | CAUGHT |
| confinement stops resolving symlinks | CAUGHT |
| existence check reverts to `find()` truthiness — *the original defect* | CAUGHT |
| existence check weakened to a directory check | CAUGHT |
| existence check always refuses | CAUGHT |
| the controller rebuilds the relative path | CAUGHT |
| drop the `DB::transaction` | CAUGHT |
| pull the filesystem delete **into** the transaction | CAUGHT |
| the refusal falls through instead of returning | CAUGHT |
| a destructive statement is hoisted above the resolve | CAUGHT |
| drop the symlink guard → nested escape | CAUGHT |
| drop the symlink guard → top-level TOCTOU | CAUGHT |
| the guard fires for everything, so no module is ever removed | CAUGHT |
| the add-on delete reads the alias column again | CAUGHT |

Two of these were **SURVIVORS first**, and that is the point of running them: the existence check
turned out to be untested (the protected list caught `Base`, confinement caught an unknown name) and
the redundant child `is_link` could not be pinned at all. The first got the two tests it was missing;
the second was deleted.

```
tests/Unit/Modules/ + tests/Feature/Modules/   40 passed (2,299 assertions)
Unit suite                                     6,120 passed, 1 skipped (37,312 assertions)
Contract suite                                 45 passed (182 assertions)
modules intact after every run                 70
```

**No destructive test ever pointed at a real path.** `POST /remove-modules/..` would have resolved to
`packages/` from the test runner's working directory and deleted all 70 vendored modules; `.../Api`
would have deleted a real one. The traversal, symlink and accept cases are therefore proved in
`tests/Unit/Modules/RemovableModuleTest.php` against directories the test creates and removes itself,
and the file says so. The two refusal cases are safe to drive because `packages/workdo/Base` and
`packages/workdo/NotAModule` do not exist, so `deleteDirectory` returns false at its own `is_dir`
check and only the database half executes — which is the half they assert.

---

## 7. Recorded, not fixed

- **A module whose manifest `name` differs from its directory is now un-removable from the UI.** The
  Remove form posts the manifest name while the resolver requires the *directory* to carry it. All 70
  vendored manifests agree, so nothing shipped regresses — but `install()` names the directory from
  the zip filename independently of the manifest, so an upload `Foo.zip` declaring `"name":"Bar"`
  produces a module whose Remove button refuses. Latent, low, and the new behaviour is the safer of
  the two: previously it half-worked, deleting rows and leaving the files.
- **The route's accept path is pinned structurally, not behaviourally.**
  `tests/Unit/Modules/ModuleRemovalEnforcesTheContractFirstTest.php` asserts the method's shape —
  resolve before every destructive statement, the refusal returns, the deletes are in one
  transaction, the directory argument is the confined path, the add-on delete reads `module`. It
  cannot assert the outcome, because `resolve()` takes no injectable root and aiming a route test at
  a real module deletes it. Adding a root parameter to a destructive API purely so a test can aim it
  elsewhere is the worse trade; the residual risk is that removal breaks for every module, which
  fails **closed**. Stated in that file's own docblock too.
- **`PROTECTED_MODULES` is only `Base`, and that was checked rather than assumed.** `General` is a UI
  label for `Base` (`helper.php:1750-1751`), not a stored value. `Finance` is pushed into the module
  list unconditionally by `helper.php:2113-2115` although `packages/workdo/Finance` does not exist —
  but its six permissions are created by the module-less `create()` in §1.1, so they are `Base` rows
  and are already protected. Both are refused by the manifest check anyway.
- **The gate asymmetry is deliberate.** `remove()` gates on `isAbleTo('Module remove')` with no
  operator limb, while `enable()` has one. For the most destructive door on the platform the narrower
  gate is the better side of that asymmetry, and it must not be "harmonised" later.
