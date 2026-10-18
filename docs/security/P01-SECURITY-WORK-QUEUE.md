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

**Fix shape:** log method, path, status code and duration. Never the objects. Redact `Authorization`
and `Cookie` explicitly rather than relying on a blacklist. Separately, consider whether 15-day
retention of API bodies is wanted at all.

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
| **P01-SEC-3** | S4 + the `larabug` blacklist review | Independent; no contract impact |
| **P01-SEC-4** | S5 session half (logout, id regeneration, `same_site`) | Independent. Token expiry/abilities wait for a Flutter window |
| **P01-F** | S6 | Already a named Phase 01 package |
| **P01-SEC-5** | S7 | Owner decision on the swallow |
| **P01-SEC-6** | S9 + S10 | Cheap; can ride alongside any of the above |
| *(precondition, not a package)* | S8 | Must be settled before P01-B ships |

Rotation (S2) is an owner operation, not an engineering package, and gates nothing on this list
except the *severity* of S3.
