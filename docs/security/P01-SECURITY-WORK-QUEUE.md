# Store Syria — Phase 01 Security Hardening Work Queue

**Produced by:** P01-A′ (Sprint 01), branch `claude/store-syria-p01a-ci-foundation`.
**Baseline:** `main` @ `26b0014edaf02d0a21a512d9854c7f15b3d676fe`.
**Source of evidence:** the Phase 00B reconciliation, re-derived from code and adversarially
re-verified. Line numbers were checked at the baseline SHA above.

## What this document is, and is not

It is a **prioritised queue**, ordered by *exploitability × blast radius*. It is the planning
artefact P01-A′ was authorised to produce.

**No security behaviour was changed on this branch.** The sprint brief forbids combining broad
security behaviour changes with CI infrastructure changes in one package, and that separation is
deliberate: a CI change that is also an authorisation change cannot be reviewed as either. Every
row below is scoped work for a *later, separately approved* package.

**No exploit was run against any server.** Nothing here was verified by exploitation. Where a row
says "immediately exploitable", that is read from the code path, and the row names what would
settle it. No secret value appears in this document — only locations, names and categories.

## Severity vocabulary

| | |
|---|---|
| **S1 URGENT** | A credible path to data loss or disclosure that exists *now*. Needs an owner decision before any deployment, not a backlog slot. |
| **S2 HIGH** | Exploitable, or it makes another row exploitable. Schedule next. |
| **S3 MEDIUM** | Real defect, bounded blast radius or needs a precondition. |
| **S4 LOW** | Correctness/hygiene. Cheap, do it alongside something else. |

---

## S1 — `BackupRestore` module: unauthenticated route to the whole database and `.env`

**Owner action required before anything else: is `module_is_active('BackupRestore')` true on the
development server?** It cannot be answered from the repository — activation is a row in
`add_on_managers`, and `module.json` carries `"display": false`. If it *is* active, treat this as
an incident rather than a queue item.

The chain, each link read in code:

| Link | Evidence |
|---|---|
| The document root **is the repository root**, not `public/` | root `index.php`, root `.htaccess`, and the server path in the tracked `error_log` |
| `.htaccess` serves any path that exists as a real file, unrewritten | every rewrite rule carries `RewriteCond %{REQUEST_FILENAME} !-f` |
| Artefacts are written *inside* that root | `BackupRestoreController.php:78-81`, `:172-175` → `storage/uploads/{Files_Backup,Database_Backup}` |
| …and made world-readable | `chmod 0777` on both directory and artefact, `:124-125`, `:193-194` |
| The file archive is the **entire application root** | `ZipArchive` over `base_path()`, `:93-95`; the only exclusion is the `zip` extension, `:106` — so `.env` and `.git/` are inside it |
| A failed dump is logged as a success | return value of `shell_exec` discarded, no exit code, no `-s`, no readability check, `:188-192`; returns "Data backup successful.", `:162-165` |
| Shell injection | `$request->database_name` interpolated into the `shell_exec` string, `:154`, `:181-182`, validated `required` only, `:143-146` |
| DB password on the command line | visible to `ps` for the life of the dump, `:184-186` |
| There is no restore | `show()`, `edit()`, `update()` are empty stubs, `:222-246` |
| Route gating is weaker than the menu implies | route carries `auth` + `PlanModuleCheck:BackupRestore` only (`src/routes/web.php:17-19`); the controller checks `isAbleTo('backup&restore create')`. The Super-Admin-only menu entry is not an authorisation boundary |

Note the contrast *inside this repository*: `deployment/deploy.sh:155-200` does the same job
correctly — refuses an empty dump, verifies readability, keeps the password out of the command
line. `deploy.sh:24-25` even states the principle: *"A dump nobody has ever read is not a backup."*

**Fix shape (not for this sprint):** deactivate the module; deny `storage/` and `*_Backup` paths
in `.htaccess`; move artefacts outside the document root; drop the `0777`; parameterise the dump
and keep the password in the child environment; verify readability before logging success. Because
`packages/workdo/` is protected by `.github/scripts/guard-no-workdo-changes.sh`, the containment
half belongs in the host (`.htaccess`, a route-level guard) or is an owner decision to fork.
**Backup *capability* work is P01-H1 and is explicitly out of scope here** — this row is about
closing an exposure, not building backups.

---

## S2 — `APP_KEY` is published and unrotated

`.env` is genuinely untracked at this HEAD (the diff entry against `0d2ca628` is a **deletion**:
`git diff --name-status 0d2ca628..HEAD -- .env` prints `D`). Nothing was rotated, and the blob
remains reachable in history at `3c39c160`.

Why it is S2 rather than a hygiene item: **it makes S3 exploitable.** Laravel derives `Crypt`
from `APP_KEY`, so with the key public, `Crypt::decrypt()` is an *encoding*, not an access
control, and an encrypted identifier can be minted by anyone holding a clone. The repository
already relies on this reasoning in the opposite direction — `ImpersonationTest::test_forged_encrypted_id_is_denied`
forges the id rather than assuming the attacker was handed a link.

**Standing rule for all Phase 01 work, worth stating once:** an encrypted identifier is not an
authorisation check in this codebase.

Also unrotated and in tracked `config/` (reported by location and category only, never value):
`config/services.php:31` (`client_secret`), `:33-34` (`hook_user`/`hook_pass` — the Zid webhook
shared secret, guarding a destructive endpoint), `config/myfatoorah.php:10` (`api_key`),
`config/jwt.php:18` (the one secret-shaped `env(KEY, <literal>)` fallback — the dangerous
silently-works pattern).

**Adversarially verified and worth correcting:** *none* of those four would be caught by
`.github/scripts/scan-secrets.sh` if newly added. Its `generic-assigned-secret` rule requires the
key name adjacent to the assignment operator, and PHP array syntax interposes a quote. An earlier
reading claimed three of four would be blocked; that is false.

**Owner decisions:** whether the recorded deferral (which names `.env`) covers `config/*.php` too;
rotation order per `docs/security/SECRET-ROTATION-CHECKLIST.md` (**written, never executed**); and
the ordering hazard on the Zid secret — the webhook guard fails *closed*, so moving those keys to
the environment without provisioning them first stops the integration rather than breaking it
open. Provision, then move. Rotating `APP_KEY` additionally needs a re-encryption pass for any
`encrypted`-cast column, inventoried against the live schema first.

---

## S3 — Unauthenticated access to tenant-owned order documents — **PARTIALLY CLOSED by P01-SEC-2**

> **Partially closed — seventeen uris guarded, three order-document routes knowingly left open.**
> The three are S12 below, every one of them reproduced. The word is "partially" because an
> adversarial audit of the fix, run after it was committed, found four further order-document
> routes the guard had missed (now closed: `{storeSlug?}/customerorder/{id}`,
> `{storeSlug?}/order-refund/{id}`, `refund-request/{refund_request}`, `order/{order}`) and three
> it cannot close within this package's authority. **Do not read this row as "order documents are
> safe".**
>
> **Closed part.** `App\Http\Middleware\OrderDocumentAccessGuard`, appended to the `web` group in
> `bootstrap/app.php` — the fix shape this row predicted. The finding below is kept verbatim
> rather than deleted, because the vendored defect it describes is still in the module and the
> guard is what stops it being reachable.
>
> Two things turned out to be **worse** than this row recorded, both measured:
>
> 1. `:31` of the same group re-declares `shippinglabel/pdf/{id}` with the SAME controller action
>    as the authenticated host declaration at `routes/web.php:1178`. `RouteCollection` keys on
>    method+domain+uri, so the module's declaration *replaces* the host's and `Authenticate`
>    silently leaves the stack while the action name stays identical. The host route was not a
>    separate safe path; it was the same path, un-gated.
> 2. Four **host** order-document routes (`order-view/{id}`, `orders/order_view/{id}`,
>    `order-receipt/{id}`, and `shippinglabel/pdf/{id}` itself) are authenticated but carry no
>    tenant predicate, and three of them check no permission either. That half is live with no
>    module enabled at all, so the severity of this row never depended on the module.
>
> Nine further uris in `AuctionProduct` and `LotteryPrizes` have the identical shape and are
> guarded too; seventeen in total. See **S11** for the pattern behind them.
>
> **A third thing the first pass got wrong, recorded because it is the useful lesson:** the
> regression ratchet shipped with that fix matched route uris against a word list
> (`shippinglabel|printlabel|order-view|order-receipt|invoice|packing`). Three of the four misses
> above are order documents whose uri contains none of those words, so the ratchet reported a
> clean sweep over a surface it could not see. It is now backed by a census keyed on the
> **handler** instead — `OrderDocumentRouteResolutionTest::test_every_route_reaching_an_order_document_handler_is_guarded`
> — which fails on a new route to any known order-document method whatever its uri says. A census
> keyed on how a uri is spelled cannot find the document route nobody thought to spell.

`packages/workdo/AutomaticOrderPrinting/src/routes/web.php:24` opens a route group whose
middleware is `['web']` **only** — no `auth`. Inside it:

- `:25` `GET /shippinglabel/{id?}/{template?}/{color?}` → `AutomaticOrderPrintingController::shippinglabel()` `:228`
- `:27` `GET /printlabel/{id?}/{template?}/{color?}` → `::printinglabel()` `:312`

Both handlers do `Crypt::decrypt($id)` and then `Order::order_detail($id)` (`:232`, `:318`). Read
across `:228-330`: no `auth()` call, no `getCurrentStore()`, no `store_id` predicate, and the only
`abort` is a template-not-found at `:292`. These are distinct URI paths from the `routes/web.php`
order routes that GATE C closed, and they were missed by that sweep.

With S2 in force the identifier is mintable, so this is **any store's order documents, readable
anonymously**. Shipping labels carry customer name, address and phone.

**Fix shape:** host-side, because the module tree is guard-protected — a middleware appended to
the `web` group in `bootstrap/app.php`, following the existing `BulkDeleteTenantGuard` precedent,
plus an ownership predicate. The `C23` precedent is the governing pattern: fix in the host, leave
the vendored defect pinned by a test so an upstream change reddens it.

**Regression test to add with the fix** (deliberately not added on this branch, since asserting
the current behaviour would assert the vulnerability): an anonymous `GET` of both routes with a
validly-encrypted foreign-store order id must answer 403/404 and must not render order detail.

---

## S4 — The API log is a plaintext credential store

`app/Http/Middleware/APILog.php:26-34` and `app/Http/Middleware/AdminApiLog.php:24-35` pass the
whole `$request` and `$response` objects to `Log::info` on every API call. That includes request
headers, and therefore `Authorization: Bearer {id}|{secret}`, and response bodies. Destination is
`storage/logs/api.log`, retained **15 days** (`config/logging.php:134-139`).

Two facts make it worse than ordinary over-logging:

1. **Tokens never expire.** There is no `config/sanctum.php`, so Sanctum's default
   `expiration => null` applies. A token read out of a log is valid indefinitely.
2. **Every token carries `['*']`.** All four mint sites call `createToken('auth_token')` with no
   abilities array. `Laravel\Sanctum\Http\Middleware\CheckAbilities` is imported at
   `routes/api.php:9` and never used.

So one log read is a permanent, unscoped credential for that principal. Note also that these two
middlewares are **logging only** — `AdminApiLog.php:19-22` is `return $next($request);` with all
work in `terminate()`. The contract checker already says this plainly:
*"a route that only logs is not a route that authenticates."*

> **BOTH LOGGERS NOW REDACT — the second half closed by the password-recovery VERIFICATION.**
>
> The password-recovery emergency fixed `APILog` only. This row named both, and `AdminApiLog` was
> still passing the whole `$request` and `$response` to the log — on **128 route declarations**,
> among them `adminlogin` (`routes/api.php:134`), `updatepassword` (`:304`) and
> `deliveryboysReset-password/{id}` (`:363`). So **administrator passwords, delivery-driver
> passwords and admin bearer tokens continued to be written to disk in plaintext** with the same
> 15-day retention, for the whole period the storefront side was considered fixed.
>
> The redaction now lives in one place, `App\Http\Middleware\Concerns\RedactsApiLogFields`, used
> by both, and a test asserts both use it and that neither logs the raw objects — because fixing
> one and not its twin is exactly how this survived. Verified by removal: reverting the admin
> scrubber fails the test.
>
> **Still open on this row, and both need the owner:**
>
> 1. **Existing log files are compromised material.** Redaction applies to future requests only.
>    `storage/logs/api-*.log` on the running server may hold up to 15 days of plaintext customer,
>    administrator and driver passwords, OTPs and bearer tokens. Only the owner can read or rotate
>    them, and whether any past use was malicious **cannot be determined from this repository**.
> 2. **Tokens never expire and carry `['*']`** — the two facts below that make a log read a
>    permanent unscoped credential. Untouched by this package.

**Fix shape (for what remains):** tokens — see
[`P01-TOKEN-EXPIRY-PROPOSAL.md`](P01-TOKEN-EXPIRY-PROPOSAL.md), which inventories **five** mint
sites (not four), establishes that **no refresh path exists for any client** and that
**`Api\AuthController::login` is unrouted — so customers have no login endpoint at all**, and
stages the policy so no step can silently log out a shipped client. Logs — decide whether 15-day retention of API bodies is wanted
at all, and rotate what is on the server now.

Adjacent, same package: `config/larabug.php:99-111` filters `*name*`, `*email*`, `*credit_card*`,
`*cvv*`, `*iban*` — and nothing else. Across ~50 gateway integrations that blacklist is not
sufficient, and should be reviewed *before* anyone enables `larabug` (today it is almost certainly
inert: `LB_KEY`/`LB_PROJECT_KEY` default to `''` and appear in no `.env.example`, and
`environments` is restricted to `production`).

---

## S5 — Token and session handling

| Item | Evidence | Note |
|---|---|---|
| Tokens never expire | no `config/sanctum.php` | see S4 |
| All tokens carry `['*']` | four `createToken('auth_token')` sites, no abilities | see S4 |
| Customer logout does not invalidate the session or regenerate the CSRF token | `CustomerLoginController.php:631-640` — `Auth::guard('customers')->logout()` only | merchant logout *does* |
| Customer login does not regenerate the session id | `CustomerLoginController.php:590` | merchant login *does*, `AuthenticatedSessionController.php:77` |
| One session record can hold a `web` **and** a `customers` principal simultaneously | `routes/customer.php` is required from `routes/web.php:1313`; cookie path `/` | by design, but it is the mechanism behind the tenant-context mismatch in S6 |
| `same_site` is `null`, not `lax` | `config/session.php:202` | browser default applies |
| `/login` is CSRF-exempt | `bootstrap/app.php:71` | matched on **path**, so a prefixed or localised login path is *not* exempt |
| `duologin` is CSRF-exempt and calls `Auth::loginUsingId()` | `bootstrap/app.php:72`; `Duo2FAController.php:233-235` | the whole Duo route group is `['web']` with no `auth` |
| MFA is not a second factor | `AuthenticatedSessionController.php:120-125` dispatches *after* `authenticate()` and `session()->regenerate()` | the session is fully authenticated when the prompt renders; `elseif` means Google 2FA masks Duo; the `2fa` middleware is on two routes and not on the admin group |

**Sequencing constraint:** token expiry and abilities are a behaviour change for binaries already
on merchants' phones. They need a compatibility window and an owner decision, per the frozen
Flutter contract. Session handling (logout symmetry, id regeneration, `same_site`) is independent
of that and can go first.

---

## S6 — Tenant context failures

This is **P01-F's** territory and is listed here for completeness of the security picture, not as
separate work. The security-relevant core:

- `config/tenancy.php:391` — `null_tenant` defaults to **`open`**, so the global scope contributes
  **no predicate at all** on every storefront request, every customer-API request, every queued
  job and every console command.
- `Order` carries neither the trait nor an allowlist entry — **there is no global tenant scope on
  orders**. `Product` carries the trait but is not allowlisted, so its scope is registered and
  inert. Ten of 93 models are enforced.
- `orders.store_id` is nullable with no foreign key, and two writers insert NULL-store rows:
  `MercadoPaymentController.php:86`, `SkrillPaymentController.php:106`. Such rows are invisible to
  all ~1,324 `where('store_id', …)` predicates while remaining readable through bare
  `Order::find()` endpoints.
- The sanctioned cross-tenant hatches `forStoreContext()` and `allowCrossTenant()` have **zero
  production call sites**, so the work that must precede tightening `null_tenant` has not started.
- Raw `DB::table()` bypasses the scope entirely — and a test asserts that this is so, which is the
  right way to hold a known hole.

**Order of operations matters here:** fix the two NULL-store writers, count and reconcile existing
rows, *then* consider `NOT NULL` + FK. Tightening `null_tenant` to `closed` before writing the
explicit hatches would empty the storefront and stop every job.

---

## S7 — Dynamic module loading failures

`app/Providers/PackageServiceProvider.php:54-56` swallows **every** `\Exception` from the whole
dynamic-load loop **with no log line**. Module classes are autoloadable *only* because that loop
calls `addPsr4()` — verified as the sole mechanism (one `addPsr4` site; no `workdo/*` in
`composer.json`; `grep -c workdo composer.lock` → 0; the `repositories` path block is decorative).

Host code in cart, checkout and payment paths gates on `module_is_active()` rather than
`class_exists()`, so a module that failed to register silently can surface as a **fatal `Error` in
checkout** rather than as a missing feature. A broken or un-registerable module removes its whole
feature — routes, views, migrations, menu, listeners — with nothing written anywhere.

Related, same package:

- Entitlement is decided from **three** sources that can disagree: `add_on_managers.is_enable`,
  `plans.modules` (CSV string), `user_active_modules`. `module_is_active()` reads a *different*
  one depending on whether the route carries a slug (`app/Helper/helper.php:1277-1343`), so
  storefront and dashboard feature flags are computed differently.
- Nine modules' admin surfaces are not entitlement-gated at route level, and at least one
  settings endpoint is reachable without authentication.
- `app/Observers/OrderObserver.php:13-21` catches `\Exception`, from which `QueryException`
  descends, and the observer *is* registered (`AppServiceProvider.php:30`). **Today that means
  orders save while all financial tracking silently does nothing.** It is listed here because it is
  the same silent-failure class — but see the warning below, because its severity *changes*.

**Owner decision:** whether `:54-56` may be changed at all, and to what. The minimum that
preserves current behaviour is to log and continue; the honest fix is to catch per module inside
the loop so one bad module cannot drop the rest. Both are host-side and both make a broken install
**start reporting**, which is a visible change in what a deployment does.

---

## S8 — The one row whose severity is scheduled to change

`OrderObserver`'s swallowed `QueryException` is, today, a silent no-op: the six GATE F money tables
do not exist, so financial tracking writes nothing and nobody notices.

**The moment GATE F opens, this becomes a money defect.** Creating those tables does not fix a
bug — it makes a silently dead subsystem start working, and with the hardcoded `payment_status =
'Paid'` writes still in place, COD and bank-transfer orders would credit merchant wallets **against
cash never collected**. `database/migrations/_gate_f_pending/README.md:76-88` states this as a hard
sequencing rule and calls it *"the single most important thing to say about SCHEMA-01."*

**Therefore:** `payment_status` derivation must be resolved **before, or in the same release as,**
the GATE F schema. This row is not actionable now; it is a precondition on P01-B that must not be
discovered late.

---

## S9 — Secret scanning cannot see what matters

- `gitleaks` is `continue-on-error` — **reported, not enforced** (standing owner decision; not
  changed in this sprint).
- `.github/scripts/scan-secrets.sh` is **diff-only** by design and says so honestly: it cannot
  find a secret already in history.
- So the four tracked `config/` literals in S2 are invisible to both, and — per the adversarial
  correction in S2 — would remain invisible if newly added.
- `.github/scripts/guard-no-env-files.sh` now reports `OK: no live .env in the checkout or the
  index`. That is **true and misleading**: untracking `.env` silently retired the warning that had
  been keeping the unrotated exposure visible. The guard is working as written; the record it
  produces no longer reflects the risk.

**Fix shape:** extend the scanner's key list to the shapes PHP array syntax actually produces, and
add an explicit, named assertion for the four known `config/` literals so their status is a *test*
rather than a paragraph in a register.

---

## S10 — Hygiene, cheap, do alongside anything

Three artefacts the repository has already declared should not be present, and which are still
tracked:

| Path | Why it matters |
|---|---|
| `error_log` | 975 B of cPanel/Apache PHP fatals from the **live host**, including its absolute filesystem path. `.gitignore:40` lists it; a gitignore entry does not untrack an already-tracked file. |
| `_active', 1)` | 52 KB shell-escaping artefact (a `less` help screen). Not in `.gitignore` at all. |
| `composer.phar` | 3.6 MB; `.gitignore:41` lists it; still tracked. |

`git rm --cached` on all three, plus a `.gitignore` entry for the second. Deliberately **not** done
on this branch: `error_log` and `composer.phar` are matched by `.gitignore` already, so removing
them from the index is a one-line change that belongs with other hygiene work rather than inside a
CI package.

---

## S11 — `['web']`-only route groups are a pattern across the module tree, not one module's slip

**S2 HIGH**, on the measurement; the *per-route* severity varies and has not been triaged.

Found while closing S3, by scanning the route files rather than by reading one module:

| | |
|---|---|
| module `web.php` files scanned | 70 |
| modules declaring at least one `Route::middleware(['web'])->group(...)` | **42** |
| routes declared inside those groups | **150** |

Reproduce with the scanner in the P01-SEC-2 report, or by hand: `rg "Route::middleware\(\s*\[\s*'web'\s*\]\s*\)->group"
packages/workdo/*/src/routes/web.php`.

**The number is an upper bound on the exposure, not the exposure.** Many of those 150 are
legitimately public storefront routes — a `{storeSlug}` product page belongs in a group with no
`auth`. The scanner is line-based and does not distinguish them. What it establishes is that the
AutomaticOrderPrinting group was **not** an isolated mistake, so no fix aimed at one module closes
the class.

Routes inside such groups that were **read** and are clearly administrative:

- `AuctionProduct/src/routes/web.php:29,34` — `auction-order-view/{id}`, `auction-order-receipt/{id}`
- `LotteryPrizes/src/routes/web.php:54,58` — `lottery-order-view/{id}`, `lottery-order-receipt/{id}`
  (all four now guarded by P01-SEC-2)
- `PartialPayments/src/routes/web.php:21,23,29,31` — `PUT payment-email/update/email_template/{id}`,
  `POST partial-payment-settings`, the `partialpayments` resource, and
  `POST payment-status-change/{id}`. **These are WRITES, not document reads**, so P01-SEC-2's
  read-authorization guard does not address them and deliberately does not claim to. An anonymous
  `POST payment-status-change/{id}` changing a payment's state is a different and probably worse
  defect than the one S3 described.

**Fix shape:** not thirteen more entries in a document guard. The tractable version is a route
*census* gate — enumerate the resolved route table with every module enabled, classify each route
as public-storefront or administrative, and fail CI on a new administrative route that carries no
`auth`. That needs the module-loading problem solved first (no module loads in any test run
today, which is why none of this was visible), so it is its own package.

**Precondition for triage:** which modules are actually enabled on the dev server. The same
question S1 asks. `SELECT module, is_enable FROM add_on_managers` answers both.

---

## S12 — Order documents reachable outside the `web` group, or by design, that P01-SEC-2 did not close

**S2 HIGH.** Every row here was **reproduced by execution** during an adversarial audit of
P01-SEC-2's own guard, run after that guard was committed. None is hypothetical. Each is left open
for a stated reason, not an oversight.

| # | Route | Who gets in | Why P01-SEC-2 did not close it |
|---|---|---|---|
| a | `POST api/{slug}/order-detail` → `ApiController@order_detail` (`routes/api.php:108`) | **any customer token, any tenant** | `api` group — the guard is on `web`, and the brief forbids changing the Flutter API contract |
| b | `GET api/admin/order-detail` → `DashboardController@orderDetail` (`routes/api.php:337`) | **any token at all**, including a delivery driver's | same |
| c | `GET {storeSlug?}/customerpayment/{id}` → `PartialPaymentsController@customerpayment` (`PartialPayments/src/routes/web.php:27`) | **anonymous** | anonymous payment-by-link is the designed flow; gating it needs the owner's decision (same decision as the storefront print button) |

**(a)** `getStore($slug)` is resolved and then never used; the handler calls
`Order::order_detail($request->order_id)` with no `customer_id` and no `store_id` predicate.
Reproduced: a customer token for store B read store A's full order document, HTTP 200.

**(b)** Validates only that `order_id` is present, then `Order::order_detail()`. Its sibling
`ViewOrder()` at `:1861` does resolve and check the store, so the omission is local, not a design.
Reproduced: a merchant token for a different tenant read the victim order, HTTP 200.

**(c)** Declared in a `['web']`-only group, so the resolved stack has neither `Authenticate` nor
`EnsureCustomerAuthenticated`. `Crypt::decrypt` then `Order::order_detail`; the customer principal
is used only as `auth('customers')->user()->id ?? 0` passed to a payment-list API, never compared
to the order. Reproduced anonymously, HTTP 200, with the module enabled through the production
loader.

**Fix shape for (a) and (b):** the same ownership predicate, written at the two call sites or in an
api-group middleware. It is a *separate package* because it changes API response behaviour and the
Flutter app treats some statuses specially — `lib/network/network.dart` forces a logout on any 401,
so the denial must be 403 there too.

**Fix shape for (c):** whatever the owner decides for customer-facing documents generally. The
honest options are a scoped expiring document token or accepting link-bearer access; this
repository has no signed-URL machinery for documents today (`routes/auth.php:45` is the only
`signed` route, for email verification).

---

## S13 — `UserController::store()` lets a merchant mint a `super admin`, defeating every tenant predicate

**S1 URGENT.** This outranks everything else on this list, and it is not an order-document defect —
it is a privilege-escalation primitive that makes the operator branch of *every* authorization
check in the codebase assignable by a merchant.

`app/Http/Controllers/UserController.php:109-114`:

```php
$role_r = Role::find($request->role);      // UNSCOPED
$user->type = $role_r->name;               // no RESERVED_NAMES check
```

The sibling `update()` at `:243-262` was already hardened and carries both controls — a scoped
`Role::where('id', ...)->where('created_by', ...)->where('store_id', getCurrentStore())` lookup and
a `RolePolicy::RESERVED_NAMES` refusal, with a comment explaining why. **`store()` was missed.**
The seeded global `super admin` role (`database/seeders/PermissionTableSeeder.php:55-65`) has
`store_id` NULL, so it is findable by id from any tenant.

`Create User` is granted to the same `admin` merchant role as `Show Order`
(`app/Models/Utility.php:2905`, `:2919`), so **every self-registered merchant holds the permission
needed**.

Reproduced, both steps green: a merchant holding `Create User` posted `role=<super admin role id>`
to `/users` → HTTP 302, and the created row had `type === 'super admin'`; that row then read a
foreign tenant's order document at `GET /order-receipt/{id}` → **HTTP 200**.

**Why P01-SEC-2 did not fix it:** it is user management, not order documents, and the fix belongs
with the other writers of `users.type`, `role_user` and `current_store` — `UserController@store`,
`UserController@editprofile`, `RoleController@store`, and the API staff-create endpoints. One
unscoped role id defeats every `store_id` predicate in the application, so it wants its own
package and its own regression tests, not a line bolted onto a document guard.

**This should be answered before any deployment.** It is the kind of row the P01-A′ brief meant by
"a credible immediately exploitable issue … requiring owner approval before any live deployment".

---

## S14 — Order-adjacent surfaces found alongside S12/S13, not yet triaged

Recorded so they are not rediscovered from scratch. Each was reported by the same audit; severity
is **not** assigned because none has been through the same verification as S12 and S13.

- `GET /order/export` (`OrderController:1230`) — dumps every order in the store to any
  authenticated caller, with no order permission.
- `GET {storeSlug?}/order-complete?data[order_id]=N` (`OrderController:563`) — discloses another
  tenant's order number to an anonymous caller and mints its storefront document link.
- `POST {storeSlug?}/downloadable_prodcut` (`AccountProfileController:845`) — mails digital
  products for an unscoped raw `order_id`. Reported but **not** reproduced.
- `DeliverySyriaOrderController:122` — answers differently for an order that exists before its
  credential check, i.e. a platform-wide order-existence oracle.
- Every order document renders store settings fetched without reference to the order's store
  (`app/Models/Utility.php:167`), so a document can print the wrong store's address. A correctness
  defect, not an access one.
- `OrderDocumentAccessGuard` denies a shopper their own document when the same browser also holds
  a live `web` session, because the merchant limb is evaluated first and returns on its own result.
  A false denial, not a leak; it needs a decision about which principal should win.

---

## S15 — Privilege-write escalations outside the web account-management surface

**S1 URGENT as a group, and one row is an emergency.** Every row here was found by a sweep of
every writer of `users.type`, `role_user`, `users.current_store` and account credentials, and
every one marked reproduced was **executed** against the real route and middleware stack. None is
hypothetical.

They are **not fixed**, and the reason is scope rather than doubt: twelve are `routes/api.php`
handlers whose behaviour the shipped Flutter app depends on, and the brief reserves API behaviour
changes for an owner-approved window. The web-guard half of the same class (S13-b/c/d) is closed.

### S15-A — **CLOSED in code, then RE-OPENED by an independent review, then closed again; server exposure still UNVERIFIED**

> **The first fix stopped both reported defects and left eleven findings — ten demonstrated
> defects and one hardening gap.** An adversarial review of it (the package after) wrote 49 tests
> designed to disprove it; **thirteen failed**. That is the headline of this row, because the
> emergency fix was reported as complete at the time:
>
> | # | What survived the emergency fix | Severity |
> |---|---|---|
> | 1 | `change-password` — a **delivery driver's** token set **any customer's** password in **any store** | **critical** |
> | 2 | `change-password` — a merchant's or operator's token set any customer's password in their tenant | **critical** |
> | 3 | The compatibility Bearer was **any customer login token**: an ordinary session reset the password with no OTP and no current password | **high** |
> | 4 | One completed OTP step authorised **unlimited** resets (the Bearer was not single-use, and Sanctum has no expiry configured) | **high** |
> | 5 | A successful reset left **sibling reset tokens** alive | medium |
> | 6 | A successful reset left **every pre-existing session** alive, so the attacker kept their token | medium |
> | 7 | A **wrong guess extended the OTP's lifetime** — the code re-put a fresh TTL while its own comment claimed the opposite | medium |
> | 8 | The attempt limit bound **one code, not the account**: re-sending reset the counter for free | medium |
> | 9 | The attempt counter's read-modify-write was **not atomic** | medium |
> | 10 | The OTP came from `rand()` — **Mersenne Twister, predictable**, not a CSPRNG | medium |
> | 11 | `deliveryboysReset-password`: **any** valid token set **any** driver's password in **any** store | **critical** |
>
> Row 11 is `POST api/admin/deliveryboysReset-password/{id}?store_id=N` (`routes/api.php:363`),
> read while checking that route's logging. `store_id` arrives in the **query string**, so the
> `where('store_id', ...)` predicate compared the attacker's two supplied values against each
> other rather than checking a tenant, and there was no authorisation beyond `custom.auth`. A
> shopper's token, another store's driver's token, or any merchant's token set any driver's
> password anywhere. Fixed rather than deferred to P01-SEC-11 because the shipped admin app sends
> its own `store_id` with the merchant's Bearer, so the ownership check refuses nothing that client
> sends — pinned by a test for that journey.
>
> Rows 1, 2 and 11 are not in the recovery handlers at all. Rows 1 and 2 are
> `POST api/{slug}/change-password`
> (`Api\ApiController::change_password`), found because the owner's brief asked whether an
> administrator or driver token can authorise a customer password change — a question about the
> **surface**, not about one handler. `custom.auth` logs a token's owner into whichever guard
> matches its class, so a `User` or `DeliveryBoy` token passed the middleware and left
> `auth('customers')` null, taking a branch that read `customer_id` **straight off the request**
> and called `Customer::find()` with no scope and no ownership check. Row 1 crosses the tenant
> boundary because nothing resolves a tenant for the `deliveryboy` guard, so
> `config/tenancy.php`'s `null_tenant => 'open'` left the model unscoped for them.
>
> The merchant-to-another-store case happened to be refused — but only because a merchant token
> *does* resolve a tenant and the global scope then narrowed the lookup. **A control that works by
> accident for one principal and not another is not a control**, so the replacement checks the
> principal and the store explicitly and does not rely on the scope.
>
> **All eleven are now closed**, each pinned by a test that fails when that single fix is reverted
> (twelve removal checks run individually; three of the removal checks were themselves wrong on the
> first attempt and were corrected — one reverted something the deadline check was already
> covering, one used an anchor that matched twice and silently applied nothing).
>
> The mechanisms: a **generation record** per recovery round, so the newest round is the only live
> one and spending any authorisation retires its siblings; **registration** of the compatibility
> Bearer, so the fallback accepts the session recovery itself minted and nothing else; the OTP's
> **deadline stored in the record** and honoured on re-put; a **per-account send cap**
> (5 per 60 minutes); the whole verify step under a **cache lock**, failing closed on contention
> (ArrayStore, FileStore and DatabaseStore all support locks — measured); `random_int()` for the
> code; and **revocation of every other session** on a successful reset, keeping only the one the
> recovery round itself minted so the client is not logged out mid-flow.
>
> **49** adversarial tests (`tests/Feature/Security/CustomerPasswordResetAdversarialTest`), the
> original **19** still passing unchanged, and **10** for the column migration
> (`CustomerPasswordOtpColumnMigrationTest`). Full suite re-run.
>
> **Three things are NOT closed by that commit, and two need the owner:**
>
> 1. **Server exposure is unverified.** This container's network policy denies the host
>    (403 CONNECT at the proxy), so whether the route was reachable could not be established. A
>    Git commit does not protect the running server: block `POST /api/*/forgot-password-save` at
>    the web server or WAF until the fix is deployed.
> 2. **Rotate credentials.** The platform operator's password, and any administrator password,
>    was settable by anyone who knew the email address.
> 3. **`storage/logs/api-*.log` is compromised material.** `APILog` logged whole requests and
>    responses on these routes, so up to 15 days of plaintext passwords, OTPs and bearer tokens
>    may be on the server. Redaction is now in place for future requests; the existing files are
>    not retroactively cleaned, and only the owner can read or rotate them. Whether any past reset
>    was malicious **cannot be determined from this repository**.
>
> A fourth finding surfaced while fixing it and is tracked as its own package: the WEB reset flow
> writes a bcrypt hash into an `integer()` column, so it cannot verify on MySQL. See
> `docs/development/phase-1/10-NEXT-WORK-PACKAGES.md` (P01-SEC-13). That is very likely why the
> insecure API path existed.

### The finding as reported

`POST api/{slug}/forgot-password-save` → `Api\AuthController::forgot_password_save():376`.
Middleware is `['APILog', 'throttle:auth']` — **no `custom.auth`**. The body is
`email` + `password`; the handler does `User::where('email', $email)->first()` — unscoped — then
`$has_email->password = bcrypt($request->password); $has_email->save();`.

**It never reads the OTP.** And the flow is incoherent as well as open: the two preceding steps,
`forgot_password_send_otp():` and `forgot_password_verify_otp():`, both operate on **`Customer`**
and store `password_otp` / `password_otp_datetime` on the customer row, while this step writes to
**`User`** — a different table. So the endpoint cannot serve its stated purpose (a customer who
completes the OTP correctly never gets their own password changed) *and* it hands any anonymous
caller who knows an email address that account's password, **including the platform super admin**.

A single unauthenticated HTTP request is a full platform takeover. This should be answered before
anything else on this list, and before any deployment.

**Fix shape:** operate on `Customer` like the other two steps, scope to the slug's store, require a
verified unexpired `password_otp`, and clear it after one use. The OTP protocol already exists, so
this is using it rather than inventing one — but it changes the endpoint's behaviour, so it needs
the owner's window. An interim mitigation that needs no app release is to disable the route.

### S15-B — the rest, grouped by what they write

| # | Route / handler | Who gets in | What they write |
|---|---|---|---|
| 1 | `Api\AuthController::forgot_password_save():397` | anonymous, any slug incl. non-existent | any `users.password` |
| 2 | `POST api/{slug}/register` → `AuthController:71` | anonymous | hijacks an existing customer by email and moves them into the attacker's tenant |
| 3 | `ApiController::change_password` / `profile_update():5772,5786` | any non-`customers` token — every delivery driver | password and email of **any customer in any store** |
| 4 | `ApiController::user_delete():6682` | any `custom.auth` token, incl. a self-registered customer | deletes an arbitrary `User` row — operator included — plus that id's orders, addresses, wishlist, reviews |
| 5 | `DeliveryBoyApiController` all five methods `:48` | any token; for reset/update/destroy an anonymous attacker can mint one via `POST api/{slug}/register` | creates and seizes delivery-boy principals in **any** tenant; `store_id` comes from the caller |
| 6 | `Api\DashboardController:4007` | any non-admin user the operator created | name and email of **every merchant on the platform**, then takeover by password reset |
| 7 | `AccountProfileController:74` (`POST /{storeSlug}/my-account`) | any signed-in shopper | another shopper's email and name, across tenants |
| 8 | `config/superadminaccess.php:3` | any merchant with `Create Role` — i.e. every merchant | grants a role the **six money-moving finance permissions** the merchant does not itself hold; the denylist has 13 names and omits them |

Two further rows are **not reproduced** and are recorded as unverified:
`GoogleAuthenticationController:34` copies the merchant's own `google2fa_secret` onto every staff
row in the tenant; `AuthController:334` writes a **hardcoded password** (`'hello@1234'`, a literal
in the repository) on the customer OTP reset path, whose OTP send is also not store-scoped.

### Why these are one package and not eight

Seven of the eight write a credential or an identity on a row the caller supplied, with no
ownership predicate — the same defect shape as S13, in a surface where the fix must also preserve a
compiled client's contract. They want one API-authorization pass with one shared ownership helper
(`Api\DashboardController::resolveChangeableOrder()` and the `resolveViewableOrder()` added for
S12 are the precedent), one Flutter window, and one regression suite — not eight separate patches
racing each other.

**Precondition:** the same `SELECT module, is_enable FROM add_on_managers` that S1 and S11 need,
plus confirmation of which API endpoints the shipped app actually calls. `tests/Support/ApiContract`
already holds the pinned endpoint manifest and is the right place to read that from.

---

## S16 — The password-change surface: one pattern, five handlers, all now closed

> Found by an independent adversarial audit of the password-recovery packages, which asked the
> owner's question — *can merchant, super-admin, customer or delivery-driver credentials cross an
> account or tenant boundary?* — about the SURFACE rather than about the recovery handlers. The
> recovery handlers held up. The surface around them did not.

### THE PATTERN

`App\Http\Middleware\CustomAuth` logs a token's owner into whichever guard matches the tokenable
**class**: Customer → `customers`, User → `web`, DeliveryBoy → `deliveryboy`. A handler written as

```php
if (! auth('customers')->user()) { $id = $request->some_id; }   // request input
else                             { $id = auth('customers')->user()->id; }
$target = Customer::find($id);                                   // no store predicate
```

is reachable by a **merchant's or a driver's** token with `auth('customers')` null, and takes the
request-supplied branch. **Five handlers had exactly this shape.** All five are closed:

| | handler | wrote | closed in |
|---|---|---|---|
| a | `Api\ApiController::change_password` | password | previous package |
| b | `Api\DeliveryBoyApiController::resetPassword` | driver password | previous package |
| c | `AccountProfileController::store` | password **and e-mail** | **this package** |
| d | `Api\AuthController::logout` | revoked any customer's sessions | **this package** |
| e | `Api\ApiController::profile_update` | **e-mail** (a takeover primitive) | **this package** |

(c) was the worst: the old-password check was guarded by `if ($request->old_password ...)`, so
**omitting the field skipped verification entirely**, and it rewrote the address every recovery flow
trusts. Measured against the original handler: a shopper took over any other customer **in their own
store**. Cross-tenant did NOT reproduce — `Customer` is on `scoped_models`, so the global scope
narrowed the lookup once a tenant resolved. The handler performed no check at all; the isolation was
accidental, which is why the replacement does not rely on it.

### THE SECOND ROOT CAUSE: `store_id` from the caller

`DeliveryBoyApiController` took `store_id` from the **query string** on every credential writer, so
its `where('store_id', ...)` compared the caller's own two values and authorised nothing. A shared
`refuseUnlessOperatorOwns()` now gates `store()`, `update()` and `resetPassword()`, admitting the
platform operator explicitly (`creatorId()` returns a `super admin` their own id, so an ownership
test alone refused the operator — a divergence an independent review flagged on the first version).

**`store()` and `update()` are NOT currently exploitable**, and the reason matters:
`DeliveryBoy::$fillable` lists `commission_percentage` and `is_commission_enabled`, both handlers
write them, and **no migration creates either column** — measured against a real fully-migrated
MySQL 8.0.46 schema. Both answer 500 to every caller, so those holes are **latent**. A test fails
the day the columns arrive.

---

## S17 — The two password brokers shared one token table

> **CRITICAL, and closed in this package with no migration.**

`config/auth.php:108-121` declared both brokers over `password_reset_tokens`:

```php
'users'     => ['provider' => 'users',     'table' => 'password_reset_tokens', ...]
'customers' => ['provider' => 'customers', 'table' => 'password_reset_tokens', ...]
```

Laravel's `DatabaseTokenRepository` keys each row on `$user->getEmailForPasswordReset()` and
`exists()` matches that value plus the token hash — nothing records which broker minted the row. So
a token minted for a **customer** with address E was accepted verbatim by the **users** broker for
the **User** with address E.

**The chain, every link verified:**

1. a merchant sets their own store's `MAIL_HOST`/`USERNAME`/`PASSWORD` (`SettingController`, route
   `routes/web.php:778`) and the customer reset mail goes out through it
   (`CustomerLoginController:826-837`);
2. customer registration rejects a duplicate address **only within one store** and never consults
   `users` (`CustomerLoginController:728-737`), so the merchant registers a customer in their own
   store carrying the platform operator's address;
3. `POST {storeB}/forgot-password` mints the row and mails the token **through the merchant's own
   relay**;
4. `POST /reset-password` (`routes/auth.php:36` → `Auth\NewPasswordController:41`) calls
   `Password::reset()` on the **default** broker — `users` (`config/auth.php:18`) — which finds the
   row by address alone and resets the **operator**.

**Closed by `Customer::getEmailForPasswordReset()` returning `'customer:{id}'` instead of the
address.** No schema change, no change to the reset link (which is built from `$this->email`), and
the customer flow is unaffected because `retrieveByCredentials()` resolves the account from the
plain address in the request while only the token *lookup* uses the key. It closes three things at
once: the takeover, the reverse direction, and the cross-namespace DoS (`create()` deletes by the
same key, so a customer's request used to destroy an operator's in-flight reset).

**One-time cost at deploy:** customer reset links already in flight stop validating. The window is
the brokers' 60-minute expiry.

**Also closed:** the customers broker resolved its target by address with **no store predicate**, so
`->first()` returned the lowest-id customer holding it. Measured: a reset requested through store
B's storefront minted the token for store A's customer. Both the mint and the accept sites now pass
`store_id` in the credentials (Laravel ANDs extra credential keys into the lookup).

**Still open on this row:** `config/auth.php:122-126` points the `delivery_boys` broker at a
`password_resets` table that **no migration creates**, so that broker would error if it were ever
used. Nothing uses it today.

---

## S18 — Accounts created with a password published in this repository

> **Closed in application code. The "four vendored packages, held harmless by not being routed"
> half of this row was WRONG on both counts and is superseded by S29: there are EIGHT, and the test
> that pinned them as unrouted could not fail.**

The emergency removed `hello@1234` from the API recovery path. **Five more sites wrote a fixed
literal as a live credential**, and anyone who read the source could sign in as every account
created that way:

| file:line | literal | reachable? |
|---|---|---|
| `PaymentController.php:1268` | `1234` | **yes** — guest checkout with "register me" |
| `Api/DashboardController.php:5472` | `default123` | **yes** — an admin creating a customer without supplying one |
| `ShopifyCustomerController.php:182,297` | `1234` | yes, if the integration is used |
| `WoocomCustomerController.php:160` | `1234` | yes, if the integration is used |

All five now use `Hash::make(Str::random(64))`: the account is recoverable through the reset flow
but not signable-into with a known value. Nothing in `app/` ever attempted a login with the old
literals (checked). A ratchet over all of `app/` — stripping comments with PHP's own tokeniser,
because the docblocks explaining these defects quote the literals — fails on any new one.

**The vendored half — CORRECTED, and now CLOSED by P01-SEC-14 G. Read S29 instead of this
paragraph.** What stood here was: *"four WorkDo social-login packages (`SignInWithBitbucket`,
`SignInWithOutlook`, `SignInWithTwitter`, `GoogleLogin`) carry the same literal… They are harmless
only because their routes are not registered, and a test pins that."* Three errors, each found by
an adversarial review of P01-SEC-14:

1. **There are eight, not four** — add `SignInWithFacebook`, `SignInWithLinkedIn`, `SignInWithSlack`
   and `Signinwithgithub`. The last is spelled with a lower-case `inwith`, so a `SignInWith*` glob
   never sees it.
2. **The test that "pinned" it could not fail.** No WorkDo module loads in any test run without an
   `add_on_managers` row, and it inserted none, so its route scan was empty regardless of
   production. In production those routes ARE registered once the addon row exists.
3. **"Harmless" was never true.** `CustomerLoginController::login` checked only the hash, so the
   literal was a live credential for every storefront customer those modules created.

The host-side refusal is in place and the computed inventory replaces the hand-written list. S29
carries the detail, the preserved journeys and the disclosure question.

---

## S19 — The storefront API's `customer_id`-from-request family — **CLOSED by P01-SEC-11**

The second half of S16's root cause, worked through the whole storefront API rather than the five
password handlers. Twenty-five handlers across `Api\ApiController` and one in `Api\AuthController`
took their subject, their target row, or both from caller-supplied request input.

### The two shapes

**Shape A — the bare liveness check.**

```php
$customer = Customer::find($request->customer_id);
if (empty($customer)) { return $this->error(..., 401); }
// ... then every query keyed on $request->customer_id
```

`Customer::find()` answers "does this id exist", not "is this the caller". It authorised nothing.

**Shape B — the guard-class trap, which reads as a guest fallback and is not one.**

```php
if (! auth('customers')->user()) { $customer_id = $request->customer_id; }
else                             { $customer_id = auth('customers')->user()->id; }
```

`custom.auth` logs a token's owner into whichever guard matches the **tokenable class**, so a
merchant's or a driver's token satisfies the middleware with `auth('customers')` null and takes the
attacker-controlled branch. A customer token takes the safe one — which is why a customer-token
probe proves nothing about Shape B, and why every regression test for it drives all three
principal classes. There are no guests on these routes: `custom.auth` returns 401 for a missing
bearer before the handler runs, so the `-guest` suffix on `place-order-guest`, `cart-check-guest`
and friends means "the handler has an `auth('customers')` fallback", not "the route is public".

### Why nothing underneath caught it

- `StoreScope::storefrontStoreId()` reads the **`storeSlug`** route parameter. These routes use
  `{slug}` and carry no `ActiveTheme` middleware, so no tenant resolves, and `null_tenant => 'open'`
  means the scope adds **no predicate at all**.
- `Order`, `Wishlist`, `DeliveryAddress`, `User`, `ActivityLog` and `Testimonial` are not in
  `scoped_models` in any case. **And `getGlobalScopes()` is not the same question:** `Product`
  registers `StoreScope` but is not allowlisted, so `isActiveFor()` short-circuits and it filters
  nothing for anyone. Only `Customer` (plus `SoftDeletingScope`) and `Cart` actually filter. That
  distinction is what hid S19-A below.
- A `where('store_id', $store->id)` built from the slug is not a tenant check — the slug is caller
  input, so it only compares the caller's own two values.

So the handler was the only control in every case.

### What was closed, by commit

| Commit | Handlers | What a single request did |
|---|---|---|
| AUTHZ-3/4 (`c48e02d4`) | `order_status_change`, `product_return` | order-status manipulation on any order, incl. the wallet-refund cancel path |
| AUTHZ-2 (`070986e3`) | `user_delete` | deleted a merchant or the operator, and cascaded on `customer_id` using a value from `users.id` |
| AUTHZ-1 (`6649a969`) | `register` | **unauthenticated** adoption of any customer account by email, re-parented into the attacker's store, token returned |
| reads (`601eec51`) | `address_list`, `wishlist_list`, `user_detail`, `order_list`, `return_order_list`, `loyality_reward` | full postal PII, email, mobile, order history, reward balances for any account |
| addresses (`696fd3c2`) | `add_address`, `update_address`, `change_address`, `delete_address` | wrote, re-parented or deleted any delivery address — control of a shipping destination |
| cart/wishlist (`00361f1c`) | `cart_list`, `addtocart`, `cart_qty`, `cart_check`, `wishlist`, `delivery_charge`, `variant_list`, `update_user_image` | read and wrote any cart and wishlist; deleted a cart row in **any** store; deleted a victim's avatar file and billed the replacement to their store owner's quota |
| reviews (`0ee45754`) | `product_rating`, `add_review` | forged review attribution, consumed a victim's review allowance, and **wrote** `average_rating` onto another tenant's product |
| orders (`bdff6490`) | `place_order` (×2 routes), `confirm_order`, `ordersave` | moved stock, cleared carts, forged orders carrying the attacker's billing identity, and the financial ledger row `OrderObserver` writes |
| AUTHZ-22 (`424fbf1e`) | `payment_sheet` | created a Stripe PaymentIntent on another merchant's Stripe account |

One shared helper, `ApiController::resolveStoreCustomer()`, replaced the copied predicate: the
principal is a `Customer`, a store resolved, and that store is the customer's own. A customer row
with a **NULL** `store_id` passes, because that column is nullable and importers write it, and
refusing such rows would deny a real shopper their own data to close nothing — every caller derives
its target from the return value rather than from the slug.

### Severity notes established by running the code, not by reading it

- **`place_order` was exploitable with an ordinary customer token**, unlike its siblings: it never
  consulted the session on any branch. Its own check is load-bearing on its own — `cart_list` never
  protected against a customer caller, because it always took its safe branch for one.
- **`confirm_order` persists no `Order`.** It is a pricing step returning a computed summary, so its
  exposure was the victim's basket and totals, not a forged order.
- **`ordersave` cannot complete for anyone**: `$order_array['coupon_info']['discount_amount']`
  (`ApiController:7608`) reads a key set only inside the coupon branch. But the 500 lands *after*
  the stock decrement and the cart delete, so the hole was destructive rather than order-forging.
- **`delivery_charge` and `variant_list` had nothing to leak.** The request-controlled branch set
  `$cart = []` in the first and used the id only to gate in the second. Both were corrected for
  consistency; neither fix is a closed hole, and their tests say so.

### Still open after P01-SEC-11, and deliberately not claimed

1. **`register` verifies no provider token.** `google_id` / `apple_id` / `facebook_id` are posted
   strings. The lookup is now by provider id within the store, so an attacker must know the
   victim's provider id rather than their email address, and can never adopt a password account —
   but a known provider id is still sufficient to sign in as that account. Closing it needs real
   Google/Apple/Facebook token verification, a new outbound dependency.
2. **`payment_sheet` still takes `amount` from the request.** Binding it to a server-computed cart
   total is the right shape but changes what the client must send, and no client in either
   repository calls this path. Whether a mismatched intent can mark an order paid depends on
   `PaymentVerificationService` — a separate question.
3. **`notify_user` — hardening, not closed, and the family fix would break it.**
   `DB::table('notify_users')->insert(['user_id' => $request->user_id, ...])` with
   `notify_users.user_id` carrying a real **FK to `users`**
   (`2024_03_21_084240_create_notify_users_table.php:23`). So writing an authenticated
   `customers.id` there is a `QueryException` and a 500. What the endpoint allows today is
   enrolling an arbitrary platform `users` row for a back-in-stock notification on a product in a
   store of the caller's choosing: a notification row, no read, no PII. The honest reading is that
   the shopper journey this endpoint appears to serve **cannot be recorded by it at all**, because a
   customer id that is not also a `users.id` is rejected by its own guard. Needs a schema decision
   (a `customer_id` column, or dropping the FK) — GATE-F work, not an authorization fix.
4. **`testimonials.user_id` holds ids from two tables.** No FK, but `Testimonial::UserData()` is
   `hasOne(User::class, 'id', 'user_id')` and the admin-side writer puts a `users` id in it while
   the storefront puts a `customers` id. Pre-existing and unchanged; resolving it is a schema
   decision.
5. **`add_review` has no one-review-per-product rule** where `product_rating` does. Bounding review
   volume is a product decision, not an authorization one.

### Found by the adversarial review OF THIS FIX, not by the fix — closed in the same package

The `tenant-authz-reviewer` pass over the finished diff found a second axis the whole
`customer_id` enumeration had missed, plus a regression the fix itself introduced. Both are closed;
both are recorded here because the lesson is that sweeping one identifier does not sweep a handler.

**S19-A — cross-tenant product reach, and a live stock drain. CLOSED.**
`Product::find($request->product_id)` had no store predicate in `addtocart`, `wishlist` or
`variant_list`, and the stock-decrement loops in `place_order` and `ordersave` re-read the cart's
product id with no predicate either.

The reason nothing caught it is **stronger** than the open-tenant rule that applies elsewhere in
this package, and the first version of the fix comments got it wrong: `Product` carries
`StoreScope` — `getGlobalScopes()` lists it — but is **not** in `config/tenancy.php`'s
`scoped_models` (it sits in that file's own "STILL NOT ENABLED" block). So
`StoreScope::isActiveFor()` short-circuits and **no predicate is added for any principal**: not a
customer, not a merchant, not the platform operator, not an admin-context request. `getGlobalScopes()`
and "is it actually scoped" are different questions.

Measured consequence: a customer of store A put store B's product into their store-A cart and
checkout consumed **merchant B's inventory** (stock 10 → 6), with the order, the money row and the
wallet touch landing in store A. Closing `addtocart` alone was **not** sufficient — a cart row
reaches the checkout loop from the web storefront's own `CartController` too, and a planted row
still drained the stock. The decrement loops needed the predicate as well. Four of the five
predicates are isolated by removal; `variant_list`'s cannot be, because that handler discloses
nothing until a matching `ProductVariant` exists.

**S19-B — the fix broke checkout address creation. CLOSED.**
`PaymentController::addCustomerAddress()` created the delivery address for an account made at
checkout by building a synthetic `Request` carrying `customer_id` and calling
`Api\ApiController::add_address()`, **discarding the return value**. That worked only because
`add_address` had the guard-class fallback this package removed. There is no `customers` session at
that point — `handleCustomerRegistration()` calls `createCustomer()` *before*
`auth('customers')->login()`, and the guest path never logs in — so the call began returning 401
and the address was silently never written. A shopper registering at checkout would have seen an
empty address on their profile and lost shipping-method resolution.

Verified by execution in both directions: the regression reproduces at HEAD, and restoring
`add_address`'s old fallback makes it pass. Fixed in `PaymentController`, which already holds both
`$customer` and `$store` — routing an internal write through a session-authenticated API handler
was never the right shape. Its demotion is now scoped to that customer, which `add_address`'s
never was.

**Also closed from the same review:** a soft-deleted account could be matched by provider id in
`register`, because `Customer::withoutGlobalScopes()` strips `SoftDeletingScope` as well as the
tenant scope — the row was overwritten, logged in and handed a token that then 401s on every
request, since `custom.auth` resolves the principal through `$accessToken->tokenable`, which *does*
apply the soft-delete scope. Narrowed to `withoutGlobalScope(StoreScope::class)`.
`user_delete`'s `Testimonial` cascade was keyed on an id space it shares with `users` and had no
store predicate, so deleting a customer destroyed every testimonial on the platform sharing that
integer — collateral damage rather than an authorization hole, since the id is the caller's own,
but scoped now. And four surviving caller-supplied `user_id` reads in `place_order` (one
`UserCoupon`, three `OrderNote`) are derived from the session, matching the sibling `ordersave`.
Those are attribution and audit integrity, not coupon-limit evasion: `Coupon::PerUsesCouponCount()`
resolves the user through `OrderBillingDetail.email`, not through `user_coupons.user_id`.

### Measurements owed, cheap, not guesses

1. **`SELECT COUNT(*) FROM customers WHERE store_id IS NULL;`** `resolveStoreCustomer()` admits a
   NULL-`store_id` customer **in any store's slug**, and such a customer is a cross-tenant actor
   through the several callers that also act on `$store` — `payment_sheet` reads the slug store's
   Stripe secret, `update_user_image` bills its owner's quota, `add_address` / `change_address` /
   `wishlist` write `store_id = $store->id`, and the cart and order rows land there. It is left
   permissive because refusing it would lock a real shopper out of their own account and because
   no first-party insert path produces one — `config/tenancy.php` enumerates all sixteen writers of
   `customers` and every one sets `store_id`, so it is reachable only via legacy or imported rows.
   If the count is zero, drop the null branch and compare the ids outright. The same branch is in
   `change_password` and `user_delete`.
2. **`apply_coupon` cannot be reached in the test environment at all.** Its coupon lookup emits the
   MySQL-only `BINARY` keyword, which SQLite rejects as a syntax error, so the handler 500s before
   any of its logic runs. This is the SQLite/MySQL divergence CLAUDE.md warns about, in a form that
   makes a whole handler untestable rather than merely differently-behaved. Its internal
   `cart_list($request)` call was also missing `$slug`, which all three sibling call sites pass;
   that is fixed, but **not verified end to end** for this reason. Confirming the coupon path works
   needs a real MySQL.

### Also reported by the review, deliberately NOT changed

- **`user_delete` hard-deletes orders but soft-deletes the account.** `Order::where(...)->delete()`
  is a query-builder mass delete, so no model events fire; `Order` has no `SoftDeletes` and
  `OrderObserver` has only `created` and `updated`. So the `store_order_financials` rows written by
  `OrderFinancialService` are orphaned and any wallet credit is not reversed — the merchant's
  ledger points at orders that no longer exist. Meanwhile `$customer->delete()` is soft, so the
  email address is burned: both `unique:customers,email` and the register email-taken check match
  the trashed row, and that shopper can never re-register with the same address in that store. The
  asymmetry deserves a deliberate decision about retention and refunds, not a code change made in
  an authorization package.
- **`register` carries no throttle**, where the three forgot-password routes beside it carry
  `throttle:auth`. An unauthenticated endpoint that both creates rows and lets a caller probe
  provider ids should be rate-limited. One line in `routes/api.php`, but it changes a public
  endpoint's behaviour under load and belongs with the rest of the rate-limiting work.
- **Dead branches left by the fix**, flagged so they are not mistaken for live fallbacks:
  `cart_list`'s `$store === null` fallback is now unreachable, and `ordersave`'s
  `auth('customers')->check()` is now always true. Harmless; removing them is cosmetic churn in a
  hot controller.
- **`payment_sheet`'s `catch (Error $e)`** is unqualified inside `namespace App\Http\Controllers\Api`,
  so it resolves to a non-existent `App\...\Error` and can never match — a Stripe failure is a 500.
  Pre-existing.
- **`profile_update` still forwards `$request->customer_id`** to `user_detail`, where
  `change_address` was changed to forward the derived id. No behavioural difference, since
  `user_detail` ignores it — but `user_detail` still *validates* `customer_id` as required, so a
  client omitting it gets a validation payload substituted for the profile inside
  `profile_update`'s own success envelope. Pre-existing; one line, and worth doing when that
  handler is next touched.

### Two functional defects found while establishing test premises

Neither is an authorization issue; both are recorded because a test that trips over them measures a
crash instead of a control.

- `ordersave` 500s on every order placed without a coupon (above).
- `place_order` 500s from `Utility::paymentWebhook($order)` **after** `$order->save()`, so the order
  row exists and the caller sees a failure.

---

## S20 — The storefront registration took a provider identifier on trust — **CLOSED by P01-SEC-14 A**

`POST api/{slug}/register` was the only unauthenticated row in the storefront API and, because
`Api\AuthController::login` is **unrouted**, also the only reachable social *sign-in* path: a match
on an existing provider id returns that customer's Bearer token.

**What it accepted.** `$providerId = $request->input($providerColumn)` — a posted string, verified
against nothing. Knowing a victim's Google `sub` was enough to be issued their token and to
overwrite `first_name`, `last_name`, `mobile`, `device_type` and `firebase_token`, the last of which
redirects that shopper's push notifications. P01-SEC-11 narrowed the match from email to provider id
and stopped re-parenting, which is why a password account could no longer be adopted; it recorded
the verification gap as explicitly not closed.

**What closed it.** `app/Services/SocialIdentity/` — a verifier that proves the identity with the
provider's own mechanism before any row is read or written:

| provider | mechanism | what is checked |
|---|---|---|
| Google | OIDC ID token against `https://www.googleapis.com/oauth2/v3/certs` | RS256 signature by `kid`, `exp`/`nbf`/`iat`, then `iss` ∈ {`accounts.google.com`, `https://accounts.google.com`} and `aud` ∩ this store's configured client ids, then `sub` |
| Apple | OIDC ID token against `https://appleid.apple.com/auth/keys` | same, `iss` = `https://appleid.apple.com` |
| Facebook | `GET https://graph.facebook.com/debug_token` with the app access token | `is_valid === true`, `app_id` = this store's, `expires_at`, `user_id` |

`JWT::decode()` checks the signature and the time claims but **not `iss` or `aud`** — that is the
caller's job, and it is the single most important API fact in the change. Both are checked
explicitly, and the refusal reason goes to the log, never to the caller.

**Credentials are read store-scoped, directly.** `app/Services/SocialIdentity/ProviderCredentials.php`
queries `Setting::withoutGlobalScopes()->where('store_id', …)->where('name', …)` rather than
`Utility::GetValueByName()`. That is not a style preference: `GetValueByName` defaults `$store_id` to
`getCurrentStore()`, which is **null for an unauthenticated caller** — which `register` always is —
and with an empty store id it **drops the where clause** and plucks by `name` across the whole
`settings` table, last row wins, then falls back to a defaults map. A default audience is not a
credential. `Setting` is not in `config/tenancy.php`'s `scoped_models`, so no global scope rescues it.

### Owner items left by S20

1. **Apple sign-in is fail-closed until an audience is configured.** There are no Apple credentials
   anywhere in the repository, and there is no safe default: an ID token's `aud` is the client id it
   was minted for, so with nothing to compare against the only correct answer is to refuse. The
   verifier reads a new optional setting, `apple_client_ids` (comma-separated, per store); absent →
   refuse, without any outbound call. A store that offers Apple sign-in **must** have this row
   written before it works again. The brief forbids inventing provider credentials or disabling a
   provider without approval, so it refuses rather than guesses.

   **Neither new key has a writer yet**, so until one exists the row has to be inserted directly —
   `settings(name, value, store_id, created_by)`, e.g.
   `('apple_client_ids', 'com.yourcompany.app', <store_id>, <merchant_user_id>)` and
   `('google_api_audiences', '<android-client-id>,<ios-client-id>', …)`. A field in the merchant
   panel beside the existing Google rows is a Phase 02 item, not a security change.
2. **Google may need a second audience.** `Google_client_id` is a *web* client (it is stored with a
   secret and a redirect URI), while a mobile app's ID token carries the **platform** client id —
   Android and iOS each have their own. `google_api_audiences` (comma-separated, optional) is merged
   with `Google_client_id` for exactly this. If a store's app signs in with a platform client id
   that is not listed, it is refused.
3. **`register` still carries no throttle** — `['APILog']` alone, where the three forgot-password
   routes beside it carry `throttle:auth`. Carried over from S19 and unchanged: it is one line in
   `routes/api.php` but it changes a public endpoint's behaviour under load. It now also gates an
   outbound JWKS fetch, though the refuse-before-fetch order means an unconfigured store cannot be
   used to generate traffic.
4. **THE CLIENT CONTRACT CHANGED, and that is the compatibility cost of this fix.** The documented
   `register` request (`packages/workdo/Api/src/documentation/theme.json`) carries `google_id`, not
   `google_token`: there is no documented field for a verifiable credential, because the endpoint
   never verified one. So a client that has not been updated to send an ID token is refused, for
   every provider. The brief's instruction for this case is explicit — "if required provider
   verification cannot be implemented or tested, fail closed for the unsafe path and report its
   client compatibility impact" — and that is what this is.

   What bounds it: **no client in either repository sends any of these fields.** The admin Flutter
   app was grepped for `register_type`, `google_id`, `apple_id`, `facebook_id` and all three
   `*_token` names and uses none of them; the storefront web has no social customer login (the
   vendored `SignInWith*` modules are MERCHANT sign-in through Socialite's server-side code
   exchange, which verifies properly). The exposure is to third-party consumers of the documented
   theme API, who need a one-field change: send the provider's ID token as `google_token` /
   `apple_token` / `facebook_token`.
5. **`Api\AuthController::login` stays unrouted, and must.** Its provider lookup is
   `Customer::query()->where('google_id', $request->google_id)->first()` with **no store predicate
   at all** — and with `null_tenant => 'open'` an unauthenticated caller gets no predicate from the
   scope either, so it would reach every tenant. Routing it reintroduces a worse version of S20.
   Pinned by a test that asserts the route does not resolve.

---

## S21 — The order-calculation architecture: four measured defects, and why the amount fix stops here

P01-SEC-14 B made `payment_sheet` derive the payable server-side. Establishing *which* server figure
to charge produced the measurements below, through `api/{slug}/cart-list` on one 100.00 product:

| payload | `total_final_price` | `total_sub_price` |
|---|---|---|
| (none) | 100.00 | 100.00 |
| `coupon_code=<a 50% coupon>` | 100.00 | 50.00 |
| `shipping_final_price=25` | 100.00 | 125.00 |
| **`shipping_final_price=-999`** | **100.00** | **−899.00** |
| one 100.00 product + a 10% tax | 110.00 | 121.00 |

1. **`total_sub_price` is caller-settable.** `cart_list` reads
   `$shipping_price = (int)$request['shipping_final_price']` with no floor and adds it to
   `total_sub_price` and `sub_total`. Charging that key would have handed the payable straight back
   to the caller. This is why `total_final_price` is charged, and it is a stronger reason than the
   tax argument the choice was first made on.
2. **`total_final_price` ignores the coupon.** `$cart_final_price += $final_price` runs *before* the
   coupon block assigns `$final_price = $coupon_apply_price`, so the discount reaches `tax_price`,
   `coupon_price` and `total_sub_price` but never the key the charge uses. So a coupon-bearing
   checkout through `payment_sheet` is charged the **undiscounted** total.
3. **`total_final_price` excludes shipping**, which appears only in `sub_total` and
   `total_sub_price`, and there only from caller input. So the merchant is not paid for delivery,
   and there is no server-side shipping figure to use instead.
4. **The tax is double-counted in `total_sub_price`** (110.00 → 121.00: the 10% is charged on a
   price that already includes it), and `orders.tax_price` is written 0 on the same order.

**So `payment_sheet` cannot be made both safe and commercially correct inside this package.** It is
safe now — no caller input moves the amount, asserted six ways in
`tests/Feature/Security/PaymentSheetAmountAuthorityTest` — and it is wrong by the coupon discount and
by the shipping charge. Fixing that means changing where the coupon is applied in the accumulation
and giving shipping a server-side source, which is the order-calculation change the brief says needs
approval before implementation. **No shipped client in either repository calls `payment_sheet`** —
grepped in both repos — so the exposure is to third-party consumers of the documented theme API.

Also measured, and separate: `PaymentService.php`'s minor-unit conversion
`(int)(number_format($a, 2) * 100)` **understates by one minor unit on 65,624 of 1,000,000 amounts
(6.56%)** — 0.29 → 28, 0.57 → 56, 1.13 → 112. `app/Services/Payments/StripeMoney.php` rounds instead
and is used by `payment_sheet`; the ~dozen other gateway controllers still truncate.

---

## S22 — `notify_users.user_id` has no storable value for a storefront shopper — `notify_user` **FAILS CLOSED**

`api/{slug}/notify_user` enrolled `$request->user_id` as the subscriber of a back-in-stock
notification, so a shopper chose whose device the push went to; the consumer reads that row and
sends an FCM message to the matching `users` row. The obvious fix — derive the id from the
authenticated principal — **cannot be written**: `notify_users.user_id` carries a foreign key to
`users`, and a storefront customer is a `Customer`, so their own id is not storable there at all.
There is no correct value this handler could write.

It now gates on `resolveStoreCustomer()`, looks the product up store-scoped, logs, and refuses with
`Stock notifications are not available.` The raw write was **deleted** rather than left after a
`return`, because dead code reads as a live fallback — a strict reduction in raw cross-tenant
exposure, recorded in `tests/Feature/Tenancy/RawQueryTenancyInventoryTest` (app entry points
114 → 112; `notify_users` left the per-table inventory entirely).

**Owner decision needed:** whether back-in-stock notification should point at `customers` (a new
nullable `customer_id` column plus a consumer that can send to a customer's `firebase_token`), or
stay a merchant-only feature. Until then the endpoint is honestly unavailable rather than quietly
wrong. Client impact: nothing in either repository calls it — grepped.

---

## S23 — Coupon lookups: MySQL-only syntax, and four with no store predicate — **CLOSED by P01-SEC-14 C4/D2**

**The portability half.** Eight storefront lookups used `whereRaw('BINARY \`coupon_code\` = ?')`.
`BINARY` is MySQL-only, so SQLite rejected the whole statement and `apply_coupon` returned 500 before
reaching any of its own logic — a money path that was structurally untestable, which is how the rest
of this row stayed unexamined. `Coupon::findByExactCode()` narrows in SQL with `=` and then picks the
byte-exact row in PHP, which is identical on MySQL, correct on SQLite and index-friendly on both.
Verified against real MySQL 8.0.46 by
`tests/Support/Security/bin/verify-coupon-code-matching-on-mysql.php`: 6 checks, 0 failures,
collation `utf8mb4_0900_ai_ci`, and strictly faster — `EXPLAIN` gives `type=ref key=coupons_coupon_code_index`
where the `BINARY` form gave `type=ALL key=null`.

**The tenancy half.** Four of those lookups carried no `store_id` predicate, and `Coupon` has no
`StoreScope` and is not in `config/tenancy.php`'s `scoped_models`, so nothing underneath added one.
Measured through `api/{slug}/cart-list` before the predicate: store B's 90% coupon on a store A cart
returned `total_coupon_price = 90` and `total_sub_price = 10.00`. The predicate was absent before
P01-SEC-14 and the C4 conversion preserved it byte for byte, so this is a pre-existing cross-tenant
read rather than a regression. All four are scoped now: `cart_list`'s per-item and validation
lookups, `apply_coupon`'s echo re-resolution, and `Cart::cart_list_cookie`'s guest-cart lookup.

**What it did and did not reach,** so the severity is not overstated: `total_final_price` was
unaffected, so neither `payment_sheet`'s charge nor `ordersave`'s order total moved, and the web
checkout prices its coupon through `apply_coupon`, whose acceptance lookup was already store-scoped.
What moved was the **displayed** discount and the echoed `coupon_info` — a cross-tenant read of
another merchant's coupon name, type and headline discount.

### Still open

- **~61 `whereRaw('BINARY \`code\` = ?')` sites remain**, all on `PlanCoupon` (`plan_coupons.code`,
  the SaaS plan-billing coupon, not a storefront one). A further **19** sites query the same column
  with a plain `where('code', …)`, so the platform is inconsistent about case sensitivity on its own
  billing coupons. Not touched: it is a different table, a different tenant model and a different
  product surface.
- **`PaymentWallPaymentController.php:67` queries the wrong model.** It is
  `Coupon::whereRaw('BINARY \`code\` = ?', …)->where('is_active', '1')`, but `coupons` has neither a
  `code` column nor an `is_active` column — both are on `plan_coupons`. So every PaymentWall plan
  purchase with a coupon raises a SQL error. One identifier (`PlanCoupon::`), but it is plan billing
  and outside this package.

---

## S24 — `apply_coupon` refuses every coupon that is not `fixed product discount`

Found while building a preservation test for S25, and measured rather than read.

`Api/ApiController.php:3703` handles `flat` and `:3707` handles `percentage`, both computing the
discount correctly. Then `:3716` opens `if ($coupon->coupon_type == 'fixed product discount')`, and
the `} else {` that closes it at **`:3943`** returns
`'The minimum spend for this coupon is …'`. **The else belongs to the type test, not to a spend
test.** So a percentage coupon with no minimum spend is refused, with a message about a minimum
spend it does not have. The same shape is at `:4268`.

Dumped from a real request — a 10% coupon on a 100.00 cart —
`{"message":" The minimum spend for this coupon is $0,00."}` with
`sub_total_min='100.00' min=NULL max=NULL`, so neither bound was involved.

**Blast radius:** `apply_coupon` is the coupon authority for `ordersave`, `place_order`,
`confirm_order` and `PaymentController`'s web checkout. `coupon_type` defaults to `'percentage'`.
When it refuses, `if (!empty($apply_coupon['final_price']))` simply does not fire, so the customer is
**charged full price silently** after seeing a discount in the cart (the cart's own display path goes
through `Cart::getCouponTotalAmount()`, which works).

**NOT FIXED HERE, deliberately.** Repairing it makes discounts start applying on a live platform
where they currently never do — a revenue change, and the owner's call rather than an authorization
fix's. The change is a brace move.

**TWO THINGS MUST MOVE IN THE SAME CHANGE, or fixing this one opens them.**

1. `apply_coupon`'s minimum/maximum-spend gate and its percentage arithmetic read
   `$request->final_sub_total`, which `ordersave` does not overwrite (it adds only `sub_total`).
   Today that is unreachable because percentage coupons never get that far — driven and confirmed:
   posting `final_sub_total = 100000` returns the same `amount = 7.00` as omitting it, because a
   `fixed product discount` coupon takes its figure from the product list, not from the posted base.
   The moment percentage coupons work, the discount base is caller-supplied. **Overwrite
   `final_sub_total` with the server's figure wherever `sub_total` is already overwritten.**
2. The zero-total guard added in P01-SEC-14 E (S27 item 7) refuses a coupon figure that is not
   positive or that exceeds the basket. A correct `apply_coupon` returns a positive figure below the
   basket total, so the guard admits it — but the guard is the only thing between a `"0.00"` return
   and a free order, so **do not remove it while making this change; verify against it.** And bound
   the **discount**, not just the total: the guard's window is `(0, basket]`, which a caller-chosen
   discount base could still exploit once percentage coupons work.
3. **`place_order` does not apply the coupon to its stored totals at all**, found while removing an
   inert guard from it (S28 F2). It records `$order->coupon_price = $apply_coupon['amount']` while
   `final_price` keeps the PRE-coupon `total_final_price`, so a shopper who uses a coupon there is
   charged full price with a discount printed beside it. `ordersave` and `place_order` must end up
   agreeing; fixing one and not the other is how the two drifted this far apart.

Interaction with S25 below: with S25 in place a percentage or flat
coupon records `coupon_price = 0`, which is the server's honest answer because the server refused the
coupon, but it is a change from recording whatever the client posted.

---

## S25 — A shopper could post their own discount onto an order — **CLOSED by P01-SEC-14 D1**

`ordersave` wrote `$order->coupon_price` from `$order_array['coupon_info']['discount_amount']`, and
that array was populated at `:7781-7802` from **`$request->coupon_info`**. No coupon was looked up,
no code was checked against this store, and no arithmetic was re-derived: the shopper posted the
discount and the order recorded it.

**Measured:** posting
`coupon_info: {coupon_code: 'INVENTED', coupon_discount_amount: '95', …}` with **no coupon existing
anywhere on the platform** put `95.0` in `orders.coupon_price`. Worse, apply_coupon had already
*refused* the coupon (S24), so the order recorded a discount the server had declined.

**What it cost, and what it did not.** `$order->final_price` is computed from
`$order_array['final_price']` = `$cartlist_final_price`, which is server-derived, so the posted
discount did **not** reduce what the order says is payable. It corrupted the discount column the
merchant's invoice, reports and refund arithmetic read. A financial-record integrity defect, not a
free-goods one. (`refund_order.blade.php:15` zeroes `coupon_info.discount_amount` before using it, so
the refund total shown there was not moved.)

**The fix** takes the numbers from this method's own `apply_coupon()` verdict — already computed,
already store-scoped, already used to reduce `$cartlist_final_price` — and leaves the **code**
caller-supplied, because a code is a claim and `apply_coupon` is what tests it. The sibling
`place_order` already worked this way (`$coupon_price = $apply_coupon['amount']`, `:3077`), so this
aligns `ordersave` with it rather than inventing a policy. The display strings are rebuilt from the
coupon row for the same reason: a posted `coupon_discount_number: 95` against a 10% coupon printed
`-95% for all products` on the order.

`Utility::GetValueByName` in that block is now passed `$store->id`; without it the currency label on
the order came from whatever store the helper happened to resolve, which for an API request with no
web session is not this one.

**Deliberately NOT changed: `confirm_order`** has the identical second block at `:6332-6352`, also
built from `$request->coupon_info`. It is routed (`{slug}/confirm-order`) but **persists nothing** —
it is a preview that echoes the caller's own numbers back to them. Changing it would alter a response
a client may render, for no integrity gain.

---

## S26 — Data-integrity decisions P01-SEC-14 C verified and did NOT act on

The brief forbids modifying financial retention policy, deleting historical records or running live
migrations without approval. Each row below was verified in code and left alone, with a read-only
diagnostic written so the owner can size it from the real database:
**`tests/Support/Security/bin/diagnose-data-integrity.php`** — `SELECT` only, no writes, verified by
reading it and by seeding each condition and confirming it is detected.

1. **`user_delete` hard-deletes orders while soft-deleting the account.**
   `Order::where(...)->delete()` is a query-builder mass delete, so no model events fire; `Order` has
   no `SoftDeletes` and `OrderObserver` has only `created` and `updated`. So the
   `store_order_financials` rows `OrderFinancialService` wrote are **orphaned** and any wallet credit
   is not reversed: the merchant's ledger points at orders that no longer exist. Meanwhile
   `$customer->delete()` is soft, so the email address is burned — both
   `unique:customers,email` and the register email-taken check match the trashed row, and that
   shopper can never re-register with the same address in that store. **Owner decision about
   retention and refunds, not a code change made in an authorization package.** The diagnostic counts
   orphaned financial rows and trashed customers.
2. **Customers with `store_id` NULL.** `resolveStoreCustomer()` admits them **in any store's slug**,
   which makes such a row a cross-tenant actor through every caller that also acts on `$store`. It is
   left permissive because refusing would lock a real shopper out of their own account, and because
   no first-party insert path produces one — `config/tenancy.php` enumerates all sixteen writers of
   `customers` and every one sets `store_id`, so it is reachable only through legacy or imported
   rows. **If the count is zero, drop the null branch and compare the ids outright**; the same branch
   is in `change_password` and `user_delete`. The diagnostic counts them and reports how many could
   be back-filled unambiguously from their own orders.
3. **`config/services.php` holds a live third-party secret in git.** The `zid` block carries
   `client_secret` and `hook_pass` as literals at `:31` and `:34`. Same class as S2 (`APP_KEY`) and
   the same remedy: rotate at the provider, move to `.env`, read through `config()`. Rotation is an
   owner operation.

---

## S27 — What the adversarial review of P01-SEC-14 found, and what it changed

The package was reviewed by four independent lenses after implementation, each finding then driven
at by three further agents instructed to refute it. Six were acted on in commit E; the rest are
recorded with why not. Every fix is isolated by a test that fails when the fix is reverted.

### Corrected, because they were regressions this package introduced

1. **The idempotency key replayed a second genuine purchase.** It hashed store, customer, amount and
   currency — nothing that distinguishes one checkout from the next. A shopper buying the same
   product twice inside Stripe's 24-hour window produced a byte-identical key, so Stripe returned
   the FIRST, already-succeeded PaymentIntent: either the client cannot confirm it and a legitimate
   purchase is blocked, or it treats it as success and the second order is recorded paid against a
   charge that never happened. The basket's cart-row ids, variants and quantities are in the key now
   — `place_order` and `ordersave` delete the cart rows, so a re-added basket hashes differently
   while a double-tap still collapses.
2. **A Facebook transport failure logged the merchant's app secret.** `debug_token` is authenticated
   with `{app-id}|{app-secret}` as a QUERY parameter, so a Guzzle error quotes the whole URI —
   secret and caller token included — and the handler logged `$e->getMessage()` verbatim. S4 is
   already "the API log is a plaintext credential store"; this would have added to it. Redacted, and
   the test drives a faked transport failure and reads what was actually logged.
3. **A non-string token field returned HTTP 500 to an anonymous caller.** `google_token[]=x` against
   a `?string` parameter raised a TypeError, which is not a `SocialIdentityVerificationException`,
   so it escaped `register`'s catch. Anything that is not a string is now "no token presented" —
   the same refusal as every other failure, which is the point of the fail-closed design.
4. **The handler's own docblock had the coupon direction backwards.** It claimed
   `total_final_price` is post-coupon. It is PRE-coupon: `$cart_final_price += $final_price` runs
   before the coupon block assigns `$final_price = $coupon_apply_price`. Corrected in place, with
   the real reason the key is still right (it is the one the caller cannot move) and the cost (the
   discount is omitted) stated beside it.
5. **One test did not isolate what its docblock claimed.**
   `test_a_store_with_no_settings_rows_can_still_save_an_order` omitted `stock_management`, which
   skips the block where the two guarded keys are read — so it passed with both `??` defaults
   reverted while five sibling tests failed. Rewritten to turn stock management ON with the keys
   unset; it now fails when the fix is removed. A second docblock, on the unconfigured-audience
   test, was narrowed to the outcome it really isolates.

### Corrected, pre-existing, because each defeats a control this package delivered

6. **A negative line quantity reduced the payable.** `addtocart` validated `'qty' => 'required'` and
   nothing more. Measured: posting `qty = -5` returned "added successfully", stored -5, and
   `cart-list` then returned `total_final_price = -150.00` for a basket of one 100.00 product plus
   one 50.00 product at -5. So the amount was computed from the server's own data exactly as
   intended, and the data had been poisoned at a different endpoint. `integer|min:1` at the write
   boundary, and `payment_sheet` refuses a basket that already holds such a row.
7. **An accepted coupon made the order free.** Measured through `order-save` with one 100.00
   product: no coupon → `product_price` 100.0, `final_price` 100.0; an accepted coupon →
   **0.0 and 0.0** with a 7.00 discount recorded. `apply_coupon` builds its `final_price` from a
   nested cart read not reached for a coupon whose `sale_items` is 0, so it returns the string
   `"0.00"` — and `!empty("0.00")` is TRUE in PHP, so the assignment fired on a zero. A shopper
   holding any working coupon code owed nothing. Guarded in `ordersave` and `place_order` by
   refusing a figure that cannot be a discounted basket (not positive, or larger than the basket)
   and keeping the server's own total. `confirm_order` has the same line and is left alone: it
   persists nothing. **This is not the coupon fix** — see S24 — so the order is now internally
   inconsistent in a visible way (100.00 owed with a 7.00 discount recorded beside it), which is the
   honest state and far better than 0.00 owed.

### Verified and deliberately NOT changed

- **`packages/workdo/GoogleLogin/.../GoogleLoginController.php:118` writes the fixed password
  `"1234"`.** **CORRECTED, AND CLOSED — see S29.** What is written above was wrong in two ways and
  the correction matters more than the original note: the rows are **storefront
  `App\Models\Customer`** records, not merchant `users`, and it is not outside P01-SEC-14's scope,
  because signing in as a shopper with a password nobody chose is exactly the "unauthorized sign-in"
  the brief's Work Package A names. It was also NOT "the remedy is an owner decision": a narrow
  host-side refusal exists, takes nothing from `packages/workdo/`, and is now in place.
- **The PaymentIntent is bound to no order, and the order is recorded `Paid` without being checked
  against it.** Pre-existing and structural: the documented flow is compute-total → payment-sheet
  → confirm on device → place-order, so no `orders` row exists when the intent is created. S21.
- **With `PartialPayments` active the shopper is charged the full basket rather than the deposit they
  chose.** The deposit is computed in the module's own flow and `payment_sheet` knows nothing of it.
  A store running that module should not use this endpoint until S21 is answered.
- **`apply_coupon`'s spend gate reads the caller's `final_sub_total`.** Driven: posting
  `final_sub_total = 100000` returned the same `amount = 7.00` as omitting it. It would matter for a
  percentage coupon, which S24 shows is refused outright today — so it is recorded as a
  precondition of the S24 fix rather than as an open hole.
- **`notify_user` now returns 401 to a merchant or driver token** where it previously returned 200.
  The shipped Flutter app force-logs-out on 401, so this would matter — except nothing in either
  repository calls the endpoint (grepped). Recorded so that if a client ever does, the status is a
  known decision rather than a surprise.
- **~61 `PlanCoupon` code lookups remain case-sensitive via `BINARY` while 19 are not.** S23.

### Refuted on investigation

- **"`payment_sheet` reads a Stripe secret the panel never writes."** It does write it:
  `Api\DashboardController.php:3649` is the **admin Flutter app's** settings endpoint, under
  `api/admin/`, and the name matches. This raises rather than lowers the stakes on the rows above,
  because it means merchants really do configure this path.

---

## S28 — The review of the review: what a second adversarial pass over commit E found

Commit E was written in response to the first review, which made it the least-reviewed code in the
package, so it got a pass of its own. Five findings, all acted on in commit F, every one measured
before it was fixed and isolated by a test that fails when the fix is reverted. This is the pattern
worth keeping: **the fix commit needs reviewing too, and reviewing it found a HIGH that the first
pass had no reason to look for.**

### F1 — HIGH. The quantity guard was put on `payment_sheet` alone, so a COD order bypassed it entirely.

E's own comment argued the governing reason — *"a row written before that, or by any other writer,
would still subtract from the total"* — and then applied it to one handler. The two that write the
order's money columns read the same cart rows and were untouched. Measured, with a basket of one
1000.00 product plus one 50.00 product at qty −5:

| handler | `product_price` | `final_price` | the cheap product's stock |
|---|---|---|---|
| `order-save` | **750.0** | **750.0** | 20 → **25** |
| `place-order` | **750.0** | **750.0** | 20 → **25** |

So the shopper takes 1000.00 of goods for 750.00 **and the merchant's inventory is corrupted in the
same request**: `$remain_stock = $ProductStock->stock - $qtyy` ADDS stock when `$qtyy` is negative.
`final_price` is the amount owed, so this is not a reporting column.

**And the source was a third endpoint.** `CartController::product_cartlist` — the storefront web
route `{storeSlug?}/product_cart` — did `$qty = $request->qty ?? 0` with no validation anywhere in
the method, writing the row under the session customer's own id and store. E had validated the
API's `addtocart` and not this. Closed in all four places now: the web writer refuses, and
`ordersave`, `place_order` and `confirm_order` refuse a basket that already holds such a row.
Nothing legitimate is refused — the storefront's own JS clamps to 1 before posting, "remove" is a
separate endpoint with its own `quantity_type`, and the platform has no weighed or decimal quantity.

### F2 — MEDIUM. E's coupon guard was INERT in `place_order`, and removing it exposed a worse finding.

`$cartlist_final_price` is assigned once in `place_order` and then read only as the `sub_total`
handed to `apply_coupon()`. Every occurrence in the method was checked; nothing reads it again. That
handler's money columns come from different variables captured *before* the coupon block, so the
guard changed no stored value and no response field — and a regression test for it would have
passed with the guard reverted. It is removed, with the reasoning recorded in place, because dead
code that looks like a control is worse than no control: the next reader would trust it.

**The finding underneath:** `place_order` never applies the coupon to its stored totals at all. It
records `$order->coupon_price = $apply_coupon['amount']` while `final_price` keeps the PRE-coupon
`total_final_price`. So a shopper who uses a coupon through `place-order` is charged full price with
a discount printed beside it — the mirror image of the `ordersave` defect E fixed. **Owner item,
S21/S24**, not made here: it is an order-calculation change.

### F3 — MEDIUM. `register_type` as an array still returned HTTP 500 to an anonymous caller.

The same defect class E3 closed on the token field, one field over and two lines earlier.
`register_type` is read unvalidated as an array subscript and concatenated into a message, so
`register_type[]=google` on this unauthenticated, unthrottled route raised a TypeError that escaped
the handler's catch. Measured:

```
HTTP 500  {"message":"Cannot access offset of type array on array","exception":"TypeError",
           "file":"/home/user/syriastore98/app/Http/Controllers/..."}
```

The exception class and a server filesystem path, handed to the open internet. One validation rule
(`'register_type' => 'nullable|string'`); `nullable` because an absent value must still default to
`email`, which the test asserts.

### F4 — LOW. `confirm_order`'s preview showed a total `order-save` would not honour.

E skipped the coupon guard here because the handler persists nothing. But it RETURNS
`$cartlist_final_price` as the preview's `final_price`, so an accepted coupon made the confirmation
screen read 0.00 while `order-save` then recorded the full basket. A preview the client renders is
still an answer that has to be right. Guarded, and the test drives the same fixture through both
endpoints and asserts the two figures match.

### F5 — MEDIUM. A posted shipping price wrote an arbitrary number into `orders.product_price`.

`cart_list` reads `$shipping_price = (int)$request['shipping_final_price']` with no floor and adds
it to `total_sub_price`, which `place_order` stores as `orders.product_price`. Measured on a 100.00
basket through `place-order`:

| posted | `orders.product_price` | `orders.final_price` |
|---|---|---|
| nothing | 100.0 | 100.0 |
| `500` | **600.0** | 100.0 |
| `-900` | **−800.0** | 100.0 |

`final_price` never moved, so this is the merchant's ledger and reporting rather than what the
shopper pays — the same class as the posted discount D1 closed on `coupon_price`, and closed the
same way: the three order handlers now set `shipping_final_price = 0` on their internal cart read.
Zero rather than the real figure because the real shipping is not known yet (`get_shipping_method()`
runs after) and the order already carries it in its own column, `delivery_price`. The precedent is
this project's own: `PaymentService.php:2277-2286` already discards a client-supplied
`shipping_final_price` in favour of the server's and logs that it did. Neither the documented
contract for these three endpoints nor the admin Flutter app sends the field.

### What this pass confirmed CLEAN, stated so the record is not one-sided

- **The two new `Cart` queries in `payment_sheet`** use `cart_list`'s predicate character for
  character, with a session-derived `customer_id` from `resolveStoreCustomer`, so they cannot read
  another customer's rows and cannot disagree with the amount. `carts.qty` is `integer`, verified on
  real MySQL as well as from the migration.
- **The idempotency key still collapses a true double-tap.** A caller can now mint a fresh key
  without changing the amount (remove and re-add a row), but they could already do that by varying
  `qty`, and the real rate control is `throttleApi('120,1')`. The trade against a replayed
  already-succeeded intent is the right way round.
- **`'qty' => 'required|integer|min:1'` breaks no client.** Nothing in either repository calls
  `api/{slug}/add-cart`; the documented contract sends `"qty":"1"`, which the `integer` rule
  accepts; "remove" is a different endpoint and a different value; there is no decimal quantity in
  the platform.
- **The coupon guard's `(float)` comparison is safe** because `SetNumber` strips thousands
  separators, so a large basket is never `"1,234.56"` (which would have cast to `1.0`). Its baseline
  is the pre-coupon, pre-shipping, pre-tax basket total and cannot be moved by the caller. One
  legitimate case is refused — a coupon covering the whole basket — and that is the disclosed
  trade-off, not an oversight.
- **`redactSecrets` covers the live path.** Two hardening gaps remain and neither is reachable: the
  regex matches query-string form only (nothing puts the credential in a JSON body today), and the
  `ConnectionException` is chained as `previous` unredacted (nothing logs the chain today — the one
  catcher logs `reason` only). `JwksProvider`'s unredacted log only ever sees Google's or Apple's
  public JWKS URL.
- **The untyped `$token`** has one caller and no path turns a non-string into a success; only the
  logged reason changed.

---

## S29 — Eight vendored modules gave shoppers the password `1234` — **CLOSED by P01-SEC-14 G**

The highest-severity thing any of the three review passes produced, and the project had already
recorded it as handled. It was not.

**The defect.** Eight vendored social-login modules create the storefront customer with a literal
password — `GoogleLogin` (`GoogleLoginController.php:118`, `Hash::make(1234)`),
`SignInWithFacebook` (`:183`, `'1234'`), `SignInWithLinkedIn`, `SignInWithSlack`,
`SignInWithBitbucket`, `SignInWithOutlook`, `SignInWithTwitter` and `Signinwithgithub` (`:111`).
`Customer`'s `'password' => 'hashed'` cast stores a pre-hashed value unchanged and hashes a plain
one once, so every such row holds bcrypt of `1234` — and the modules' own
`Auth::guard('customers')->attempt([... 'password' => 1234 ...])` depends on exactly that, so this
is their working flow rather than a misreading of it.

`CustomerLoginController::login` then authenticated with `Hash::check($posted, $customer->password)`
and no check of `social_type` at all. So **anyone who knew the email address of a shopper who had
ever used a web social button could sign in as them with `1234`**, from the open internet, on a
route carrying only `throttle:auth` (10/min per ip+email — no obstacle to one known password).

**Three things the record had wrong, each worth more than the row itself.**

1. **S18 said these modules "are harmless only because their routes are not registered, and a test
   pins that".** The test could not fail. `test_the_vendored_social_login_literals_remain_unrouted`
   scanned the router for routes belonging to those packages — but **no WorkDo module loads in any
   test run unless the test inserts an `add_on_managers` row**, and it inserted none, so the result
   was empty whatever production does. In production, once the addon row exists,
   `packages/workdo/GoogleLogin/src/routes/web.php:23-26` registers `{slug}/auth/google` and its
   callback under plain `web`. This is the exact blindness CLAUDE.md warns about, protecting the
   highest-severity row in this document.
2. **It enumerated four packages. There are eight.** The missed ones include `SignInWithFacebook`,
   pinned by nothing, and `Signinwithgithub` — whose directory is spelled with a lower-case
   `inwith`, so every hand-written `SignInWith*` glob misses it, including the first draft of the
   replacement test. The inventory is now **computed from the files** rather than typed out, which
   is how the eighth was found.
3. **P01-SEC-14's own S27 entry called the rows `users` (merchants).** They are
   `App\Models\Customer`. Corrected above.

**The fix, and why it is narrow.** `packages/workdo/` is vendored and under a modification guard, so
the control is host-side: `CustomerLoginController::login` refuses when a row has a `social_type`
**and** its password still matches the module literal. Nothing legitimate is refused —

- a social customer who has since set a real password through forgot-password keeps logging in,
  because their hash no longer matches;
- a shopper who simply chose `1234` keeps logging in, because they have no `social_type` (the
  validator allows four characters, so this is a real case);
- the social **button** keeps working, because the modules authenticate through
  `Auth::guard('customers')->attempt()` and never reach this controller.

The password is deliberately **not** scrubbed: that would break the modules' own `attempt()` call
and so disable the provider integration, which the brief forbids without owner approval.

`social_type` is added by a module migration, so the column can be absent; the predicate is guarded
on `Schema::hasColumn` and the test creates the column in the shape the module declares, asserts it
did, and then drives the real login endpoint — so it measures the control and not the environment.

### Owner items left by S29

1. **How many such rows exist?** `SELECT COUNT(*) FROM customers WHERE social_type IS NOT NULL;`
   and, of those, how many still hold bcrypt of the literal. Every one is an account that was
   open to anyone who knew its email address. **That is a disclosure question, not an engineering
   one.**
2. **Those rows cannot be scrubbed without breaking the modules.** The clean end state is a fork or
   an upstream fix that mints a random password; until then the host-side refusal is what stands
   between those accounts and anyone who knows the email address, and it must not be removed.
3. **`SELECT COUNT(*) FROM add_on_managers`** — whether these modules are active at all decides
   whether this was ever live. The refusal costs nothing either way.

---

## S30 — The negative-quantity family, closed on all four money paths — **two writers remain, in vendored code**

Three commits chased one defect outward, each because a review pass asked "where else?":

| commit | what it closed |
|---|---|
| **E** | `payment_sheet` (the card path) refuses a basket with a `qty <= 0` line; `addtocart` validates `integer|min:1` |
| **F** | `ordersave`, `place_order` and `confirm_order` refuse it too — a COD order had bypassed E entirely; `CartController::product_cartlist` (the storefront web writer) validates |
| **G** | `PaymentController::processOrder` — the storefront WEB checkout — refuses it. The fourth and last path that turns a cart into money |

**Why the web checkout's existing stock check did not already cover it.**
`assertCartAvailable()` at `PaymentController.php:315` does
`if (... (int) $qty <= 0) { continue; }` — it **skips** a non-positive line. That is correct for a
stock question (there is nothing to reserve) and wrong for a pricing one: the line is skipped for
stock and then priced anyway into `$order->product_price` and `$order->final_price`. A guard that
looks like the one you need is worse than none, which is the same lesson as S28 F2.

### Two writers remain, and they cannot be fixed in place

| file | route | middleware |
|---|---|---|
| `packages/workdo/BoostSales/.../BoostSalesController.php:59 → :183` | `POST {storeSlug}/boost-sales-cart` | plain `web` |
| `packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88 → :209` | `POST {storeSlug}/frequently_product_cart` | plain `web` |

Both are `$qty = $request->qty` written straight to `carts.qty` with no validation, and both are
vendored — `packages/workdo/` is under a modification guard, so an in-place fix is reverted by the
next upstream update and leaves no patch record.

**This is why the control is on the READ side.** All four paths that turn a cart into money now
refuse such a row whatever wrote it, so the money and the stock are closed. What a module route can
still do is **create** the row, which costs the shopper their own checkout until it is removed — a
self-inflicted denial, not a cross-tenant or money defect.

**Owner decision.** The clean end state is an upstream fix or a route middleware over those two
module routes. A middleware is a few lines in the host app and needs no module edit; it is not made
here because it changes the behaviour of module routes, and because the harm it would prevent is
already prevented at every point where it matters. A model-level guard on `Cart` was considered and
rejected: `Cart` is touched by 70 modules and a refusal there is not a narrow change.

**NOT a fixable-by-validation case, recorded so nobody tries:** `carts.qty` cannot simply be made
`UNSIGNED`. A migration on a live money-adjacent table is an owner operation, and the column's
existing negative rows — if any — would fail the alter. `SELECT COUNT(*) FROM carts WHERE qty <= 0;`
is the question to answer first.

---

## S31 — The payer sets `orders.final_price` on the gateway-return paths — **web checkout CLOSED by P01-SEC-14 H; the gateway half CLOSED by P01-COMMERCE-SEC-01, see S32**

The most serious thing any review pass found, and it was found by asking the scope question a fourth
time. **One path is closed and measured; six are open with an identical, one-line remedy that cannot
be verified in this environment.**

### What is closed — `PaymentController::processOrder` (P01-SEC-14 H)

MEASURED end to end through the real route, on a 100.00 basket:

| posted | `orders.product_price` | `orders.final_price` |
|---|---|---|
| nothing | 100.0 | 100.0 |
| `shipping_final_price = 500` | 100.0 | **600.0** |
| `shipping_final_price = -900` | 100.0 | **−800.0** |

`orders.final_price` is the amount **owed** — for a `cod` order it is the cash the driver collects.
It lands there from `$final_sub_total_price`, which is `$cartlist['total_sub_price']`
(`PaymentController.php:1997` and `:2025`), which `cart_list` builds by adding
`(int)$request['shipping_final_price']` with no floor. So the shopper set what they owed, to any
value including a negative one.

**This is worse than the defect Work Package B closed.** That was the card amount on an endpoint no
shipped client calls; this is the storefront web journey real shoppers use, and it is COD cash.
After the fix all three rows read 100.0.

### What is open — the same line, six gateway-return controllers

Each takes `$requests_data = $request->all()` and hands it to the cart reader, so the payer's
`shipping_final_price` flows into `total_sub_price` and from there into `orders.final_price` exactly
as above. **No race is needed: the shopper posts directly to these routes** (e.g.
`POST {storeSlug?}/order-khalti`, `routes/customer.php:185`).

| controller | computes its own `$delivery_price` |
|---|---|
| `AuthorizeNetPaymnetController.php` | yes |
| `CinetPayController.php` | yes |
| `EasebuzzController.php` | yes |
| `KhaltiPaymnetController.php` | yes (`:360-363` → `$order->delivery_price` at `:603`) |
| `PaiementProPaymentController.php` | yes |
| `PayUPaymentController.php` | yes |

### The design, prepared rather than applied — and why

**The edit is one line per controller**, immediately before the cart read, identical to the one in
`processOrder`:

```php
$request->merge(['shipping_final_price' => 0]);
```

It is safe in the same way: every one of the six computes its own `$delivery_price` from
`get_shipping_method()` and writes it to the order's own `delivery_price` column, so zeroing removes
only the caller's figure. `PaymentService.php:2277-2286` already logs that a client-supplied
`shipping_final_price` was discarded on its own path, so this is the project's established pattern
rather than a new policy.

**It is not applied here because it cannot be executed here.** These are gateway-RETURN handlers:
driving one end to end needs that gateway's credentials and a real callback, and this environment
has neither. Six untested edits to live payment-return paths on a trading business is the case the
brief reserves — *"if a secure fix requires changes to the order calculation architecture, prepare a
tested design and request approval before implementing the larger change."* The mechanism was read
at every site and the measurement above is from the one path that CAN be driven; what is missing is
execution on the six, not understanding of them.

**The test that would prove each one** is already written for the closed path and transfers directly:
seed `countries`/`states`/`cities`, post the handler's payload with and without
`shipping_final_price`, and assert `orders.final_price` is the basket's own total in both cases. It
needs a gateway stub or a staging credential per controller.

### And the stock half, same family

Eleven files carry a raw `$remain_stock = $ProductStock->stock - $qtyy;`, including the six above.
With a negative `$qtyy` that subtraction **ADDS** stock — the inventory corruption S30 closed on the
four entry paths is still reachable on a gateway return, because the return handler re-reads the
cart after the entry guard has already passed. The quantity guard that the four money paths now
carry would close it at each site; that is the same six-controller change and belongs with it.

**Owner decision, in one sentence:** ~~approve the one-line shipping fix plus the quantity guard
across those six controllers, in an environment where each gateway return can actually be driven.~~
**SUPERSEDED — see S32.** The premise was wrong: the six did not need a gateway to be fixed or
tested, because their root defect was the `$request->all()` fallback, not the shipping arithmetic.
All six are closed, plus a seventh S31 did not name. An eighth is open and needs a different fix.

---

## S32 — The gateway returns: what P01-COMMERCE-SEC-01 closed, and the five things it did not

S31 left six gateway controllers open on the argument that the shipping fix "cannot be verified in
this environment". **That argument was wrong, and this section records why.** The six did not need a
gateway. Their root defect was never the shipping arithmetic — it was that each handler fell back to
`$request->all()` when the session held nothing, so a caller who simply **omitted the session
cookie** supplied the cart, the prices, the shipping, the coupon, the store the order is written to
and the customer it is written for. That is testable with no credentials at all.

A **seventh** handler had the same defect and S31 did not name it. An **eighth** has it and cannot
be fixed the same way.

### The trust boundary, gateway by gateway

Every route below is declared in `routes/customer.php` inside a group whose only middleware is
`[web, ActiveTheme, themelanguage]` — **no authentication, no authorization, no throttle**, and
three of the six are `Route::any`, so they answer GET without a CSRF token.

| handler | route | what it actually is | gateway verification | fixed |
|---|---|---|---|---|
| `KhaltiPaymnetController::getProductStatus` | `POST {slug}/order-khalti` | browser return | **yes** — `$khalti->verifyPayment()` at :597, order write nested inside `status_code == 200` | state, quantity, status |
| `AuthorizeNetPaymnetController::getOrderPaymentStatus` | `ANY {slug}/authorizenet-status` | **the charge itself** — card number, expiry and CVV arrive in this request and the handler calls `authCaptureTransaction` | n/a; gated by its own outbound capture | state, **capture amount**, quantity, status |
| `CinetPayController::getProductStatus` | `POST {slug}/get-payment-cinetPay` | browser return | **no** | state, quantity, status |
| `EasebuzzController::getProductStatus` | `POST {slug}/get-payment-easebuzz` | browser return | **no** | state, quantity, status |
| `PaiementProPaymentController::getProductStatus` | `POST {slug}/get-payment-paiementpro` | browser return | **no** | state, quantity, status |
| `PaymentController::getProductStatus` | `ANY {slug}/get-payment-status` | browser return — the **generic** one, and PayU's real writer | per-provider, three of ~50 | state, quantity |
| `PayUPaymentController::storeGetPayUStatus` | `ANY /store-payu-payment/status` | redirect only | — | writes no order |
| `PaymentController::whatsapp` | `POST {slug}/whatsapp`, `{slug}/telegram` | **both** a return and an initiation | none | **NOT FIXED — see below** |

Two corrections to earlier records in this file and in the commit log:

- **PayU is not a seventh controller; `PaymentController::getProductStatus` is.**
  `storeGetPayUStatus` writes no order — it redirects to `route('store.payment.status')`. That is
  `PaymentController::getProductStatus`, which carried the same `$request->all()` fallback **65
  lines below** its session read, past three per-provider status blocks. Commit `8a981bb0`'s message
  asserted that handler "reads the session only"; reading only its opening lines is how that was
  missed. It is fixed in `2c7489ff`.
- **`PayUPaymentController::getProductStatus` carries the fallback shape and is dead code.** No
  route declares it, so it is not fixed and not deleted — it gets **no** state guard and **no**
  quantity guard. It did receive the `payment_status` derivation in `950a9830`, because that sweep
  matched the literal `'Paid'` across all of them; on dead code that is a no-op either way. If the
  method is ever routed it must be guarded first.

### What a browser can no longer decide

`app/Http/Controllers/Concerns/GuardsGatewayReturn.php` is the shared piece, mirroring the existing
sibling `GuardsPlanActivation`. It adds no new mechanism for money: four services already owned
these questions and **not one of the six was wired to any of them.** The package is wiring, not
invention.

| control | what it replaced |
|---|---|
| `storedCheckoutState()` | the `$request->all()` fallback in all seven. Reading only the stored state is sufficient rather than merely narrower: that state is the request as it stood **after** `processOrder` priced the cart, computed shipping server-side and refused a non-positive quantity, so each handler now inherits all of it |
| `adoptStoredCheckoutState()` | the `$request->merge($requests_data)` that sat on the **authenticated arm only**, and pins `customer_id` and `method_id`, which `merge()` cannot reach when the state omits them |
| `serverPayableAmount()` | AuthorizeNet's `$amount = (int) json_decode($input['data'])['get_amount']` — the figure charged to the card, chosen by the browser, and `(int)` charged **10** for a 10.99 basket |
| `basketHasNonPositiveQuantity()` | nothing. Inserted before each stock loop; `processOrder`'s own check does not cover these entry points, which the gateway redirect reaches directly |
| `gatewayReturnPaymentStatus()` | the literal `$order->payment_status = 'Paid'` in all six, now routed through the existing `PaymentVerificationService` |

### Three defects the state guard did NOT close, found by testing it

The stored-state guard decides *which array* a handler reads. It does not stop a handler reading
`$request->x` instead — and the attacker here is a **real shopper with real stored state** who adds
one field to the return they were already making. All three were measured on the live code.

1. **A guest return could be claimed for another customer.** `$request->merge($requests_data)` sits
   inside the `elseif (! empty($customer_id))` arm, so it never ran for a guest. Posting
   `customer_id=<victim>` wrote an order **owned by the victim** on CinetPay and Easebuzz and
   emptied the victim's basket on CinetPay, Easebuzz and Khalti. Closed in `0eee8524`.
2. **The browser chose the shipping price, from any tenant's methods.** A state with no `method_id`
   left the posted one standing; `ShippingMethod::find()` has no store predicate and the model is
   **not in `config/tenancy.php`'s `scoped_models`**. A store-B `Local pickup` method at cost
   `'-500'` put `-500.00SYP` into store A's `orders.delivery_price` — another tenant's figure,
   negative, non-numeric, in a money column. The **payable was unaffected**: `$order->final_price`
   never reads `$delivery_price`. Closed in `7484b290` for the five gateway controllers.
   **And then it was still live on the seventh**, which is the one that matters most — see below.
3. **A bare merge does not pin, and the seventh handler had a bare merge.**
   `PaymentController::getProductStatus` already carried its own
   `if (is_array($requests_data)) { $request->merge($requests_data); }`, so when the five were
   switched to `adoptStoredCheckoutState()` it was left alone as "already merging". That was wrong:
   `merge()` cannot overwrite a key the state omits, which is the entire reason the pins exist. The
   shipping defect above therefore stayed **live** on the generic storefront return — PayU's real
   order writer — after it was closed in the five. Measured: `delivery_price` **−500.0** on
   `{slug}/get-payment-status` while the five already read 0.00. Closed in `57c8953c`.

   Finding it needed two things that had been hiding it. First, a tabulation of which handler calls
   which trait member, rather than an assumption that "it merges" meant "it is covered". Second,
   `countries`/`states`/`cities` rows in the fixture: that handler **inserts** a `cities` row from
   `billing_info`, so without the FK parents it raised a FOREIGN KEY violation and 500d **before**
   the order write — the defect was sitting behind a crash, which is exactly the condition the
   project's "a test must bite" rule exists to catch.
4. **`Product` is still unscoped** and `ShippingMethod` joins it. Both are reachable cross-tenant by
   id from these routes; the fixes above remove the *caller's* ability to supply those ids, which is
   containment, not a scope.

### The five things this package did not close

1. **No gateway return proves a payment.** CinetPay, Easebuzz, PaiementPro and PayU ask nobody
   whether money moved; the order is written because the browser arrived at a URL. `'Paid'` is now
   *derived* through `PaymentVerificationService` instead of asserted, so every unverified write is
   logged and enforcement is a one-line change in that service — but the posture is unchanged, and
   deliberately so: that service's own docblock records that recording gateway orders as `Pending`
   would stop merchant wallets being credited for **51 live payment methods**. That is a business
   decision, not an engineering one. **Owner decision.**
2. **There is no idempotency key.** Replay is currently blocked only because the stored state is
   single-use (`Session::forget()` after reading). A genuine duplicate server-to-server notification
   — which none of these handlers accepts today — would need a real key.
3. **AuthorizeNet's fix is not verified by execution.** An empty `catch (\Exception $e) {}` at
   `:335-336` swallows the SDK setup failure and the next statement raises `Undefined variable
   $requestNet` at `:338`, so the handler 500s before reaching its stock loop. The state guard and
   capture-amount fix are verified; the **quantity guard is placed correctly and never ran.** The
   empty catch is itself a live defect: any SDK throw becomes a 500 for a real shopper mid-charge.
4. **`PaymentController::whatsapp` (`:3098`) keeps its `$request->all()` fallback at `:3103`, and
   must.** The route has two kinds of caller: `resources/views/payment/whatsapp.blade.php:42`, a
   genuine return with stored state, **and**
   `resources/views/front_end/sections/payment_list.blade.php:21` plus
   `front_end/pages/checkout.blade.php:191`, which post to it **directly from the checkout page
   without passing through `processOrder` at all**. For those there is no server-stored state to
   read, so the handler is an unpriced order-placement endpoint rather than a return. Requiring
   stored state would break a live payment method. **The fix it needs is server-side pricing on
   entry — a different change, and the next package.**
5. **The cart-wide delete has no store predicate** —
   `Cart::where('customer_id', $request->customer_id)->delete()`, unlike the per-line delete four
   lines above it. A predicate was added and **reverted**: it could not be shown to change any
   outcome, because on both principals that reach the line a tenant resolves and `Cart`'s
   `StoreScope` already supplies one, measured both ways. It stays a residual because
   `null_tenant => 'open'`: a principal resolving **no** tenant would get a predicate from neither
   source.

### One pre-existing crash this package had to work around

All six raise `Undefined array key "out_of_stock_threshold"` and `"notification"` on a store whose
settings table lacks them — the same defect P01-SEC-14 C3 fixed in `ordersave`, from the same raw
`Setting::...->pluck()` with no defaults. It **500s every legitimate return** in such a store. The
test fixture seeds both so the tests measure the trust boundary rather than the crash; the crash is
not fixed here and belongs with the whatsapp pricing work.

### Evidence

`tests/Feature/Commerce/GatewayReturnTrustBoundaryTest.php` — **38 tests, 124 assertions, all
passing.** Reaching the order writer at all needed the guest cart cookie: the guest arm reads
`Cart::where('cookie_session_id', Cookie::get('cart'))` (`app/Models/Cart.php:198`), not the
authenticated customer, so a seeded `cookie_session_id` row plus the encrypted `cart` cookie drives
a genuine checkout to the write. That is what made both the defects and the positive control
observable.

Every guard was removed and re-measured:

| guard removed | result |
|---|---|
| `storedCheckoutState` → the old fallback | **20 of 20 fail** |
| the same, in `PaymentController` only | **5 of 5 fail** |
| `adoptStoredCheckoutState`'s body | **3 fail** (khalti, cinetpay, easebuzz) |
| the `method_id` pin alone | **1 fails**, printing `'-500.00SYP'` |
| the seventh handler's pin, back to its bare merge | **1 of 2 fails**, printing `-500.0` — PaiementPro still passes, which is what isolates the pin |
| the store predicate on the cart delete | **nothing fails** — which is why it was reverted |

Two tests are labelled in their own docblocks as **not** evidence for this package, because a
removal check proved they are not:

- `test_posted_money_cannot_change_the_order_total` (the brief's manipulated-positive and
  negative-shipping cases) passes with **both** merges stripped from PaiementPro. The guest arm
  never consults `$request` for money at all. It is a regression fence.
- the cross-store assertion in the identity test survives because the identity pin stops the delete
  running, not because the delete is scoped.

The positive control is what stops the rest being vacuous:
`test_a_legitimate_guest_return_still_writes_its_order` drives a genuine guest checkout through
PaiementPro and asserts an order **is** written — `customer_id` 0, `is_guest` 1, `final_price`
100.00 from the server, one unit of stock consumed. Without it, every refusal above could be
satisfied by a handler that refuses everything.

**What none of this proves:** that any gateway verified a payment. None of them does. A test
claiming otherwise would be exactly the "simulated validation mistaken for real gateway
verification" the brief warns against.

### What the adversarial review of this package found — and the one it found that mattered most

The committed work was reviewed against the six categories the package brief names. Every claim
below was driven through the real routes before being accepted; two of the reviewer's claims were
corrections to **my own records**, which are fixed in place.

**The review's most important finding, and the one every test in this package was structurally
unable to see.** All of them attack the **return**. The attack is one leg earlier.

```
POST {slug}/process-order  with a method_id and nothing else
  -> processShipping()                      PaymentController.php:1689  (gated only on !empty($request->method_id))
  -> CartController::get_shipping_method()
  -> ShippingMethod::find($request->method_id)            <-- no store predicate, and
                                                              ShippingMethod is not in scoped_models
  -> for 'Local pickup' the merchant's `cost` column is used VERBATIM
  -> prepareFinalRequestData() writes it as shipping_final_price
     AND FOLDS IT INTO cartlist_final_price               PaymentController.php:1721
  -> the gateway return reads the state, exactly as it should, and writes the order
```

MEASURED, both legs, through the real routes:

| leg | input | result |
|---|---|---|
| 1 — `process-order` | **only** `method_id=<store B's 'Local pickup', cost '-50'>` | state `shipping_final_price` `'-50'`, `cartlist_final_price` **50.0** |
| 2 — the return | **no money fields at all** | `orders.product_price` 100.0, `orders.final_price` **50.0** |

A guest pays 50 for 100 of goods. For AuthorizeNet that same `cartlist_final_price` is what
`serverPayableAmount()` hands to the card capture, so it is the amount **charged**. `cost` is a
merchant-controlled string column written with no validation, so a negative one needs no exotic
access — and the method did not have to belong to the attacker's store.

**Why this package's guards could not see it: the stored state really was the server's.** Reading
only the stored state is still correct. The server had been talked into computing a poisoned figure
one leg earlier. `test_posted_money_cannot_change_the_order_total` could never have caught it — it
posts money **on the return**, the leg that was already safe. That is what the fence was fencing
against, found from the other side.

Closed in `0ad3f7e0`, in **one place**: `CartController::get_shipping_method`, the single chokepoint
that `processShipping()` (initiation), the five gateway returns and
`PaymentController::getProductStatus` all share.

1. The lookup is **store-scoped**. A `NULL` `store_id` is still accepted — the column is nullable,
   legacy rows may not carry it, and refusing those would hide a merchant's own shipping method,
   which is a broken storefront rather than a fix. What is refused is a method belonging to a
   **different** store.
2. The computed charge is **floored at zero**. A negative charge is not free shipping: it is
   subtracted from the payable.

Each guard is independently necessary, and the data provider isolates both — removing the floor
fails `own negative`, removing the predicate fails `foreign positive`.

### Two corrections to my own records that the review forced

- **AuthorizeNet's line numbers were stale.** The empty `catch (\Exception $e) {}` is at
  **`:335-336`** and the `Undefined variable $requestNet` use at **`:338`** — not `:298-299`/`:332`.
  They shifted when `81db1700` inserted the capture-amount comment. The stale numbers were in this
  section and in two test docblocks; all are fixed.
- **`test_replaying_a_return_cannot_multiply_orders` did not test replay.** It never called
  `withSession()`, so no stored state was present on any of its three requests — every one was the
  forged-return case again, and deleting every `Session::forget()` from all six handlers would not
  have failed it. Rewritten to drive a **genuine** first return and replay that. It now fails on
  **all six** when `Session::forget()` is removed.

### Findings accepted, verified, and deliberately NOT fixed

1. **A guest can still claim a victim's order — through the e-mail, at initiation.**
   `handleCustomerRegistration` does `Customer::where('email', $billing['email'])->first()`
   (`PaymentController.php:1310`) — find-or-create by a **posted e-mail**, with no proof of control
   — and that id becomes the state's `customer_id`, which `0eee8524`'s pin then faithfully
   restores. MEASURED: a guest whose only input was the victim's e-mail in the ordinary checkout
   form got `orders.customer_id = <victim>` and the victim's saved basket **deleted**.
   **This makes `0eee8524`'s test claim narrower than its name**, and the docblock now says so: it
   proves a return cannot be claimed *by posting the id on the return*.
   **Not fixed, because the fix is a product decision, not a guard.** The platform deliberately
   links a guest order to a matching account. Writing `customer_id = 0` for guests and keeping the
   e-mail only in `order_billing_details` would close it and would also detach every *legitimate*
   guest order from its account. **Owner decision.**
2. **Khalti's verification is amount-unbound**, which qualifies the one row in the table above that
   reads "verifies". `$amount = $payload['amount']` (`:635`) is the **browser's** figure;
   `verifyPayment($secret, $token, $amount)` (`:637`) is gated on HTTP status alone (`:638`), and
   the order's own total is never compared to it. A token for a 1-unit payment satisfies the gate
   for a 100-unit order. **Not fixed:** the comparison needs Khalti's minor-unit convention, and
   enforcing a factor I cannot calibrate would either break every live Khalti order or emit a false
   mismatch on each one. Per the brief, a gateway that cannot be secured without credential-based
   testing gets a **blocker**, not a guess.
3. **A ninth handler of the same family has no guards at all.**
   `NMIPayController::getNMIProductStatus`, `Route::any {slug}/get-payment-NMI`
   (`routes/customer.php:355`), same middleware group. It is the *other* card-charge handler and it
   carries every shape this package set out to remove: `Session::get('request_data')` with a caller
   fallback (`:164-168`), the charge amount from the state with no recomputation (`:183`), card
   number and CVV from the request (`:184-186`), raw read-modify-write stock decrements with **no
   quantity guard**, and a literal `'Paid'` (`:605`). Its stock loop sits inside
   `if ($parsedResponse['response'] == 1)`, so it needs live NMI credentials — **latent in this
   environment, live for such a store.** The caller fallback itself is probably inert: `json_encode()`
   makes `$requests_data` a *string*, so the later `['slug']` reads break rather than obey. **Not
   fixed:** shipping guards I cannot execute even once to a live card-charge path is the thing the
   brief forbids. It is the clearest next package.
4. **Every legitimate CinetPay return 500s after committing the order.**
   `CinetPayController.php:1008` does `event(new CinetPayPayment(...))` and **`CinetPayPayment`
   exists nowhere in the repository** — one grep hit, the `new` itself — so it resolves to
   `App\Http\Controllers\CinetPayPayment` and raises an uncaught `Error`. Already committed by then:
   the order row, the stock decrements and the cart deletes. Never reached: the cookie-cart clear,
   the reward event and the `order.complete` redirect. My own probe recorded exactly this —
   `http=500` with `orders=1`. Pre-existing, same "every legitimate return fails" character as the
   `out_of_stock_threshold` crash, and it belongs with it.
5. **CinetPay omits the coupon from its order total.** `:743` and `:762` write
   `$final_price + $tax_price` where Khalti (`:656`), Easebuzz (`:687`), PaiementPro (`:688`) and
   AuthorizeNet (`:749`) all write `... - $coupon_price`. Same basket, same coupon, two different
   `orders.final_price`, with `coupon_price` recorded on the row either way. Against the shopper.
   **Not fixed:** it is a live money formula and the coupon path is not drivable here — S24 records
   that `apply_coupon` refuses every coupon that is not `fixed product discount`, so a test could
   not bite. It needs S24 first.
6. **`orders.delivery_price` receives a formatted currency string on the five.**
   `CartController:547`/`:582` overwrite `shipping_final_price` with
   `currency_format_with_sym(...)`. The default symbol position is `'pre'`
   (`app/Helper/helper.php:1151`), so production MySQL gets `"$500.00"` into a DECIMAL column and
   stores `0.00`. `PaymentController:2138` is the only one reading the numeric `total_final_price`.
   The new shipping test only gets a castable value because it sets
   `site_currency_symbol_position => post`. Correctness; no attacker benefit beyond what is already
   fixed above.
7. **Per-provider vacuity inside otherwise-biting tests**, now stated in the docblocks rather than
   left implied: the negative-quantity test's two stock assertions cannot move for
   `storefront-return` (that handler decrements through `ProductStockService::normaliseQuantity`,
   which returns null for `qty <= 0`, so stock cannot move there with or without the guard — its
   message assertion carries the weight); the identity test's orders assertion cannot fire for
   `khalti` (it never reaches `new Order()` here, so its in-store cart assertion carries the
   weight); and part B of the A/B test is satisfied by a 500 on authorizenet and cinetpay, so it
   bites only against the blanket-refusal regression it names. **Only the PaiementPro positive
   control proves a legitimate return completes.**

### Claims the review confirmed

AuthorizeNet-is-the-charge; PayU's storefront route writing no order; `PayUPaymentController::getProductStatus`
being unrouted; the quantity guard preceding **every** stock write in all seven, with no second
loop, no early decrement and no give-back path; the guard's logic having no hole for non-numeric,
fractional, `'-0'`, array or boolean quantities; `cart_list` resolving its principal through
`resolveStoreCustomer`; `apply_coupon` being store-scoped; `TaxMethod` carrying `store_id` in all
six; the per-line cart delete carrying `store_id`; and the refusal log recording key **names** only,
never values.

One qualification worth keeping: "Khalti is the only one of the six that verifies server-side" holds
only among the five **pure returns**. AuthorizeNet's whole order write is nested inside the SDK's own
`getResultCode() == "Ok"`, and `PaymentController::getProductStatus` verifies PhonePe (`:1910`) and
Tap (`:1942`) and reads toyyibpay's status (`:1928`). The table above states this correctly.

### Next, in priority order

The census this package produced is now nine order writers, not six, and two of them are
unguarded. Both are the same shape and neither can be closed by the guards here.

1. **`NMIPayController::getNMIProductStatus`** — a live `Route::any` with no auth, the *other* card
   charge, and not one of this package's guards. Latent only because its stock loop needs live NMI
   credentials. **It needs a store with credentials, or a stub, before anything is shipped to it** —
   that is the precondition, and it is why this package stopped at reporting it.
2. **`PaymentController::whatsapp`** — server-side pricing on the WhatsApp/Telegram
   order-placement entry, which takes its whole checkout from `$request->all()` because two
   checkout views post to it without passing through `processOrder`. Carries the
   `out_of_stock_threshold`/`notification` crash, and the CinetPay `event(new CinetPayPayment(...))`
   fatal belongs in the same sweep.
3. **The order-total formulas** — CinetPay's missing coupon subtraction, the reward branch
   subtracting the reward *instead of* the coupon on all six, and `orders.delivery_price` taking a
   formatted currency string. Gated on **S24**: the coupon path is not drivable until
   `apply_coupon` stops refusing every non-`fixed product discount` coupon, so a test cannot bite
   before then.

Owner decisions, gating nothing else on this list: guest-order attribution by posted e-mail, Khalti
amount binding, and the unverified-`'Paid'` posture.

---

## Cross-repository row, carried over

**B8 — a merchant can read their own webhook secret and forge events for their own store.**
`app/views/api/v1/accounts/webhooks/_webhook.json.jbuilder:6` (Lynomia side) renders
`json.secret`, and a Store Syria merchant tenant owner is provisioned as a Lynomia
*administrator*, so they can sign a payload that `POST /lynomia/events/{their_store}` accepts as
genuine. `ConsentStateProjector` writes whatever `state` arrives, subject only to the revision
watermark — so a forged `consent_changed` with `state: granted` records a grant the shopper never
gave. **Not** a cross-tenant breach: each store's secret is independently derived.

It bears on the **lawfulness of the consent record**, which is why it is in a security queue rather
than a messaging backlog. Two options are already written up on the Lynomia side; neither is an
engineering call.

---

## Suggested package boundaries

Keeping these apart is the point; each is independently reviewable and independently revertable.

| Package | Rows | Gating |
|---|---|---|
| **P01-SEC-1** (urgent, isolated) | S1 containment | Owner must first answer whether the module is active |
| **P01-SEC-2** | S3 + its regression test | ~~Independent; host-side only~~ **DONE** |
| **P01-SEC-7** | S11 | Needs the module-loading problem solved first, and the owner's `add_on_managers` answer |
| **P01-SEC-8** | S13 | **Urgent and isolated.** Owner should answer before any deployment |
| **P01-SEC-9** | S12 (a) + (b) | Changes API behaviour, so it needs a Flutter window |
| *(owner decision, not a package)* | S12 (c) | Same decision as the storefront document button |
| *(triage, not a package)* | S14 | Verify each row before assigning severity |
| **P01-SEC-10** | **S15-A** | **EMERGENCY.** Anonymous platform takeover. Answer before any deployment; route can be disabled as an interim with no app release |
| **P01-SEC-11** | S15-B rows 1-7 + **S19** | ~~One API-authorization pass, one shared ownership helper, one Flutter window~~ **DONE.** 26 handlers, nine commits, one shared helper (`resolveStoreCustomer`). No new 401 on any path a shipped client calls; see S19 for the five rows left open |
| **P01-SEC-14** | **S20** + **S22** + **S23** + **S25**, and the amount half of **S21** | ~~Identity and payment trust boundaries~~ **DONE.** Six commits (A, B, C, D, E, F). Provider tokens verified server-side; the amount OWED — on the card path and on COD alike — proof against a posted price, currency, coupon code, shipping price or negative quantity; `notify_user` failed closed; four coupon lookups store-scoped; the order's discount and ledger columns taken from the server. E and F are the two adversarial reviews' own findings — see S27 and S28; F closed a HIGH that bypassed E's guard through COD, and G closed S29, the `1234` password on eight vendored social-login modules that S18 had recorded as harmless. No new 401 on any path a shipped client calls. H closed S31's web half — a payer setting `orders.final_price`, the COD cash amount — and S31 carries the six gateway controllers with the same defect as a prepared design awaiting approval |
| *(owner decision, not a package)* | **S21** calculation rows, **S24**, **S26** | Each changes money or deletes history. Answer before the next commerce package |
| **P01-COMMERCE-SEC-01** | **S32**, and the gateway half of **S31** + **S30** | ~~Payment-return integrity and server-authoritative totals~~ **DONE.** Five commits. The `$request->all()` fallback is gone from all six gateway returns and from a seventh S31 did not name (`PaymentController::getProductStatus`, PayU's real writer); the AuthorizeNet capture amount is the server's; a non-positive quantity is refused before any stock row moves; `'Paid'` is derived rather than asserted. Testing the guard then found three more: a guest return claimable for another customer, the browser choosing the shipping price from any tenant's methods, and an unproven cart-delete predicate that was reverted rather than shipped. 38 tests, 124 assertions; every guard removed and re-measured. **Five items left open, four of them owner decisions — see S32** |
| **P01-COMMERCE-SEC-02** *(next, recommended)* | **S32's two unguarded writers** | `NMIPayController::getNMIProductStatus` (a live `Route::any`, the other card charge, no guards at all — needs NMI credentials or a stub first) and `PaymentController::whatsapp` (server-side pricing on the WhatsApp/Telegram entry, which takes its whole checkout from `$request->all()` because two checkout views bypass `processOrder`). Sweep the `out_of_stock_threshold`/`notification` crash and the CinetPay `CinetPayPayment` fatal with them. Host-side only; no contract change |
| *(owner decision, not a package)* | **S32 items 1–2** | Guest-order attribution by posted e-mail, and Khalti amount binding. Each changes behaviour a real shopper depends on, or needs credentials this environment does not have |
| **P01-SEC-12** | S15-B row 8 | `config/superadminaccess.php` denylist vs the finance permissions. No API impact |
| **P01-SEC-3** | S4 + the `larabug` blacklist review | Independent; no contract impact |
| **P01-SEC-4** | S5 session half (logout, id regeneration, `same_site`) | Independent. Token expiry/abilities wait for a Flutter window |
| **P01-F** | S6 | Already a named Phase 01 package |
| **P01-SEC-5** | S7 | Owner decision on the swallow |
| **P01-SEC-6** | S9 + S10 | Cheap; can ride alongside any of the above |
| *(precondition, not a package)* | S8 | Must be settled before P01-B ships |

Rotation (S2) is an owner operation, not an engineering package, and gates nothing on this list
except the *severity* of S3.
