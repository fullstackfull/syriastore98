# Phase 01 closure — execution tracker

**The single tracker for the 57 tasks that were not DONE at checkpoint `fb556cd6`.** Status, what
each depends on, what evidence closes it, and for the blocked ones **which kind of blocker** it is.

This is not a plan and not a second copy of `PHASE-01-FINAL-DECISION-REPORT.md`. The report explains
*why*; this tracks *where each task is*. Where a row says a thing was executed, the command and the
output are in the report or in the commit message named on the row.

**Live counts** (`ledger-tool.py counts`, re-read on every update): the ledger is the authority; this
file is a view of it. If the two disagree, the ledger is right and this file is stale.

| | at `fb556cd6` | now |
|---|---|---|
| DONE | 48 | **49** |
| READY | 17 | **16** |
| BACKLOG | 9 | 9 |
| BLOCKED | 31 | 31 |
| **total** | 105 | 105 |

---

## 1. The blocker taxonomy, and why it matters

The owner directive asked for the 31 BLOCKED tasks to be classified before anything else, because
"BLOCKED" had been doing five different jobs at once. The five categories, and what each obliges:

| | Category | What it means | What I do |
|---|---|---|---|
| **A** | **Already decided** | An owner decision exists in the record. | Implement it. No question. |
| **B** | **Engineering blocker** | The open question is a technique, not a policy. | Settle it and execute. **Asking the owner would be the error.** |
| **C** | **Environment blocker** | Needs a read-only observation or a server I do not have. | Prepare the exact command and the expected evidence. **Not an open architecture question.** |
| **D** | **Owner decision** | A policy, money, privacy or product call. | One consolidated question with a recommendation. |
| **E** | **Production authorization** | The work is known; executing it changes production. | Write the runbook. Execute nothing. |

The directive was explicit that **C and E are not unresolved architecture decisions**, and that I must
not ask the owner to choose between implementation techniques engineering can determine. Applying
that honestly moved four tasks out of the owner's queue — see §2.

### The result

| Category | Count | Tasks |
|---|---|---|
| **A** already decided | 0 | — |
| **B** engineering — mine to settle | **4** | P1-INV-06, P1-SEC-07, P1-PLT-02 (guard half), P1-SEC-32(a)(b) |
| **C** environment — command prepared | **7** | P1-OWN-01, P1-OWN-03, P1-OWN-06, P1-OWN-07, P1-OWN-11, P1-PAY-03, P1-SEC-26(1) |
| **D** owner decision | **13** | P1-ORD-03, P1-PAY-04, P1-PLT-04, P1-INV-12, P1-INV-20, P1-INV-21, P1-INV-22, P1-ORD-09, P1-ORD-11, P1-OWN-04, P1-OWN-05, P1-OWN-10, P1-OWN-12, P1-SEC-32(c)(d) |
| **E** production authorization | **7** | P1-MIG-01, P1-MIG-02, P1-MIG-03, P1-MIG-04, P1-OWN-02, P1-OWN-08, P1-OWN-09 |

Thirteen D rows **do not become thirteen questions.** They consolidate into **seven** decision
blocks (§4), because most of them are the same decision asked about different code.

---

## 2. Category B — four tasks that were never the owner's to decide

Each of these sat in BLOCKED with a blocker that reads like a question but is an engineering
determination. This is the directive's point, and it was worth four tasks.

### B-1 · P1-INV-06 — "upstream module fix vs a host route middleware"

**Not a decision.** The project skill already settles it, and the acceptance criteria permit either.
A route re-declaration does **not** work here: module route files load from `app->booted()`, *after*
`routes/web.php`, and `RouteCollection` keys on method+domain+uri, so the module's declaration wins
the slot and the middleware silently disappears. The mechanism that works is

```php
$middleware->appendToGroup('web', \App\Http\Middleware\YourGuard::class);
```

which also survives `route:cache` (the cache stores middleware *names* unexpanded). There is an
established precedent in the host app. **→ READY. No owner input.**

### B-2 · P1-SEC-07 — the blocker's premise has a counter-example in this repo

Its stated blocker is: *"No host mechanism is known that covers all six modules AND the
console/queue context."* That premise is **not established**, and the repository contains a working
counter-example:

`app/Providers/AppServiceProvider.php:54-61` registers a listener **by event-name string** —

```php
// REGISTERED BY EVENT-NAME STRING rather than `PurchaseTicket::observe()`, because the
// model lives in a vendored module that may not be loaded.
Event::listen(EnforceLotteryTicketPrice::EVENT, EnforceLotteryTicketPrice::class);
```

— precisely to reach a vendored module's model without loading it, and model events fire in
**console and queue** as well as web, which route middleware does not.

**Stated at the confidence it deserves:** this proves the *class* of mechanism (container/event
level, not route level) is available and does reach the console. It does **not** by itself prove a
hook exists for this particular path, because the six modules *resolve* an email template — a read,
not a model save — and a model event is the wrong shape for a read. The right candidate is a
container binding or a resolver the template lookups already pass through.
**→ READY for engineering investigation, not BLOCKED on the owner.** If the investigation finds no
host mechanism, *then* the version-pinned upstream report its acceptance criteria describe is the
answer — and that is a finding, not a question.

### B-3 · P1-PLT-02 — two halves, and only one is blocked

Acceptance is two criteria: (1) *"Neither writer can persist an order with a NULL `store_id`"* and
(2) *"Existing NULL-store rows are COUNTED and reported (not modified)"*.

(1) is pure host-app engineering — a guard on two writers — and needs nobody. **→ READY.**
(2) needs the live database, and the script already exists at
`tests/Support/Security/bin/report-null-store-orders.php`. **→ C, see §3.**

Splitting it is the whole point: as one row it looked owner-blocked, and the guard — the part that
stops *new* bad rows — was waiting on a *count of old ones*.

### B-4 · P1-SEC-32(a)(b) — the platform's own host

Four sub-questions. **(a)** *"may ANY store ever hold the platform's own host (the `APP_URL` host)?"*
is not a business call: a store holding `storesyria.com` as a custom domain is served in place of the
platform's own admin host — a takeover vector. The task's own acceptance criteria say as much
("if not, engineering adds a one-comparison reservation in `HostClaims`"). **Settled: always refuse.**
**(b)** reserved sub-domain labels — engineering ships a conventional list (`www admin api mail ftp
cpanel webmail app static cdn assets mx ns1 ns2 autodiscover`); the owner may extend it, which is not
a blocker. **(c)** and **(d)** are genuinely the owner's and the task is right that engineering must
not pick a registrar policy — they go to §4, decision block **D-7**.

---

## 3. Category C — seven environment blockers, with the exact commands

**None of these is an open architecture question.** Each is one observation I cannot make from here.
Every command below is **read-only**: no `UPDATE`, no `DELETE`, no `ALTER`, no migration.

| Task | What is needed | Command | Expected evidence |
|---|---|---|---|
| **P1-OWN-11** | three read-only facts about the live `settings` table | `SHOW INDEX FROM settings;` · `SELECT value, COUNT(DISTINCT store_id) c FROM settings WHERE name IN ('domains','subdomain') GROUP BY value HAVING c > 1;` · `SELECT id, store_id, name FROM settings WHERE BINARY name <> LOWER(name) AND LOWER(name) IN ('domains','subdomain','enable_domain','enable_subdomain');` | the real index set; any host held by >1 store; any case-renamed row |
| **P1-OWN-01** | BackupRestore containment | `SELECT id, name, is_enable FROM add_on_managers WHERE name LIKE '%ackup%';` then `php artisan route:list --name=backup` | whether a row exists at all — **"disabled" is not containment**, `loadDynamicAddons()` plucks with no `is_enable` predicate, so a disabled row still registers every route |
| **P1-OWN-07** | WooCommerce transfer residue | `SELECT store_id, COUNT(*) FROM woocommerce_product_mappings GROUP BY store_id;` plus the product-ownership join in the task | which rows the pre-fix primitive moved |
| **P1-PLT-02**(2) | NULL-store order count | `php tests/Support/Security/bin/report-null-store-orders.php` | counts and store ids; **reports, never modifies** |
| **P1-OWN-03** | the compromised API log | `ls -la storage/logs/api.log && wc -l storage/logs/api.log` then `curl -I https://storesyria.com/storage/logs/api.log` | whether it exists, its size, and whether it is **fetchable** — the last is the finding |
| **P1-PAY-03** | gateway verification | provider sandbox credentials in the environment, then the per-gateway harness | one callback per gateway proving server-side verification |
| **P1-SEC-26**(1) | acceptance criterion 3 | the settings read on a route naming no store, observed against live data | which merchant's settings a store-less route returns |
| **P1-OWN-06** | branch protection on `main` | repository setting — owner action in GitHub | required checks recorded |

**P1-OWN-03 carries an instruction, not just a question.** If that `curl -I` returns `200`, an
unauthenticated log containing plaintext passwords and bearer tokens is being served right now. That
is a containment item, not a survey — it belongs with §5's operator list.

---

## 4. Category D — thirteen tasks, seven questions

Consolidated, as the directive required. Each block: the question, the recommendation, and what it
costs to get it wrong. **No block invents a prior approval.**

**D-1 · COD settlement timing** *(P1-PAY-04 — the highest-leverage decision in the project)*
Does a cash-on-delivery order settle at order time or at delivery? Until this is answered GATE F
cannot be released, and without GATE F Phase 01 cannot be accepted.
**Recommend: per-store configurable, default settle-on-delivery.** Changes when merchants see their
money, so it needs explicit acceptance, not inference.

**D-2 · Schema / GATE F** *(P1-MIG-01…04 — also E, see §5)*
Fifteen tables live code reads and writes with no migration, six money-bearing, and **15 of the 17
declared test skips name one of them**. Order: MIG-02 → MIG-03 → D-1 → MIG-01 → MIG-04, each with a
rollback plan.

**D-3 · Data retention and deletion** *(P1-ORD-03, P1-OWN-10)*
Should `user_delete` hard-delete orders while soft-deleting the account? May 407 committed
user-uploaded files stay in git history? Both are retention/privacy policy.
**Recommend: orders are financial records — retain, soft-delete only.** The directive forbids me
changing privacy-retention on an assumed approval, so these stay open.

**D-4 · Token lifetime and order-link credentials** *(P1-OWN-04, P1-ORD-09)*
Tokens never expire and all carry `['*']`; there is no refresh path for any client; the order link is
`Crypt::encrypt(id)` and forgeable with the leaked `APP_KEY`.
**Recommend: narrow abilities now** (breaks no installed app) and **defer expiry** until a refresh
path ships, because the shipped Flutter app force-logs-out on any 401. For P1-ORD-09 the *mechanism*
is engineering (signed, expiring, order-bound); what is yours is the **lifetime** and whether old
links are honoured during `APP_KEY` rotation. That narrows it from "design the credential" to a
number and a cutover rule.

**D-5 · LotteryPrizes containment** *(P1-INV-20, P1-INV-21, P1-INV-22)*
Seven-plus defects in vendored code that must not be edited.
**Recommend: containment — delete the `add_on_managers` row and prove by test that the routes are
gone.** Note the trap: **"disabled" is not containment.** This is consistent with the recorded D-02
(the lottery payment rewrite is **not** enabled); a refuse-only allowlist may still ship.

**D-6 · Destructive-default behaviours** *(P1-INV-12, P1-ORD-11, P1-PLT-04)*
Deleting a category deletes every product in it by FK cascade, below the application. A storefront
refund *request* rewrites order totals before any merchant decision. Module loading swallows every
exception with no log line.
**Recommend: refuse-while-not-empty for categories; a refund request records intent and changes
nothing until acceptance; log-and-continue for module loading.** All three change behaviour merchants
can see, and the refund one touches settlement — which I will not change on an assumed approval.

**D-7 · Who may hold a host, and who may inject script** *(P1-SEC-32(c)(d), P1-OWN-12, and the domain
audit's §8)*
**This is the consolidated domain block, and it is the one your numeric-subdomain directive lands
in.** Four things, and only these four are yours:
1. Do numeric subdomains **supersede discovery recommendation D-08(a)** ("drop custom domains;
   slug-only addressing")? That is the only domain recommendation on file and it points the other
   way. **Recommend: yes, supersede — and say so, so the record stops holding two opposed positions.**
2. Must a custom domain be **proven** before it wins a lookup (DNS TXT challenge), or is first-come
   acceptable? **Recommend: require proof.** Today a host is taken by whoever saves it first.
3. Who **releases** a host a squatter holds — an operator action, or a rule? Engineering will not
   pick a registrar policy.
4. At cutover, do session cookies move to `.storesyria.com`? **Recommend: no — per-host scope.**
   Moving them logs every merchant out once and shares a cookie scope across tenant hosts.
Plus **P1-OWN-12**: may a holder of `Manage Store Setting` alone write `storejs`, `storecss` and
`google_analytic` — script on every storefront page, checkout included? **Recommend: require a
second, higher permission.** That is script injection into a payment page.

**Full detail: `docs/development/phase-1/P01-DOMAIN-IDENTITY-AUDIT.md`.** Its §0 records, with
evidence, that **no previously approved numeric-subdomain design exists in this repository** — the
authoritative register has no domain entry, D-08 is an unratified recommendation pointing the other
way, and #111 holds open questions. The requirement's authority is the directive of 2026-10-22. I
will not represent it as a recovered approval.

---

## 5. Category E — seven production authorizations. Runbooks only.

**Nothing in this section has been executed, and nothing will be without explicit authorization.**

| Task | The action | Prerequisite | Note |
|---|---|---|---|
| **P1-OWN-08** *(critical)* | scrub/purge `shetabit_visits.request` and `.headers` | read-only inventory first, then a trial on a **restored copy** | **The only item in the project that is a live exposure rather than a latent defect.** The code hole is closed; the rows remain. No application function reads those two columns. |
| **P1-OWN-09** *(critical)* | rotate every store's payment-gateway secrets | treat as exposed | **Platform keys first** (reCAPTCHA, OpenAI — owner-only, no merchant action), then notify merchants |
| **P1-OWN-02** | rotate `APP_KEY` + four tracked config secrets | **inventory encrypted-at-rest columns FIRST** | rotating before the inventory **corrupts every encrypted column**. Order: inventory → re-encrypt → rotate |
| **P1-MIG-01** | release GATE F (six money-bearing tables) | D-1 (COD) + P1-MIG-03 | drafts held unrunnable in `database/migrations/_gate_f_pending/` |
| **P1-MIG-02** | `customers.password_otp` INTEGER → string | owner approval | migration prepared, **not applied**; verified on MySQL by `verify-sec13-migration-on-mysql.php` |
| **P1-MIG-03** | `products.cost_price` + two `delivery_boys` columns | with MIG-01 | the two `delivery_boys` columns are fillable and written by two handlers that **500 for everyone** today |
| **P1-MIG-04** | nine more undeclared tables | approval + a decision on two retired chatwoot tables | |

### Immediate operator action list

Independent of everything above, and in this order:

1. **`curl -I https://storesyria.com/storage/logs/api.log`** — if `200`, an unauthenticated log of
   plaintext passwords and bearer tokens is being served. Deny it at the webserver today.
2. **Rotate the platform's own keys** (reCAPTCHA, OpenAI). Owner-only, no merchant coordination.
3. **Move `DocumentRoot` to `public/`** — one change, closes a whole exposure class.
4. **Inventory encrypted columns** before any `APP_KEY` rotation.
5. **Ask the host: does a wildcard certificate cover `*.storesyria.com`?** Costs nothing and decides
   whether the numeric-subdomain work needs per-store provisioning at all. Both Webuzo call sites
   pass `issueSsl = false` today, so anything that path provisions gets **no certificate**.

---

## 6. The 16 READY tasks

Priority order is the directive's: real security exposure, customer isolation, payments, orders,
refunds, storage safety, configuration integrity.

| Task | Risk | Why it ranks here |
|---|---|---|
| **P1-SEC-22** | medium | an **unthrottled, ungated anonymous** `POST generate/response` — every merchant and every staff member can spend the platform's OpenAI budget |
| **P1-SEC-06** | high | authenticated SSRF, four import handlers; `WoocomCategoryController:102` verified |
| **P1-ORD-14** | high | refund restocks by **order-line position**, so a partial refund restores the wrong product; the over-restore limit is bypassable in a normal case (one product on two lines) |
| **P1-SEC-11** | medium | `getCurrentStore()` still hardcodes `1` for the operator |
| **P1-SEC-31** | high | nine vendored controllers write `settings` by caller-supplied name with no permission check |
| **P1-SEC-28** | high | unlinks of a DB/request path that bypass `StoredUpload` |
| **P1-SEC-34** | high | anonymous review description reaches the merchant dashboard |
| **P1-INV-13** | high | three unscoped mapping resolves, one still carrying a store-transfer primitive |
| **P1-SEC-14** | high | the error tracker receives request, cookies, session and input on every exception |
| **P1-SEC-16** | medium | credential-at-rest gaps outside the visit log |
| **P1-ORD-10** | medium | any shopper makes any store email its customer an attacker-chosen link |
| **P1-ORD-12** | medium | refund id resolved as an order id |
| **P1-ORD-13** | medium | thank-you page identifies an order by a **non-unique** timestamp |
| **P1-INV-15** | medium | `product_return`: POS orders 500, no transaction, double-credit |
| **P1-INV-09** | medium | stored media paths expanded as **glob patterns** and unlinked with no uploads-root containment |
| **P1-PLT-06** | medium | saving a settings tab **removes the store's custom domain at the hosting panel** — note the overlap with §4 D-7 |

**Closed this session:** P1-SEC-12 (`5bb30127`) — the one *reachable* cross-tenant read the 25-task
audit found. `edit_address_form` resolved any address row on the platform by bare id behind a
presence-only check, and it is the handler the shipped address book's Edit control calls. Fixed
through the existing `shopper()`/`ownedAddress()` predicate; 3 tests; two removal mutants both bite.
The ratchet meant to prevent exactly this had never listed `DeliveryAddress::find(` — the spelling
the defect used — so it was **green over a live cross-tenant read**; it now keys on the bare-id
constructor for both models, strips comments, and asserts its own reachability.

---

## 7. The 9 BACKLOG tasks

| Task | Disposition |
|---|---|
| **P1-ORD-02** | **mandatory** — one server-authoritative total, asserted. The `OrderTotalReconciler` cutover is **incomplete and the code says so**; my earlier "9 call sites" claim was inherited and wrong. Needs a seam + survey test. |
| **P1-PAY-02** | **mandatory** — idempotency on gateway returns. Use the conditional-transition form. **My first predicate was wrong:** `payment_status <> 'Paid'` matches no NULL row, so every legitimate first call looks like a replay. The NULL must be spelled out. |
| **P1-INV-03** | **mandatory** — POS writes `product_stock` to an absolute value from the POS session |
| **P1-INV-05** | primitive half only; **criterion E3 now has real-MySQL evidence** (`4f2d0f41`, 12 checks) |
| **P1-ORD-06** | **mandatory** — auction status change POSTs host-table PII to a merchant-controlled URL |
| **P1-PLT-03** | **mandatory** — `forStoreContext`/`allowCrossTenant` have **zero call sites** |
| **P1-ORD-01** | `total_final_price` ignores the coupon |
| **P1-INV-16** | auction `product_return` restocks an unscoped `Product`, no replay guard |
| **P1-PLT-05** | observability — refusal logging reaches somewhere an operator looks |

**Auction restriction retained:** `auction:order` **is not activated and not scheduled.** That is
recorded owner decision D-03 and nothing here changes it.

---

## 8. Verification state

Everything below was executed. Where a number differs from an earlier claim of mine, the earlier
claim was wrong and the correction is noted.

| Gate | Result |
|---|---|
| Unit | 351 passed |
| Contract | 26 passed |
| **Feature + known-outcome gate** | **5234 passed, 2 declared failures, 17 declared skips; gate exit 0; manifest UNMODIFIED** |
| Security | 3545 passed, 4 skipped |
| Tenancy + Commerce | 843 passed, 13 skipped |
| Guards + contrast | 4 guards, exit 0 |
| **Secret scan** | **exit 0 over 351 changed files, with the PR's real base ref** |
| **MySQL (8.0.46)** | **runner green; + 18 new checks for store-slug identity** |
| Mutation | 24 mutants, 24 bite |

### Corrections this session, kept visible on purpose

1. **The domain audit's null-slug claim was wrong.** It said a null slug is stored and `getStore()`
   fails open via `whereNull`, citing production's `strict => false`. **MySQL 8.0.46 refuses the row
   under strict *and* non-strict** — non-strict waives implicit defaults and truncation, not an
   explicit NULL into NOT NULL. The real consequence is a **500 on store creation**: still a defect,
   but louder and with no cross-tenant reading.
2. **The derived slug is `--1`, not `-1`** — the `/-+/` collapse runs *before* de-duplication.
3. **A collation-wide collision class was missed entirely** until the MySQL script existed:
   `getStore('Damascus')` resolves a stored `damascus` under `utf8mb4_unicode_ci`, and the
   de-duplication's own `LIKE` folds the same way. SQLite's BINARY collation cannot show it.
4. **A secret-scan census over-reported 4×** because it was keyed on an approximation of the rule
   rather than the rule; exactly one line matched.
5. **A removal-check mutant silently failed to apply** and the run reported all-green — i.e.
   unmutated code. Re-scoped and re-run. A harness that tests the wrong file produces exactly the
   output a thorough run produces.

---

## 9. Restrictions observed

No merge to `main`. No deployment. PR #3 remains **Draft**. No persistent migration run. No live
data read or modified. No credential rotated. No force-push — both designated branches advanced
**fast-forward only** and are identical. No `packages/workdo/` file edited. No CI guard weakened and
no entry added to `.github/known-test-outcomes.json`. No DNS or hosting configuration touched and no
certificate requested. **Phase 02 not started.**
