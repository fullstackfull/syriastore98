# Phase 2.5 — Security Closure Report

**Prepared:** 2026-11-11 · **Branch:** `claude/vibrant-bell-12tquy` ·
**Not merged. Not deployed. Phase 03 not started.**

Answering the owner's directive of 2026-11-11, section by section. One finding dominates everything
else in it, so it comes first.

---

## 0. Read this first

**The repository being public is not the most serious thing that was found.** Reviewing download
exposure, as the directive asked, established that the **live web server serves its own `.git`
directory**, and that the repository's **root commit contains a committed `.env`** carrying the
production `APP_KEY`, three `DB_*` variables and a mail-or-gateway password.

> **The production `APP_KEY` and database credentials are retrievable over plain HTTP from
> `https://storesyria.com/.git/` by any anonymous visitor.** No GitHub access, no credentials, no
> filename to guess, and no skill beyond an off-the-shelf `.git` dumper.

Eight measured links support that, each one a `HEAD` request with no body retrieved or a local
`git` read with no value printed. They are set out in
`docs/security/P1-SEC-40-INCIDENT.md` §3.2.1.

Three consequences for how the rest of this report should be read:

1. **The emergency action is to stop the live host serving `.git`.** A GitHub history rewrite
   changes nothing about what the server hands out. I cannot do either — no production access, and
   history rewriting is forbidden without separate authorisation.
2. **The leaked password hashes matter less than they would otherwise.** Database credentials grant
   the whole live database directly; 93 bcrypt hashes at cost 12 are a slower route to less.
3. **This re-rates an existing owner-deferred item.** The work queue has tracked `#120` /
   `P1-OWN-09` as *"the live `APP_KEY` and database credentials reachable at `3c39c160:.env`"*,
   deferred on the reading that exploiting it needs a clone of the repository. It needs one HTTP
   request.

---

## 1. Security incident containment status — P1-SEC-40

Full record, including the forensic metadata, the coordinated history-cleanup plan and the
incident-response recommendation: **`docs/security/P1-SEC-40-INCIDENT.md`**.

### 1.1 What was done — all non-destructive

No history rewritten, no force-push, nothing deleted from any remote.

| | |
|---|---|
| **Dump untracked from the working tree** | `git rm --cached storesyria_ecommercego.sql`. Merging removes it from every checkout that pulls, the server's included. It does **not** remove it from history, and by design cannot. |
| **Recurrence blocked** | `.gitignore` carries `*.sql`, `*.sql.gz`, `*.dump`, `*.bak`, verified against eight candidate paths with `git check-ignore`. The removal above is what makes the pattern bite: `check-ignore` reports a *tracked* path as not ignored, so the block was inert against this file until now. |
| **Apache deny rules** | `/.htaccess` and `public/.htaccess` — VCS metadata, dump and backup extensions including compressed variants, `storage/{logs,framework,app}`, composer manifests, editor swapfiles. Placed **outside** `<IfModule mod_rewrite.c>`, because the pre-existing `<Files .env>` deny sits inside it and would fail open if mod_rewrite were ever unloaded. |
| **nginx equivalent** | `docs/security/p1-sec-40-nginx-deny.conf`. Unused today — the host answers `Server: Apache` — because migrating to nginx discards every `.htaccess` protection silently. |
| **Regression test** | `tests/Unit/Security/DumpAndVcsPathsAreDeniedTest.php`, 30 cases: 18 paths that must be refused, 10 that must keep working. |
| **Second repository checked** | `StoreSyria_app98` is clean — no dump-shaped file tracked, none anywhere in its history. Dump patterns added to its `.gitignore` as prevention. Pushed as `40a7f4b`. |

> **None of this changes the live host until it is deployed.** The server keeps serving `.git`
> until the new `.htaccess` reaches it.

### 1.2 Two things in that work worth singling out

**A deny rule nearly broke every product image.** Denying `/storage/` wholesale was the obvious
move and would have been wrong: uploads are served as `/storage/uploads/<file>` through the
`public/storage` symlink via `Storage::url()`. Only `/storage/{logs,framework,app}` are refused, and
the 10 negative controls in the new test exist to keep it that way.

**Untracking the dump nearly deleted four tests, silently.**
`UserDeletionSafetyCoverageTest` read the dump to keep
`UserDeletionSafety::CASCADE_DEPENDENCIES` honest against production's real foreign keys, and was
written to *skip* when the dump was absent — correct, and better than passing vacuously. The
removal made that skip the normal case. **Measured:** with the dump moved aside, all four cases
skipped and the suite still **exited 0**, because CI runs `vendor/bin/phpunit --testsuite Unit` with
no `--fail-on-skipped` and the known-outcome manifest covers the Feature log only.

`docs/security/P0-USER-DELETION-CASCADE.md` had **predicted this exact failure** and recorded it as
an open gap against `#120`. It is now closed: the 15 `ADD CONSTRAINT` lines live in a committed
schema-only fixture, `tests/Support/Security/fixtures/production-users-cascades.txt`, verified at
extraction to carry zero hashes, emails, IP addresses, `INSERT` statements or value tuples. Four
cases read the fixture and run everywhere; a fifth cross-checks the fixture against the dump where
the dump is present, and only that one skips. Dump present → 5 passed; dump absent → 4 passed,
1 skipped.

The general lesson is worth keeping: **a security remediation that deletes an input can silently
delete the coverage built on it.**

### 1.3 What the dump contains — counts only, never values

29,790 data rows across 84 tables (198 exist in the schema). **93 bcrypt hashes, every one cost 12,
every one distinct** — customers 57, users 33, delivery_boys 2, lynomia_contact_verifications 1.
Cost 12 is above Laravel's default of 10 and roughly four times slower to attack; zero duplicates
means no account shares a password and there is no seeded default in the set.

Email addresses in 14 tables, led by `shetabit_visits` 186, `order_billing_details` 143,
`stores` 34. `shetabit_visits` holds **6,442 rows** of raw visitor requests with headers, user
agents and referers.

**`personal_access_tokens` carries 7 rows** — 4 `User`, 2 `DeliveryBoy`, 1 `Customer`, each with
`["*"]` abilities — stored as 64-character SHA-256 digests, with **zero** 40-character plaintext
tokens. Sanctum hashes before storage, so **the dump hands over no usable bearer token.**

**No live secret material:** zero matches for `sk_live_`, `pk_live_`, `AKIA`, `AIza`, `ghp_`,
SendGrid and Slack shapes, and zero for `base64:` — so no `APP_KEY` inside the dump itself. The only
gateway keys present are one Stripe **test** secret and one Stripe **test** publishable key.

> The pre-existing "no credentials in the dump" test is not evidence for any of that. It checks for
> `DEFINER=`, `IDENTIFIED BY` and `GRANT`, which are genuinely absent, and it cannot see password
> hashes or gateway settings rows. It passed throughout.

**One question is unresolved and it decides the notification obligation.** The work queue records,
under S51, *"the owner's statement that the data is demo data"* — made about the historical `.env`,
not about this dump. The directive opening this incident calls the dump "password hashes and
personal data". The file itself only shows that the records are *shaped* like production data.
Resolving it needs a comparison against the live database, which needs production access.
`P1-SEC-40-INCIDENT.md` §2.1 sets out what turns on each answer.

---

## 2. Dependency remediation results

**Branch: `claude/composer-security-same-major`. Not merged.** The directive reserves that: *"Do not
merge potentially breaking dependency changes without explicit approval."*

First, why this matters beyond the advisory count: **`/composer.lock` is world-readable on the live
host** (200, 611,406 bytes, §1 / `P1-SEC-40-INCIDENT.md` §3.2.2). An attacker holds the exact
version of every dependency, which is what turns a published advisory list into a targeting aid. The
same deploy that closes `.git` closes that too.

### 2.1 Result

**A blanket `composer update` was NOT used, and the dry-run is why.** It moves roughly 150 packages
and crosses **major** versions — `firebase/php-jwt` v6→v7 and `hamcrest/hamcrest-php` v2→v3 among
them, both permitted by the existing constraints. That is precisely the "potentially breaking" change
the directive reserves, and it is not a same-major update in any sense. So the three packages the
acceptance report identified were updated by name, without `--with-all-dependencies`, so nothing
transitive could be dragged across a major boundary.

| package | from | to | advisories before → after |
|---|---|---|---|
| `phpoffice/phpspreadsheet` | 1.30.1 | **1.30.8** | **12 → 0** |
| `mtdowling/jmespath.php` | 2.8.0 | **2.9.2** | **1 → 0** |
| `laravel/framework` | v11.47.0 | **v11.57.1** | **5 → 4** |

| | |
|---|---|
| packages changed | **exactly 3** — no additions, no removals |
| **major-version crossings** | **0**, verified by comparing the major component of every changed version |
| `composer.json` | **unchanged** — all three were already permitted by the existing constraints |
| advisories | **66 → 52**, across **22 → 20** packages |
| `AUDIT_CEILING` | **untouched at 58** |

### 2.2 The gate goes green without raising the ratchet

`ci.yml` sets `AUDIT_CEILING: '58'` and exits 1 above it. Before: **66, failing by 8.** After:
**52, passing with 6 to spare.**

That is the outcome worth having. The directive says *"Do not raise AUDIT_CEILING or suppress
findings"*, and the acceptance report had already noted that a lock-only route clearing the gate is
*"a better answer than raising a ratchet"*. This is that route, and it clears the gate by actually
removing 14 advisories rather than by moving the line — including **both criticals in
phpspreadsheet**, the package that carried the largest count of any single dependency.

phpspreadsheet going 12 → 0 also retires the reachability argument in `ci.yml` entirely, which is
worth noting because §4.3 shows half of that argument was false.

### 2.3 Verification

| check | result |
|---|---|
| dependency resolution | clean; `composer update` completed and wrote a consistent lock |
| **Unit** | **1619 passed**, 12,182 assertions — identical to pre-update |
| **Contract** (the Flutter API contract) | **38 passed**, 149 assertions — identical |
| **Feature / security** (`tests/Feature/Security/`) | **5150 passed, 1 skipped** (the declared one), **0 failures**, 21,839 assertions |
| `composer validate` | `composer.json` valid, lock in sync |

`laravel/framework` v11.47.0 → v11.57.1 is ten minor versions, and that is where breakage would
live if anywhere — which is why the suites, not the diff, are the evidence here.

### 2.4 What is still open, and the honest limits of this

**52 advisories remain and the gate passing is not the same as them being addressed.** The largest
remaining clusters are `guzzlehttp/guzzle` 9, `symfony/http-foundation` 6, `dompdf/dompdf` 6,
`phpseclib/phpseclib` 5, `laravel/framework` 4 (one of its five survived the bump),
`guzzlehttp/psr7` 4, and the rest of symfony at 12 between eight packages.

Several of those are same-major bumps too — guzzle 7.10→7.16 and psr7 2.8→2.13 would clear 13
between them — and the acceptance report records a symfony route worth 66→53 on its own. They were
**not** applied here, for one reason: each additional package widens the blast radius of a change
nobody has approved yet, and the three named ones already clear the gate. A second tranche is a
sensible follow-up once this one is accepted, not something to bundle in uninvited.

**None of the four criticals was ever measured as reachable** — that finding from the acceptance
report stands, and §4.3 corrects the one piece of its reasoning that was wrong.

**One thing found while validating, pre-existing and not from this change.** `composer.json` carries
two **unbound `*` constraints**: `myfatoorah/laravel-package` in `require` and `laravel/pint` in
`require-dev`. Any future version satisfies them, including a breaking one or a hostile one if either
package were ever compromised — and a payment integration is not where you want that. `composer
validate` already warns about the first. Worth pinning to a caret range, as a separate small change;
it is not bundled here because changing a constraint is a `composer.json` edit and this branch
deliberately contains none.

---

## 3. Remaining cross-tenant risks

### 3.1 The two reported reads — both real, both fixed

| handler | route | middleware | what crossed |
|---|---|---|---|
| `ProductController::getProductsVariantQuantity()` | `GET get-products-variant-quantity` | `['auth','xss','setlocate','verified']`, **no permission check** | `quantity` as an **integer** — a competitor's exact variant stock, for any product id on the platform |
| `ProductController::product_price()` | `POST {storeSlug?}/product_price` | `[ActiveTheme,'themelanguage']` — **public** | store A's storefront priced store B's product under A's settings, tax and flash-sale rules |

**They are not equally severe, and the fix says which is which.** The quantity endpoint is a real
confidentiality leak — a storefront publishes "In Stock" or "Out Of Stock", never the number, and no
public route exposes that figure. `product_price` returns data that is already public, so it was an
**isolation** failure; it is fixed because computing one tenant's price under another tenant's rules
is wrong, not because the price was secret.

Neither predicate is built from a second request field: `getCurrentStore()` for the merchant route
is the authenticated principal, and `$store->id` for the storefront route is the storefront being
browsed — the same source `getRouteStoreId()` uses.

### 3.2 A flaw in the first version of my own fix

`Product::where('store_id', getCurrentStore())` **fails open.** `getCurrentStore()` returns null for
a principal with no `current_store` who is not `super admin` and owns no store, and Laravel's
`where($column, null)` does not compare against NULL — it rewrites itself to `whereNull($column)`.
Since `products.store_id` is nullable, the predicate selected **exactly the products nobody owns**
rather than none: `null_tenant => 'open'` by a different road. The handler now refuses an
unresolvable tenant instead of building a query from null, driven by a test with an orphan product.

### 3.3 `VariantDelete()` is dead code, and that was hiding a trap

It gates on `auth()->user()->can('Delete Variants')`, and Laratrust runs with
`permissions_as_gates => false` (`config/laratrust.php:265`). **Measured** on a merchant granted
that exact permission: `isAbleTo()` is `true`, `can()` is `false`. The handler refuses everyone — a
broken feature, fail-closed.

The trap: `can` → `isAbleTo` is the obvious tidy-up, and it would activate a handler that resolved a
caller-supplied `$product_id` with a bare `Product::find()` and then **wrote**, rewriting
`variants_json` on another tenant's product. **The predicate was added while the handler was still
unreachable**, so the permission fix cannot ship a cross-tenant write with it. Both facts are
pinned.

### 3.4 The census — and the two wrong numbers it gave first

Recorded because the method failed twice, and because a census that fails quietly is how this
project has previously convinced itself a surface was clean.

| attempt | method | result | why it was wrong |
|---|---|---|---|
| 1 | grep `Product::find` | **49** sites | counted comments *about* past fixes as findings; ignored where the id came from |
| 2 | + "no ownership guard within 12 lines" | **38** sites | `destroy()` re-reads the **route-bound** model; the ~16 payment callbacks take `$product->product_id` from an order-items collection they already own; `product_cartlist()` is guarded by P01-COMMERCE-SEC-01 just **beyond** 12 lines, because its comment is long. A window heuristic measures comment length, not safety. |
| 3 | require the id to be **caller-supplied** (`$request->` directly, or a variable assigned from it in the same function) | **4** sites, **3 already guarded** | the fourth was `product_cartlist`, guarded just outside the window |

So the two handlers in §3.1 were **the last unguarded caller-supplied sites in
`app/Http/Controllers/`** — a measured claim, not an impression.

### 3.5 One vendored finding — REPORTED, NOT FIXED

Same census over **111 controller files** in `packages/workdo/*/src/app/Http/Controllers/`: 4
caller-supplied sites, 3 guarded, **1 not**.

**`AuctionProduct/.../AuctionProductController.php:1585`, `addProductbidamount()`**, routed
`POST /{storeSlug}/product-bid`. It checks `auth('customers')->user()` and redirects to login
without one — so it authenticates, but **authentication is not tenancy**.
`Product::find($request->product_id)` carries no store predicate and the handler then **writes**:
`$product_bid->product_id = $request->product_id`. A customer signed in to store A can bid on store
B's auction product.

**Not fixed, deliberately.** CLAUDE.md forbids editing vendored `packages/workdo/` as a shortcut,
and a cross-tenant write in a payments-adjacent module is not a shortcut-sized change. Two things
bound it and neither removes it: the module must be active for its routes to load (an
`add_on_managers` row — which is also why **no test run reaches it**, so the suite is structurally
blind here), and a bid is not a purchase. **Owner decision.**

### 3.6 StoreController — explicit authorization, and a correction to the premise

The directive asked for explicit authorization on the sensitive `StoreController` edit/update
operations. **Measured against the shipped controller, the new 8-case test file gives 6 passes and
2 failures** — so the premise was broader than the defect, and saying so is more useful than
implying a bigger repair.

The six that pass were already passing: `type == 'super admin'` genuinely refused merchants and
staff. **There was no cross-tenant authorization hole here**, unlike §3.1. The two failures are the
defects, and they are 500s on an authorization surface: `PUT /stores/{unknown}` dereferenced
`$store->created_by` on null, and `GET /stores/{unknown}/edit` dereferenced `$user->id` on null.

What the change does: adds those two null guards, and routes the operator test through one named
helper instead of an inline string comparison repeated across the controller — which is how
`UserPolicy` came to have four methods checking operator status four different ways. **Treat the six
passing cases as regression pins, not as evidence of a repair.**

Two further findings on that surface, recorded rather than changed:

- **`edit($id)` takes a USER id; `update($id)` takes a STORE id**, on the same resource route. The
  view bridges it by submitting `$store->id`, so the happy path works — but `PUT /stores/{userId}`
  typed by hand writes to whichever store carries that number. `update()` now refuses an
  unresolvable id and an owner link that does not hold. The parameter meanings are left alone:
  changing what a route parameter means is a behavioural change, not a security fix.
- **The operator comparison is left byte-identical on purpose.** `UserPolicy::isOperator()`
  normalises case and a trailing space, and MySQL's `utf8mb4_unicode_ci` treats `'Super Admin'` as
  equal to `'super admin'` — so such a row is the operator to the database and a stranger to this
  `==`. Normalising would **admit** an actor currently refused, and the instruction is *"Do not
  broaden operator permissions."* The divergence is pinned by a test that explains itself, so a
  future consistency pass has to read the note before closing it. **Owner decision.**

### 3.7 Financial-record deletion — now prohibited

Instruction: *"Treat financial-record deletion as prohibited pending an approved retention and
correction policy."* `BankTransferController::destroy()` now **refuses every caller**, operator and
record owner included.

This **inverts an earlier repair, and that is not a regression.** F-1 found
`if ($order) { $order->delete(); }` — decoration, because route-model binding guarantees `$order`,
so any authenticated caller erased any merchant's payment record. It was first repaired to admit the
operator and the record's owner, matching `PlanOrderDataTable::query()`. The two legitimate-path
tests in `DestructiveRoutesTenantGuardTest` were **inverted to assert refusal**, and the first of
them had closed with: *"(Whether they SHOULD be able to is a separate audit question, recorded for
the owner and not decided here.)"* The owner decided it.

**The delete button was removed** from `resources/views/plans/action.blade.php` in the same change.
Leaving it would be a control that looks available and never works, and the next person to meet it
would most likely re-admit a caller in the controller to make it function — the one thing the
instruction forbids. A `@permission` guard would also be the wrong shape: the action is prohibited
for everyone, not reserved for someone.

There is no retention or correction policy in `docs/security/` yet, so there is nothing to delete
*under*, and a hard delete leaves no audit row: `PlanOrder` has no `SoftDeletes` and nothing writes
an `AuditLog` on that path.

### 3.8 The independent review found a live bypass of §3.7 — and three defects in my own fix

`.claude/agents/tenant-authz-reviewer.md`, run on the uncommitted change. Fourth time in five
packages that it found something in the *fix* rather than the shipped code. Full record: S55 in
`docs/security/P01-SECURITY-WORK-QUEUE.md`.

**The prohibition in §3.7 held on one route, not on the operation.** My docblock claimed *"refuses
EVERY caller"*. Two other paths hard-deleted `PlanOrder` rows:

- **LIVE, merchant-reachable.** `Api\DashboardController::DeleteStore` ran
  `PlanOrder::where('store_id', $store->id)->delete()`, routed `DELETE api/admin/deletestore` under
  `custom.auth` — which the **shipped Flutter admin app** calls with a `User` Sanctum token. Its own
  predicate (`created_by = $user->id`) is satisfied by a merchant deleting **their own** store, so a
  merchant with two or more stores erased their own payment history. The decisive detail: **the web
  twin already had that exact line commented out with the exact rationale** ("PlanOrder IS BILLING …
  they outlive the store"), and `StoreController:994` even points at the API twin. The decision had
  been applied to one door. Closed identically, in the routed method and in the unrouted
  `DeleteStore_backup` that carried the same line.
- **Operator-reachable.** `BulkDeleteTenantGuard` returned `$next($request)` for an operator
  **before** its platform-global denial, so `type=plan-order` passed although `plan-order` is in
  `PLATFORM_GLOBAL`. Closed with a `FINANCIAL_RECORDS` check **ahead of** the bypass — one type
  narrowed, every other platform-administration type untouched, with a companion test pinning that
  the operator still reaches `country`, `state`, `city` and `newsletter`.

**Three defects inside my own fix:**

| | what | severity |
|---|---|---|
| a | `VariantDelete()`'s new predicate guarded only the `variants_json` rewrite; `ProductVariantOption::find($id)->delete()` sat **outside** that block on a bare caller-supplied id. **And my docblock told the next reader to "change `can` to `isAbleTo`, keep the predicate"** — followed verbatim, that ships a cross-tenant destructive write. Code and note both fixed; the option now resolves through the owning product | latent, but the instruction made it likely |
| b | `productVariant()`, the POS modal **five lines above** the reported handler, had the same defect and renders any tenant's product name, image, category and price. It does not render the stock integer, so §3.1's claim survives | live, low |
| c | The fixed quantity handler resolved the tenant twice and dereferenced `$store->slug` unguarded — a 500 when `current_store` points at a deleted store | hardening |

Two overclaims of mine were corrected rather than softened (§3.6's mismatched-id claim, and a test
limb that could not fail on a non-variant fixture — a variant companion case was added where it
does). One review point was **stale, not wrong**: it reported the null-tenant branch as untested,
having read the file mid-change; verified by reverting that branch alone, the test reddens.

A pre-existing gap in the method I was already editing: `StoreController::update()` validated
`'email' => 'required'` only, while its sibling `store()` uses `required|unique:users,email`. So the
operator could point merchant B's address at merchant C's existing one, and both login and the
password-reset broker resolve by `User::where('email', …)->first()`. Now
`required|email|unique:users,email,{id}`.

### 3.9 The two new regression tests passed vacuously three times

Recorded because every failure mode is one this project has met before, and because a test that
passes for the wrong reason is worse than no test.

1. **A precondition the exposure needs.** `DeleteStore` answers *"You cannot delete your last store"*
   before any delete, so a one-store fixture passed with the bypass fully restored. The fixture now
   creates a second store, and a **premise assertion** checks the store really was deleted.
2. **A route that does not exist under test.** `POST /bulk-delete` is **404** in PHPUnit — it belongs
   to a vendored WorkDo addon, and no module loads without an `add_on_managers` row. The HTTP case
   was measuring a 404. It now drives the middleware directly.
3. **Two ways of being authenticated that are not the same way.** `handle()` reads the **matched
   route**, then `auth()->user()` — not `$request->user()`. A bare `Request::create()` has no matched
   route and `setUserResolver()` does not reach `auth()`, so the guard bailed twice without
   inspecting anything.

Final discrimination: with both fixes reverted the two bypass cases **fail** and the
platform-administration control still **passes**; restored, all 13 cases pass.

### 3.10 The five acceptance fixes are preserved

F-1 `BankTransferController::destroy` (now tightened further, §3.7) · F-2
`MenuController::deleteItem` · F-3 `MenuItemsController::deleteCustomLink` · F-4
`PlanController::destroy` · F-5 `UserController::edit`. All still present; `DestructiveRoutesTenantGuardTest`
and `CrossTenantPolicyGridTest` still drive them.

---

## 4. CI results

### 4.1 Gate by gate

| gate | result |
|---|---|
Measured on `claude/vibrant-bell-12tquy` at `69da2799`, with `vendor/` resynced to that branch's
own lock first — so these are this branch's numbers, not the dependency branch's.

| gate | result |
|---|---|
| **Unit** (blocking) | **1619 passed**, 12,182 assertions |
| **Contract** (blocking, the Flutter API contract) | **38 passed**, 149 assertions |
| **Feature** | **8,436 testcases — 8,433 passed**, 2 failed, 1 skipped, 43,485 assertions |
| **known-outcome gate** | **OK** — "the observed outcome set matches the manifest exactly: 2 declared failures, 1 declared skip, 0 errors, and nothing undeclared" |
| coverage matrix `--check` | **green** — 295 surfaces, **225/24/28/8/10/0** |
| `check-distinct-surface-captures` | **green** |
| `detect-refusal-toast-captures` | **green** — **932 captures** checked |
| `composer audit must not grow` | **RED on this branch** — 66 advisories against `AUDIT_CEILING: '58'`, failing by 8. **Green on `claude/composer-security-same-major`** at 52. See §2 |

**Nothing was added to `.github/known-test-outcomes.json`.** The 2 failures and 1 skip are the
pre-existing declared set. The gate's own closing words are worth repeating, because they are the
difference between this report and a claim of readiness:

> This is NOT a statement that the suite is green. It is a statement that the suite is failing and
> skipping exactly the tests the project has recorded, for the reasons it has recorded.

Two Unit failures appeared during this work and both were caused by the change, so both are
recorded rather than worked around:

- `ClaudeConfigurationTest::test_claude_md_does_not_restate_the_large_documents` — my correction to
  the guard entry pushed `CLAUDE.md` past its 300-line ceiling. The entry was **condensed** to fit,
  not the ceiling raised. Now 299.
- `ActionPartialContractTest::test_every_design_system_action_control_has_an_accessible_name` — a
  **floor** of 98 named controls, and removing the prohibited plan-order delete button left 97. The
  floor was lowered by **exactly one**, with the removal named in the test, because the floor exists
  precisely so that deleting controls cannot satisfy "none unnamed". An unexplained decrement is the
  failure it is there to catch.

### 4.1.1 One Feature failure was an artefact of my own process, not a regression

A Feature run begun early in this work reported an **undeclared** failure —
`StateChangingGetInventoryTest::test_no_undeclared_host_get_route_writes`, naming
`GET api/admin/customerlist` and `GET api/admin/delivery-order-list` as handlers that write. The
same file passed 4/4 in isolation immediately afterwards, which is an order-dependence signal and
not something to wave through.

**Diagnosed, not guessed.** That test slices each handler's body using
`ReflectionMethod::getStartLine()`/`getEndLine()` — line numbers from the class **as loaded into the
PHP process** — against the file read **fresh from disk** (`file($file)`). I inserted roughly twenty
comment lines into `Api/DashboardController.php` *while that suite was in flight*, so the slice used
stale line numbers and landed on unrelated code that happened to match a write pattern.

So the figure in §4.1 comes from a run started **after** every source edit. The lesson is mine:
**do not edit source while a suite is running**, and treat a suite failure that will not reproduce
in isolation as a question about the harness rather than an answer about the code. It also means
that test is quietly fragile under concurrent edits — worth knowing before someone else meets it.

### 4.2 Refusal-toast detection is now a CI gate

The owner asked for it *"after verifying its runner dependencies and deterministic results."* Both
were verified, and so was a third thing that matters more than either — that it catches something.

| | |
|---|---|
| **Dependency** | Pillow only; `os`, `re`, `sys`, `json` are stdlib. Installed into a **venv**, because PEP 668 marks a distro Python externally managed and `pip install` into it can simply refuse. Pinned `Pillow==12.3.0`, `--only-binary` so nothing compiles. The pin is load-bearing beyond reproducibility: under 12.3.0 the detector already emits a `DeprecationWarning` that `Image.getdata` is removed in **Pillow 14 (2027-10-15)**, so an unpinned install would eventually turn this gate into an error. |
| **Determinism** | Three consecutive runs over the committed captures: exit 0 each time, **byte-identical stdout** compared by sha256 of the output. A pure function of the files — fixed relative crop, fixed thresholds, no clock, no network, no ordering dependence. |
| **Efficacy** | Driven in both directions. A toast-red block painted into the top-right corner of one cited capture made it **exit 1 and name that file**; restoring the capture returned it to exit 0, with the evidence tree byte-identical to `HEAD` afterwards. It checks **932 captures**. |

**Exit 2 fails the build, explicitly.** The detector exits 2 when its *premise* fails — Pillow
missing, or the `VERIFIED` table in `build-coverage-matrix.py` no longer parseable — meaning
**nothing was checked**. S51 in the work queue is exactly that failure: a guard whose premise
expired went silent and its silence was filed as a passing release gate. A bare `run:` would fail on
exit 2 too, but with the same red X as a real finding, and the difference between "your evidence is
fake" and "this gate checked nothing" is the point.

The stale comment on the sibling step, which said the detector "stays a documented manual run"
because adding an image library was "a dependency decision for the owner", is corrected to record
that the owner made it.

### 4.3 A refuted security justification in CI, corrected

`ci.yml` justified phpspreadsheet as not-reachable partly with *"the vulnerable auto-detection
branch is never entered"*. **That clause is measurably false and is deleted rather than softened,
because a reader would have relied on it.** Verified independently, four links:

1. **Eight** import controllers validate `'csv_file' => 'required|mimes:xlsx,xls,csv,txt'` — `txt`
   explicitly accepted.
2. `config/excel.php` is **not published**, so the package defaults apply, and
   `vendor/maatwebsite/excel/config/excel.php`'s `extension_detector` maps 16 extensions — `txt` is
   **not** among them, so the reader type resolves to NULL.
3. `ReaderFactory.php:33` is `$readerType ?: static::identify($file)` and `:82` is
   `IOFactory::identify($temporaryFile->getLocalPath())` — an **explicit fallback into
   auto-detection**, which a merchant uploading `evil.txt` enters.
4. **The conclusion nevertheless holds**, on the other clause alone: the CVE needs the filename
   string attacker-controlled with a stream wrapper, and `getLocalPath()` is a server-generated
   temporary path.

So: not exploitable, for **one** of the two reasons originally given. If the temporary-path
guarantee ever changes, the auto-detection branch is live and nothing else stands between a merchant
upload and the vulnerable code.

### 4.4 A `CLAUDE.md` claim corrected

It said there is *"no `api` or `sanctum` guard"*. **Measured:** `config/auth.php` declares three
guards; `config('auth.guards')` returns **four** at runtime, because Sanctum's provider injects
`sanctum` (`driver => sanctum`) at `register()`. The conclusions drawn from that entry all still
hold — `Auth::guard('api')` does raise *"Auth guard [api] is not defined"* — but the line now says
to check the runtime list, not the config file, before asserting a guard's absence.

---

## 5. Visual acceptance items

**The matrix is frozen as instructed: 295 surfaces — 225 REDESIGNED AND VERIFIED / 24 PREMIUM
VERIFIED / 28 OUT OF SCOPE / 8 DUPLICATE / 10 BLOCKED / 0 PENDING.** Nothing was promoted,
demoted or reclassified, and the 295-surface inventory was not reopened.

- **The 24 PREMIUM VERIFIED surfaces** awaiting owner visual approval:
  `docs/development/phase-2.5/PHASE-2.5-VISUAL-ACCEPTANCE-PACKAGE.md`. **No design has been
  accepted** — acceptance is the owner's explicit decision and this report does not assume it.
- **The 10 BLOCKED surfaces**, reported separately with a recommended disposition each:
  `docs/development/phase-2.5/PHASE-2.5-BLOCKED-DISPOSITIONS.md`. They are **four** root causes, not
  ten problems: 4 need a MySQL fixture, 2 need third-party sandbox credentials, 1 needs a device
  capture, 3 are product decisions. **If only one group is actioned, make it the MySQL one** — the
  repository has walked that path 42 times, and a MySQL service in CI would additionally convert 42
  operator-run security harnesses into continuously verified ones.

---

## 6. Owner decisions requiring explicit authorization

Ordered by urgency, not by number. **None has been taken.**

| # | Decision | Why it is blocked |
|---|---|---|
| 1 | **Stop the live host serving `.git`** — deploy the deny rules, or move/delete the server-side `.git`. The only step that closes anonymous retrieval of `APP_KEY` and the database credentials | no production access; deployment not authorised |
| 2 | **Rotate the database password and `APP_KEY`**, after #1 | secret rotation is an escalation item; breaks existing ciphertext and sessions |
| 3 | **Pull Apache access logs** for `/.git/` and the dump path **before rotation discards the window** | no production access — and it expires rather than waits |
| 4 | Repository visibility | owner decision |
| 5 | Force password resets (93 accounts) and revoke the 7 Sanctum tokens | touches live user data; logs users out, and the Flutter app forces logout on any 401 |
| 6 | Rewrite history — dump and historical `.env` in one `filter-repo` pass | explicitly forbidden without separate authorisation |
| 7 | Breach notification | legal determination; and §1.3's demo-data question decides its scope |
| 8 | Re-rate `#120` / `P1-OWN-09` from owner-deferred | the deferral rested on exploitation needing a clone, which it does not |
| 9 | Patch the vendored `AuctionProduct` cross-tenant bid write (§3.5) | editing `packages/workdo/` needs authorisation |
| 10 | The `StoreController` operator-spelling divergence (§3.6) | resolving it would broaden operator reach |
| 11 | Merge any dependency change (§2) | *"Do not merge potentially breaking dependency changes without explicit approval."* |
| 12 | A retention and correction policy, to replace the blanket prohibition in §3.7 with a correction mechanism | product and compliance decision |

Also outstanding, unchanged by this work: the Phase 01 release gates in
`docs/development/phase-1/PHASE-01-CLOSURE-EXECUTION.md`.

---

## 7. Commits and repository state

### 7.1 `fullstackfull/syriastore98`

**Development branch `claude/vibrant-bell-12tquy`** — the branch this directive's work was done on:

| commit | what |
|---|---|
| `32f562c4` | P1-SEC-40 containment: dump untracked, `.gitignore` census corrected to the measured 93 hashes, Apache + nginx deny rules, 30-case regression test, the schema-only cascade fixture that saved four tests, the incident record and the S53 queue entry |
| `69da2799` | the two cross-tenant product reads; the live bypass of the financial-record prohibition and the operator bulk-delete path; three defects the review found inside the fix; refusal-toast detection wired into CI; the refuted phpspreadsheet justification and the `CLAUDE.md` guard claim corrected; S54 and S55 queue entries; the BLOCKED dispositions |
| *(this commit)* | this report |

Prior commits in the same branch, from the acceptance work that preceded this directive:
`78e14d03`, `5284c57b`, `f9424503`, `5326caa4`, `e1c80472`, `b4a4a924`, `37237a92`, `a20b4d8c`,
`bb641f6e`.

**Dependency branch `claude/composer-security-same-major`**, branched from `69da2799`:

| commit | what |
|---|---|
| `a4c011d9` | `composer.lock` only — the three same-major bumps. 66 → 52 advisories. **Not merged.** |

It carries **no** application change and no `composer.json` change, so it can be accepted or
discarded on its own.

### 7.2 `fullstackfull/StoreSyria_app98`

| commit | what |
|---|---|
| `40a7f4b` | dump patterns in `.gitignore` as prevention — the repository is clean in tree and history — plus the recorded finding that its two committed `AIza` Firebase keys are client-side by design and are **not** part this incident, with what they *do* need (key restriction, not rotation) |

### 7.3 State

| | |
|---|---|
| working tree | clean on both branches after this commit |
| `main` | **untouched.** No merge, no push to it |
| history | **not rewritten.** No force-push anywhere. The dump blob `d3d42e68…` and the `.env` blob `aa5c75b8…` both remain reachable, which is also what preserves the forensic evidence — no separate copy of either was made |
| the dump on disk | present in this container only, untracked and ignored; it was not copied, printed, uploaded or sent anywhere |

---

## 8. What this report does not claim

- **Not release-ready.** Security blockers remain, and the most serious of them is live right now.
- **Not merged, not deployed, Phase 03 not started.**
- **No design accepted.** The 24 surfaces are *prepared for* approval.
- **No matrix movement.** 225/24/28/8/10/0, unchanged.
- **Exposure is confirmed; access is not.** There is no evidence anyone fetched either channel —
  and, for the served `.git`, **no means of obtaining that evidence from the GitHub side.** The
  host's access logs are the one place that could still answer it, which is why #3 above is
  time-critical.
