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

> **Closed in application code; four vendored packages remain, held harmless only by not being
> routed.**

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

**Not fixed, deliberately:** four WorkDo social-login packages (`SignInWithBitbucket`,
`SignInWithOutlook`, `SignInWithTwitter`, `GoogleLogin`) carry the same literal and
`SignInWithOutlook:174` even *authenticates* with it. `packages/workdo` is vendored and under a
modification guard. They are harmless only because their routes are not registered, and a test
pins that: if one is ever routed, the route must be refused rather than the package patched.

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
| **P01-SEC-12** | S15-B row 8 | `config/superadminaccess.php` denylist vs the finance permissions. No API impact |
| **P01-SEC-3** | S4 + the `larabug` blacklist review | Independent; no contract impact |
| **P01-SEC-4** | S5 session half (logout, id regeneration, `same_site`) | Independent. Token expiry/abilities wait for a Flutter window |
| **P01-F** | S6 | Already a named Phase 01 package |
| **P01-SEC-5** | S7 | Owner decision on the swallow |
| **P01-SEC-6** | S9 + S10 | Cheap; can ride alongside any of the above |
| *(precondition, not a package)* | S8 | Must be settled before P01-B ships |

Rotation (S2) is an owner operation, not an engineering package, and gates nothing on this list
except the *severity* of S3.
