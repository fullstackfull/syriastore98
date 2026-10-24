# P1-OWN-01 — BackupRestore: what "disabled" protects, and the containment that is ready to apply

**Status: verification complete. Nothing in this document has been applied to any server, and the
module has not been enabled anywhere.** GitHub issue #42. Queue row S1.

The owner confirmed on **2026-10-19** that the BackupRestore module is **disabled** on the Store
Syria server. That confirmation is about the server's state. This document establishes what the
*code* does with each possible meaning of "disabled", because the answers differ and only one of
them is safe.

Everything below marked **measured** comes from
`tests/Support/Security/bin/verify-backuprestore-module-gating.php` — three application boots in
three subprocesses, one per module state, against throwaway sqlite files. **6 checks, 0 failures.**
It is a standalone script and not a test for the reason CLAUDE.md records: no WorkDo module loads
in any test run, so an in-process assertion that "the routes are absent" cannot fail and proves
nothing. `CredentialBoundaryTest:504-511` records that exact vacuous assertion having guarded the
queue's highest-severity row.

---

## 1. "Disabled" has two meanings and only one is safe

| server state | backuprestore routes registered? | measured |
|---|---|---|
| **no `add_on_managers` row** | **no** — the service provider never loads | yes |
| **row present, `is_enable = 0`** | **YES — all eight, identical to fully enabled** | yes |
| row present, `is_enable = 1` | yes | yes |

`PackageServiceProvider::loadDynamicAddons()` does `AddOnManager::pluck('module')` with **no
`is_enable` predicate** (`app/Providers/PackageServiceProvider.php:47`). A row's **existence**
loads the module and registers its routes. The flag does not enter into it.

**So the first thing to check on the server is whether there is a row at all**, not whether the
flag is off.

## 2. The three sources that disagree

P1-OWN-01 asked for this to be documented explicitly, because the disagreement is the finding.

1. **`add_on_managers` row existence** → gates the **loader**, and therefore the route table.
   `PackageServiceProvider.php:47`. **Measured: this is the only source that changes what is
   reachable.**
2. **`add_on_managers.is_enable`** → gates `App\Classes\Module::isEnabled()` only
   (`app/Classes/Module.php:138-144`). **Measured: it changes nothing about the route table.**
3. **`module_is_active($name)`** → gates `PlanModuleCheck` for non-operators, and is
   **principal-dependent** on top of (2): `false` with no principal (`app/Helper/helper.php:1338`),
   `true` **unconditionally** for a `super admin` (`:1328-1330`), otherwise `ActivatedModule()`.

Each is pinned by a ratchet in `tests/Feature/Security/BackupRestoreModuleGatingTest.php`, written
to strip comment lines before matching — because a ratchet that greps source can otherwise be
satisfied by a docblock quoting the thing it scans for, which is exactly what P1-INV-08 did to
`BulkDeleteAuthorizationTest`.

## 3. The exposure that would exist if the module were enabled

**Exact routes and middleware, as registered (measured):**

| method | uri | middleware |
|---|---|---|
| GET | `backuprestore` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| GET | `backuprestore/create` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| POST | `backuprestore` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| GET | `backuprestore/{backuprestore}` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| GET | `backuprestore/{backuprestore}/edit` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| PUT | `backuprestore/{backuprestore}` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| DELETE | `backuprestore/{backuprestore}` | `auth`, `web`, `PlanModuleCheck:BackupRestore` |
| GET | `api/backuprestore` | `api`, `auth:api` |

Plus, in the controller itself: `isAbleTo('backup&restore manage')` on `index`,
`isAbleTo('backup&restore create')` on `create`/`store`, `isAbleTo('backup&restore delete')` on
`destroy`.

**`S1`'s title is imprecise and it matters for where the fix goes.** S1 is headed *"unauthenticated
route to the whole database and `.env`"*. **The route is not unauthenticated** — every one above
carries `auth`. What is unauthenticated is the **artefact**, and that is a host-level problem:

- artefacts are written to `storage/uploads/{Files_Backup,Database_Backup}`, **inside the document
  root** (S1 records that the document root *is* the repository root);
- at **`chmod 0777`** on both directory and file (`BackupRestoreController.php:124-125`, `:193-194`);
- and `.htaccess` serves any path that exists as a real file unrewritten — every rewrite rule
  carries `RewriteCond %{REQUEST_FILENAME} !-f`.

The file archive is a `ZipArchive` over `base_path()` whose only exclusion is the `zip` extension,
so **`.env` and `.git/` are inside it**.

**Disabling the module does not remove an artefact already generated.** That is the single most
important consequence of this section: if a backup was ever taken while the module was active, the
file is still there and still downloadable by anyone who can guess the name.

`api/backuprestore` carries `auth:api` — and there is **no `api` guard** in `config/auth.php`
(CLAUDE.md), so that route resolves against a guard that does not exist. Its behaviour should be
established before enabling, not assumed.

## 4. Two further defects in the module, for the owner's decision — not fixed here

`packages/workdo/` is vendored and `guard-no-workdo-changes.sh` forbids editing it, so these are
recorded rather than patched:

- **Shell injection.** `$request->database_name` is interpolated into the `shell_exec` string
  (`:154`, `:181-182`) and validated `required` only (`:143-146`).
- **A failed dump is reported as success.** The `shell_exec` return value is discarded, with no
  exit code, no `-s` and no readability check (`:188-192`), while the handler returns
  `"Data backup successful."` (`:162-165`). `deployment/deploy.sh:24-25` in this same repository
  states the principle it violates: *"A dump nobody has ever read is not a backup."*
- **The DB password is on the command line**, visible to `ps` for the life of the dump (`:184-186`).
- **There is no restore.** `show()`, `edit()` and `update()` are empty stubs (`:222-246`).

## 5. What to check on the server — read-only, nothing to change

**An operator-ready runbook for these three checks, with the exact commands, what to record,
what not to touch, and the incident trigger, is
`docs/security/P1-OWN-01-SERVER-VERIFICATION-RUNBOOK.md`.**

None of this requires enabling the module, reading a backup's contents, or altering configuration.

1. `SELECT module, is_enable FROM add_on_managers WHERE module = 'BackupRestore';`
   **No row is the safe answer.** A row with `is_enable = 0` means the eight routes above exist.
2. `ls -la storage/uploads/Database_Backup storage/uploads/Files_Backup`
   **Any file here is downloadable by anyone who can guess the name.** Note its mode and date.
3. Confirm whether the document root is the repository root, and whether `.htaccess` already
   denies `storage/` and `*_Backup`.

## 6. Containment, specified and ready to apply — **not applied**

In priority order. All of it is **host-side**, so none of it touches `packages/workdo/`.

1. **Remove any existing artefact from the web root**, after deciding whether it is still needed.
   This is the only step that addresses the exposure that exists *today* on a disabled module.
2. **Deny the paths in `.htaccess`** at the document root, so a future artefact is not servable
   even if written there:
   ```apache
   RedirectMatch 404 (?i)/storage/uploads/(Files|Database)_Backup
   RedirectMatch 404 (?i)_Backup/.*\.(zip|sql|gz|tar)$
   ```
   `RedirectMatch 404` rather than `Deny` so the path is indistinguishable from one that was never
   there. **Verify after applying** that a known artefact URL returns 404 and that the merchant
   admin still loads.
3. **If the module is ever to be enabled**, require first: artefacts written outside the document
   root, `0640` rather than `0777`, the database name parameterised rather than interpolated, the
   password passed in the child environment, and the dump's size and readability verified before
   any success message. Four of those five are inside the vendored module, so enabling it means
   either accepting them or forking the module — **an owner decision, not an engineering one.**
4. **Delete the `add_on_managers` row** rather than setting `is_enable = 0`, if the intent is that
   the feature be unreachable. Per §1 that is the difference between no routes and eight.

## 7. Why this task is not DONE on verification alone

Items 1–3 of §5 are server observations this session cannot make and must not attempt, and §6
item 1 is a change to server state. The engineering half — establishing what the code does, pinning
it, and specifying the containment — is complete and is what this document and its two test
artefacts deliver.

**Evidence:**
- `tests/Support/Security/bin/verify-backuprestore-module-gating.php` — 6 checks, 0 failures
- `tests/Feature/Security/BackupRestoreModuleGatingTest.php` — 4 tests, 11 assertions, three
  removal checks: adding the `is_enable` filter to the loader fails it, removing the super-admin
  short-circuit from `PlanModuleCheck` fails it, and a comment quoting the needle does **not**
  satisfy it
