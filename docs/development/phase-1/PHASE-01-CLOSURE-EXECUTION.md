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
| READY | 17 | **17** |
| BACKLOG | 9 | 9 |
| BLOCKED | 31 | **30** |
| **total** | 105 | 105 |

(READY returned to 17 and BLOCKED fell to 30 because P1-PAY-04 was unblocked by owner decision D-06,
not because a task regressed.)

---

## 0. Owner decisions of 2026-10-22, and the release blockers that survive them

Six decisions landed (recorded in full in `15-DEFERRED-OWNER-DECISIONS.md` as **D-06 … D-11**). They
closed the three questions this tracker named, and the sections below are left as written so the
before/after is legible rather than rewritten into agreement.

**What they closed:** COD settlement timing (D-06, implemented in `e54e4bff`), numeric subdomains and
custom-domain proof of control (D-07), the platform OpenAI key (D-08, implemented in `e326f0b3`),
retention at 24 months (D-09), key rotation deferred with the exposure recorded (D-10), and the
release path approved in principle (D-11).

### P1-SEC-28, scoped but not started — and one finding that measured FALSE

Investigated read-only. `app/Services/StoredUpload.php` (`resolve`/`resolveAll`/`delete`) and
`app/Services/StoredPathPolicy.php` both already exist, so criteria 1–2 have their machinery; the work
is the census test pinning which unlinks are database-derived, plus the three named items
(`ImportExport`'s default image, `attachmentDestroy`'s ticket scoping, `update_user_image`'s shared
avatar). `ProductController:2198` is already guarded by `isInsideUploadsRoot()`.

**And a candidate defect that did NOT survive measurement, recorded because a plausible-looking
non-defect costs as much attention as a real one.** `LanguageController::destroyLang($lang)` validates
nothing and does `unlink($langDir . $lang . '.json')` — the shape of a path traversal, with only an
`isAbleTo('Delete Language')` check and that permission seeded to merchant roles. Measured against the
real router:

| request | result |
|---|---|
| `DELETE lang/en` | matches, `lang='en'` |
| `DELETE lang/../../composer` | **no match** |
| `DELETE lang/..%2F..%2Fcomposer` | **no match** |
| `DELETE lang/%2e%2e%2f%2e%2e%2fcomposer` | **no match** |
| `DELETE lang/..` | matches, `lang='..'` → targets a file literally named `...json` |

The route is `lang/{lang}` and Laravel's default segment pattern is `[^/]+`, so no value containing a
separator reaches the handler in raw or encoded form. **Not a traversal.** A validation rule would be
reasonable hardening; it is not the defect it resembles and is not logged as one.

---

### The release blockers that remain — four, and only one is engineering

| | Blocker | Kind | Note |
|---|---|---|---|
| **1** | **GATE F schema dump** — `SHOW CREATE TABLE` for 15 tables from a **restored backup**, a read-only NULL census over the 56 `store_id` tables, `SELECT DISTINCT type FROM users` | **environment** | Collapses with D-11's backup + restoration-rehearsal condition into ONE prerequisite. The drafts are **guesses** from `$fillable`; "the dump wins on every disagreement". Promoting a guess is worse than waiting, because `Schema::hasTable()` guards would make the later correct migration a silent no-op and freeze wrong types permanently. |
| **2** | **Wildcard DNS + SSL verification** for `*.storesyria.com` | **environment** | D-07's own condition. Both Webuzo call sites pass `issueSsl = false`, so anything that path provisions gets **no certificate**, and the credentials are absent so it throws. |
| **3** | **Three acceptance criteria need a browser or live server** | **environment** | P1-SEC-06 criterion 3 (the Shopify redirect fix needs the live Admin API), P1-SEC-11 criterion 4 and P1-SEC-26 (Arabic RTL / English LTR rendering and contrast). No further engineering reaches DONE on these here — it is one session with a rendered app and staging, or an explicit decision to accept them on test evidence. |
| **4** | ~~**Who releases a squatted host** — P1-SEC-32(d)~~ | ~~owner~~ | **WITHDRAWN 2026-10-24. This row was WRONG.** It was written against the older `D-07` entry and missed **D4** in the final owner-approved set, whose scope column reads *"`P1-SEC-32` entirely, including (d)"*: *"forced release/transfer is operator-only after verifying ownership, with previous holder, new holder, operator, reason and time in the audit log; DNS change alone never transfers a claim"*. So (d) is **decided**, and the rule-based option this row floated is the one D4 explicitly forbids. What remains is **implementation**, gated on D15 like (c): the release path needs an audit record with five named fields, and no table provides one. **There is no outstanding owner decision on #111.** |

**The remaining COD engineering sits behind blocker 1**, so it is not separately schedulable: the
verified-collection trigger (today it is the merchant *marking delivered*, a proxy for collection
rather than evidence of it), separate courier/settlement tracking, and the per-store workflow all need
the GATE F tables.

**No longer blockers:** `P1-OWN-02` / `P1-OWN-09` (rotation — deferred by D-10, exposure recorded
accurately rather than softened), `P1-ORD-03` (retention — D-09; orders are financial records so
deletion is selective, not a hard delete), `P1-SEC-22(e)` (platform key — done), `P1-SEC-32(a)(b)(c)`
(D-07).

**One ordering hazard is now discharged, and it is worth knowing why it mattered.** The GATE F README
warns that creating those tables does not fix a bug — it makes a **silently dead** subsystem start
working (`OrderFinancialService` throws `QueryException`, `OrderObserver` catches `\Exception`, so all
financial tracking currently logs and does nothing), and that a premature `Paid` would then credit
wallets against cash never collected. D-06 plus `e54e4bff` make COD `Pending` at creation, so that
specific hazard is closed **before** the subsystem is switched on. The README's
`PaymentService.php:2280` citation was re-verified and is **stale** — that line is coupon logic; of the
four literal `'Paid'` sites two are already fixed and the fourth is a counter sale where `Paid` is
correct. All 52 payment arms were **not** re-audited, so D-05 still stands.

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
| **P1-INV-12** | the **live** `products.category_id` delete rule, now that D13 is implemented | `SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'products';` and the same for `TABLE_NAME = 'testimonials'` | `CASCADE` confirms the refusals are load-bearing in production; **anything else means the live schema differs from the migration and the finding changes**. Read-only: `information_schema` only, no DDL |

**P1-INV-12's row is the one case where a CASCADE answer is the GOOD one.** The three refusals
shipped for D13 are an application control standing in front of a database constraint. If the live
`DELETE_RULE` is `CASCADE`, as `2023_12_26_092822_create_products_table.php:55` declares and as
MySQL 8.0.46 confirms for that migration, then the control is needed and is in the right place. If it
is anything else, the live schema has drifted from the migrations — which is GATE F's whole premise —
and the task needs re-reading rather than closing.

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

## 7a. Work completed under the final decisions (D1 … D18)

### P1-INV-12 · D13 — a category that still holds products is no longer deletable

**The decision.** D13: *"Do not cascade-delete products when a merchant deletes a category. Refuse
category deletion while associated products remain. Provide a safe reassignment option before
deletion. Verify actual MySQL foreign-key behavior and change constraints through an approved
migration."* That answered the one question the task was blocked on — cascade, `SET NULL`, or
refuse-while-non-empty — and it chose the third.

**The census is the part worth reading.** The ledger named one path. A sweep for every site that
deletes a `categories` row found **three**, and the one the ledger had never named is the one a
client actually calls:

| Path | How it fires the cascade | Guard placed |
|---|---|---|
| `CategoryController::destroy` | `$category->delete()` | after the existing `$hasChild` refusal, **above `Category::categoryImageDelete()`** |
| the BulkDelete addon route | `Category::whereIn('id', $request->ids)->delete()` (`BulkDeleteController:116`) — **never reaches the controller** | `app/Http/Middleware/BulkDeleteTenantGuard.php`, host-side |
| `DELETE api/admin/deletecategory` | `$Category->delete()` in `Api\DashboardController::DeleteCategory` — **the endpoint the shipped admin app calls**, previously unguarded | inside the `if (! empty($Category))` branch |

Three store-wide wipes were deliberately **excluded**: `Category::where('store_id', $store->id)->delete()`
at `Api\DashboardController` ~4194 and ~4297 and `StoreController:806`. Deleting or resetting a
whole store is meant to take its catalogue with it; D13 is about deleting *a category*.

**Why placement, not just presence, is the finding.** By the time `CategoryController::destroy`
reaches `$category->delete()` it has already unlinked the category icon and cover and looped every
product through `Product::productImageDelete()`, which unlinks each product's images, cover,
downloadable file and preview video **from disk**. A refusal above `$category->delete()` alone would
have been a refusal after the irreversible part. A removal check pins it: moving the guard below the
image deletes fails the two "nothing is destroyed" tests and nothing else.

**Three things each refusal does the same way, and each for a measured reason.**

1. **Raw `DB::table` count.** `Product` is `Cachable`; a stale Eloquent count reads zero against a
   populated category and waves the delete through. The cache can only make this guard *permissive*,
   never wrongly restrictive, so raw is the conservative read.
2. **No store predicate.** The foreign key does not read `store_id`, so a row carrying this
   `category_id` and another store's `store_id` is cascade-deleted just the same. Narrowing the
   guard to one store fails exactly the cross-store test.
3. **Not a permission check.** D13 is data integrity, so the **platform operator is refused too** —
   which in `BulkDeleteTenantGuard` makes it the one check in the file placed **above** the operator
   bypass. Moving it below leaves every other test in that file green, which is why that mutant exists.

**Compatibility held.** The API path keeps `ApiResponser::error()` — HTTP **200** with `status: 0`,
the same envelope the app already renders for *"Maincategory data not found."* No new status code,
and specifically **not a 401**: repo B's `lib/network/network.dart` forces a logout that clears
preferences on any 401. The bulk route answers **409** (the caller's permission is fine; the request
conflicts with the data), which the addon's own script renders from `response.message`.

**No information leak.** The bulk guard's tenant-side check is asked of the ownership-narrowed
`$owned` list rather than raw input, so a merchant ticking their own empty category alongside another
store's populated one gets their own delete — not a refusal that tells them something about store B.
Asking it of raw input fails exactly that test.

**`scalarIds()` does not validate, on purpose, and that is measured.** The vendored controller passes
the same `ids` list into `whereIn('id', …)->delete()`. MySQL coerces `'1abc'` to row 1 inside
`WHERE id IN (…)` while PHP's `FILTER_VALIDATE_INT` rejects it — so validating in PHP first would
count products for a *different* set than the delete removes. Both halves are now checks 4–6 of
`verify-category-product-cascade-on-mysql.php`.

**Three existing tests were re-pointed, not deleted.** Two positive controls and the foreign-product
test all deleted categories **with** products — they asserted the cascade as correct behaviour, which
is exactly what D13 forbids. Their liveness evidence moved to the integration-mapping deletes (the
part of the handler still meant to run), and the foreign-product test now measures the guard's
widened predicate instead of the loop's narrow one.

**The pre-existing product loop is now unreachable-by-design and kept on purpose**, documented in
place: it is the last line of defence for an irreversible disk operation if the guard is ever
narrowed, moved or removed, and it costs one query returning no rows.

**What remains, and it is not engineering.** D13's *"safe reassignment option"*: there is no bulk
re-category UI, so a merchant moves products one at a time from the product edit form. All three
refusal messages say so rather than leaving them at a dead end. And the FK constraint itself is
**unchanged** — D13 permits changing it only *"through an approved migration"*, and that is D15's
business and needs the real schema dump. The live `DELETE_RULE` check is §3's new row.

**Evidence.** 13 / 83 in `MerchantCategoryOwnershipTest`, 27 / 225 in `BulkDeleteAuthorizationTest`,
29 / 84 in `AdminApiTenancyTest`, 130 passed across the seven-suite blast radius, Unit 351,
Contract 26, and 6 checks / 0 failures on MySQL 8.0.46. **Seven mutants, all bite.** One of them
failed to apply on the first attempt and reported *29 passed on unmutated code* — discarded, then
re-run with the mutation proved present in the file first, which is the third time that failure mode
has appeared in this project.

### What the independent authorization review of the D13 change found

Five findings. **Three verified independently and acted on, two recorded as their own tasks, one
left alone deliberately.** The review is also the reason this entry says "three delete paths" rather
than four: it ran its own sweep and confirmed the set is complete.

**Acted on — A: the API path was missing the child guard the web twin has.**
`CategoryController::destroy` has refused a category with child categories since P1-INV-08
(`CategoryController.php:460-463`); `Api\DashboardController::DeleteCategory` had no such predicate
anywhere, so a merchant could delete a parent through the shipped app. **Orphaned, not cascaded**,
and the difference is why this is integrity rather than loss: `categories.parent_id` carries **no
foreign key** (`2023_12_25_093024_create_categories_table.php:21` is a plain `unsignedBigInteger`
defaulting to 0), so the children survive holding a `parent_id` that resolves to nothing — and
`buildCategoryTree()` walks by `parent_id`, so such a child drops out of the UI while remaining a
real row with real products under it. Fixed in the API path only, scoped to the category's own store
like the web twin. Not added to the bulk guard: that file's charter is tenancy, and the child
question is a separate decision. Pinned by
`AdminApiTenancyTest::test_the_api_refuses_to_delete_a_category_that_still_has_children`, whose
fixture parent holds **no products** so it fails if and only if the child predicate is missing.

**Acted on — (c): do NOT unify the middleware's `isOperator()` with the project-canonical one.**
I had asked whether `BulkDeleteTenantGuard::isOperator()` should delegate to
`App\Policies\UserPolicy::isOperator()`. **It must not, and the answer is a lockout.** Verified:
the middleware's helper is character-identical to `StoreScope::isSuperAdmin()`
(`app/Scopes/StoreScope.php:1231`), which is the function it has to agree with, because section D of
the guard defers to `StoreScope::resolveTenant()` and refuses any principal whose mode is not
`MODE_TENANT`. `UserPolicy` uses `rtrim($x, ' ')` to match MySQL's PAD SPACE collation, which keeps
a **leading** space — so `' super admin'` is an operator to `trim()` and not to `rtrim()`.
Delegating would *narrow* who counts as operator, which sounds like a tightening and is a lockout:
such a principal fails the check, falls into the tenant path, and gets **403** at
`mode !== MODE_TENANT`. The two helpers legitimately differ because they answer different questions
— one must agree with the scope that decides which rows a request can see, the other with the
collation that decides what the database considers equal. Written into the helper's docblock so
nobody "unifies" them later. (`OperatorCheckCensusTest`'s patterns do not match the normalised form,
before or after, so its ceiling and floor are untouched — checked.)

**Acted on — the raw-query inventories, which is what actually failed CI.** The first full gate run
came back **exit 1 with four undeclared failures**, all of them the raw-query ratchets: the three new
store-less `DB::table('products')` counts moved `app` 136 → 139, `products` from `[2, 0]` to
`[5, 3]`, and put `products` into the set of tenant-discriminated tables reached rawly without a
`store_id` predicate. That is the ratchet doing its job, and the remedy it normally asks for — add
the predicate — is **here the defect**: the cascading foreign key does not read `store_id`, so a
predicate makes each count read zero against a populated category and permits the cascade. So the
three sites are **declared** with their reason rather than changed, and
`OwnedPathRawQueryInventoryTest` gained a per-**statement** `STORE_LESS_BY_DESIGN` declaration,
keyed by a fragment of the statement rather than by line number.

That mechanism is new, so it carries its own two-directional ratchet and its own removal check:

- a declaration whose fragment matches nothing **fails** (so it cannot outlive its statement);
- a *new, undeclared* store-less raw query in the same file **still fails** with the original
  message (so it is not a blanket exemption for the file).

Both measured. Nothing was added to `.github/known-test-outcomes.json`.

**Recorded as tasks, not fixed here:** `P1-INV-24` (a **third** table cascades off `categories` —
`product_reviews.category_id`, a stale denormalised copy, so following D13's own "move the products
first" advice destroys reviews silently) and `P1-INV-25` (the store-wide wipes delete categories
before their product loop, so the cascade empties `products` and the media unlink never runs —
uploads left on disk, storage quota left charged; plus the latent cross-store limb of the same two
call sites).

**Left alone deliberately:** the store-less count can block a delete over a row the merchant cannot
see, and its `:count` discloses that one integer. Widening is the right direction for a refusal, the
`Log::warning` gives support a path, and both need the same latent anomaly as `P1-INV-25`'s second
limb. Recorded, not changed.

**Two strengthenings taken from the review:** `assertVictimIntact` now asserts the `products` row
itself (raw, since `Product` is `Cachable`) rather than inferring it from the category row, so a
failure reads "the cascade fired"; and `CATEGORY_TYPE` is pinned to `App\Models\Category::class`, so
a rename fails saying "D13 is unhooked" instead of only failing the whole-map comparison.

### P1-INV-03 · POS stock integrity — promoted from BACKLOG and closed

**Promoted because its stated blocker had lapsed.** The row read: *"depends on P1-INV-01: both
change ProductController, and the directive forbids two agents editing the same working file."*
P1-INV-01 is DONE (#27, `f522feaf`). The directive names POS stock integrity among the BACKLOG
items to promote.

**Five defects across three handlers**, each independent and each with its own mutant. Two in
`PosController::store()`: no store predicate (`Product` is not in `scoped_models`, so a till moved
store B's stock 50 → 49) and an **absolute write** rather than a decrement. Three in
`ProductController`: `addToCart` unscoped, `updateCart` unscoped, and a stock bound that **could
never fire**.

**The absolute write is the one that loses money quietly.** `originalquantity` is captured into the
session when a line is added and never re-read, so the write overwrote current stock from a
snapshot: stock 50 at add-time, other sales take it to 30, the till sells 1 — correct is 29, it
stored **49**, inventing twenty units already sold. No error. Two tills clobber each other the same
way.

**The dead bound is one character of precedence.** `isset($cart[$id]["originalquantity"]) <
$cart[$id]['quantity']` — `isset()` yields a bool, PHP 8 casts the int side to bool, so it is
`true < true` for every positive quantity. Confirmed by execution: shipped `false`, intended `true`.
That mattered *more* after the decrement fix, because the posted quantity is now what leaves the
shelf — 999 against a stock of 5 takes recorded stock to −994.

**The decrement reuses `ProductStockService`** rather than adding a second stock engine, with
`allowOversell: true` because a till means the goods are already leaving the shop.

**Verified on the real engine, not from a docblock.** I had described the decrement as "atomic in
the database" on the strength of the service's own documentation; that is now measured.
`verify-stock-oversell-concurrency-on-mysql.php` — **12 checks, 0 failures on MySQL 8.0.46**:
the second transaction blocks on the row lock (error 1205), its predicate is re-evaluated against
the committed value, ten callers against five units land exactly at zero, **the read-modify-write it
replaced does oversell here**, and a backorder product still reaches −1 so that behaviour was not
silently removed.

---

### P1-PAY-02 · payment idempotency — promoted from BACKLOG and closed

`767a05db`. **One checkout and one payment now produce at most one order.** Each of the seven
storefront return handlers already made its stored checkout state single-use with
`Session::forget()`, which stops a SEQUENTIAL replay — and
`GatewayReturnTrustBoundaryTest::test_replaying_a_return_cannot_multiply_orders` already pinned
that, while saying in its own docblock what it did not pin: *"It does NOT claim the handlers have
an idempotency key. They do not."*

**The session cannot stop two returns that OVERLAP.** Laravel holds no session lock for the life of
these requests, so both readers hold a valid copy of the state and both proceed — the identical
read-check-use straddle already fixed on the Paymera path, where it was measured to produce a
second order, a second stock decrement and a second wallet credit.

`PaymentService::process()` (one caller, `PaymentController:1778`) now stamps a `checkout_nonce`,
which all 46 `Session::put('request_data', ...)` sites inherit through their own `except()`. On the
way back, `claimGatewayReturn()` takes one or two keys with `Cache::add()` — the nonce for "one
checkout, one order", and the gateway's own transaction reference for "one PAYMENT, one order",
which the nonce cannot answer. **The payment key covers khalti and cinetpay, the only two whose
return carries a reference their own code reads**; the other four read none, which is the
unverified-payment blocker's territory, not this task's, and is recorded as a residual rather than
papered over.

Three regressions in the fix, found by the authorization review — see section 8.

### P1-PLT-05 · security and financial observability — promoted from BACKLOG and closed

`5909c12b`. **92 security log lines (11 `critical`, 81 `warning`) enumerated in
`docs/security/P01-OBSERVABILITY-INVENTORY.md`, with a two-directional ratchet.** Phase 01 added
every one of them with its own justification; nobody had asked where they LAND.

**One file, unrotated, mixed with everything else, and nothing alerts.** `LOG_CHANNEL=stack` →
`LOG_STACK=single` → `storage_path('logs/laravel.log')` at `LOG_LEVEL=debug`. The rotating `daily`
driver exists and nothing selects it. The expectation is RECORDED and `config/logging.php` is
deliberately NOT changed: logging configuration is deployment configuration.

**And the larabug review produced a finding larger than the blacklist.** The blacklist is in good
order (P1-SEC-14 extended it) and is now pinned. But `LaraBugHandler::write()` discards every
record whose context carries no exception, so **enabling larabug would deliver none of the 92**.
Measured by driving the handler with a real record and asking the fake what it received, with a
positive control — not by reading the vendor source.

### P1-PLT-03 · tenant-scope adoption — promoted from BACKLOG and closed

Two documents and two tests. **The row's title is a complaint that turned out to be wrong**, which
is a better outcome than sprinkling hatch calls to make a count go up: the operator already
resolves `MODE_SUPER_ADMIN` with no predicate, a multi-store merchant already crosses through
`admissibleStoreIds()`, and the storefront, both non-web guards, jobs and commands resolve no
tenant with `null_tenant => 'open'`. **There is nothing to escape from.** So criterion 1's
deliverable is a PROPERTY — `TenantScopeHatchAdoptionTest` fails on the first out-of-request query
on an enforced model — rather than a call site.

Criterion 2 was **already satisfied** by `TenantModelClassificationTest`, which is a better
artifact than a document and was built to avoid the second drifting copy a new document would
create. **The audit closed its one hole:** `DeliveryBoy` was named as an unscoped trait-carrier and
explained nowhere. Measured — both writers set `store_id`, driver login is on a guard the scope
cannot see, and 22 of 26 read sites have no filter — so it is an unclassified *enforcement
candidate*, now recorded with a new exhaustiveness assertion and raised as **P1-PLT-08 (#119)**.
Enforcing it changes an authentication provider's visibility and needs its own full-suite evidence.

Criterion 3 is **measured, not argued**: `TENANCY_NULL_TENANT=closed` over the two isolation suites
gives **202 failures of 4061** (19 Tenancy, 183 Security, against 0 under `open`) — and the shape
matters more than the count. Every failing name is authenticated `customers`-guard traffic or a
guest cart, not anonymous browsing, which points straight at the narrow fix the proposal
recommends: teach `resolveTenant()` to consult the `customers` and `deliveryboy` guards, where the
principal is known and carries a real `store_id`.

## 8. Verification state

Everything below was executed. Where a number differs from an earlier claim of mine, the earlier
claim was wrong and the correction is noted.

| Gate | Result |
|---|---|
| Unit | 351 passed |
| Contract | 26 passed |
| **Feature + known-outcome gate** | **5319 passed, 2 declared failures, 17 declared skips; gate exit 0; manifest UNMODIFIED** — on the delivered tree `c53cb55b`, with the tracked-content SHA-256 **identical before and after** the run. A commit landed mid-run during the earlier attempt, so `git status` could no longer serve as the contamination proof and the content hash did instead. |
| Security | 3545 passed, 4 skipped |
| Tenancy + Commerce | 843 passed, 13 skipped |
| Guards + contrast | 4 guards, exit 0 |
| **Secret scan** | **exit 0 over 351 changed files, with the PR's real base ref** |
| **MySQL (8.0.46)** | **runner green; + 18 new checks for store-slug identity** |
| Mutation | **73 mutants, 73 bite** — 45 earlier (24 + 10 for P1-INV-12/D13 + 6 for P1-ORD-11/D3 + 5 for P1-INV-03) + **18 for P1-PAY-02** + **5 for P1-PLT-05** + **6 for P1-PLT-03**. Two SURVIVORS were resolved rather than excused: an array limb in `stampCheckoutNonce()` was DEAD CODE and was deleted (a census showed `process()` has one caller passing a typed `Request`), and the store-scoping of the payment claim key had no test, so a test was written to stage the property it hardens. A third mutant **failed to apply semantically** — `[] ?: [...]` returns its second operand, so the list it was meant to empty was untouched; re-pointed and re-run, because a mutant that cannot be applied proves nothing. Fourth occurrence of that failure mode in this project. |
| **MySQL 8.0.46, this session** | cascade verifier **6 checks / 0 failures**; stock-oversell concurrency **14 checks / 0 failures** (section F added — the read-modify-write restock LOSES one of two concurrent returns, the atomic increment keeps both); **gateway-return claim 12 checks / 0 failures** (new: B blocks on the row lock, error 1205, rather than deciding on a stale read). Each carries its own removal check. |
| **A MySQL script that LIED TO ME, and was corrected** | the claim verifier's section C plants a row by hand to hold an uncommitted insert. The first version planted the RAW key while `Cache::add()` writes `cache.prefix . $key`, so the two connections contended for two DIFFERENT rows — B's insert succeeded and the two checks after it then passed for the wrong reason. **One failing check is what exposed it.** |
| **CI** | green on every head pushed this session: `c53cb55b`, `92244cfc`, `1d5d4eed`, `91ea82a4`, `f91e4b8d`, `eaa53d97`, `dbfc2ea7`, `98c53b8a`, `c25b2745`, `767a05db`, `5909c12b` |
| **An authorization review that found three live regressions IN THE FIX** | the `tenant-authz-reviewer` on P1-PAY-02. All three were one defect: a claim is CONSUMED, so taking it on a path that takes no money kills a live checkout. A declined NMI card became unretryable; one cross-site GET could permanently kill an in-flight NMI checkout (`Route::any`, CSRF does not apply to reads, and the card-expiry check returns before the forget); and a payment reported PENDING by PhonePe/toyyibpay was refused as a replay when it completed. **All three confirmed by reading the code before acting**, all three fixed, each with its own test and mutant. The review also found WHY the suite could not see the first one: an existing test asserting exactly the broken retry PASSED, because its fixture predates the nonce. |
| **A gate that FAILED and was not worked around** | the first full run on `1b386890` returned **exit 1, four undeclared failures** — the raw-query inventories catching three new store-less counts. Declared with reasons, with a new two-directional declaration ratchet; **nothing added to `.github/known-test-outcomes.json`** |

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

---
---

# CLOSURE — 2026-10-24. The release-prerequisite register.

**This section is the authority for everything above it.** The earlier sections were written at
`fb556cd6` and are deliberately left as they were, per this file's own convention, so the
before/after stays legible — but where they disagree with this one, **this one is right**. Two
claims above are now struck through or superseded by name; the counts table is simply stale.

## 1. Counts, live

| | at `fb556cd6` | **now** |
|---|---|---|
| DONE | 48 | **80** |
| READY | 17 | **2** |
| BACKLOG | 9 | **5** |
| BLOCKED | 31 | **22** |
| **total** | 105 | **109** |

Four rows were added during the phase. `ledger-tool.py counts` is the authority; this is a view.

## 2. What is left, by WHO can move it — not by status

The status field says where a row is. It does not say who is holding it, and that is the question
a closure needs. Every one of the 22 BLOCKED and 2 READY rows falls into exactly one of these.

### A — ONE owner decision, and it gates the GATE F release (1 row)

| | Row | The choice |
|---|---|---|
| **#26** | `P1-PAY-04` | D-06 settled COD timing; **D1 then rejected the trigger the shipped machine uses** — a merchant marking their own order delivered. Nothing is violated today because the wallet credit is dead (no GATE F tables). **(a)** withdraw the trigger now, changing every merchant's revenue reporting overnight, or **(b)** accept it in writing as an interim until a recorded collection event exists. `CodStateMachineTest::test_gate_f_cannot_be_released_while_marked_delivered_is_the_settlement_trigger` now goes red if GATE F is promoted before this is answered. |

**That is the only open owner decision in Phase 01.** The former candidate — P1-SEC-32(d) — was
answered by D4 and the row above claiming otherwise is struck through.

### B — ENVIRONMENT. Decided; needs a read-only observation or a server (11 rows)

Each already has its exact command prepared. None is an open architecture question.

| Row | | The prerequisite, exactly |
|---|---|---|
| `P1-PLT-02` | #34 | **Engineering is COMPLETE** — the fix (`8909272f`) and the reporting script both exist, and the script was executed here (`php tests/Support/Security/bin/report-null-store-orders.php`, exit 0, prints zeros on sqlite). Run it against production and record the count. |
| `P1-OWN-01` | #42 | Three read-only server observations, listed in `P1-OWN-01-BACKUPRESTORE-CONTAINMENT.md` §5. None enables the module or reads a backup. D17 keeps it disabled meanwhile. |
| `P1-OWN-03` | #44 | The running server. Past malicious use is not determinable from the repository. |
| `P1-OWN-06` | #47 | Branch protection on `main` — a repository owner setting, not scriptable from a session. |
| `P1-OWN-07` | #56 | Read-only production inventory of the pre-fix WooCommerce transfer residue. |
| `P1-OWN-08` | #86 | D6 gives the direction; execution needs the restored backup. Seven sub-items, including whether the card-bearing rows start a PCI process. |
| `P1-OWN-09` | #88 | D7 gives the direction; the only evidence of exploitation is the web-server access log, and the read-only queries are listed on the row. |
| `P1-OWN-11` | #106 | Three read-only SQL statements, verbatim on the row. Counts and store ids suffice; no values need leave the server. |
| `P1-SEC-26` | #99 | `SELECT store_id, created_by, value FROM settings WHERE name = 'storage_setting'`. Guessing it either breaks every image-existence check or leaves the defect. |
| `P1-OWN-04` | #45 | D8 decided the design. Needs a **client release window** — the shipped Flutter app force-logs-out on any 401, so expiry cannot land without a staged rollout. |
| `P1-OWN-05` | #46 | (a) closed. (b) `pint` and (c) Playwright-in-CI are owner choices with no security content. |

### C — D15. Decided; the migration may not run until the ten prerequisites are met (5 rows)

`P1-MIG-01` #38 · `P1-MIG-02` #39 · `P1-MIG-03` #40 · `P1-MIG-04` #41 · `P1-SEC-32` #111 (c) and (d)

All five have reviewed drafts or a named mechanism. **Do not promote a guessed schema**: a
`hasTable`/`hasColumn` guard makes a premature definition permanent and invisible. D12 answers
`P1-MIG-04`'s open sub-question — the retired Chatwoot tables are **retained**, not dropped.

**One ordering constraint crosses B and C and must not be lost:** `products.cost_price` must be
declared **before** `store_order_financials`, or every P&L row is all-margin; and the two
`delivery_boys` commission columns must land **with or before** `delivery_earnings`. Both are in
`_gate_f_pending/README.md`.

### D — VENDOR. Reported, not sent (4 rows, all DONE)

`P1-SEC-07` #69 · `P1-INV-20` #67 · `P1-INV-21` #68 · `P1-INV-22` #70. Two version-pinned upstream
reports exist in `docs/upstream/`. **Both are prepared and NOT sent** — sending is an outward
disclosure and the owner's call. Each says so on its first line.

### E — ENGINEERING, unblocked, not done (2 READY + named residuals)

| Row | | What is actually left |
|---|---|---|
| `P1-ORD-13` | #82 | Carry the order's primary key through the ten gateway return paths, as the WhatsApp/Telegram path already does via `order_complete_ids`. No owner input, no credentials. |
| `P1-SEC-14` | #89 | Outbound reporting is disabled and the blacklist hardened; what is unmet is criterion 3 — *the answer about production keys must be **recorded***, and it is not. The owner's reply restated the question rather than answering it. |
| `P1-PAY-03` | #25 | Khalti's verified amount is still the **client's** posted `payload['amount']`; binding it to the server's own figure is a local comparison. AuthorizeNet and Khalti both verify and record nothing, so both now write Pending — a commercial regression. Khalti's writer refuses on a failed curl instead of writing a Pending order, leaving a charged customer with no row. |
| `P1-PLT-04` | #36 | One read-only count bounds the storefront entitlement change; the query is in `ModuleEntitlementSourcesTest`'s docblock. |

## 3. Two corrections this closure makes to its own earlier sections

1. **P1-SEC-32(d) was never an open owner decision** by the time it was written down as "the single
   smallest outstanding one". D4 had already answered it. Struck through above and corrected on
   #111.
2. **"The remaining COD engineering sits behind blocker 1, so it is not separately schedulable"** is
   too strong. The marked-Delivered trigger is a live contradiction with D1 that needs no new table
   to *decide*, only to *replace*. It is now section A.

## 4. What Phase 02 inherits, and what it must not assume

**Inherits, working and tested:** the tenancy and authorization guard family in
`app/Http/Middleware/` (ten guards, each with removal checks); the payment-verification seam, whose
default is Pending for any gateway with no record; the known-outcome gate; the observability
inventory ratchet; the GATE F containment, now including the ordering ratchet in §A.

**Must not assume:**

- **That a green suite means a correct control.** Twice this phase a guard passed its own tests and
  an independent read found a live bypass — the cart guard's JSON-boolean hole and PaiementPro's
  caller-supplied "verification". Both were in code written the same day, by the same process that
  wrote the passing tests.
- **That a source-grep ratchet measures what it says.** Prose can satisfy one *and* fail one; both
  happened here. Strip comments.
- **That `$casts` is the encrypted-column inventory.** Three of the four encrypted-at-rest columns
  carry no cast and live in tables with no migration.
- **That the financial subsystem is empty or inert.** This register said it was **dead** — tables
  absent, service throwing, Observer swallowing. **The 2026-10-24 production restore refuted that.**
  All 15 tables exist and hold live data (80 wallet-ledger rows, 92 per-order P&L rows, 40 currency
  rates); the service runs. What survives is that **no migration creates any of it**, so the schema
  is unmanaged rather than absent, and GATE F puts it under version control rather than switching it
  on. Phase 02 must assume the money tables hold **real balances** — and that **one of them does not
  reconcile** (`docs/GATE-F-VERIFIED-SCHEMA.md`).

**Phase 02 does not start until Phase 01 acceptance.** Nothing in this section is an invitation to.


---

# CLOSURE — 2026-10-24, part 2. Section A is empty, and three rows above are wrong.

**This section supersedes the one above it**, by the same convention that one claims over the
sections before it. The part-1 register was written at `673daff4`, before the owner answered #26
and before the last engineering batch; where the two disagree, this one is right.

## 1. The owner decision that section A was waiting for is answered and implemented

> **Owner, 2026-10-24, final:** "Withdraw the Delivered-based COD payment/settlement trigger now.
> Delivered must never, by itself, mark COD as Paid or credit a merchant wallet. Require a separate
> verified cash collection event, recording the collector (merchant, courier or delivery company),
> collection amount, time and supporting evidence. Keep order delivery reporting separate from cash
> collection and financial settlement reporting. Preserve historical records and avoid destructive
> migration. Implement the safe state transitions and required tests now; any schema-dependent
> accounting functionality remains gated on GATE F."

Option **(a)**. Implemented in `47719ce1` and verified on that tree (Feature 5703 passed, 2 declared
failures, 17 declared skips, 0 errors; known-outcome gate OK both directions; CI run 199 green).
`P1-PAY-04` is DONE and #26 is closed.

**Section A above is therefore empty. There is no open owner decision in Phase 01.**

One thing in that implementation is worth carrying forward on its own: the withdrawal found a
**second settlement path nothing had recorded** — `Order::order_status_change()`'s delivered branch
fired `event(new OrderPaymentStatus($order))` while changing no payment status at all. It credited
nothing, because `EventServiceProvider` registers `OrderPaymentStatus::class => []` with no
listeners, and that is precisely why it was removed rather than left. A dead coupling that goes
live the moment somebody adds a listener has the same shape as the GATE F financial subsystem.

## 2. Three rows in section E above are wrong, and the corrections are not cosmetic

### `P1-ORD-13` (#82) — section E restates the ORIGINAL scope as if nothing had been done

Section E says "carry the order's primary key through the ten gateway return paths… no owner input,
no credentials". **Criteria 1–3 have been closed since 2026-10-23.** `app/Services/OrderCompleteBinding`
exists, ten producers call `remember($order->id)`, and `orderComplete()` resolves by primary key
with an explicit store predicate in every limb.

What is actually open is **criterion 4** — *every gateway return handler driven end to end* — which
needs a provider sandbox per gateway and reaches no gateway from this container. **That is a
section B row (environment), not a section E row (engineering).** Listing it under "unblocked
engineering" invited somebody to redo work that is already on the branch.

One exception to "no gateway can be driven" now exists and is recorded under `P1-PAY-03` below.

### `P1-SEC-14` (#89) criterion 3 — it needed a command, not a reply

Section E says the answer "must be recorded, and it is not. The owner's reply restated the question
rather than answering it." Waiting for a different reply was the wrong move: `.env` is not in this
repository, so no reply from here could ever answer it. **It is now one read-only command, and its
output is the record:**

```
php tests/Support/Security/bin/report-larabug-configuration.php
```

It prints presence and length, never a key value, so the output is pasteable onto a public issue.
It reports both gates — `isConfigured()` and `mayReportOutbound()` — says what each answer means,
and carries the fact the owner needs when they decide: enabling larabug would deliver **none** of
the 127 inventoried security log lines, because its Monolog handler discards every record whose
context carries no `Throwable`. **Moved from section E to section B.**

### `P1-PLT-04` (#36) criterion 2 — the query is now a script, and writing it found a bug in itself

Section E says "the query is in `ModuleEntitlementSourcesTest`'s docblock". It is now
`tests/Support/Security/bin/report-storefront-entitlement-gap.php`, read-only, which adds the figure
the bare query misses: a merchant with **some** activation rows still loses every module their plan
grants that they have no row for, so the whole-merchant count is a floor rather than the answer.

Worth recording because it is this project's recurring failure in miniature: exercised against a
seeded throwaway database rather than only the empty test one, the script was **wrong** — a
truncated `plans.modules` column (`{`) fell through its CSV fallback and was reported as a module
named `{`, with the undecodable counter still reading zero. It invented a module and then claimed
its figures were complete. It now refuses to guess. Running it only against the empty development
database would have shown zeros and proved nothing. **Moved from section E to section B.**

## 3. `P1-PAY-03` (#25) criterion 2 is closed, and the regression was ours

Section E listed three residuals on this row. All three are closed in this batch.

**Khalti's verify call asked the gateway the caller's question.** `$amount = $payload['amount']` is
the caller's own field, and Khalti's `payment/verify/` endpoint confirms the token and the amount as
a **pair** — so a buyer who paid one unit could present that token with a matching one-unit amount,
collect a truthful 200, and have the order written at the server's full basket price. The
verification was real the whole time; it verified the wrong number. Both call sites now pass the
server's figure, and the plan-purchase one was also moved **below** the coupon block, where
`$price` is the price actually being charged rather than the list price — an ordering bug nothing
had recorded.

**The Pending regression on Khalti and AuthorizeNet was introduced by this project**, not inherited.
Both obtained a real provider result and recorded no verification, so once the no-record rule landed
in `PaymentVerificationService` every order from both was written Pending. Nobody chose that; it
fell out of the default inversion. Both record now.

**A Khalti payment that moved money while the verify call failed no longer leaves a charged customer
with no row.** An unreachable gateway is `UNVERIFIABLE`, not `REJECTED`: the order is written
Pending for reconciliation and the shopper is still told the payment failed. A gateway that was
reached and said no still writes nothing.

**And one thing that matters beyond this row:** `App\Khalti\Khalti` is now resolved from the
container instead of constructed with `new`, so a test double can drive the handler past the curl
call. `GatewayReturnTrustBoundaryTest`'s docblock records that "khalti stops at verifyPayment()" —
it does not have to any more. That is the one "cannot be driven → drivable" move
`docs/security/P01-ORD-13-GATEWAY-TEST-REQUIREMENTS.md` §321 names Khalti for, and it is the pattern
the other nine handlers would need.

**What is still NOT met on #25, stated so the close is not read as complete:** criterion 1 as
literally written. Three of 52 payment types are provider-verified (paymera, khalti, nmi). The rest
resolve to Pending — which closes the falsely-Paid defect but is not "verified against the
provider", and getting there needs each provider's status endpoint, field names and an
authenticated credential. Owner plus credentials, one gateway at a time.

## 4. Counts, live

| | at `fb556cd6` | part 1 (`673daff4`) | **now** |
|---|---|---|---|
| DONE | 48 | 80 | **81** |
| READY | 17 | 2 | **2** |
| BACKLOG | 9 | 5 | **5** |
| BLOCKED | 31 | 22 | **21** |
| **total** | 105 | 109 | **109** |

`ledger-tool.py counts` is the authority; this is a view. The `owner-decision 26` that tool also
prints is **not** a count of open decisions — it counts rows whose *Owner decision required* field
reads yes, including the many whose decision has since been made. Open owner decisions: **none**.

## 5. What is left, restated — every row, by who holds it

- **A — owner decisions: none.**
- **B — environment or a server: 13 rows.** The 11 from part 1, plus `P1-ORD-13` criterion 4
  (gateway sandboxes) and `P1-SEC-14` criterion 3 (one command, above). `P1-PLT-04` criterion 2
  joins them as a prepared script.
- **C — D15-gated migrations: 5 rows.** Unchanged. The two ordering constraints still hold.
- **D — vendor reports: 4 rows, all DONE here, both reports prepared and NOT sent.** Sending is an
  outward disclosure and the owner's call.
- **E — engineering, unblocked, not done: none.** Section E is empty. Everything that was in it is
  either done (`P1-PAY-03` criterion 2) or correctly reclassified to B.

## 6. One finding for Phase 02 that is larger than any row above

**The strongest tenancy ratchet in this project is silent for 28 of 190 raw query sites.**
`RawQueryTenancyInventoryTest`'s per-table exposure map — the one its own docblock calls "the
ratchet that matters", because it fails when a table's unscoped count goes **up** — keys on
`DB::table('literal')`. A site that builds its table name from a variable gains no row, so a later
change dropping its `store_id` predicate trips nothing.

This was found the way such things are found: a new service written as `DB::table(self::TABLE)`
moved the per-directory count (which alarmed, correctly) while adding **no row at all** to the map.
The class docblock already conceded the 28, so the blind spot was documented and its consequence
was not. It is recorded here rather than fixed, because widening a required CI guard is a Phase 02
change, not a closure one.

## 7. A RELEASE PREREQUISITE THAT LIVES NOWHERE ELSE: the PR attestation gap

Recorded 2026-10-24 at head `2efbc1dd`, because it is a release prerequisite and the only place
it is currently stated is one sentence at the bottom of a 60KB pull-request description.

PR #3's description carries the `SECURITY-DIFF-REVIEW` attestation in numbered sections, §8–§20,
each covering a **named commit range** and — this is the convention that makes it trustworthy —
**none widened to cover commits it did not examine**. §17 is the model: it attests 3 of its 28
commits and names the 25 it does not.

Its closing sentence reads:

> **Any commit pushed after `bfb921d2` is outside this attestation until a section covers it.**

**There are 77 such commits** (`git rev-list --count bfb921d2..2efbc1dd`). No section covers any of
them. The gap is therefore *self-documented* rather than hidden — the convention is working as
designed — but it is unclosed, and the checklist in `.github/SECURITY-DIFF-REVIEW.md` is a
**required** CI check that passes on the file's presence, not on its coverage.

**What blocks closing it, stated precisely rather than as an estimate.** Of the 77, **8 were
authored in this session** (`2f68495d`, `09191cda`, `0afac2cf`, `d082214a`, `0d8a48b1`, `673daff4`,
`47719ce1`, `2efbc1dd`) and each carries its own evidence, gates and mutant results in its own
commit message, with the two that needed it reviewed independently. **The other 69 are from earlier
sessions and were not examined here.** Writing a section that covered them would be the one thing
the convention forbids, so it is not done.

**This blocks nothing today.** PR #3 is a Draft titled ⛔ DO NOT MERGE, final integration into
`main` is not authorised, and at `2efbc1dd` all 9 check runs are `success` with `mergeable_state`
`clean`. It blocks **integration**, which is the owner's gate anyway.

**The three ways to close it, for the owner to pick — not an engineering choice:**

1. **A §21 covering the 8 commits of this session only**, naming the other 69 as uncovered, exactly
   as §17 did. Cheap, honest, and leaves the larger gap explicit.
2. **A per-range pass over all 77.** This is the only option that actually closes the contract, and
   it is a real piece of work: 69 commits nobody in this session read, across tenancy,
   authorization, payments and migrations.
3. **Accept the gap in writing** and record that integration rests on CI plus the per-commit
   evidence rather than on the checklist. Legitimate, and should then be said in the PR description
   so the closing sentence stops promising a section that will not come.

One caution against rewriting that description casually, from its own text: on 2026-10-21 a rewrite
was **cut off mid-text by a safety classifier and replaced the whole description with a ~6,000
character prefix**, and the write was not retried. The full prior text survives only because it was
committed byte-identically to `PR3-DESCRIPTION-ARCHIVE.md`. Any option above that edits the
description should archive first.

## 8. A TEST-DESIGN RULE this phase learned the expensive way (2026-10-24)

**A sentinel asserted ABSENT from a haystack must be long enough that it cannot occur by chance.**

`StorefrontVisitLogTest` asserted that a CVV sentinel appears nowhere in `shetabit_visits`. Its
oracle is `serialize()` of **every column of every row**, which includes the `url` column — and the
fixture's store slug is `store-<id>-<uniqid() hex>`. The sentinel was `'737'`. Three characters.

It fired in the merge-base regression gate on `22731010`, **a commit whose entire diff was one
markdown file**, and the failure message carried its own refutation:

```
s:7:"request";s:2:"[]";                         <- the control worked; nothing was stored
s:3:"url";s:44:"http://localhost/store-1-6adcb973799b1/login"
                                      6adcb9[737]99b1
```

**Rate, computed not estimated:** 11 three-character windows in a 13-character hex suffix, so
p ≈ 11/16³ ≈ **0.27% per run** — which is why it survived roughly 200 CI runs before firing, and why
85 local runs of the test reproduced nothing. A failure this rare reads as a flake and gets re-run,
which is exactly how it would have kept its secret.

**What makes this worth a rule rather than a footnote.** The three other sentinels in that same file
are long and self-labelling (`PW-FIXTURE-SENTINEL-4471`, `COOKIE-SENTINEL-9902`,
`RESETTOKEN-SENTINEL-5538`). One short one was enough. And the cost is not just a red build: with
`'737'`, **a real CVV leak and a slug collision are indistinguishable** — the assertion could not
tell you which it had found. Lengthening it removes a false alarm and *improves* attribution, so it
is not weakening a guard; a chance match makes such a test fail, never pass.

**For Phase 02:** when adding an absent-from-haystack assertion, make the needle distinctive, and
check what else is in the haystack — ids, timestamps, slugs, uniqid hex, serialized string lengths
(`serialize()` emits `s:737:` for a 737-byte string) are all needle-shaped. A scan at the time of
this fix found no other short numeric sentinel in `tests/Feature/Security/`.

---

# FINAL — Phase 01 engineering closure, 2026-10-24

**This section is terminal.** It is the authority for Phase 01's closing state, and Phase 01 does
not expand past it: no new audit campaign, no further owner questionnaire. Where any earlier
section of this file disagrees, this one is right.

## 1. The owner's closing direction, and what each item resolved to

| | direction | resolution |
|---|---|---|
| 1 | Accept engineering completion, subject to the recorded acceptance limitations. No restarted discovery, no repeated tests. | Accepted. The limitations are listed in §3 below and are **not** restated from their source documents. No test in this range was re-run to produce this section. |
| 2 | Keep the two upstream vendor reports prepared but unsent. | Unsent. Both remain in `docs/upstream/`, each saying so on its first line. **No outward disclosure was made.** |
| 3 | Focused attestation-gap review of the previously uncovered commits; reuse existing evidence; do not rerun all 77 individually. | Done: `PR3-ATTESTATION-S21.md`. Nothing was re-run. Count corrected to **79** (77 was right at `2efbc1dd`; two commits landed after). |
| 4 | Record the 13 environment checks and 5 D15-gated migrations as outstanding **release prerequisites, not completed tests**. | Done, §2 below, labelled as such. |
| 5 | Finalize the Phase 01 closure and Phase 02 handoff using the existing authoritative documents. | This section plus the dated closure section of `docs/development/HANDOFF-CURRENT.md`. No new document tree. |
| 6 | Preserve all mandatory merge and release gates; do not merge `main` or deploy production while they are unmet. | Preserved and unmodified, §4 below. `main` not merged, nothing deployed. |

## 2. OUTSTANDING RELEASE PREREQUISITES — these are NOT test results

**Read this table as a list of things that have not happened.** Every row is an observation or a
migration that is **outstanding**. None is a passing test, a completed check or evidence of
anything. Where a row has a prepared script, the script's existence is engineering that is done;
**running it is the prerequisite, and it has not been run against production.**

### 2a. Environment / server — 13 rows

| row | issue | the prerequisite |
|---|---|---|
| `P1-PLT-02` | #34 | Run `report-null-store-orders.php` against production and record the count. |
| `P1-OWN-01` | #42 | Three read-only server observations (`P1-OWN-01-BACKUPRESTORE-CONTAINMENT.md` §5). D17 keeps the module disabled meanwhile. |
| `P1-OWN-03` | #44 | The running server. Past malicious use is not determinable from the repository. |
| `P1-OWN-06` | #47 | Branch protection on `main` — a repository owner setting. |
| `P1-OWN-07` | #56 | Read-only production inventory of the pre-fix WooCommerce transfer residue. |
| `P1-OWN-08` | #86 | Needs the restored backup. Seven sub-items, including whether card-bearing rows start a PCI process. |
| `P1-OWN-09` | #88 | The web-server access log is the only evidence of exploitation; queries are on the row. |
| `P1-OWN-11` | #106 | Three read-only SQL statements. Counts and store ids suffice. |
| `P1-SEC-26` | #99 | `SELECT store_id, created_by, value FROM settings WHERE name = 'storage_setting'`. |
| `P1-OWN-04` | #45 | A **client release window** — the shipped Flutter app force-logs-out on any 401. |
| `P1-OWN-05` | #46 | (b) `pint` and (c) Playwright-in-CI are owner choices with no security content. |
| `P1-ORD-13` | #82 | **Criterion 4 only** — a provider sandbox per gateway. Criteria 1–3 closed 2026-10-23. |
| `P1-SEC-14` | #89 | **Criterion 3 only** — run `report-larabug-configuration.php` where the app runs and paste the output. |

A fourteenth read-only observation of the same kind exists and is **not** counted in the owner's 13:
`P1-PLT-04` #36 criterion 2, with `report-storefront-entitlement-gap.php` prepared. Flagged rather
than renumbered, so this table and the owner's direction agree.

### 2b. D15-gated migrations — 5 rows

`P1-MIG-01` #38 · `P1-MIG-02` #39 · `P1-MIG-03` #40 · `P1-MIG-04` #41 · `P1-SEC-32` #111 (c) and (d)

All five hold reviewed drafts or a named mechanism, parked unrunnable under
`database/migrations/_gate_f_pending/`. **Do not promote a guessed schema**: a `hasTable` /
`hasColumn` guard makes a premature definition permanent and invisible. D12 answers `P1-MIG-04`'s
open sub-question — the retired Chatwoot tables are **retained**.

**Two ordering constraints that must not be lost between documents:** `products.cost_price` must be
declared **before** `store_order_financials`, or every P&L row is all-margin; and the two
`delivery_boys` commission columns must land **with or before** `delivery_earnings`, because
`is_commission_enabled` is what gates the earning write.

**CORRECTED 2026-10-24 — this warning was wrong, and is kept visible rather than deleted.** It
said the financial subsystem was *dead, not untested*: tables absent, `OrderFinancialService`
throwing, `OrderObserver` swallowing, so releasing GATE F would *switch it on*. The restored
production dump shows the opposite — all 15 tables present, 80 ledger rows, 92 P&L rows, the service
running. The claim was inferred from this repository's migrations, which genuinely do not create
these tables; the inference that therefore production lacks them does not hold for a schema partly
applied by hand.

**What makes GATE F dangerous is different, and worse.** The schema exists but is **unmanaged**, so
no environment but production has it; and the live data in it has integrity defects that activation
would propagate rather than create — a wallet whose balance cannot be reconstructed from its own
ledger, and 17 COD orders settled with no collection evidence (which cannot exist, because
`cod_collection_events` does not). Full account: `docs/GATE-F-VERIFIED-SCHEMA.md`.

## 3. The acceptance limitations this closure is subject to

Not restated — duplicating them is how they drift. Each lives in its authoritative document:

| limitation | authority |
|---|---|
| per-task PARTIAL rows and their exact unmet criteria | `12-PHASE-01-TASK-LEDGER.md` (`ledger-tool.py counts` is the authority for state) |
| open security findings | `docs/security/P01-SECURITY-WORK-QUEUE.md` |
| owner decisions D1–D18 and D-01…D-11 | `15-DEFERRED-OWNER-DECISIONS.md` |
| the attestation boundary for `bfb921d2..1bff9dc7` | `PR3-ATTESTATION-S21.md` §4 |
| what Phase 02 must not assume | §4, §6, §8 of this file |

**Final counts** (`ledger-tool.py counts`): **DONE 81 · READY 2 · BACKLOG 5 · BLOCKED 21 · total 109.**
No open owner decision. No unblocked engineering — section E of the part-2 register is empty.

## 4. Mandatory merge and release gates — preserved and unmet

Unmodified by this closure, and **nothing was weakened or added to a manifest to obtain green**:

- `Tests (REQUIRED — clean install from composer.lock)` and `Tests (REQUIRED — PayHere excluded)`
- `Merge-base regression gate (new failures only)`
- `assert-known-test-outcomes.php` against `.github/known-test-outcomes.json` — **2 declared
  failures and 17 declared skips**, asserted in both directions
- `guard-no-workdo-changes.sh` · `guard-gate-f-containment.sh` · `guard-no-new-root-uploads.sh` ·
  `guard-test-isolation.sh` · `guard-no-env-files.sh` · `scan-secrets.sh`
- `.github/SECURITY-DIFF-REVIEW.md`, now covered to `1bff9dc7` by `PR3-ATTESTATION-S21.md`

**At `1bff9dc7` all 9 check runs are `success` (run 40102841581, read back from the API).** That is
CI green on the branch. It is **not** release authorisation: the prerequisites in §2 are unmet, PR #3
is a **Draft titled ⛔ DO NOT MERGE**, and per the owner's direction **`main` is not to be merged and
production is not to be deployed while they remain unmet.**

## 5. Phase 02

Does not start until Phase 01 acceptance. The handoff is the dated closure section of
`docs/development/HANDOFF-CURRENT.md`, which points at the documents above rather than restating
them. Nothing in this section is an invitation to begin.
