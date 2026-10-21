# ⛔ DO NOT MERGE

**This branch is Phase 01 development in progress. Final integration into `main` is not authorised.**
It is a Draft so that CI runs the two gates which only exist on a `pull_request` event — the
merge-base regression gate and the security attestation — against the real diff.

> ### The attestation gap flagged here is now closed, by §10
>
> This notice used to say that four P01-COMMERCE-SEC-02 commits sat beyond §8's review with no
> checklist entry of their own. There are now **eleven** such commits: **§10 attests to the seven up
> to `611357e6`** and **§11 to the four after it**, both written by the session that wrote them and
> worked against their actual diff.
>
> **§8 still covers only `79a8ca88` and must not be read as covering them** — it is left exactly as
> its author wrote it, scoped as its author scoped it. §9's figures are likewise `79a8ca88`'s, and
> §10's are `611357e6`'s. §11 carries the current head's and was EXTENDED, by its own author, as
> two further commits landed — it says so, and says what changed. Three attestations, each honest
> about what it looked at, rather than one silently re-signed.

---

## This description was rewritten, and the previous one was wrong

The body this replaces described **only** the P01-A′ CI work and asserted:

> *No application code is touched: zero files under `app/`, `packages/`, `routes/`, `config/`, `database/`, `resources/`, `themes/`, `bootstrap/` or `public/`.*

**That assertion is false and is withdrawn.** It was true when written and stopped being true as
Phase 01 continued on the same branch. The diff touches seven of those nine directories:

| directory | files | claimed |
|---|---|---|
| `app/` | 44 | zero |
| `resources/` | 4 | zero |
| `database/` | 2 (a `.draft` + a README — nothing runnable) | zero |
| `routes/` | 1 | zero |
| `config/` | 1 | zero |
| `bootstrap/` | 1 | zero |
| `public/` | 1 | zero |
| `packages/` | **0** — the vendor guard holds | zero ✓ |
| `themes/` | **0** | zero ✓ |

A security attestation that describes the wrong diff is worse than none, because the checklist it
satisfies is the one control standing between these paths and `main`.

**Those were the figures at `68091f3c`. At the current head `5f5f96b6` the diff is 209 files,
+70,786 / −1,064, 131 commits**, against `main` at `1eebc721` — and the table above is likewise
`68091f3c`'s. Both are superseded by §12, which carries the counts at `5f5f96b6`, the directory
breakdown, and an attestation for the 54 commits `68091f3c..5f5f96b6` that no earlier section
examined — then by **§13, which attests to the 17 commits `5f5f96b6..4a55987d`** (222 files, +76,089 / −1,123, 148 commits at `4a55987d`) —
and then by **§14, which attests to the 10 commits `4a55987d..9f031e72` and carries the current figures: 244 files, +79,848 / −1,205, 158 commits.**
A commit pushed after `9f031e72` is outside every section until a later one covers it. (The figures here are left rather than overwritten because the paragraph they sit in is
the record of a withdrawn claim.)

---

## 1. CI foundation (P01-A′)

The work the original description covered, unchanged and still accurate: the sensitive-diff
checklist that ran nowhere on a push; the vendored-module guard that saw one commit of a push; the
Contract suite missing from `tests-degraded`; a network failure reading as a new advisory; the
Flutter contract check blind to 68 of 69 module route files; no repo-wide static validation.

`AUDIT_CEILING` stays **58**. `.github/known-test-outcomes.json` is **unmodified** — nothing was
added to it to obtain green, which its own README forbids.

**`laravel/pint` was rejected on measurement**: against three files written fresh for this branch in
the surrounding style, the default `laravel` preset reports five rule violations on all three. It
needs a `pint.json` codifying the existing style, or an authorised reformatting pass — **owner
decision**.

## 2. The CI workflow conflict with `main`, reconciled rather than resolved

`main` advanced to `1eebc721` during development. One file conflicted — `.github/workflows/ci.yml`
— and the conflict was **not cosmetic**. Both sides had removed the *same* step from
`regression-gate` and relocated it, each correctly fixing a different hole in the same control:

- **this branch** moved it into `ci.yml`'s `static` job, which runs on every event — closing the
  **push** hole, since `regression-gate` is `pull_request`-only and almost every commit here
  arrived by direct push.
- **`main`** moved it into `.github/workflows/security-attestation.yml`, whose trigger includes
  `edited` — closing a different hole, where a reviewer signs the checklist and the gate stays red
  because `ci.yml` does not re-run on a description edit.

**Neither side was selected.** Keeping both would have left two *enforcing* copies of one gate —
worse than either, because clearing one leaves the other red for a reason invisible from the checks
list. One caller per event class:

| event | workflow | mode |
|---|---|---|
| pull request | `security-attestation.yml` (main's, incl. `edited`) | **enforcing** |
| push | `security-attestation-push.yml` (this branch's coverage, relocated) | **report only** |
| — | `ci.yml` | runs the gate script **nowhere** |

`main`'s placement wins the pull-request path because it also clears the `edited` trap, and its
`SecurityAttestationWiringTest` pins that by asserting `ci.yml` never names the script. **All 6 of
its assertions pass unmodified** — nothing of `main`'s was weakened or deleted to accommodate this.

`flag-sensitive-diff.sh` auto-merged and **both halves survived, verified rather than assumed**:
`main`'s widened `PATTERN` (`Providers/`, `RateLimiter`, `Throttle`, `database/migrations/`,
`LynomiaChat/src/app/` — the surfaces the rate limiters live on) **and** this branch's three-mode
interface. They compose because the mode is a third optional argument defaulting to `enforce`.

**Reporting is not reviewing.** `report` mode exits 0 on an unacknowledged sensitive diff. What
enforces the checklist on a push is branch protection requiring pull requests — an owner setting no
workflow can assert.

### New gate-wiring regression tests

A semantic merge is exactly the operation that drops a check by accident: the conflict resolves, the
file parses, the suites pass, and one guard went with the losing hunk.

- `test_the_security_sensitive_diff_checklist_runs_on_every_event` — **rewritten.** It asserted the
  step was in `ci.yml`, which is no longer where it lives. It now asserts the *property* across the
  whole workflow directory: both event classes covered, by unconditional jobs, and not any location.
- `test_the_gate_is_enforced_in_exactly_one_place_and_reported_in_the_rest` — **new.** Exactly one
  enforcing caller; every other explicitly `report`; and the mode must be a **literal**, not
  computed at run time.
- `test_no_required_gate_was_lost_in_the_workflow_merge` — **new.** Twelve controls pinned by name
  across the directory: secret screen, sensitive-diff checklist, WorkDo guard, GATE F containment,
  known-outcome assertion, merge-base regression gate, Flutter API contract, auth-coverage contract,
  Contract suite, audit ratchet, repo-wide `php -l` floor, design-system contrast.

Each removal-checked: deleting the push workflow fails 2; making it enforcing as well fails 1 (the
duplication); making its mode a variable fails 1.

## 3. Tenant and authorization security

**P01-SEC-11 / S19 — the `customer_id`-from-request family.** 26 storefront handlers took their
subject from the request body. The platform's trap is that `app/Http/Middleware/CustomAuth.php`
logs a Sanctum token's owner into the guard matching its **tokenable class**, so a merchant's or
driver's token passes `custom.auth` while `auth('customers')` stays null — and every handler shaped
`if (! auth('customers')->user()) { $id = $request->some_id; }` is reachable by a non-customer and
takes the attacker-controlled branch. Closed behind one shared predicate,
`ApiController::resolveStoreCustomer()`.

**P01-SEC-2 — order documents.** Anonymous and cross-tenant access to tenant-owned order documents,
plus four more routes its own ratchet could not see, plus an alias route reaching a guarded action.

**S12, S13, S14** — order-detail API ownership; a merchant minting a platform operator through user
creation (and the three follow-ups the first commit left open); the order export gate.

**No new 401 on any path a shipped client calls.** `ApiResponser::error()` returns HTTP 200 with
`{status: 0}`, and the shipped Flutter app force-logs-out on any 401, so 403 is the established
refusal code and the one used throughout.

## 4. Password recovery

The anonymous admin-reset takeover and the fixed password, then the eleven findings that fix left
open, then the password-change surface: five handlers of one shape, and two brokers sharing one
token table. The two OTP POSTs gained `throttle:auth` — `send` could be driven to mint codes
without bound (SMS and e-mail cost real money per message here) and `verify` could be driven to
guess them; the in-handler attempt limit bounds guesses per *code*, which is the half a rate limit
cannot do. `S13` on `customers.password_otp` has a prepared-and-**unapplied** migration — see §7.

## 5. Design System handover (Claude B)

The imported token and component foundation, one pilot screen, and the dark-mode and
focus-indicator corrections an independent verification found. `check-contrast.py` is now **wired
into CI**: it resolves every `--ss-*` `var()` chain and computes real WCAG 2.1 ratios for 40
foreground/background pairs across light and dark, and fails if the `auto` scope drifts from `dark`.

**The browser half is deliberately not wired.** `browser-checks.cjs` covers focus rings, tab order,
RTL geometry and overflow at 7 widths — 66 checks, all passing at handover — but needs Playwright,
Chromium and eight rendered fixtures. That is a dependency and runtime decision for the owner; it
stays a documented manual run.

## 6. Commerce and payment corrections

**P01-SEC-14** — identity and payment trust boundaries: provider tokens verified server-side rather
than asserted; the amount owed proof against a posted price, currency, coupon code, shipping price
or negative quantity on both the card and COD paths; `notify_user` failed closed; four coupon
lookups store-scoped. Two adversarial review passes are themselves commits (E, F), and F closed a
HIGH that bypassed E's guard through COD. G closed the password `1234` on eight vendored
social-login modules. H closed the web half of the payer setting `orders.final_price`.

**P01-COMMERCE-SEC-01** — 17 commits. Seven storefront payment-return handlers fell back to
`$request->all()` when the session held nothing, so a caller who omitted the session cookie supplied
the cart, prices, shipping, coupon, the **store** the order is written to and the **customer** it is
written for. Closed behind a shared `app/Http/Controllers/Concerns/GuardsGatewayReturn` trait. Four
live defects were then found by *testing* the fix, each measured end-to-end through the real routes:

| defect | measurement |
|---|---|
| a shipping method could price a checkout it does not own | `method_id` posted at **initiation** made the server itself compute `cartlist_final_price` **50.0** for a 100.00 basket; the return then posted no money fields and wrote `final_price` **50.0** |
| another merchant's stock drained through this store's cart | `POST /{storeA}/product_cart` with store B's `product_id` → 200 "added successfully"; a legitimate store-A return → store B's stock **90 → 83**. Two anonymous requests, no payment |
| the quantity guard had a floor and **no ceiling** | `qty 2147483647` took a 50-unit product to **−2,147,483,597**, order written |
| a guest return could be claimed for another customer | posting `customer_id=<victim>` wrote an order **owned by the victim** and emptied their basket |

**Corrections to my own earlier claims are recorded in the queue**, including two removal checks
that went stale *inside* the package when a later fix subsumed an earlier guard, and one "critical"
finding from a reviewer that measurement **refuted** (`final_sub_total` — inert, gated by S24).

**P01-COMMERCE-SEC-02, S34, P01-COMMERCE-SEC-03 and its FOLLOW-UP** — eleven commits (`5110c64b`,
`8b301e24`, `b8b918a0`, `e8753695`, `d69f2f17`, `609631c1`, `611357e6`, then `c4a5f3f6`,
`db508dc4`, `db385f6d` and `68091f3c`), all added **after** the review in §8 and by a different
session from it. The first seven are **attested in §10** and the last four in **§11**, by that
session, against their own diff and with their own measurements. The paragraph that used to stand here described them from their
commit messages only; that is no longer the best available account of them.

The follow-up is what an independent `tenant-authz-reviewer` pass over `611357e6` produced, and it
is worth reading as a result in its own right: **five findings against this project's own freshly
shipped fix, four of them real**, including the sentence that names the whole class —
*the pair that is checked is not the pair that is written* — plus the **web cash-on-delivery order
writer**, which had no ownership check of any kind, and `POST api/admin/createOrderViaApi`, which
proved the caller owns the store and that the product exists and never tied the two together. S36
in `docs/security/P01-SECURITY-WORK-QUEUE.md` carries all of it.

## 7. Remaining blockers

- ~~**Two unguarded order writers.** `NMIPayController::getNMIProductStatus` and
  `PaymentController::whatsapp`.~~ **Closed by P01-COMMERCE-SEC-02 and attested in §10.**
- **No gateway return proves a payment moved.** `'Paid'` is now *derived* through
  `PaymentVerificationService` so every unverified write is logged, but the posture is unchanged by
  design: enforcing it would stop merchant wallets being credited for **51 live payment methods**.
  **Owner decision.** Khalti's verification is *amount-unbound* and needs its minor-unit convention
  to bind — a credential-blocked blocker, not a guess. §10 records the one movement here: NMI now
  records a real gateway verification, and the service's log no longer claims "no verification
  seam" over a write that had one.
- **No idempotency key** on any return; replay is blocked only because the stored state is
  single-use — now on the NMI card charge too, where a replay was a second *charge*.
- **GATE F** — 15 tables live code reads and writes have no migration, 6 money-bearing. Drafts held
  unrunnable in `database/migrations/_gate_f_pending/`.
- **S13 migration prepared and NOT applied** — `customers.password_otp` is `INTEGER` while the web
  reset writes a 60-char bcrypt hash. The draft is `.php.draft`, so Laravel cannot load it.
- ~~**Two of the four pre-existing crashes** remain.~~ **All four are now closed** — the
  `out_of_stock_threshold`/`notification` crash, the missing `App\Events\CinetPayPayment` class, and
  two post-charge crashes on the NMI path. See §10.
- **`Product`, `ProductVariant` and `ShippingMethod` remain globally unscoped.** What shipped is
  containment at the caller, **four packages and nine order writers** running. Adding them to `config/tenancy.php`'s
  `scoped_models` is a platform-wide change to every read with `null_tenant => 'open'` underneath
  it, and needs its own compatibility decision. **Owner decision.**
- **A NULL `store_id` question that one query answers**, and this environment cannot:
  `SELECT COUNT(*) FROM products WHERE store_id IS NULL;` and the same for `product_variants`. Three
  routes now use a **hard** `store_id` predicate while the shared helper accepts NULL as "not a
  provable mismatch" — **six** paths after the follow-up (`add-cart`, `place_order`, `ordersave`,
  the admin API order writer, and the gate before the cart write in each of the two cart writers). If no such rows exist, make the
  tolerant rule the only rule. If they do, those predicates need the tolerant form plus a
  compensating control. **Owner decision** — see §10 and §11.
- **NEW, and the recommended next package: four cross-tenant writes on the MERCHANT surface**,
  outside the cart → order chain, **three confirmed by execution** — `ProductController::update`
  (a store-A merchant renamed store B's product and set its stock 50 → 7),
  `OrderController::order_return` → `Order::product_return` (moved store B's variant stock 50 → 59
  and marked B's order returned), `PosController::store` (an **absolute** `product_stock` write from
  the POS session), and `WoocomProductController::edit`. One sentence covers them: a **permission**
  check standing in for an **ownership** check, on models no global scope filters. Written up as
  **S37** with line numbers and measured movements, and as `P01-MERCHANT-SEC-01` in the work-package
  document. Not taken here: each needs its own predicate and test, and `ProductController` is a
  ~1,600-line resource controller whose `store`/`update`/`destroy` share the binding. **One caveat
  for whoever takes it: SQLite MASKS S37-1 through a `NOT NULL` column the handler writes as NULL,
  so production is MORE exposed than the test database** — the reverse of the direction this project
  has been bitten in before.
- **`laravel/pint`**, the **browser half** of the design-system checks, and **branch protection**
  requiring pull requests — all owner decisions.

Open security work is itemised in `docs/security/P01-SECURITY-WORK-QUEUE.md`.

## 8. SECURITY-DIFF-REVIEW: completed — for `79a8ca88`

**Scope: this review covers the diff up to and including `79a8ca88`. The seven commerce commits
after it are NOT covered here — they are attested separately in §10.** Worked against the real
diff, not the CI-only diff the previous body described. The widened `PATTERN` flags **16** paths,
unchanged on the current head:

```
.github/scripts/flag-sensitive-diff.sh            app/Http/Middleware/Concerns/RedactsApiLogFields.php
.github/workflows/ci.yml                          app/Http/Middleware/OrderDocumentAccessGuard.php
.github/workflows/security-attestation-push.yml   app/Policies/RolePolicy.php
app/Http/Controllers/RoleController.php           app/Services/SocialIdentity/ProviderCredentials.php
app/Http/Middleware/APILog.php                    bootstrap/app.php
app/Http/Middleware/AdminApiLog.php               config/auth.php
routes/customer.php                               database/migrations/_sec13_pending/{.draft,README.md}
tests/Feature/Security/CredentialBoundaryTest.php
```

**1. What this REMOVES or NARROWS.** The attestation step is gone from `regression-gate` (both sides
removed it) and from `static` (this reconciliation). Its behaviour is not dropped — §2. No test,
check, guard or threshold was removed or lowered; `AUDIT_CEILING` and the known-outcome manifest are
untouched. `|| true` was removed from the composer-audit capture, which narrows nothing — the
failure is now examined instead of discarded. Counted: `config/auth.php` +28/−1, `bootstrap/app.php`
+29/−1, `routes/customer.php` +8/−3, and in every case the deletions are the same lines re-emitted
with a control **added** (middleware chained onto two previously unlimited OTP routes).

**2. Did any file already do something else?** `flag-sensitive-diff.sh` enforced by default and
still does; the third argument defaults to `enforce`, so `main`'s two-argument call is unchanged in
behaviour. `check-contract.php` globbed one casing and now globs both — old behaviour is a strict
subset. `RedactsApiLogFields` is new and only redacts.

**3. What control assumed the widened surface was narrow?** The WorkDo guard's reach widened from
one commit to a whole pushed range; a guard can only see *more*, and a newly-visible pre-existing
`packages/workdo/` edit could now fail a build that previously passed — disclosed here rather than
discovered. The sensitive `PATTERN` widened on both sides; a wider pattern asks for the checklist
more often and never less. The repo-wide `php -l` was measured green on 3,413 files before being
made blocking, and carries a non-vacuity floor.

**4. Audit / logging / compliance emission.** Nothing removed, skipped or made conditional.
**Added**: API-log field redaction (`RedactsApiLogFields`, after a measured plaintext-password leak
into `storage/logs/api-*.log`), a step-summary record whenever the checklist is unacknowledged, a
distinct diagnosis for a transport failure, and a log line on every unverified `'Paid'` write.

**5. Does any failure become silent?** One, deliberately and visibly: a push with sensitive paths
and no acknowledgement exits 0 with `::warning::` plus a run-summary entry, where before **it did
not run at all**. Previously silent, now loud and non-blocking — and the file says it is not
enforcement. An unknown mode exits 2 rather than degrading. Everything else stays blocking.

**6. Method-resolution order?** One new trait, `GuardsGatewayReturn`, used by 7 controllers; it
mirrors the existing sibling `GuardsPlanActivation` and defines only new method names — verified by
running each handler's suite. No class hierarchy, prepend or vendored concern is touched. (That
trait was subsequently extended by the commits §10 covers.)

**7. Verified by removing it?** Yes, every guard in this review's scope, individually, with the
measurement recorded in its own commit. Fifteen removal checks across the commerce package alone;
three on the new gate-wiring tests; three on the CI controls. **Two earlier removal checks are
marked STALE in the queue** because a later fix subsumed them — found by re-measuring rather than
assumed, and the guard they covered is now documented as unproven defence-in-depth rather than
claimed as proven.

**8. Merge-base regression gate?** Runs on this PR. The figures in §9 are local, for `79a8ca88`;
**GitHub's result is authoritative**.

## 9. Measured locally at `79a8ca88`

These are **not** measurements of the current head — the seven later commits are measured in §10.

| Suite / gate | Result |
|---|---|
| Unit | **278 passed**, 1,527 assertions |
| Contract | **26 passed**, 83 assertions |
| Feature | **2,691 passed**, 17,932 assertions, 2 failures, 17 skips, 0 errors |
| Known-outcome gate | **EXIT 0** — 2,710 testcases; observed set matches the manifest exactly |
| Secret scan — package-scoped | **exit 0** (27 files) |
| Secret scan — full PR, as CI runs it | **exit 0** (117 files) |
| WorkDo vendor guard | **exit 0** — `packages/workdo/` unmodified |
| GATE F containment | **exit 0** |
| Flutter API contract | **exit 0** |
| API auth coverage | **exit 0** |
| Design-system contrast | **exit 0** |
| All three workflow files | parse |

**The Feature suite is not green and is not meant to be.** It carries 2 accepted failures and 17
accepted skips declared by name in `.github/known-test-outcomes.json`; the gate asserts the outcome
*set* in both directions, so an undeclared failure **and** an unexpectedly-passing declared failure
both fail it. Reading "2 failed" as a problem is a misreading — the gate is what decides, and it
exits 0. The manifest is **unmodified** by this branch.

**One fragility observed and not changed:** two `markTestSkipped` environment guards in the API-log
tests ("The API log was not written in this environment") are **not** among the 17 declared skips.
They do not fire in the authoring container or on the runner, so the gate passes in both. If a
runner ever fails to write that log they would fire and break the gate's declared skip set. Flagged
rather than restructured.

Tests run on SQLite `:memory:`; production is MySQL, whose type affinity differences have already
hidden one real defect here (S13). Schema-sensitive evidence is verified against a real MySQL by
scripts under `tests/Support/Security/bin/`.

---

## 10. SECURITY-DIFF-REVIEW: completed — for the seven commerce commits `79a8ca88..611357e6`

**Scope: `5110c64b`, `8b301e24`, `b8b918a0`, `e8753695` (P01-COMMERCE-SEC-02), `d69f2f17`,
`609631c1` (S34) and `611357e6` (P01-COMMERCE-SEC-03).** Written by the session that wrote them,
against their own diff. §8 is not re-signed and still means exactly what its author said.

**Files touched, all of them:** `app/Events/CinetPayPayment.php` (new),
`app/Http/Controllers/Concerns/AppliesCartStockChanges.php` (new),
`app/Http/Controllers/Concerns/GuardsGatewayReturn.php`, `app/Http/Controllers/Api/ApiController.php`,
`app/Http/Controllers/{NMIPay,Payment,AuthorizeNetPaymnet,CinetPay,Easebuzz,KhaltiPaymnet,PaiementProPayment,PayUPayment}Controller.php`,
`app/Models/Cart.php`, `app/Services/NMIService.php`, `app/Services/PaymentVerificationService.php`,
four test files and three documents.

**NONE of these paths is flagged by the sensitive `PATTERN`.** Verified: the 16 flagged paths listed
in §8 are unchanged by these seven commits, which touch no middleware, no policy, no route file, no
`config/`, no `bootstrap/`, no migration and nothing under `.github/`. The checklist is answered
anyway, because the gate is PR-level and because the diff moves money and inventory.

### 1. What does this REMOVE or NARROW?

**One protection was removed by accident, found by review, and restored.** `d69f2f17` corrected a
`??`/`==` precedence bug so that stock management OFF stops deducting stock — and
`ApiController::ordersave`'s refusal for *a product this store does not own* lived **inside** that
same `if` arm. Correcting the precedence made the arm skip for unmanaged stores and took the
ownership check with it. `609631c1` hoists the refusal out of the arm, restoring the status quo
(same condition, same message), pinned by a test that fails without it. Reported here because it is
exactly the "a widened surface and an existing control written against the narrower one" shape
`.github/SECURITY-DIFF-REVIEW.md` exists for.

**Everything else is additive or a correction of a provable error.** Two `$request->all()`
fallbacks removed (NMI's state, and NMI's basket, which came from a **hidden form field** the server
printed into the payment page and read back). `(int)` truncation removed from a card capture.
Twelve `$settings['stock_management'] ?? '' == 'on'` expressions corrected to
`(($settings['stock_management'] ?? null) === 'on')`. No test, guard, check or threshold removed or
lowered; `.github/known-test-outcomes.json` **unmodified**; `AUDIT_CEILING` untouched;
`composer.json`, `composer.lock` and `database/migrations/` untouched.

**One deliberate behaviour change, owner-approved before it was made:** stock management OFF now
means stock is not deducted. Blast radius stated plainly — `Utility.php:111` ships `'off'` as the
platform default and `SettingController:1094` writes `'off'` on an unticked checkbox, so the key is
present-and-`'off'` for essentially every store that ever saved its settings, which is the exact
case the precedence bug mistook for "on". The order is still **written**; only the deduction stops.

### 2. Did any file already exist and do something else?

`GuardsGatewayReturn` and `PaymentVerificationService` were extended, not overwritten; every
pre-existing method keeps its behaviour and its tests. `AppliesCartStockChanges` is a **verbatim
move** of two private methods out of `PaymentController` so a fourth order writer could use them
rather than carry a second copy — no body changed, and the two writers that used them pass
unchanged. `app/Events/CinetPayPayment.php` is new and replaces nothing: it is the class
`CinetPayController:1008` had **always** dispatched and which existed nowhere, so every legitimate
CinetPay return raised an uncaught `Error` *after* committing the order, the stock decrements and
the cart deletes.

### 3. What control assumed the surface you widened was narrow?

The reverse happened twice, and both are the substance of these commits:

- **`addtocart`'s store-scoped product lookup** (added in S19) was assumed to keep foreign products
  out of the cart. It did not: the null it returns was never refused, and the `attribute_id` branch
  resolved the **variant** from the caller's own two values. `ProductVariant` carries no
  `StoreScope` and is not in `scoped_models`, so **scoping the product did not scope the variant.**
- **`assertCartAvailable()`** used the correct stock-management test while the loop it guards used
  the buggy one, so the two halves of one decision disagreed; a store with the setting off was
  pre-flighted as unmanaged and then decremented anyway.

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**None removed.** Three added: a logged refusal for every gateway return with no stored state, a
logged refusal for a basket naming another store's product, and — correcting a line that said the
opposite of what happened — `PaymentVerificationService` no longer logs *"No server-side
verification seam exists for this payment type"* over a write that **did** record a gateway
verification. NMI is the first of the nine order writers whose `Paid` is backed by a recorded
verification rather than asserted; the resulting status is unchanged.

### 5. Does any failure become silent?

**No. Four failures stop being silent, and one becomes loud that was previously a crash.** The four
pre-existing crashes are closed: the `out_of_stock_threshold`/`notification` undefined-key read that
500'd every legitimate order in a store lacking those rows (swept across seven controllers), the
missing CinetPay event class, and two post-charge crashes on the NMI path (a bare `tax_id` read and
an unassigned `$plan`). Each previously produced a 500 **after** money and stock had moved. A
mismatched product/variant pair at `add-cart` likewise answered 500 and now answers the handler's
own refusal.

### 6. Is method-resolution order involved?

Two Concern traits, `GuardsGatewayReturn` (extended) and `AppliesCartStockChanges` (new), used by
`PaymentController` and `NMIPayController`. Verified member by member: no name collides with the
using classes, with each other, or with `ApiResponser`; `PaymentController` no longer declares the
moved property. Private trait members flatten into the using class, so visibility is unchanged. No
class hierarchy, prepend or vendored concern is touched.

### 7. Verified by removing it?

**Yes — 26 controls across the three packages, each reverted individually and re-measured.** 18 in
P01-COMMERCE-SEC-02 (2 recorded as **subsumed** and kept as labelled defence-in-depth rather than
claimed), 7 of 9 files in S34 (the other two documented as unreachable and as dead code), and 8 in
P01-COMMERCE-SEC-03 — all 8 of which bite.

**Seven controls failed nothing on their first measurement**, and each is written up rather than
quietly fixed, because that is this project's own rule turned on its author. The three worth a
reviewer's attention:

- **A positive control was passing through an HTTP 500.** The NMI legitimate-charge test asserted
  seven database facts, all true, while the route answered **500** — the order committed and the
  journey then died on a bare `$response['tax_id']` read. Every refusal assertion in that file
  leaned on it. Both positive controls now assert the **response** as well as the row.
- **A refusal test passed because the route crashed.** The product/variant mismatch test asserted
  only that no cart row was written; without its guard the handler 500s and writes nothing either
  way. It now asserts the response is **not** 500 first: a refusal and a crash are not the same
  outcome.
- **A test could not reach the line it documented.** The cart-increment test posted `variant_id => 0`
  against a gate of `!empty($x) || $x != 0`, where both limbs are false for `"0"` — so the branch
  never ran. It now drives a real variant row.

**Three independent adversarial reviews ran across these commits** (two on P01-COMMERCE-SEC-02, one
`tenant-authz-reviewer` on S34), and they found five defects in the work itself — including the two
above, a store predicate that covered one of a loop's two branches, a `Session::forget()` placed
where a declined card destroyed the shopper's checkout, and a reward discount subtracted twice. All
fixed. One reviewer claim was traced and **refuted** rather than accepted, and one of my own claims
was **withdrawn** after a reviewer predicted the measurement that disproved it.

### 8. Merge-base regression gate?

Runs on this PR and is green on the current head. **GitHub's result is authoritative.**

### 9. Measured locally at `611357e6`

| Suite / gate | Result |
|---|---|
| Unit | **278 passed**, 1,527 assertions |
| Contract | **26 passed**, 83 assertions |
| Feature | **2,799 testcases — 2,780 passed**, 18,167 assertions, 2 declared failures, 17 declared skips, **0 errors** |
| Known-outcome gate | **EXIT 0** — *"the observed outcome set matches the manifest exactly"* |
| Unit/Tenancy | **10 passed** |
| Feature/Tenancy | **400 passed**, 6 skipped |
| Feature/Security | **1,114 passed**, 4 skipped |
| WorkDo vendor guard · GATE F · secret scan · test isolation · env guard | **all exit 0** |

CI on the previous head `609631c1` reported **2,784 testcases, 2 failures, 17 skips** with the gate
exact and both MyFatoorah packages installed from the unmodified lock — matching the local figures
commit for commit.

**Caveat on the local figures, stated because it bears on what they prove:** this container cannot
reach `dev.azure.com`, so the two `myfatoorah/*` packages — source-only — cannot be installed here,
and the suites above ran against a tree missing them via the repository's own
`make-degraded-manifest.php`, uncommitted. **CI installs the full locked set and is the authority.**

**`main` was not modified. No deployment, no migration run, no `scoped_models` change, no vendored
file touched, no gateway payment flow rewritten, no server or live data touched, no production
payment call made.**

---

## 11. SECURITY-DIFF-REVIEW: completed — for the follow-up commits `611357e6..68091f3c`

**Scope: `c4a5f3f6`, `db508dc4`, `db385f6d` and `68091f3c`.** This section was written at
`db508dc4` and **extended twice** as the last two commits landed, by the same session, with the
measurements re-taken each time. It is not a re-signature of anyone else's work. §10 is not re-signed and still means exactly what it says for
`79a8ca88..611357e6`; its figures are that head's. These two commits are what an independent
`tenant-authz-reviewer` pass over `611357e6` — briefed to assume nothing it had just been told —
led to, plus one site a completeness sweep of the order writers found that neither that commit nor
the review had named. Full record: **S36** in `docs/security/P01-SECURITY-WORK-QUEUE.md`.

**Files touched, all of them:** `app/Http/Controllers/Api/ApiController.php`,
`app/Http/Controllers/Api/DashboardController.php`,
`app/Http/Controllers/AuthorizeNetPaymnetController.php`,
`app/Http/Controllers/PaymentController.php`, `app/Services/PaymentService.php`, two documents and
two test files. **NONE is flagged by the sensitive `PATTERN`** — no middleware, no policy, no route
file, no `config/`, no `bootstrap/`, no migration, nothing under `.github/`. The checklist is
answered anyway, because the diff moves money and inventory.

### What the review found, and what was real

| | finding | outcome |
|---|---|---|
| S36-1 | `addtocart` validated `attribute_id` against the product and then stored **raw `$request->variant_id`** — "the pair that is checked is not the pair that is written". Two siblings: the non-`attribute_id` arm resolved `ProductVariant::find()` **unpaired** (a foreign variant came back non-empty, skipped the paired re-resolution, and priced the cart line from **another tenant's `variation_price`**), and the plain-product arm validated no variant at all | **real** — fixed at source, plus one gate on the pair about to be persisted |
| S36-2 | the web `cod` / `bank_transfer` order writer `PaymentService::getProductStatus` had **no ownership check of any kind** — reached from `processOrder`, a route carrying **no auth middleware** | **real** — the one live host-app order writer the six-site wiring missed. Guarded at the caller and inside the writer |
| S36-3 | the Authorize.Net capture had the quantity pre-flight and **not** the ownership one, and it builds the `authCaptureTransaction` itself | **real** — guarded before the charge, from the stored state |
| S36-4 | an **orphan decrement**: the refusal sat inside the stock loop with no transaction, so a poisoned second basket line left the first line's stock deducted with no order | **real** — one pre-flight hoisted above both API loops |
| S36-5 | `ordersave`'s dead guard (`explode()` before the emptiness test) | **real** — fixed, and recorded as *subsumed* defence in depth |
| — | `confirm_order` mutates stock cross-tenant | **refuted**: the block labelled "Order stock decrease" contains no write. What it carries is a cross-tenant **existence probe**, recorded |
| S36-6 | *(not from the review)* `Api\DashboardController::createOrderViaApi` — **`POST api/admin/createOrderViaApi`, the prefix the shipped admin app calls** — proved the caller owns the **store** and that the product **exists**, and never tied the two together | **real** — a merchant could decrement another merchant's stock while writing the order under their own store. Store predicate on the product, `product_id` predicate on the variant |
| S36-7 | *(executed risk review)* the **web** cart writer `CartController::product_cartlist` had the same unpaired variant on its **authenticated** arm (its guest arm has been paired since P01-COMMERCE-SEC-01) — and, with the checkout writers now refusing such baskets, a shopper could poison their own cart and be unable to check out at all | **real** — paired, plus the same gate before the write. A tenancy fix that leaves the shopper in a dead end is half a fix |
| S36-8 | *(completeness critic)* the quantity **CEILING** was missing from all three cash-on-delivery writers. **Measured:** qty `2147483647` on a 50-unit product → stock **−2,147,483,597** and an order for **21,474,836,470** marked **`Paid`**, on `process-order` (HTTP 302, no error), `place-order` and `order-save` alike | **real, and it corrects a refutation of mine.** Asked about a sweep's "no quantity pre-flight" claim I pointed at their `qty <= 0` floor and called it refuted — half right. `MAX_SANE_LINE_QUANTITY` existed and was wired into none of them. Shared helper now at all three |
| S36-9 | *(completeness critic)* the `explode()`-before-`empty()` shape in **8 reachable** methods, which `basketHasForeignProduct()` does **not** cover (it `continue`s on a variant that resolves to no row). One copy is **post-capture** — `AuthorizeNetPaymnetController:574` — where the crash left the card **charged with no order row** | the post-capture copy **fixed**, and stated as **not test-provable here** (reaching that loop needs a real capture; the SDK is unreachable). The other seven **recorded** with line numbers |

### 1. What does this REMOVE or NARROW?

**One thing is removed: a fix of mine, withdrawn after measurement.** I read
`PaymentService::getProductStatus` taking `slug` and `customer_id` from request input and pinned
both in `processOrder`. The removal check showed the pin was **redundant** —
`PaymentController::processBillingInfo()` already merges
`['customer_id' => $customerId, 'slug' => $store->slug, 'store_id' => $store->id]` from the
server-resolved values, **unconditionally**, before `processPayment()` is reached. The pin was
removed again and the claim is withdrawn. The test stayed, because nothing had pinned that
existing control, and it fails when that merge is deleted.

Everything else is additive or a correction of a provable error. Three lookups are **narrowed**:
`ProductVariant::find($request->variant_id)` gains `->where('product_id', …)` in `addtocart` **and
in `CartController::product_cartlist`'s authenticated arm**, and `Product::find()` /
`ProductVariant::find()` gain a store and a `product_id` predicate in the admin API writer. Both narrow exactly the predicate whose absence is the defect. Four `\DB::rollBack()`
calls are **added** before early returns that previously left a transaction open — pre-existing,
and previously unreachable on the variant path because `find()` always resolved a row.

No test, guard, check or threshold removed or lowered. `.github/known-test-outcomes.json`
**unmodified**; `AUDIT_CEILING` untouched; `composer.json`, `composer.lock`,
`database/migrations/`, `routes/`, `config/` and `config/tenancy.php`'s `scoped_models` all
untouched.

### 2. Did any file already exist and do something else?

`GuardsGatewayReturn` is used, not changed. Two classes now `use` it — `ApiController` and
`PaymentService` — for `basketHasForeignProduct()` only, rather than carrying a copy of the
predicate. Verified member by member against both classes: no name collides (each class's own
payment verifier is `paymentVerifier()`, the trait's is `gatewayReturnVerifier()`), and the
gateway-return helpers the trait also carries are simply unused on those paths.

### 3. What control assumed the surface you widened was narrow?

The reverse, twice, and both are the substance of these commits: `addtocart`'s **pairing check**
was assumed to govern the stored row and governed only the checked values; and the six-site
pre-flight wiring of `611357e6` was assumed to cover the checkout writers, while the **web
cash-on-delivery writer** and the **Authorize.Net capture** were not among the six.

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**None removed. Two added:** a logged refusal on the web checkout (`checkout: refused, basket names
a product from another store`) and one in the COD/bank-transfer writer, each carrying store,
customer and path. The shared `gatewayReturnForeignProductRefusal()` is deliberately **not** reused
at those two sites: its message tells the shopper their payment "has not been applied to an order",
which is true on a gateway return and false at checkout initiation or on cash-on-delivery.

### 5. Does any failure become silent?

**No. One becomes loud.** A mismatched product/variant pair at `ordersave` answered HTTP 500 and
now answers the handler's own refusal. One crash is **measured and deliberately not fixed**: a cart
row naming a variant id that does not exist dies earlier, in the cart-list builder, at
`ApiController.php:654` (*"Attempt to read property `discount_price` on null"*). It is a 500 on
one's own basket, not a cross-tenant write, and the new gate stops such a row being created through
the host app. Recorded in S36 rather than claimed.

### 6. Is method-resolution order involved?

Yes, and it is the answer to §2 above: one existing trait, two new `use` sites, no class hierarchy,
prepend or vendored concern touched.

### 7. Verified by removing it?

**Yes — 19 removal checks on top of §10's 26.** Measured, and reported as measured:

- **Bites alone:** `addtocart`'s validated-variant assignment; the gate before the cart write (in
  both cart writers); the Authorize.Net pre-flight; its `['product_list']` dereference; the
  `place_order` hoist; both admin API predicates; and **each of the three quantity ceilings**, every
  one failing its own data-provider case, with positive controls proving all three writers still
  complete an ordinary order (stock 50 → 48).
- **Mutually subsuming, with the measurement that proves the pair:** the two web-checkout
  pre-flights (each alone still refuses; **removing both** moves another merchant's stock);
  `ordersave`'s hoist and its dead-guard correction (each alone suffices; **both** removed gives a
  500); `addtocart`'s paired lookup and the gate (each alone refuses the foreign-variant payload).

**Four things proved nothing until they were measured**, and each is written up rather than quietly
fixed, because that is this project's own rule turned on its author for the fourth package running:

1. **A guard that read the wrong level.** The Authorize.Net pre-flight first passed
   `storedCheckoutCartList($state)` — the cart list — whose *lines* live under `product_list`.
   Iterating the cart list walks its top-level keys, none of which carries a `product_id`, so it
   would have refused **nothing** while every test passed. Caught by reading the one sibling call
   site, not by the suite.
2. **A fixture whose insertion order decided the result.** The orphan-decrement test passed with
   the fix reverted: the cart list is served **newest row first**, so the poisoned line was
   processed first and nothing had been decremented yet.
3. **The redundant pin** of §1 above.
4. **A fixture that made a refusal test pass with BOTH its controls removed.**
   `CartController::product_cartlist` refuses any variant whose `variation_option` lacks `enabled`
   (*"Product variant is not available."*) **before** reaching either control, and the shared
   fixture omitted that flag — so the refusal under test was not the refusal being measured. With a
   corrected fixture: the paired lookup alone is **subsumed** by the gate, and **removing both
   writes the foreign row**. The same both-removed check was then run against `addtocart`'s pair and
   bites there too, which retires the one open question the first harness run had left.

An adversarial read of all seven `attribute_id` branches also established that **only one is both
un-fixed and reachable** (`ApiController:1034-1050`, WholesaleProduct active / SizeGuideline
absent), and that two others are **dead code** — `:1026` repeats the identical `module_is_active`
check `:1024` already made, so its `} else {` can never run. Verified here before being recorded.
That one reachable arm is **not changed**, and the reason is in S36: beyond being untestable (no
WorkDo module loads in any test run), assigning the paired variant there would move a wholesale
store's cart row from `variant_id = 0` to a real variant, i.e. move the decrement from
**product-level to variant-level stock**. A behaviour change on a path this repository cannot
exercise is an owner decision, not a drive-by. The gate before the cart write already stops that
arm persisting a foreign or mismatched variant, so no security property depends on it.

### 8. Merge-base regression gate?

Runs on this PR. **GitHub's result is authoritative** — reported in the session that pushed these
commits.

### 9. Measured locally at `db508dc4`

| Suite / gate | Result |
|---|---|
| Unit | **278 passed**, 1,527 assertions |
| Contract | **26 passed**, 83 assertions |
| Feature | **2,818 testcases — 2,799 passed**, 18,217 assertions, 2 declared failures, 17 declared skips, **0 errors** |
| Known-outcome gate | **EXIT 0**, 17 of 17 skip reasons parsed — *"the observed outcome set matches the manifest exactly"* |
| New / extended suites | `ForeignProductCartChainTest` **31 passed** (16 new), `AdminApiOrderStockOwnershipTest` **3 passed** (new) |
| GATE F · test isolation · env guard · secret scan | **all exit 0** |
| WorkDo vendor guard | exit 0, but it **SKIPS locally** for want of a base ref — CI's copy is the authority |

**One failed gate run, diagnosed rather than explained away.** An earlier background run of the same
suite reported `GATE EXIT: 1` — all counts correct (2 declared failures, 17 declared skips, 0
errors) but only **13 of 17** skip reasons recoverable from the TeamCity log. The cause was mine: I
was editing app files while that run executed. Re-run on a quiet tree, 17 of 17 parsed and the gate
exits 0. Recorded because a disturbed measurement that happens to look like a failure is worth as
much scrutiny as one that happens to look like a pass.

Same caveat as §10, for the same reason: this container cannot reach `dev.azure.com`, so the two
`myfatoorah/*` packages are absent locally and the suites ran against a tree missing them.
**CI installs the full locked set and is the authority.**

**`main` was not modified. No deployment, no migration run, no `scoped_models` change, no vendored
file touched, no gateway payment flow rewritten, no server or live data touched, no production
payment call made.**

---

## 12. SECURITY-DIFF-REVIEW: completed — for the **54 commits** `68091f3c..5f5f96b6`

**Read the scope line before the findings, because the gap was larger than the branch-synchronization
note recorded.** The owner's note described this as fast-forwarding a 25-commit source onto the
Phase 01 branch. That is true of the *branches*. It is not the attestation gap. §11's last commit is
`68091f3c`, and **the PR head had already advanced 25 commits past it**, to `a3f09b73`, before this
synchronization began. So the unattested range is `68091f3c..5f5f96b6` — **54 commits** — and this
section covers all of them. Sections 8, 9, 10 and 11 are **left exactly as their authors wrote them
and are not re-signed**: §8 and §9 are `79a8ca88`'s, §10 is `611357e6`'s, §11 is `68091f3c`'s.

**Measured scope of this section's range:** 54 commits, **101 files, +31,516 / −110**.
**Zero files under `packages/`** and zero under `themes/`.

**Measured scope of the whole PR at this head:** 131 commits, **209 files, +70,786 / −1,064**,
against `main` at `1eebc721`. **The header counts above this section ("128 files, +39,329 / −1,013,
77 commits ... Current head `68091f3c`") were true of `68091f3c` and are now superseded** — they
are left in place because the paragraph they sit in is the record of an earlier withdrawn claim, and
these are the current figures. By directory at this head: `tests/` 80, `app/` 59, `docs/` 37,
`.claude/` 14, `resources/` 6, `.github/` 3, `database/` 2, `config/` 2, `routes/` 1, `public/` 1,
`bootstrap/` 1, plus `CLAUDE.md`, `.gitignore` and `.env.example`. **`packages/` 0 and `themes/` 0 —
the vendor guard holds across the whole PR.**

### The branch synchronization itself, recorded because it is the one history-affecting act here

| condition | result |
|---|---|
| remote refs re-fetched immediately before each push | yes — three times in all |
| destination still an ancestor of the source, re-checked each time | **YES** — `git merge-base --is-ancestor` exit 0 for `a3f09b73` → `7bc60b9b`, then `7bc60b9b` → `5f5f96b6` |
| behind / ahead | **0 behind, 27 ahead**, then **0 behind, 1 ahead** |
| mode | **plain `git push`, no force, no `--force-with-lease`** — a non-fast-forward would have been rejected by the remote, which is the stop condition rather than something to override |
| result | `a3f09b73..7bc60b9b`, then `7bc60b9b..5f5f96b6`; both fast-forwards |
| both branches and PR #3 | `claude/store-syria-p01a-ci-foundation`, `claude/wonderful-gates-60khob` and the PR head are all **`5f5f96b6`** |

The second fast-forward carries §7b's fix, which was found while taking §9's figures — so the
synchronization happened in two steps rather than one, and the figures in §9 are `5f5f96b6`'s.

No history was rewritten. Nothing was discarded. `main` was not touched.

### What this range contains

Eighteen security tasks plus one test-infrastructure finding (**P1-TST-01**, §7b below), each with
its own ledger entry carrying line numbers, measured movements and removal checks, in
`docs/development/phase-1/12-PHASE-01-TASK-LEDGER.md`:
**P1-INV-01 / P1-PLT-01** (a merchant could rewrite and restock any store's product),
**P1-INV-07** (two destructive routes and a read leak on that same controller),
**P1-INV-08** (category destroy unlinking another store's catalogue media from disk),
**P1-OWN-01** (BackupRestore: `disabled` protects only when there is no `add_on_managers` row),
**P1-SEC-02** (the grantable-permission ceiling was a denylist, so a merchant could mint money powers),
**P1-INV-04** (the WooCommerce import re-owned another store's product),
**P1-INV-02** (`order_return` had neither a permission nor an ownership check),
**P1-ORD-04** (`order_status_change` had no permission check at all, and its cancel limb moves money),
**P1-INV-14** (six anonymous admin mutations in two vendored modules),
**P1-INV-17** (four cross-tenant writes behind `AuctionProduct`'s resource routes, plus two live cross-tenant reads),
**P1-INV-18** (cross-tenant lottery draws),
**P1-INV-19** (a forged auction bid attributed to another customer on another store's product),
**P1-INV-20** (two of four LotteryPrizes defects),
**P1-INV-21** (the lottery ticket price was a client-posted hidden input),
**P1-INV-22** (a merchant could point their lottery setting at another store's product),
**P1-SEC-08** (the two reserved-role-name gates disagreed about case),
**P1-SEC-01** (session hygiene — the subject of the last four commits), and the
**#66 tooling evaluation** (twelve candidates read from their real upstreams, **one adopted**, nothing
else installed).

### 1. What does this REMOVE or NARROW?

**Nothing is removed. 110 deletions across 101 files, and every one is accounted for.** The six files
with more than five deletions, each checked individually:

- `app/Http/Controllers/CartController.php` **−22 / +33** — the `add to cart` **ActivityLog write is
  not removed**; it is the same lines re-emitted around a hoisted guard. Verified still present at
  `CartController.php:474`.
- `app/Policies/RolePolicy.php` **−11 / +270** and `RoleController.php` **−5 / +46** — the
  grantable-permission **denylist of 13 names is kept** as defence in depth and consulted *before* a
  new positive-authorization limb. Narrower, never wider.
- `app/Models/Order.php` **−6 / +136** — the one removed `$product->save()` is the **same write with a
  store predicate added** to the lookup feeding it. The restock still happens; it can no longer
  happen to another tenant's row.
- `config/session.php` **−2 / +21** — a **comment only**. The stock Laravel line claiming a `lax`
  default was false of this application; `'same_site' => env('SESSION_SAME_SITE', null)` is
  **unchanged** (see §12.9 and D-01).
- `CLAUDE.md` **−11 / +63** and two Tenancy test files (**−11** and **−7**) — documentation and test
  prose. The `ResidualTenancyExposureTest` deletions are the `assertCount(23, …)` section being
  rewritten to a claim that holds in both test orders, by its own author, and its count of 23 is
  **unchanged** (it is the same 23 that §7b turns out to depend on).
- `bootstrap/app.php` **−1 / +247** — the single deletion is `'/login'` leaving the CSRF `except:`
  list. That **adds** a control.

The **four P1-SEC-01 commits' only change to executable application code is that one deleted line
plus two session rotations.** The last of the four changes **no executable line at all** — proved
mechanically rather than asserted: `CustomerLoginController.php`'s token stream is identical before
and after, under `token_get_all()` with `T_COMMENT` and `T_DOC_COMMENT` dropped.

`.github/known-test-outcomes.json` is **unmodified** across this range — verified, and
`.github/` is untouched entirely. `AUDIT_CEILING` stays **58**. `composer.json`, `composer.lock`,
`config/tenancy.php`'s `scoped_models` and every runnable migration: untouched. No test, guard,
check or threshold was removed or lowered.

**Three narrowings are deliberate and are the substance of the range:** request-supplied
`product_id` / `variant_id` / `store_id` lookups gain store predicates; `Rule::notIn(RESERVED_NAMES)`
becomes a case-insensitive closure (`RolePolicy::reservedNameRule()`), so `Admin` is now refused
where `admin` already was; and eight new route-middleware guards refuse cross-tenant module writes.

### 2. Did any file already exist and do something else?

`RolePolicy` gained `isReservedName()` and `reservedNameRule()`; every pre-existing method keeps its
behaviour and its tests. **Nine files are added under `app/Http/Middleware/`: eight guards and one
trait.** The eight guards are `AuctionBidIdentityGuard`, `LotteryDrawOwnershipGuard`,
`LotteryMailTemplatePrerequisite`, `LotteryRefundOwnershipGuard`,
`LotterySettingProductOwnershipGuard`, `LotteryTicketWriteGuard`, `ModuleOrderMutationGuard` and
`ModuleResourceOwnershipGuard`; all eight are registered through `appendToGroup('web', ...)` in
`bootstrap/app.php`. None replaces an existing middleware and none is inserted into a `$priority`
list. `CustomerLoginController::logout()` and `verifyPasswordOtp()` keep
their request/response contract exactly — same redirects, same flash keys, same status codes.

**One departure worth naming because it differs from its own siblings:**
`LotterySettingProductOwnershipGuard` has **no platform-operator pass-through**, where the three
sibling lottery guards do. That is deliberate and the comment at its registration site says so —
three earlier drafts of that comment said the opposite, which is finding 8 of the P1-SEC-01 review
and is fixed.

### 3. What control assumed the surface you widened was narrow?

The reverse, repeatedly, and it is this range's recurring sentence: **a permission check standing in
for an ownership check, on models no global scope filters.** `config/tenancy.php`'s `scoped_models`
is an allowlist and `Product`, `ProductVariant`, `Order` and `ShippingMethod` are **not in it**, so
`Product::find($id)` reaches every tenant's catalogue for every principal. Eleven of the eighteen
tasks above are that one shape. `scoped_models` is **not** changed here — that remains the owner
decision §7 records.

One new widening is disclosed rather than discovered: nine guards now refuse requests that
previously succeeded. Each refuses only a cross-tenant target, each has a positive control proving
the legitimate merchant path still works, and each has a removal check proving the refusal is the
guard's and not an accident of the fixture.

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**None.** Mechanically checked across the whole range's `app/` diff: the only removed
`ActivityLog`/`Log::`/`->save()` lines are the `add to cart` block re-emitted in
`CartController` and the one restocking `$product->save()` whose lookup gained a predicate — both
verified still present. **Added:** a logged refusal in each of the eight new guards, carrying the
caller, the store and the refused identifier.

### 5. Does any failure become silent?

**No.** Two become loud, both in P1-INV-22's guard and both found by the adversarial review of my own
work: the guard's loop **skipped** a `lottery_products` value it could not read, so a blank select —
`null`, via Laravel's global `ConvertEmptyStringsToNull` — wrote a NULL-product lottery
**unexamined**; it now refuses. And every refusal said *"Permission denied."* though the product
select is the only control in that form without `required`, so the one user-reachable refusal gave
the merchant no idea what to fix; it now names the field.

**One pre-existing 500 is recorded and not fixed:** two `delivery_boys` columns
(`commission_percentage`, `is_commission_enabled`) are `$fillable` and written by two handlers while
**no migration declares them**, so those handlers 500 for everyone. GATE F, not this range.

### 6. Is method-resolution order involved?

Yes, and narrowly: one new trait, `app/Http/Middleware/Concerns/ComparesRowOwnership.php`, with
seven `protected` members — `isPlatformOperator()`, `permissionIsEnforceable()`, `readRowStore()`,
`storeMatches()`, `noMatchedRowOutsideCallerStore()`, `noMatchedRowOutsideStore()` and
`usableId()`. **It is used by six of the eight new guards** (not by
`LotteryMailTemplatePrerequisite` or `LotteryTicketWriteGuard`) **and by one listener**,
`app/Listeners/EnforceLotteryTicketPrice.php`, which is a different resolution context and is named
here for that reason. Verified member by member: **every one of the nine using classes is a plain
`class` extending nothing**, so no inherited member can be shadowed and no parent can shadow a trait
member. No class hierarchy, no `prepend`, no vendored concern, and **no vendored file of any kind**
is touched.

### 7. Verified by removing it?

**Yes — every control in this range, individually, with the measurement in its own commit and its
own ledger entry.** This project's rule is stricter than the vendored TDD skill's: after the fix,
revert it and confirm the test fails *again*. It keeps paying for itself, and the honest report is
that **this range's reviews found more defects in my own fixes than in the original code for three
tasks running**:

- **P1-INV-22 (#70 item 5).** My census used `Setting::paymentList` — *translated labels* — where the
  form uses `Api\ApiController::payment_list`'s bare literals. The Arabic-language "0 reachable"
  headline I published was **false**; corrected to 9 reachable of 26 by re-harvesting from the
  **rendered page**. The middleware predicate I had proposed on that census would have refused
  **every payment method on a non-English storefront** and broken `Paytabs` outright. Not shipped.
- **P1-SEC-08 / #69.** My conclusion *"the premise does not hold"* over-generalised from the six
  **host** template names to the **module** names, which **are** per-tenant. Corrected twice; the
  fourth revision is what stands, and the upstream WorkDo report is drafted and **not sent**.
- **P1-SEC-01.** Reported in full in the ledger's round-2 entry. The code survived every attack; the
  **tests did not**. Its top finding was that *neither changed line was executed by any test* — the
  only HTTP test posted to an unknown store and took the early-return branch, so it would have passed
  with the whole fix deleted. Four direct-invocation tests were added and **six removal checks bite**.

**Three claims from reviewers were traced and refuted rather than accepted**, each with the citation:
that PhonePe 500s today (`JsonResponse::getTargetUrl()` raises `BadMethodCallException`, which *is*
an `Exception` and *is* caught); that `Utility.php:3117`'s `LIKE` resolve is the real email-template
defect (three agents said so — on real MySQL the UNIQUE index means at most one row can match, and
the ambiguity is a SQLite-only artifact); and a proposed `input()`/`all()` fix that measurement
showed changed nothing. **And one of my own test harnesses over-stated the attacker in the safe
direction** and is documented as such: SQLite's BINARY unique index accepts a `roles.name` row that
MySQL's `utf8mb4_0900_ai_ci` refuses, so the reserved-name escalation reads worse under SQLite than
it is in production. Verified against a real MySQL by
`tests/Support/Security/bin/verify-reserved-role-name-collation-on-mysql.php`, which creates and
drops its own throwaway database.

### 7b. A DEFECT THIS ATTESTATION'S OWN MEASUREMENTS FOUND, in already-pushed work

Written up as its own subsection rather than folded into §7, because it was found **while taking
the figures for §9** and it invalidates a line two earlier ledger entries carry.

**`php artisan test tests/Feature/Tenancy tests/Feature/Security` — the `/tenant-check` selection —
reported 5 FAILURES that neither the individual files nor `--testsuite=Feature` reported.** My first
reaction was that the two runs had overlapped on a shared `storage/`. **Wrong: it reproduced
identically on a quiet tree.** So it is a real, deterministic order dependency, and the ledger's
"Security + Tenancy … 0 failures" line is no longer true for that selection.

All five were **POSITIVE controls** — "a merchant can *verb* their **own** row" — on the guards
P1-INV-17 shipped. All five failed on **HTTP 500, not 403**, so no guard was ever involved:

```
Error: Class "Workdo\AuctionProduct\app\Models\AuctionProductSetting" not found
  at packages/workdo/AuctionProduct/src/app/Http/Controllers/
     AuctionProductSettingController.php:142
```

**Root cause, measured rather than inferred.** Composer's `ClassLoader` keeps a **private
`$missingClasses` negative cache** (`vendor/composer/ClassLoader.php:93`), consults it first in
`findFile()` (`:448`) and writes a miss into it (`:471`). It belongs to the loader **singleton**, so
it outlives every Laravel application teardown. `Workdo\` is **absent from `composer.json`'s psr-4
block** — a module's namespace exists only once
`PackageServiceProvider::loadAddons()` has run `addPsr4()`. So any code that asks `class_exists()`
for a module model **before** that call records it as permanently absent, and **no later
`addPsr4()` can undo it**, because `findFile()` never reaches the prefix map for a class already in
the cache. `BulkDeleteAuthorizationTest` enables the BulkDelete module, whose model map
`class_exists()`-checks every addon model it knows of while only BulkDelete's own prefix is
registered.

Probed at the point of failure: **184 cached misses, 23 of them `Workdo\`** — exactly the 23 addon
model classes `ResidualTenancyExposureTest` already counts. **Clearing only those 23 and changing
nothing else took the same request from 500 to 302, exception: none.**

**This is a test-process artefact and that is part of the finding, not an excuse.** Production boots
once and registers every active module's prefix inside `PackageServiceProvider`, before application
code can ask about a module class — so production's negative cache caches truths. A test process
boots the application dozens of times against **one** ClassLoader, which is the only way the order
inverts. The failure direction here was the harmless one: an order-dependent **positive** control
reads as "the fix locks the owner out", a false alarm. **The same mechanism could just as easily
make a module route 500 under a NEGATIVE control, which would pass for the wrong reason** — and that
is the direction this project keeps being bitten in.

**Fixed in test support, not in application code and not in `packages/`:**
`tests/Support/ComposerClassLoaderGuard::forgetWorkdoMisses()`, called from `tests/TestCase::setUp()`
— one line, scoped to `Workdo\`, with the derivation in the class's own docblock.
`tests/Unit/ComposerClassLoaderGuardTest.php` adds **5 tests / 10 assertions**, two of them premise
tests (a ClassLoader is registered; `Workdo\` is still not a static prefix — if it ever becomes one
this guard can go).

**It weakens nothing.** Clearing a cached "no" lets `class_exists()` ask the filesystem again, which
still answers **false** for a prefix nobody registered — so the *"resolves although no provider for
its module was registered in this process"* ratchet in `ResidualTenancyExposureTest` keeps biting
exactly as before, and that file's own `assertCount(23, …)` is unchanged and still passes.

**Removal checks, and one over-claim of mine corrected by measurement:**

| check | result |
|---|---|
| **R-L1** delete the `setUp()` call | **4 of the 5 return** in the two-file pairing, and the wiring ratchet fails |
| **R-L2** move the call *above* `parent::setUp()` | the ordering assertion fails — **and the behaviour does not** |

R-L2 is the correction. My first version of that test **required** the call to follow
`parent::setUp()`, reasoning that the application boot is what primes the cache. **Measured both
ways: with the call moved above `parent::setUp()` the combined selection is equally green (49 passed
in the pairing).** What it clears is what the *previous* test left; this test's own boot re-primes
nothing, because `RefreshDatabase` has rolled the `add_on_managers` rows back. **The assertion was
deleted rather than re-justified** — an assertion whose stated reason does not hold is worse than no
assertion. The residual is stated instead, because no position fixes it: a test that primes the cache
for a module class **inside its own body** before calling `loadAddons()` must call
`forgetWorkdoMisses()` itself.

### 8. Merge-base regression gate?

Runs on this PR. On the intermediate head `7bc60b9b` the fast-forward triggered `CI`,
`Security attestation` (pull_request — the enforcing caller) and `Security attestation (push)`;
`Static checks`, `Secret scan`, `Security-sensitive diff: human checklist`, the PayHere report and
`Tests (REQUIRED -- real suites, PayHere excluded and NOT fixed)` all reported **success** there. **CI run #104 on `7bc60b9b` has since completed with conclusion `success` in full** — both `Tests (REQUIRED)` jobs, the merge-base regression gate, `Static checks`, `Secret scan` and the sensitive-diff checklist. Two further fast-forwards (to `e6871e6d`, then `5f5f96b6`) re-triggered the whole set, and those runs are **in progress as this is written**. **GitHub's result is authoritative, not the local figures below** — and note that the
local figures are the ones that found §7b, which CI's `--testsuite=Feature` run cannot see, because
the defect only appears in the `/tenant-check` selection.

### 9. Measured locally at `7bc60b9b`

| Suite / gate | Result |
|---|---|
| Unit | **291 passed**, 1,671 assertions |
| Contract | **26 passed**, 83 assertions |
| Feature | **3,069 testcases — 3,050 passed**, 19,877 assertions, 2 declared failures, 17 declared skips, **0 errors** |
| Known-outcome gate | **EXIT 0** — *"the observed outcome set matches the manifest exactly"*, 17 of 17 skip reasons parsed |
| `SessionHygieneTest` | **17 passed**, 81 assertions (was 12 / 53 — the five new tests are finding 1) |
| `/tenant-check` selection (`Feature/Tenancy` + `Feature/Security`) | **1,784 passed**, 10 skipped, **0 failures** — **was 1,779 passed and 5 FAILED**; see §7b |
| `ComposerClassLoaderGuardTest` | **5 passed**, 10 assertions (new — §7b) |
| `guard-no-workdo-changes.sh` vs `origin/main` | **exit 0** — `packages/workdo/` unmodified |
| GATE F containment · no-env-files · test isolation | **all exit 0** |
| Secret scan over this range (101 files) | **exit 0** — no credential-shaped literals added |
| `flag-sensitive-diff.sh` over this range | flags **17 paths**, checklist required — which is this section |

**`guard-composer-lock-integrity.sh` exits 1 locally, and it is the same caveat §10 and §11 both
record, not a new one.** Two source-only packages — `myfatoorah/laravel-package` 2.2.4 and
`myfatoorah/library` 2.2.8 — cannot be installed in this container because it cannot reach
`dev.azure.com`. 235 of 237 locked packages are installed, **all 235 at the locked version, 0 at a
different version, 0 crossing a major**. The suites above therefore ran against a tree missing those
two. **CI installs the full locked set and is the authority.**

**The Feature suite is not green and is not meant to be** — 2 accepted failures and 17 accepted skips
declared by name in `.github/known-test-outcomes.json`, which this range does not modify. The gate
asserts the outcome *set* in both directions, so an undeclared failure **and** an
unexpectedly-passing declared failure both fail it. It exits 0.

### 10. Owner decisions this range deliberately did **not** take

Recorded so that an unmade decision is never read as unfinished work. Full text in
`docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md`.

- **`SESSION_SAME_SITE` (D-01).** `lax` is correct for this cookie **and breaks money**. Nine
  CSRF-exempt routes are POSTed to by third-party gateways and then read their state out of the
  session; driven with no session cookie, **seven answered `302 → /login`** with
  `AuthenticationException`, **six of those take money before activating a plan**, and
  `{storeSlug?}/get-payment-easebuzz` answered the checkout refusal because P01-COMMERCE-SEC-01
  deliberately made the session the sole source of checkout state. Per the owner's decision of
  2026-10-20 the value is **unchanged**, the false stock comment is removed, the value is pinned by a
  test, and **P1-SEC-01's item-C acceptance criterion is deliberately left unticked** rather than
  marked met.
- **LotteryPrizes #67 / #68 payment state.** A recorded `Paid` is not trusted without settlement
  evidence. The maintainable Pending / Paid / Failed / Cancelled / Refunded design is **prepared, not
  implemented** — no breaking workflow or schema change without approval.
- **#69 tenant-safe email-template resolution** across the six affected modules. The upstream WorkDo
  report is **drafted and not sent**; no vendor code is touched.
- **#70 payment-method compatibility.** The actual reachable set is documented
  (`docs/security/P01-LOTTERY-PAYMENT-METHODS-AVAILABLE.md`); **no previously unreachable payment
  flow is activated.**
- **`auction:order` stays unscheduled**, pending a separate end-to-end security and financial
  acceptance review.
- Still open from §7 and unchanged: `scoped_models` for `Product` / `ProductVariant` /
  `ShippingMethod`; the `NULL store_id` count query; gateway-callback authenticity; idempotency;
  GATE F; the S13 migration; `laravel/pint`; the browser half of the design-system checks; branch
  protection.

**`main` was not modified. No deployment, no migration run, no `scoped_models` change, no vendored
file touched, no gateway payment flow rewritten, no server or live data touched, no production
payment call made. PR #3 remains Draft.**

---

## 13. SECURITY-DIFF-REVIEW: completed — for the **17 commits** `5f5f96b6..4a55987d`

**Read the scope line first.** §12 ends at `5f5f96b6`, and the PR head had already advanced **three commits past it**
(`57f091a1` P1-ORD-07, `e67a5c9c` P1-ORD-08, `ade26e13` the S14 triage) before this range began — so those three were
**never examined by §12**. This section covers all 17. Sections 8–12 are left exactly as written and are not re-signed.

**Measured scope of this range:** 17 commits, **25 files, +5,342 / −98**: application code (`app/ routes/ config/ database/
bootstrap/`) 12 files +708 / −59, tests 10 files +2,940 / −5, docs 3 files +1,694 / −34. **Zero files under `packages/`,
`vendor/`, `themes/`, `.github/`, `composer.json` or `composer.lock`.** Whole PR at this head: **148 commits, 222 files,
+76,089 / −1,123** against `main` at `1eebc721`; `packages/` 0, `themes/` 0 — the vendor guard holds.

### The branch synchronization, recorded because it is the one history-affecting act here

| condition | result |
|---|---|
| remote refs re-fetched immediately before each push | yes, every time |
| destination still an ancestor of the source, re-checked each time | **YES** — `git merge-base --is-ancestor` exit 0 for `ade26e13→9f7a8eae`, `9f7a8eae→256bda2f`, `256bda2f→6d7abae6`, `6d7abae6→4a55987d` |
| mode | **plain `git push`, no force, no `--force-with-lease`** — a non-fast-forward would have been rejected, which is the stop condition |
| shape | the working branch was pushed after each commit; the PR branch was fast-forwarded **four** times, after the gates; both and the PR head are **`4a55987d`** |
| `main`, the Draft state | untouched; PR #3 remains Draft |

No history was rewritten. Nothing was discarded.

**CI caught what my local runs did not.** The *Secret scan* failed on `9f7a8eae`: three `generic-assigned-secret` hits on
**my own test sentinels** (`PaymentListSecretDisclosureTest.php:31,33`, `StorefrontVisitLogTest.php:37`). They are fixtures, and
the scan has an explicit rule for fixtures that say so; they now say `FIXTURE` (`256bda2f`) and **the guard is untouched**.
**My earlier local "scan-secrets exit 0" was wrong**: I had run the script without its base argument, which scans nothing — and I had
written that claim into four ledger entries. Corrected in `6d7abae6`. Run exactly as CI invokes it, a second hit then appeared (a
JWT-shaped test fixture, `jwt-literal` has no fixture exemption by design), fixed in `73aeb1e3` by building it at runtime.

### What this range contains

**P1-ORD-07** (#79) the storefront refund family; **P1-ORD-08** (#80) the anonymous thank-you and tracking pages; two **CRITICAL**
exposures found *during* that work and fixed: **P1-SEC-13** (#85) — the storefront visit log (`shetabit_visits`) persisted every
request's body and headers, i.e. customers' plaintext passwords, Cookie headers and, for two gateways, card number and CVV — and
**P1-SEC-15** (#87) — `POST /api/{slug}/payment-list` returned every gateway's secret keys to any token holder, and the public
`POST /api/{slug}/register` hands a token to anybody (an anonymous visitor read another store's Stripe secret key; reproduced by
execution). Plus the ledger, JSON index and queue (S40–S42), and **twelve new tasks** from the reviews: P1-ORD-12/13/14, P1-SEC-12/14/16/17/18,
and the owner-gated P1-OWN-08/09.

### Who examined which commits — stated so that no review is read as covering a commit it did not see

| commits | examined by | verdict |
|---|---|---|
| `57f091a1`, `e67a5c9c` — the first deliveries | five read-only lenses: authorization/refund, anonymous disclosure, regression, test quality, completeness (the last **executed** probes) | one **BLOCKING** (the refund record was indexed by tick position, not line) and one vacuous test; all acted on |
| `b658e251`, `7f5b1c88` | `tenant-authz-reviewer` | NON-BLOCKING; the cancel-restores-wrong-line finding became P1-ORD-14, the concurrency and throttle findings were fixed, one did not reproduce |
| `5c0d0893`, `f8e53422` | a third read-only review | NON-BLOCKING; acted on in `9223a0a1` |
| `dcb4b8a5` (P1-SEC-13) | three read-only lenses: bypass, sibling sinks, exposure | NON-BLOCKING; every finding dispositioned in queue S40 |
| `92671045` (P1-SEC-13 round 2) | the third review (visit-log lens) | NON-BLOCKING; acted on in `9223a0a1` |
| `c7c48a75` (P1-SEC-15) | two read-only lenses: the fix, and a sweep for the same pattern | NON-BLOCKING; the sweep produced P1-SEC-14/16/17/18 |
| `38c013d2` (P1-SEC-15 round 2) | the third review (payment-list lens) | NON-BLOCKING; acted on in `9223a0a1` |
| **`9223a0a1`'s own changes** | **not independently reviewed** — the changes made in response to the third review carry scripted removal checks (all bite), the full Feature gate, and one script executed on a real MySQL server, and nothing else |  |
| `256bda2f`, `73aeb1e3`, `6d7abae6`, `4a55987d` | not reviewed — test-fixture labels and records; `validate()` passes on the task graph |  |

All reviewers were **read-only** (they could not execute); each claim they made is READ. The executed evidence is mine and is named where used.

### 1. What does this REMOVE or NARROW?

**Nothing is removed that was not meant to be; 98 deletions across 25 files, each accounted for.**
- `AccountProfileController.php` **−35 / +268** — the refund handler body rewritten: the old record loop (indexed by tick position), the
  unconditional trust of the body `order_id`, and the unbounded quantities. **Narrower, deliberately** — a request now needs the path id
  to agree with the body, the order to be this shopper's in this store, each ticked line to carry its own valid quantity, and the order
  write and the refund row are one transaction that re-reads the row `FOR UPDATE` and refuses if it changed.
- `OrderController.php` **−17 / +95** — `orderComplete`'s unscoped `Order::find()` on request input and the dead `responce[data][order_id]` branch.
  **Narrower, deliberately** — `data[order_id]` is honoured only for an id this session placed; an ambiguous order number renders neither order.
- `tests/Feature/Tenancy/RawQueryTenancyInventoryTest.php` **−5 / +15** — the census **updated, not loosened** (app 122→123, 167→168,
  139→140, one new SCOPED `orders` row); its unscoped-set test passes unmodified.
- the ledger / JSON / queue deletions are re-rendered task blocks — verified byte-identical to the hand-kept blocks *before* regeneration.

Behaviour narrowed on purpose, all disclosed in the tasks' compatibility fields: `payment-list` returns secret-named fields as **null (keys kept)**;
the visit log no longer stores request bodies, credential headers or credential query values; order-track needs the number **and** the email
and is throttled on both axes; a refund with nothing removed, an unknown product, or a bad quantity is refused.

### 2. Did any file already exist and do something else?

New: `app/Services/GatewaySecretRedactor.php`, `app/Services/SanitisedVisitor.php`, six test files, one MySQL verification script. Modified existing
files are the ones listed above plus `ApiController` (one return), `Setting::paymentList` (one return), `ActiveTheme` (a try/catch),
`AppServiceProvider` (one binding), `RouteServiceProvider` (two limiter definitions and a folding helper), `routes/customer.php` (one route's
middleware), `HomeController::order_track`. The `shetabit-visitor` container binding **replaces** the vendored package's singleton.

### 3. What control assumed the surface you widened was narrow?

Nothing was widened on purpose. Two assumptions changed: `ActiveTheme` used to assume a visit-log failure is fatal (now it is not), and the `auth`
limiter used to 500 on an array-shaped `email` before any handler ran (now it keys it with an absent email, so such a request reaches the login
handlers, which validate `email`). A client **outside both repositories** that decoded a gateway secret as a non-null string from `payment-list` would
break; none is known (CLAUDE.md records that no client in either repository calls the storefront API; the vendor's own API documentation lists the secrets).

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**Yes, and stated plainly.** The storefront visit log's *content* is deliberately narrowed (no request body, an allowlist of headers, redacted query
values), and a visit that cannot be written is now **swallowed with a warning line** instead of failing the page — the log is conditional on its own
success. **No audit or compliance emission is removed:** `ActivityLog`, `AuditLog` and the refund/payment logging are untouched, and nothing reads the
two columns that were emptied (verified by grep: `Api\DashboardController`, `ThemeAnalyticController`, `HomeController`, `StoreController` read
store_id/url/ip/platform/browser/device/created_at only).

### 5. Does any failure become silent?

The visit-log failure above (a `warning` with the class and a 200-character message — deliberately **not** the exception object, because the default log
stack includes an error tracker that would ship the request's cookies off the box). Every other failure introduced is *louder*: a refund that cannot be
recorded rolls back and answers with a handled error and a log line instead of a 500 after the order was already rewritten; a stale order is refused
with a specific message.

### 6. Is method-resolution order involved?

The container binding. Package providers register before application providers in this Laravel 11 bootstrap (READ: `Application.php`,
`bootstrap/cache/services.php`) — and **executed**: dropping the re-binding fails six tests. `SanitisedVisitor` overrides public methods only. Route
declarations: `payment-list` has a single declaration (no later one drops its middleware); `order.track` gained middleware on the one declaration.

### 7. Verified by removing it?

**Yes, control by control, each alone, the file restored byte-identical each time.** Every control bites **except** those named: `JSON_THROW_ON_ERROR`
on the two refund encodes (no request reaches it — `mb_substr` substitutes `?` for invalid UTF-8; measured), and the strict `in_array` flag, the
`&&` gate and the recorded-email-empty check in order-track, and the posted-email trim (each is covered by another control or by the global
`TrimStrings`; the pairs bite together). Redundant regex alternatives were **removed** rather than claimed. My own first versions of several tests
were vacuous and are recorded in the task evidence (a header test that sent no headers; three controls masked by a sum check until a two-ticked-line
test isolated them; a loop test whose by-reference flag made iterations 2 and 3 test nothing).

### 7b. Defects this attestation's own measurements found, in already-pushed work

- The full Feature gate caught **two regressions of mine** that every targeted run missed: a test that greps the controller *source* for
  `refund_status     = 'Processing'` (my re-alignment broke it), and the raw-query census (my `FOR UPDATE` read had no `store_id`). Both fixed in code; the
  test and the manifest are untouched.
- CI's Secret scan caught the wrong local claim described above.
- The "second concurrent request waits" claim in the refund handler was **unexecuted** until a script ran it on MySQL 8.0.46 (InnoDB, REPEATABLE-READ):
  the second locking read blocks (1205), and after the first commits a plain read inside the older snapshot still returns the OLD row while the
  locking read returns the committed one. The same script showed that `MyShop`, `mYSHOP` and `myshóp` match **one** `stores` row — which is why the
  rate-limit keys are now folded to lowercase ASCII. It is registered in `run-mysql-verifications.sh` (11 of 11 scripts pass).
- Two of my own claims were refuted by the reviewers or by measurement and corrected: the invalid-UTF-8 path in the refund record does not reproduce, and
  CyberSource's store route is *not* logged by the visit log (its first path segment is not a slug).

### 8. Merge-base regression gate?

CI is the authority. On head `4a55987d`, read back from the PR's check runs rather than inferred: all **9 of 9** completed with conclusion `success`, none pending, none failed. Seven are the named gates -- Static checks, Secret scan, Security-sensitive diff human checklist, the PayHere report, both REQUIRED Tests jobs ("real suites, clean install from composer.lock" and "PayHere excluded and NOT fixed", GitHub Actions run 39534048785) and the Merge-base regression gate (new failures only) -- and two are the non-blocking "push report" jobs. They span four workflow runs.

### 9. Measured locally at `9223a0a1` (the last code change; later commits are a test-fixture label and records)

Feature **3140 passed, 2 declared failures, 17 declared skips, 0 errors — the known-outcome assertion exits 0**; Unit **339**; Contract **26**; the MySQL
runner **11 of 11**. The five CI guards run exactly as CI invokes them (`guard-test-isolation .`, `guard-no-env-files .`, `guard-gate-f-containment .`,
`guard-no-workdo-changes <main> .`, `scan-secrets <main> .`) exit 0 at `73aeb1e3`. `guard-composer-lock-integrity` exits 1 **locally only** because two
locked packages (`myfatoorah/laravel-package`, `myfatoorah/library`) cannot be installed in this container — the same caveat §10–§12 record; CI passes
`--allow-missing`. **The Feature suite is not green and is not meant to be** — the two accepted failures and 17 accepted skips are declared by name in
`.github/known-test-outcomes.json`, which this range does not modify.

### 10. Owner decisions this range deliberately did **not** take

Unchanged from §10–§12 and recorded in `docs/development/phase-1/15-DEFERRED-OWNER-DECISIONS.md`, plus:
- **P1-OWN-08 (#86)** — the `shetabit_visits` rows already written (plaintext passwords, session cookies, possibly card fields): scrub or purge, whether
  customers are told and made to reset passwords, backups and any BackupRestore dump, sequencing with the APP_KEY rotation. **No data operation was
  performed.** Severity as found: CRITICAL.
- **P1-OWN-09 (#88)** — every store's gateway secrets were readable by any internet visitor through `payment-list`: rotation guidance, a read-only
  check of the access logs, the API log files on the server.
- **P1-ORD-09 (#73)** the forgeable `/order/<ciphertext>` link and **P1-ORD-11 (#75)** the refund workflow — still BLOCKED.
- The production **proxy topology** (no trusted-proxy configuration exists, so every limiter keyed on `ip()` collapses behind a proxy) and whether the
  error tracker is keyed in production (P1-SEC-14).

**`main` was not modified. No deployment, no migration run, no vendored file touched, no live data touched.** The MySQL server used for the executed
verification is this sandbox's own throwaway server, and the scripts create and drop their own databases. **PR #3 remains Draft.**

---

## 14. SECURITY-DIFF-REVIEW: completed — for the **10 commits** `4a55987d..9f031e72`

**Read the scope line first.** §13 ends at `4a55987d`. These ten commits are new, and **nothing in §1–§13 covers them**. Measured: 10 commits, **39 files, +3,816 / −139** —
application code (`app/ bootstrap/ resources/`) 23 files +561 / −123, tests 13 files +2,758 / −0, docs 3 files +497 / −16. **Zero files under `packages/`,
`vendor/`, `themes/`, `.github/`, `composer.json` or `composer.lock`.** (`resources/views/vendor/{campaigns,donation,lottery-prizes}/` are three new *host* overrides of
module views, not edits to them.) Whole PR at this head: **158 commits, 244 files, +79,848 / −1,205** against `main` at `1eebc721`; `packages/` 0, `themes/` 0 — the vendor guard holds.

### The branch synchronization

| condition | result |
|---|---|
| remote refs re-fetched immediately before the push | yes |
| destination still an ancestor of the source | **YES** — `git merge-base --is-ancestor` exit 0 for `4a55987d→9f031e72` |
| mode | **plain `git push`, no force, no `--force-with-lease`** |
| shape | the working branch was pushed after the commits (`a541eeb1`, `b6902fc7`, `8788c030`, `9f031e72`); the PR branch was fast-forwarded **once**, after the final gate; both are **`9f031e72`** |
| `main`, the Draft state | untouched; PR #3 remains Draft |

No history was rewritten. **One commit message is wrong and stays wrong:** `8788c030` says "the tenant bounds it: the other STORE's baskets survived". That was a test
artifact (`actingAs($customer, 'customers')` also makes `customers` the *default guard*, so a tenant resolves; in production the default guard is `web` and the scope adds
nothing). **Measured on the unfixed code, one anonymous `cart-clear` on store 1's slug deleted the logged-in customers' baskets of every store.** It is corrected in
`17012b5b`'s message, in the records and in the issue titles; the pushed commit was not rewritten.

### What this range contains

Six new tasks and one reopened family, all found *by reviews of the one before*:
**P1-SEC-17** (#91) credentials handed to the browser — the Telegram bot token to an anonymous shopper, the Midtrans server key in page HTML, the platform reCAPTCHA secret in a script;
**P1-SEC-19** (#93) `POST /setting-form` rendered the **platform's OpenAI keys** to any merchant or staff login;
**P1-SEC-20** (#94) anonymous Donation/LotteryPrizes responses serialised the whole `Store` row, Duo keys included;
**P1-SEC-21** (#95) `get-massage` deleted the basket lines of a POSTED customer id and built every store's message from the first merchant's template;
**P1-SEC-23** (#97) one guest's WhatsApp/Telegram order deleted *every* guest's basket row for the product on production MySQL (`'' = 0`; executed on 8.0.46);
**P1-SEC-24** (#98) **baskets deleted, taken over and altered through a cookie that was not there** — an anonymous `cart-clear`/`cart-remove`/`change-cart`, any login, and any logged-in
COD or gateway order, across every store; plus seven gateway returns clearing by the stored `customer_id`. P1-SEC-22 (#96) is the READY residue. Every one was **reproduced by
execution through its real route before it was fixed.**

### Who examined which commits — stated so that no review is read as covering a commit it did not see

| commits | examined by | verdict |
|---|---|---|
| `4a85ca3e` (P1-SEC-17) | four read-only lenses over the working tree that became it: completeness, regression, bypass, tests | NON-BLOCKING; the guard's per-request log line, three missing tests (connection failure, empty token, module status) were acted on — **those edits are mine and were not re-reviewed**; the siblings became P1-SEC-19/20/21/22 |
| `eaa0d21f` (P1-SEC-19) | `tenant-authz-reviewer` | **CLEAN** |
| `d809ddb7` (P1-SEC-20, first version) | `tenant-authz-reviewer` | NON-BLOCKING with one MEDIUM: `$hidden` is object state, serialised into the cache, so a `Store` cached before the deploy keeps leaking |
| `c1342d24` (the fix that stands) | **not independently re-reviewed** — a test that revives a pre-fix serialisation fails against `d809ddb7` and passes now; removal-checked |
| `a541eeb1` (P1-SEC-21) | `tenant-authz-reviewer` | NON-BLOCKING; two findings acted on (owner-keyed template lookup; untested authenticated arm); it also surfaced the SEC-23 defect |
| `b6902fc7` (SEC-21 follow-ups, P1-SEC-23) | two read-only lenses (callers; misuse) | NON-BLOCKING; the *typed-e-mail* finding (a guest's queued id is the customer found from the e-mail they typed) was acted on, and the null-cookie delete it pointed at became P1-SEC-24 |
| `8788c030`, `59944003` (P1-SEC-24) | three read-only lenses: regressions, identity/abuse, completeness of the sweep | NON-BLOCKING; every finding acted on or recorded — **including the false tenant claim above**. `59944003` (cart-remove) was made *during* that review and was read by it only in the tree, not in a diff |
| **`17012b5b`** (SEC-24 review follow-ups) | **not independently reviewed** — scripted removal checks (each new control bites), the full Feature gate, nothing else |
| `9f031e72` | not reviewed — records; `validate()` passes on the task graph (88 tasks) |

All reviewers were **read-only**. Their claims are READ; the executed evidence is mine and is named where used. One count of mine was wrong and is corrected in the task records: the no-argument `getAdminAllSetting()` callers are **34**, not 36 (the first count included a comment).

### 1. What does this REMOVE or NARROW?

**Nothing is removed that was not meant to be; 139 deletions across 18 non-test files, each accounted for.**
- `PaymentController.php` **−32 / +104** — `getWhatsappUrl` no longer returns the bot URL and posts through the Http client; its basket clear and template lookup are the principal's and the store's;
  `clearCartLines` identifies a customer by id, a guest by cookie, and skips a line with neither; the queue sites carry the authenticated id plus the cookie. **Narrower, deliberately.**
- the four gateway returns that carried a long justification comment (AuthorizeNet, Easebuzz, PaiementPro **−13** each; Khalti **−12**) — the whole-basket delete is now `Cart::forCustomer(auth('customers')->id())`; the comment that said "StoreScope already adds the predicate" is **removed because it is false in production**.
- `Cart.php` **−9 / +58** — two scopes (`forGuestCookie`, `forCustomer`) that refuse an input that selects too much, and a quantity path that no longer dereferences a missing row before checking it (a 500).
- `PaymentService`, `CartController`, `CustomerLoginController` and three more gateway returns (PayU, NMI, CinetPay) — the same two scopes at their call sites; the login merge now moves only this store's rows.
- `midtras/{order_payment,payment}.blade.php`, `MidtransController`, `PaymentService::processMidtrans` — the server key leaves the view data and the views. `verify-email` — site key, not secret.
- the ledger / JSON deletions are re-rendered task blocks.

Behaviour narrowed on purpose, all disclosed in the tasks' compatibility fields: a non-operator POSTing the ChatGPT tab gets 403; `get-massage` answers an empty body for Telegram and **no longer returns another
tenant's message template** (a store with none sends an empty message — executed: HTTP 200, no 500); a request with **no cart cookie matches no basket** (it matched every logged-in customer's); a returning customer
who is *not* logged in and checks out as a guest with their own e-mail keeps their saved account basket until next login; `change-cart` for an unknown or foreign cart id answers `status 0` instead of a 500.

### 2. Did any file already exist and do something else?

New: `WithholdTelegramBotUrl` (middleware), three host view overrides in `resources/views/vendor/`, `tests/Support/Midtrans/MT_Tests.php` (the SDK's own stub class, which its package ships under `tests/`), ten test files, one MySQL
verification script. `Store` gains `attributesToArray()`. `bootstrap/app.php` gains one `web`-group middleware. Modified existing files are listed above.

### 3. What control assumed the surface you widened was narrow?

Nothing was widened. Two assumptions were *found false*: "a tenant bounds a basket delete" (it does not for a storefront request in production), and "a cart cookie is always present or the lookup matches nothing" (`where(col, null)` is `IS NULL`).
A client **outside both repositories** that read `duo_secret_key` from a serialised `Store`, or a Telegram URL from `get-massage`, would break; none is known and the only shipped page ignores the body.

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**No audit or compliance emission is removed.** A failed Telegram delivery used to raise a PHP warning (a 500 the page ignored); it is now a logged 200, recorded by class and status only — never the message, which carries the token.
`ActivityLog` writes in `cart_remove` are untouched.

### 5. Does any failure become silent?

The Telegram failure above is a `warning` line rather than a 500; the guard that empties a bot URL logs **nothing** deliberately (the review found a per-request line was noise on every legitimate module checkout and a flood an anonymous caller could drive).
A basket line with neither a customer nor a cookie is skipped without a line — HTTP cannot produce that shape. Everything else introduced is louder (a handled "Product not found" instead of a 500).

### 6. Is method-resolution order involved?

Two places. `WithholdTelegramBotUrl` is appended to the `web` group, which reaches module routes that register from `booted()` (same vehicle as `BulkDeleteTenantGuard`) — **executed**: unregistering it fails three tests. The package-view overrides win because Laravel's
`loadViewsFrom()` consults `resources/views/vendor/<ns>` first — **executed** through the production loader, and a drift test fails if a module's own view changes. Route declarations: none added or reordered.

### 7. Verified by removing it?

**Yes, control by control, each alone, the file restored byte-identical each time** (scratch scripts in the session scratchpad, not committed; the commands and outputs are in the task evidence). Every control bites, including a revert in a gateway controller that has no end-to-end test (the tokenizer-based census catches it). Where a removal did **not**
bite, the test was wrong and was fixed: the guest-quantity-count test (it returned a constant before reaching the line), the "guest must not delete `where customer_id = 0`" guard, the connection-failure and empty-token branches of the Telegram sender, the `$hidden`-vs-cache case, and the authenticated arm of `get-massage`.

### 7b. Defects this attestation's own measurements found, in already-pushed work

- **The false tenant claim** in `8788c030` (above) — found by a reviewer's reading, then **measured**.
- `$hidden` in `d809ddb7` (above), found by a reviewer, **executed** to confirm it fails.
- My first `change-cart` reading ("`Cart::find($cart_id)` with the request's id — an IDOR") was **refuted by execution**: it refuses; but the same probe found that **`cart-remove`'s logged-in arm did delete another customer's line** — fixed in `59944003`.
- The first quantity test, the first ratchet regexes (line-based; they missed comments, line breaks, array form) and the first SEC-23 fixture (`customer_id => ''`, which the real checkout never produces) were all too weak and were fixed on review.
- `get-massage`'s fix for the template (`getAdminAllSetting($owner, $store)`) was wrong: it also filters on `created_by`, which `customMassage` overwrites with whoever saved last. Fixed in `b6902fc7`.

### 8. Merge-base regression gate?

CI is the authority. **Read back from the PR's check runs, on head `9f031e72` (GitHub Actions run 39546062018): completed, conclusion `success` — all six jobs:** Static checks, Secret scan, the PayHere report, both REQUIRED Tests jobs ("clean install from composer.lock" and "PayHere excluded and NOT fixed"), and the Merge-base regression gate. Security attestation runs 39546062003 and 39546660551 and the push-attestation run 39546056502: `success`. (This replaces the interim wording written while the Tests jobs were still running.)

### 9. Measured locally at `17012b5b` (the last code change; `9f031e72` is records)

Feature **3208 passed, 2 declared failures, 17 declared skips, 0 errors — the known-outcome assertion exits 0**; Unit **339**; Contract **26**; the MySQL runner **12 of 12** (run at `b6902fc7`; the scripts are unchanged since; one script, reserved role names, **skipped itself** in this container with an
access-denied error). The five CI guards run exactly as CI invokes them (`guard-test-isolation .`, `guard-no-env-files .`, `guard-gate-f-containment .`, `guard-no-workdo-changes <main> .`, `scan-secrets <main> .`) exit 0 — scan: no credential-shaped literal in 241 changed files.
`guard-composer-lock-integrity` exits 1 **locally only** (two locked packages cannot be installed in this container), as §10–§13 record.

### 10. Owner decisions this range deliberately did **not** take

- **Rotation and exposure (P1-OWN-09, #88), extended:** the Telegram bot tokens, Midtrans sandbox server keys, the platform reCAPTCHA secret, **the platform OpenAI key(s)**, and the Duo keys of merchants who used the Donation/LotteryPrizes checkout were readable for as long as the routes have answered;
  baskets already deleted or reassigned cannot be reconstructed. The access-log queries are listed in the task. **Nothing has been rotated, purged or announced.**
- **Guest-order attribution by typed e-mail (S32)** is untouched: an order is still written for the customer the typed e-mail names. Only *whose basket is cleared* changed.
- **Midtrans:** no client-key setting exists; adding one is a schema/UI change. The sandbox `snap.js` was **read, not run**: `data-client-key` is optional for `snap.pay(token)`.
- **P1-SEC-22 (#96)** is READY, not done: an unthrottled, ungated anonymous Telegram relay; an exception object flashed into the Midtrans page; Khalti's return never clears a guest's basket; the vendored `SkipCart` `emptyCart`.
- `main` was not modified. No deployment, no migration run, no vendored file touched, no live data touched. **PR #3 remains Draft.**

## 15. SECURITY-DIFF-REVIEW: completed — for the **4 commits** `9f031e72..3e7eab92`

**Read the scope line first.** §14 ends at `9f031e72`. These four commits are new, and **nothing in §1–§14 covers them**. Measured: 4 commits, **21 files, +2,608 / −72** —
application code (`app/ routes/`) 10 files +397 / −47, tests and test tooling 8 files +1,775 / −0, docs 3 files +436 / −25. **Zero files under `packages/`, `vendor/`, `themes/`,
`.github/`, `composer.json` or `composer.lock`.** Whole PR at this head: **162 commits, 257 files, +82,431 / −1,252** against `main` at `1eebc721`; `packages/` 0 — the vendor guard holds.

### The branch synchronization

| condition | result |
|---|---|
| remote refs re-fetched immediately before each push | yes (three pushes: `9f031e72→bbfc8f2f`, `→4c6ade05`, `→3e7eab92`) |
| destination still an ancestor of the source | **YES** each time — `git merge-base --is-ancestor` exit 0 for both remote branches |
| mode | **plain `git push`, no force, no `--force-with-lease`** |
| shape | both branches were pushed after each commit group and are **`3e7eab92`** |
| `main`, the Draft state | untouched; PR #3 remains Draft |

No history was rewritten. **One thing about CI on the intermediate head:** the run on `4c6ade05` (run 39558813011) was **cancelled by the next push** (the workflow's concurrency group), not failed — the run on `3e7eab92`, which contains it, is the one that counts.

### What this range contains

Two tasks closed and four recorded, all found *by reviews of the one before and by the tests themselves*:
**P1-SEC-25** (#100, S41 F4) a read that named no store answered with the **last tenant's row** — a shopper at store A read store C's Ozow key (`getRouteStoreId()`; `GetValueByName` and `getAdminAllSetting()` use the store the request's route names; the Ozow/Easebuzz reads pass `$store->id`);
**P1-SEC-18** (#92, S41 F5–F7) staff reached a merchant's SMTP/Stripe secrets (admin API **and** web Settings screens), a saved logo deleted another tenant's file, `/zid/{id}/orders` was readable by any login — and, found beside it and **executed**: **(1)** `Utility::changeStorageLimit`
(~35 callers) did `File::glob` + `File::delete` on a database path, so a **staff member could unlink application files** (`logo_dark = config/*`, then save a logo); **(2)** staff could rewrite the **owner's login email** through `updateStoreOwnerInfo`; **(3)** that handler built a path from the raw `store_id`, and
**on MySQL 8.0.46 `'5/../../x'` matches store 5** (script committed; SQLite does not, so the suite is blind to it).
P1-SEC-26 (#99), **P1-SEC-27** (#101), **P1-SEC-28** (#102) are READY residue; **P1-OWN-10** (#103) is BLOCKED on the owner: **407 user-uploaded files are committed to the repository**.

### Who examined which commits — stated so that no review is read as covering a commit it did not see

| commits | examined by | verdict |
|---|---|---|
| `82ba2ea3` (P1-SEC-25) | `tenant-authz-reviewer` on the first version of the diff | no defect introduced; findings acted on (memo keyed by slug, two vacuous tests strengthened, token-class test, explicit store ids). **My later edits — the one-line form of the `Utility` change (needed so the line-pinned inventories do not move), the per-slug memo, the explicit ids, the token test — were not re-reviewed.** Its `/pricing` claim was **refuted by execution** (dead code) and is pinned by a test |
| `4c6ade05` (P1-SEC-18) | **two** `tenant-authz-reviewer` rounds. Round 1 read the first draft and found: the wrong permission (`Manage Store Setting` is the *Store Setting* screen; Settings is `Manage Setting`), that scoping the F6 lookup alone would let a tenant aim the delete, the owner takeover, the open web doors. Round 2 read the corrected tree and found: the doubled `uploads/uploads/<id>` prefix, the product-path glob, the raw store id in a path, NUL bytes, and that my "equivalent mutant" claim for the operator limb was wrong | every finding acted on. **The edits made after round 2 are mine and were not independently re-reviewed** — the doubled-prefix peel, `changeproductStorageLimit`, the NUL check, `$storeId = $store->id`; each is covered by a test that was mutation-checked |
| `bbfc8f2f`, `3e7eab92` | not reviewed — records; the ledger/JSON checker passes (93 tasks) |

Both reviewers were **read-only**. The glob finding and both incidents below were found by **my own tests**, not by a reviewer.

### 1. What does this REMOVE or NARROW?

**Nothing is removed that was not meant to be; 72 deletions across 8 files (47 in application code, 25 in the re-rendered docs), each accounted for.**
- `Utility.php` **5 / 5** — `changeStorageLimit` and `changeproductStorageLimit`: `File::glob(base_path($path))` → one literal file inside the owner's part of `uploads/` (`StoredUpload::resolveAll`). **Narrower, deliberately: a wildcard or a path outside it is ignored, so a file that used to be removed may now stay on disk.**
- `AppSettingController` **−20 / +25** — five unscoped `Setting::where(name)->first()` + `File::exists/delete` → scoped to the store and `StoredUpload::delete`. `DashboardController` **−12 / +96** — the same for the three payment images, the five-handler gate, and the owner-identity rule.
- `PaymentService` **−8 / +11** — eight credential reads gain `$store->id`. `routes/web.php` **−2 / +7** — the two Zid routes gain `PlatformOperatorOnly`.
- Behaviour narrowed on purpose: a staff member without `Manage Setting` gets **403** (never 401) on the SMTP/Stripe handlers and the four web Settings handlers; staff can no longer **change** the owner's name/e-mail/mobile (resubmitting the stored values is fine); `/zid/{id}/orders|webhooks` are operator-only; a shopper's store-less settings read now serves the store the route names. The merchant **owner** is unaffected by every gate (the owner limb).

### 2. Did any file already exist and do something else?

New: `StoreSettingsAccess`, `StoredUpload`, `getRouteStoreId()` in `helper.php`, six test classes, one MySQL verification script (registered in the runner). Existing files modified are listed above. **The line-pinned inventories (`EloquentScopeBypassInventoryTest`, `ResidualTenancyExposureTest`) were not moved**: the `Utility.php` edits are line-neutral on purpose.

### 3. What control assumed the surface you widened was narrow?

Nothing was widened. Assumptions found **false**: "a settings read without a store id reads the caller's store" (it read the last tenant's, for every shopper); "the Settings menu being hidden protects the Settings routes" (the routes had no permission check); "`Manage Store Setting` is the Settings permission" (it is the Store Setting screen's); "`resolveOwnedStore()` is a permission check" (it admits the owner's staff); "scoping a lookup to the caller's store makes a delete safe" (the caller can write that row — it is a path they choose); "a stored path is a file name" (it was a glob).

### 4. Is any audit, logging or compliance emission removed, skipped or made conditional?

**No.** `StoredUpload` adds a `warning` (the path only) when it refuses a path outside `uploads/` or another tenant's folder.

### 5. Does any failure become silent?

A refused unlink is silent to the user (the row is still updated; the file stays) and logged as above. Routes that name **no** store, and an unknown slug, still take the old settings read (P1-SEC-26).

### 6. Is method-resolution order involved?

The Zid routes carry `['auth', PlatformOperatorOnly]` — `auth` first, because the operator middleware passes an anonymous request through (**executed**: an anonymous caller is still redirected, not given a 403). Each Zid URI is declared once (the reviewer grepped for a shadowing declaration and found none). No route was added or reordered.

### 7. Verified by removing it?

**Yes, control by control, files restored byte-identical each time** (a mutation harness, ~50 mutants: each of the nine gates, each of the eight lookups and unlinks, every `StoredUpload` rule, the owner/operator limbs, the permission name, the owner-identity fields, the Zid middleware, the route-store fallbacks). Every control bites. Survivors the harness found were test gaps and were closed (a test for each single owner field; a poisoned-path test for every upload handler; the operator limb is **not** an equivalent mutant and has a test).

### 7b. Defects this attestation's own measurements found, and an incident

- The glob delete in `changeStorageLimit` — found by my own test, **executed**, fixed in the same commit.
- **INCIDENT — two of my own test drafts deleted TRACKED files from the working tree, before any commit.** (a) A teardown that removed `uploads/<store id>` collided with the real tracked folders (`uploads/2`, `uploads/11`, …): **72 files**. (b) A mutation run that re-enabled the glob, with a test that passed `*` and `uploads/*/*` to `changeStorageLimit`, removed the **repository root (artisan, composer.json, …) and 131 uploads**. Both were restored with `git checkout`; `git ls-files -d` is empty; **no commit contains a deletion.** The tests now use only sandbox paths and store ids 987001/987002, and the harness restores after every mutant.
- The first draft of P1-SEC-18 used the wrong permission and would have widened the delete (found by the review, never pushed). My first reading of the `/pricing` page was a disclosure; **executed**, it is unreachable (recorded as refuted).

### 8. Merge-base regression gate?

CI is the authority. **Read back from the PR's check runs:**
- `9f031e72` (§14's head), run 39546062018: **all six jobs `success`** — Static checks, Secret scan, the PayHere report, both REQUIRED Tests jobs, and the Merge-base regression gate; Security attestation runs 39546062003 and 39546660551 and the push-attestation run 39546056502: `success`.
- `bbfc8f2f`, run 39550534096: **all six jobs `success`**.
- `4c6ade05`, run 39558813011: **cancelled by the next push** (concurrency), not a failure.
- `3e7eab92` (**this head**, the PR's current head), run 39559080258: **completed, conclusion `success`** (every job); Security attestation run 39559080240: `success`.

### 9. Measured locally at `4c6ade05` (the last code change; `3e7eab92` is records)

Feature **3313 passed, 2 declared failures, 17 declared skips, 0 errors — the known-outcome assertion exits 0**; Unit **339**; Contract **26**. The five CI guards run exactly as CI invokes them exit 0 — scan: no credential-shaped literal in 254 changed files. The new MySQL script was run **on its own** against MySQL 8.0.46 and held all its assertions; **the whole MySQL runner was not re-run in this range** (it was 12 of 12 at `b6902fc7`).
The Feature tests run on **SQLite**: the admin-API write handlers use MySQL-only `insert … ON DUPLICATE KEY UPDATE`, so an "allowed" write is asserted as "not 403" and a refusal as an exact 403 with the row untouched. `guard-composer-lock-integrity` exits 1 **locally only**, as §10–§14 record.

### 10. Owner decisions this range deliberately did **not** take

- **P1-OWN-10 (#103), new:** 407 user-uploaded files (screenshots, a receipt, profile images) are **committed to the repository**; whether to keep, purge (a history rewrite — not done) or ignore `/uploads/` is the owner's. Engineering deleted nothing from it.
- **Read-only data checks for the owner:** `SELECT store_id, value FROM settings WHERE name = 'storage_setting'` (P1-SEC-26), `SHOW INDEX FROM settings` — the migration has no unique key on `(name, store_id)` (P1-SEC-27).
- **P1-OWN-09 (#88)** is unchanged: nothing has been rotated, purged or announced.
- **P1-SEC-27/-28 are READY, not done:** staff can still write any setting **by name** through the web's generic writers, and the admin API's Cod/Bank/Loyalty/currency writers have no permission check; ~25 direct `File::delete(base_path(<db value>))` sites bypass `StoredUpload`, some in **vendored** modules that cannot be edited.
- `main` was not modified. No deployment, no migration run, no vendored file touched, no live data touched. **PR #3 remains Draft.**

---

SECURITY-DIFF-REVIEW: completed

---
_§1–§9 generated by [Claude Code](https://claude.ai/code/session_01NpsMSm55F4YSkwEte13M7C) · §10, §11, §12, §13, §14, §15 and the current counts by [Claude Code](https://claude.ai/code/session_011xBKzZ9gjc6cKu9DEj8Raz)_

---

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_011xBKzZ9gjc6cKu9DEj8Raz