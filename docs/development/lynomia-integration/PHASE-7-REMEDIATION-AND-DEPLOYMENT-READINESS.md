# Phase 7 — Remediation and Deployment Readiness

Store Syria × Lynomia Chat. Written at the end of the live-findings remediation pass.

**What this document is.** The consolidated answer to the Phase 7 remediation directive: what was
broken, what was fixed, what the fixes were measured against, and precisely what is still needed
from the owner before either application may be installed or updated on the existing pre-launch
servers.

**What it is not.** It is not a deployment authorization, and it does not claim any deployment was
performed. No database migration was run on any server, nothing was deployed, no WhatsApp provider
was connected and no message was sent to any real recipient. Those remain outside what this pass
was authorized to do.

**How to read the verdicts.** §17 and §18 are deliberately separate and they do not match. The
engineering remediation is complete and verified; the server deployment is not, for reasons that
are infrastructural rather than technical and are not resolvable from inside these repositories.

---

## 1. Current commit SHAs for both repositories

Re-read at the time of writing rather than carried forward from an earlier report.

### Store Syria — `fullstackfull/syriastore98`

| | |
|---|---|
| Working branch | `claude/awesome-meitner-bop1lb` |
| Branch HEAD | **`66a66b8d`** `feat(deployment): a safe update path and a rollback procedure for Store Syria` |
| Pushed | Yes — local and `origin/claude/awesome-meitner-bop1lb` both at `66a66b8d`; nothing unpushed |
| Default branch | `main`, at `c1ea89b9` |
| This pass added | `c1c54cd1` (L1), `48bb74df` (L2), `7f412573` (dependencies), `33b7dd40` (L1 adversarial test), `4204cbc6` (`/register` assertion), `66a66b8d` (deployment package) |

### Lynomia Chat — `fullstackfull/lynomiachat`

| | |
|---|---|
| Working branch | `claude/awesome-meitner-bop1lb` |
| Branch HEAD | **`62f21be3`** — a merge of `origin/lynomia-custom` into the working branch |
| Pushed | Yes — local and `origin/claude/awesome-meitner-bop1lb` both at `62f21be3`; nothing unpushed |
| Merge parents | `2dce2f8f` (previous branch head) + `0af2c436` (default branch) |
| Default branch | `lynomia-custom`, at `0af2c436` |
| Commits this pass | `16c6c422`, `1eb25c71`, `2dce2f8f`, and the merge. **No application code** — a CI workflow, one heap value and one spec (§2) |

The merge is not cosmetic: it brings in **103 files and ~10,000 lines** — the Odoo accounting
integration merged into `lynomia-custom` through PR
[fullstackfull/lynomiachat#36](https://github.com/fullstackfull/lynomiachat/pull/36).

Pushing it to the **working branch** introduces nothing new, because all fifteen of those commits
already live on `lynomia-custom` itself: the push brings the working branch up to the default
branch rather than moving the Odoo work anywhere it was not already merged. What has *not* happened
is CI running on the merged result (§16.2); its local regression run is §7.1.

### A deliberate deviation the owner should know about

The directive says to push "to their respective default branches". **I did not.** This session
operates under a standing instruction naming `claude/awesome-meitner-bop1lb` as the development
branch for both repositories and saying never to push to a different branch without explicit
permission. Those two instructions conflict, and the conflict is not mine to resolve silently, so
I took the conservative reading: every commit went to the designated branch, nothing was pushed to
`main` or `lynomia-custom`, and no pull request was opened. Both designated branches are fully
pushed and up to date.

**What the owner needs to do about it:** say which you want. Either (a) merge the designated
branches into the default branches yourself, or (b) tell me to push to the default branches
directly and I will. Nothing about the code changes either way — this is purely about where it
lands.

---

## 2. L1 root cause and selected remediation

### The defect

A suspended installation could not be resumed through the intended workflow, and recovery required
editing a database row by hand.

Four rules, each correct, deadlocked:

1. Lynomia will not re-enable an installation it has not health-checked.
2. Lynomia's health check is exactly one `GET` of **this side's commerce IDENTITY endpoint**.
3. Store Syria pushes the lifecycle transition to Lynomia **before** writing it locally — on
   purpose, so this side can never report `ACTIVE` while Lynomia is still refusing to send.
4. `AuthenticateCommerceApi` refuses every commerce request from an installation that is not
   `ACTIVE`.

So a resume asked Lynomia to health-check a store whose link was still `suspended`; rule 4 refused
the identity read with `403`; Lynomia answered `resume_failed` (`409`); and the unguarded push
threw, so the local transition never happened.

Measured against a live pair before the fix: `resume()` raised a conflict and `reauthenticate()`
reported `back_online=false`, both leaving the link `suspended`.

**Why no suite caught it.** Each repository drives a *fake* of the other side. Store Syria's fake
answers the lifecycle `PUT` without ever running Lynomia's health check, so the one interaction
that deadlocks is the one interaction neither suite performs. This is the risk blocker A1 named in
writing, demonstrated.

### The remediation: a scoped recovery window

I did **not** widen the authorization gate. `LinkState::SSO_ALLOWED` is untouched and no data route
gained anything.

What was added is a **recovery window** — a server-side grant with five independent limits:

| Limit | Mechanism |
|---|---|
| **Opened only by an authorized recovery** | `InstallationLifecycle::transition()` opens one only when the target state is `ACTIVE`, stamped with the transition name and the operator's id |
| **One route** | `AuthenticateCommerceApi::IDENTITY_ROUTES` is a constant containing exactly one route name, `lynomia.commerce.v1.store`. Data routes are unreachable through it |
| **One use** | `spendRecoveryWindow()` is a single atomic conditional `UPDATE` whose affected-row count *is* the answer, so two callers racing it cannot both see it unspent |
| **Seconds, not minutes** | `LYNOMIA_RECOVERY_WINDOW_SECONDS`, default 60, floor 5 |
| **Shut in a `finally`** | A refused push cannot leave a usable window behind |

It also **authorizes nothing on its own.** A request must still carry that store's own *current*
commerce credential to reach the health check at all — the credential-version check is untouched,
so a superseded token is still `401`. And eligibility excludes every terminal state:
`RECOVERY_ELIGIBLE = RESUMABLE`, so `DECOMMISSIONED` and `PENDING_VERIFICATION` cannot open a
window at all.

Every step is audited: `lynomia_recovery_window_opened`, `..._spent`, `..._closed`, the last
recording whether the window was actually used.

### The fix is one-sided, which matters for deployment ordering

**No Lynomia application code changed for L1 — or for L2.** Verified by diffing this branch against
the pre-pass Lynomia default branch: the three commits from this pass touch
`.github/workflows/run_foss_spec.yml`, one value in `deployment/deploy.sh`, and one spec file.
Every `app/`, `lib/`, `config/` and `db/` change in that range comes from the Odoo accounting merge
(§1), not from this remediation.

Lynomia's behaviour needed no change because its rule was never the wrong one: it declines to
re-enable an installation it has not health-checked, and that is correct. What was wrong was that
the other side refused the health check it was asking for.

So the remediation requires **no coordinated deploy ordering** between the two applications: Store
Syria can be updated independently, and Lynomia at whatever point suits. That removes a whole class
of deployment risk — there is no window in which one side has the fix and the other does not and
something breaks as a result.

### On the directive's Option B, and the security requirement

The directive said that if the recommended option required an architectural or security exception
inconsistent with the stated requirements, I should not implement it and should report the
conflict instead.

**No exception was required, so none was taken.** Each requirement is met by construction rather
than by promise:

- *Do not weaken the general authorization gate* — `SSO_ALLOWED` is unchanged; the window is a
  narrow additional branch, not a relaxation of the existing one.
- *Do not make a disabled installation generally active merely to perform recovery* — the
  installation stays `suspended` throughout the push. A test asserts exactly this and fails if the
  state is anything else at that moment.
- *Narrowly scoped, authenticated, audited, and revoked or unusable after the transition* — one
  route, one use, seconds-long, still requires the store's valid credential, three audit events,
  and shut in a `finally`.

An earlier attempt in this project to widen the gate directly was **refused by a permission
control** and was not routed around; the recorded options were put to the owner, and this design is
the one the owner then specified. It did not need the exception.

---

## 3. L1 real HTTP regression results

Two independent bodies of evidence. The directive required the original failure to be reproduced
before the fix was validated, and it was.

### Reproduction, before the fix

Against the two real applications over HTTP: `resume()` raised a conflict, `reauthenticate()`
reported `back_online=false`, and the link stayed `suspended` in both cases. One investigation
detour is recorded for honesty: a first reproduction attempt returned HTTP 500, which turned out to
be `ActiveRecord::PendingMigrationError` from the freshly merged Odoo migrations — an environment
state, not the defect. The migrations were applied additively and the reproduction then showed the
real `409`.

### The twelve required transition scenarios

The directive named twelve. Each is mapped to its evidence; nothing is marked covered by assertion
alone.

| # | Scenario | Evidence |
|---|---|---|
| 1 | Active → Suspended | Live run + `test_a_suspend_opens_no_window` (and asserts no window is created, which is the security half) |
| 2 | Suspended → Resume requested | Live run + `test_a_window_is_open_while_the_resume_push_is_in_flight` |
| 3 | Credential validation | `test_a_window_does_not_rescue_a_superseded_credential` → `401` |
| 4 | Successful authorized recovery | Live run: resume now completes; `test_a_window_is_open_...` asserts the final state is `ACTIVE` |
| 5 | Active and healthy | Live run: the health check passes *through* the window, which is what makes the installation healthy rather than merely marked active |
| 6 | Suspension after recovery | Live run: suspend after a successful resume propagates normally |
| 7 | Revocation | `REVOKED` is in `RESUMABLE`, so it is recovery-eligible by design; live revoke/resume exercised |
| 8 | Re-authentication | Live run: `reauthenticate()` now reports `back_online=true` where credentials are valid |
| 9 | Unauthorized recovery attempt | `test_a_suspended_installation_is_refused_the_identity_endpoint_with_no_window` → `403` |
| 10 | Expired or invalid credential | `test_an_expired_window_authorises_nothing`, `test_a_closed_window_authorises_nothing`, `test_a_window_does_not_rescue_a_superseded_credential` |
| 11 | Cross-tenant recovery attempt | `test_one_stores_window_cannot_be_spent_by_another_store` |
| 12 | **Concurrent suspension and resume** | `test_a_concurrent_decommission_cannot_be_resurrected_by_an_in_flight_resume` — see the note below |

**Scenario 12 needs its own paragraph, because the obvious version of it proves nothing.** I first
wrote it as a concurrent *suspend*. Measured, that is a no-op: `move` deliberately tolerates a
transition to the state the link is already in so a retried request is not an error, so the resume
simply completed and the installation was legitimately `ACTIVE`. The interleaving that carries real
risk is a **terminal** transition, because a resume already in flight writing `ACTIVE` over a
decommission would leave this side active on an installation whose credentials Lynomia has already
dropped. That is also the race `InstallationLifecycle`'s in-lock guard was written for. The test now
commits a `DECOMMISSIONED` row at the one moment it can interleave and asserts the resume **loses
the race as a conflict**, the state stays `DECOMMISSIONED`, no window survives, and the identity
route is `403` afterwards.

A second correction is recorded with it: the concurrent transition is applied as a **committed row
write**, not by calling `InstallationLifecycle` recursively. The recursive version re-enters the
same test double whose closure the test is inside, so the nested push throws and the decommission
never commits — which made the first version of that example pass for entirely the wrong reason. A
direct write is also the more faithful model, since the guard under test re-reads the row under
`lockForUpdate` and a real second process would reach it exactly as a committed row.

### Regression suite

`tests/Feature/Lynomia/LynomiaRecoveryWindowTest.php` — **16 examples, 32 assertions, all passing.**
They are split on purpose: **one** fails if the mechanism is removed; **the rest fail if it is ever
made wider.** Data endpoints stay `403` with a window open and the window is not even consumed by
the attempt; a window is good for exactly one identity read; two spends cannot both succeed; one
store cannot spend another's; expired and closed windows authorize nothing; `DECOMMISSIONED` and
`PENDING_VERIFICATION` cannot open one; a suspend opens none; a refused push still shuts its window;
a superseded credential is still `401`.

### Acceptance criteria

| Criterion | Status |
|---|---|
| Resume succeeds through the legitimate workflow | **Met** — live, over HTTP |
| Re-authentication succeeds where valid credentials are available | **Met** — `back_online=true` |
| The restored installation is actually healthy | **Met** — Lynomia's own health check is what passes |
| Unauthorized recovery is rejected | **Met** — `403` with no window; `401` on a stale credential |
| Suspended installations remain unable to send messages or perform normal commerce actions | **Met** — asserted separately, with the window open |
| Real HTTP integration tests prove the behaviour across both applications | **Met** — both applications, real HTTP, no fake on either side of the wire |

---

## 4. L2 migration fix and login/register results

### Root cause

`packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php:28`:

```php
$this->loadMigrationsFrom(__DIR__ . '/../database/Migrations');   // capital M
```

The directory on disk is `src/database/migrations`. **`loadMigrationsFrom()` does not check that
the path exists** — it appends the string to the migrator's list, so a path resolving to nothing
contributes no migrations and *no warning*. The mis-case resolves on a case-insensitive macOS or
Windows checkout and resolves to nothing on Linux, which is every server this ships to.

Six migrations were therefore never contributed. One creates `owner_menu_settings`, which
`resources/views/layouts/guest.blade.php:96` reads on **every guest screen** — so a Linux install
came up with `/login` and `/register` both answering HTTP 500 on `no such table`. Nobody could sign
in, which put every page behind authentication out of reach.

### The fix is in the host, not the module

`app/Providers/PackageServiceProvider.php` now registers each addon's real on-disk
`src/database/migrations` from the loader, so a module whose own provider declares a path that does
not exist can no longer strand its schema in silence.

`packages/workdo/` is vendored commercial code that `.github/scripts/guard-no-workdo-changes.sh`
refuses to let a pull request modify — an in-place edit is reverted by the next upstream update and
leaves no patch record — and **that guard's own guidance is that such a fix belongs in the host
application.** I patched the module first, discovered the guard, reverted, and implemented it in the
host. The module's one-character defect is deliberately left in place.

### The six migrations, inventoried and checked for destructiveness

`landing_page_settings`, `join_us`, `marketplace_page_settings`, `owner_menus`,
`owner_menu_items`, `owner_menu_settings`.

All six are **`Schema::create` only**. Two are additionally guarded by `if (!Schema::hasTable(...))`.
None drops, renames, truncates or rewrites an existing table or column, and none backfills data. The
only `dropIfExists` calls are in `down()`, which is the normal reversal and is not executed by
`migrate`. Applying them to an existing database adds tables and touches nothing already there.

**No existing data was modified to solve this**, as the directive required. The fix was developed
against a disposable local database.

### Results

| Check | Result |
|---|---|
| Clean install runs all six migrations | **Pass** — all six tables present |
| `/login` | **HTTP 200** (was 500) |
| `/register` | **HTTP 200** (was 500) |
| The six previously-failing guest-screen tests | **All pass** |
| Authentication, registration, sessions, redirects | Covered by the `Auth/` suite, passing |
| Other WorkDo modules | No regression — full Feature suite, §7 |
| Regression test for a fresh installation | **Added** — see below |

### The fix's blast radius, measured — it newly registers exactly one module

This needed checking rather than assuming, because the fix registers a directory for **every**
addon the loader processes, not just LandingPage. If it had quietly switched on other modules'
migrations, that would be a far bigger change than L2 and a deployment hazard.

Measured across all 70 modules under `packages/workdo/`, resolving every
`loadMigrationsFrom(__DIR__ . '…')` call against the calling file's own directory:

| | |
|---|---|
| Modules with migration files in `src/database/migrations` | **51** |
| …whose provider **already** registers that exact directory | **50** |
| …**newly** registered by the host fix | **1 — LandingPage** (6 files) |

So for 50 modules the host registers a path their own provider already registered, which is a
no-op: `Migrator::getMigrationFiles()` keys the collected files by migration name, so a duplicate
path collapses rather than running anything twice — and a migration already recorded in the
`migrations` table would not re-run regardless. LandingPage is the only module whose migrations the
fix actually adds, which is exactly the intended scope.

> A correction worth recording, because it is the kind of mistake that produces a confident wrong
> answer: my first attempt at this measurement scanned `src/Providers/` and reported that **51 of 51**
> modules were newly registered — alarming, and wrong. The providers live in `src/app/Providers/`,
> so the scan found no `loadMigrationsFrom` calls at all and counted every module as unregistered.
> The corrected figure agrees exactly with `tests/Unit/Packages/AddonMigrationRegistrationTest`'s
> independent count (69 calls, 50 existing paths, 18 benign absences, 1 defect).

**Two further bounds on what runs.** `loadAddons()` is called with
`$staticAddons = ['LandingPage', 'LynomiaChat']` and then with the modules listed in
`add_on_managers`. So on a fresh database only those two are processed — which is why
`coupon_requests` (PDFVouchers) is *still* absent after this fix, confirming the loader did not
start registering unactivated modules. On a server, the set is the activated modules, and for every
one of them the host path is one its provider already declared.

### The step-12 regression test, and why it is a new file

The directive asked for regression tests ensuring *fresh installations* load the required
migrations. The existing `AddonMigrationStrandingTest` could not serve: it registers the real
on-disk path **for its own tests** in `afterRefreshingDatabase()`, so an assertion placed there
would pass whether or not the application registers it — exactly the distinction that matters.

`tests/Feature/Platform/FreshInstallAddonMigrationsTest.php` registers **nothing**. Seven examples:
the six tables under an ordinary `RefreshDatabase` boot, plus `/login` returning 200.

**Proven by reversion rather than by assertion.** With `registerAddonMigrations()` commented out,
the original error reproduces exactly — `no such table: owner_menu_settings`, raised from
`guest.blade.php` — and with it restored the file is 7/7. The fix was then confirmed byte-identical
to before the experiment.

### Acceptance criterion

> *L2 may close only when a clean installation can complete its migrations and use the
> authentication entry points without HTTP 500 errors.*

**Met.**

---

## 5. Composer audit results

### What was applied

**`league/commonmark` 2.8.0 → 2.10.3**, and nothing else.

| Metric | Before | After |
|---|---|---|
| Advisories | 72 | **58** |
| Affected packages | 22 | **21** |
| `league/commonmark` advisories | 14 (9 high) | **0** |

All three advisories reachable on the mail path are among the fourteen cleared.

Five packages move, and they are exactly 2.10.3's own requirement closure: `league/commonmark`,
`nette/schema` v1.3.3→v1.3.6, `nette/utils` v4.1.1→v4.1.5, `symfony/deprecation-contracts`
v3.6.0→v3.7.1, `symfony/polyfill-php80` v1.33.0→v1.43.0. **No major version is crossed and
`laravel/framework` stays at v11.47.0.**

### The ratchet was tightened, not loosened

`AUDIT_CEILING` **lowered 70 → 58**. The directive forbade *raising* it; the CI step's own notice
says to lower it to the new figure so the ratchet keeps holding. Leaving it at 70 would have left
twelve advisories of headroom for a new vulnerable dependency to enter the lock unnoticed.

### Transitive constraints, checked rather than assumed

- `laravel/framework` v11.47.0 requires `league/commonmark ^2.7` → 2.10.3 satisfies it.
- `composer.json` does **not** name commonmark — it is transitive, which is why only the lock moved
  and no manifest change was needed.
- 2.10.3's own requires (`php ^7.4||^8.0`, `ext-mbstring`, `league/config ^1.1.1`,
  `psr/event-dispatcher ^1.0`, `symfony/deprecation-contracts ^2.1||^3.0`,
  `symfony/polyfill-php80 ^1.16`) are all satisfied by the locked versions.
- `composer install --dry-run` resolves the lock and passes composer's platform check.
- Lockfile reproducibility preserved: the update was applied with `--no-install`, the tracked
  `composer.json` was never modified, and no version constraint was loosened.

### The verification boundary, stated plainly

**The upgraded code never ran in this container.** These archives are fetched from
`api.github.com` and `codeload.github.com`, which this environment's egress policy answers `403`
for any repository outside the session's own two. `vendor/` still holds commonmark 2.8.0. CI
installs from the lock with unrestricted egress and is where that half happens. Nothing ships
before then, because deployment is not authorized.

**And a green suite would not have proved it anyway** — which is the more important point. Before
this pass, `grep -il markdown tests/` matched **nothing**: 2053 Feature cases and not one rendered a
markdown mail. commonmark had *zero* coverage, so "the suite passes" said nothing about it in
either direction. `tests/Feature/Mail/MarkdownMailRenderingTest.php` closes that gap:
`Mailable::markdown()` is the only route from this codebase into commonmark — used by five
mailables — and it is now rendered end to end, asserting the markdown actually became HTML, on
whichever version is installed.

### Remaining 58 advisories and their actual impact

Unchanged from the standing assessment, re-ranked by reachability through enabled functionality:

1. **`mtdowling/jmespath.php`** — critical. Parsed on every `aws-sdk-php` response, reachable from
   `get_file()`'s wasabi branch when `storage_setting == 'wasabi'`.
2. **`phpoffice/phpspreadsheet`** — 2 critical + 5 high. Reached by every product, customer and
   order import through `maatwebsite/excel`. One known refutation stands and was re-verified: the
   `IOFactory::load()` type-juggling advisory does not apply, because `maatwebsite/excel` never
   calls it — every path is `createReader($type)` then `$reader->load($path)` with an explicitly
   chosen reader and a server-generated temporary path.
3. **`guzzlehttp/guzzle` + `psr7`** — 13 advisories. Every outbound HTTP call.
4. **`symfony/*` and the remainder** — framework-internal, lower direct reachability.

### What was deliberately not applied, and a documented claim that no longer holds

`ci.yml` recorded a "conservative alternative" upgrade plan said to hold `laravel/framework` at
v11.47.0 with **zero** major crossings. **Re-measured by dry run, that is no longer true:**

```
package moves                 : 63
laravel/framework             : v11.47.0 => v11.57.0      (it DOES move)
major crossings               : sabberworm/php-css-parser v8.7.0 -> v9.5.0
```

The full plan is worse: 81 moves including `phpseclib/phpseclib 3.0.42 => 4.0.2`. The dependency
graph moved since that note was written, so the property that made the conservative set "the safer
first step" is gone. Neither is applied: this environment cannot install them at all (same egress
policy), and shipping 63 unexercised package moves that bump the framework on the strength of a dry
run would be worse than shipping nothing. Both are recorded in `ci.yml` with the real numbers for an
owner decision — see §16.

---

## 6. Additional vulnerabilities or defects discovered

Everything in this section was found during this pass and was not in any earlier report. None is a
tenant-isolation or authorization defect; several are *record* defects, which matter because a
false record is what lets a real failure hide.

### 6.1 `deployment/setup_20.04.sh` contains `DROP DATABASE` — **operational hazard, high**

`deployment/setup_20.04.sh:348`, inside `configure_db()`:

```sql
UPDATE pg_database SET datistemplate = FALSE WHERE datname = 'template1';
DROP DATABASE template1;
CREATE DATABASE template1 WITH TEMPLATE = template0 ENCODING = 'UNICODE';
```

It also runs `ALTER ROLE chatwoot SUPERUSER`.

This is **correct and standard for provisioning a brand-new Postgres server**, and dangerous in
this context only because the file sits beside `deploy.sh` in the same directory and the owner's
servers already exist. It is a *first-time installation* script, not an update script.

**Mitigation, carried into §13:** the runbook names `deployment/deploy.sh` as the only script to
run against the existing servers, and `setup_18.04.sh` / `setup_20.04.sh` as **must-never-run**.
This is a documentation and procedure fix; no script was modified, because the scripts are correct
for what they are for.

### 6.2 `deployment/deploy.sh` reviewed and found non-destructive — no finding, recorded because the directive asked

457 lines, reviewed for destructive behaviour. It is unusually careful: a disk-space preflight that
measures the **backup** filesystem rather than the app directory (a previous version passed a host
with a full `/var` and then failed *at* the backup), a pre-deploy `pg_dump` whose output is verified
with `pg_restore -l`, the outgoing revision recorded so a rollback has a target, a readiness-endpoint
gate after restart, and an `ERR` trap. It compares revisions **before** backing up, so a no-op
re-deploy costs nothing. No `rm -rf`, no `DROP`, no `TRUNCATE`, no `db:reset`, no `migrate:fresh`,
no `git reset --hard`, no force-push.

### 6.2b The new Store Syria `deploy.sh` reviewed against the same standard

The directive says to review all deployment scripts for destructive behaviour before recommending
execution, and a script written in this pass is not exempt from that. Scanned with the same
patterns used on Lynomia's `deployment/`:

- No `rm -rf`, `DROP`, `TRUNCATE`, `db:wipe`, `migrate:fresh`, `migrate:reset`, `db:seed`,
  `reset --hard`, or force-push.
- `git merge --ff-only` — refuses rather than rewriting history if the branch is not
  fast-forwardable.
- `php artisan migrate --force` — Laravel's `--force` suppresses the interactive production
  prompt; it does **not** mean force-drop.
- Writes outside the repository go to `$BACKUP_DIR` only: the dump and
  `last-deployed-revision`.
- Everything else it writes is a Laravel cache it regenerates (`config:cache`, `route:cache`,
  `view:cache`) and an idempotent `storage:link`.

**It has never been run against a real server**, because none is reachable (§14). Treat it as
reviewed-but-unexercised: `DRY_RUN=1` first (§16.4).

### 6.3 commonmark had zero test coverage — **gap, closed**

Covered in §5. Recorded here because it is a defect in the *evidence*, not the code: the suite could
not have detected a commonmark regression, so "run the suite against the upgrade" would have produced
a pass that proved nothing. Closed by `tests/Feature/Mail/MarkdownMailRenderingTest.php`.

### 6.4 The documented "conservative" upgrade plan no longer reproduces — **stale record, corrected**

Covered in §5. `ci.yml` claimed zero major crossings and `laravel/framework` held; measured, the set
moves the framework to v11.57.0 and crosses `sabberworm/php-css-parser` v8→v9. Corrected in place
with the measured numbers.

### 6.5 `vendor/yoomoney/*` are empty directories — **local environment gap, not a code defect**

`vendor/yoomoney/yookassa-sdk-php` and `vendor/yoomoney/yookassa-sdk-validator` exist on disk with
**zero files** and are absent from `vendor/composer/installed.json`: stubs left by an install that
could not complete, because the lock fetches them from `git.yoomoney.ru`, which answers `403` here.

`app/Services/PaymentService.php`, `app/Models/Utility.php`, `app/Models/Setting.php`,
`app/DataTables/OrderDataTable.php` and `app/Payments/RetiredGateways.php` all reference YooKassa
classes. Nothing in the suite exercises them, which is consistent with YooKassa being an optional
gateway — but it means **the YooKassa payment path is unexercised in this environment and would
fatal on a missing class if reached.**

**This is not caused by any change in this pass** and is not a defect in the repositories: on a
server with ordinary egress, `composer install` fetches both packages normally. It is listed so the
deployment preflight checks it (§13.1) rather than discovering it in production. Recommended
preflight assertion: `composer check-platform-reqs` plus a class-existence probe for the configured
gateways.

### 6.6 Three stale failure counts in `ci.yml` comments — **corrected**

Three comments asserted "eight tests fail", which the L2 fix made false (it is two). A comment that
states a wrong baseline is how a *ninth* failure becomes indistinguishable from the eight — the
exact failure mode the surrounding comment was written to warn about. Corrected.

One adjacent staleness was **left alone and is reported instead**: a comment at the gate step still
says the `tests` job "cannot pass until the dead `lahirulhr/laravel-payhere` package is resolved".
`.github/known-test-outcomes.json` already records that as no longer true. I did not edit it, to
keep this pass's diff attributable to this pass; it is a one-line correction for whoever next
touches that block, and the `tests-degraded` and `lockfile-reproducibility` jobs that exist to work
around PayHere are dead weight that could be removed with it.

### 6.7 The manifest's Unit test count was wrong — **corrected, and it was not a loss**

`.github/known-test-outcomes.json` recorded 181 Unit tests; the measured figure is **175 tests, 981
assertions**. Nothing was removed: `tests/Unit` holds **147 test methods at `HEAD` and 147 in this
change**, counted in both trees (the executed figure is higher because of data providers). The 181
is stale prose of the same kind the file already flags for its earlier "107 Unit / 1126 Feature"
figures. Corrected, with the verification method recorded so nobody has to re-derive it.

### 6.8 A tripwire's own remediation instruction would have reddened it — **corrected**

`tests/Unit/Packages/AddonMigrationRegistrationTest.php` said: *"When the owner fixes it: this test
goes red… Remove the LandingPage entry from `EXPECTED_STRANDED`."* That instruction assumed the fix
would be the one-character module edit. Because the fix went into the **host** instead, the module's
declared path is still absent and the module still ships migrations — so the entry must **stay**, and
following the old instruction would have broken the test. The docblock now says so explicitly, and
explains what the ratchet is still for: the host loader compensates for a wrong path, it does not
make one correct, and a module keeping migrations somewhere non-conventional would still be missed.

### 6.9 A tenancy tripwire fired, was re-read, and is not a finding — **recorded in full**

Making the three `owner_menu*` tables exist moved them out of `RawQueryTenancyInventoryTest`'s
undeclared-schema list into its "present, no `store_id`" list. That assertion's message asks a reader
to re-read the classification when this happens, so it was re-read rather than updated:

- These are the **platform owner's** landing-page navigation, not store data. `owner_menus` and
  `owner_menu_settings` discriminate on `created_by`; `owner_menu_items` hangs off `owner_menus` by
  a `menu_id` foreign key and carries no tenant column by design. None declares `store_id`, so no
  global scope was ever expected to reach them.
- **Every** unscoped raw statement on all three is an `INSERT` in one seeder
  (`OwnerMenusTableSeeder.php:35`, `:92`, `:115`), and a seeder is supposed to be cross-tenant — the
  same reasoning that file already applies to the ProductAffiliate backfill on `customers`.
- The one **live** read is Eloquent and owner-pinned: `guest.blade.php:96` does
  `OwnerMenuSetting::where('created_by', 1)->first()` for the platform's single guest layout.

So making these tables exist did not reveal a cross-tenant read surface; it removed six stranded
migrations and an HTTP 500 on `/login`. If a store-scoped raw statement on any of them ever appears,
the per-table ratchet sees it first.

### 6.10 Eight Lynomia specs cannot pass without public DNS — **portability finding, not a defect**

Found while root-causing the Lynomia regression run (§7.1), and worth recording because it will hit
any developer on a restricted network, not just this container.

`spec/services/website_branding_service_spec.rb` stubs HTTP with WebMock
(`stub_request(:get, 'https://example.com')`), which intercepts at the HTTP-library level. But
`WebsiteBrandingService` fetches through `SafeFetch::Fetcher`, whose `perform_request` calls
`SsrfFilter.public_send(...)` — and **`ssrf_filter` performs its own DNS resolution before
connecting**, because that is precisely its purpose: resolve the hostname, check the address is not
private, then connect to the address directly. A DNS lookup is not an HTTP request, so **WebMock
cannot intercept it.**

Where `example.com` does not resolve, `ssrf_filter` raises `SsrfFilter::UnresolvedHostname`, the
service returns `nil`, and all eight examples fail dereferencing `nil`. Measured here:
`getent hosts example.com` returns nothing, and the failure message names
`lib/safe_fetch/fetcher.rb:49`.

**It is not a defect and not a regression.** CI run 111 was green on all 20 jobs including all
sixteen rspec shards, so these examples pass on a runner with ordinary DNS. The product code is
behaving correctly — refusing to fetch a host it cannot resolve is the safe answer.

**Worth fixing anyway, cheaply**, if offline-capable specs are wanted: stub at the `SafeFetch` or
`SsrfFilter` seam rather than at the HTTP layer, or use a hostname the suite resolves itself. Not
done here — it is outside this directive's scope and touches a spec this remediation does not own.

---

## 7. Full test results

Measured in this container: PHPUnit 11.4.4 on PHP 8.3.6 against sqlite `:memory:` for Store Syria;
RSpec on Ruby 3.4.4 against PostgreSQL 16 with pgvector for Lynomia.

### Store Syria

| Suite | Result |
|---|---|
| **Unit** | **175 tests, 981 assertions, 0 failures, 0 errors** (and see §6.7 — the manifest's recorded 181 was stale prose, not a loss) |
| **Feature** | **2064 tests, 13,846 assertions, 2 failures, 17 skipped, 0 errors** |

**The two failures are the two declared ones**, and they are deliberately *not* L1 or L2:

1. `Auth\RegistrationTest::test_new_users_can_register` — a seeder fixture gap that exposes a latent
   robustness defect. `RefreshDatabase` runs migrations and not seeders, so `roles` is empty and
   `Role::where('name','admin')->first()` is `NULL`; the two `addRole` call sites are both
   null-guarded, so the role assignment is **silently skipped** and a merchant is created with no
   role and no error. In production the admin role *is* seeded, so this is latent rather than live —
   the silent tolerance is the finding.
2. `ProfileTest::test_profile_page_is_displayed` — a real application defect:
   `Attempt to read property "slug" on null` at `app/Http/Controllers/HomeController.php:367`
   (`route('landing_page', $store->slug)`). Needs a null-store guard there and at `:369`.
   `AddonMigrationStrandingTest::test_the_profile_page_failure_is_not_this_bug` exists to prove this
   is independent of L2, and still passes.

**And the Store Syria figures are not contaminated by this container's configuration** — asked
because the Lynomia run turned out to be (§7.1), so the same question had to be put to this side
rather than assumed away.

Three independent mechanisms insulate it, and the third was measured rather than reasoned about:

1. `phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:` with **`force="true"`**,
   which overrides any `.env` value.
2. `tests/bootstrap.php` asserts both and is **fatal** on anything else, and CI has a hard guard
   duplicated in each job that runs the suite — because `RefreshDatabase` runs `migrate:fresh`,
   which drops every table in whatever database the suite resolves.
3. The integration flags are **not** forced by `phpunit.xml`, and this container's `.env` does set
   `LYNOMIA_INTEGRATION_ENABLED` and `LYNOMIA_COMMERCE_EVENTS_ENABLED` — so this was a real risk,
   not a theoretical one. It does not materialise: `APP_ENV=testing` makes Laravel load
   **`.env.testing`**, a tracked repository file that sets no `LYNOMIA_*` or `COMMERCE_*` key at
   all, so both flags fall to their `config/lynomia.php` defaults of `false`. Probed from inside a
   booted test rather than inferred from the loader's documented behaviour:

   ```
   lynomia.enabled=false   commerce_events=false   base_url=empty   DB=sqlite/:memory:
   ```

   Which is exactly what CI sees, CI having no `.env` at all. The probe was a temporary file and is
   not committed.

**The known-outcome gate passes.** The blocking CI check was run locally against the real JUnit and
TeamCity logs:

```
testcases observed   : 2064  (manifest floor 1000)
failures observed    : 2     (manifest declares 2)
errors observed      : 0     (always expected: 0)
skips observed       : 17    (manifest declares 17)
OK: the observed outcome set matches the manifest exactly -- 2 declared failures,
    17 declared skips, 0 errors, and nothing undeclared.
```

Gate exit status **0**, run locally against the real JUnit and TeamCity logs from the measurement
above.

The gate's own wording is worth repeating, because it is the right frame for this whole section:
*"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
skipping exactly the tests the project has recorded, for the reasons it has recorded."*

**Baseline movement, and why deleting six entries is the opposite of suppression.** Failures went
**8 → 2** while the test count rose 2025 → 2064 (the L1 recovery-window suite, the fresh-install
regression file and the markdown rendering test). The manifest gate asserts its set in **both** directions: a listed entry that starts
passing *fails CI* until the entry is deleted, because the record has become a lie. So removing the
six NEW-ADDON-SCHEMA-02 entries was mandatory, not discretionary — and it is the one thing the
directive's "no silent rebaselining" rule is not about. Nothing was added to the manifest; no test
was skipped, disabled, quarantined or weakened; the skip set is byte-identical at 17. A second
ratchet (`tests/Unit/Ci/KnownTestOutcomesCheckerTest.php`) pins the same set independently so both
must change in one commit, and it was updated with the measurement it demands.

### 7.1 Lynomia Chat

**Measurement in progress at the time this section was committed, and it will be superseded by the
figures rather than left as a claim.** The full suite is ~12,181 examples in a single process; it
had run 25 minutes of CPU when this was written. Reporting it as pending is deliberate: the
alternative is a number I have not seen.

What is already established about the run:

- The test database had its pending migrations applied **additively** — `rails db:migrate`, with
  `db:migrate:status` reporting **0 pending** afterwards. No reset, no `db:schema:load`, no
  `migrate:fresh`. The directive's prohibition on resetting a database to make tests pass was
  observed.
- The run covers the **merged** result, including the Odoo accounting integration brought in by
  `62f21be3` (§1) — so it is a regression check on code this pass did not write as well as on the
  three commits it did.
- The relevant prior baselines, for comparison when the figures land: **12,181 examples / 1 failure
  / 70 pending** at the pre-Phase-7 merge, where the single failure was a global
  `ActiveStorage::Blob` count assertion; and CI **run 111, 20/20 green** on `2dce2f8f`, which is
  that failure fixed (§9). A result at or better than one failure is therefore the expected outcome,
  and anything worse is a regression introduced by the Odoo merge rather than by this remediation —
  which is precisely why the run was started before pushing anything to a default branch.

> **If the figures are not appended below in a later revision of this document, treat this
> subsection as unfinished and do not read §17 as covering the Lynomia suite.** §17's GO verdict
> rests on L1, L2 and the Store Syria measurements, all of which are complete and recorded above.

---

## 8. Cross-platform integration test evidence

The evidence that matters most, because it is the only evidence that is not two expressions of one
reading agreeing with each other.

### What was actually run

**Both real applications, booted together, driven against each other over real HTTP with no fake on
either side of the wire:**

| | |
|---|---|
| Store Syria | Laravel 11 on PHP 8.3.6, PostgreSQL |
| Lynomia Chat | Rails 7.2 on Ruby 3.4.4, PostgreSQL 16 + pgvector |

### Verified end to end

- **Provisioning** — a store link created through the real provisioner against the real Lynomia.
- **SSO** — the single-sign-on handoff, with `SSO_ALLOWED` enforced.
- **Platform API authorization** — the full matrix, 23/23 after three driver errors in my own
  harness were corrected (a missing `sha256=` signature prefix; reading `id` where `/store` returns
  `external_store_id`; and a `422` that was *correct* because `/orders` requires `number`). Those
  were my mistakes, not product defects, and are recorded as such.
- **Bidirectional commerce handshake.**
- **Four signed commerce events**, verified and processed, with HMAC signatures over
  `timestamp.body`.
- **Replay de-duplication** — a repeated delivery id rejected.
- **Tenant isolation** — one store's credential refused another store's data.
- **Suspend propagation** — a suspension on this side reaching Lynomia.
- **L1's whole lifecycle** — reproduction of the deadlock, then resume and re-authenticate
  succeeding through the legitimate workflow (§3).

### The cross-repository contract artifacts, re-hashed

These are the integration's sharpest silent-failure mode: one artifact per event, generated from
the producer's real code in Lynomia, copied into Store Syria, and pinned by SHA-256 in **both**
repositories so that regenerating it on one side without copying it to the other turns a test red
instead of shipping a quiet schema drift. Re-measured at the time of writing, at the two different
paths each repository keeps them under:

| Artifact | SHA-256 (first 16) | Result |
|---|---|---|
| `consent_changed.v1.json` | `05183533cbc234b9` | **identical** |
| `message_delivery_status.v1.json` | `5d7a5d897a68a435` | **identical** |
| `audience_fields.v1.json` | `7ca6f1905a80602f` | **identical** |

All three match byte for byte across `fullstackfull/lynomiachat` and `fullstackfull/syriastore98`,
and the `consent_changed` hash equals the value pinned in
`spec/contracts/consent_changed_contract_spec.rb`. No contract drift was introduced by this pass,
and `spec/contracts/` is in the gated CI shards (§9).

### What this run is worth, stated precisely

This is what found **L1** and **L2**. Neither had ever been caught by any suite in either
repository, which is the clearest possible demonstration that the fakes were agreeing with each
other rather than with reality. The *contract* half of blocker A1 is therefore no longer an unknown.

**It is not a staging deployment**, and it does not close A1. Everything that is a property of a
deployment rather than of the contract remains unproven: DNS, TLS termination, reverse-proxy header
and body handling, queue workers under supervision rather than in a foreground process, a real
WhatsApp provider account (prohibited), real data volumes, and both applications surviving a
restart with persisted state. A1's severity is reduced from "the single largest unknown" to
**bounded and deployment-environment-specific** — not to zero. See §10.

---

## 9. CI status

### Lynomia Chat — the gate now exists, and the code is green under it

Before this work, `lynomia-custom` — the **default branch** — ran **no workflow at all**.
Re-verified against the Actions API while writing this section: `run_foss_spec.yml` triggered on
pushes to `develop` and `master`, both inherited from `chatwoot/chatwoot`, and the repository has
**zero** `event=push` runs in its entire history; all 929 runs on `lynomia-custom` were
`event=schedule` and every one `conclusion=skipped` (the four fork no-ops: lock, stale,
ghsa-linear-sync, nightly_installer). So a regression merged to the default branch had nothing to
fail. The workflow now gates `lynomia-custom` on push.

| Run | Event | Branch | Commit | Result |
|---|---|---|---|---|
| 110 | `workflow_dispatch` | `claude/awesome-meitner-bop1lb` | `16c6c422` | failure, 19/20 — one `ActiveStorage::Blob` leak assertion |
| **111** | `workflow_dispatch` | `claude/awesome-meitner-bop1lb` | **`2dce2f8f`** | **SUCCESS, 20/20** |

All twenty jobs green on run 111: rubocop, brakeman + blocking bundle-audit, eslint, vitest
coverage, the new production-build step, and all sixteen rspec shards including `spec/contracts/`.

> **Read that table precisely, because an earlier draft of this section overstated it.** Runs 110
> and 111 were **manually dispatched on the working branch**, not pushes to `lynomia-custom`. So
> what is proven is that *this code* passes the full gate on a clean runner — the first real CI
> evidence this fork's code has had. What is **not** proven is the default branch running it: that
> first happens on the push or merge that carries these commits onto `lynomia-custom`, which is
> owner decision §16.1. The gate is installed and the code is green under it; the default branch
> has still never run it.

Two gaps were closed in the same pass:

- **A production build gate.** `pnpm vite build` now runs in CI as a **blocking** step with a
  manifest assertion. It was measured needing more heap than `deployment/deploy.sh` was giving it —
  the deploy was running 1–7% below OOM — so the deploy's app build heap went 4096 → 6144 MB.
- **`spec/contracts/`** is in the gated shards, so the cross-platform contract specs run on every
  push to the default branch.

**Not yet run:** CI has not seen `62f21be3` (the Odoo merge), because it is not pushed — see §1 and
§16.

### Store Syria — gates added, and the blocking gate verified locally

| Gate | Status |
|---|---|
| Known-outcome gate (`assert-known-test-outcomes.php`) | **Passes** — verified locally against real JUnit + TeamCity logs (§7) |
| Contract suite | Added as a **blocking** step in the `tests` job in this project |
| `composer audit` ratchet | **Tightened 70 → 58** |
| Addon migration registration tripwire | Passing; semantics corrected (§6.8) |

#### `main`'s CI is currently RED, on exactly one step, and this change is its fix

Read from the Actions API rather than inferred. All 13 `ci.yml` runs in this repository are
`event=push` on `main`; the most recent is **run 13** on `c1ea89b9` — the current `main` HEAD — and
its conclusion is **failure**. Job by job:

| Job | Conclusion |
|---|---|
| `Tests (REQUIRED -- real suites, clean install from composer.lock)` | **success** |
| `Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)` | **success** |
| `Is the PayHere blocker still there? (report)` | success |
| `Secret scan` | success |
| `Static checks (no vendor needed)` | **failure** |
| `Merge-base regression gate` | skipped (PR-only) |

And inside the one failing job, exactly **one** failing step:

```
step 6  composer audit (locked set, no install)   success
step 7  composer audit must not grow              FAILURE     <-- the only one
step 8+ (guards, php -l, contract checks)         skipped (changed-file gated)
```

Both required test jobs pass, including `Feature suite -- BLOCKING on the known-outcome SET` and
`The Feature outcome set must match the record -- BLOCKING`.

**So `main` is red solely because the advisory count (72) exceeded the ceiling (70)** — on a
`composer.lock` byte-identical to the commit it merged into, which is why it could not be fixed by
reverting anything. **The dependency commit in this pass is the fix:** commonmark takes the count
to 58 and the ceiling is lowered to 58, so that step passes on this code. That is the single
highest-value consequence of §5, and it is why §16.1 matters — the fix is sitting on a branch.

**Not yet run on this code:** CI has not seen `7f412573`. All 13 runs are on `main`, so the
workflow's push filter does not cover `claude/awesome-meitner-bop1lb`; the authoritative evidence
for the `tests` job is the local gate run in §7, and the audit figure is `composer audit --locked`
against the committed lock.

### One pre-existing CI observation, reported not fixed

The `tests-degraded` and `lockfile-reproducibility` jobs exist to work around the dead
`lahirulhr/laravel-payhere` package. PayHere is absent from `composer.lock` and the strict install
from the lock succeeds, so both jobs are dead weight. Recorded in the manifest already; removing
them is separate housekeeping (§6.6).

---

## 10. Corrected blocker register

`docs/development/lynomia-integration/PRODUCTION-READINESS-BLOCKERS.md` was reconciled against the
code. Four changes:

### Closed in this pass

| # | What | Evidence it is really closed |
|---|---|---|
| **C22** | **L1** — suspension was a one-way door | 16 examples, split so one fails if the mechanism is removed and the rest fail if it is widened; plus live HTTP resume and re-authenticate (§3) |
| **C23** | **L2** — six migrations stranded, `/login` 500 | 7/7 fresh-install examples that register nothing, **proven by reverting the fix** and reproducing the original error exactly (§4) |
| **B10** | Lynomia's seeding guard inverted when set to `false`, and the seeder's first act is `destroy_all` | **Verified applied** at all three sites — each now calls `Custom::SeedingPermission.allowed?`, which *casts* the value instead of trusting a non-empty string: `lib/seeders/account_seeder.rb:15`, `lib/seeders/inbox_seeder.rb`, `lib/seeders/reports/report_data_seeder.rb`. Kept in §B rather than moved to §C because §C indexes Store Syria's register and this fix lives in the Lynomia repository |

### Narrowed but explicitly **not** closed

**A1 — no run against a live Lynomia has ever happened.** The register now separates two claims
that were being conflated:

- **Retired:** the contract risk. The two sides were checked against each other's real code over
  real HTTP, and that is what found L1 and L2 (§8).
- **Still open:** everything deployment-specific. A1 closes by "a staging deployment of both sides,
  by the owner", which has not happened. Re-verified 2026-10-17: **no DNS record of any kind** for
  `chat.lynomia.com`, `lynomia.com`, `www.lynomia.com`, `storesyria.com`, `store-syria.com` or
  `syriastore98.com`; `~/.ssh` empty; no deployment credential; arbitrary egress policy-denied.

Severity reduced from "the single largest unknown" to **bounded and
deployment-environment-specific**. Not closed.

### Open, unchanged, and visible

| # | What | Why it stays open |
|---|---|---|
| **A2** | `APP_KEY` was published and has not been rotated | Rotation was explicitly **prohibited** by the directive. Owner action, and it must precede any real traffic |
| **A3** | GATE F — 15 tables that live code reads and writes have no migration | Unchanged. Six are the money tables behind the 17 declared skips; the skips are honest refusals to assert against absent schema, not suppression |
| **A4** | Store Syria is `PRE_UAT` for cart recovery | Unchanged |
| **B1** | The `flow_sessions` concurrent index on a production-sized table | Unchanged. Relevant to §13.6: a `CREATE INDEX CONCURRENTLY` cannot run inside a transaction and needs its own review before any migration run |
| **B11** | Lynomia's own `db/seeds.rb` plants a contact with an unreserved phone number | **Open.** Relevant to deployment: `db/seeds.rb` must not be run on the existing servers (§13.1) |
| **B3.1 / B3.2** | Already closed in Phase 6 as C15 / C16 | No change |

**No blocker was closed on the strength of a mocked test**, which the directive prohibited. C22 and
C23 each rest on a live reproduction plus a reversion proof; B10 rests on reading the applied code
at all three sites.

---

## 11. Store Syria server deployment requirements

**The headline finding, and what was done about it.** Store Syria had **no deployment story in the
repository at all** — no `deployment/` directory, no deploy script, no `Procfile`, no systemd units,
no nginx configuration, no rollback procedure — while Lynomia has all of these (§12). That asymmetry
was the single largest piece of deployment work outstanding.

**It is now partly closed**, because the directive authorizes preparing deployment automation and
creating missing deployment documentation. This pass adds:

- **`deployment/deploy.sh`** — a safe, idempotent update path for an installation that already
  exists. §13.3 lists its properties; they are deliberately the ones that make Lynomia's script
  safe rather than a different design.
- **`deployment/ROLLBACK.md`** — rollback and recovery, including per-migration reversibility for
  the Phase 7 set and the recovery path for an installation stuck suspended.

**Still missing, and still owner-side:** systemd units and nginx configuration for Store Syria, both
of which depend on the real hostnames and the server's existing layout (§14) and cannot be written
blind.

### Platform

| Requirement | Declared | Verified in this container |
|---|---|---|
| PHP | `^8.2` | **8.3.6** — satisfied |
| Laravel | `^11.9` | v11.47.0 locked |
| Composer extensions | 25, all satisfied | `composer check-platform-reqs` → **no failures** |

The 25 required extensions: `ctype`, `curl`, `date`, `dom`, `fileinfo`, `filter`, `gd`, `hash`,
`iconv`, `json`, `libxml`, `mbstring`, `openssl`, `pcre`, `phar`, `session`, `simplexml`, `sodium`,
`spl`, `tokenizer`, `xml`, `xmlreader`, `xmlwriter`, `zip`, `zlib`. (`ctype` and `mbstring` are
polyfilled, but install the native extensions — the polyfills are slow on hot paths.)

### Database — and a configuration hazard

`config/database.php` supports `sqlite`, `mysql`, `mariadb`, `pgsql`, `sqlsrv`.

> **`.env.example` is the Laravel skeleton default, not a production configuration.** It ships
> `DB_CONNECTION=sqlite`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
> `SESSION_DRIVER=database`, `FILESYSTEM_DISK=local`, `BROADCAST_CONNECTION=log`. Copying it to
> `.env` on a server would produce a single-file SQLite database and a database-backed queue. **The
> production `.env` must set a real database engine explicitly.** Whichever engine the existing
> server already runs is the one to keep — do not migrate engines as part of this deployment.

### Redis

`REDIS_CLIENT=phpredis` is configured, so the **native `phpredis` extension** is required if Redis
is used for cache, queue or session. It is not in the composer extension list above because it is
read from configuration rather than declared as a requirement — **this is a preflight check**, not an
assumption (§13.1).

### Queue workers

Required. The integration's outbound work is queued, and with `QUEUE_CONNECTION=database` on the
skeleton default there is no worker supervision in the repository either. Needs a supervised worker
(systemd or supervisor) running `php artisan queue:work` with a restart policy.

### Scheduler

**Required, and specifically required by this integration.** `LynomiaChatServiceProvider` registers
five scheduled commands, all `withoutOverlapping()`:

| Command | Cadence | Why |
|---|---|---|
| `lynomia:deliver-commerce-events` | every minute | the live path — an order's status changing is what an agent is waiting to see |
| `lynomia:deliver-consent-decisions` | every minute | a decision sitting in an outbox is a decision that has not taken effect |
| `lynomia:reconcile-events` | every 5 minutes | safety net behind the live path |
| `lynomia:reconcile-deliveries` | every 15 minutes | matches the reconciler's own grace window; reads **one** workspace, so cost does not grow with store count |
| `lynomia:reconcile-consents` | hourly | repairs rare losses — Lynomia's webhook sender swallows a failed delivery, so an opt-out that arrived during a deploy is otherwise gone |

**Without a cron entry invoking `php artisan schedule:run` every minute, none of these run**, and
the failure is silent: commerce events and consent decisions accumulate in their outboxes and
nothing reaches Lynomia. This is the single most important non-obvious Store Syria deployment
requirement.

### WorkDo module activation

Addon modules load only when listed in `add_on_managers`, which is **empty in a fresh database**
(NEW-ADDON-SCHEMA-03). `LynomiaChat` must be activated for the integration to function.
`coupon_requests` remains an undeclared table for the same reason (§6.9 context). Module activation
is step 7 of the runbook.

### Web server, reverse proxy, HTTPS, storage

- Document root `public/`; nginx or Apache with PHP-FPM.
- `php artisan storage:link`; `storage/` and `bootstrap/cache/` writable by the web user.
- HTTPS required: `Commerce::StoreUrl` enforces `https_required`, and
  `COMMERCE_TRUSTED_STORE_HOSTS` is the documented, deliberate exception for internal hosts.
- `APP_URL` must be the real external URL — it is read by the integration's own configuration.

### Migrations

`php artisan migrate --force`. **The L2 fix changes what this does**: addon migrations are now
registered from the host loader, so the six previously-stranded LandingPage migrations will run on
the next migrate. All six are `Schema::create` only, two additionally `hasTable`-guarded, with no
data writes (§4) — additive, and reviewed. **See §13.6: this is the migration review the owner must
read before authorizing.**

### Backup and rollback

**Nothing exists.** Must be written — proposed in §13.5 and §13.16.

---

## 12. Lynomia Chat server deployment requirements

By contrast, substantially complete in-repo: `deployment/` contains `deploy.sh`, systemd units for
web and worker with targets, nginx configuration, `ROLLBACK.md`, `INCIDENT.md`, Ubuntu setup
scripts, and test scripts for backup credentials and the drain probe.

### Platform

| Requirement | Value |
|---|---|
| Ruby | **3.4.4** (`.ruby-version`, pinned in `Gemfile`) |
| Rails | 7.2 |
| Node | **24.x** (`.nvmrc` 24.13.0) |
| Package manager | **pnpm 10.x**, pinned `pnpm@10.2.0` |
| PostgreSQL | **16**, and **`pgvector` is required** — `CREATE EXTENSION vector`. Missing it fails `db:schema:load` outright; in this container it needed `postgresql-16-pgvector` installed |
| Redis | Required — Sidekiq plus application caching |

### Processes

From `Procfile`:

```
release: POSTGRES_STATEMENT_TIMEOUT=600s bundle exec rails db:chatwoot_prepare && echo $SOURCE_VERSION > .git_sha
web:     bundle exec rails ip_lookup:setup && bin/rails server -p $PORT -e $RAILS_ENV
worker:  bundle exec rails ip_lookup:setup && bundle exec sidekiq -C config/sidekiq.yml
```

On the servers these are the systemd units `chatwoot-web.1.service` and
`chatwoot-worker.1.service`, grouped by `chatwoot-web.target`, `chatwoot-worker.target` and
`chatwoot.target`.

### Assets and frontend build

`pnpm vite build`. **Measured:** the app build needs more heap than the deploy script was allowing —
it was running **1–7% below OOM**. `deployment/deploy.sh` now sets 6144 MB (was 4096) for the app
build; `build:sdk` is unchanged at 4096. CI runs the same build as a blocking step with a manifest
assertion (§9). **The build server needs ≥ 8 GB RAM available to the build step**, or it will fail
intermittently in a way that looks like a flake.

### Platform API, webhooks, workers

- The platform workspace must exist and be configured: `LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID`,
  `..._INBOX_ID`, `..._SENDER_USER_ID`, `LYNOMIA_PLATFORM_TOKEN`.
- **Webhook subscription is self-healing but not automatic on first deploy.**
  `PlatformWorkspaceGateway::ensureDeliveryFeedSubscribed` is called from the reconciliation sweep,
  so it repairs itself on that cadence — this is blocker C19's fix, and C19 is exactly the failure
  of shipping a feed with no subscriber, green on both sides. Verify the subscription exists after
  deploy rather than assuming it (§13.14).
- Sidekiq must be running for the delivery feed to emit at all.

### Migrations, backup, rollback

`deployment/deploy.sh` takes a verified `pg_dump` before touching the database and records the
outgoing revision. `deployment/ROLLBACK.md` documents per-migration reversibility, including one
migration with a data backfill whose `down` drops the grandfather flags — read it before rolling
back, not during.

---

## 13. Deployment and rollback runbooks

Covering the eighteen areas the directive named. **This is a prepared package, not an executed one**
— §18 is NO-GO and nothing here has been run against any server.

> **Two scripts must never be run against the existing servers:**
> `deployment/setup_18.04.sh` and `deployment/setup_20.04.sh`. They are **first-time provisioning**
> scripts and `setup_20.04.sh:348` contains `DROP DATABASE template1` plus
> `ALTER ROLE chatwoot SUPERUSER` (§6.1). Correct for a bare server; catastrophic framing for one
> that already exists. **`deployment/deploy.sh` is the only script to run for an update.**
>
> **Also do not run** Lynomia's `db/seeds.rb` (blocker B11 — it plants a contact with an unreserved
> phone number) or any `Seeders::AccountSeeder` path on the existing servers.

### 13.1 Preflight validation

Run read-only, on both servers, **before** anything else:

- Record the current revision of each application and the output of `systemctl is-active` for every
  unit, so a rollback has a known-good target.
- Disk: ≥ 2 GB free on the **backup** filesystem and enough for a build. `deploy.sh` already checks
  this and measures the backup filesystem specifically — do not bypass it.
- **Store Syria:** `php -v` ≥ 8.2; `COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs`;
  confirm `phpredis` is loaded if Redis is configured; **confirm the configured payment gateways'
  classes exist** (§6.5 — `vendor/yoomoney/*` was empty here and the YooKassa path would fatal if
  reached); confirm `.env` sets a real `DB_CONNECTION` and not the skeleton `sqlite`.
- **Lynomia:** `ruby -v` = 3.4.4; `node -v` = 24.x; `pnpm -v` = 10.x; `SELECT * FROM pg_extension
  WHERE extname = 'vector'` returns a row; `redis-cli ping`; ≥ 8 GB RAM available to the build.
- Confirm DNS resolves and TLS is valid for the real hostnames — **this currently fails for every
  candidate hostname** (§14).

### 13.2 Server dependencies

Install native extensions rather than relying on polyfills (Store Syria), and
`postgresql-16-pgvector` (Lynomia). Do not use the `setup_*.sh` scripts to do it.

### 13.3 Safe application installation or update

- **Lynomia:** `deployment/deploy.sh`. It is idempotent, compares revisions before backing up so a
  re-run of an already-deployed revision costs nothing, and aborts through an `ERR` trap.
- **Store Syria:** `deployment/deploy.sh`, **new in this pass**. Run `DRY_RUN=1` first: it performs
  the whole preflight, prints the revision diff and the pending-migration list, and stops before
  changing anything. Its safety properties: refuses to run as root; refuses the skeleton
  `DB_CONNECTION=sqlite`; checks free disk on the *backup* filesystem rather than the app directory;
  compares revisions before backing up so a no-op re-run is free; dumps **and verifies** the
  database; records the outgoing revision; shows pending migrations against §13.6; fast-forwards
  only, never rewriting history; migrates with `--force`; rebuilds caches; restarts PHP-FPM and the
  queue; gates on `/login` returning 200; warns if no `schedule:run` cron entry exists; and aborts
  through an `ERR` trap. It reads `.env` with a parser that refuses a value whose meaning would
  depend on the reader, and passes the password only in the dump command's own environment.

### 13.4 Configuration requirements

`.env` on each server, managed outside the repository (§15). Every Lynomia integration key is listed
in §15; **nothing is committed and no value appears in this document or in any log.**

### 13.5 Backup verification

- **Lynomia:** `deploy.sh` does this — `pg_dump` then `pg_restore -l` to prove the dump is readable.
  `deployment/test_backup_credentials.sh` exists to prove the backup can authenticate *before* a
  deploy needs it. Run it first.
- **Store Syria:** `deploy.sh` now does it — the dump is verified with `pg_restore -l` (PostgreSQL)
  or a DDL/data check (MySQL), and the deploy aborts if the dump is empty or unreadable. For the
  **first** deployment, additionally restore it into a scratch database by hand: an automated
  readability check is not the same as a proven restore.

### 13.6 Database migration review — **the owner must read this before authorizing**

| Migration set | Effect | Risk |
|---|---|---|
| Store Syria — 6 LandingPage migrations (newly registered by the L2 fix) | `Schema::create` only, two `hasTable`-guarded; no data writes | **Low, additive.** Reviewed line by line, and the fix is measured to newly register **exactly this one module** — the other 50 paths it registers were already registered by their own providers (§4) |
| Store Syria — `add_recovery_window_to_lynomia_store_links` (new, L1) | adds 4 **nullable** columns to `lynomia_store_links` | **Low, additive.** No backfill, no default rewrite |
| Lynomia — Odoo integration migrations (merged in `62f21be3`) | new tables for the accounting integration | **Review before running.** Came from an upstream PR, not from this pass |
| Lynomia — `conversation_scope_enforced` on `custom_roles` | adds a column **and runs one `UPDATE` backfill** | **Read `ROLLBACK.md:125` first.** `down` drops the column and with it the grandfather flags; re-running `up` recomputes from current permissions |
| Lynomia — `flow_sessions` concurrent index (**blocker B1**) | `CREATE INDEX CONCURRENTLY` | **Highest-risk item, and already guarded.** It cannot run inside a transaction, takes real time on a production-sized table, and can leave an `INVALID` index if interrupted. `deployment/deploy.sh` **stage 5 detects an invalid index and refuses to continue past it**, and `ROLLBACK.md:184` says to `DROP INDEX CONCURRENTLY` the one it names and let the migration re-create it. So the risk is handled by the script rather than by the operator remembering — verified by reading both |

Run `rails db:migrate:status` / `php artisan migrate --pretend` first and compare against this table.

### 13.7 Module activation

Store Syria: activate `LynomiaChat` in `add_on_managers` — the integration does nothing until it is
listed (§11).

### 13.8 Application startup

Restart PHP-FPM (Store Syria) and `systemctl restart chatwoot.target` (Lynomia). `deploy.sh` gates
on a readiness endpoint afterwards rather than assuming the restart worked.

### 13.9 Background workers

Store Syria: supervised `queue:work`. Lynomia: `chatwoot-worker.target` (Sidekiq). Assert both are
active **and processing**, not merely running.

### 13.10 Scheduler

**Store Syria: add the cron entry.** `* * * * * cd /path && php artisan schedule:run >> /dev/null
2>&1`. Without it the five integration commands never run and the failure is silent (§11).

### 13.11 TLS and routing

Real certificates for the real hostnames; `APP_URL` and `LYNOMIA_BASE_URL` must match them. HTTPS is
enforced by `Commerce::StoreUrl`, so a misconfigured scheme fails closed rather than silently
downgrading.

### 13.12 Health checks

Store Syria: `/login` **200** — this is L2's acceptance criterion and the exact thing that was 500.
Lynomia: the readiness endpoint `deploy.sh` already polls.

### 13.13 Inter-platform credential exchange

`LYNOMIA_PLATFORM_TOKEN` and `LYNOMIA_WEBHOOK_MASTER_SECRET` must be present on the Store Syria side
and correspond to the configured platform workspace. Per-store commerce credentials are **derived**
from `(store_id, version)` — they are not configured, and bumping the version is the revocation
mechanism.

### 13.14 Webhook registration

Confirm the platform workspace's delivery-feed subscription exists after deploy. It self-heals via
the reconciliation sweep, but **C19 is precisely the bug of assuming it is there** — verify it.

### 13.15 Cross-platform smoke tests

Non-destructive, in this order, with `LYNOMIA_INTEGRATION_ENABLED` still **false** for the first
three:

1. Store Syria `/login` → 200; Lynomia readiness → OK.
2. Schedulers and workers active and processing.
3. Enable the integration for **one** test store only.
4. Provision → SSO → identity read.
5. One commerce event, verified end to end.
6. Suspend → resume (**L1's path**) → confirm `back_online=true`.
7. Confirm the delivery-feed subscription exists.

**Do not** connect a WhatsApp provider or send any real message — both are explicitly outside this
authorization.

### 13.16 Rollback procedures

- **Lynomia:** `deployment/ROLLBACK.md`, with the outgoing revision `deploy.sh` recorded. Read the
  per-migration notes before reversing anything.
- **Store Syria:** `deployment/ROLLBACK.md`, **new in this pass**. It documents the code-only
  rollback as the *preferred* path for Phase 7, because both Phase 7 migrations are additive: the
  old code ignores a nullable column it does not know about, and dropping the six LandingPage tables
  would re-create the L2 outage. The schema-restore path is documented with an explicit warning that
  it discards every write since the dump, and a note that after launch the correct move is a forward
  fix instead.

### 13.17 Recovery procedures

`deployment/INCIDENT.md` (Lynomia). For Store Syria, §4 of the new `deployment/ROLLBACK.md`:
draining and restarting queue workers, the silent-scheduler failure and how to confirm it, what to
do when `/login` returns 500 again, and how to recover an installation stuck suspended — now a
supported workflow rather than a hand-edited row, with an explicit instruction **not** to write
`state = 'active'` by hand, because the recovery window exists so that an installation is only ever
marked active after Lynomia has actually verified it.

### 13.18 Operational logging and monitoring

Watch the five scheduled commands' outcomes (silent non-execution is the failure mode), queue depth
and failed jobs, Sidekiq's dead set, the audit log for `lynomia_recovery_window_*` events, and the
delivery feed's stale/unmatched/unrecognised counters — those are recorded with reasons rather than
dropped, which only helps if somebody reads them.

---

## 14. Domain and infrastructure prerequisites

The directive said not to assume `chat.lynomia.com` is the deployed domain merely because a
configuration file mentions it, and to verify actual DNS and server availability. **Verified, and it
is not.**

### DNS, re-measured 2026-10-17

| Hostname | Result |
|---|---|
| `chat.lynomia.com` | **NO DNS RECORD** |
| `lynomia.com` | **NO DNS RECORD** |
| `www.lynomia.com` | **NO DNS RECORD** |
| `storesyria.com` | **NO DNS RECORD** |
| `store-syria.com` | **NO DNS RECORD** |
| `syriastore98.com` | **NO DNS RECORD** |

Not "unreachable from here" — **no record of any kind resolves**. The hostname in
`packages/workdo/LynomiaChat/src/config/lynomia.php` is a configured default and is explicitly
documented there as having previously been hardcoded in three places; it is not evidence of a
deployed installation.

### Access available from this environment

| | |
|---|---|
| SSH keys | `~/.ssh` **empty** |
| Deployment credentials | none |
| Arbitrary outbound egress | **policy-denied** — attempts to `lynomia.com:443` and `www.chat.lynomia.com:443` were answered `403` at the gateway |
| GitHub | scoped to the two project repositories only — which is also why the five dependency archives could not be fetched (§5) |

**So no owner server was inspected, because none is reachable and no credential exists.** The
directive authorized "inspecting accessible existing servers without destructive changes"; none was
accessible.

### What the owner must supply before any deployment

1. **The real hostnames** for both applications, with DNS A/AAAA records actually published.
2. **TLS certificates** valid for those hostnames (HTTPS is enforced, not optional).
3. **SSH access** to both servers for whoever executes the runbook.
4. **Confirmation of the existing database engine and version** on the Store Syria server — so the
   migration review in §13.6 can be completed against the real schema rather than a local one.
5. **Confirmation that the servers are the ones intended**, with their current application
   revisions, so §13.1 has a rollback target.

Without items 1–3, §18 cannot become GO regardless of code quality.

---

## 15. Required secrets and their handling procedure

**No real credential appears in this document, in any repository file, or in any log produced by
this pass.** The list below is of **variable names only**.

### Store Syria

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel application key. **See blocker A2** — the existing key was published and has not been rotated. Rotation was explicitly prohibited by this directive, so it remains an owner action and **must precede any real traffic** |
| `DB_*` | database connection. Must set a real engine — not the skeleton `sqlite` (§11) |
| `REDIS_*` | Redis connection |
| `MAIL_*` | mail transport |
| `APP_URL` | the real external URL; read by the integration |
| `LYNOMIA_PLATFORM_TOKEN` | **high sensitivity** — platform-API authentication to Lynomia |
| `LYNOMIA_WEBHOOK_MASTER_SECRET` | **high sensitivity** — the root of per-store webhook signing |
| `LYNOMIA_BASE_URL` | Lynomia's real base URL |
| `LYNOMIA_PLATFORM_WORKSPACE_ACCOUNT_ID` / `..._INBOX_ID` / `..._SENDER_USER_ID` | platform workspace identity |
| `LYNOMIA_INTEGRATION_ENABLED` | **keep `false` until §13.15 step 3** |
| `LYNOMIA_COMMERCE_EVENTS_ENABLED` | **keep `false` initially**; while off, nothing is recorded |
| `COMMERCE_TRUSTED_STORE_HOSTS` | the documented internal-host exception to `https_required` |
| `LYNOMIA_RECOVERY_WINDOW_SECONDS` | **new in this pass**; default 60, floor 5. Leave at the default |

Remaining `LYNOMIA_*` tuning keys — timeouts, retries, rate limits, retention, TTLs, notice
toggles — have safe defaults in `config/lynomia.php` and need not be set.

### Lynomia Chat

Standard Chatwoot secrets (`SECRET_KEY_BASE`, `POSTGRES_*`, `REDIS_*`, `FRONTEND_URL`, mail and
storage) plus the platform workspace configuration. `deployment/deploy.sh` reads `.env` for backup
credentials and **refuses to guess**: it aborts if a required key is missing or blank, and tells the
operator to single-quote a value whose interpretation would otherwise depend on the invoking shell.
`deployment/test_backup_credentials.sh` proves this works before a deploy depends on it.

### The no-credentials requirement, verified rather than asserted

The directive says never to include real credentials in repository files, documentation or logs.
Checked rather than claimed: every one of the 20 files this pass added or changed — including both
documents, the new `deployment/` files and `composer.lock` — was scanned for private-key headers,
JWT-shaped strings, long base64 and hex runs, `Bearer` tokens, and any
`PASSWORD=`/`SECRET=`/`TOKEN=`/`API_KEY=` assignment with a plausible value.

**Nothing credential-shaped was found.** Every pattern hit was a false positive of the scan itself:
file paths (the base64 character class includes `/`, so long repository paths match) and
`composer.lock`'s git commit hashes. The assignment scan returned empty.

`.env` is confirmed git-ignored (`.gitignore:34`) and untracked, so the local development
configuration in this container cannot be committed; the repository additionally has a CI guard
against a live `.env` appearing in the checkout or the index.

### Handling procedure

1. **Never** commit a value. `.env` is server-side only, owned by the application user, mode `600`.
2. Generate `LYNOMIA_WEBHOOK_MASTER_SECRET` and `LYNOMIA_PLATFORM_TOKEN` with a CSPRNG; deliver them
   out of band, never over chat, email or a ticket.
3. Rotate `APP_KEY` **before** real traffic (A2), as a deliberate operation with its own plan —
   re-encrypting anything encrypted with the old key. Not part of a deployment.
4. Per-store commerce credentials are **derived**, not stored: nothing to distribute, and a version
   bump revokes.
5. Keep the two enablement flags `false` until the smoke tests in §13.15 pass.
6. Scrub any credential that reaches a log, and treat it as compromised rather than merely exposed.

---

## 16. Outstanding owner decisions

Six, in the order they block things.

### 16.1 Where should the commits land? — **blocks the pull-request step**

Three commits are on `claude/awesome-meitner-bop1lb` in Store Syria and four plus a merge in
Lynomia. The directive says push to the default branches; the session's standing instruction names
the designated branch and forbids pushing elsewhere without explicit permission (§1). I took the
conservative reading. **Decide: merge the designated branches yourself, or authorize me to push to
`main` and `lynomia-custom` directly.**

### 16.2 Should the Lynomia Odoo merge be pushed? — **blocks CI on that code**

`62f21be3` merges the Odoo accounting integration (~10,000 lines from upstream PR #36) into the
working branch, and is pushed there. The Odoo work itself is already on `lynomia-custom` and passed
CI on its own pull request (run 109, success), so nothing unreviewed was introduced — but **CI has
never run on this merged result**, because `run_foss_spec.yml` triggers on pushes to
`lynomia-custom` and this is a working branch. Its local regression run is §7.1. Merging the working
branch into `lynomia-custom` is what puts the merged result in front of CI.

### 16.3 The broader Composer upgrade — **a real security trade-off**

58 advisories remain (§5), with `jmespath`, `phpspreadsheet` and `guzzle` the reachable ones. The
documented upgrade plan no longer reproduces its claimed properties: the "conservative" set moves
63 packages, bumps `laravel/framework` to v11.57.0 and crosses one major; the full set moves 81
including `phpseclib` 3→4. **Neither can be installed or exercised in this environment.** Options:

- **(a)** Accept 58 for pre-launch and do the upgrade as its own project with a full green suite.
  **Recommended** — pre-launch has no real traffic, and an untested 63-package move that bumps the
  framework is the larger risk.
- **(b)** Authorize the upgrade to be done and validated in CI, on its own branch, before deployment.
- **(c)** Ship it unvalidated. **Not recommended**, and I would not do this without an explicit
  instruction.

### 16.4 Store Syria's deployment tooling — **partly closed in this pass; review needed**

`deployment/deploy.sh` and `deployment/ROLLBACK.md` are new and have never been run against a real
server, because none is reachable (§14). **They need your review before first use**, and the honest
way to take them is: run `DRY_RUN=1` first, read what it reports, and only then run it for real.

What is still missing and cannot be written blind: **systemd units and nginx configuration** for
Store Syria, both of which depend on the real hostnames and the server's existing layout. Decide
whether to supply those details (§14) so they can be written, or to wire the script into the
process you already use.

### 16.5 `APP_KEY` rotation (blocker A2) — **must precede real traffic**

Prohibited by this directive, so untouched. It needs its own plan because anything encrypted with
the old key must be re-encrypted.

### 16.6 The two remaining declared test failures

`HomeController.php:367` needs a null-store guard (and `:369`); the registration role-assignment
tolerance needs a decision on whether a missing admin role should fail loudly. Both are small, both
are outside L1/L2's scope, and both are currently *declared* failures rather than hidden ones.
Decide whether to fix them in this phase or carry them.

---

## 17. Final remediation GO/NO-GO

# ✅ GO — for the engineering remediation only

Both release-blocking live findings are fixed, verified against the real applications, and
regression-tested. This verdict covers **the code**, and nothing beyond it.

| Item | Verdict | Rests on |
|---|---|---|
| **L1 — broken installation resume** | **CLOSED** | Reproduced first; fixed with a scoped recovery window that widens no gate; all twelve required transition scenarios evidenced; 16 examples / 34 assertions, one of which fails if the mechanism is removed and the rest if it is widened; resume and re-authenticate succeeding over real HTTP between both applications |
| **L2 — stranded migrations, `/login` 500** | **CLOSED** | Root-caused to an unvalidated `loadMigrationsFrom` path; fixed in the host, not the guarded vendored module; `/login` and `/register` both 200; **proven by reverting the fix and reproducing the original error exactly**; blast radius measured to exactly one module |
| **Verified dependency vulnerabilities** | **PARTIAL — by design** | commonmark 2.10.3 applied: 72 → 58 advisories, 14 → 0 on commonmark, all three mail-path-reachable cleared, ceiling *tightened* 70 → 58. Runtime validation is CI's, because the archives cannot be fetched here (§5). The broader upgrade is an owner decision with measured numbers (§16.3) |
| **Security boundaries preserved** | **YES** | `SSO_ALLOWED` untouched; no data route changed; credential verification still precedes everything, so a rejected credential cannot even burn a window; tenant isolation, ownership checks and platform scoping unchanged; the one new capability is one route, one use, seconds long, audited three times and shut in a `finally` |
| **Both applications verified together over real HTTP** | **YES** | §8, and it is what found both defects |
| **No silent rebaselining** | **YES** | Failures 8 → 2 because six tests now *pass*; the gate requires their entries to be deleted; skip set byte-identical at 17; no test skipped, disabled, quarantined or weakened; two independent ratchets updated in the same commit with the measurements they demand |
| **Corrections committed and pushed** | **YES for Store Syria** | Six commits on the designated branch, pushed. Lynomia's merge is tested but unpushed pending §16.1/§16.2 |

### What this verdict explicitly does not cover

- **It is not a deployment approval.** See §18.
- **The two declared Store Syria test failures remain** — a null-store defect at
  `HomeController.php:367` and the registration role-assignment tolerance. Both are outside L1/L2,
  both are *declared* rather than hidden, and both are §16.6.
- **58 advisories remain**, with `jmespath`, `phpspreadsheet` and `guzzle` the reachable ones.
- **commonmark 2.10.3 has not executed anywhere yet.** CI is where it first will.
- **`APP_KEY` is unrotated** (blocker A2) and must be dealt with before real traffic.

---

## 18. Final server deployment GO/NO-GO

# ⛔ NO-GO — and not for any reason in the code

The remediation is sound (§17). Deployment is blocked on infrastructure facts that cannot be
resolved from inside these repositories, and the directive did not authorize it in any case:

> *"This directive does not yet authorize executing database migrations or deploying the merged
> applications to the existing servers."*

Nothing was deployed, no server migration was run, no provider was connected and no message was
sent. That boundary was respected.

### The blockers, in the order they must be cleared

| # | Blocker | Who clears it |
|---|---|---|
| 1 | **No DNS.** All six candidate hostnames have no record of any kind (§14) | **Owner** — publish records, or tell me the real hostnames |
| 2 | **No TLS certificates** for hostnames that do not yet resolve. HTTPS is enforced, not optional | **Owner** |
| 3 | **No access.** `~/.ssh` empty, no deployment credential, egress policy-denied. No owner server was inspected because none was reachable | **Owner** |
| 4 | **Store Syria's deployment tooling is new and unexercised.** `deploy.sh` and `ROLLBACK.md` exist as of this pass but have never run against a real server; systemd units and nginx config still cannot be written without blocker 1 | **Owner review**, then a first `DRY_RUN=1` run |
| 5 | **The database engine on the Store Syria server is unconfirmed**, so §13.6's migration review is against a local schema rather than the real one | **Owner** |
| 6 | **Lynomia's Odoo merge is untested in CI and unpushed** (§16.2) | **Owner decision** (§16.1) |
| 7 | **`APP_KEY` unrotated** (blocker A2) | **Owner**, before real traffic |

### The exact minimum the owner must supply to authorize deployment

The directive asked for this precisely, so here it is as a checklist:

1. **Hostnames** for both applications, with DNS published and verifiable.
2. **TLS certificates** valid for them (or confirmation that the servers already terminate TLS).
3. **SSH access** for whoever runs the runbook, and confirmation of the application user and paths.
4. **Database engine and version** on the Store Syria server, plus confirmation that a backup
   target with ≥ 2 GB free exists.
5. **Current application revision** of each server, so §13.1 has a rollback target.
6. **A decision on §16.1** — where the commits should land.
7. **Explicit authorization** to run `php artisan migrate --force` (Store Syria) and
   `deployment/deploy.sh` (Lynomia) against the existing servers, having read §13.6.

With items 1–5 I can complete the migration review against the real schema, finish the Store Syria
systemd and nginx configuration, and hand back an exact command sequence. **Items 6 and 7 are
decisions only the owner can make.**

### What I recommend, in order

1. Answer §16.1 so the fixes stop sitting on a branch — **Store Syria's `main` CI is currently red
   on exactly the step this pass fixes** (§9).
2. Supply the infrastructure facts above.
3. Deploy **Store Syria first**, with both integration flags still `false`. The fix is one-sided
   (§2), so no coordinated ordering is needed and the blast radius is one application.
4. Then Lynomia, via its existing `deploy.sh`.
5. Then §13.15's smoke tests, enabling the integration for **one** test store only.
6. Only then consider provider connection and real messaging — both of which need their own
   authorization and neither of which this pass touched.

---

## Appendix — the standing restrictions this pass observed

Recorded because a later phase should not read them as oversights:

- No database was reset to make a test pass. Lynomia's test database had pending migrations applied
  **additively**; Store Syria's suite runs against sqlite `:memory:` behind a CI-enforced isolation
  guard.
- `APP_KEY` was not rotated or modified.
- No WhatsApp provider account was connected and no message was sent to any recipient.
- No real financial charge was created.
- No destructive fault injection was performed on any shared system.
- Neither application was exposed publicly.
- No security control was weakened to make a test pass. One attempt in an earlier pass to widen the
  authorization gate was refused by a permission control and was **not** routed around; the options
  were costed and put to the owner, and the design the owner then specified needed no exception.
- No advisory ceiling was raised. It was lowered.
- No blocker was closed on the strength of a mocked test.
