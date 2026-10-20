# P01-SEC-01 — session hygiene: what was wrong, what was mis-filed, and the one that needs you

**Issue:** #15 · **Task:** P1-SEC-01 · **Status:** four of five items done; item C is an **owner
decision** and item E is **retired with evidence**.

Evidence: `tests/Feature/Security/SessionHygieneTest.php` — 12 tests, 53 assertions. Each of the
five criteria was driven before anything was changed, then adversarially attacked by a second
agent. **Two of the five were mis-filed**, and those corrections are the substance of this document.

---

## Summary

| | criterion as filed | verdict | what shipped |
|---|---|---|---|
| **D** | the `/login` CSRF exemption is not satisfied by a prefixed path | **mis-filed; the real defect is worse** — `POST /login` itself was exempt | one deleted line |
| **A** | customer logout invalidates, symmetric with the web guard | **confirmed, but `invalidate()` would have been a regression** | gated `regenerate(true)` |
| **B** | session id regenerated on customer login | **already correct** — the framework does it; the real gap is one method away | rotation at the OTP-verify boundary |
| **C** | `SESSION_SAME_SITE` has an explicit default | **confirmed, and `lax` breaks money** | comment corrected, value pinned — **owner decision** |
| **E** | MFA dispatched after `authenticate()` and `regenerate()` | **already correct** | retired with the measurement |

## D — `POST /login` was CSRF-exempt. This is the one that mattered.

`bootstrap/app.php`'s CSRF `except:` list carried the bare entry `'/login'`. `/login` is the
**merchant and operator login**, so that is **login CSRF**: an attacker can log a victim into the
*attacker's* account and then observe or capture whatever the victim does there. The leading slash
did not make the entry dead — `inExceptArray()` does `trim($except, '/')` first.

**Measured both ways**, by two independent agents on a patched tree:

- with the entry present, `POST /login` with **no token succeeds**;
- with it deleted, the full real flow still works — `GET /login`, scrape the 40-char `_token`,
  `POST` → 302 `/dashboard`, `GET /dashboard` → 200 rendering the user's name.

`resources/views/auth/login.blade.php:38` already emits `@csrf`, so **the token was always being
sent and always being ignored**. Nothing depended on the exemption. Seven candidate regressions
were driven, including the browser form, the payment callbacks and the Flutter paths; all refuted.

**The criterion asked the wrong question.** "Not satisfied by a prefixed or localised path" was
**already true**: `Str::is` honours only `*`, so `ar/login` and `{storeSlug}/login` never matched.
That half is pinned anyway, with the pattern seeded as it used to be, so nobody "fixes" it by adding
a leading wildcard — which would exempt every storefront and localised login.

`'/duologin'` is left alone deliberately: a different route with a different caller, and nothing in
this item measured it.

## A — `invalidate()` would have been the regression, not the fix

**What was wrong:** `SessionGuard::logout()` removes `login_customers_*` and nothing else — no
`migrate()`, no `regenerateToken()`. Measured on `DatabaseSessionHandler`, the production driver
(`config/session.php:21`, `.env.example:29`): after a logout the **old `sessions` row survived** and
still carried `request_data = {"email": …, "address": …}` — the whole checkout POST body, since
`PaymentService:653` stores `$request->except('attachment','payment_receipt')`. The cookie the
browser still holds kept addressing it, and the CSRF token was not rotated. (52 `put('request_data')`
sites against 8 `forget()` sites, so it is normally left behind.)

**It is not an authentication bypass** — the login key really is removed, so the stale cookie
re-authenticates nobody.

**Why not `invalidate()`, which is what the criterion asked for.** `config/session.php:130` derives
**one static cookie name** from `APP_NAME` with no per-store override, and `:146` sets the path to
`/`. Storefronts are path-prefixed on the same host, so **a single cookie holds `login_web_*` and
`login_customers_*` at once** — and a merchant previewing their own storefront is exactly the person
this logout button is rendered to (`front_end/common/account-tab.blade.php:75`). Measured:

| mode | merchant `login_web_*` | shopper key | other data | old record |
|---|---|---|---|---|
| as shipped | survives | removed | kept | **kept** |
| `invalidate()` | **destroyed** | removed | all gone | destroyed |
| `regenerate(true)` | survives | removed | kept | destroyed |

The route carries no auth middleware (gathered: `["web", ActiveTheme, "themelanguage"]`), so a guest
POST reaches it — and with `invalidate()` patched in, a POST from a principal holding only a `web`
session **wiped the merchant's admin key**. Symmetry with the web guard is the wrong goal when the
two guards share a cookie.

**And it is gated.** A guest cart row is keyed to `session()->getId()` (`Cart.php:154`), so rotating
the id for a caller who never had a shopper session orphans that caller's basket — measured through
`POST {slug}/product_cart`: one row became two. A logged-in shopper has nothing to lose; their row
carries `cookie_session_id = NULL`.

The gate tests the **session key**, not `Auth::guard('customers')->check()`: no provider lookup, and
immune to `SessionGuard`'s `$loggedOut` memoisation, which made a `check()`-gated probe report
"never rotates" when it in fact always rotated.

**Two things the investigation corrected in my own brief:** the storefront cart is **DB + cookie,
never session** (`carts` row keyed by `customer_id` or `cookie_session_id`), so `invalidate()` would
not have dropped a cart; and `LANGUAGE` has **zero** session write sites — it is a cookie, so Arabic
RTL / English LTR is unaffected either way.

**Also found, out of scope and queued:** there is **no `deliveryboy` logout at all** (latent — the
api group carries no `StartSession`, so those `login()` calls write to a store that is never
persisted), and `app/Helper/helper.php:436` and `HomeController.php:383` both force
`auth()->logout()` with no invalidate or regenerate.

## B — the criterion was already satisfied; the real gap is one method away

"Session id regenerated on customer login" is **already true** and needed no change:
`SessionGuard::login()` → `updateSession()` → `migrate(true)`, free from the framework. Pinned, so
nobody adds a redundant second migrate.

**The privilege boundary that rotated nothing is `verifyPasswordOtp()`.** The three flags it writes
— `password_otp_verified`, `..._customer_id`, `..._store_id` — are, on their own, a standing
authorisation to change that customer's password: the reset handler reads **nothing else, not even
`password_otp`**. An attacker who could plant the pre-OTP session id could replay it after the victim
verified and take the account over.

`regenerate(true)` now runs immediately before those flags are written — migrate-with-destroy, so
the planted row is deleted rather than left carrying pre-verify state, plus the CSRF rotation that
matches `AuthenticatedSessionController.php:77`. Measured closed, with the legitimate reset kept as
an explicit oracle.

**Sequencing, which is the part that matters.** Today the takeover is held shut only by a **broken
column**: `customers.password_otp` is `INTEGER` while the web reset writes a 60-char bcrypt hash, and
the prepared-and-unapplied migration in `database/migrations/_sec13_pending/` **widens it**. Applying
SEC-13 without this rotation **arms** the takeover. Recorded in that directory's README, and pinned
by a test that reads it.

## C — `lax` is correct and not safe here. This one needs you.

`same_site` defaults to `null`, and the stock Laravel comment above it claimed *"By default, we will
set this value to `lax`"* — **false of this application**, and removed. `null` omits the attribute,
so browsers apply their own Lax-by-default, but not identically across browsers or across the
redirect shapes a gateway uses.

Setting `'lax'` explicitly is the obvious hardening **and it breaks money.** A top-level cross-site
POST does not carry a `Lax` cookie, and nine CSRF-exempt routes are POSTed to by third-party
gateways and then read their state out of the session. Measured by driving each with no session
cookie:

| route | handler | measured at `lax` |
|---|---|---|
| `iyzipay/callback/plan/{id}/{amount}/{coupan_code?}` | `IyziPayController@iyzipayCallback:125` | 302 → `/login` |
| `aamarpay/success/{data}` | `AamarpayController@aamarpaysuccess:195` | 302 → `/login` |
| `plan-get-phonepe-status` | `PhonePePaymentController@planGetPhonePeStatus:104` | 302 → `/login` |
| `plan-easebuzz-payment-notify` | `EasebuzzController@notify_url:150` | 302 → `/login` |
| `plan-get-Powertranz-status/{plan_id}` | `PowertranzPaymentController@…:197` | 302 → `/login` |
| `plan/sslcommerz/status/{plan_id}` | `SSLCommerzPaymentController@…:172` | 302 → `/login` |
| `store/paymera/trigger/{orderId}` | `PaymentController@handlePaymeraTrigger:877` | 302 (reads `Cache`, breaks only on the `auth` middleware) |
| `{storeSlug?}/get-payment-easebuzz` | `EasebuzzController@getProductStatus:226` | **the checkout refusal, and no order** |
| `campaigns-card-payment/` | `Campaigns…@paymentSuccess:128` | null deref on `Auth::user()->id` |

Six of those take money and then activate a plan from `Auth::user()`; at `lax` the merchant lands on
the login page and **the plan is never activated**. The ninth is the one that matters most:
P01-COMMERCE-SEC-01 deliberately made the session the **sole** source of checkout state, so
`storedCheckoutState()` returns null, `gatewayReturnRefusal()` fires, and a paid Easebuzz order
silently fails to exist.

**So the value is unchanged and pinned**, with the reason sitting next to it in `config/session.php`
rather than in a file the application never reads. `SESSION_SAME_SITE` in the environment still
overrides it. **Your call**, and the honest framing is: this is not a one-line hardening, it is a
decision about nine payment paths.

## E — retired, with the measurement

`GoogleAuthentication` and `Duo2FA` are the only MFA mechanisms, and both dispatch at
`AuthenticatedSessionController.php:120-125` — after `$request->authenticate()` (`:76`) and
`$request->session()->regenerate()` (`:77`). Measured by comparing the session id at the `Login`
event with the id the response carries: they differ, so regeneration follows credential verification
and the MFA redirect follows both. No MFA code is dispatched before credentials are checked —
GoogleAuthentication is TOTP and sends nothing, and Duo2FA's challenge page 302s to `/login` for a
guest, so there is no enumeration or SMS-cost vector *on this path*.

**A separate live finding, not folded into E:** `AuthenticatedSessionController:68-74` discloses
account existence and state **before** `authenticate()`. That is its own item and is queued, not
fixed here.

## Removal checks — all 7 bite

Scripted; each control deleted or weakened **alone**, the suite re-run, all three files restored and
verified identical.

| | control | result |
|---|---|---|
| R-A1 | the logout rotation entirely | 1 fail |
| R-A2 | the gate (rotate unconditionally) | 1 fail |
| R-A3 | reading the session key **after** `logout()` — the inert-fix ordering | 1 fail |
| R-A4 | `invalidate()` instead of `regenerate(true)` | 1 fail |
| R-B1 | the OTP-verify rotation | 1 fail |
| R-D1 | re-adding the `/login` exemption | 1 fail |
| R-C1 | flipping `same_site` to `lax` | 1 fail |

### Three of my own tests were vacuous, and the removal check is what proved it

The first version of item A drove the real route and asserted the session id changed. **It passed.**
Then R-A1 showed it **also passed with the rotation deleted entirely**, and a probe showed why:
`phpunit.xml` runs the **`array` session driver**, which persists nothing between requests, so
`StartSession` mints a new id on **every** request whatever the handler does. Measured, guest and
shopper alike, with a prior request to establish the cookie:

```
GUEST   : before=9wrPZ7Cc after=6UaPDzAk changed=YES
SHOPPER : before=i1GVX5Fj after=towxk6Jr changed=YES
```

So an HTTP-driven session-id assertion in this suite is **vacuous by construction** — the same
engine-hiding class as SQLite masking a MySQL defect, one layer up. The three tests were deleted
rather than relaxed, and item A is now asserted at the source (which bites on all four A checks) plus
a direct test of the primitives the fix relies on: that `logout()` does **not** rotate (otherwise the
fix is redundant) and that `regenerate(true)` rotates **and preserves the data** (otherwise it is
`invalidate()` by another name).

**Two further self-inflicted traps, recorded because they are the recurring kind:**

- I read the CSRF except list off the middleware's **instance property** `$except`, which is always
  `[]` — the configured list lives in the **static** `$neverVerify` and is merged by
  `getExcludedPaths()`. Three assertions passed vacuously; `assertNotContains('/login', [])` is
  trivially true. The premise test is what caught it, and it now pins the distinction.
- A `assertStringNotContainsString("guard('customers')->check()", …)` went **red against correct
  code**, because the fix's own docblock explains why the gate does *not* use `check()`. An
  assertion a comment can trip is worse than no assertion, so `methodSource()` now strips comments.

## Gates

```
php artisan test tests/Feature/Security/SessionHygieneTest.php   12 passed (53 assertions)
```

Full-suite figures are in the commit message.
