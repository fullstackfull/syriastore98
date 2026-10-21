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

## S14 — Order-adjacent surfaces found alongside S12/S13 — TRIAGED (P1-SEC-03, 2026-10-20)

Originally recorded unverified: *"severity is **not** assigned because none has been through the same
verification as S12 and S13."* **All six rows have now been driven or refuted, in two workflow rounds.**
The verdict, the verified citation and the follow-up for each are below. The original wording of every row
is kept, because three of the six carried a claim the evidence did not support.

**There are SIX rows, not four.** The task ledger said "four unverified surfaces". The four were bullets
1–4, the access findings; bullets 5 and 6 had already been characterised as a correctness defect and a
false denial. The task's criterion says *each row*, so all six were in scope, and that mismatch is how two
rows went unexamined in round 1. (Not a silent skip, as a critic inferred: both triage agents finished
and then returned JSON that failed to parse, so the harness discarded it. Round 2 lost three more the same
way, and for those only the first 2,048 bytes of each payload survive.)

**Citations are verified at head `5f5f96b6`.** Line numbers drift — three of the original four had — so
the method name is the stable reference and the line is a courtesy.

- `GET /order/export` (S14 cited `OrderController:1230`; the handler is `OrderController::fileExport()`)
  — dumped every order in the store to any authenticated caller, with no order permission.
  **ALREADY CLOSED** by `0a23723b` (2026-10-18, *"S14: gate the order export, and stop the guard refusing a doubled principal"* — the same commit that closed the last row below). Gated by an operator-first `Manage Order` check; driven: a permissionless staff
  member is refused (302), the same user after the grant gets the file (200), the platform operator gets
  it (200). The "every order in the store" wording was already store-scoped (`OrderExport::collection()`
  filters `store_id`). One new **low** residual, tracked as **P1-SEC-11 (#78)**: `getCurrentStore()`
  hardcodes `1` for the operator, so the companion empty-store guard is unreachable for them — a 500, and
  in production a wrong-store export. The six `*-order-export` routes at `routes/web.php:976-996` are
  `ReportController`, not `OrderController`; see **P1-SEC-09 (#76)**.
- `GET {storeSlug?}/order-complete?data[order_id]=N` (S14 cited `OrderController:563`; `orderComplete()`)
  — discloses another tenant's order number to an anonymous caller and mints its storefront document link.
  **REPRODUCED — HIGH, and worse than the row said.** With no session of any kind, a primary key typed into
  the URL returned the order number and a minted link to the FULL order (email, phone, address, line items,
  total); and it did not need to be another tenant — the victim's own public slug works too, and `N` is a
  sequential integer. Fixed by **P1-ORD-08 (#80)**. The residual forgeable details link is
  **P1-ORD-09 (#73), BLOCKED on an owner decision.**
- `POST {storeSlug?}/downloadable_prodcut` (S14 cited `AccountProfileController:845`; now `:920`) — mails
  digital products for an unscoped raw `order_id`. Reported but **not** reproduced.
  **REPRODUCED — MEDIUM, and the wording overstated it.** Nothing is exfiltrated: the digital file is never
  derived from the order. It is a cross-tenant **phishing** primitive — any signed-in shopper can make any
  store send a genuine-looking order email, over that merchant's own SMTP relay, to that merchant's own
  customer, with a link the attacker chose. Tracked as **P1-ORD-10 (#74), READY.**
- `DeliverySyriaOrderController:122` (`Api\DeliverySyriaOrderController::updateStatus`, lookup at
  `:121-125`) — answers differently for an order that exists before its credential check, i.e. a
  platform-wide order-existence oracle.
  **REPRODUCED — MEDIUM** (recovered from a truncated triage record; re-established as the first RED test of
  the fix). With a non-empty bearer: `404 Order not found` for an unknown `product_order_id`, `403 Invalid
  credentials` for a real one, in any tenant; unauthenticated and unthrottled. Bounded: it reveals
  existence only, no PII, money or write. The sibling `DeliverySyriaStatusController` has the same
  ordering but queries `zid_orders`, and its echoed `zid_order_id` is the caller's own input.
  Tracked as **P1-SEC-10 (#77), READY.**
- Every order document renders store settings fetched without reference to the order's store
  (S14 cited `app/Models/Utility.php:167`; the branch is `:139-157`, the fallback at `:153`), so a document
  can print the wrong store's address. A correctness defect, not an access one.
  **REPRODUCED — MEDIUM, and the classification is OVERTURNED** (recovered from a truncated triage record).
  A shipping label for merchant A's own store-A order printed store B's address at HTTP 200, twice, through
  the `created_by = 1` fallback, which carries no store predicate. A round-2 critic's escalation —
  that a shopper's own document could render a foreign merchant's identity in a real dual session — is
  **REFUTED by measurement**: that session gets 403, and the shopper-reachable storefront document never
  calls `Utility::Setting()` at all. Tracked, with the shared root `getCurrentStore()` and the strict
  `users.type` comparison at the document guard, as **P1-SEC-11 (#78), READY.**
- `OrderDocumentAccessGuard` denies a shopper their own document when the same browser also holds a live
  `web` session, because the merchant limb is evaluated first and returns on its own result.
  A false denial, not a leak; it needs a decision about which principal should win.
  **ALREADY CLOSED** — fixed 2026-10-18 by the same `0a23723b`, which made the merchant limb fall through to
  `shopperOwns()` on all three of its failure paths. Driven: a doubled principal is admitted to its own document, and still refused an order
  neither identity owns.

**Surfaces the triage found that were not in the original six:**

- `AccountProfileController::order_refund_request` — **REPRODUCED — HIGH.** Any registered shopper could
  rewrite another tenant's order totals; a negative quantity inflated them. Fixed by **P1-ORD-07 (#79)**.
- `HomeController::order_track` — **REPRODUCED — HIGH.** The posted email was compared to nothing.
  (Intra-store: the store predicate existed, so the "no store predicate" half of the claim is refuted.)
  Fixed by **P1-ORD-08 (#80)**.
- `ReportController` — **REPRODUCED — MEDIUM**, within-tenant vertical privilege: zero authorization checks
  across 29 methods and 3,136 lines (verified), a permissionless staff member gets the owner's report body.
  No cross-tenant exposure. **P1-SEC-09 (#76), READY.**
- `AccountProfileController::customerorder` — a round-2 critic called it an unguarded consumer of the
  forgeable ciphertext. **REFUTED by execution:** `OrderDocumentAccessGuard::TARGETS` already refuses it,
  proven by `OrderDocumentAccessTest`.
- `AccountProfileController::change_refund_cart` — same request-input shape, **unrouted** (no route, no
  view). Recorded under **P1-ORD-11 (#75)** so a future route declaration triggers a review.

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
**five of the six** are `Route::any`, so they answer GET without a CSRF token (only
`order-khalti` is POST-only) — and Easebuzz is CSRF-exempt even on POST, by the
`'*get-payment-easebuzz*'` pattern in `bootstrap/app.php`. I first recorded "three"; re-counted
from `routes/customer.php`.

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
4. **The quantity guard had a floor and no ceiling.** MEASURED: a cart line of qty `2147483647`
   on an otherwise **legitimate** PaiementPro return took a product seeded at 50 units to
   `product_stock` **−2147483597**, and wrote the order. The quantity is not negative, so the floor
   waved it through; the result is as negative as stock gets. It needs no payment and no account —
   `carts.qty` is written straight from the request by two vendored module routes under plain `web`,
   and three of these gateways verify nothing, so the stock moves whether or not money did. One
   anonymous request corrupts any merchant's inventory, with no order anyone intends to pay for.
   Closed by `MAX_SANE_LINE_QUANTITY`; the guard is renamed `basketHasUnusableQuantity()` because
   "non-positive" no longer describes it. The bound is a **sanity bound, not a business rule** —
   the business rule is the merchant's own `products.maximum_quantity`, which `CartQuantityControl`
   enforces at initiation only and never here.
5. **ANOTHER MERCHANT'S STOCK COULD BE DRAINED THROUGH THIS STORE'S CART — the deepest defect in
   this package, and the only one that needs no payment at all.** `Product` is **not** in
   `scoped_models`, so `Product::find($id)` adds no predicate for anybody, and
   `CartController::product_cartlist` (`POST {slug}/product_cart`, no auth) resolved its product
   that way and wrote whatever came back into **this** store's cart.

   MEASURED end to end through two real routes, with no account and no payment:

   | request | result |
   |---|---|
   | `POST /{storeA}/product_cart` with `product_id=<a store-B product>`, `qty 7` | HTTP 200, `"added successfully"`, `carts` row `product_id=<B's>`, `store_id=<A's>` |
   | `POST /{storeA}/get-payment-paiementpro` (an ordinary, legitimate return) | store B's `products.product_stock` **90 → 83** |

   Neither the quantity guard nor anything else in this package could see it: the quantity is a
   perfectly reasonable 7, and by the time the loop runs the row looks entirely ordinary. CinetPay,
   Easebuzz and PaiementPro verify no payment, so the stock moves whether or not money did.

   Same family as the **S19** cross-tenant stock drain, and the same lesson that section records: a
   `customer_id` sweep cannot find it, because the attacker-controlled identifier is the **product**
   id. Closed where the row is **written**, which is the only place one change covers every reader,
   and refusing only a **provable** mismatch so a product with a null `store_id` is left alone.
   `ShippingMethod` was the same shape and is closed in `0ad3f7e0`. **`Product` itself remains
   unscoped** — these are containment at the caller, not a scope.

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
| the `method_id` pin alone | **1 fails**, printing `'-500.00SYP'` — ***STALE, see below*** |
| the seventh handler's pin, back to its bare merge | **1 of 2 fails**, printing `-500.0` — ***STALE, see below*** |
| both pins, keeping the merge | **1 fails** — `test_the_identity_pin_covers_a_key_the_state_omits`, the only fixture that isolates them |
| `adoptStoredCheckoutState()` entirely | **3 fail** — the merge-on-the-guest-arm half |
| `Session::forget()` from all six | **6 fail** — the rewritten replay test |
| the shipping floor | **1 fails** — `own negative` |
| the shipping store predicate | **1 fails** — `foreign positive` |
| the quantity **ceiling** | **5 fail** — `test_an_absurd_quantity_is_refused_on_the_return` |
| the foreign-product refusal at add-to-cart | **1 fails**, printing the attack's own `"added successfully"` response |

**TWO OF THOSE REMOVAL CHECKS WENT STALE INSIDE THIS PACKAGE, and the second review caught it.**
The checks recorded for `7484b290` and `57c8953c` were valid when they were run. `0ad3f7e0` then
fixed the same defect one leg deeper, at the chokepoint — and that **subsumed the `method_id` pin**.
Re-measured: with `0ad3f7e0` in place, removing the `method_id` pin fails **nothing**, and replacing
the whole of `adoptStoredCheckoutState()` with a plain `$request->merge($state)` left all 42 tests in
the file **green**.

So the pins were never isolated by any fixture here, for a reason worth stating: every state in this
file is built by `forgedCheckoutState()`, which **sets** `customer_id` — and a plain merge is
sufficient whenever the state carries the key. The pins exist for the case `merge()` cannot reach, a
key the state **omits**, and nothing built that case.
`test_the_identity_pin_covers_a_key_the_state_omits` now does, by unsetting it, and it is the one
test that fails when the pins go.

The `method_id` pin has **no remaining attacker-beneficial case at all** after `0ad3f7e0`: a foreign
method is refused by the store predicate, a negative cost by the floor, and this store's own
positive-cost method only makes the shopper pay more. It is kept as defence in depth at zero cost and
is recorded as **unproven**, not claimed as a guard. This is the project's own "a test must bite"
rule turned on its author: a later fix can stop an earlier test from biting, and nothing re-checks
it unless someone looks.
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

8. **The five gateway controllers never got the atomic decrement or the stock give-back**, which is
   the brief's "stock mutation failure and retry behaviour" case and the honest answer to it.
   Counted: each of the five carries **three** raw read-modify-write decrements
   — a variant one, a `$datas->product_stock` one and a `$Product->product_stock` one, at
   e.g. Khalti `:437`, `:465`, `:530` (**I first counted two**; the middle one reads `$datas`, not
   `$Product`, so the pattern I grepped missed it, and the wrong count reached a commit message.
   The *placement* claim is unaffected: all three sit after the quantity guard and before
   `new Order()`) — and **zero** uses of
   `App\Services\ProductStockService`, against 12 uses in `PaymentController`. So two things
   P01-SEC-14 fixed on the `processOrder`/`ordersave` paths are absent here:

   - **Atomicity.** Two concurrent checkouts both write `stock - qty` and the second overwrites the
     first, so the shop oversells by the quantity it processed concurrently. Live today.
     `tests/Feature/Commerce/OrderStockConcurrencyTest` proves the *atomic* path holds under ten
     concurrent checkouts; nothing covers these five, because they do not use it.
   - **Give-back.** `$appliedStockChanges` appears **zero** times in all five. On a mid-loop
     failure — `return $this->error(['message' => __('Product not found .')])` — the lines already
     decremented stay decremented with no order written.

   **I could not demonstrate the give-back loss by execution**, and say so rather than assert it:
   the obvious construction (delete a product row so its line fails inside the loop) is caught
   *before* the loop, and the probe measured `firstLineStock` unchanged at its seeded 50. The
   *absence* of the machinery is a grep result, not a measured loss. **Not fixed:** restructuring
   the stock loop in five live payment handlers is a change of a different size from this package,
   and the brief's own ban on widening applies. The negative half — the part that *mints* stock — is
   closed by the quantity guard, which is what the brief asked for.

### A claim two reviewers rated critical, and the measurement that refuted it

`final_sub_total` **is** genuinely absent from the merges the handlers build before calling
`apply_coupon` — they pass `coupon_code`, `sub_total` and `slug` only
(`PaiementProPaymentController.php:376-382` and the same shape in the other five) — and
`apply_coupon` reads `$request->final_sub_total` in **three** places: the spend gate, the base the
discount is subtracted from, and the percentage base (`ApiController.php:3763-3776`, `:3820-3826`).
So a posted value does survive the state merge. Two independent reviewers rated it **critical**, one
of them claiming `orders.final_price` could be driven negative.

**It is inert.** Driven through the real route with a valid, active, in-store 10% `percentage`
coupon and `coupon_info` populated as a real checkout stores it:

| posted `final_sub_total` | `orders.coupon_price` | `orders.final_price` | `orders.product_price` |
|---|---|---|---|
| nothing | 0.0 | 100.0 | 100.0 |
| 1000000 | 0.0 | 100.0 | 100.0 |
| −1000000 | 0.0 | 100.0 | 100.0 |

Identical. The discount is **zero** even with no manipulation at all, which is **S24**: `apply_coupon`
refuses every coupon that is not `fixed product discount`, so the percentage and flat branches —
`final_sub_total`'s only consumers — are dead code today. The queue already records
`final_sub_total` as a precondition of the S24 fix; what is new is that these **five gateway
handlers are five more call sites that must move in that same change**, or fixing S24 opens them.

A third reviewer reached this conclusion by reading; the table above is what settled it. It is the
clearest case in this package for verifying before reporting: the code path was described
correctly and the exploitability was not.

### Two more pre-existing crashes, found while testing that

Both on the **guest coupon** path, and both the same "every legitimate return fails" character as
the `out_of_stock_threshold` and CinetPay crashes:

- **`PaiementProPaymentController.php:293`** — `$request->coupon_info['coupon_id']` is read whenever
  `coupon_code` is present, with no check that `coupon_info` exists. A guest whose state carries a
  coupon code but no `coupon_info` gets `Trying to access array offset on null`, then a fatal on
  `$coupon->PerUsesCouponCount()` one line later. (The lookup itself **is** store-scoped here,
  which is worth noting against the reviewer's claim that it is not.)
- **`PaiementProPaymentController.php:810`** — `Undefined array key "coupon_name"`, raised **after
  the order row has committed**. Same shape as the CinetPay event fatal: the shopper is charged,
  the order exists, and they see a 500.

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

---

## S33 — The two order writers S32 could not close, and what closing them cost

P01-COMMERCE-SEC-01 closed seven of the nine order writers its own census found. The two it left
are not two more of the same: one is a card charge with no guard of any kind, the other is the only
writer on the platform that is both a gateway return and a direct order-placement entry. S32
recorded both as the next package and named the precondition for each. This section records what
P01-COMMERCE-SEC-02 did with them, what it measured, what two adversarial reviews then found in the
work itself, and what is still open.

### 1 — `NMIPayController::getNMIProductStatus` — the other card charge

`Route::any {storeSlug?}/get-payment-NMI` (`routes/customer.php:355`), in the group whose only
middleware is `[web, ActiveTheme, themelanguage]`: no authentication, no authorization, no throttle,
and `any`, so it answers GET without a CSRF token. The card number, expiry and CVV arrive in the
request and the handler posts the `sale` itself.

S32 described it as carrying "every shape this package set out to remove". It carried one more that
no other handler has:

**THE SERVER PRINTED THE PRICED CART INTO THE PAYMENT FORM AND BELIEVED IT BACK.**
`PaymentService::getNMIProductview()` (`PaymentService.php:4228-4236`) hands one `$cartList` to two
places — `Session::put('request_data', ...)` and the view — and the view renders it as

```blade
<input type="hidden" class="hiden" name="response_data" value="{{ json_encode($response) }}">
```

(`resources/views/front_end/payment/nmi-payment.blade.php:98`). On the way back the handler read
**the hidden field**:

```php
$response        = json_decode($request->input('response_data'), true);
$products        = $response['product_list'];        // the product ids AND the line quantities
$product_price   = $response['total_final_price'];   // orders.product_price
$final_price     = $response['total_final_price'];
$total_sub_price = $response['total_sub_price'];
```

and `$products` drives every stock decrement below it. This is not the "omit the session cookie"
defect the previous package closed: it is the **success** path, with a correct server state sitting
unread. A caller edited one hidden input and chose the basket, the quantities and the recorded
totals.

| | was | now |
|---|---|---|
| the state | `Session::get('request_data')` → `json_encode($request->response_data)` | `storedCheckoutState()`, refuse when absent |
| the basket and its totals | the hidden `response_data` field | `storedCheckoutCartList()` — the state's own `cartlist` |
| the amount charged to the card | `$requests_data['cartlist_final_price']`, raw | `serverPayableAmount()` |
| line quantities | nothing | `basketHasUnusableQuantity()`, **before the charge** |
| the basket's store | nothing | `basketHasForeignProduct()`, **before the charge** |
| the order's owner | `$request->customer_id` | `adoptStoredCheckoutState()` |
| replay | nothing | `Session::forget()` immediately before the charge, restored on every path that took no money |
| stock | three read-modify-writes, no give-back | `ProductStockService` via a shared Concern, with give-back |
| `payment_status` | the literal `'Paid'` | `gatewayReturnPaymentStatus()` over a recorded verification |
| `orders.final_price` with shipping on | `$final_sub_total_price` — **a variable the method never assigns** | the amount the card was charged |

**THE ORDER TOTAL WAS 0.00 ON A CHARGED CARD.** `$order->final_price = $final_sub_total_price` at
three sites and nothing in the file ever assigns that name. PHP 8 warns and evaluates it as null, so
on any store whose plan has `shipping_method = 'on'` the order recorded **0.00** while the card was
charged the real amount. A reporting and merchant-wallet defect, not an attack, and the kind of
thing only a *reachable* order writer reveals.

**NMI IS THE FIRST OF THE NINE WITH A VERIFICATION BEHIND ITS `Paid`.** S32's survey, and
`EnforcedPaymentTypesIntegrityTest`'s docblock, record "no verification call of any kind in the
file ... No seam to extend". True of the **file**, false of the **handler**: the order write has
always been nested inside NMI's `response == 1`, which is the gateway's answer to a capture this
handler made. The seam was the success test. That answer is now recorded through
`PaymentVerificationService::recordVerification()` and the status derived from it. The status is the
same `'Paid'` — `'nmi'` is not in `ENFORCED_PAYMENT_TYPES` — but it is attributable, and the survey
row moved from `$sites` to `$wired` as that test's failure message instructs.

#### How S32's precondition was met without touching a gateway

S32 stopped at reporting because "shipping guards I cannot execute even once to a live card-charge
path is the thing the brief forbids". The handler built its own `new Client()` inline — the one
statement no test can get past, and it sat **above** every guard the writer needs. The call moved to
the already-injected `App\Services\NMIService::chargeSale()`: same endpoint, same form fields, same
parse. A test substitutes the service and supplies the gateway's **answer**, which makes the order
writer reachable for the first time. **That is a seam for the writer's guards and is not evidence
about NMI.** No test sends a card number anywhere and none claims a payment was verified.

### 2 — `PaymentController::whatsapp` — the writer that must keep its fallback

S32 recorded that this handler keeps its `$request->all()` fallback **and must**, because two
checkout views post to it without passing through `processOrder`. That holds, so this half of the
package draws the boundary **per field**. Its money was already server-derived by P01-SEC-14. What
was still the caller's:

1. **A guest could write an order owned by any customer.** `$request->merge($requests_data)` sits on
   the authenticated arm only, so for a guest every later `$request->customer_id` was the posted
   one: `orders.customer_id`, the `product_order_id`, and the `customer_id` of every row queued into
   `$cartLinesToClear`. Same family as `0eee8524`, still live here.
2. **A posted shipping figure moved `orders.product_price`.** Found by adversarial review, then
   driven: `$requests_data = $request->all()` → `Cart::cart_list_cookie` → `Cart.php:199`
   `$shipping_price = (int) ($data['shipping_final_price'] ?? 0)` → `:621` folded into
   `$final_total` → `:635` `total_sub_price` → `$order->product_price`. `orders.final_price` is
   unaffected (built from `total_final_price`, which excludes the term), so it is a recorded-total
   defect, not a payable one. Zeroed **only when there is no stored state**, the same one-line
   pattern `processOrder` already uses at `:1082`.
3. **The posted slug beat the route's.** Recorded as a **consistency fix, not a breach**: anyone may
   post to `/{storeB}/whatsapp` directly, so writing a store-B order through store-B's own slug
   gains an attacker nothing. What it produced was a handler disagreeing with its own URL. The route
   now wins, a mismatch is logged, and the state's `slug` is pinned to the resolved one.
4. **No quantity bound**, and **`$plan` could be undefined** — both closed.

### 3 — The two pre-existing crashes swept with them

Both named in S32 as belonging to this package, and both **500 every legitimate order** in a store
that happens to lack a row.

- **`out_of_stock_threshold` / `notification`** — swept across all seven payment controllers to the
  shape P01-SEC-14 C3 already used in `ordersave`.
- **`event(new CinetPayPayment(...))`** — `CinetPayController.php:1008` dispatched a class that
  existed nowhere, so it resolved to `App\Http\Controllers\CinetPayPayment` and raised an uncaught
  `Error` **after** the order row, the stock decrements and the cart deletes had committed. Fixed by
  adding the missing class and its import, not a `class_exists()` guard: every sibling gateway fires
  the same shape and nothing listens to any of them.

### 4 — One shared Concern, and why it is not a second copy

`App\Http\Controllers\Concerns\AppliesCartStockChanges` holds `stockService()`,
`decrementStockOrFail()` and `restoreStockDecrements()`, moved **verbatim** out of
`PaymentController`. S32 item 8 records what copies have cost here: five gateway handlers with three
read-modify-write decrements each and zero uses of `ProductStockService`, because nothing shared
existed to wire them to. One correction to the commit message: `getWhatsappUrl` no longer calls
`decrementStockOrFail` at all, so the trait serves **two** writers in `PaymentController`, not three.

### 5 — What the two adversarial reviews found in this package's own work

Two independent reviews ran against the committed change. **They converged on five defects the work
had introduced or left, and one of them invalidated this file's own positive control.** Every claim
below was re-verified by reading or executing before being accepted.

1. **THE NMI POSITIVE CONTROL WAS PASSING THROUGH AN HTTP 500.** MEASURED by printing the status:
   `500`, while all seven of its database assertions passed. `if ($response['tax_id'])` is a bare
   index and the fixture's `cartlist` omitted the key — which the real `Cart::cart_list*` always sets
   (`Cart.php:633`) — so Laravel's warning-to-`ErrorException` promotion threw **after**
   `$order->save()`. The order existed; the journey did not. Every refusal assertion in the file
   leans on that control. Fixed three ways: the fixture now matches what the cart reader produces,
   the handler's read is `!empty()`, and both positive controls now assert the **response** as well
   as the row.
2. **THE STORE PREDICATE COVERED ONE OF THE STOCK LOOP'S TWO BRANCHES.** The variant branch resolves
   its row with `ProductVariant::where('id', $variant_id)->where('product_id', $product_id)` — a
   predicate built entirely from the caller's own two values. `ProductVariant` carries no
   `StoreScope` and is **not** in `config/tenancy.php`'s `scoped_models` (verified directly). So a
   line naming another merchant's product *and* their matching variant moved that merchant's
   `product_variants.stock` anyway. **Every fixture in the file hard-coded `variant_id => 0`**, so
   no test could see it.
3. **AND IT WAS IN THE WRONG PLACE: it could only refuse after the capture.** The predicate sat
   inside the loop, so a basket naming a foreign product produced a charged card, no order row, and
   "Product not found" — which the package's own docblock forbids three paragraphs above the guard
   that caused it. Both findings are closed by one change: `basketHasForeignProduct()`, before the
   charge, covering both branches because the variant predicate requires the variant to belong to
   the product already judged.
4. **`Session::forget()` BEFORE THE GUARDS DESTROYED A DECLINED SHOPPER'S CHECKOUT.** A mistyped CVV
   — a routine few percent of card traffic — wiped the stored state, and the next submit met *"We
   could not confirm your checkout details ... please contact the store"*. The state is now consumed
   immediately before the charge and **put back on every path that took no money**.
5. **THE REWARD DISCOUNT WAS SUBTRACTED TWICE.** `processRewardPoints()` (`:1520-1530`) already
   subtracts it at initiation and `prepareFinalRequestData()` composes `cartlist_final_price` from
   that — so the card is charged net of reward points. Assigning `$final_sub_total_price` then fed
   `$order->final_price = $final_sub_total_price - $saveRewardPrice`, below the captured amount.
   **Introduced by the fix for the 0.00 defect**: before it, the same line evaluated to
   `0 - $saveRewardPrice`. Corrected to the payable.
6. **`chargeSale()` did not catch the failure its comment named.** In Guzzle 7
   `ConnectException extends TransferException` and is **not** a `RequestException` (verified in the
   vendored source). DNS failure, connection refused, TLS handshake failure and connect timeout
   would have escaped exactly as before. Now `catch (TransferException $e)`.
7. **THE VERIFICATION LOG SAID THE OPPOSITE OF WHAT HAPPENED.** `resolveOrderPaymentStatus()`
   short-circuits for a type outside `ENFORCED_PAYMENT_TYPES` **without reading** `$verification`,
   and logged *"No server-side verification seam exists for this payment type"* over the top of a
   write that had just recorded one. The service now reports a matching record as what it is. The
   status is identical either way; only the log changes — which is the entire point of a log that
   exists to measure the residual.
8. **The `$plan`/`$user` null-guard was applied to `whatsapp` and not to NMI in the same diff** —
   and on NMI that 500 lands after the capture. Fixed.

Three smaller review findings were accepted and fixed: the replay test asserted only that nothing
happened (a 500 would have satisfied it, so it now asserts the refusal); the identity **pin** was
not isolated by any fixture, because every state here *sets* `customer_id` and a bare merge
suffices — `test_the_nmi_identity_pin_covers_a_key_the_state_omits` now unsets it, exactly as
`GatewayReturnTrustBoundaryTest` had to; and the "a guest cannot claim" docblock overclaimed in the
way S32 finding 1 already forced the previous package to narrow, so it now says *by posting the id
on the return*.

**One review finding was traced and rejected.** Both reviews flagged `$cartlist_final_price` being
reassigned after the charge. Traced: after the coupon branch the variable is never read again —
`$order->final_price` is built from `$final_sub_total_price`, `$final_price` or the state — so the
reassignment is dead and cannot reach the order. Not changed.

### 6 — Evidence

`tests/Feature/Commerce/UnguardedOrderWriterTrustBoundaryTest.php` — **34 tests, 135 assertions**.

**THE POSITIVE CONTROLS ARE WHAT STOP THE REST BEING VACUOUS**, and after finding 5.1 they assert
the response as well as the row. `test_a_legitimate_nmi_charge_still_writes_its_order` drives a
genuine guest checkout to `order.complete` — the first time any test has executed this order writer
at all — and `test_a_legitimate_whatsapp_order_is_still_written` does the same for the other.

Every guard removed and re-measured:

| guard removed | result |
|---|---|
| the NMI state guard → the old caller fallback | **1 fails** |
| the basket read from the state → the hidden field | **9 fail** — the dedicated hidden-field test carries the weight; the other eight are noise, because reverting it leaves tests posting no `response_data` and the handler dies |
| `serverPayableAmount()` | **5 fail** (every unusable-total data set) |
| the NMI quantity guard | **4 fail** |
| `basketHasForeignProduct()` | **2 fail** (the plain-product and the variant line) |
| `Session::forget()` before the charge | **1 fails** (replay) |
| putting the state back on a decline | **1 fails** (the retry) |
| `adoptStoredCheckoutState()` | **2 fail** (the claim test and the pin test) |
| `$final_sub_total_price = $serverPayableAmount` | **1 fails** |
| the settings defaults | **1 fails** |
| the `tax_id` guard | **1 fails** |
| the `$plan` guard | **1 fails** |
| the `empty($ProductStock)` guard | **1 fails** |
| the stock give-back | **1 fails** |
| the whatsapp shipping-figure zeroing | **3 fail** |
| the whatsapp customer pin | **1 fails** |
| the whatsapp route-slug precedence | **1 fails** |
| the whatsapp quantity guard | **3 fail** |
| **the in-loop product store predicate** | **nothing fails — subsumed** |
| **the in-loop `empty($Product)` guard** | **nothing fails — subsumed** |

**THREE GUARDS WERE NOT ISOLATED BY ANY TEST ON THE FIRST ATTEMPT, and a fourth group only became
provable after new tests were written.** Recorded because it is the project's own "a test must bite"
rule turned on its author:

| guard | first attempt | after the test was rewritten or added |
|---|---|---|
| the NMI state guard | **19 of 19 still passed** — the forged body carried no `cartlist`, so the empty-basket check produced the same refusal | the body now carries a complete `cartlist`; **1 fails** |
| `serverPayableAmount()` | **19 of 19 still passed** — with a well-formed state it returns the number the raw read did | a data-provider test drives absent / empty / non-numeric / zero / negative; **5 fail** |
| the whatsapp route-slug precedence | **19 of 19 still passed** — with stored state present the body's slug never reaches the slug read | the test drives the **fallback** path; **1 fails** |
| the `tax_id`, `$plan` and `empty($ProductStock)` guards | **34 of 34 passed** — the fixture was too tidy to reach any of them | `test_a_charged_card_does_not_end_in_a_crash` drives all three; **1 fails each** |

**The last two guards in the table are subsumed and are recorded as unproven rather than claimed.**
`basketHasForeignProduct()` refuses the same basket one leg earlier and better placed, so the
in-loop predicate and its null guard now fail nothing. They are kept as defence in depth at zero
cost — the only protection left if the pre-flight is bypassed or a new caller reaches that loop
directly — exactly as S32 kept the `method_id` pin after `0ad3f7e0` subsumed it.

**Per-data-set vacuity, disclosed rather than implied** (the omission S32 finding 7 records for the
previous package): in both quantity tests the *stock* assertions are structurally dead for the
`negative`, `fractional` and `not a number` sets, because `ProductStockService::normaliseQuantity()`
refuses the decrement either way. Only `absurd` would move stock without the guard. The load-bearing
assertions there are the refusal message, the gateway call count and the order count, and the
docblocks now say so.

**What none of this proves:** that NMI, or any other gateway here, verified a payment.

**One testing fact worth keeping, because it cost a debugging session and fails silently.**
`Illuminate\Routing\Route::getController()` **caches its controller instance**, and the
RouteCollection lives for the whole test rather than one request. So a second
`$this->app->instance(NMIService::class, $other)` mid-test rebinds the container and changes nothing
the handler sees — the route keeps charging the first double while `app(NMIService::class)` returns
the new one. MEASURED: the decline-and-retry test incremented the *first* double's call count and
failed claiming the retry never reached the gateway. Anything needing two different gateway answers
in one test must queue them on one instance; `fakeNmiSequence()` does, and its docblock records why.

### 7 — What this package did NOT close

1. **`ProductVariant` is unscoped everywhere.** Its lookup is
   `ProductVariant::where('id', $variant_id)->where('product_id', $product_id)` in all twelve stock
   loops — a predicate built entirely from the caller's own two values, consulting neither the store
   nor the `$Product` the handler just resolved, so **scoping the product does not scope the
   variant**. `product_variants.store_id` exists and is nullable, so the project's
   refuse-a-provable-mismatch rule applies.

   **A CORRECTION TO THIS SECTION'S FIRST VERSION:** it said NMI was "the only one of the nine with
   a product-level store predicate". Wrong — `ApiController::place_order` (`:3251`),
   `::ordersave` (`:8032`) and `::variant_list` (`:8527`) have had one all along. The product-level
   gap is in the **web storefront** handlers, not platform-wide; the **variant** gap is universal
   and includes the three API writers that do scope the product. The full site-by-site inventory,
   the scope boundary and the tests owed are in
   [`docs/development/phase-1/11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md`](../development/phase-1/11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md),
   prepared as **P01-COMMERCE-SEC-03**. `basketHasForeignProduct()` is already shared by seven
   handlers, so the work is wiring rather than invention.

   Reachability is the same as S32 item 5 and is **a containment gap, not a demonstrated exploit**:
   the add-to-cart chokepoints are store-scoped, so a foreign line enters only through the two
   vendored module writers (`BoostSales`, `FrequentlyProduct`), which write `product_id` and
   `variant_id` straight from the request. Not driven here — no module loads in any test run.
2. **A non-numeric cart quantity crashes before any guard can see it** on every handler that prices
   its cart from the database. `app/Models/Cart.php` multiplies `$cart_value->qty` with no cast at
   twelve sites, so `carts.qty = 'many'` raises `TypeError: Unsupported operand types: int * string`
   inside the cart read — `PaymentController.php:3171` for `whatsapp`. MEASURED with
   `withoutExceptionHandling()`. **It fails closed** (500, no order, no stock), so it is an
   availability defect in a shared storefront model, and fixing it means casting twelve sites in a
   model every cart read goes through. The NMI guard *does* refuse that quantity, because it reads
   the stored state rather than the rows.
3. **The five gateway controllers still have no atomic decrement and no give-back.** S32 item 8,
   unchanged. The Concern they would use now exists.
4. ~~**`$settings['stock_management'] ?? '' == 'on'` is still a precedence bug.**~~ **CLOSED by
   owner decision — see S34.** Twelve expressions across nine files, corrected to
   `(($settings['stock_management'] ?? null) === 'on')`, with 39 regression tests covering ON, OFF,
   absent and an unrecognised value across every order writer reachable in this environment.
5. **`orders.delivery_price` is still untested on a real shipping figure.** Every state in this file
   carries `shipping_final_price => 0`, so the `get_shipping_method()` arm and the DECIMAL column are
   never driven. S32 item 6 records that a real figure is a *formatted currency string* which MySQL
   stores as `0.00`; that needs a MySQL script under `tests/Support/Security/bin/`, not a Feature
   test. **Nothing here says anything about `orders.delivery_price` in production.**
6. **The coupon branches remain as S32 left them**, gated on **S24**: `cartlist_final_price` carries
   no coupon discount when `coupon_info` is set, the two coupon branches read two different keys
   (`coupon_discount_amount` vs `coupon_final_amount`), and the shipping-off reward branch drops
   `$coupon_price`. Not drivable while `apply_coupon` refuses every non-`fixed product discount`
   coupon, so a test could not bite.
7. **Negative stock is still reachable by design.** `ProductStockService` applies a `>=` predicate
   only where `stock_order_status === 'not_allow'`, and `MAX_SANE_LINE_QUANTITY` is 1,000,000 — so
   one accepted line can take a 50-unit product to −999,950. The ceiling bounds the blast radius; it
   is not a floor. Pre-existing and covered by `OrderStockConcurrencyTest`, not by this file.
8. **There is still no idempotency key.** Replay is blocked only because the stored state is single
   use — now on NMI too, where a replay was a second *card charge*. Unchanged from S32 item 2.
9. **Nothing here proves a payment.** Unchanged from S32 item 1. **Owner decision.**
10. **`orders.product_price` means different things on different writers** — `total_final_price` on
    NMI, `total_sub_price` on `whatsapp`. Noted, not reconciled; it is a reporting question for the
    owner, not a defect either handler can settle alone.

---

## S34 — `stock_management`: the setting the order writers did not actually read

**CLOSED. Owner-approved behaviour change, and the one in this project's record whose blast radius
is "most stores".** It was reported and deliberately deferred twice — by P01-SEC-14 (the note at
`PaymentController::stockManagementIsOn()`) and again by P01-COMMERCE-SEC-02 — because correcting it
changes live merchant behaviour. The owner has now decided it, with the required behaviour stated:
**OFF must not automatically deduct stock.**

### The defect

Twelve expressions across nine files tested the setting as

```php
if ($settings['stock_management'] ?? '' == 'on')
```

`??` binds **looser** than `==` in PHP, so that parses as

```php
if ($settings['stock_management'] ?? ('' == 'on'))      // i.e.  ... ?? false
```

Whenever the key **exists**, its own **value** becomes the condition — and `'off'` is a non-empty
string, so it is truthy. **A store with stock management OFF had its stock decremented anyway.**

### Why the blast radius is most stores, not a corner case

This is the part the earlier deferrals did not state, and it is what makes the change worth the
disruption:

- `app/Models/Utility.php:111` ships **`'stock_management' => 'off'`** as the platform default.
- `app/Http/Controllers/SettingController.php:1094` writes **`'off'`** whenever the merchant's
  checkbox is unticked: `$request->has('stock_management') ? $request->stock_management : 'off'`.

So the key is **present with value `'off'`** for essentially every store that has ever saved its
settings — precisely the case the bug mistook for "on". A store that has *never* saved settings has
no key at all, took the `?? false` limb, and was already correct.

### Exactly one transition changes

| setting | before | after |
|---|---|---|
| absent | no deduction | no deduction |
| `'on'` | deducted | deducted |
| **present, not `'on'`** | **deducted** | **not deducted** |

All three are pinned by tests, so the two that must not move cannot drift. A fourth data set — a
value that is neither `'on'` nor `'off'` — separates "the fix reads the value" from "the fix
special-cases the string `'off'`".

**What deliberately does NOT change: the order is still written.** "Do not deduct" is not "do not
sell". A merchant who turns stock tracking off is saying they do not want this platform counting
units, not that checkout should fail — and that is asserted, not assumed, on every path where the
order write is reachable.

### The twelve sites

| file | sites | enclosing path |
|---|---|---|
| `Api/ApiController.php` | 3 | `cart_qty`, `place_order`, `ordersave` |
| `PaymentController.php` | 2 | `getProductStatus`, `whatsapp` |
| `PaiementProPaymentController.php` | 1 | `getProductStatus` |
| `CinetPayController.php` | 1 | `getProductStatus` |
| `EasebuzzController.php` | 1 | `getProductStatus` |
| `KhaltiPaymnetController.php` | 1 | `getProductStatus` |
| `AuthorizeNetPaymnetController.php` | 1 | `getOrderPaymentStatus` |
| `PayUPaymentController.php` | 1 | `getProductStatus` — **dead code**, unrouted |
| `Models/Cart.php` | 1 | `cart_qty_cookie` — **not a deduction**, see below |

All now read `(($settings['stock_management'] ?? null) === 'on')`, the form
`PaymentController::stockManagementIsOn()` and `assertCartAvailable()` have always used.

**ONE CLAIM IN THIS SECTION'S FIRST VERSION WAS WRONG AND IS WITHDRAWN.** It said
`ApiController::place_order:3254` was "a bare index ... so a store that has never saved its settings
raised 'Undefined array key'", and that its "absent" case was a 500. **Both are false.**
`place_order` does not read the settings table directly: `:3014` is
`$settings = Utility::Setting($storeId)`, and `Utility.php:135` starts from `$array = $defaults`,
which already contains `'stock_management' => 'off'` (`:111`). **The key is never absent on that
path**, so the bare `== 'on'` it carried was already correct for `'off'`, `'yes'` and absent alike,
and the change to `=== 'on'` there is a consistency normalisation with **no behavioural effect**.
MEASURED, after the project's own `tenant-authz-reviewer` predicted exactly this: with `:3254`
reverted to the bare index, all eight `place-order` rows stay green. The genuine absent-key path is
`ordersave` (`:7755`), which reads the table raw. The test docblock now records which rows bite and
which are a fence.

**Two sites already used the correct `isset(...) && ... == 'on'` form and were left alone:**
`PaymentService.php:2332` and `NMIPayController.php:580`. Their existence is the evidence this was a
precedence slip rather than a decision, and NMI is driven by a test anyway — so that a future sweep
cannot "tidy" it into the broken form.

### The one site that is not a deduction, recorded loudly rather than changed quietly

`Cart::cart_qty_cookie()` (`Cart.php:735`, reached by `POST {slug}/change-cart`) chooses between
**two branches** rather than between deducting and not: the `if` arm bounds a guest's cart increment
by the variant's stock, the `else` arm applies looser `stock_status` rules. With the bug, a store
with the setting **off** took the **strict** arm — bounded by a stock count it had asked the platform
not to keep. After the fix it takes the `else` arm.

So an unmanaged store's shopper can now raise the cart quantity past the recorded stock figure. That
is the correct reading of "stock management off", it moves no stock and writes no order, and
`test_an_unmanaged_store_does_not_bound_a_cart_increment_by_stock` asserts it — so the change is on
the record rather than a side effect nobody measured. The owner's brief says not to change unrelated
stock behaviour *silently*; this is the site that clause is about.

### A second, quieter inconsistency closed with it

`PaymentController::assertCartAvailable()` has always used the **correct** test, so for a store with
the setting off it returned "nothing to check" — and the loop then decremented anyway. The two
halves of one decision disagreed. `test_the_preflight_and_the_loop_agree_about_an_unmanaged_store`
drives the observable case: a cart line for more units than exist, on an unmanaged store, must be
neither refused nor deducted, and must still produce an order.

### Evidence

`tests/Feature/Commerce/StockManagementSettingTest.php` — **39 tests**, four settings states across
every order writer reachable in this environment:

| path | driven by |
|---|---|
| `PaiementProPaymentController::getProductStatus` | the order-writing test (order + stock asserted) |
| `PaymentController::whatsapp` | the order-writing test (order + stock asserted) |
| `NMIPayController::getNMIProductStatus` | the order-writing test, with the gateway answer substituted |
| `CinetPay`, `Easebuzz`, `Khalti`, `getProductStatus` | the stock-loop test (stock asserted; only PaiementPro reaches `new Order()`) |
| `ApiController::place_order`, `::ordersave` | the API test, with a real customer token |
| `Cart::cart_qty_cookie` | the cart-increment test |

**The ON case is asserted per route as the non-vacuity control, and it earned its keep immediately:**
Easebuzz moved **no stock at all** with the setting on, because it stores its state under its own
session key (`easebuzz_request_data`, `EasebuzzController:245`) and seeding only `request_data` left
it refused at the state guard. Every OFF case for that route would have passed vacuously. The ON
control is the only reason that was caught.

Removal checks — the precedence bug restored per file:

| file reverted | result |
|---|---|
| `PaiementProPaymentController` | **2 fail** |
| `PaymentController` (3 expressions) | **5 fail** |
| `Api/ApiController` (3 expressions) | **5 fail** |
| `CinetPayController` | **2 fail** |
| `EasebuzzController` | **2 fail** |
| `KhaltiPaymnetController` | **2 fail** |
| `AuthorizeNetPaymnetController` | **nothing fails — unreachable** |
| `PayUPaymentController` | **nothing fails — dead code** |
| `Models/Cart.php` | **nothing fails — not a deduction** |

**The first run of that harness failed seven of the nine**, which is why the test file grew: only
PaiementPro and `PaymentController` were covered at first, and CinetPay, Easebuzz, Khalti and the
two API writers were corrected with nothing able to fail. Three sites remain uncovered by execution
and are stated rather than implied:

- **AuthorizeNet** — its handler is the charge, not a return, so it dies in an empty `catch` before
  its stock loop. Same exclusion, same reason, as `GatewayReturnTrustBoundaryTest` records.
- **PayU** — the method is unrouted dead code; nothing can drive it. If it is ever routed it must be
  guarded first.
- **`Cart::cart_qty_cookie`** — now driven and **biting**, after its first test could not fail.
  That test posted `variant_id => 0`, and `Cart.php:722` gates the block on
  `if (!empty($request->variant_id) || $request->variant_id != 0)` — for `"0"` BOTH limbs are false,
  so control went to the non-variant `else` at `:786` and **line 735 never executed**. The removal
  check said as much (reverting it failed nothing) and the reviewer named the gate. It now drives a
  real `ProductVariant` row with one unit of stock and `manage_stock` set, so the strict arm would
  have refused the increment and the loose arm allows it: **1 fails** on revert.

### What the tenant-authz review found in this package's own work

Three corrections, each verified before being accepted, and one protection restored:

1. **THIS CHANGE REMOVED A TENANCY PROTECTION AS A SIDE EFFECT** — the thing the brief forbids.
   `ApiController::ordersave`'s refusal for a product the store does not own is the `else` of
   `if (!empty($Product))` **inside** the `stock_management` arm (`:8034`). While the precedence bug
   was live, an `'off'` store entered that arm anyway and so got the refusal; correcting the
   precedence made the arm skip and **took the ownership check with it**. The order would have been
   written recording another merchant's product, priced from their catalogue, with the shopper's
   cart cleared — a cross-tenant **reference**, not a write, since the deduction is skipped too.
   The refusal is now **hoisted out of the arm**, which restores the status quo rather than
   tightening (same condition, same message; a managed store reaches it either way), and
   `test_an_unmanaged_store_still_refuses_a_foreign_product_line` fails without it.
   **`place_order` is deliberately NOT mirrored**: its expression was always correct, so an
   unmanaged store there never had the refusal, and hoisting it would be a NEW refusal over a
   **hard** `store_id` predicate that would start rejecting legitimate legacy NULL-store products.
   Recorded for P01-COMMERCE-SEC-03.
2. **The `place_order` absent-key claim** — withdrawn above.
3. **The `Cart.php:735` test could not fail** — corrected above.

And one finding that belongs to the next package but **raises its severity**:
`ApiController::addtocart` can plant a cart row naming a **foreign** product through the host app,
on its `attribute_id` branch (`:1451`), which never consults the store-scoped `$product` and writes
`$request->product_id` verbatim at `:1557` — the null dereference that follows raises after the
insert, with no transaction, so the row persists behind a 500. A later `order-save` on a managed
store then decrements the other merchant's stock. That refutes the "module-gated only" reachability
paragraph the next-package document shipped with; both are corrected in
[`11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md`](../development/phase-1/11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md).

---

## S35 — The cart → order chain that reached another merchant's inventory

**CLOSED by P01-COMMERCE-SEC-03.** The first cross-tenant **inventory write** in this project's
record that needs no module, no forged principal and no payment: an ordinary customer of one store,
using their own token, in **two requests**.

### The chain, measured before the fix

```
POST /api/{slugA}/add-cart          store-A customer, their own bearer token
    product_id   = <store B's variant product>
    attribute_id = <store B's variant>
    variant_id   = <same>
  -> carts row PERSISTED: {product_id: B's, variant_id: B's, store_id: A's}
     (then HTTP 500 — see "the row survives the error" below)

POST /api/{slugA}/place-order       an ordinary checkout, same token
  -> store B's product_variants.stock  50 -> 43   (the cart quantity, 7)
  -> an order written in store A
  -> HTTP 500
```

Both legs driven through the real routes by
`tests/Feature/Security/ForeignProductCartChainTest`, which failed on exactly these three
assertions against the unfixed code and passes now.

### Why the existing guards did not see it

`addtocart` has resolved its product **store-scoped** since S19 —
`Product::where('id', $request->product_id)->where('store_id', $store->id)` — and the comment above
that line claimed it "keeps a foreign product out of the cart in the first place". **It did not.**
Nothing acted on the null: `if ($product && …)` guarded only the variant-required message, and
control reached the `attribute_id` branch, which

- resolves the variant as
  `ProductVariant::where('id', $request->attribute_id)->where('product_id', $request->product_id)`
  — **the caller's own two values**, consulting neither the store nor `$product`;
- prices the line from that foreign variant (`$ProductVariant->final_price * qty`);
- takes the **posted** `variant_id` at the `$product_stock_datas` fallback, which that branch never
  assigns;
- writes `$cart->product_id = $request->product_id` verbatim.

**`ProductVariant` carries no `StoreScope` and is not in `config/tenancy.php`'s `scoped_models`, so
SCOPING THE PRODUCT DOES NOT SCOPE THE VARIANT.** That one sentence is the whole defect, and it is
why every fix in this package comes in two halves.

**The row survives the error.** `$cart->save()` runs before the handler dereferences the same null
`$product`, with no transaction around the pair — so the refusal the caller saw and the persistence
were independent. Partial persistence was part of the attack, not a side effect, which is why the
tests assert the table rather than the response.

### What was fixed

| | |
|---|---|
| `ApiController::addtocart` | the store-scoped lookup's **null is now refused**, before any branch. One guard covers the variant, wholesale, size-guideline and plain paths |
| `ApiController::addtocart` (variant branch) | `if (empty($ProductVariant))` **moved above** the `explode(',', $ProductVariant->variation_option)` that dereferenced it — the guard was unreachable, nested inside `if ($option == true)` which could only be true once the dereference had already succeeded. A mismatched pair answered 500 instead of "Product not found." |
| `ApiController::place_order` | the ownership refusal **hoisted out of the stock-management arm**, as S34 did for `ordersave` |
| `ApiController::place_order` | `empty($ProductStock)` tested **before** its dereference, same shape as the above |
| `GuardsGatewayReturn::basketHasForeignProduct()` | **extended to the variant**: a line whose variant belongs to a different product is refused |
| 6 checkout preflights wired | PaiementPro, CinetPay, Easebuzz, Khalti, `PaymentController::getProductStatus`, `PaymentController::whatsapp` — before any stock row moves |

**`place_order`'s hoist reverses a decision S34 recorded.** S34 declined to mirror `ordersave`'s
hoist here, arguing that this handler's stock-management expression was always correct so an
unmanaged store never had the refusal, and that hoisting would be a *new* refusal over a hard
`store_id` predicate. **Measurement overtook that argument**: `place-order` was the leg that
actually moved store B's stock (50 → 43), while `ordersave` refused the identical request *because*
of S34's hoist. A live cross-tenant inventory write is not an unknown legacy behaviour worth
preserving.

### The variant half is not a `store_id` predicate, and that is deliberate

`product_variants.store_id` **is nullable** (`create_product_variants_table.php:30`), so a hard
store predicate on the variant would drop legitimate legacy rows. The helper instead requires the
variant to belong to **the product it is paired with**, which the product half has already
established belongs to this store. Same conclusion, no legacy cost. A variant id that resolves to
no row is left to the handlers' own "Product not found" paths, exactly as a missing product is.

### The inventory, corrected

The next-package document said **12** variant lookup sites. Verified against current code, the
stock-mutating set is **14** — `PaymentService::getProductStatus:2334` was missed, and it also
resolves its product with a bare `Product::find($product_id)`:

| file | variant site | product predicate | preflight now |
|---|---|---|---|
| `Api/ApiController` `place_order` | `:3349` | store-scoped | ownership refusal hoisted |
| `Api/ApiController` `confirm_order` | `:6632` | **none** | — see §open |
| `Api/ApiController` `ordersave` | `:8175` | store-scoped | S34's hoist |
| `PaymentController::getProductStatus` | `:2127` | **none** | **wired** |
| `PaymentController::getWhatsappUrl` | `:2869` | **none** | availability check only, no write |
| `PaymentController::whatsapp` | `:3408` | **none** | **wired** |
| `PaymentService::getProductStatus` | `:2334` | **none** | — see §open |
| `NMIPayController` | `:607` | store-scoped | S33's preflight |
| `KhaltiPaymnetController` | `:431` | **none** | **wired** |
| `EasebuzzController` | `:471` | **none** | **wired** |
| `PaiementProPaymentController` | `:472` | **none** | **wired** |
| `CinetPayController` | `:577` | **none** | **wired** |
| `AuthorizeNetPaymnetController` | `:534` | **none** | not wired — handler dies before its loop |
| `PayUPaymentController` | `:407` | **none** | not wired — **unrouted dead code** |

### Evidence

`tests/Feature/Security/ForeignProductCartChainTest` — **15 tests**, every one driving the whole
journey through real routes rather than a validation helper, with real database assertions.

Coverage against the brief's list: customer A adding product B ✓ · variant B under product A ✓ ·
correct product with incorrect variant ✓ · foreign cart rows already stored ✓ (both API writers and
the gateway return) · orders from foreign or inconsistent carts ✓ · stock mutation across stores ✓ ·
stock management ON and OFF ✓ · valid plain and variant purchases ✓ · a request that fails after
validation but before persistence ✓.

Eight controls removed and re-measured, **all eight bite**:

| control removed | result |
|---|---|
| the `addtocart` product-null refusal | **2 fail** |
| the `addtocart` variant emptiness guard | **1 fails** |
| `place_order`'s ownership refusal | **1 fails** |
| the helper's **variant** half | **1 fails** |
| the helper's **product** half | **5 fail** |
| the PaiementPro preflight | **2 fail** |
| the storefront-return preflight | **1 fails** |
| the whatsapp preflight | **1 fails** |

**THREE OF THOSE EIGHT FAILED NOTHING ON THE FIRST RUN, and the removal check is what found it** —
the project's "a test must bite" rule turned on its author for the third package running:

| control | first attempt | after |
|---|---|---|
| the `addtocart` variant emptiness guard | **nothing failed** — the mismatch test asserted only that no row named the foreign variant, and without the guard the handler **500s** on the null dereference, so no row is written either way. The exact "passes only because of an unrelated HTTP 500" shape the brief forbids | the test asserts the response is **not 500** first: a refusal and a crash are not the same outcome. **1 fails** |
| the storefront-return preflight | **nothing failed** — no test drove `{slug}/get-payment-status` with a foreign basket | a data-provider test drives it; **1 fails** |
| the whatsapp preflight | **nothing failed** — same | same test drives `{slug}/whatsapp`; **1 fails** |

**The positive controls are what stop the rest being vacuous**, one per leg: a legitimate variant
purchase (add-to-cart → order → the variant's own stock moves by the quantity ordered), a legitimate
plain purchase, a legitimate guest gateway return (order written, variant 50 → 43), and an unmanaged
store completing an order **without** deducting — so the fix cannot be mistaken for having
re-coupled ownership to the stock-management setting S34 separated.

One test also caught a fixture error of its own before any of this: `add-cart` is routed at
`{slug}/add-cart`, not `{slug}/addtocart`, and the first run of four refusal tests passed **because
the route 404'd**. Fixed before anything was concluded from them.

### Still open — and the owner decisions this package deliberately did not take

1. **`ApiController::confirm_order:6632` and `PaymentService::getProductStatus:2334`** both mutate
   stock, both resolve their product with **no** store predicate, and neither has a preflight.
   `confirm_order` was out of this package's measured chain and `PaymentService::getProductStatus`
   is the twin of a handler already wired — both are small, and both are left rather than guessed
   at, because neither was driven to a measured stock movement here.
2. **The two predicate rules still disagree.** `addtocart`, `place_order` and `ordersave` use a
   **hard** `->where('store_id', $store->id)`; `basketHasForeignProduct()` and `0ad3f7e0` accept a
   **NULL** `store_id` as "not a provable mismatch". The hard form is what those three handlers
   already shipped, so this package changed no policy — but it means **a legacy product whose
   `store_id` is NULL is refused at checkout on those routes**. Whether such rows exist in
   production is one query the owner can answer and this environment cannot:
   `SELECT COUNT(*) FROM products WHERE store_id IS NULL;` — and the same for `product_variants`.
   If the answer is zero, make the nullable-tolerant rule the only rule. If it is not,
   the hard predicates need the tolerant form and the tolerance needs a compensating control.
   **Owner decision.**
3. **`ProductVariant` and `Product` remain globally unscoped.** What shipped is containment at the
   caller, three packages running. Adding them to `scoped_models` is a platform-wide change to
   every read, with `null_tenant => 'open'` underneath it, and needs its own compatibility
   decision — which the brief for this package explicitly withheld.
4. **AuthorizeNet and PayU are not wired**, with the same stated reasons as in S33: the first dies
   in an empty `catch` before its stock loop, the second is unrouted dead code. Neither is
   verifiable by execution here, and a guard nothing can fail is not a guard.

## S36 — What an adversarial review of S35 found: the pair that was checked was not the pair that was written

**CLOSED by the P01-COMMERCE-SEC-03 FOLLOW-UP**, except for the residuals listed at the end, which
are recorded rather than changed and say why.

S35 closed the cart entry, the two API order writers and six checkout writers. A
`tenant-authz-reviewer` pass over that commit, briefed to assume nothing it had just been told,
found **five** things wrong with it. All five were re-verified here by reading the code, and four
turned out to be real. A completeness sweep of the order writers then found a **sixth** site that no
part of S35 or of the review had named, on the prefix the shipped admin app calls.

### S36-1 — `addtocart` stored the variant the caller posted, not the one it validated

`ApiController::addtocart`'s `attribute_id` branch resolves
`ProductVariant::where('id', $request->attribute_id)->where('product_id', $request->product_id)`
and refuses a mismatch — and then never assigned `$product_stock_datas`. The shared assignment
after all the branches is

```php
$variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;
```

so it fell through to **raw request input**, and `$cart->variant_id` was written from that. The row
therefore paired this store's product with whatever variant id the caller sent. S35's own docblock
at that branch claimed the pairing "is what makes the chain sound"; it was the pairing of the
checked values, not of the stored one. That docblock is corrected in place.

Two more copies of the same fall-through on the same handler:

- the **non-`attribute_id`** arm resolved `ProductVariant::find($request->variant_id)` **unpaired**,
  so a foreign variant came back non-empty, skipped the paired re-resolution below it, became
  `$variant_id`, and was set as `variantId` on `$product` — which made `$product->final_price`
  price the cart line from **another tenant's `variation_price`**;
- the **plain-product** arm (`variant_product == 0`) validates no variant at all and wrote whatever
  arrived verbatim.

**Fixed** by handing the validated variant to the shared assignment, pairing the unpaired lookup,
and **one gate on the pair about to be persisted**, which covers every branch including the six
module arms this project cannot test.

**AND IT CARRIES THE SAME BEHAVIOUR CHANGE THIS QUEUE GAVE AS ITS REASON FOR NOT TOUCHING THE
WHOLESALE ARM.** An adversarial authz review caught that asymmetry and it is a fair hit. For a
caller sending a valid `attribute_id` and **no** `variant_id`, the row used to store `variant_id = 0`
— so the cart priced at *product* level and the stock loops decremented `products.product_stock`.
It now stores the real variant id, so the line prices from the variant and the loops decrement
`product_variants.stock`. That is exactly the consequence recorded below as the reason the
WholesaleProduct arm was left alone.

The distinction that remains is about *evidence*, not about behaviour: the non-module arm is driven
by tests, so the change is measured and reversible on a known signal, while no test in this
repository can load a WorkDo module. Both arms carry the same consequence; one of them can be
watched. The documented `add-cart` contract (`packages/workdo/Api/src/documentation/theme.json`)
carries **no** `attribute_id` field at all, so no documented client sends the combination that
triggers it. Removal-checked: the first bites
`test_add_to_cart_stores_the_variant_it_validated_not_the_one_posted`; the gate bites
`test_add_to_cart_refuses_a_stray_variant_on_a_plain_product`. The paired lookup and the gate are
**mutually subsuming** for the foreign-variant payload — each alone refuses it, so neither alone
bites that test. Recorded as measured, not claimed.

### S36-2 — The web `cod` / `bank_transfer` order writer had no ownership check at all

`App\Services\PaymentService::getProductStatus` is the storefront's cash-on-delivery and
bank-transfer order writer, reached from `PaymentController::processOrder`
(`routes/customer.php:177`, **no auth middleware** — a guest reaches it through the registration
arm). Its product lookup is `Product::where('id', $product_id)->first()` with **no store
predicate**; its plain-product arm decrements `Product::find($product_id)`. Neither pre-flight was
wired into it: it is the one **live host-app order writer** S35's six-site sweep missed.

**Fixed twice, deliberately:** once in `processOrder` beside the two pre-flights already there
(covering every payment type `processPayment` dispatches), and once inside the writer against the
`$store` it will actually write with. Removal-checked: **each alone still refuses**; removing
**both** moves another merchant's stock, which is the measurement that proves the pair.

### S36-3 — The Authorize.Net capture had the quantity guard and not the ownership one

`AuthorizeNetPaymnetController::getOrderPaymentStatus` (`routes/customer.php:186`) builds the
`authCaptureTransaction` itself, so a refusal from any later point leaves a shopper **charged with
no order row** — the outcome `basketHasForeignProduct()`'s own docblock argues against, in the one
file it was not wired into.

The basket has to come from the **stored state**: the handler does not build `$products` until
after the capture. **Fixed** with `storedCheckoutCartList($state)['product_list']` before the
charge, plus the quantity guard pre-charge as well (the post-charge copy is kept as defence in
depth).

**A no-op was caught here by reading, not by the test.** The first version of this guard passed
`storedCheckoutCartList($state)` directly — which returns `$state['cartlist']`, whose *lines* live
under `product_list`. Iterating the cart list walks its top-level keys, none of which carries a
`product_id`, so the guard would have refused nothing while every test passed. Both the guard and
the dereference are removal-checked independently.

### S36-4 — An orphan decrement: a poisoned second basket line left the first line's stock deducted

`place_order` and `ordersave` kept their ownership refusal **inside** the stock loop, and the
decrement is a bare `->save()` with no transaction around it. A two-line basket whose first
processed line is legitimate and whose second is foreign **decremented the first, refused on the
second, and wrote no order** — an orphan deduction against this store's own merchant, repeatable at
will.

**Fixed** by hoisting a single basket pre-flight above both loops; the in-loop refusals are kept as
defence in depth (they also cover a product id that resolves to no row, which the pre-flight
deliberately treats as "not a provable mismatch").

**ORDER OF INSERTION IS LOAD-BEARING IN THE TEST, and the first version had it wrong.** The cart
list is served **newest row first**, so the line inserted LAST is processed FIRST. With the foreign
row created second the loop refused on its first iteration, nothing had been decremented, and the
test passed with the fix reverted. Measured both ways: with the rows the right way round, reverting
the hoist takes the legitimate variant 50 → 47 with no order written.

### S36-5 — `ordersave`'s dead guard, and the crash that is NOT it

`ordersave` ran `explode(',', $ProductStock->variation_option)` **before**
`if (!empty($ProductStock))`, so a line whose variant did not pair with its product raised a null
property read — HTTP 500 — and the handler's own refusal below was unreachable. `place_order` got
this correction in S35; its twin did not. **Fixed**, and now **defence in depth behind S36-4's
pre-flight**: removal-checked as *subsumed* (it bites only when the pre-flight is removed as well).

**A separate crash was measured on the way and is NOT fixed:** a cart row naming a variant id that
does not exist at all dies earlier, in the cart-list builder, at
`ApiController.php:654` — *"Attempt to read property `discount_price` on null"*. It is a 500 on
one's own basket, not a cross-tenant write, and the new `addtocart` gate stops such a row being
created through the host app. **Recorded, not changed.**

### S36-6 — The merchant API's own order writer: the site nobody had named

`Api\DashboardController::createOrderViaApi`, routed at `POST api/admin/createOrderViaApi`
(`routes/api.php:240`, `custom.auth`) — **the prefix the shipped admin Flutter app calls**:

- `resolveOwnedStore($storeId)` proves the caller owns the **store**;
- `products.*.product_id => exists:products,id` proves the product **exists**;
- **nothing tied the two together.** `Product::find()` and `ProductVariant::find()` are scoped by
  nothing, so a merchant who owns store A could post their own `store_id` with another merchant's
  `product_id` and the loop decremented **that merchant's** `products.product_stock` — or their
  variant's `stock` — while the order was written under store A. The same shape as S19, one surface
  over. The sibling merchant-web writer `OrderController::store` already carries the predicate.

**Fixed** with a store predicate on the product and a `product_id` predicate on the variant. Both
removal-checked, with a positive control that a merchant's own order still writes and still
decrements (50 → 47).

**And one leak closed because the fix made it reachable:** four early returns inside this handler's
`\DB::beginTransaction()` returned without a rollback. Harmless under FPM, a held transaction on a
long-lived worker — and previously unreachable on the variant path, because `find()` always
resolved a row. `\DB::rollBack()` added before each.

### S36-7 — The web cart writer had the same unpaired variant, and it is an availability finding too

`CartController::product_cartlist` — `POST {storeSlug}/product_cart`, the storefront's own
add-to-cart — resolves three variants. The guest arm (`:277`) has been **paired** since
P01-COMMERCE-SEC-01. The **authenticated** arm (`:362`) was
`ProductVariant::find($request->variant_id)`, unpaired, and `$cart->variant_id` was written from
whatever came back; the SizeGuideline module arm (`:306`) is unpaired as well. The product has been
store-scoped on this path since P01-COMMERCE-SEC-01 — and, once again, **scoping the product does
not scope the variant.**

Found by an *executed* risk review of this package's own checkout guards, which pointed out the
second-order consequence: with the checkout writers now refusing such baskets, a shopper could
**poison their own cart here and then be unable to check out at all**. A tenancy fix that creates a
dead end for the shopper is only half a fix.

**Fixed** by pairing `:362` and by the same one-gate-before-the-write pattern `addtocart` received,
which also covers the module arm no test here can drive.

**AND THE FIRST VERSION OF ITS TEST PASSED WITH BOTH CONTROLS REMOVED.** `product_cartlist` refuses
any variant whose `variation_option` lacks `enabled` (*"Product variant is not available."*)
**before** it reaches either control, and the shared fixture omits that flag — so the refusal under
test was not the refusal being measured. A dedicated fixture now sets `manage_stock,enabled`.
Re-measured after the correction: the paired lookup alone is **subsumed** by the gate, and
**removing both writes the foreign row** — which is what proves the pair. The same both-removed
check was then run against `addtocart`'s pair and bites there too, which retires the open question
left by the first harness run.

### S36-8 — The quantity CEILING was missing from all three cash-on-delivery writers

**MEASURED, by a completeness critic, against the committed code** — qty `2147483647` on a product
seeded with 50 units:

| writer | response | stock | order |
|---|---|---|---|
| `POST {slug}/process-order` (web, `cod`) | **302, no error** | 50 → **−2,147,483,597** | written |
| `POST api/{slug}/place-order` | 500 | 50 → **−2,147,483,597** | written |
| `POST api/{slug}/order-save` | 500 | 50 → **−2,147,483,597** | written |

The order so written carried `product_price = final_price = 21,474,836,470.0` and
`payment_status = 'Paid'`. Controls from the same probe: qty `-5` was refused (*"Your cart contains
an invalid quantity."*) with stock unmoved, and qty `2` completed normally — so the floor works and
only the ceiling was absent. Not a SQLite artefact: `carts.qty`, `products.product_stock` and
`product_variants.stock` are signed integers on MySQL too.

This is the **same defect P01-COMMERCE-SEC-01 closed on the gateway returns** (recorded there as
*"the quantity guard had a floor and no ceiling"*), left open on the three writers that take cash on
delivery. `GuardsGatewayReturn::MAX_SANE_LINE_QUANTITY` existed, and was wired into **none** of
them.

**AND I HAD REFUTED THIS FINDING.** Asked about a sweep's claim of "no quantity pre-flight" on
these handlers, I found their `qty <= 0` refusal and called the claim wrong. It was half right. The
lesson is narrow and worth keeping: *naming a control is not checking its bound.*

**Fixed** with `basketHasUnusableQuantity()` — the shared helper, not a fourth copy of the rule — at
all three writers, each keeping its own refusal wording so no client sees a new response shape.
Removal-checked: each of the three bites its own data-provider case, with positive controls proving
all three writers still complete an ordinary order (stock 50 → 48).

### S36-9 — The `explode()`-before-`empty()` shape, and the one copy that is POST-CAPTURE

S36-5 fixed `ordersave`'s. A completeness critic then established that the ownership pre-flight does
**not** cover this shape — `basketHasForeignProduct()` `continue`s when a variant resolves to no row,
because a missing row is not a provable mismatch — and that **8 reachable copies** remain.

One is materially worse than the rest and is **fixed here**:
`AuthorizeNetPaymnetController:574` sits **after the card capture**, so a line naming a nonexistent
`variant_id` left the shopper **charged with no order row**. The reorder is the same one
`place_order` and `ordersave` carry. **It is not proven by a test, and that is stated rather than
glossed:** reaching that loop requires a successful Authorize.Net capture, so every request that
gets there in this environment dies at the SDK with no credentials and no network.

The remaining seven are **recorded, not changed** — `PaymentService:2378`,
`PaymentController:2174` and `:3465`, `KhaltiPaymnetController:453`, `EasebuzzController:493`,
`PaiementProPaymentController:494`, `CinetPayController:599`. Each produces a 500 rather than a
cross-tenant write, each sits behind an ownership pre-flight that already refuses the mismatched
case, and the residual case (a variant id that resolves to nothing) reaches them only from a cart
row the host-app writers can no longer create.

### S36-10 — The Authorize.Net pre-flight guarded the snapshot; the loop decrements the live cart

**The most important finding of the review of this package, and it is against my own fix.**

S36-3 added an ownership pre-flight before the card capture, reading the basket from the stored
checkout state — which is the only basket available before the charge, and the right one to decide
on there. But the handler then **re-reads the live cart** at `:439` (`Cart::cart_list_cookie()`, the
guest arm) and `:456` (`ApiController::cart_list()`, the authenticated arm), assigns `$products`
from *that*, and the stock loop iterated it with `Product::where('id', $product_id)->first()` —
**no store predicate at all**.

So a cart row added **during the gateway round trip** was never examined: the pre-flight saw a clean
snapshot, the card was captured, and the decrement landed on another merchant's row. The two
vendored module cart writers can still create such a row (`BoostSalesController`,
`FrequentlyProductController` — `Product::find()` unscoped, `variant_id` raw), and legacy rows
already are one. **Latent for a store with neither module active; live for a store with either.**

`NMIPayController` — the sibling card charge — does not have this gap: it assigns
`$products = $storedProducts` (the *same* array its guard checked) **and** keeps a
nullable-tolerant store predicate plus an `empty($Product)` refusal in its loop. This file had
neither half.

**Fixed** by mirroring NMI's shape onto the loop: a nullable-tolerant store predicate
(`store_id = $store->id OR store_id IS NULL`), `$datas` bound to the same row so the plain-product
arm cannot reach past it, and an `empty($Product)` refusal. The refusal is **post-capture** and says
so to the shopper, which is the lesser of two outcomes: better a charge to reconcile than another
merchant's inventory gone.

**What the test proves and what it does not.** `test_the_authorizenet_capture_refuses_a_foreign_basket_before_charging`
drives the pre-charge guard and bites. It cannot cover the loop predicate: reaching that loop needs
a successful Authorize.Net capture, and every request that gets there in this environment dies at
the SDK. Stated here rather than left as an implication of a passing test.

### S36-11 — The admin order API would bill another store's customer

`'customer_id' => 'required|exists:customers,id,status,1'` proves the customer **exists** and
nothing more. `exists:` is a raw query-builder rule, so it bypasses Eloquent — and `Customer` is the
one model on this platform whose `StoreScope` actually filters, which is what makes the bypass easy
to miss. The id then went verbatim into `Order::create` and both `OrderNote::create` calls, so a
merchant could write an order **under their own store naming another store's customer**.

Severity is lower than the stock drain the same handler had: the wallet row follows the order's
store rather than the customer (`OrderObserver`), and the victim cannot see it from their own
storefront because `ApiController::order_list` is store-scoped. So it is cross-tenant data
**linkage**, not disclosure or money. **Fixed anyway** — three lines, and the ownership question is
the same one. Removal-checked.

### S36-12 — `payment_sheet` had the quantity guard and not the ownership one

An asymmetry rather than a hole, and worth closing for a reason that only became true with this
package: `payment_sheet` creates a Stripe **PaymentIntent** and writes no order — `place_order`
writes it later, and `place_order` now **refuses** a basket naming another store's product. Leaving
this unguarded therefore made the **charge-then-refuse** outcome *more* likely than before, not
less: the shopper would be asked to pay for a basket no order writer will accept.

**Fixed** with the shared pre-flight before `StripeMoney::toMinorUnits()` and before any Stripe
call. Removal-checked. No positive control is possible here — a legitimate basket needs real Stripe
keys — and the file says so.

### Three orderings and one translation gap, recorded rather than changed

- **The web cart gate ran after `$ActivityLog->save()`**, so a refused add left an audit row
  claiming "add to cart" with the variant that was rejected, and the API twin had the opposite
  order. **Moved above the activity log.** (The first attempt at that move put the block in
  `cart_remove()`, because "the first `// activity log` in the file" is not the same thing as "this
  method's". Caught by reading the result rather than trusting the edit.)
- **`processOrder`'s pre-flights run after `handleCustomerRegistration()`**, which writes a
  `Customer`, an `ActivityLog` and a `DeliveryAddress`. A refused guest checkout therefore still
  creates an account and an address. Pre-existing — the two guards that were already there share
  the ordering — and not changed here.
- **`PaymentService::getProductStatus`'s guards run after the `bank_transfer` receipt upload**, so a
  refused bank-transfer checkout leaves an uploaded file behind. Pre-existing, not money, not stock.
- **Two new flash strings are absent from `resources/lang/ar.json`** — *"Your cart contains an item
  this store does not sell."* and *"Your cart contains an invalid quantity."* — so an Arabic shopper
  sees English. They reuse keys `processOrder` already emitted untranslated, so the diff widens an
  existing gap rather than opening one. Left to a translation pass rather than guessed at.

### One pre-existing storefront question, flagged because it is cheap to check

`products.default_variant_id` is **written** (`ProductController:819`) and **read**
(`Wishlist.php:49,58,59`) and has **no migration** — a GATE-F-class column. It is only ever written
for `variant_product == 1`, so for a plain product it is NULL, and the product-detail button renders
`variant_id="{{ $product->default_variant_id }}"`, i.e. an **empty string**.

Measured on this container's PHP 8.3.6: `"" != 0` is **`true`**, so
`if (!empty($variant_id) || $variant_id != 0)` — the guest arm's gate at `CartController:276` —
**enters the variant branch for an empty string** and refuses the add with "Product not found.".
`0`, `"0"` and an omitted field are all fine. If production really does render an empty string
there, that is a live guest-storefront break on plain products — **pre-existing, independent of this
package, and not verifiable from here**. The gates this package added use
`!empty($x) && (int) $x !== 0`, which does not have this shape.

### Refuted, and corrected against the reviewer

- **`confirm_order` is not a stock mutator.** The block labelled "Order stock decrease start/end"
  contains no assignment and no `save()`. What it does carry is a cross-tenant **existence probe**
  (`Product::where('id', $product_id)->first()`, no store predicate) — a read, recorded below.
- **The API order writers do have quantity guards — and I was HALF WRONG about it, which S36-8
  below records in full.** A sweep reported "no quantity pre-flight" on `place_order` and
  `ordersave`; I pointed at their inline *"Your cart contains an invalid quantity."* refusal and
  called the finding refuted. The floor is real; **the ceiling was missing**, and a completeness
  critic measured what that costs. Refuting a finding by naming a control is not the same as
  checking what the control covers.
- **A pin I added and then removed.** I read `PaymentService::getProductStatus` taking `slug` and
  `customer_id` from request input and added a pin for both in `processOrder`. The removal check
  showed it was **redundant**: `processBillingInfo()` already merges
  `['customer_id' => $customerId, 'slug' => $store->slug, 'store_id' => $store->id]`
  unconditionally before `processPayment()` is reached. The pin was removed again. The test stays,
  because nothing pinned that existing control, and it bites when that merge is deleted.

### Residuals — recorded, not changed

| site | shape | why not here |
|---|---|---|
| `PosController::store:205` + `ProductController::addToCart:2593` | writes `products.product_stock` to an **absolute** value from a session-held `originalQuantity`, no store predicate | merchant POS surface, different chain; the basket rows are plain arrays keyed `id`/`quantity`, so the shared helper cannot be dropped in unchanged |
| `ProductController::update` (and `WoocomProductController::edit`) | route-model-bound `Product $product` with `isAbleTo('Edit Products')` and **no store predicate** read in the lines inspected | a merchant-admin IDOR family, not the cart→order chain; **unverified** beyond the binding — needs its own package |
| `Order::product_return:660,666` | `+=` restock through unscoped `Product::find` / `ProductVariant::find` | callers not fully traced; restock rather than drain |
| `addtocart`'s six module arms | each carries its own `: $request->variant_id` fall-through | **no WorkDo module loads in any test run** — a change there cannot be proven by removal. The new gate before the write covers them anyway. An independent read of all seven `attribute_id` branches found that **only one is both un-fixed and reachable** — `ApiController:1034-1050`, the WholesaleProduct-active / SizeGuideline-absent arm. Verified here: `if (module_is_active('WholesaleProduct'))` at `:1024` encloses an **identical** check at `:1026`, so that inner condition is always true and its `} else {` at `:1106` — with the `attribute_id` branch at `:1107` inside it — is **dead code**. The same holds for `:1431`. Not changed, for a second reason beyond testability: assigning the paired variant there would move a wholesale store's cart row from `variant_id = 0` to a real variant, which moves the decrement from **product-level to variant-level stock**. That is a behaviour change on a path no test in this repository can exercise |
| `ApiController:1039` (wholesale arm) | the same dead-guard shape as S36-5 — `explode(',', $ProductVariant->variation_option)` before `if (empty($ProductVariant))`, which is nested inside `if ($option == true)` and therefore unreachable | module-gated; a mismatched pair answers 500 there instead of the handler's refusal. The gate before the cart write still stops the row being persisted |
| `Cart.php:736`, `AuthorizeNetPaymnetController:535`, `PaymentService:2335` | the same dead-guard shape as S36-5 | not reachable past the pre-flights now wired ahead of them |
| `PayUPaymentController::getProductStatus` | ~690 lines of order writer, no ownership predicate, `$request->all()` fallback | **unrouted** — one route declaration away from being live; a landmine, not a gap |
| `ApiController.php:654` | 500 on a cart row naming a non-existent variant | see S36-5 |
| `confirm_order:6639` | cross-tenant product **existence probe** | a read, no write; lowest severity on this list |

### Owner decisions this package did not take

- **The NULL `store_id` question is still open** and one query answers it:
  `SELECT COUNT(*) FROM products WHERE store_id IS NULL;` and the same for `product_variants`. The
  hard predicate is now on **five** paths (`add-cart`, `place_order`, `ordersave`, the admin API
  writer, and the gate before the cart write) while the shared helper stays nullable-tolerant.
- **`Product` and `ProductVariant` remain globally unscoped.** What ships is containment at the
  caller, now on nine writers. Adding them to `config/tenancy.php`'s `scoped_models` remains a
  platform-wide compatibility decision.

## S37 — The merchant surface: four cross-tenant writes outside the cart → order chain

**OPEN. Recommended as the next Phase 01 security package.** Found by a completeness critic run
against the P01-COMMERCE-SEC-03 FOLLOW-UP, which was asked what the plan was missing. Three of the
four were **confirmed by execution** in this session (its probe scripts are named at the end of this
section); the fourth is code-verified only. I re-read the two headline sites myself before writing
this and they are as described.

These are **not** the cart → order chain. They are the merchant's own admin surface, and the shape
is the one `Product`'s missing global scope makes available everywhere: a route-model binding or a
bare `find()` on a model that no scope filters, with a *permission* check standing in for an
*ownership* check. `isAbleTo('Edit Products')` answers "may this user edit products" and never "may
this user edit THIS product".

| # | site | what moves | authorization present | measured |
|---|---|---|---|---|
| **S37-1** | `ProductController::update` (`app/Http/Controllers/ProductController.php:965`, `routes/web.php:848` `PUT product/{product}`) | `products.product_stock` from request input (`:1232`, `:1449`), `product_variants.stock` (`:1437`, `:1563`), and the whole catalogue row | `isAbleTo('Edit Products')` only — **no `store_id` comparison anywhere in the method**; the target is a route-model-bound `Product $product` on a model with no global scope | **executed**: a store-A merchant `PUT /product/{store B id}` renamed the victim's product to `HIJACKED` and set `product_stock` 50 → 7 |
| **S37-2** | `OrderController::order_return` (`app/Http/Controllers/OrderController.php:651`, `routes/web.php:1263` `POST order-return`) → `Order::product_return` (`app/Models/Order.php:637`) | `product_variants.stock` **increments** (`:666`), and the victim's `orders.product_json` gains `"return":1` | auth group only — **no permission check and no ownership predicate**; `order_id`, `product_id` and `variant_id` come straight from the request | **executed**: a store-A merchant posting store B's `order_id` moved B's variant stock 50 → 59 and marked B's order returned, then 500'd *after* the writes landed |
| **S37-3** | `PosController::store:205` (`routes/web.php:1087`/`:1096`), fed by `ProductController::addToCart:2597` (`:1092`) and `ProductController::updateCart` (`:1093`) | `products.product_stock` **written to an absolute value** out of the POS session, not decremented | `isAbleTo('Create Pos')` only; both session feeders resolve `Product::find($id)` unscoped, and `updateCart` takes an unbounded `quantity` | **executed**: seeding the POS session with store B's product then posting the sale moved B's `product_stock` 50 → 49 |
| **S37-4** | `WoocomProductController::edit:369-389` (`routes/web.php:928`) | overwrites a foreign `products.product_stock` and then **reassigns `store_id` to the caller's store** | `isAbleTo('Edit Woocommerce Product')`; the `WoocommerceConection` lookup carries **no `store_id` predicate** while its own category sibling (`:326`) and the Shopify twin (`ShopifyProductController:533`) both do | code-verified only — driving it needs a WooCommerce API response |

**Why these were not taken in the follow-up package.** Each needs its own ownership predicate and
its own regression test, and `ProductController` is a ~1,600-line resource controller whose `store`,
`update` and `destroy` paths all share the binding — that is a package, not a drive-by, and the
brief for the follow-up was explicit about not expanding into another rewrite. They are also a
different family from the cart → order chain: no shopper principal is involved, so none of the
shared basket pre-flights applies. **S37-3 in particular cannot reuse `basketHasForeignProduct()`**
— POS rows are plain arrays keyed `id`/`quantity`/`originalquantity`, not the cart-row shape the
helper walks.

**Sequencing note.** S37-1 is the one to take first: it is the simplest predicate, it reaches both
the catalogue and inventory, and `ProductController::addToCart:2597` — S37-3's feeder — is in the
same file.

**One SQLite caveat that makes production MORE exposed than the test database**, recorded because
this project has been bitten by the reverse: the probe found SQLite initially masking S37-1 through
a `NOT NULL` column the handler writes as NULL. MySQL with `strict => false` coerces it, so the
write succeeds there where it failed here. Any test for S37-1 should be verified against a real
MySQL by the pattern in `tests/Support/Security/bin/`.

Two adjacent, lower-severity observations from the same sweep, recorded so the surface is closed
out: `ProductController::updateCart`'s own overflow guard is structurally dead — it compares
`isset(...)`, a **bool**, against a quantity — and `routes/web.php:1100` points at
`ProductController::addToCartVariant`, which **does not exist anywhere in `app/`** (a dead route
that 500s on call, not a security gap).

Probe scripts from the session that measured these, kept out of the repository because a
conditionally-skipped test would break the declared-skip set:
`QtyCeilingProbeTest.php`, `GapProbeTest.php`, `ProductUpdateProbeTest.php`,
`OrderReturnProbeTest.php` — run with
`vendor/bin/phpunit --configuration phpunit.xml <abs path>`, each extending
`Tests\Feature\Security\SecurityTestCase` with `RefreshDatabase`.

---

## S38 — What P1-INV-07 and its adversarial review left behind on the catalogue surface

**OPEN.** P1-INV-01 closed `ProductController::update`, `edit` and `show`; P1-INV-07 closed the
three sibling routes that took their target from request input instead of a route binding
(`file_delete`, `product_attribute_delete`, `attribute_combination_edit`). Writing and reviewing
those two packages surfaced the rows below. **Every row here was re-verified in this session by
reading the code named — the provenance of each claim is stated, and where a review's claim did
not survive verification that is said too.**

### S38-1 — `CategoryController::destroy` destroys another store's product media, variants and carts

**CRITICAL. Verified by reading the whole call chain. The most severe row on this queue's
merchant surface, and more severe than the three sites P1-INV-07 closed.**

`app/Http/Controllers/CategoryController.php:271` (`Route::resource('category', …)`,
`routes/web.php:846`, so `DELETE /category/{id}`):

```php
if (auth()->user() && auth()->user()->isAbleTo('Delete Product Category')) {
    $mainCategory = Category::find($id);          // NO store predicate
    ...
    $hasChild = Category::where('parent_id', $category->id)
                        ->where('store_id', getCurrentStore())->exists();
    if ($hasChild) { return ...; }                 // the ONLY getCurrentStore() in the method
    Category::categoryImageDelete($category);
    $products = $mainCategory->product_details;    // hasMany(Product::class, 'category_id')
    foreach ($products as $product) {
        Product::productImageDelete($product);
    }
    ...
    $category->delete();
}
```

`Category` is **not** in `config/tenancy.php`'s `scoped_models` (`grep -c "Category::class"
config/tenancy.php` → **0**) and the model registers no global scope, so `Category::find($id)`
reaches every tenant's categories. `product_details` is a plain
`hasMany(Product::class, 'category_id', 'id')` (`app/Models/Category.php:43`) on a model that is
likewise unscoped, so the loop iterates the **victim store's** products.

`Product::productImageDelete` (`app/Models/Product.php:628-677`) then, per product:

- unlinks every `product_images.image_path` from disk (`:650`),
- passes the merged list — image paths plus `cover_image_path`, `downloadable_product` and the
  `preview_content` video — to `Utility::changeproductStorageLimit`, which **also** unlinks every
  one (`app/Models/Utility.php:878-884`),
- deletes all `product_images` rows (`:655`) and all `product_variants` rows **and the stock they
  carry** (`:657`) — neither model carries `BelongsToStore` and neither is allowlisted, so nothing
  narrows those two deletes,
- unlinks the `other_description_api` photo-upload files (`:669`).

**CORRECTION, made before this row was acted on.** The first draft of this entry also claimed the
`Cart::where('product_id', …)->delete()` at `:659` destroys the victim's shoppers' live baskets.
**It does not, in this request context.** `App\Models\Cart` carries `BelongsToStore`
(`app/Models/Cart.php:17`) *and* is allowlisted in `config/tenancy.php:298`, so in an authenticated
merchant web request — where `getCurrentStore()` resolves to the attacker's store — `StoreScope`
compiles `AND store_id = <attacker>` and the delete matches **no victim row**. The one scoped model
in the chain is the one thing that holds.

It holds *only* because a tenant resolves. `StoreScope` **fails open** on a null tenant by design
(`null_tenant => 'open'`), so the same `productImageDelete()` reached from a context where
`getCurrentStore()` returns null — a queued job, a scheduled command, or an operator whose
`users.current_store` is unset — deletes **every** store's cart rows for that product. That is a
second, narrower exposure on the same line, and it is the reason the fix belongs in `destroy()`'s
own predicate rather than in trusting the scope.

**The one store predicate in the method makes the attack more likely to proceed, not less.** The
`$hasChild` guard asks whether any category with `parent_id = <victim category>` belongs to
**`getCurrentStore()`** — the attacker's store. For a foreign category that is false even when the
victim's category has children, so the guard waves it through and the victim's child categories
are orphaned.

`Utility::changeproductStorageLimit` also debits **the attacker's** `storage_limit`
(`User::find(\Auth::user()->creatorId())`, `:874-877`) — the one artefact the attack leaves on the
attacker's own side, and a detection signal worth keeping in mind for the server.

**SECOND CORRECTION, and it raises the severity.** The first draft of this entry said "the
`products` rows themselves survive; everything that makes them a sellable catalogue does not."
**They do not survive.** `products.category_id` is declared `ON DELETE CASCADE` against
`categories.id` (`database/migrations/2023_12_25_*_create_products_table.php:55`), and
`product_variants.product_id` cascades again off `products`, so `$category->delete()` removes
**every product row in the category and every variant under it, for every store**, below the
application entirely — no controller predicate can reach it.

Found by a regression test failing: the P1-INV-08 test asserting that a foreign product sharing
the caller's category kept its rows went red, and the row was gone. Verified on the real engine
rather than inferred from SQLite —
`tests/Support/Security/bin/verify-category-product-cascade-on-mysql.php`, **3 checks, 0 failures
on MySQL 8.0.46**, including `information_schema.REFERENTIAL_CONSTRAINTS` reporting
`DELETE_RULE = CASCADE`.

So the pre-fix exposure was not "a store's catalogue media and variants" but **a store's
catalogue**. Disk unlinking is still the part that cannot be undone from the application, which
is why this outranks a cross-tenant *write*; the cascade is why it outranks the first draft of
itself.

The required predicate is the one `ProductController` now carries: platform operator exempt via
`UserPolicy::PLATFORM_ADMIN`, then `getCurrentStore()` against `$category->store_id` — before
`categoryImageDelete`, which is the first destructive call. The same controller's `update`/`edit`/
`show` need checking in the same pass; this row records `destroy` because it is the one verified.

### S38-2 — stored media paths are expanded as GLOB PATTERNS and every match is unlinked

**OPEN, containment missing, reachability latent. Verified by reading; the review's severity
claim did not survive verification and the honest reading is recorded here instead.**

`Utility::changeproductStorageLimit` (`app/Models/Utility.php:857-886`) does not treat a stored
path as a path:

```php
$pattern = base_path($file_p);
$files1  = \File::glob($pattern);          // the stored value is a GLOB PATTERN
...
foreach ($files as $file) { \File::delete($file); }   // every match, no containment
```

There is no uploads-root containment on any of it, and the same absence holds in the five
read-and-delete loops over `other_description_api`
(`app/Models/Product.php:667`, `app/Http/Controllers/ProductController.php:1817`,
`app/Http/Controllers/Api/DashboardController.php:2183`, and two vendored copies in
`packages/workdo/AuctionProduct` and `packages/workdo/DigitalProduct`), each of which does
`\File::delete(base_path($value['value']))` on a value out of stored JSON.

**What an adversarial review claimed and what is actually true.** The review called this a
merchant-reachable arbitrary file delete. It is not, on the code in this repository:

- `other_description_api` has **no writer anywhere in this repository** — five sites read and
  delete from it, zero write it (`grep -rn other_description app/ resources/ packages/`). It can
  only hold a value through a DB-level write or an upstream module version not vendored here.
- `image_path`, `cover_image_path`, `downloadable_product` and `preview_content` are written from
  `$path['url']` returned by the upload helper (`ProductController.php:240`, `:422`, `:527`,
  `:699`, `:1131`, `:1294`) — server-generated, not raw request input.

So the primitive exists and the containment is missing, but no shipped host write path plants a
traversing or globbing value in it today. It is queued as **hardening with a latent trigger**, not
as a live breach, and the fix is cheap: `ProductController::isInsideUploadsRoot()` already exists
from P1-INV-07 and wants promoting to a shared helper that both `productImageDelete` and
`changeproductStorageLimit` call. A glob metacharacter in a stored path should additionally be
refused rather than expanded.

### S38-3 — the cluster P1-INV-07 found around it, each verified, none a cross-tenant breach

| # | site | what is wrong | verified how |
|---|---|---|---|
| **S38-3a** | `Api\DashboardController::AddVariant` (`:1695`), routed `POST api/admin/addvariant` (`routes/api.php:206`, `custom.auth`) | writes `$ProductVariant->name` and `->type`; `product_variants` has **neither column** (`2024_01_01_104858_create_product_variants_table.php`), and the NOT-NULL `product_id` and `sku` are never set — **the endpoint cannot succeed for anyone**. Same class as CLAUDE.md's `delivery_boys` note | read the migration against the handler |
| **S38-3b** | `routes/web.php:906` `PUT products/sku_combination/edit` → `ProductController::sku_combination_edit` | **the method does not exist** (`grep -rn "function sku_combination_edit" app/` → 0 matches). A live route that 500s on call. Not a security gap; the twin of `addToCartVariant` recorded in S36 | grep, zero matches |
| **S38-3c** | `ProductController::attribute_combination:1879` | `ProductAttributeOption::where('id', $id)->first()` carries **no store predicate**, while its own sibling `ProductAttributeOptionController.php:56` does (`->where('store_id', $store)`). Leaks another tenant's option **terms** into the combination builder | read both, side by side |
| **S38-3d** | `Api\DashboardController::DeleteProduct_backup:2143` | unrouted dead code (`grep -rn DeleteProduct_backup routes/` → 0) carrying no ownership guard. Harmless until something routes it; a removal candidate, not a fix | grep, zero matches |
| **S38-3e** | `file_delete`, `product_attribute_delete`, `attribute_combination_edit` | none of the three calls `isAbleTo` **at all** — P1-INV-07 gave them ownership, not permission. Adding a permission limb is correct but is **not** a substitute: per CLAUDE.md there is no super-admin bypass, so an `isAbleTo`-only limb would refuse the platform operator | counted occurrences in all three method bodies: 0 |
| **S38-3f** | `StoreController` store-deletion loop | an adversarial review attributed a no-op in this loop to the P1-INV-01 commit. **It did not come from there**: `git log -S "A super admin is exempt, matching OrderPolicy::allow"` returns **`e71da912`**, a pre-existing commit. Recorded so the next reader does not re-litigate it | `git log -S` |

**S38-3e is the general shape worth carrying forward.** The missing-permission and
missing-ownership limbs are different defects with different failure modes, and in this codebase
the permission limb is the one that can lock out the operator. Any pass that adds `isAbleTo` to
these routes must add the `PLATFORM_ADMIN` exemption in the same edit, or it will close a hole and
open an outage.

### S38-4 — unvalidated `parent_id` leaks every store's category names, and admits a cycle that fatals

**OPEN, LIVE, verified by reading all three files. Raised as P1-INV-11.** Found by the adversarial
review of P1-INV-08, and P1-INV-08's guard cannot see it: the row being written is the caller's
**own**, so `categoryIsOutsideCurrentStore()` correctly returns false.

`CategoryController` writes `parent_id` straight from request input in both writers — `:123`
(`store`) and `:281` (`update`), identical lines — with no `exists` rule and no store predicate.
The only validation rule in either method is `'name' => 'required'` (`:58`, `:209`). The value is
then rendered back through an **unscoped** relation: `Category::parent_category()` is a plain
`belongsTo` (`app/Models/Category.php:111-114`) and `CategoryDataTable` renders
`optional($category->parent_category)->name` (`:58-60`).

So merchant A PUTs **their own** category with `parent_id=N`, loads `/category`, and the "Parent
Category" column shows **store B's** category name. Incrementing `N` enumerates the platform's
whole catalogue taxonomy, one name per request. The create/edit dropdown *is* store-scoped, so the
UI never offers a foreign id — obscurity, not a control, and the POST is hand-craftable.

The same missing rule admits `parent_id = <own id>` or a two-category cycle, which makes
`buildCategoryTree` (`app/Helper/helper.php:2538-2551`, unconditional recursion, no visited-set)
recurse until it fatals — a 500 on the caller's own create and edit pages. Self-inflicted and
own-store only, but it is a denial of the merchant's own screens from the same unvalidated write.

### S38-5 — what the adversarial review of P1-INV-08 got wrong, and what my own fix got wrong

Recorded because both directions are worth keeping.

**My fix degraded a CI ratchet, and the review caught it.** The P1-INV-08 docblock in
`CategoryController::destroy` quoted `isAbleTo('Delete Product Category')` verbatim, inside the
1200-byte window `BulkDeleteAuthorizationTest:303-309` greps for exactly that string. **Measured:
two matches in the window, and with the controller's real permission check deleted the suite still
passed 23/23** — the ratchet had become satisfiable by prose, which is precisely the failure
CLAUDE.md names. Fixed by rewording, and the ratchet is now **strengthened**: it requires exactly
one occurrence and rejects a comment-only match, so it fails when the real check is removed. A
sweep of all 21 ratcheted controllers found no other instance.

**My fix also stated a false reason.** Both new files said `Category` "carries no
`BelongsToStore`". It does (`app/Models/Category.php:14`). The operative fact is absence from
`scoped_models` — the other half of the two-part check CLAUDE.md insists on, and the half that
decides, since `StoreScope::isActiveFor()` short-circuits for an unlisted model.

**And the review was wrong about one thing, usefully.** It called S38-2's missing containment a
merchant-reachable arbitrary file delete; verification found `other_description_api` has five
readers and zero writers in this repository, so it is hardening with a latent trigger. It was also
right that my child-guard removal check needed **two** mutations and therefore did not cover the
guard's store value — closed by adding the platform-operator tests, since the operator is the only
principal who reaches that line with the two store values differing (`getCurrentStore()` returns a
hardcoded `1` for a `super admin` with no `current_store`, `app/Helper/helper.php:403-404`).

---

## S39 — What the two module guards found, and what two adversarial reviews found in them

Fourteen rows. Four are defects the guards closed, four are defects the REVIEWS found IN THE
GUARDS' OWN WORK (a fall-through that was a live cross-tenant write, a false seeding premise, a
vacuous test, and two censuses that could not see what they claimed to cover), three are open
and raised as issues, and one is a review claim that did not survive verification — kept
because the refutation is the useful part. The raw, untriaged completeness-review output is at
`docs/security/P1-INV-14-17-COMPLETENESS-REVIEW-RAW.md`.

**The route census is the point of this row.** P1-INV-14 (#57) was filed as *one* unauthenticated
route, found by following `Order::product_return`'s callers. A census of **both** modules' route
files — every `POST`, printed with the middleware group enclosing it — found **six** admin
mutations in `['web']`-only groups. Following the first route found would have shipped a guard
covering a sixth of the exposure, and the census is now a ratchet
(`test_every_module_declared_order_mutation_post_is_guarded_or_exempt`) rather than a one-time read.

### S39-1 — six anonymous admin mutations, two driving the host `Order` model — **CLOSED**

| route | handler | writes |
|---|---|---|
| `lottery-order-status-change/{id}` | `App\Models\Order::order_status_change` | `delivered_status`, `payment_status='Unpaid'` → `OrderObserver` → `OrderFinancialService` |
| `lottery-order-return` | `App\Models\Order::product_return` | stock |
| `auction-order-status-change/{id}` | `AuctionProductOrder::order_status_change` | `delivered_status` |
| `auction-order-return` | `AuctionProductOrder::product_return` | stock |
| `auction-order-assign` | `AuctionProductOrder::find` → `save` | `delivery_id` |
| `update-auction-status` | `AuctionProductSetting::findOrFail` → `save` | `is_active` |

**FIVE OF THE SIX ARE LIVE; `auction-order-return` IS LATENT** — its handler iterates
`product_json` as a list of lines, but that column's only writer on an auction order
(`AuctionProduct/src/app/Console/Auction.php:97`) stores a single product *object*, so the loop
reads an offset off a scalar and the route 500s before any write for every principal. Corrected
after the adversarial review; guarding an anonymous entry point to a broken handler is still right,
calling it a live hole was not.

Closed by `App\Http\Middleware\ModuleOrderMutationGuard`, appended to the `web` group. Full
argument and evidence in `docs/security/P1-INV-14-MODULE-ORDER-MUTATION-VERIFICATION.md`.

### S39-2 — "the module is disabled" is not containment, a second time

`PackageServiceProvider::loadDynamicAddons()` does `AddOnManager::pluck('module')` with **no
`is_enable` predicate**, so a row with the flag off still registers every one of a module's routes.
This is the same finding S1/#42 established for `BackupRestore`, now reconfirmed on two more
modules. **The server read `SELECT module, is_enable FROM add_on_managers WHERE module IN
('LotteryPrizes','AuctionProduct');` batches into #42's three read-only checks** — it tells us the
severity that *was*, not whether the guard is needed.

### S39-3 — `AuctionProductOrder::product_return`: four defects the guard cannot close — **OPEN, #61 (LATENT)**

Raised as **P1-INV-16**. The guard authorises the row the request *names*; it cannot authorise a
second row the vendored handler picks out of the first one's data.
`packages/workdo/AuctionProduct/src/app/Models/AuctionProductOrder.php:285`:

1. **The restock pairs to nothing.** `Product::find($product_id)` (`:306`) and
   `ProductVariant::where('product_id', …)` (`:312`) carry no store predicate, and `Product` has
   **no effective global scope** — absent from `config/tenancy.php`'s `scoped_models`, so
   `StoreScope::isActiveFor()` short-circuits. The variant path does a real stock write. Bounded by
   what an auction order's `product_json` can hold, which is the open question #61 names first.
2. **No replay guard.** `:350` recomputes `final_price` from the already-reduced total, so each call
   subtracts again — the defect measured on the host model in P1-INV-02, where a `continue`-style
   guard held the stock but `final_price` went 10.00 → **−80.00**.
3. **`$product` is shadowed** at `:306`, so `:308` reads `qty` off the `Product` *model*. There is
   no `products.qty` column (verified against every `*products*table*` migration), so the
   non-variant restock is `+= null` and the stock never moves while the row is still saved. **This
   masks defect 1 on that path**, so a reproduction driving only `variant_id = 0` will report "no
   cross-tenant write" and be wrong about the variant path.
4. **No null guard** on the `find()`. No longer reachable from `auction-order-return` now that the
   guard refuses an id with no row; still reachable from any other caller.

The **lottery** routes are unaffected: they call the host `Order::product_return`, which P1-INV-02
hardened with both a store anchor and a replay guard.

**LATENT, and the reason matters for whoever fixes it.** Defects 1 and 2 sit behind a loop that
never runs: `product_json` on an auction order holds a single product *object*, not a list of
lines, so `foreach ($product_json as $key => $product)` at `:301` yields scalars and
`$product['product_id']` is an offset read on one — a 500 before any write. So the first question
on #61 is no longer "can `product_json` reference a foreign product" but **"does fixing the loop
shape arm the unscoped restock?"** Any fix must land the store predicate *before or with* the
loop-shape fix, or correcting the obvious bug turns on the cross-tenant one.

### S39-4 — the "unseeded permission" premise was FALSE, and it cost a shipped gap — **CLOSED**

**This row originally said the opposite**, and it is kept in corrected form rather than quietly
rewritten, because the mistake is the instructive part.

The claim was that `Manage Auction Product Order` and `Manage Auction Product` are absent from
`Utility::addNewData()` — true — and that `addNewData()` is therefore *the only* mechanism creating
`Permission` rows — **false**. Each vendored module ships its own seeder, and the enable path runs
it:

- `packages/workdo/AuctionProduct/src/database/seeders/PermissionTableSeeder.php:19-51` creates all
  ten auction permissions and grants every one to the global `admin` role.
- `AuctionProductDatabaseSeeder.php:19` calls it; `app/Console/Commands/PackageSeed.php:75-83`
  resolves it; `ModuleController` runs `Artisan::call('package:seed ' . $name)` at `:131`, `:151`,
  `:162`, `:182`.
- **Measured** against a throwaway database: 0 rows before, 2 after, merchant `admin` role holds
  both.

**What it cost.** The guard's first commit left a within-tenant privilege gap on the strength of
it: any authenticated principal whose `current_store` matched the row — staff on a role that
deliberately *lacks* the permission included — could cancel, confirm and return auction orders,
reassign the driver and toggle auction settings. Found by the adversarial review, not by the test
that "verified" the premise, which scanned only `app/Models/Utility.php`.

**The general lesson, and it has now bitten twice in this queue:** a scan of one file answers a
question about that file, never about the application. The comment-stripping was correct and
irrelevant — the file simply was not where the answer lived.

**Fixed.** The permission limb is now enforced for all six targets whenever a `permissions` row of
that name exists — conditional rather than hard, because a hard gate on a missing name is the
permanent 403 `FinancePermission` records, and a missing row is a real state for a module enabled
before its seeder existed. With no row the **ownership** limb still refuses every other tenant's
row, asserted in both directions.

Whether a merchant's staff *should* hold a money-adjacent auction power is still an owner decision
— the same reasoning `app/Models/Utility.php:2866-2876` applies to the finance six. What changed is
that the question is now asked.

### S39-5 — a present-but-unusable id fell through to the route segment — **CLOSED**

`ModuleOrderMutationGuard::resolveId()` required `is_numeric()` in both of its loops, so `id=5abc`
was skipped by the body loop and the route-parameter loop authorised the caller's **own** order
while the handler passed the raw string on. **Measured on MySQL 8.0.46**
(`tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php`): `'5abc'` and
`'5 OR 1=1'` both resolve to row 5 — another store's — while the old predicate authorised 42.

**SQLite does not coerce**, so the Feature suite was structurally blind to it in both directions:
the attack no-ops there and a green suite proves nothing. The same engine-hiding as P01-SEC-13.
Found by the adversarial review *after* 23 green tests and a passing gate. Fixed: presence in the
input hands a key the decision, and `filter_var(FILTER_VALIDATE_INT)` decides whether it is usable.

### S39-6 — one id, two tables: the auction status change leaks host-order PII — **OPEN, #62**

`AuctionOrdersController:230` calls `Order::order_detail($request->id)` — the **host** model — with
the id this guard authorised against `auction_product_orders`. Independent id sequences, so
`orders.id = X` is usually another tenant's. `Utility::webhook()` resolves the destination by the
caller's own `store_id`, so the merchant configures where it goes, and `order_detail()` returns
billing and delivery name, address, email and phone.

The P1-ORD-04 shape in **read** form. Not closable by widening the guard: requiring `orders.id = X`
to be own-store would false-deny on every id collision across the two sequences, which is frequent.
The fix belongs where the leak is.

### S39-7 — four cross-tenant writes behind `Route::resource`, and the census that could not see them — **CLOSED**

Both modules' resource groups carry `auth` + `PlanModuleCheck` and **no store predicate**:

| route | defect |
|---|---|
| `auctionproduct` `update():861` / `destroy():1509` | reach the **host `Product`** by bare id — cross-tenant catalogue edit and delete, with images; gated only on `Edit`/`Delete Auction Product`, which the module seeder grants every merchant |
| `auctionsetting` `update():115` | **no permission at all**, and writes `store_id = getCurrentStore()` — it *moves* another store's row into the caller's tenant |
| `auctionsetting` `destroy():140` | no permission at all |
| `auctionorder` `destroy():105` | no permission at all (`update():95` is a `//` stub) |

The `auctionproduct` pair is P1-INV-01's defect reproduced in vendored code, and is the most severe
of the four.

**The second half of the row is the census.** P1-INV-14's ratchet promised that "no route can be
added to an existing mutation handler without someone answering for it here" — too broad: its regex
needs a `Route::post(...)` line, and `Route::resource` has none. It now covers `Route::resource`
for these two modules, with all six declarations carrying reasoned entries. Widening the method list
platform-wide was **tried and reverted**: it matched generic CRUD in seventeen other modules — 19
extra uris, each needing an exemption that would say nothing. The platform-wide sweep stays #63's
acceptance criterion rather than being faked by a list of empty exemptions.

**CLOSED** by `App\Http\Middleware\ModuleResourceOwnershipGuard`, appended to the `web` group, with
the ownership predicate now SHARED with P1-INV-14's guard rather than copied
(`app/Http/Middleware/Concerns/ComparesRowOwnership.php`) — that guard shipped with two defects in
its own predicate, each found by review rather than by a test, so a second copy would have inherited
whichever version was current that day. Full argument and evidence in
`docs/security/P1-INV-17-MODULE-RESOURCE-OWNERSHIP-VERIFICATION.md`.

Four of the five writes reproduced by execution. **The fifth could not be reproduced in the suite at
all:** `AuctionProductController::update` writes NULL into `products.trending` and
`products.track_stock` from unposted request fields, so SQLite REFUSES the statement while
production MySQL (`strict => false`, `config/database.php:55`) COERCES and the cross-tenant product
rename LANDS. Measured on MySQL 8.0.46. **Production is more exposed than the test database** — the
same direction and class as P1-INV-01 / S37-1.

Two further things are worth carrying forward from the work:

- **`PlanModuleCheck` is a real gate here**, and omitting it from a fixture makes a test pass
  without reaching a handler. Three of five cases "passed" that way on the first run. Entitlement
  is a `user_active_modules` row keyed on the OWNER (`activated_modules_for_owner`,
  `app/Helper/helper.php:1360-1384`).
- **A validator is not an ownership control.** The product-update case was refused by
  `category_id required` and looked green. All four required fields are attacker-supplied.

**Still open from this row:** the `show()` reads on the three resources were not analysed, and the
other 68 modules' `Route::resource` declarations remain #63's sweep.

---

### S39-9 — a VACUOUS test on the most severe entry of the P1-INV-17 guard — **CLOSED**

The one test covering `PUT auctionproduct/{auctionproduct}` — the entry on the **host `products`
table**, i.e. the cross-tenant catalogue edit — asserted only that `name` and `store_id` were
unchanged and that the response was not a 500. **All three pass with the guard deleted.** Measured
by removal check:

1. the handler writes NULL into `products.trending` and `products.track_stock`, and SQLite refuses
   that UPDATE, so `name` is unchanged either way;
2. the handler never writes `store_id`, so that assertion could not move;
3. the body is wrapped in `catch (\Exception $e) { return $msg; }`
   (`AuctionProductController.php:1494-1497`) and `QueryException` **is** an `Exception` — so the
   failure is swallowed and the response is **HTTP 200**, which is why even the not-500 assertion
   passed.

**And two of my own docblocks already claimed the test asserted 403.** The prose described the
test I meant to write; the test did not do it. Found by the second adversarial review, which
quoted my own docblocks back at me.

This is the shape CLAUDE.md records — "a test asserting 'the row didn't change' passes when the
endpoint 500s for everyone" — with a new variant worth naming: **a handler that catches its own
exception turns a crash into a 200, so even a not-500 assertion is not a liveness check.** The
general rule this queue should carry: on a surface where the engine or a `catch` can absorb the
write, assert the REFUSAL, never the row.

Fixed by asserting the 403; the fix was verified to bite.

### S39-10 — two ratchet holes that would have let the exposure return silently — **CLOSED**

Both found by the second review, both latent, both cheap:

- **The census was anchored on the uri.** `^(PUT|PATCH|DELETE) (auctionproduct|...)/ ` could not see
  a prefixed declaration of the same controllers — and this module's own `RouteServiceProvider`
  already loads a second route file under `prefix('api')`, so a prefixed resource is not
  hypothetical. Re-keyed on `$route->getActionName()`, which catches a prefix, **a second uri on
  the same handler** (the class `OrderDocumentAccessGuard`'s `order/{order}` entry records) and a
  rename, with a reachability assertion so a controller rename cannot make the scan vacuous.
  Verified by adding a temporary prefixed declaration: all three of its methods were reported.
- **The anonymous limb's premise was unpinned.** The guard's docblock keeps that limb because "the
  group has `auth` today" is a property of a vendored file upstream may change — *which is exactly
  how the sibling guard's six routes came to be anonymous* — and nothing asserted it. The
  route-table test now requires `Authenticate` in every target's stack. Verified by temporarily
  stripping `auth` from the module group.

Generalisable: **a census keyed on how a uri is spelled cannot see the declaration nobody thought
of.** That is the third time this queue has recorded it (uri words for order documents, `Route::post`
lines for module mutations, now the uri prefix), and each time the fix was to key on the handler
instead.

### S39-11 — two LIVE cross-tenant READS on the auction `edit` forms, which the guard's own disclosure did not reach — **CLOSED**

`Route::resource` registers `edit` at a **different uri** — `<base>/{param}/edit` — so
`ModuleResourceOwnershipGuard`'s disclosure sentence ("every other method on the same uri passes
through untouched … so nobody reads this guard as covering the resources' reads") was true and
useless: it could not reach `edit`, and the only read it named was the harmless `show()` stub.

Two of the three `edit()` handlers load the target by bare id with **no permission check anywhere in
the method**:

- `AuctionProductController::edit:823-853` — `Product::find($id)`, then `Form::model($product, …)`.
  **Measured** on a merchant holding NO auction permission, with only the plan entitlement: HTTP 200
  carrying another store's `name`, `slug`, `price`, `sale_price`, `description`, `specification`,
  `detail`, `custom_field` JSON, stock posture, and the file paths of its cover image, downloadable
  product and preview asset. Not leaked, stated so the finding is not overstated: the gallery
  (`ProductImage` is store-filtered at `:836`) and `product_stock` (its block is conditional).
- `AuctionProductSettingController::edit:92-107` — `AuctionProductSetting::find($id)`; name and all
  four date/time fields rendered.

**The asymmetry is the shape of the hole:** `Edit Auction Product` **is** checked by `update()` on
the identical uri, and the module's UI gates the Edit *button* on it. So the write was gated and the
read was not — the opposite of what a reviewer comparing the two methods would assume.

Reproduced **four times independently**: the review's own probe, two refuters who each wrote their
own, and the regression test, which fails with HTTP 200 and the victim's name when the guard is
removed.

`auctionorder/{id}/edit` is deliberately not a target: its handler reads no model and renders a view
that does not exist, so it is a 500 for everyone. No row read, so nothing to leak.

**No false denial**, and this was checked rather than assumed. Both buttons are gated by Laratrust's
`@permission`, which resolves through `hasPermission()` and **not** through Laravel's Gate — so it is
unaffected by this project's `permissions_as_gates => false`, unlike `@can`. Every non-operator who
legitimately reaches these screens already holds the name. The one principal a bare permission gate
*would* lock out is the platform operator, for whom `isAbleTo()` is false; the shared trait checks
the operator first, and a test now says so.

### S39-13 — `POST lotteryresult` forges PAID host orders and plants rows in the victim's tenant — **OPEN, #64**

`LotteryResultController::store():66-193`, reachable from `Route::resource('lotteryresult', …)`, with
**no permission check** (only `index():32` checks `Manage Lottery Winner`). It takes `selected_codes`
from request input, resolves `PurchaseTicket::whereIn('lottery_code', …)` with **no store
predicate**, then per ticket inserts a **host `App\Models\Order`** carrying the *victim's* product id
and ticket price with `payment_status => 'Paid'` and `store_id =>` the *attacker's* store — and
`LotteryCustomer::insert()`s rows **into the victim's tenant**, bypassing model events and every
global scope. The inverse of the usual defect: not reading another tenant's data but planting rows
in it.

**Neither guard's census could have produced it, and that is my own mistake.** P1-INV-17's stated
scope was "every `update()` and `destroy()` body behind the six `Route::resource` declarations" —
`store()` was never in scope, and the write-up's "What is NOT closed" named only `show()`. Worse,
the census exemption read *"stub: `update():221` and `destroy():231` are both `//`"* — **true of
those two methods and falsely reassuring about the resource**, because the dangerous method is
`store()`.

**A new failure mode for this queue's censuses, and it is the fourth:** uri words (order documents),
`Route::post` lines (module mutations), the uri prefix (resource mutations), and now **an exemption
scoped to the methods somebody happened to look at.** An exemption that is true about what it says
and silent about what matters reads as coverage. All three lottery entries now state which methods
were read and which were not.

### S39-14 — a customer can forge an auction bid attributed to another customer on another store's product — **OPEN, #65**

`POST /{storeSlug}/product-bid` (`['web']`-only group; measured `gatherMiddleware()` is literally
`["web"]`) →`addProductbidamount:1580-1608`. It checks `auth('customers')->user()` for **presence**
at `:1582` and then discards the principal: `product_id`, `bid_amount`, `customer_id` **and**
`store_id` all come from request input, and the view posts the last two as **hidden fields**.
`AuctionProductBid` has no scope and is absent from `scoped_models`; `max('bid_amount')` at `:1568`
and `:1635` carries no store predicate. Verified by execution by three independent agents.

**The purest form of the pattern CLAUDE.md names as the most productive in this codebase** — *a
presence test mistaken for an identity test.*

Two corrections to the original claim, both verified: it is **not anonymous** (a customer account on
any store is required, trivially obtained but not an open endpoint), and the order-creation limb is
**latent** — `auction:order` is an unscheduled Artisan command. The live consequence is bid poisoning
and forged attribution; scheduling that command arms the money write.

### S39-12 — a review claim that did NOT survive verification, recorded because the refutation is the useful part

**Claim:** `users.type` is written from a role name, and the only protection against a reserved
spelling is `Rule::notIn(RolePolicy::RESERVED_NAMES)`, which is **case-sensitive** — so a merchant
could create a role named `sUper admin`, assign it, and mint a platform operator for every
normalised comparison in the codebase.

**Refuted, by execution.** The claim's first half is correct: `RoleController.php:124` and `:254` use
`Rule::notIn`, which `ValidatesAttributes.php:1463` implements as a plain `in_array` with no case
folding, so `sUper admin` **is** a creatable role name in a merchant's own tenant. But that is not
the only protection. **Both** writers of `users.type` carry a second, *normalised* refusal at the
assignment site:

- `UserController.php:147` (store), seven lines above the `:154` write
- `UserController.php:302` (update), three lines above the `:305` write

both `in_array(strtolower(trim((string) $role->name)), RolePolicy::RESERVED_NAMES, true)` →
*"That role cannot be assigned."* That is the **identical expression every normalised consumer
uses**, so the control is coextensive with the consumer predicate rather than a case-sensitive
denylist. A probe over the live constant confirmed every candidate spelling — `' super admin'`,
`'sUper admin'`, `'SUPER ADMIN'`, tab-separated variants — is refused at assignment.

**The claim's chain stopped one step short:** it established that a reserved spelling can become a
ROLE NAME, then asserted without checking that it becomes a `users.type`.

**What is still worth knowing**, and is hardening rather than a defect: `RESERVED_NAMES`' five
mixed-case entries are dead weight for the `UserController` check, which lowercases first and can
only ever match the lowercase entries. They exist solely to give the case-sensitive validator a few
extra spellings — and covering five of unbounded case permutations is not coverage.
`RolePolicy.php:66-68` states that design intent explicitly. Belongs to the role/user workstream.

### S39-8 — the raw-query inventory's glob was not recursive, and nearly lost a site — **CLOSED**

Moving the shared ownership predicate into `app/Http/Middleware/Concerns/` took its `DB::table()`
read with it, and `OwnedPathRawQueryInventoryTest`'s glob was `app/Http/Middleware/*.php` — **not
recursive**. The raw query would have left the inventory silently, which is exactly the escape that
file exists to prevent. **The site count caught it** (11 → 10) rather than anything noticing the
directory. The glob now covers subdirectories, the trait is classified `'comparison'` with its five
controls asserted there, and a new assertion requires both module guards to still
`use ComparesRowOwnership`.

Worth generalising: an inventory keyed on a flat glob answers a question about that directory, not
about the codebase. It is the same shape as S39-4's "a scan of one file answers a question about
that file".


---

## S40 — The storefront visit log wrote every request's body and headers to the database — **CLOSED by P1-SEC-13; the rows already written are P1-OWN-08 (BLOCKED, owner)**

*Found 2026-10-21 while chasing an unexplained HTTP 500 in a P1-ORD-07 test. Not found by any census: nothing
in the tenancy or authorization sweeps looks at what a page-view counter persists.*

`App\Http\Middleware\ActiveTheme::handle` calls `visitor()->visit($store)` on every request whose first path
segment is an active, themed store's slug. `Shetabit\Visitor\Visitor::visit()` persists `prepareLog()`, which
includes `'request' => $request->all()` and `'headers' => $request->headers->all()`. The package's only filter,
`config/visitor.php` `except => ['login', 'register']`, is matched with `Request::is($path)` — the **bare** path
`login`, never `{storeSlug}/login`. So it excluded nothing on a storefront.

**EXECUTED (SQLite test database, this tree):** `POST /{slug}/login` with a customer's email and password and a
`Cookie` header left one `shetabit_visits` row whose `request` column held the plaintext password and whose
`headers` column held the Cookie value. Route membership was executed for `{storeSlug?}/get-payment-NMI`
(`nmi_card_number`, `nmi_cvv_code`) and `{storeSlug?}/authorizenet-status` (`cardNumber`, `cvv`) — the field names
are READ in the controllers; the persistence is the same code path as the executed login. Not logged, because their
first segment is not a slug: `store-get-cybersource-status`, `store-pay-with/DPO`, `store-pay-with/Powertranz`, the
`plan-*` routes (a correction to the first version of this entry, which listed CyberSource). `url` also stored the
query string, so a password-reset link's token went into the table, and `referer` stored the page the visitor came
from.

**The most valuable subset (READ, review lens "exposure"):** cookies are not exempt from the path prefix — storefronts
are path-prefixed on the same host as the admin — so a merchant or the super admin previewing a storefront sent their
*admin* session cookie (and `remember_web_*` if ticked) into the table with `visitor_type = 'App\Models\User'`.

**The fix (host app only, vendor untouched):** `App\Services\SanitisedVisitor` subclasses the package visitor and is
re-bound as `shetabit-visitor` in `AppServiceProvider::register` (package providers register first, so the app's
binding wins — READ, `bootstrap/cache/services.php`). `request()` is `[]`; `httpHeaders()` is an **allowlist** with
values scrubbed of invalid UTF-8; `url()`/`referer()` keep the path and the harmless query and replace the *value* of
credential-looking parameters, JSON-looking or >200-character values, URL-valued parameters' inner queries, and any
path segment that is a Laravel ciphertext (the anonymous order-details link is a capability). `ActiveTheme` wraps the
visit in try/catch and logs the **class**, never the exception object (the default log stack includes the error
tracker, which would ship the request's cookies off the box). Nothing reads the `request` or `headers` columns
(`Api\DashboardController`, `ThemeAnalyticController`, `HomeController`, `StoreController` read store_id, url, ip,
platform, browser, device, created_at), so no screen changes.

**Tests:** `StorefrontVisitLogTest` (11 / 67 assertions, RED first: 6 of 7 failed) and `SanitisedVisitorTest` (44 / 101,
table-driven so each credential-looking name is pinned on its own). Removal checks bite for the container binding,
`request()`, the header allowlist, `url()` and `referer()` redaction, the try/catch, the exception-object logging, nested
and fragment handling, the encrypted-segment rule, the exact short names, the oversize/JSON/URL-valued rules, header
scrubbing and **every** regex alternative (two redundant alternatives — `email`⊂`mail`, `signature`⊂`sig` — and later
`pwd`, `session`, `credential` were *removed* rather than claimed). Two of my own first versions were vacuous and are
recorded: the header test sent no headers (`call()` does not apply `withHeaders()`), and the allowlist kept `referer`,
which a test then caught carrying a reset token.

**Adversarial review — three read-only lenses (bypass, sibling sinks, exposure), verdict NON-BLOCKING, all acted on or recorded:**

| Finding | Disposition |
|---|---|
| Order-details link stored in `url`/`referer` (MEDIUM) | **Fixed** — ciphertext path segments redacted |
| Denylist misses `pw`, `cvc`, `hash`, `nonce`, `jwt`, `bearer`, `ticket`, `sid`, `iban`, `account`…; URL-valued and JSON values | **Fixed** (`transaction_id`/`reference` deliberately not added: not credentials) |
| `report($e)` ships cookies/session to the error tracker (LaraBug) on every failed visit | **Fixed** (class-only log); the tracker itself is **P1-SEC-14** |
| Invalid-UTF-8 header threw and lost the visit | **Fixed** (header values scrubbed); `userAgent()`/`languages()` scrubbing was dropped — not reproducible on SQLite |
| Sibling sinks: LaraBug payload, `payment-list` secrets + API log, `withInput()` password flash, plaintext 2FA seed | **Recorded** — P1-SEC-14, **P1-SEC-15 (critical, fixed — S41)**, P1-SEC-16 |
| `store_id` is never populated on a visit row (`Visit::$fillable` lacks it, `Store` has no `visitLogs()`) | **Recorded, not fixed** — analytics that filter `where store_id` read zero here; a per-store scrub is impossible, so P1-OWN-08 must be table-wide. Production's vendored copy may differ — diff it |
| No retention anywhere; BackupRestore's `mysqldump` of the whole database (web-reachable path, chmod 0777) would contain the same rows; `database_name` is concatenated into `shell_exec` | **Recorded** under P1-OWN-08 / P1-OWN-01 — owner actions |

**Not closed, deliberately:** the rows already written (P1-OWN-08 — scrub vs purge, customer notification and forced
password reset, backups, sequencing with the APP_KEY rotation P1-OWN-02, whether card-bearing rows start a PCI process).
No data operation was performed. **Severity as found: CRITICAL** — plaintext customer passwords and, with the NMI or
Authorize.Net gateway enabled, card number and CVV at rest; storing a CVV after authorisation is prohibited outright by
PCI DSS whoever can read the table.

---

## S41 — `POST /api/{slug}/payment-list` returned every gateway's SECRET keys; public registration mints the token that reaches it — **CLOSED by P1-SEC-15; exposure follow-up P1-OWN-09 (BLOCKED, owner)**

*Found 2026-10-21 by the sibling-sink lens of the S40 review (READ) and confirmed by execution the same day. The second
critical exposure of the session.*

`Api\ApiController::payment_list` builds the checkout's payment-method list and, for each gateway, puts the merchant's
live **secret** next to the display fields: 129 distinct field names across about fifty gateways, 55 of them secrets (56 of 131 counting the web twin builder's Paymera entry)
(`stripe_secret_key`, `paystack_secret`, `paytm_merchant_key`, `nmi_api_private_key`, …). `getStore($slug)` takes the store
from the URL with no relation to the caller; the route (`routes/api.php:90`) carries `custom.auth` + `APILog`; and
`POST /api/{slug}/register` — `APILog` only, no auth, no throttle on that route — returns a Sanctum token to anybody.
(`custom.auth` accepts a token of **any** principal class — CLAUDE.md §1 — so "must be a customer" was never a barrier.)

**EXECUTED:** an anonymous visitor registered on their *own* store, POSTed `/api/{victim-slug}/payment-list` with the
returned token, and read the victim store's Stripe secret key and Paystack secret from the JSON. A shopper of another store
and the victim's own shopper got the same body. The API log then wrote the response to disk with only
password/otp/token/secret/authorization/api_key redacted, so most gateway secrets sat in `api-*.log` (15-day retention).

**The fix (host app, shape kept):** `App\Services\GatewaySecretRedactor` nulls every secret-named field in every entry at the
single return of `payment_list` — *after* the PartialPayments module has had its say — and keeps the keys. `secretKey`
(which the vendor code fills with the Sspay/Toyyibpay on/off flag) is exempt so its value is unchanged. The web checkout's
twin builder `Setting::paymentList` returns redacted entries too (its view echoes only name/status/detail/image, so this is
defence in depth). A **ratchet** pins every field name of both builders (131: SECRET 56, KEEP 75; the API response alone is 129: 55 / 74) into explicit lists: a gateway added later
fails `PaymentListSecretDisclosureTest` until a human classifies it.

**Tests:** 10 / 160 assertions; removal checks bite for the call site, every regex alternative, the exact `cashfree_key`
entry, the exemption, the twin builder, over-redaction and unset-vs-null. **Review:** two read-only lenses (fix, sweep), both
NON-BLOCKING; the fix could not be broken by reading, and the sweep found the siblings below.

**What the sweep found beside it (READ, none fixed here — all recorded as READY tasks):**

| # | Finding | Task |
|---|---|---|
| F1 HIGH (probable) | `POST {slug}/get-massage` returns `https://api.telegram.org/bot<TOKEN>/sendMessage…` to an anonymous shopper; reachable after a guest `process-order` with `payment_type=telegram` (no enabled-flag check) | **P1-SEC-17** |
| F2 MEDIUM | Midtrans **server** key rendered as `data-client-key` in page HTML and in a form-action query (guest and platform-to-merchant); module copies carry it; only sandbox keys leak today (`isProduction=false` throws first) | P1-SEC-17 |
| F3 MED-LOW | Platform `NOCAPTCHA_SECRET` echoed into `verify-email.blade.php`'s script for any logged-in unverified user | P1-SEC-17 |
| F4 MEDIUM | `Utility::GetValueByName` with no store id reads **across tenants** (last row wins) for guests and customers: Ozow, Easebuzz, `payment_data` — a shopper can be charged through another tenant's keys | **P1-SEC-18** |
| F5 MEDIUM | Any **staff** user of a merchant can read and overwrite that merchant's gateway and SMTP secrets through the admin API (`resolveOwnedStore` admits the owner's staff; no `isAbleTo`) | P1-SEC-18 |
| F6 MED-LOW | Any merchant can delete another tenant's image file (`Setting::where('name','cod_image')->first()` with no `store_id`, then `File::delete`) | P1-SEC-18 |
| F7 MEDIUM | `/zid/{storeId}/orders` and `/webhooks`: any logged-in user reads any connected store's Zid data (`zid_stores` has no migration — GATE F) | P1-SEC-18 |

**Not closed, deliberately:** that the keys were readable by any internet visitor for as long as the route has answered — the
repository history is squashed, so *how long* is unknowable here. Whether to tell merchants to rotate, a read-only check of
the web-server access logs for `payment-list` calls from sessions that are not the store's own shoppers, and the API log files
on the server are owner decisions: **P1-OWN-09**.

---

## S42 — What the S40 and S41 reviews found at rest and in transit that is not the visit log or `payment-list`

*READ unless stated. Each row is a READY task; none is fixed by P1-SEC-13 or P1-SEC-15.*

| Sink | Where | Task |
|---|---|---|
| Error tracker receives URL + decrypted cookies + `Session::all()` + `Request::all()` + `auth()->user()->toArray()` on every reported exception, posting whether or not `LB_KEY` is set; blacklist misses `cookie`, `*session*`, `*secret*`, every card-number name, `expiry` | `config/logging.php:57`, `config/larabug.php:99-111`, `LaraBug.php:154-177` | **P1-SEC-14** (raises P1-PLT-05) |
| API log redaction gaps (`pass`/`key`/`card`/`cvv`/`pin`/`code`/`cookie`); `ApiController.php:5851` logs `$request->all()` directly | `RedactsApiLogFields.php:36-40` | **P1-SEC-16** |
| `->withInput()` with no argument flashes the password into `sessions.payload` (session encryption off) | `CustomerController.php:95,106`, `DeliveryBoyController.php:58,125` | P1-SEC-16 |
| `google2fa_secret` plaintext, not hidden, **copied to the new sub-user** (two accounts share one TOTP seed); `Customer::$hidden` lacks `password_otp`; `deliverysyria_secret` not hidden | `UserController.php:165-168`, `User.php:63-66` | P1-SEC-16 |
| Easebuzz / AamarPay put the whole checkout JSON + `_token` in the return URL's `request_data` | `PaymentService.php:4253-4254`, `:1746` | P1-SEC-16 (now redacted from the visit log; still in browser history and access logs) |
| **No trusted-proxy configuration** (no `trustProxies`, no `config/trustedproxy.php`): `ip()` is `REMOTE_ADDR`, so behind a reverse proxy or CDN every limiter that keys on it - `throttle:auth` on the login routes, `order-track` - collapses to one bucket per address/number, and a receipt number is enough to keep its owner at 429 | `bootstrap/app.php`, `RouteServiceProvider.php` | **Owner to confirm the production topology**; the fix is configuration, not code |
| BackupRestore: `mysqldump` of the whole database to a web-reachable path, chmod 0777, linked with no auth in front; `database_name` concatenated into `shell_exec` (command injection for anyone holding `backup&restore create`) | `BackupRestoreController.php:181-188` | existing **P1-OWN-01** — the dumps would contain the S40 rows |


## S43 — Credentials handed to the browser, and what the review of that fix found beside it — **P1-SEC-17, -19, -20, -21, -23 and -24 CLOSED; P1-SEC-22 READY; rotation is P1-OWN-09 (BLOCKED, owner)**

*Found 2026-10-21: the three sinks by the S41 sweep (READ), the rest by a four-lens read-only review of the fix
(completeness, regression, bypass, tests) and by execution. Every row below says which.*

**P1-SEC-17 (S41 F1–F3) — fixed, `4a85ca3e`.**

| Sink | Fix | Evidence |
|---|---|---|
| **F1** `POST {slug}/get-massage` returned `https://api.telegram.org/bot<TOKEN>/sendMessage` to an anonymous caller after posting the order text itself; the one page that calls it stores the body in a hidden input nothing reads | host handler posts through the Http client and returns nothing; a failure is logged by class and status only (a refused `file_get_contents` raised a warning whose message is the URL, which reached the log and the error tracker) | EXECUTED RED: the body was the URL. 9 tests |
| **F1, vendored copies** Donation and LotteryPrizes return the same URL from their own `getWhatsappUrl()` (they never post anything) | `WithholdTelegramBotUrl` on the `web` group - a rule about a response, not a list of route names - empties a bare bot-URL body | EXECUTED RED through both module routes, enabled by the production loader. 12 tests |
| **F2** the Midtrans **server** key printed as `data-client-key` and, via `route($fallback, $data)`, into the form action | removed from the view data and both views (store checkout and plan purchase); Campaigns / Donation / LotteryPrizes copies replaced by package-view overrides in `resources/views/vendor/<ns>/`, with a drift test that fails when the module's own view changes | EXECUTED RED for all five pages. 5 tests, plus the module set |
| **F3** `verify-email` printed the platform `NOCAPTCHA_SECRET` into a script | uses `NOCAPTCHA_SITEKEY`, like `login`, `register`, `forgot-password`, `reset-password` | EXECUTED RED. 2 tests |

26 tests / 86 assertions; every control removal-checked, and two mutations the review found unbitten (log the exception
message; drop the empty-token return) now fail a test. **Midtrans:** the sandbox `snap.js` was fetched and read (not run) -
`data-client-key` defaults to `''` and its absence only logs that custom-UI configuration will not take effect. NOT executed:
the Midtrans-hosted popup without a `client_key`.

**Found by the review and fixed in their own commits (each EXECUTED RED first):**

| # | Finding | Task |
|---|---|---|
| **HIGH** | `POST /setting-form` with `tab_type=chat_gpt_setting` returned the **platform's shared OpenAI keys** to any merchant or staff login (the tab link and the save were operator-only; the read had no check) | **P1-SEC-19** `eaa0d21f` |
| MEDIUM | Donation / LotteryPrizes Telegram, WhatsApp and Khalti responses serialised the whole `Store` row to an anonymous caller - with Duo2FA installed, `duo_secret_key` and `duo_integration_key` | **P1-SEC-20** `d809ddb7`, then `c1342d24` (see below) |
| MEDIUM | `get-massage` deleted the cart lines of the POSTED `customer_id` (a guest could clear a victim's basket line); and built every store's message from the **lowest-id merchant's** `whatsapp_content` because `getAdminAllSetting()` with no arguments resolves `auth()->user()` (null for shoppers) | **P1-SEC-21** `a541eeb1`, then `b6902fc7` (see below) |
| **HIGH** (found by the review of the above, **EXECUTED on MySQL 8.0.46**) | `whatsapp()` queued a guest's basket clear as `customer_id = ''`; guest rows carry `customer_id 0` and differ only by cookie. On production's non-strict MySQL `'' = 0`, so **one guest's WhatsApp/Telegram order deleted every guest's basket row for that product in the store** (2 of 2 measured). Strict mode raises 1292 and clears nothing; SQLite clears nothing, which is why the suite never saw it | **P1-SEC-23** `b6902fc7` |
| **HIGH** (found by the review of P1-SEC-23, **EXECUTED, each member through its real route**) | **Baskets were deleted, taken over and altered through a cookie that was not there.** A logged-in customer's basket rows carry `cookie_session_id` NULL and `where('cookie_session_id', null)` is `whereNull`, so every query that used `Cookie::get('cart')` unchecked selected every logged-in customer's rows when the cookie was absent. Measured: an **anonymous** `cart-clear` / `cart-remove` / `change-cart` with no cookie deleted or altered another customer's basket - **and one `cart-clear` on store 1's slug wiped the logged-in customers' baskets of every store (2 baskets in 2 stores -> none); an earlier note said the tenant bounds it, which was a test artifact (`actingAs($c, 'customers')` makes `customers` the default guard and so resolves a tenant; production's default guard is `web`)**; a customer **logging in** with no cookie had every other customer's basket reassigned to them; a logged-in customer's **COD order** and an **NMI return** deleted every other customer's basket (cross-store, for the same reason). Eight order-completion handlers carry it. Second half: seven gateway returns and `PaymentService::clearCartLines` cleared the buyer's basket by `$request->customer_id` (the stored state: `''` for a guest, which MySQL reads as 0, or the id of the customer whose e-mail was typed). Also `cart-remove`'s logged-in arm deleted any customer's line by its sequential id | **P1-SEC-24** `8788c030`, `59944003`, `17012b5b` |

**Each of the three fixes then had an independent read-only review (`tenant-authz-reviewer`), and it changed the code:**

- **SEC-19 - CLEAN.** The operator test matches the other operator checks; no other path renders the keys; the tab's only consumer has no error handler, so the 403 breaks nothing.
- **SEC-20 - NON-BLOCKING, one MEDIUM acted on.** `$hidden` is object state and Eloquent's `__sleep()` serialises it, so with `CACHE_STORE=database` a `Store` cached *before* the deploy keeps the old empty list and keeps leaking until the cache is flushed. Moved to `attributesToArray()`, a method; the new stale-cache test fails against the `$hidden` version (executed).
- **SEC-23 - NON-BLOCKING; its review found the larger problem.** On the real web checkout the queued id is the customer found from the *typed* billing e-mail, so the clear deleted that customer's basket (executed RED); and the cookie delete after an order was unguarded against a missing cookie (P1-SEC-24).
- **SEC-21 - NON-BLOCKING, two findings acted on.** (1) The template was keyed on the owner's user id while `customMassage` stamps `created_by` with whoever saved last - a staff-saved template would be missed; now read by name and store. (2) The authenticated arm was untested (a guest `process-order` stores a posted `customer_id`; a guest who then logs in takes that arm); the reviewer's mutation passed every earlier test and fails the new one. It also surfaced the SEC-23 defect.

**Found and not fixed - READY or recorded under an existing task (READ unless stated):**

| Finding | Task |
|---|---|
| `get-massage` is an unthrottled, ungated anonymous relay to the merchant's Telegram chat; Donation records a row per anonymous POST (one call delivering one message is EXECUTED) | **P1-SEC-22** |
| `processMidtrans` flashes the exception **object** into the page (`{!! !!}` in two layouts); `isProduction=false` is hard-coded, so every production-key merchant takes that path on every checkout | P1-SEC-22 |
| Marketing module prints `business_password` in page script and compares it in the browser (not traced) | P1-SEC-22 |
| `payment_list` still returns `telegram_chat_id` | P1-SEC-22 |
| **34** other no-argument `getAdminAllSetting()` callers in `app/` (14 in `PaymentService`, 9 in `helper.php`): on a storefront path they resolve the lowest-id merchant's settings, the shape of S41 F4 | P1-SEC-18 |
| `POST generate/response` lets any logged-in user spend the platform's OpenAI key (cost abuse, not disclosure; reach not verified) | P1-SEC-22 |
| Khalti's return never clears a guest's cookie basket; vendored `SkipCart` `emptyCart` deletes by a request `customer_id`; admin abandoned-cart handlers rely on the scope alone; LynomiaChat returns a live guest session id as a basket id | P1-SEC-22 |
| `StoreLocator` builds `…&key=$apiKey` and calls `file_get_contents`; a network failure puts the merchant's key in the warning text | recorded; SEC-14 covers the tracker half |
| The vendored installer prints `.env` while `storage/installed` is absent; production state unknown | existing GATE 0 |
| No Midtrans **client** key setting exists, so a real client key can never be rendered; adding the field is a schema/UI change | needs approval |
| `customer_donations.theme_id` is written by the Donation handler and declared by no migration | schema drift, recorded |

**Not closed, deliberately:** the keys and tokens have been readable for as long as these routes have answered, and the
history is squashed so how long is unknowable. Rotating the merchants' Telegram bot tokens and Midtrans sandbox server keys,
the platform reCAPTCHA secret (only where `RECAPTCHA_MODULE=yes` and the version is not v2), the **platform OpenAI key(s)**,
and the Duo keys of any merchant who used the Donation/LotteryPrizes Telegram/WhatsApp/Khalti checkout, plus the access-log
queries (`POST */get-massage`, `POST /setting-form` with `tab_type=chat_gpt_setting` from a non-operator session, the module
`*-telegram` routes) are owner decisions: **P1-OWN-09**.

---

## S44 — A read that names no store answered with the last tenant's row — **P1-SEC-25 CLOSED (S41 F4); P1-SEC-26 READY; P1-SEC-18 narrowed to F5–F7**

*Found by the P1-SEC-15 sweep (S41 F4, READ, probable); confirmed by execution 2026-10-21. A read-only review of the fix found no defect in it and produced the follow-ups below; one of its findings (`/pricing`) was refuted by execution.*

| | |
|---|---|
| **What** | `Utility::GetValueByName($name)` with no store id used `getCurrentStore()` (the `web` guard's `current_store`), which is NULL for every shopper. With no store id the query has no `store_id` predicate: all tenants' rows, last one wins. `getAdminAllSetting()` with no arguments, with no user and no store, returned the **lowest-id merchant's** settings |
| **Measured** | three tenants with the same `ozow_pay_Site_key`: a shopper at store A and one at store B both read store C's key — their Ozow payment request would be created in C's account (money **misdirection**; no read returned a secret to the caller) |
| **Fix** | `getRouteStoreId()`: the store the request's route names (`storeSlug`, or `slug` as the first path segment only), memoised per slug; `GetValueByName` and `getAdminAllSetting()` use it when nothing else names a store; the eight Ozow/Easebuzz credential reads pass `$store->id` |
| **Tests** | `StorelessCredentialReadTest` 11 / 39 (RED first; each control removal-checked; customer, driver and merchant token classes), `PricingSettingsDisclosureTest` 3 / 7 |
| **Gate** | Feature 3222 passed / 2 declared failures / 17 declared skips (known-outcome exit 0), Unit 339, Contract 26, the five CI guards exit 0, on 82ba2ea3 |

**Refuted by execution — the review's `/pricing` finding.** The LandingPage module's pricing view prints
`json_encode(getAdminAllSetting())` twelve times, which for a caller with no user and no store is a merchant's whole settings
array. But `HomeController::Pricing()` renders it only inside `module_is_active('LandingPage')`, after redirecting every
logged-in caller, and `module_is_active()` with no route slug answers from the authenticated user and is **false** when there is
none. The view is dead code behind `/pricing`, enabled module or not. A host override written for it was deleted; the premise is
pinned (it fails, with the instruction to override the view first, if `module_is_active()` ever answers true for an anonymous caller).

**Not closed, and recorded:**

| Residue | Task |
|---|---|
| Routes that name no store still take the lowest-id merchant's settings: `store-pay-with/DPO/create` (display currency only), the Paymera callback's coupon-string currency reads, custom-domain pages other than `/`, webhooks, jobs | **P1-SEC-26** |
| An unknown slug keeps the all-tenant read until the handler's `getStore()` refuses it (READ, not executed) | P1-SEC-26 |
| `check_file()` / `delete_file()` / the logo helpers read the **platform's** `storage_setting` through the merchant-shaped call; for a shopper it is now the visited store's row. Whether any deployment depends on the old answer is not determined — owner data query `SELECT store_id, value FROM settings WHERE name='storage_setting'` | P1-SEC-26 |
| `HomeController::Pricing()` configures the public captcha from the lowest-id merchant's settings instead of the platform's | P1-SEC-26 |
| F5 staff read/overwrite a merchant's gateway and SMTP secrets through the admin API; F6 cross-tenant image delete (`cod_image`); F7 Zid routes | **P1-SEC-18** (narrowed) |

## S45 — Staff and the secrets, and a file-delete primitive found while closing it — **P1-SEC-18 CLOSED; P1-SEC-27, -28 READY; P1-OWN-10 BLOCKED (owner)**

*Found 2026-10-21: F5–F7 by the S41 sweep (READ); everything marked EXECUTED by a test through the real route (or, for the MySQL row, by a script on MySQL 8.0.46); the rest by two read-only reviews of the fix. Gate: Feature 3313 passed / 2 declared failures / 17 declared skips (known-outcome exit 0), Unit 339, Contract 26, the five CI guards exit 0, on 4c6ade05*

| # | Finding | State |
|---|---|---|
| **CRITICAL** | `Utility::changeStorageLimit($owner, $path)` — ~35 callers — did `File::glob(base_path($path))` + `File::delete()` of every match; the web's generic settings writers take any name/value, so a **staff member** could store `logo_dark = config/*`, save a logo, and unlink the application's config (EXECUTED, sandbox dir). `changeproductStorageLimit` had the same glob (ImportExport writes product paths raw) | **fixed**: one literal file inside the owner's part of `uploads/` (`StoredUpload`) |
| HIGH | `updateStoreOwnerInfo` let **staff** rewrite the owner's name, **login email** and mobile — password-reset takeover in two requests (EXECUTED) | **fixed**: owner/operator only; staff may resubmit stored values |
| MEDIUM | F5 — staff read and overwrite a merchant's SMTP password / Stripe secret through the admin API (5 handlers) **and the web Settings screens** (4 handlers) — no `isAbleTo` anywhere (EXECUTED) | **fixed**: `StoreSettingsAccess` (operator → owner → `Manage Setting`); 403, never 401. First draft used the wrong permission (`Manage Store Setting` is the *Store Setting* screen) |
| MEDIUM | F6 — saving your own COD/bank/Stripe image, **or a theme logo/favicon/SEO image in the web**, deleted the *lowest-id tenant's* file (unscoped `Setting::where(name)->first()`; 3 API + 5 web sites, EXECUTED) | **fixed**: scoped; unlink via `StoredUpload` (scoping alone would have let a tenant aim the delete) |
| MEDIUM | `updateStoreOwnerInfo` built `public_path('uploads/'.$storeId)` from the raw request string; **MySQL matches `'5/../../x'` to store 5 (EXECUTED on MySQL 8.0.46, strict and non-strict; SQLite does not, so the suite is blind to it)** — image files could be written outside `public/uploads` (Laravel's validator blocks `.php/.phtml/.phar`, EXECUTED) | **fixed**: the id the lookup returned |
| MEDIUM | F7 — `/zid/{storeId}/orders` and `/webhooks`: any logged-in user read any connected Zid store's data | **fixed**: operator-only (`zid_stores` has no tenant column) |
| — | the review's `/pricing` claim (S44) was refuted; the reviews' other claims are in the tasks below | |

**Not closed, and why it matters**

| Residue | Task |
|---|---|
| Staff can still write any setting **by name** through the web's generic writers (gateway keys, SMTP host, domains → a Webuzo delete gadget) and the admin API's Cod/Bank/Loyalty/currency writers have **no** permission check; `settings` has no unique key on (name, store_id) in its migration | **P1-SEC-27** |
| ~25 direct `File::delete(base_path(<db value>))` sites bypass `StoredUpload` — host (`ProductController`, `Product`, `DashboardController`) and **vendored** (DigitalProduct, AuctionProduct, SignInWithLinkedIn/Facebook, which also read the lowest-id tenant's row); ImportExport's default image is deleted as collateral; files with no store segment have no owner to compare | **P1-SEC-28** |
| 407 user-uploaded files are committed to the repository (`uploads/` is tracked; `.gitignore` only excludes `/public/uploads/`) | **P1-OWN-10** (owner) |

**An incident worth knowing about:** two of this session's own test drafts deleted *tracked* files from the working tree (72 files from a teardown that
collided with `uploads/<store id>`; then the repository root plus 131 uploads when a mutation re-enabled the glob and a test passed `*`). Both were restored
from git before any commit; the tests now touch only sandbox paths.

## S46 — The by-name settings writers, and what two reviews found in the first two designs of the fix — **P1-SEC-27 CLOSED; P1-SEC-29, -30, -31 and P1-PLT-06 READY; P1-OWN-11 and -12 BLOCKED (owner)**

*Found 2026-10-21 by the P1-SEC-18 reviews (READ) and closed by P1-SEC-27. Two read-only reviews of the fix followed (the second a four-lens workflow, 74 agents, 35 findings each checked by two skeptics, all READ); the rows marked EXECUTED were run: MySQL 8.0.46 for the collation, the SQLite suite with a recording hosting-panel fake for the rest. Gate: Feature 3544 passed / 2 declared failures / 17 declared skips / 0 errors (the known-outcome assertion exits 0), Unit 339, Contract 26, the five CI guards exit 0 (scan: no credential-shaped literal in 257 changed files), the whole MySQL runner 14 of 14 on MySQL 8.0.46, all on 9c9a232d. The first full Feature run on this tree had ONE new failure -- a P1-SEC-18 test whose premise (staff with no permission storing a poisoned logo row) is exactly what this task closed; its actor was moved to a principal that can still reach the write and the glob mutant still makes it fail*

| # | Finding | State |
|---|---|---|
| **HIGH** | Nine web handlers wrote EVERY posted name into `settings` for the caller's store and their routes carry no permission middleware: a user holding NEITHER settings permission could write `MAIL_*`, the Stripe keys, `bank_transfer`, `CURRENCY_NAME` (what a card is charged in), an integration's endpoint (its stored token is sent there) or `domains` | **fixed**: the six Settings-screen handlers require `Manage Setting` outright; the three Store Setting handlers require `Manage Store Setting` and refuse a change to a restricted name without `Manage Setting`; the admin API's six writers answer 403 |
| **HIGH** (review 1, EXECUTED) | `utf8mb4_unicode_ci` is case-, accent- and PAD-SPACE-insensitive: `Domains`, `DOMAINS`, `domains ` and `dómains` ARE the stored `domains` row, and the writers' `updateOrCreate` rewrote that row **including its name** while the guard compared case-sensitively. SQLite matches none of them — the suite cannot show it | **fixed**: names lower-cased and trimmed, a restricted name in a spelling other than the stored one refused, a non-ASCII / control / integer key refused; `verify-settings-name-collation-on-mysql.php` (in the runner) |
| **HIGH** (review 1) | ThemeSettings turns a posted TOKEN into on/off flags and Webuzo add/delete calls; any token but `enable_domain` **deletes the stored custom domain**, so the by-name comparison ('on' = 'on') was bypassed | **fixed**: a user without `Manage Setting` is fed the inputs that reproduce the stored state, the five routing names are never written, a differing value is refused |
| HIGH, regression (review 1) | `_token` is a "secret" by `GatewaySecretRedactor`'s pattern, so every honest browser post by staff was refused; the suite has CSRF off and never posted it | **fixed** |
| HIGH, regression (review 2) | an ABSENT routing field counted as a change, so the Home and Checkout tabs (which post none) were refused on every custom-domain / sub-domain store — and the first version's test pinned that refusal as correct | **fixed**: only a present, differing value is an attempt |
| MEDIUM (review 2) | the currency, loyalty, integration-endpoint, local-storage and fourteen gateway-image names were unclassified, so the gates on their dedicated handlers were bypassable through the generic ones | **fixed** |
| MEDIUM (review 2) | an "unchanged" resubmission re-stamped `created_by` (`/seo-setting`) or inserted a second row (`/theme-settings`), which every newest-row reader then prefers over the owner's later edit | **fixed**: an unchanged restricted value is removed from the request, body and query |
| LOW | integer keys (`where name = 0` matches every non-numeric name on MySQL); a file under `subdomain`; routing rows written by the neutralisation itself | **fixed** |
| — | refuted by both skeptics: "the neutralising replay is not idempotent" | recorded as a known read-only panel call for an explicit-token post |
| MEDIUM (review 3) | other routes to the rows P1-SEC-27 protects: the web currency-rate update (`auth` only), the stock and refund tabs' POST targets, `currency_name` in `updateStoreOwnerInfo` (on MySQL it addresses the CURRENCY_NAME row), the social-login audience pins; and the hosting panel was still reachable by a token-less post on a store whose domain is not stored in canonical form | **fixed** in `e8bae382`: each asks `Manage Setting`; the three Webuzo blocks of ThemeSettings run only for a user who may change routing |
| MEDIUM (review 3, pre-existing) | ordinary Store Setting saves by staff re-stamp `created_by` on the owner's rows, so the owner's screen shows them blank | **P1-PLT-07** |

**Not closed, and why it matters**

| Residue | Task |
|---|---|
| A holder of `Manage Store Setting` alone can still put raw script on every storefront page, **checkout included** (`storejs`, `storecss`, `google_analytic`, `fbpixel_code` are what the screen exists to edit). Pinned by a test that fails the day it changes | **P1-OWN-12** (owner) |
| **Any merchant owner can claim another merchant's custom domain or sub-domain**: the host lookup is an unscoped `->first()` and nothing keeps the values unique. EXECUTED: the older store's owner saved the newer store's host and the lookup returned the older store | **P1-SEC-29** |
| Nine vendored module controllers write `settings` by caller-supplied name with no permission check (four with no auth or module check); cannot be edited, so the control is host-side | **P1-SEC-31** |
| The fixed-name credential writers (storage, WooCommerce, Shopify, Twilio, WhatsApp, Firebase) and the store's name/e-mail/slug have no permission check | **P1-SEC-30** |
| Saving the Home or Checkout tab removes the owner's OWN custom domain at the hosting panel (EXECUTED; pre-existing; kept for the owner and permitted staff by P1-SEC-27) | **P1-PLT-06** |
| The live `settings` indexes, hosts already held by two stores, rows renamed by a case variant — only the owner can read them | **P1-OWN-11** (owner) |

**A thing worth knowing about the process:** the first version of this fix was a deny-list of names, tested only on SQLite, and its first review found three HIGH defects — one of which the suite could not have seen on principle. The second review found the first fix's own regression and that a deny-list cannot be shown complete. The design that shipped puts the PERMISSION on the route and keeps the name rule only where a lower permission legitimately reaches a generic writer. The acceptance criterion that asked for per-writer allow-lists was deliberately not implemented (reasons in the task's evidence).

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
| **P01-COMMERCE-SEC-02** | **S32's two unguarded writers**, and **S33** | ~~The last two order writers~~ **DONE.** Five commits. The NMI card charge no longer takes its basket, its line quantities or its totals back out of a hidden form field; the amount captured, the basket's store and an unusable quantity are all settled BEFORE the card is charged; the state is single-use and restored on every path that took no money; stock is atomic with give-back; `'Paid'` is derived over a recorded gateway verification, the first of the nine. `whatsapp` keeps its `$request->all()` fallback — two checkout views need it — so its boundary is per field: the order's owner, its store and a posted shipping figure that reached `orders.product_price`. Both pre-existing crashes swept. **Two adversarial reviews then found five defects in the work itself, including a positive control that was passing through an HTTP 500 and a store predicate that covered one of the stock loop's two branches and could only refuse after the capture — all fixed, all recorded in S33.** 34 tests, 135 assertions; 18 guards removed and re-measured, 2 recorded as subsumed. **Ten items left open — see S33 §7** |
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
