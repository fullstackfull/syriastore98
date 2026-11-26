# Phase 06 — the MARKET family measured against the code

**Scope: `MARKET-001` … `MARKET-005`, the five recovered owner-approved decisions at
`docs/development/master-plan/DECISION-ID-REGISTER.md:211-215`, plus `EX-010` at `:438`.**

Written 2026-11-26. Everything below is measured against this worktree unless it says otherwise;
where a figure came from another document it says so and names it.

---

## 0. Why this family needed looking at

`MARKET-002`, `MARKET-003` and `MARKET-004` appear in **neither** `MASTER-PLAN-REQUIREMENT-
TRACEABILITY.md` **nor** `MASTER-PLAN-GAP-REGISTER.md`. `MARKET-001` and `MARKET-005` appear only in
the gap register, as one row: `GAP-196`, whose whole content is

> **Not located.** No app/theme marketplace. `MarketPlaceList` is a different thing (see GAP-189).
> *Next action:* Phase 06 scope, not started. Expected absence.

`PHASE-06-INVENTORY-2026-11-24.md:3266` already corrected the first half of that, and this task
corrects the second. **"Expected absence" was wrong in the direction that matters:** the absent thing
was not the marketplace, it was the *boundary* `MARKET-004` demands around the one that exists.

`DECISION-ID-REGISTER.md:463` records `EX-010` as holding **"vacuously — Phase 06 has not started"**.
That is refuted. Measured below: the platform has an authenticated upload-and-install door, and until
this change an uploaded package's own service provider was registered on the next request, with
nothing having reviewed it. `EX-010` was live and unenforced, not vacuous.

---

## 1. Dispositions

| id | bucket | one line |
|---|---|---|
| `MARKET-001` | **PARTIALLY BUILT** · one defect fixed · the rest **OWNER DECISION** | the browse-and-install surface exists and was crashing on a vendor HTTP failure; the *developer* half needs `MARKET-003` |
| `MARKET-002` | **MISSING FUNCTIONALITY** (review record + inputs built) · the **standard** is **OWNER DECISION** | nothing recorded a review; the four review inputs are now captured from the package without running it |
| `MARKET-003` | **OWNER DECISION — not actionable by the plan's own words** | "عند اعتماد نموذجها التجاري" — *once its commercial model is approved*. It has not been. No pricing or revenue split invented |
| `MARKET-004` | **MISSING FUNCTIONALITY** (built) + **MISSING VERIFICATION** (built) | an uploaded package executed on the next request; there is now a trust boundary and a tokenised census |
| `MARKET-005` | **MISSING VERIFICATION** (built) | the guarantee already held in code and nothing asserted it. Now asserted behaviourally and structurally |

---

## 2. `MARKET-004` — "do not execute untrusted external code in production"

### 2.1 What was measured

The chain is two methods long.

- **`ModuleController::install()`** extracts an uploaded zip into `packages/workdo/<name>` and writes
  an `add_on_managers` row with `is_enable = 0`. That reads like a safety.
- **`PackageServiceProvider::loadDynamicAddons()`** did `AddOnManager::pluck('module')` with **no
  `is_enable` predicate** and handed the result to `loadAddons()`, which registers the package's
  PSR-4 prefix, calls `$this->app->register($provider)` for every provider its own `composer.json`
  names, and registers its migrations.

So the uploaded package's `register()` and `boot()` ran before anybody enabled it. **This is asserted
as a premise, not inferred:**
`UploadedModuleDoesNotExecuteUntilReviewedTest::test_the_loader_does_register_an_installed_package_that_has_no_review_record`
writes a probe package whose provider sets a container binding, inserts only the install door's own
row, and observes the binding appear.

`is_enable` governs *reachability* — `ModuleAvailability`, `DisabledModuleRouteGuard` — and has never
governed *execution*. Nothing could have caught this with a status code: a disabled module answers no
routes, so every HTTP probe looked right while its provider ran on every request.

**`InstallAddonRequest::ALLOWED_ENTRY_EXTENSIONS` admits `php` and `blade.php` deliberately**, and
that is correct — a theme *is* Blade. The defect was never the extension list; it was that nothing
stood between the upload and the loader.

### 2.2 Why `is_enable` is not the fix

`PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §3 already considered `where('is_enable', 1)` at the
loader and rejected it, for a reason that is right: `loadAddons()` registers migrations, console
commands and event listeners, so filtering there would mean a switched-off application could not be
migrated, could not be re-enabled cleanly, and would silently stop its scheduled work. It would fail
by **breaking** rather than by refusing.

None of that transfers to an unreviewed upload, and that is the justification for a second flag
rather than a reuse of the first: **a package nobody has reviewed must not be migrated either — its
migrations are its code.** "Switched off" is a state a trusted application returns from; "never
reviewed" is a state it has not yet entered.

### 2.3 What was built

| | |
|---|---|
| `database/migrations/2026_11_26_000100_create_module_review_records_table.php` | additive; `up()` is a no-op if the table exists; `down()` drops only the new table |
| `App\Models\ModuleReviewRecord` | **deliberately NOT `Cachable`** — a cached miss on "has this been reviewed" is the execution of unreviewed code, the same reasoning as `MarketingNudgeSend` |
| `App\Support\Modules\ModuleTrust` | the predicate: `mayExecute()`, `executable()`, `recordUpload()`. Reads through the query builder, not the model, because it runs while the container is still assembling |
| `App\Support\Modules\PackageDeclaration` | the review inputs, by **static text read** — `json_decode` for the manifest, a regular expression over the seeder SOURCE for permission names. Nothing in the package is executed |
| `PackageServiceProvider::loadDynamicAddons()` | the dynamic list now passes through `ModuleTrust::executable()` |
| `ModuleController::install()` | records the upload, keyed on `$addon->module` (what the loader plucks) with declarations read from `$fileName` (where the bytes went) |
| `AddonController::ThemeInstall()` | records the upload and writes `Addon.status = '0'` |
| `AddonController::ThemeEnable()` | refuses to switch an unapproved uploaded theme **on**; never refuses switching one **off** |
| `App\Http\Controllers\ModuleReviewController` + 3 routes + 2 views | where an operator records a decision, and reads what the package declares |

**An absent row is not an unreviewed package.** The 70 vendored modules and the three shipped themes
arrived through git; no row describes them, and `mayExecute()` returns true for them. Refusing on a
missing row would unload every module on the first request after the migration — `LandingPage` alone
owns 275 routes (figure from `PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §2, not re-measured here).

**The failure directions are asymmetric on purpose.** Table absent → trusted (a fresh checkout before
`migrate`; refusing would brick a new install). Schema unreachable → trusted (the only caller that
reaches `loadAddons()` without a database is the static first-party pass). **Table present and the
read throws → refused**, logged `critical`. `ModuleAvailability` fails open in all three because it is
an availability control; this one cannot, because failing open here runs third-party code.

### 2.4 Two behaviour changes, stated

1. **An uploaded theme now arrives switched off.** `ThemeInstall()` used to write `status = '1'`, so an
   uploaded theme was immediately offered to every merchant by `ThemeCustomizeController::index()`
   (`Addon::where('status','1')`) and to the operator building a plan (`PlanController:190`, `:262`).
   No existing test asserted the old value — checked:
   `ThemeInstallDoesNotOverwriteAnotherThemeTest:218` asserts the row exists and reads no column;
   `AddonInstallTest` drives only refusals.
2. **`Addon::firstOrNew` replaces `new Addon`** in `ThemeInstall()`. A re-upload under the same name
   used to insert a SECOND row for one `theme_id`, and `ThemeEnable()`/`destroy()` both read
   `->first()`, so the duplicate was unreachable and un-deletable. Pre-existing; found here.

### 2.5 The prohibition, measured as a prohibition

`NoUntrustedCodeExecutionCensusTest` is **tokenised, not grepped** — `token_get_all()`, so `T_EVAL`
is what the parser saw and a comment or a string cannot satisfy or break it. A control mutation
(prose plus a string literal containing `eval(`, `include`, `unserialize(`) leaves it green; all five
dangerous mutations fail it.

Measured over `app/` and `packages/workdo/`:

| | `app/` | `packages/workdo/` |
|---|---|---|
| `eval` | **0** | **0** |
| `create_function` | **0** | **0** |
| PHP `assert()` as a bare call | **0** | not measured |
| `include` / `require` sites | **12**, in 7 files, each declared with its confinement | not measured |
| `unserialize` | **2**, both `['allowed_classes' => false]` | not measured |

A naive regex reported **21** include sites where the tokeniser reports **12**; nine were the words
inside strings. `App\Services\StoredPathPolicy` has four `self::assert(...)` calls that a name-only
census reports as PHP's `assert()` — excluded by a bare-call check, not by name.

The include census **enumerates the safe set**: the complete list of files that include anything is
declared with a reason each, keyed by file and count rather than by line number, so a new site in a
new file fails whatever it includes.

---

## 3. `MARKET-005` — "suspend apps or withdraw their permissions without destroying merchant data"

### 3.1 The guarantee already held, and nothing asserted it

Measured across the host application before a line was written:

- `migrate:rollback`, `migrate:reset`, `migrate:fresh`, `Schema::drop`, `dropIfExists`, `dropColumn`,
  `DROP TABLE` → **0 occurrences in `app/`**. No disable or removal path drops a module's tables.
- `function uninstall`, `function onDisable`, `ModuleDisabled`, `disableModule` → **0 files in
  `packages/workdo/`**. No module has a destructive uninstall hook.
- `ModuleController::enable()`'s disable branch is `$module->disable()` and nothing else — one
  `UPDATE add_on_managers SET is_enable = 0`.
- `ModuleController::remove()` deletes exactly two row sets, `permissions` and `add_on_managers`, both
  keyed on the module name, plus the module's directory.
- `PlanController::update()` and `User::assignPlan()` delete `user_active_modules` rows.

`permissions` and `user_active_modules` are the two the decision text **allows**: its own verb for
them is "سحب صلاحياتها", *withdraw its permissions*. Everything else is merchant data.

So the disposition is **MISSING VERIFICATION**, exactly as the brief predicted — and an unasserted
guarantee is one refactor from being false.

### 3.2 What was built

- **`SuspendingAnAppKeepsMerchantDataTest`** (6 tests) drives the production doors with a real
  vendored module (`ProductReview`, one migration, `product_reviews`) loaded through
  `EnablesWorkdoModules`, with a premise test first because CLAUDE.md records that no WorkDo module
  loads in a test run without an `add_on_managers` row. Covers: disable keeps every row and keeps the
  row and the permissions; suspend-then-restore keeps the data; withdrawing an entitlement keeps the
  data; removal deletes only its own bookkeeping, leaving another module's permissions and the `Base`
  set intact.
- **`SuspensionDestroysNothingCensusTest`** (4 tests) is the structural half and **enumerates the safe
  set**: every destructive statement in `ModuleController::{enable, remove, Check_Child_Module}`,
  `AddonController::{destroy, ThemeEnable}`, `Module::disable` and `User::assignPlan` must target
  `Permission`, `AddOnManager`, `userActiveModule` or `Addon`. Anything else fails **by default**.
  Scoped to the **method body** by brace matching, with a premise test that fails if a method is
  renamed.

Five delete spellings were injected into the disable path — `Model::where()->delete()`,
`DB::table()->delete()`, a bare `$var->delete()`, `Model::destroy()`, `->truncate()` — and **all five
fail the census.** The bare-variable spelling is the one that forced the census to resolve a local
variable back to its assignment: `AddonController::destroy()` ends in `$theme->delete()`, which a
blacklist would have missed, and which is the exact shape CLAUDE.md records as having hidden fourteen
of fifteen dynamic raw-SQL sites.

---

## 4. `MARKET-001` — "a marketplace for apps, themes and developers"

### 4.1 What exists

| half | state |
|---|---|
| **browse** | **built.** `resources/views/marketplace/` (5 views), `HomeController::{Software, SoftwareDetails, Pricing}` over `Module::getByStatus(1)`, public routes `GET add-on` and `GET add-on/details/{slug}`; operator-side `module/index.blade.php`, `addon/index.blade.php`, `addon/apps.blade.php` |
| **install** | **built and operator-gated.** `POST install-modules`, `POST theme-addon`, `POST theme-enable`, all behind `can:manage-platform-addons` |
| **versioning** | **declared and unused.** See §5 |
| **developers** | **absent.** No developer account, submission, listing-ownership or payout surface. Blocked on `MARKET-003` |

### 4.2 The defect that was fixed

**`Utility::BuyMoreTheme()` could take three operator screens down.** Its body was
`json_decode(file_get_contents('https://ecom-demo.workdo.io/cronjob/addon.json'), true)` inside a
`try`, with `return []` in the catch and **no return statement on the fall-through** — so a failed
fetch returned `[]` and a successful fetch of an empty payload returned **null**.

Its three callers (`AddonController::index`, `AddonController::AddonApps`, `ModuleController::index`)
pass the result straight into a view. `module/index.blade.php` guards every use; the other two do
not — `addon/index.blade.php` does `@foreach ($theme['theme'] ...)` and `addon/apps.blade.php` does
`@foreach ($apps['apps'] ...)`. **Measured by rendering both views with each value: "Undefined array
key", which Laravel raises as an `ErrorException` and the operator sees as HTTP 500.** So `GET /addon`
and `GET /addon-apps` were down for any installation whose egress does not reach that host — the
normal state of a restricted production network.

Three things changed: the three keys every view reads are now always present and always arrays; the
catch is `\Throwable` rather than `\Exception` (a PHP `Error` from a changed payload was not caught at
all); and the fetch carries a **6-second timeout**, where `file_get_contents` on a URL otherwise uses
`default_socket_timeout` — **60 seconds** by default, held in front of the operator's screen.

**The outbound call itself is unchanged, deliberately.** Whether a first-party catalogue should
replace the vendor's is `MARKET-001`'s own question and is coupled to `MARKET-003`'s unapproved
commercial model.

**A test of this file passed for the wrong reason until a mutation caught it.** `ecom-demo.workdo.io`
*is* reachable from this environment, so the shape assertion ran the success path and stayed green
when the fallback shape was emptied. The failure path is now driven by replacing PHP's `https` stream
wrapper for the duration of one call — deterministic, no seam added to production code.

---

## 5. `MARKET-002` — "security, compatibility, versioning and permission reviews"

### 5.1 What existed: nothing for three of the four

- **security review** — nothing. No record, no signature, no provenance, no analysis.
- **compatibility** — `ModuleController::Check_Parent_Module()` refuses to *enable* a module whose
  `parent_module` entries are inactive. **Measured over all 70 vendored manifests: zero declare a
  non-empty `parent_module`, so this check has never fired for any module that ships.** Pinned as a
  ratchet by `PackageDeclarationTest::test_no_vendored_module_declares_a_dependency_today`, which
  fails if an upstream update makes it live for the first time.
- **versioning** — `version` is in all 70 manifests, read by `App\Classes\Module::find()`, and
  **consumed in exactly one place: `module/index.blade.php:220`, through `sprintf('%.1f')`.** Nothing
  compares two versions. There is no upgrade path, no minimum-platform check, no downgrade refusal.
- **permission review** — nothing showed what permissions a package would create. The only way the
  platform obtained them was `Artisan::call('package:seed <Module>')` from
  `ModuleController::enable()`, which **runs the package** — the thing `MARKET-004` forbids.

### 5.2 An upload could silently replace a different installed module — fixed

`ModuleController::install()` derives its target from the uploaded FILENAME —
`$extractPath = base_path('packages/workdo/' . $fileName)` — and then did

```php
if (is_dir($extractPath)) { $this->deleteDirectory($extractPath); }
```

So **`LandingPage.zip` recursively deleted the shipped LandingPage module** — the CMS that owns `/`
and more routes than any other module — and put the archive's contents there instead, in one HTTP
request, with no confirmation and no record of what had been there.

**This is the defect the THEME door was already fixed for, and only the theme door was.**
`PHASE-06-THEME-INSTALL-TARGET.md` and `ThemeInstallDoesNotOverwriteAnotherThemeTest` record "an
archive whose entries read `greentic/...` replaced the installed greentic theme's files in place, and
every store on that theme then rendered the replacement". Same shape, different route: there the
ARCHIVE picked the target, here the FILENAME does.

**It interacts with the `MARKET-004` boundary, which is why it is closed rather than left.**
`ModuleTrust::recordUpload()` writes a `pending` record for whatever name the install lands under, so
without this guard an upload named after a shipped module would both REPLACE it and make it
UNLOADABLE until somebody approved the replacement.

The guard compares the manifest `name` of what is installed against the manifest `name` of the
extracted archive — read from the TEMP copy, **before anything is deleted** — and refuses when they
differ. Updating a module with an archive that *is* that module still works, which is asserted as the
premise of the refusal. **Severity, honestly: operator-reachable only**, since the route group and
`install()` both assert `manage-platform-addons`; the same disposition the theme door and the
module-removal door carry.

### 5.3 A name too long for the register bypassed the boundary — fixed

Found by reviewing this change, not by a failing test, and it was a bypass of the whole
`MARKET-004` boundary by nothing more than a long name.

`add_on_managers.module` and `addons.theme_id` are both a plain `$table->string(...)`, i.e.
VARCHAR(255), and both take a value the uploader influences — `install()` writes
`$data['name'] ?? $fileName` straight from the **archive's own manifest**.
`module_review_records.subject` was VARCHAR(64). So a name between 65 and 255 characters installed
fine, failed the register's INSERT, and `ModuleTrust::recordUpload()` cannot undo an install whose
files are already on disk. **No record means trusted**, so the package would have loaded with nothing
recorded against it.

The column is now 191 — the utf8mb4 index-safe length this codebase already uses for an indexed
string (`module_payment_initiations.route`) — and **both install doors refuse a longer name outright**
through one predicate, `ModuleTrust::nameFitsTheRegister()`, so the two limits agree instead of one
silently losing to the other. Refusing there is safe because the loader plucks
`add_on_managers.module`: files on disk with no row are never loaded, and the extracted directory is
removed anyway.

### 5.4 What was built, and the limit

`module_review_records` carries `declared_version`, `declared_dependencies` and
`declared_permissions`, captured at upload time **from the package by static read**, and
`ModuleReviewController::show()` renders them for the reviewer. `declared_version` is a string label,
not a number, because all 70 manifests write it as a JSON number and `sprintf('%.1f')` would round a
semantic version to nonsense; `null` and `'1.0'` stay distinguishable, which `Module::find()`'s
`?? 1.0` substitution destroys.

`declaredPermissions()` returns **`null` when no seeder source could be read** and **`[]` when one was
read and declared nothing** — a reviewer must be able to tell a gap in the measurement from a fact
about the package. It recognises the shape all 70 vendored modules use and is **deliberately allowed
to be incomplete**; nothing in the application makes an authorization decision from it, and
`ModuleTrust` does not read it.

**The review STANDARD is not encoded and must not be.** Nothing here asserts that an approved package
is safe; a row records that a named operator took responsibility for it on a date.
`PHASE-06-OWNER-DECISION-PACKAGE.md:589` already records that `MARKET-002` "needs a review standard",
and that remains the owner's.

---

## 6. `MARKET-003` — deferred by the plan's own words

> `MARKET-003` · أسعار وعوائد للمطورين **عند اعتماد نموذجها التجاري**.
> *Prices and developer revenue share, **once its commercial model is approved**.*

The approval is not in this repository. **No pricing model was built and no revenue split was
invented.** What the owner would need to decide, if and when they want this:

1. Who may publish to the marketplace — the platform only, invited partners, or open submission.
2. Whether a paid add-on is billed through the existing plan machinery (`add_on_managers` already has
   `monthly_price` and `yearly_price` columns, **all zero in all 70 manifests**) or separately.
3. The developer share, and who carries refunds, chargebacks and tax.
4. Whether a developer may see merchant data — which is a privacy decision, not a commercial one, and
   interacts with `MARKET-004`: a reviewed package still runs inside the application.
5. What happens to a merchant's paid add-on when the developer withdraws it. `MARKET-005` fixes the
   data half; the money half is open.

Until (1) and (2) are answered, the "developers" half of `MARKET-001` has no shape to build to.

---

## 7. Measured findings NOT fixed here, with the reason

(The two that WERE fixed outside the five decisions' direct scope are §4.2, the catalogue 500, and
§5.2, the module-overwrite door.)

1. **A vendored seeder crashes when no role is named `admin`.**
   `packages/workdo/ProductReview/src/database/seeders/PermissionTableSeeder.php` does
   `$company_role = Role::where('name','admin')->first();` then `$company_role->hasPermission($value)`
   with **no null check**. So `ModuleController::enable()`'s re-enable branch, which calls
   `Artisan::call('package:seed <Module>')`, is a fatal **"Call to a member function hasPermission()
   on null"** on any installation with no such role. Measured — it is how
   `test_switching_an_application_back_on_is_possible_after_a_suspension` first failed. **Vendored, so
   not edited.** A host-side fix would have to pre-create the role or wrap the Artisan call, and both
   are behaviour changes on the enable path. `Utility::addNewData()` has the identical null guard on
   its own `$superadminRole`, with a comment recording the same defect — so the host already knows
   this shape.

2. **`ModuleController::{index, add, remove}` gate on `isAbleTo()` alone and so refuse the platform
   operator**, while `enable()` carries the operator-first limb and `install()` is operator-ONLY
   (`can:manage-platform-addons`). Measured: a freshly created platform admin gets **HTTP 302** from
   `GET /modules/list`. **This is not a live production defect** — `Utility::addNewData()`
   (`app/Models/Utility.php`, the `$superadminNewPermission` block) grants `Manage Module`,
   `Module Add` and `Module remove` to the role named `super admin`, so the operator passes in
   practice. **Deliberately not changed:** `GAP-218` records that changing owner/operator pass
   semantics on live authorization gates is a behaviour change that has already refused the store
   owner against two independent tests encoding the old behaviour as the shipped contract. It is
   recorded here because the *inconsistency* is real and the owner may want it normalised.

3. **`AddonController::destroy()` deletes a theme's directory without checking whether a store is
   using it.** No merchant *data* is destroyed — `theme_customize_orders` and `stores.theme_id` are
   untouched — and `config('theme.active')` means such a store falls back to stylique's views. So it
   is an availability question, not a `MARKET-005` violation, and what should happen (refuse, warn,
   reassign) is a product decision.

4. **`BuyMoreTheme()`'s result is still not cached.** `Cache::remember` would need a TTL nobody has
   chosen, and the failure this closed was a 500, not a slow page.

5. **Two guards are redundant with each other and a mutation survives each.** Narrowing
   `BuyMoreTheme()`'s catch back to `\Exception` leaves the suite green (the `is_string($raw)` guard
   means nothing throws on the failure path), and removing its `is_array($json)` guard also leaves it
   green (the `\Throwable` catch absorbs the resulting error). Both are defence in depth and are
   recorded as such rather than claimed as tested — which is itself evidence the catch widening was
   right.

---

## 8. The two new screens, and what is and is not asserted about them

`module/review.blade.php` and `module/review-detail.blade.php` use only classes this codebase already
uses, and declare **no colour of their own** — measured: no inline `style=`, no hex, no `rgb()`, no
`hsl()`. Their only toned element is `<x-ss.status-badge>`, whose `.ss-badge--*` tokens are defined
for light **and** dark in `public/css/store-syria-ds.css` and are contrast-checked there; the
component also renders a dot so colour alone does not carry the meaning (WCAG 1.4.1). A first draft
used `badge bg-warning`, which appears **nowhere else** in the merchant tree and would have been a new
contrast risk.

Spacing uses the logical `ps-3`, which this tree already uses 18 times, rather than `pl-3`.

**Both languages are asserted**, and the test records why it is written the way it is: `SetLocate`
sets the locale per request from `Session::get('LANGUAGE')`, so a `__()` call made *after* a `get()`
resolves in whatever locale the middleware chose. The first draft compared an Arabic page against
English keys; it failed, and had the keys been missing it would have failed for a reason that looked
identical. The 39 new keys are in both `resources/lang/en.json` and `resources/lang/ar.json`.

**Contrast on the rendered page is HUMAN ACCEPTANCE, not asserted here.** CLAUDE.md requires
`APP_DEBUG` off and the compositing text-node walk as the instrument, and that is a reviewed run, not
a unit assertion.

## 9. Tests added

| file | tests | suite |
|---|---|---|
| `tests/Unit/Modules/ModuleTrustTest.php` | 10 | Unit |
| `tests/Unit/Modules/PackageDeclarationTest.php` | 10 | Unit |
| `tests/Unit/Modules/StaticAddonsStayFirstPartyTest.php` | 2 | Unit |
| `tests/Unit/Modules/NoUntrustedCodeExecutionCensusTest.php` | 5 | Unit |
| `tests/Unit/Modules/SuspensionDestroysNothingCensusTest.php` | 4 | Unit |
| `tests/Feature/Marketplace/UploadedModuleDoesNotExecuteUntilReviewedTest.php` | 7 | Feature |
| `tests/Feature/Marketplace/UploadedPackageIsRecordedAndNotLiveTest.php` | 13 | Feature |
| `tests/Feature/Marketplace/ModuleReviewRegisterDoorTest.php` | 15 | Feature |
| `tests/Feature/Marketplace/SuspendingAnAppKeepsMerchantDataTest.php` | 6 | Feature |
| `tests/Feature/Marketplace/AddonCatalogueSurvivesTheVendorFeedTest.php` | 9 | Feature |
| **total** | **81** | 31 Unit, 50 Feature |

**No test is skipped.** One draft used `markTestSkipped` when no vendored module declared a
`parent_module`; it was replaced by the positive ratchet in §5.1, because CLAUDE.md records that a
conditionally-skipped test breaks the declared outcome set.

**39 distinct mutations were run; 36 were caught.** The three survivors are §7.5's two redundant
guards in `BuyMoreTheme()` and one unreachable branch in `PackageDeclaration::declaredPermissions()`
(a seeder file that exists but cannot be read). One control mutation — a comment and a string literal
containing `eval(`, `include` and `unserialize(` — correctly leaves the tokenised census green. Every
mutation and its result is in the task report.

**Two measurement errors were made and caught, and they are worth recording** because they are the
shape CLAUDE.md warns about. (1) Two mutation edits silently failed to apply (a Python escaping
mistake) and the suite's green result was briefly read as "not caught"; verifying the file had
actually changed is now part of each mutation. (2) `AddonCatalogueSurvivesTheVendorFeedTest`'s shape
assertion passed through the live network's success path, so emptying the fallback shape left it
green — fixed by driving the failure path with a replaced `https` stream wrapper.
