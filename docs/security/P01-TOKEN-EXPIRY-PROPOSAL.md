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
  **merchant mint site only** (`DashboardController:687`), leaving the global default null. That is
  the whole reason this is stage 3 and not stage 1.

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
