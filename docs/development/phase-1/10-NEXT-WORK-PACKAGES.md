# 10 — Next Phase 01 work packages

Ordered. Each is independently reviewable, independently revertable, and scoped so one can be
rejected without blocking the next. Severity vocabulary and the findings themselves live in
[`../../security/P01-SECURITY-WORK-QUEUE.md`](../../security/P01-SECURITY-WORK-QUEUE.md).

**P01-SEC-10 is closed** by the password-recovery fix; it is listed below only to record what
remains attached to it.

---

## P01-SEC-13 — The web OTP reset cannot verify on MySQL  ·  **prepared, awaiting a migration window**

`Customer\Auth\CustomerLoginController:154` writes `Hash::make($otp)` — a 60-character bcrypt
string — into `customers.password_otp`, declared `integer()` by
`database/migrations/2023_12_15_112855_create_customers_table.php:39` with **no migration altering
it**. SQLite's dynamic typing accepts the string, which is why the flow passes in tests; MySQL
raises error 1366 under `STRICT_TRANS_TABLES` and coerces to `0` without it.

**The secure web reset therefore cannot complete on the production engine, and that is very
likely why the insecure API path existed.**

**It fails closed.** The verify step tests `empty($customer->password_otp)` at
`CustomerLoginController:391` before it reaches `Hash::check()` at `:413`, so a coerced `0` is
refused as "no active verification code". This is an availability defect, **not a bypass** — a
correction to how the first report left it open.

### What the verification package did with it

**Prepared, executed in isolation, and deliberately NOT runnable.**
`database/migrations/_sec13_pending/0001_draft_widen_customers_password_otp.php.draft` widens the
column to a nullable `VARCHAR(255)`. It follows the `_gate_f_pending/` convention already in this
repository: Laravel's migration glob is non-recursive and the file is named `*.php.draft`, so two
unrelated things must go wrong before a deploy could apply it.
`tests/Feature/Security/CustomerPasswordOtpColumnMigrationTest` loads the draft and **runs** `up()`
and `down()` against the test schema — row count, `email` and `password` unchanged, a pre-existing
numeric value preserved, a 60-character hash stored and verified afterwards, the rollback clearing
rather than coercing, and the guard making it a no-op where the column is absent.

`up()` is one DDL statement and reads or writes **no customer row**. Existing integer values are
left in place: they are already inert (refused by the 5-minute expiry, and unparsable as a hash).

### The migration alone would have made things worse, so two controls ship ahead of it

The web verify step had **no attempt limit** and neither of its POST routes had a **throttle** —
only the sibling login route at `routes/customer.php:102-104` did. The 5-minute expiry was the
sole bound on guessing a 6-digit code, and `customer.password.otp.send` could mint a fresh one
without limit.

None of that is reachable while the column is an INTEGER, because nothing can verify at all.
**Applying the widening by itself would have turned a dead flow into a brute-forceable one.** Both
controls are now in place and are safe to carry while the flow is still inert:

- `CustomerLoginController::MAX_OTP_ATTEMPTS` (5, matching the API service), counted in the session
  the flow already uses for its customer and store binding, destroying the code when exhausted;
- `throttle:auth` on `customer.password.otp.send` and `customer.password.otp.verify`.

### Preconditions on the owner, before this runs anywhere

1. `SHOW COLUMNS FROM customers LIKE 'password_otp';` — a server provisioned outside these
   migrations may already be `VARCHAR`, in which case drop the draft rather than run it. **The
   MySQL behaviour above was not executed:** this container has the PDO mysql client driver and no
   MySQL server, so it rests on the declared type plus documented MySQL semantics.
2. The attempt limit and both throttles must be in the same release.
3. A backup that has been restored at least once — the DDL rewrites the table on MySQL, which
   locks; size `customers` first.
4. A maintenance window. A reset in flight across the change fails and the customer re-requests.

**Rollback is lossy and says so:** narrowing back to `INTEGER` cannot represent a bcrypt hash, so
`down()` clears non-numeric values first, invalidating any reset in flight at that moment.

**Blocked on:** owner approval to run a migration on the dev server. Nothing else.

---

## P01-SEC-11 — The `{slug}` storefront API authorization pass  ·  **NEXT, and independently executable**

**This is the recommended next package.** It needs nothing from the owner: no migration window, no
mobile-client release, no production access.

### Why it is next

A verified sweep of all ~211 declarations in `routes/api.php` — reading each handler rather than
the queue — found **25 routes still open**, three of them critical. The pattern is uniform and so
is the fix, which is what makes the package tractable as one unit.

**Two root causes account for all of them:**

1. **`custom.auth` is authentication, not authorization.** It logs a token's owner into whichever
   guard matches the tokenable class, so a handler written as
   `if (! auth('customers')->user()) { $id = $request->some_id; }` is reachable by a **merchant's
   or a driver's** token with `auth('customers')` null, and takes the request-supplied branch. Five
   handlers of exactly this shape have now been closed (`change_password`, `logout`,
   `profile_update`, `AccountProfileController::store`, `DeliveryBoyApiController::resetPassword`);
   the rest of the family is below.
2. **`store_id` arriving in request input and being used as a tenant predicate.** A predicate built
   from a value the caller supplies compares the caller's own two values against each other and
   authorises nothing.

### Scope: the 25 open routes, by what they do

**Critical — writes that reach another tenant's data or another principal's account**

| | route | what it does |
|---|---|---|
| AUTHZ-1 | `POST api/{slug}/register` | on the non-`email` `register_type` path, finds an existing Customer by address with no store predicate, rewrites its identity and `store_id`, and mints a token for it — anonymous takeover plus a tenant move |
| AUTHZ-2 | `POST api/{slug}/user-delete` | `User::find($request->user_id)` — the **admin** model, bare id — then deletes that id's orders, addresses, wishlists and reviews, then the row |
| AUTHZ-3 | `POST api/{slug}/order-status-change` | sets delivery and payment status on any order platform-wide; the cancel path drives the wallet-refund observer |
| AUTHZ-16 | `POST api/admin/deliveryboyStore` | creates a driver principal with a caller-chosen password in any store — **latent**, see the note below |

**High — cross-tenant writes and PII reads**

AUTHZ-4 `product-return` (marks lines returned on any order, increments stock) · AUTHZ-5
`delete-address` · AUTHZ-6 `update-address` (re-points an address at the attacker's `customer_id`)
· AUTHZ-7 `change-address` (writes for an arbitrary id and returns that customer's PII) · AUTHZ-8
`update-user-image` (overwrites and `File::delete()`s another customer's file) · AUTHZ-10
`user-detail` · AUTHZ-11 `address-list` · AUTHZ-12 `order-list` / `return-order-list` · AUTHZ-13
`place-order` / `order-save` (places a real order for an arbitrary `customer_id`) · AUTHZ-17
`deliveryboysIndex` (**returns drivers' bcrypt password hashes** for any store) · AUTHZ-18
`deliveryboysUpdate` / `deliveryboysDestroy`

**Medium / low**

AUTHZ-14 cart and wishlist on an arbitrary id · AUTHZ-19 `deletereview` · AUTHZ-20
`product-rating` / `add-review` · AUTHZ-22 `payment-sheet` (mints a Stripe PaymentIntent of any
amount on the slug store's live secret key) · AUTHZ-23 `loyality-reward` · AUTHZ-24 `notify_user` ·
AUTHZ-25 `api/admin/notification` (500s for any non-web principal)

### The client-window question, resolved — this is the package boundary

The queue previously held P01-SEC-11 as *"needs a Flutter window"*. That is **not true of most of
it**, and the distinction is what makes this package independently executable:

- **The shipped ADMIN app's base URL is `/api/admin`**
  (`StoreSyria_app98/lib/utils/base_api.dart:3`), so it cannot call `api/{slug}/*` at all. Every
  `{slug}` row above can be fixed **without touching that client** — the same argument that let
  `deliveryboysReset-password` be fixed in place, verified there by a test that pins the journey.
- The **storefront** client that does call `api/{slug}/*` is in neither repository. For these rows
  that is not a blocker, because the fix is to derive the target from the authenticated principal
  instead of from request input — and a correct client already sends its own id. A client that was
  sending somebody else's id was exercising the defect.
- The genuinely blocked rows are the `api/admin/*` ones the admin app does call: AUTHZ-17 and
  AUTHZ-18. Those keep the window requirement and should be the package's last unit, or deferred.

### Decomposition — each unit independently reviewable and revertable

1. **The `customer_id`-from-input family** (AUTHZ-4..14, 20, 23, 24). One mechanical change
   repeated: take the principal from `auth('customers')`, refuse a non-Customer, ignore the posted
   id. One test per route asserting a merchant token, a driver token and another customer's token
   are each refused, plus one pinning the owner's own journey.
2. **The two destructive ones** (AUTHZ-2, AUTHZ-3). Separate because they need a decision about
   what the endpoint is *for*: `user-delete` operates on the **admin** model where the only
   plausible caller is a shopper deleting their own account.
3. **`register`** (AUTHZ-1). Separate because fixing it decides whether social sign-in should
   link-by-address at all; the interim is `unique:customers,email` scoped to the slug store on
   every `register_type`.
4. **`payment-sheet`** (AUTHZ-22). Separate because it touches money and a live Stripe key.
5. **The admin-app rows** (AUTHZ-17, AUTHZ-18). Last, or deferred to a client window.

### What it deliberately excludes

- Everything needing a migration (P01-SEC-13, the `delivery_boys` missing columns, a
  `last_used_at` index).
- Token expiry (`docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md`) — owner-gated, and stage 5 is blocked
  on there being a customer login endpoint at all.
- The rate-limiter architecture. Every recovery route shares one `auth` bucket keyed on
  ip + sha256(email), which collapses to one bucket per IP wherever no email is submitted. That is a
  design decision, not a defect to patch route by route.
- `S1` (BackupRestore) and `S2` (APP_KEY) — both owner-gated.

### A latency note that must not be lost

**AUTHZ-16 and AUTHZ-18 are not currently exploitable.** `DeliveryBoy::$fillable` lists
`commission_percentage` and `is_commission_enabled`, both handlers write them, and **no migration
creates either column** — measured against a real fully-migrated MySQL 8.0.46 schema. So both
endpoints answer 500 to every caller and their authorization holes are **latent**. Their gates are
already in place (closed in this package) and
`tests/Feature/Security/CredentialBoundaryTest::test_the_driver_table_still_lacks_the_columns_the_create_handler_writes`
fails the day those columns arrive, so the sequencing cannot be forgotten. This is a schema gap of
the same family as GATE F's missing tables and should join that register.

---

## P01-SEC-12 — `config/superadminaccess.php` vs the finance permissions  ·  **third**

The `RolePolicy::grantPermission` denylist has 13 names and omits the six money-moving finance
permissions, so a merchant with `Create Role` — every merchant — can grant a role authority the
merchant does not itself hold. No API impact, no migration, small and self-contained.

---

## P01-TOOL-1 — Agent tooling  ·  **DELIVERED**

> **All four items are done and committed.** The four skills the package assumed would need
> installing were **already available** — nothing was installed.
>
> | | where | verified by |
> |---|---|---|
> | project `CLAUDE.md` | `/CLAUDE.md` | `ClaudeConfigurationTest` (pointers resolve; length bounded) |
> | WorkDo PreToolUse hook | `.claude/hooks/guard-protected-paths.php` | 44 cases executed against the real script, both directions |
> | `tenant-authz-reviewer` | `.claude/agents/` | frontmatter contract, read-only tool set |
> | `/tenant-check` | `.claude/commands/` | runs only suites that exist; names the gate |
>
> **THE PLACEMENT DECISION BELOW IS REVERSED, by the owner.** This section recommended keeping all
> four *outside* the application repository, on the precedent of the P01B tooling commit (176 files
> of unrelated design skills). The owner's P01-TOOL-1 brief instead says "commit and push approved
> project configuration to the existing Claude A development branch", so `.claude/` is committed.
>
> That precedent is still respected, because the distinction it was really about is kept: what is
> committed is **project** configuration that is specific to this repository and useless outside it
> — its WorkDo allowlist, its test paths, its authorization pitfalls. **Personal** configuration
> stays out: `frontend-design`, `ui-ux-pro-max` and Claude B's five design skills remain in
> `~/.claude/skills/` (outside version control entirely — `/home/user` is not a git repository),
> and `.claude/settings.local.json` plus `CLAUDE.local.md` are now gitignored.
>
> **Two limitations, both measured.** The subagent and the slash command are registered at session
> start, so neither could be invoked from the session that created them — `Agent(subagent_type:
> tenant-authz-reviewer)` answered "Agent type not found" and `/tenant-check` answered "Unknown
> skill". Both become live in a new session in this repository. The tests compensate by holding
> both files to the documented frontmatter contract field by field, but **a live invocation of
> either is still unverified.**

### The package as originally scoped

Scheduled as its own package because tooling must not delay security work, and because each item
below changes how future work is done rather than what the product does. In priority order:

1. **A project `CLAUDE.md`.** The single highest-leverage artifact and there is none. Every
   session re-derives: Laratrust not spatie; `permissions_as_gates` disabled so `@can()` is always
   false; no super-admin bypass, so operator status is a `users.type` string comparison;
   `'admin'` and `'super admin'` are both authority literals; `Order` is not in
   `config/tenancy.php`'s `scoped_models`; no WorkDo module loads in any test run unless a test
   inserts an `add_on_managers` row; denials must be 403 because of `network.dart`; GATE F.
   Several of this phase's findings cost real time to rediscover.
2. **A `PreToolUse` hook refusing edits under `packages/workdo/`.** CI already catches these —
   after a push. The hook makes the wasted work impossible; CI stays the backstop.
3. **A `tenant-authz-reviewer` subagent.** Every major finding this phase — order documents, S12,
   S13, S15, the password reset — was the same defect shape, and CI could not see any of them;
   adversarial review found all of them. Seed it with the facts from item 1.
4. **A `/tenant-check` command** wrapping the existing guard scripts and the tenancy test subset.
   Wraps, does not duplicate.

~~Keep all four in `.claude/` **outside** the application repository unless the owner decides
otherwise~~ — superseded; see the decision recorded above. The P01B tooling commit (176 files of
unrelated design skills) remains the precedent for keeping *personal* tooling out, and it is
still honoured.

---

## P01-COMMERCE-SEC-02 — the last two order writers  ·  **DELIVERED**

> **Done.** Five commits. The full record, including what two adversarial reviews found in the work
> itself, is [`S33`](../../security/P01-SECURITY-WORK-QUEUE.md) — this entry is the summary.
>
> | | |
> |---|---|
> | `NMIPayController::getNMIProductStatus` | the platform's second card charge, previously unguarded. Its basket, line quantities and totals came back out of a **hidden form field**; the captured amount, the basket's store and an unusable quantity are now all settled **before** the card is charged; single-use state, restored on every path that took no money; atomic stock with give-back; `'Paid'` derived over a **recorded** gateway verification — the first of the nine |
> | `PaymentController::whatsapp` | keeps its `$request->all()` fallback (two checkout views need it), so the boundary is per field: the order's owner, its store, and a posted shipping figure that reached `orders.product_price` |
> | swept with them | the `out_of_stock_threshold`/`notification` crash across seven controllers, and the missing `App\Events\CinetPayPayment` class that 500'd **every legitimate CinetPay return after committing the order** |
> | shared | `AppliesCartStockChanges` — the stock decrement/give-back pair moved verbatim out of `PaymentController` so a fourth writer could use it rather than a second copy |
>
> **34 tests, 135 assertions. 18 guards removed and re-measured; 2 recorded as subsumed rather than
> claimed.** Three guards were not isolated by any test on the first attempt and a fourth group only
> became provable after new tests were written — both are recorded in S33, because this is the
> project's own "a test must bite" rule turned on its author.
>
> **The single most important finding came from adversarial review, not from the suite: this file's
> own NMI positive control was passing through an HTTP 500.** The order row committed and the
> journey then died on a bare `$response['tax_id']` read, and every refusal assertion in the file
> leaned on that control. Both positive controls now assert the response as well as the row.
>
> **Ten items remain open and are listed in S33 §7.** The clearest follow-up: `ProductVariant` is
> scoped by nothing anywhere, and NMI is now the only one of the nine handlers with a product-level
> store predicate — so the family is inconsistent, and `basketHasForeignProduct()` is already shared.

---

## P01-COMMERCE-SEC-03 — `ProductVariant` ownership & checkout tenant isolation  ·  **DELIVERED**

> **Done.** The full record is [`S35`](../../security/P01-SECURITY-WORK-QUEUE.md); the
> investigation it was planned from, with the corrections the delivery forced, is
> [`11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md`](11-NEXT-PACKAGE-PRODUCTVARIANT-TENANCY.md).
>
> **The first cross-tenant inventory write in this project's record that needs no module, no
> forged principal and no payment** — an ordinary customer of one store, their own token, two
> requests:
>
> ```
> POST /api/{slugA}/add-cart     product_id + attribute_id = store B's variant product
>   -> carts row persisted {product_id: B's, variant_id: B's, store_id: A's}, then HTTP 500
> POST /api/{slugA}/place-order
>   -> store B's product_variants.stock 50 -> 43, an order written in store A
> ```
>
> **One sentence explains all of it: `ProductVariant` carries no `StoreScope` and is not in
> `scoped_models`, so scoping the product does not scope the variant.** `addtocart` had been
> store-scoping its product since S19 and ignoring the null that lookup returned; the
> `attribute_id` branch then resolved the variant from the caller's own two values and wrote the
> posted `product_id` verbatim — before the dereference that produced the 500, with no transaction,
> so the poisoned row survived the error the caller saw.
>
> Fixed: the null is refused at the cart entry; two dead emptiness guards moved above the
> dereferences they were meant to prevent; `place_order`'s ownership refusal hoisted out of the
> stock-management arm (reversing S34's deliberate non-mirroring, because measurement overtook the
> argument); `basketHasForeignProduct()` extended to the variant; six checkout preflights wired.
>
> **15 tests, every one driving the whole journey through real routes. Eight controls removed and
> re-measured — all eight bite, and three of them failed nothing on the first run.** One of those
> three is worth remembering: a test asserting "no cart row was written" passed with its guard
> removed, because without the guard the route **500s** and writes nothing either way. The fix was
> to assert the response is not a 500 first — a refusal and a crash are not the same outcome.
>
> **Owner decisions left open**, in S35's final section: whether any `products`/`product_variants`
> rows have a NULL `store_id` (one query, and it decides which of the two predicate rules this
> codebase should keep), `confirm_order` and `PaymentService::getProductStatus` (both mutate stock
> with no store predicate and no preflight), and the global-scope question this package was told
> not to take.
>
> **Two of those were closed by the FOLLOW-UP package below**: `PaymentService::getProductStatus`
> now carries the pre-flight twice (S36-2), and `confirm_order` turned out **not** to mutate stock
> at all — the block labelled "Order stock decrease" contains no write; what it does carry is a
> cross-tenant existence probe, recorded in S36's residuals.

---

## P01-COMMERCE-SEC-03 FOLLOW-UP — what the review of SEC-03 found  ·  **DELIVERED**

An independent `tenant-authz-reviewer` pass over SEC-03's own commit, briefed to assume nothing it
had just been told. It reported five findings; four were real on re-reading, and a completeness
sweep of the order writers found a sixth site that neither SEC-03 nor the review had named.
Full record in [`../../security/P01-SECURITY-WORK-QUEUE.md`](../../security/P01-SECURITY-WORK-QUEUE.md),
section **S36**.

| # | What | Status |
|---|---|---|
| S36-1 | `addtocart` validated `attribute_id` and then stored raw `$request->variant_id` — "the pair that is checked is not the pair that is written". Two sibling fall-throughs on the same handler, one of which priced a cart line from another tenant's `variation_price` | **closed** at source plus one gate on the pair about to be persisted |
| S36-2 | The web `cod` / `bank_transfer` order writer (`PaymentService::getProductStatus`) had **no** ownership check — the one live host-app order writer SEC-03's six-site wiring missed | **closed** twice: caller and writer |
| S36-3 | The Authorize.Net capture had the quantity guard and not the ownership one, and it charges the card itself | **closed** pre-charge, from the stored state |
| S36-4 | An **orphan decrement**: a poisoned second basket line left the first line's stock deducted with no order | **closed** by hoisting the pre-flight above both API stock loops |
| S36-5 | `ordersave`'s dead guard (`explode()` before the emptiness test) — the twin SEC-03 corrected on `place_order` only | **closed**, and recorded as *subsumed* defence in depth |
| S36-6 | `Api\DashboardController::createOrderViaApi` — **`api/admin/createOrderViaApi`, the prefix the shipped admin app calls** — proved store ownership and product existence and never tied them together | **closed** with a store predicate and a variant pairing |
| S36-7 | the **web** cart writer `CartController::product_cartlist` had the same unpaired variant on its authenticated arm — and, with the checkout guards now refusing such baskets, a shopper could poison their own cart and be unable to check out | **closed**: paired, plus the same gate before the write |
| S36-8 | the quantity **ceiling** was missing from all three cash-on-delivery writers. Measured: qty `2147483647` drove stock 50 → **−2,147,483,597** and wrote a **`Paid`** order for **21,474,836,470** on `process-order`, `place-order` and `order-save` alike | **closed** with the shared helper at all three; **and it corrects a refutation of mine** — I had answered the same finding by naming their `qty <= 0` floor |
| S36-9 | the `explode()`-before-`empty()` shape in **8** reachable methods, one of them **post-capture** (`AuthorizeNetPaymnetController:574`) where the crash left the card charged with no order row | the post-capture copy **closed** (not test-provable here — the SDK is unreachable); the other seven **recorded** |

> **Three measurements worth carrying forward, because each was a control or a test that proved
> nothing until it was measured:**
>
> 1. **A guard that read the wrong level.** The Authorize.Net pre-flight first passed
>    `storedCheckoutCartList($state)` — the cart list — where the *lines* live under
>    `product_list`. It would have refused nothing while every test passed. Caught by reading the
>    one sibling call site, not by the suite.
> 2. **A fixture whose insertion order decided the result.** The orphan-decrement test passed with
>    the fix reverted, because the cart list is served **newest row first**, so the poisoned line
>    was processed first and nothing had been decremented yet. The rows had to be inserted the
>    other way round.
> 3. **A fix of mine that was redundant.** I pinned `slug` and `customer_id` in `processOrder`
>    against a writer that reads both from input — and the removal check showed
>    `processBillingInfo()` already merges both from the server-resolved values, unconditionally.
>    The pin was removed again; the test stayed, because nothing had pinned that existing control.
>
> **Still open after this package** (in S36's residuals table, with the reason each was not taken):
> `PosController::store`'s absolute `product_stock` write, the merchant-admin `ProductController::update`
> binding, `Order::product_return`'s unscoped restock, the six module arms of `addtocart`, the
> unrouted `PayUPaymentController` writer, a 500 in the cart-list builder at `ApiController:654`,
> and `confirm_order`'s cross-tenant existence probe. The NULL `store_id` query and the
> `scoped_models` question remain **owner decisions**.

---

## P01-MERCHANT-SEC-01 — the merchant surface  ·  **RECOMMENDED NEXT**

Four cross-tenant writes that are **not** the cart → order chain, three of them confirmed by
execution. Full evidence, with line numbers and the measured movements, in
[`../../security/P01-SECURITY-WORK-QUEUE.md`](../../security/P01-SECURITY-WORK-QUEUE.md) section
**S37**.

The shape is one sentence: **a permission check standing in for an ownership check**, on models that
no global scope filters. `isAbleTo('Edit Products')` answers *may this user edit products* and never
*may this user edit THIS product*.

| # | site | measured |
|---|---|---|
| S37-1 | `ProductController::update` — route-model-bound `Product $product`, no `store_id` comparison in the method | a store-A merchant renamed store B's product and set its stock 50 → 7 |
| S37-2 | `OrderController::order_return` → `Order::product_return` — `order_id` from request input, no permission check, no ownership predicate | moved store B's variant stock 50 → 59 and marked B's order returned |
| S37-3 | `PosController::store` — an **absolute** `product_stock` write from the POS session, fed by two unscoped `Product::find()` resolvers | moved store B's `product_stock` 50 → 49 |
| S37-4 | `WoocomProductController::edit` — overwrites a foreign product's stock and then **reassigns its `store_id`** to the caller | code-verified only |

**Take S37-1 first:** the simplest predicate, it reaches both the catalogue and inventory, and
S37-3's feeder (`ProductController::addToCart`) is in the same file.

**Verify S37-1 against a real MySQL.** The probe found SQLite *masking* it through a `NOT NULL`
column the handler writes as NULL; MySQL with `strict => false` coerces it, so **production is more
exposed than the test database** — the reverse of the direction this project has been bitten in
before. The pattern is in `tests/Support/Security/bin/`.

**Scope note for whoever takes it:** none of the shared basket pre-flights applies here — no shopper
principal is involved — and S37-3 in particular *cannot* reuse `basketHasForeignProduct()`, because
POS rows are plain arrays keyed `id`/`quantity`/`originalquantity` rather than the cart-row shape the
helper walks.

---

## Still owed before Phase 01 acceptance

Unchanged from [`09-SINGLE-DEVELOPER-TRANSITION.md`](09-SINGLE-DEVELOPER-TRANSITION.md), plus:

| # | Item | Owner action |
|---|---|---|
| 1 | Password-recovery **server exposure** | Confirm whether the route was reachable; block it at the edge until the fix deploys |
| 2 | `storage/logs/api-*.log` on the server | Treat 15 days of it as compromised material; rotate anything found |
| 3 | Operator and administrator passwords | Rotate — they were settable by anyone who knew an email address |
| 4 | S12(c) anonymous `customerpayment` document | Decide: gate it, or accept link-bearer access |
| 5 | Customer-facing documents generally | Decide: scoped expiring token, or accept |
| 6 | Branch protection + required checks on `main` | Repository setting; no script can assert it |
| 7 | `BackupRestore` active on the dev server? | `SELECT module, is_enable FROM add_on_managers` also answers S11's triage |
| 8 | GATE F — six undeclared money tables | Owner/DBA |
