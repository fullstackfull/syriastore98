# P06-2B/2C review follow-up — three live items in code that had already been committed

**Date:** 2026-11-24
**Reviews:** independent `tenant-authz-reviewer` on `b329e43b` (disabled-module boundary) and
`039d17b4` (theme install). 13 findings; 3 were live defects, 1 was a regression the 2C fix itself
introduced, and 4 were test or documentation gaps in my own work.
**Every claim below was re-measured before it was acted on.** Two of the reviewer's figures were
estimates and are corrected here; one test I wrote from its report passed against the unfixed
handler and had to be rewritten.

---

## F1 — the guard could not see authentication supplied by its own sibling. **The highest-severity item.**

`DisabledModuleRouteGuard::requiresAuthentication()` read `$route->gatherMiddleware()` and nothing
else — which is where `route-census.php` looks, so the control and the measurement agreed with each
other and both were wrong.

This application authenticates module routes **three** ways, and only two of them put anything in a
route's middleware array. The third is `ModuleRouteManifest` + `ModuleAdministrativeRouteGuard`:
group middleware that resolves `auth('web')->user()` for any `METHOD uri` carrying an
`ADMINISTRATIVE` rule. **340 keys.** That manifest exists *precisely because* those routes had no
authentication of their own.

Measured on the real router, replaying PartialPayments' own two declarations in their own order
(`packages/workdo/PartialPayments/src/routes/web.php` — the resource at line 18 inside
`['auth','web','PlanModuleCheck:PartialPayments']`, and **the same resource again** at line 30
inside `['web']`):

```
GET partialpayments              middleware=[web]   gatherMiddleware says auth: NO   manifest: 'auth'
POST payment-status-change/{id}  middleware=[web]   gatherMiddleware says auth: NO   manifest: 'auth'
```

CLAUDE.md's rule is why: *"Later route declarations replace earlier ones for the same method+URI,
silently dropping the earlier middleware."* The second declaration wins, so `auth` **and** the
`PlanModuleCheck` entitlement are gone from the array.

**Effect:** a signed-in merchant with PartialPayments switched off still reached the whole
`partialpayments` admin resource and `POST payment-status-change/{id}` — which is P1-SEC-04's own
named effect. The guard's docblock claimed it closed "every screen and every writer"; it closed the
ones that said so in their own middleware.

**Fix:** one condition, consulting the manifest first. **The verb is the request's, not
`$route->methods()[0]`** — `Route::any` carries seven methods and the manifest keys each separately,
and `payment-status-change/{id}` has a rule for `POST` and **none** for `GET`, so reading the
route's first verb would make the writer read as anonymous. That asymmetry is what the second test
pins, with both halves asserted as premises so a manifest change cannot leave it vacuous.

**A correction to the review:** it put the figure at "≈386 ADMINISTRATIVE, from 583 − 197". The real
constants are **`ADMINISTRATIVE` 340** and **`ANONYMOUS_BY_DESIGN` 243**. The mechanism was right;
the arithmetic was an estimate.

---

## F11 — the theme archive could still pick its own target directory

`039d17b4` deleted an extraction that let one request overwrite an installed theme. It left the
other half: `$extractPath = base_path('themes/' . safeDirectoryName($filename))` with **no `=== ''`
check**, and `safeDirectoryName()` returns the empty string when nothing survives its allowlist.
Measured:

```
'....zip'      -> basename '...'       -> ''
'@@@.zip'      -> basename '@@@'       -> ''
'العربية.zip'  -> basename 'العربية'   -> ''
```

**The Arabic case is why this is not only an attack shape.** This product is bilingual; a theme zip
named in Arabic is an ordinary operator upload, and it silently took the themes root as its target.

With an empty name `$extractPath` is `themes/` itself, `is_dir()` is true so the mkdir is skipped,
and `moveExtractedFiles()` merges the archive's root folder **into the themes root** — recursing
into existing directories and overwriting files with `rename()`. **Both siblings already had this
check** (`ModuleController::install()`, `AddonController::destroy()`) and this one did not.

Operator-reachable (`manage-platform-addons` is `type === 'super admin'`), so the disposition is the
original one: not a privilege escalation, worth closing because one operator action should add a
theme, not replace another.

### The test I wrote from the report was wrong, and that is worth recording

My first draft used a single-level archive and **passed against the unfixed handler**.
`moveExtractedFiles()` moves the *contents* of `tmp/<rootFolderName>`, so with one level the root
folder *is* `zz9victim` and its contents land at `themes/front_end/...` — missing the victim
entirely. The overwrite needs the shape a real theme zip has: a wrapper directory **containing** the
theme directory. The review said so in one clause — "one extra nesting level" — and it is the
difference between a test that fails first and a test that is decoration.

---

## F10 — the 2C fix itself broke one archive shape

The one case where deleting that extraction made things **worse**. The final contents of `themes/`
used to be the **union** of the deleted extraction and the move; the move alone is partial, because
`$rootFolderName` was `scandir()`'s alphabetically first entry. Measured on a directory holding
`__MACOSX`, `greentic` and `.DS_Store`:

```
[0] => .DS_Store    [1] => __MACOSX    [2] => greentic
```

`.` is 0x2E and `_` is 0x5F, both below lowercase `a` at 0x61 — and every shipped theme is named in
lowercase. So for any Finder-built zip the move took the sidecar, and the real theme, previously
saved by the extraction that is now gone, stayed in the temp tree and was deleted with it:
`themes/<name>` held the sidecar's contents, the `Addon` row was still written with `status = 1`,
and the theme was broken.

And when the first entry is a **file**, `scandir()` on it returns `false`, which `array_diff()`
rejects as a TypeError on PHP 8.3 — an HTTP 500 leaving `themes/tmp_<uniqid>` behind. That half is
pre-existing, not introduced, and closes with the same change.

**Fix:** skip `__MACOSX` and `.`-prefixed entries, then fall back to the temp root when the pick is
not a directory — mirroring `ModuleController::install()`, which already had the second half and not
the first.

---

## F9 — the fail-open path stopped controlling silently

`ModuleAvailability` swallowed its `Throwable` with **no log**, while its sibling in
`PackageServiceProvider` logs `critical` for the same class of failure — and this project's own
P1-PLT-04 lesson was that a broken install *did not fail, it DISAPPEARED*. An availability control
that stops controlling is exactly that shape. It now logs a warning naming the module. The
direction stays open, deliberately: a database that cannot be read must not make every module's
routes refuse.

---

## F2 / F3 — a claim of mine that was circular

The `moduleOf()` docblock said the census "MEASURED the mapping as exact in both directions … no
mismatches either way across all 1,464". **It cannot have.** `route-census.php:75` *defines*
module-ownership with the same regex, so a route the regex misses is not discovered — it is counted
as first-party. Corrected, with the forward direction stated as what actually holds, and both blind
spots recorded and measured:

| class | measured | live? |
|---|---|---|
| closure action (`getActionName()` is `'Closure'`) | **11 of 137** module route files | mostly latent — the `auth:api` probes throw, as there is no `api` guard |
| module route pointing at a first-party controller | **exactly 2 files**; 1 live | `AutomaticOrderPrinting` re-declares `shippinglabel/pdf/{id}`; ownership covered by `OrderDocumentAccessGuard`, availability not |

Both fail **open**. Neither is fixable by changing the regex, because the declaring *file* is not
available at request time — only the action. Closing them needs the route-to-module mapping to come
from the manifest, which is the same change the storefront widening needs and belongs with it.

---

## Evidence

| | |
|---|---|
| failing first | F1: the two new guard tests; F10/F11: 4 of 4 theme tests. All failed before, pass after |
| after | guard suite **15/15**, theme suite **9/9** |
| mutations | **7 applied, 6 caught on the first pass**; the survivor is below |
| blocking suites | Unit **6,166 passed** + 1 skipped; Contract **45 passed** |
| working tree | `themes/` asserted to hold exactly the three shipped themes after every run |

### The mutation that survived, and what it taught

`F10: drop the not-a-directory fallback` **survived** — because the `.`-prefix filter already
removes `.DS_Store`, so `$rootFolderName` was a directory and the fallback never fired. The guard
was real and nothing pinned it: the same shape as the redundant `is_link` check in the module door,
which was *deleted* rather than left unpinnable.

Here the guard is genuinely needed, for a shape the filter cannot help with — a **flat** archive,
files at the root with no wrapper directory, where there is no directory to descend into at all.
`test_a_flat_archive_with_no_wrapper_directory_still_installs` covers it, and the mutation is now
**CAUGHT**. That test exists only because a mutation survived.

---

## Carried forward, not closed

- **F13 is the design input for the owner's widened 2B requirement** and is the most valuable part
  of the review. Its central measurement: **P5-FIN-4's reconciliation surface does not exist** —
  `app/Console/Commands/` holds no reconciliation command, and the traceability document says so in
  terms. `PaytmTransactionVerifier::verify()` is the closest existing shape to an authenticated,
  idempotent "was this previously initiated payment really captured" call and is the pattern to
  copy. So the reconciliation path the owner asked to preserve has to be **built**, not exempted.
- **F13's exemption list**, which a blanket refusal of all module routes would break: 87
  `payment-callback` keys, the emailed `customerpayment` link and its two write halves, `POST
  payment-status-change/{id}` (the merchant's manual settlement — "reconcile a previously initiated
  payment" literally), 6 `own-credential` and 2 `webhook` keys, `BackupRestore`/`ImportExport` as
  operator recovery tools, and LandingPage's 275 routes. The design consequence: once anonymous
  routes are in scope, `requiresAuthentication()` stops being the discriminator and the manifest's
  **category** becomes it — which also closes F1 and F2 structurally.
- **F7 — the two statically-loaded modules.** `PackageServiceProvider` force-*loads* `LandingPage`
  and `LynomiaChat` irrespective of any row, and three other first-party mechanisms keep saying
  LandingPage is on; a row that exists and says `0` would now make its 275 routes refuse while they
  disagree. Recoverable (the module screen is a host route, pinned by a test), so an inconsistency
  rather than a lockout. Belongs with the widening.
- **F6 — LynomiaChat schedules six of its own reconcilers**, which keep running for a switched-off
  module. For a bundled first-party integration that is probably right; it is a decision, and it is
  currently unstated. **For the owner.**
- **F12 — the theme test's `tearDown` baseline. CLOSED** rather than carried, because the blast
  radius is the three shipped themes: `setUp()` builds the baseline with `scandir(...) ?: []`, so a
  failed scan would leave it empty and the cleanup loop would treat every shipped theme as its own
  leftover. Unreachable in practice, and one line is cheaper than the argument.
