# P01 — Sanctum token expiry and revocation: a proposal, not a change

**Nothing in this document is implemented.** The owner's constraint was: *"Do not introduce a
breaking global expiration policy without owner approval."* This is the analysis and the staged
plan; the only token work actually landed in this package is revoke-on-password-change for the
principals that were missing it (below, under "already done").

---

## The finding that decides everything

**There is no customer login endpoint.** `Api\AuthController::login` exists at
`app/Http/Controllers/Api/AuthController.php:207` and **is routed nowhere** — the only
`AuthController` declarations are `register`, the three `forgot-password` steps, and `logout`
(`routes/api.php:46,50,51,52,54`), and the only `login` routes in `routes/api.php` are
`adminlogin` at `:47` and `:134`, both `DashboardController::login`.

So a storefront customer can obtain a token by exactly two routes:

| | route | what it costs the holder |
|---|---|---|
| 1 | `POST api/{slug}/register` | registering an account |
| 2 | `POST api/{slug}/forgot-password-verify-otp` | completing a password recovery |

**If a customer's token expires, the only way back in is password recovery.** That makes a customer
token expiry an availability decision, not a security one, and it is why step 1 of the plan below
is not "set an expiration".

**And there is no refresh path for anyone.** No refresh route, no refresh token, nothing. In the
shipped admin app, `Prefs.setToken` is called from exactly one place —
`StoreSyria_app98/lib/core/controller/auth/auth_controller.dart:171`, inside `loginAuth()`, which
posts the typed email and password. For that app, *"the token stopped working"* and *"the merchant
must retype their password"* are the same event.

## The current state, measured

Five mint sites. Every one passes no abilities (so `['*']`) and no `expiresAt`:

| file:line | principal | route | client |
|---|---|---|---|
| `Api/AuthController.php:127` | Customer | `POST api/{slug}/register` (`['APILog']` only — no throttle) | unseen storefront app |
| `Api/AuthController.php:207` | Customer | **none — unrouted** | none (dead code) |
| `Api/AuthController.php:419` | Customer | `POST api/{slug}/forgot-password-verify-otp` | unseen storefront app |
| `Api/DashboardController.php:687` | User | `POST api/admin/adminlogin`, **and** a duplicate `POST api/adminlogin` (`routes/api.php:47`) | the shipped admin app |
| `Api/DashboardController.php:4327` | DeliveryBoy | `POST api/admin/delivery-login` | **a driver app in neither repo** |

- **No `config/sanctum.php` exists**, so Sanctum's default `expiration => null` applies: tokens
  never expire. `CustomAuth:66-68` is the only code that reads `expires_at`, and it can never fire
  because nothing ever sets it.
- **No route enforces abilities.** `Laravel\Sanctum\Http\Middleware\CheckAbilities` is imported at
  `routes/api.php:9` and never used; `tokenCan()` is called nowhere.
- **Login already revokes.** Both `DashboardController:686` and `:4326` call `tokens()->delete()`
  immediately before minting, so **single-session-per-principal is already the de facto policy**
  for merchants and drivers. That matters: a policy that ends old sessions is not a new idea here.
- **The single-token revoke exists in one place only**: `DashboardController:738-740` (`adminlogout`)
  deletes the one presented token, via raw `DB::table()` rather than Eloquent.

## The biggest unknown

**The delivery-driver app is in neither repository.** `POST api/admin/delivery-login` mints driver
tokens and nothing in the shipped admin app references that route. So the driver client's
behaviour on a refused request — whether it re-prompts, loops, or silently fails — **cannot be
established from this session.** Any expiry that reaches driver tokens is a change whose blast
radius we cannot see.

## The proposal, staged so each step is separately approvable and separately revertable

### Stage 0 — already done in this package, no approval needed

Revoke-on-password-change existed for exactly one principal on exactly one route. It now covers:

- `Api\AuthController::forgot_password_save` — all other sessions, plus **`remember_token`
  rotation**, which was missing: the storefront login calls `login($customer, true)` when "remember
  me" is ticked and Laravel's remember cookie is long-lived, so a reset that left the column alone
  let whoever held the account walk back in with a cookie.
- both **web** reset paths (`resetPasswordWithOtp` and the broker `resetPassword`) — they rotated
  `remember_token` but left every API token alive, so the recovery route a customer can actually
  reach in a browser did not evict an API attacker.
- `Api\DeliveryBoyApiController::resetPassword` — an operator forces a driver reset *because* the
  account is suspect, and the driver's token survived it.

### Stage 1 — observability, before any expiry. **No behaviour change, so no approval needed.**

Nothing today can answer "how old is the oldest token still in use?". `CustomAuth:88` already
writes `last_used_at` on every authenticated request, so the data exists.

- Add a read-only report (an artisan command) over `personal_access_tokens`: count by tokenable
  type, age distribution of `created_at`, and `last_used_at` recency.
- **Decision gate:** if the oldest actively-used token is older than any expiry being considered,
  that expiry will log people out. Measure before choosing.

### Stage 2 — abilities, which cost nothing to narrow. **Owner approval: yes, low risk.**

Every token carries `['*']`, so a token read out of a log (see S4) is a universal credential.

- Pass explicit abilities at each mint site: a shopper token does not need admin endpoints, a
  driver token does not need merchant ones.
- **Do not add `CheckAbilities` to routes in the same release.** Mint first, enforce later, so the
  two can be rolled back independently and tokens minted before the change are not stranded.

> **ADDENDUM 2026-10-29 — STAGE 2 IS DONE, AND A MEASUREMENT FROM IT CHANGES WHAT ENFORCEMENT
> COSTS (F189).** The abilities are now minted: `storefront` for the three customer sites,
> `merchant` for `DashboardController::login` (which serves the operator too), `delivery` for
> `deliveryLogin`. But the "enforce later" half is **not** a small follow-up, and the reason was
> measured during the review rather than assumed:
>
> `CustomAuth` logs the principal in with `auth('…')->login($tokenable)` and **never calls
> `withAccessToken($accessToken)`**. So `currentAccessToken()` is null on every `custom.auth`
> route; `HasApiTokens::tokenCan()` short-circuits to **false for every principal**, a `['*']`
> token included; and Sanctum's `CheckAbilities` throws `AuthenticationException` — a **401** — the
> moment it sees a null `currentAccessToken()`. `$request->user()` with no argument also resolves
> the default `web` guard, so a driver or customer token is null there too.
>
> **Adding `abilities:` to a route today would therefore 401 every caller of it**, and the shipped
> Flutter app force-logs-out on any 401. Enforcement's first job is to attach the token in
> `CustomAuth` (or to check in-handler), not to add middleware.
>
> **Stage 3 below is unaffected.** The merchant expiry does not go through Sanctum's guard:
> `CustomAuth:66` reads `expires_at` directly and refuses a past one. Measured, and it is the
> premise the stage rests on.
>
> Two further facts for whoever writes the enforcement stage, both in the ledger: **F191** — seven
> `api/admin` routes are reachable by more than one principal (and `adminlogout` by all three), so
> they need `CheckForAnyAbility` or an in-handler check rather than one ability each; and the
> merchant mint site is `DashboardController:846`, not the `:687` named below.

### Stage 3 — expiry for merchants only. **Owner approval: required.**

Merchants are the one principal whose client we can read and whose re-authentication path we know
works (the app posts email + password and stores the result).

- Set `config/sanctum.php` `expiration` — a long value first (30 days), not a short one.
- The admin app turns a 401 into a forced logout, which for this flow is the correct behaviour: the
  merchant sees the login screen and types their password.
- **Detection before users notice:** the Stage 1 report plus a count of 401s on
  `api/admin/*`. A spike means the value is too short.
- Sanctum's `expiration` is **global**, so this stage cannot be done through that key alone without
  also expiring customer and driver tokens. Implement it by passing `expiresAt` at the
  **merchant mint site only** — `Api\DashboardController::adminlogin`, measured 2026-10-29 at
  **`:927`** (this section cited `:687`) — leaving the global default null. That is the whole reason
  this is stage 3 and not stage 1.

#### Prepared 2026-10-29: everything Stage 3 needs already exists except the one argument

Measured, so the remaining work is one line behind a flag rather than a design:

| what Stage 3 needs | state |
|---|---|
| enforcement of `expires_at` | **already there.** `CustomAuth:66-68` — `if ($accessToken->expires_at && $accessToken->expires_at->isPast()) return $this->unauthenticated();` |
| a 401 the shipped app turns into a re-login | **already there.** `CustomAuth::unauthenticated()` returns the exact `ApiResponser` envelope with status 401, which repo B's `lib/network/network.dart:285-288` turns into a forced logout — the behaviour this stage wants |
| re-login minting a fresh token | **already there.** `adminlogin` does `$user->tokens()->delete()` then `createToken(...)`, so every login both revokes and re-issues |
| old tokens left alone | **free.** Existing rows have `expires_at` null and `CustomAuth:66` only refuses when it is set, so nothing is mass-invalidated — which is the owner's explicit boundary |
| customer and driver untouched | **free.** Their mint sites are `Api\AuthController:316/398/619` (`['storefront']`) and `DashboardController:4852` (`['delivery']`); none would take the argument |
| no global expiry | **free.** There is still no `config/sanctum.php`, and this approach never writes one |

So the whole of Stage 3 is: pass a third argument at `:927`, read from a config key that **defaults
to off**, so merging the mechanism changes nothing until the owner turns it on.

#### What remains BLOCKED, and it is not the mechanism

The owner's condition is *"do not apply an expiry policy to real production without a real usage
report and a review of its effect on active sessions"*. **That report cannot be produced from this
environment**: `tokens:inventory` (Stage 1) is written, tested and read-only, but there is no
production data here to run it against, and this session has no production access. The blocker is
therefore **data availability, not engineering**, and it is recorded rather than worked around.

What the owner needs before turning the flag on, in the order the report gives it:

1. `php artisan tokens:inventory` against production — it prints, per principal type, the age
   distribution across the 7/30/90/365-day edges, the dormant count, and specifically **how many
   merchant tokens are both in use and older than 30 days**. That last number is the one that
   decides whether 30 days is a re-login wave or a no-op.
2. A 401 count on `api/admin/*` for a week before and after, which is the detection signal this
   section already names.

If (1) shows a large in-use-and-older-than-30-days population, the first value should be larger than
30 days, or Stage 4 (idle expiry) should be preferred — `last_used_at` is already written on every
request, so idle expiry needs no new data either.

### Stage 4 — idle expiry, as a cheaper alternative to absolute expiry. **Owner approval: required.**

`last_used_at` is already written on every request.

- Refuse a token whose `last_used_at` is older than N days, in `CustomAuth` beside the existing
  `expires_at` check.
- Kinder than absolute expiry: an active user is never logged out, and an abandoned token dies.
- **Cost, measured:** `last_used_at` is declared `timestamp()->nullable()` with **no index**
  (`database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php:20`), and
  `CustomAuth:88` already writes it on every authenticated API call. A predicate on an unindexed
  column on the hot authentication path needs the index added first — which is a migration, so
  this stage carries a migration window that stage 3 does not.

### Stage 5 — customer and driver expiry. **Blocked, and should stay blocked.**

- **Customers:** blocked on there being a login endpoint at all. Expiring a customer token today
  forces every affected shopper through password recovery. Adding `Api\AuthController::login` to
  `routes/api.php` would unblock it — that is a small change, but it is a new public
  authentication surface and belongs in its own package with its own throttling and its own review.
- **Drivers:** blocked on being able to read the driver app. Do not expire a token whose client's
  failure behaviour is unknown.

### Stage 6 — pruning. **No approval needed once any expiry exists.**

`sanctum:prune-expired` is scheduled nowhere — `routes/console.php:9` schedules exactly one
command, `zid:process-events`, and `app/Console/` and `bootstrap/app.php` add none. It is a no-op
while nothing expires, and should be scheduled in the same
release as the first expiry — not before, or it will look like it works when it does nothing.

## What would break if a global expiration were switched on today

1. **Every shipped admin app session**, at once, on the day it is deployed — every merchant
   retypes their password. Recoverable, but it is a support event, and it should be announced.
2. **Every driver session**, with unknown client behaviour. This is the real risk.
3. **Every storefront customer session**, with no way back except password recovery — and the
   storefront recovery flow is itself the thing this project has spent three packages repairing.
4. Nothing else: no machine-to-machine integration holds a Sanctum token (the Zid and Shopify
   integrations authenticate with their own stored credentials, not Sanctum).

## Recommendation

Do stages 0 (done), 1 and 2. Hold 3 until stage 1 has numbers. Leave 5 blocked, and treat
"add a customer login endpoint" as a prerequisite to be scoped separately rather than a detail of
this work.

---

## Appendix — `Api\AuthController::login` is UNROUTED, and that is recorded as INTENDED

**P1-OWN-04 (#45), second acceptance criterion:** *"`Api\AuthController::login` is routed, or its
absence is recorded as intended."* This appendix is that record, and the answer is **intended** —
not as a shrug, but because routing it today would open a defect.

### The measurement

`app/Http/Controllers/Api/AuthController.php:328` declares `public function login(Request $request, $slug = '')`.
`routes/api.php` routes **five** of that controller's methods and not this one:

```
:46  POST {slug}/register                      register
:50  POST {slug}/forgot-password-send-otp      forgot_password_send_otp
:51  POST {slug}/forgot-password-verify-otp    forgot_password_verify_otp
:52  POST {slug}/forgot-password-save          forgot_password_save
:54  POST {slug}/logout                        logout            [custom.auth]
```

So the storefront API lets a customer **register**, **reset a password** and **log out**, and
gives them no way to log in. That is as odd as it sounds, and it is why the criterion asks the
question at all.

### Why it is recorded as intended rather than routed

**The handler is not safe to expose as written.** Its first statement is
`$store = getStore($slug)` — and `$store` is then **never used in the credential check**. The
authentication is:

```php
Auth::guard('customers')->attempt(['email' => $request->email, 'password' => $request->password]);
```

No store predicate. Whether that is cross-tenant depends entirely on whether a tenant resolves at
the time, because `Customer` **is** in `config/tenancy.php`'s `scoped_models` — and
`null_tenant => 'open'` means that when no tenant resolves the global scope **adds no predicate at
all**. A route reached by an unauthenticated caller is exactly the case where a tenant may not
resolve. Routing this method would therefore ship a login endpoint whose store-scoping is
unestablished, on the strength of a `$store` variable that is assigned and discarded.

The social-login branches below it (`google_id`, `facebook_id`, `apple_id`) build their own
`Customer::query()` and would need the same question asked separately.

### Why this costs nothing today

**No client calls the storefront API.** `routes/api.php:133-387` sits inside
`Route::prefix('admin')` and is what the shipped Flutter admin app uses; the storefront API outside
that group has no caller in either repository. So the missing login endpoint breaks nothing that
exists, and adding it would create a surface with no consumer and an open question.

### What would have to be true to route it

1. The credential check names the store — derived from the route's slug, not from request input.
2. The three social branches each do the same.
3. A negative test drives a customer of store A against store B's slug and asserts refusal, run on
   **MySQL** as well: `customers.email` comparison under `utf8mb4_0900_ai_ci` is case- and
   accent-insensitive, so a SQLite-only test proves less than it appears to.
4. Rate limiting, as the sibling `forgot-password-*` routes already carry (`throttle:auth`).
5. The token it issues carries the narrowed abilities of **Stage 2** above rather than `['*']`.

Item 5 is why this appendix lives in the token proposal: whenever a login endpoint is added, it is
the natural place for the ability narrowing D8 asks for, and routing it **before** Stage 2 would
mint more `['*']` tokens rather than fewer.

### Status

**Recorded as intended. Not routed in this phase.** If the storefront API ever gains a client, this
appendix is the list of what must be true first.

