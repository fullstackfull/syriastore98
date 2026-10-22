# Phase 01 — Final Decision Report

**Store Syria · Foundation & Stability · prepared 2026-10-22**

This report exists to be acted on, not filed. It says what Phase 01 has actually closed, what is
still technically executable without an owner decision, and what is waiting on a decision only the
owner can take. Every number in it was produced by running something in this session or by reading
the code; where a claim could not be executed here, it says so in those words.

---

## A. Executive summary

**Phase 01 is not ready for acceptance, and the reason is not unfinished engineering.** Of the 105
ledger tasks, **31 are blocked**, every one of them on something only the owner can supply — and 5 of
those 31 are the only thing standing between the platform and a releasable schema. The engineering queue is longer than the old counts
suggested, because the 25 `IN_REVIEW` tasks were audited rather than assumed: **15 came back READY,
not DONE**. After this session there are **17 READY tasks and 9 backlog tasks**, of which 4 backlog
items are mandatory before acceptance.

What this session changed:

| | before | after |
|---|---|---|
| DONE | 36 | **48** |
| IN_REVIEW | 25 | **0** |
| READY | 5 | **17** |
| BACKLOG | 9 | 9 |
| BLOCKED | 30 | **31** |
| *total* | *105* | *105* |

Three READY tasks were completed, verified and committed in this range: **P1-SEC-05** (the secret scanner could not
see a secret written in PHP array syntax), **P1-PAY-01** (fourteen null-dereference crash sites,
not the seven the row recorded) and **P1-INV-10** (the catalogue cluster). Each of the three found
that the recorded census was short, and each found it short the same way — keyed on a *spelling*
rather than on the write or the dereference. That is now the single most reliable prediction this
project makes about its own records, and it is reported here as a methodological finding, not an
apology: **a count in a task row is a lower bound.**

What the owner has to decide. Nine subjects, in the priority order the owner set:

1. **Customer data already written in plaintext** — `shetabit_visits` holds customer passwords,
   session cookies and possibly card fields (P1-OWN-08, #86, critical). The code hole is closed;
   the *rows* are still there. This is the one item in this report that is a live exposure rather
   than a latent defect, and it is called out here rather than buried.
2. **Payment-gateway secrets that were world-readable** (P1-OWN-09, #88, critical). Treat as
   exposed; the decision is rotation sequencing, not whether.
3. **Tenant isolation and host ownership** — who may claim a host (P1-SEC-32, #111), and the
   store-less setting reads (P1-SEC-26, #99).
4. **Payment verification and COD settlement** (P1-PAY-03 #25, P1-PAY-04 #26). COD settling at
   order time is what blocks releasing GATE F.
5. **Refund and return lifecycle** (P1-ORD-11 #75, P1-INV-16 #61).
6. **Financial records and retention** (P1-ORD-03 #22, P1-OWN-07 #56).
7. **GATE F and the schema** — 15 tables with no migration, 6 money-bearing (P1-MIG-01…04,
   #38–#41). Nothing in Phase 01 can be deployed until this is released.
8. **Auth tokens and production secrets** (P1-OWN-02 #43, P1-OWN-03 #44, P1-OWN-04 #45).
9. **Operational and CI controls** (P1-OWN-06 #47, P1-OWN-01 #42, P1-PLT-04 #36).

**The single most consequential thing in this report:** the acceptance criteria require two things
this environment cannot produce. Criterion **E3** (two concurrent checkouts of the last unit cannot
oversell) and criterion **F4** (schema-sensitive evidence verified on real MySQL) both need a MySQL
server. **This container has neither a `mysql` client nor `mysqld`** — verified by `command -v`.
Earlier sessions had MySQL 8.0.46. So those two criteria are marked **NOT VERIFIED** in section I,
and no amount of further work in this session can change that. They are not failures; they are
unmeasured, and they must be measured somewhere that has a database before Phase 01 is signed off.

---

## B. Actual task counts, reconciled against GitHub

Counted from `docs/development/phase-1/phase-01-tasks.json` and from
`list_issues(state=OPEN)` on `fullstackfull/syriastore98`, both in this session.

| status | ledger rows | open issues carrying that label |
|---|---|---|
| DONE | 48 (36 + 3 completed here + 9 reclassified by the audit) | 1 open (#110, by design — see below) |
| IN_REVIEW | **0** — all 25 reclassified in section I.2 | **0** |
| READY | 17 (P1-INV-15 #60, P1-ORD-13 #82, + 15 reclassified) | 17 |
| BACKLOG | 9 | 9 + **1 issue with no ledger row** |
| BLOCKED | 31 (30 + P1-PLT-02) | 31 |
| **total** | **105** | **71 open** |

**The label column is current, not aspirational.** All 28 `status:` labels were updated in this
session — the 25 reclassified tasks plus #19, #23 and #51 — so the GitHub view and the ledger agree
row for row. The issue bodies were **not** rewritten; the reasoning lives in the ledger's `evidence`
field and in section I.2, and only #51 and #66 received a comment.

105 ledger rows, 71 open issues, 34 closed. The reconciliation is clean except for two items, both
reported rather than silently corrected:

- **#66 `P1-TOOL-01` is an open issue with no ledger row.** "Skills and tooling standardization,
  DEFERRED behind the critical security queue." Its subject matter is *settled*:
  `docs/tooling/SKILLS-MANIFEST.md` records an owner decision on all twelve candidate tools (one
  adopted, the rest rejected or deferred) and `CLAUDE.md` says explicitly "do not re-ask". So the
  issue is open for work that has already been decided. **Recommendation: the owner closes #66 as
  completed**, pointing at the manifest. Closing it is a judgement about whether that decision is
  final, which is why this session commented on the issue rather than closing it.
- **#110 `P1-PLT-07` is DONE in the ledger and labelled `status:done`, and still open.** That is
  what the execution protocol prescribes (step 10 sets the *label*; it never says to close), so it
  is not an error. 34 other issues *are* closed, so the practice has been mixed. **Recommendation:
  pick one convention at acceptance** — the simplest is that DONE issues stay open until the phase
  is accepted, then close as a batch, which keeps the acceptance review in one place.

No ledger row lacks an issue. No open issue's `status:` label disagrees with its ledger row.

---

## C. Completed work in this session, with commits and test evidence

Three tasks, three commits, each with its own regression tests and its own mutation (removal)
checks. Every test named below was run in this session; the engine was SQLite `:memory:` with
`CACHE_STORE=array` (`phpunit.xml`) unless stated otherwise.

### C.1 P1-SEC-05 (#19) — the secret scanner could not see a secret written in PHP array syntax

Commit **`bfe8d213`**.

The defect was not that a rule was missing; it was that the `generic-assigned-secret` rule required
a *quote* immediately after the assignment operator, so `'api_key' => $x` matched and
`'api_key' => 'AKIA…'` inside a PHP array did not. **And the pattern existed in two places in
`.github/scripts/scan-secrets.sh`** — the rule itself and the narrowing gate that re-checks it. A
one-sided fix leaves the gate rejecting everything the widened rule finds, which is how this came
to need two edits in one file.

Widening it produced **51 false positives** in the test tree, which breaks the scanner's own
criterion that it exits 0 on the current tree. The alternative fixes were measured — 70 distinct
placeholder values, an unbounded adjective list, or 51 assertion-coupled renames — and all were
worse than a structural rule: the *generic* rule is not applied to `tests/*`, while every
high-confidence rule still is.

Evidence: `tests/Unit/Ci/SecretScannerCoverageTest.php`, **10 tests / 40 assertions**, which drive
the real script in throwaway git repositories rather than re-implementing its regex. Two of them
exist because the first version of this test was wrong: an isolated-regex test "caught" a planted
fixture that the real script correctly stripped as a placeholder (`AKIAIOSFODNN7EXAMPLE1` contains
`EXAMPLE`), and the shell-assembled ERE cannot be extracted with single-quote delimiters. Driving
the real script is what exposed both. 4/4 mutants bite.

### C.2 P1-PAY-01 (#23) — "the seven remaining crash sites" are fourteen

Commit **`9bb6f2ac`**.

`explode(',', $ProductStock->variation_option)` where `$ProductStock` can be `null`. Under this
application's error configuration (`error_reporting(-1)`, `E_WARNING` in the mask,
`HandleExceptions` converting to `ErrorException`) a null dereference **throws**; whether the user
sees a 500 or a 200 depends only on whether the enclosing handler catches it.

The row recorded seven sites. Keyed on the **dereference** rather than on the function name, the
census is **47 sites, 32 of them unguarded, 14 in scope** across six files (`PaymentService`,
`PaymentController`, `KhaltiPaymnetController`, `EasebuzzController`,
`PaiementProPaymentController`, `CinetPayController`). All 14 were made null-safe in place —
`explode(',', (string) ($ProductStock->variation_option ?? ''))` — with **identical line counts
before and after in all six files**, deliberately, so that concurrent auditors' line citations do
not shift.

Evidence: `tests/Feature/Commerce/VariationOptionNullSafetyTest.php`, **10 tests / 33 assertions**,
including a premise test that asserts `error_reporting() === -1` and that the bare dereference
really does throw, and a comment-stripped source ratchet narrowed to the two dereference shapes
(`$ProductStock` and `$variant_data`) so it does not fire on `PaymentController:258`, which is
correctly guarded two lines above by `if ($variant === null) { continue; }`. Commerce suite: 443
passed / 7 skipped. 4/4 mutants bite with all six files restored byte-identically.

### C.3 P1-INV-10 (#51) — the catalogue cluster: five items, seven defects

Commit **`7db6beda`**.

The row named five items. Each one turned out to be either larger than recorded or stale, and the
two new findings are the interesting half.

**(a) `Api\DashboardController::AddVariant` cannot create a variant and never could.** It wrote
`$ProductVariant->name` and `->type`; `product_variants` has **neither column** (create migration
`2024_01_01_104858`, no later `ALTER`, and no reader of either attribute anywhere in `app/` — the
real label column is `variant`, which `SalesDownloadableProductDataTable.php:40` reads). Direct
property assignment bypasses `$fillable`, so both landed in the `INSERT` and the database answered
`Unknown column 'name'` — a 500 for every caller, the store's own owner included.

It cannot be repaired in place: `product_id`, `variant`, `sku` and `price` are all NOT NULL with no
default, `product_id` carries a foreign key to `products`, and the validator asks for `name` and
`type` only. There is no product to attach the variant to, no sku and no price anywhere in the
request. Writing "columns that exist" would mean *inventing* a request contract and then securing a
new cross-tenant write path for it — a feature, not a repair. So the endpoint now **refuses** in
this file's own `error()` envelope (HTTP 200 + `status: 0`, deliberately not a 401, which repo-B's
`lib/network/network.dart` turns into a forced logout). **Nothing that worked stops working,
because nothing worked:** `grep` over `StoreSyria_app98/lib` for `addvariant`, `variantlist`,
`updatevariant` and `deletevariant` returns **0 hits** — the Flutter app creates variants through
the product endpoints' `variant_attribute`/`variant_id` fields.

**(a-new) `UpdateVariant` carried the same `->type` write**, behind `if (!empty($request->type))`.
So it worked when the caller sent `name` alone and threw the moment `type` was sent — the harder
failure to notice. The `type` write is dropped; `name → variant` is preserved, and that mapping is
what settles AddVariant's intent: the API's `name` parameter means `product_variants.variant`.

**(b) The dead route is one route; the class is sixty-nine.** `PUT products/sku_combination/edit`
pointed at `ProductController::sku_combination_edit`, which does not exist — a live route raising
`BadMethodCallException`. Removed (0 references in any Blade template or JavaScript). Keyed on
`$route->getActionName()` instead of the uri spelling, **69 host-app route actions point at methods
that do not exist.** Two were already recorded in the security queue: this one as S38-3b, and
`ProductController::addToCartVariant` as an S36 observation that was written down and never given a
task row — so it was still live. Both are now removed. **The other 67 all come from one generator:
a `Route::resource()` declaration for a controller that implements only some of the seven actions**,
which registers the rest anyway. They are held by a declared, both-directions ratchet (a new dead
route fails it; a route that quietly gets its method back fails it too) and reported as a finding
in section D rather than fixed inside this task.

**(c) The unscoped attribute-option lookup, and the 500 the fix uncovered.**
`ProductController::attribute_combination` read
`ProductAttributeOption::where('id', $id)->first()` with no store predicate, while its own sibling
`attribute_option()` (`:1841`) and `ProductAttributeOptionController::ownedOption()` both carry
one. `ProductAttributeOption` registers no `StoreScope` and is absent from `config/tenancy.php`'s
`scoped_models`, so nothing else narrowed it: a merchant who posted another tenant's option ids got
that tenant's `terms` rendered back. A read, and only of attribute term strings — recorded as what
it is.

The predicate carries an explicit empty-current-store guard, which is not redundant: Laravel turns
`where('store_id', null)` into `whereNull`, so without it a store-less session would match every
*store-less* option row — fail-open. `ownedOption()` already guards this for the same model.

**(c-new)** Adding the predicate exposed a **pre-existing 500**. An attribute that resolved no
options pushed an *empty group* into the cartesian product; `combination([[]])` returns `[]`, and
`attribute_combinations.blade.php:1` reads `count($combinations[0])` — `Undefined array key 0`, a
`ViewException`. It fired for **any** option id that did not resolve (a deleted option would do it)
long before this task; narrowing the lookup would have turned a cross-tenant leak into a
cross-tenant 500, which is not a clean refusal. Empty groups are no longer pushed, so a foreign id
is a 200 with an empty combination table — which is what "these options are not yours" should look
like.

**(d) The row's premise was stale, and the file's own docblock was half wrong.** The row said
`DeleteProduct_backup` had "no ownership guard"; it already had `resolveOwnedStore()`. What it did
*not* have was a tenant predicate on its **per-row** lookups — `ProductImage`, `Cart`, `Wishlist`
and the `Product` delete itself all keyed on a bare `$request->product_id`. Nor did
`ViewOrder_backup`, which passes `$request->order_id` into `Order::order_detail()` —
`Order::find($order_id)` with no predicate (`app/Models/Order.php:279-281`). So the controller
docblock's claim that all four `_backup` methods "were repaired anyway so they cannot be wired up
later and reintroduce the hole" was true of two and false of two. Those two are **deleted** (dead
code cannot be relied on to stay repaired); `OrderList_backup` and `DeleteStore_backup` are
genuinely tenant-scoped and are kept, which the docblock now records accurately. Neither removed
method was routed or referenced anywhere in `app/`, `resources/` or `tests/`.

**(e) The three permission limbs, and the lockout they had to avoid.** `file_delete`,
`product_attribute_delete` and `attribute_combination_edit` called `isAbleTo` **nowhere** —
P1-INV-07 gave them ownership, which answers *whose row is this*, and never *may this user touch
product media and variants at all*. All three now go through one new private helper,
`ProductController::lacksProductPermission()`, **operator-first** with a normalised `type`
comparison (Laratrust has no super-admin bypass, so an `isAbleTo`-only limb is a lockout, not a
tightening — `FinancePermission` records that happening once already).

The permission *choice* is where the evidence mattered. `Edit Products` for the two destructive
endpoints. **`Manage Products` OR `Edit Products` for the render**, because `show()` (`:881`) and
`edit()` (`:934`) call `isAbleTo` nowhere — the product view and edit *screens* carry no permission
requirement of their own — and `show.blade.php:730` PUTs to `attribute_combination_edit` from that
ungated read-only screen. Gating a render on `Edit Products` alone would have taken the variations
panel away from a staff role holding `Manage Products` for the catalogue list and nothing more. The
destructive endpoints behind the render are gated on their own, so render-on-Manage /
mutate-on-Edit gives up no control.

Seeding was checked **before** the limbs were written, not after: `Utility::addNewData()` creates
both names and grants both to the `admin` role (`:2679`/`:2681`) and the `super admin` role
(`:3008`/`:3010`), so a default merchant is unaffected in either direction. A permission no row
exists for can be held by no role and would make the limb a permanent 403.

**Evidence.** `tests/Feature/Security/CatalogueEndpointIntegrityTest.php` — **9 tests**, covering
(a), (b) and (d): the schema premise (`Schema::hasColumn` on `name`, `type` and `variant`), the
reproduced `QueryException`, the refusal plus a no-row-was-created assertion, the `UpdateVariant`
name-with-type repair, a cross-tenant positive control proving the repair did not widen P1-SEC-04's
predicate, the declared 67-entry both-directions dead-route ratchet with a >200-route reachability
assertion, and comment-stripped source ratchets on the two removed and the two kept `_backup`
methods. `tests/Feature/Security/MerchantProductOwnershipTest.php` — **13 new tests** for (c) and
(e) beside the existing P1-INV-07 ownership tests, bringing that file to **22 tests / 51
assertions**: (c) negative and positive control, and for each (e) route a refusal for a
permission-less **owner** plus the **platform-operator positive control that catches the lockout**,
the `Manage Products`-only compatibility control, and a control proving a missing variant id still
answers 404 for a permitted caller rather than becoming a 403 for everyone.

Three of this task's own tests failed first for the wrong reason and each failure was a real
finding: a literal `product_id => 1` died on the foreign key in the *fixture* (census-failure mode
3 — the handler was never reached), an assertion forbidding `$request->` in `DeleteStore_backup`
fired on the one correct, load-bearing read, and the (c) negative test is what surfaced the
pre-existing view crash.

---

## D. Unfinished implementation that needs no owner decision

Everything in this section can be built and shipped by an engineering session with no further input
from the owner. It is the whole of the remaining owner-independent queue, and it is short.

### D.1 The two remaining READY tasks

| task | issue | what remains | owner-gated part |
|---|---|---|---|
| **P1-ORD-13** | [#82](https://github.com/fullstackfull/syriastore98/issues/82) | The thank-you page identifies an order by a non-unique timestamp number. Carry the order's primary key through the gateway returns (≈10 flash sites) and close the Paymera replay. | **none** |
| **P1-INV-15** | [#60](https://github.com/fullstackfull/syriastore98/issues/60) | `Order::product_return`: POS orders 500, the return is not wrapped in a transaction, and `tax_price` is zeroed. | **one part only:** which of the two return endpoints credits stock. That is a settlement-behaviour choice, not a defect — see below. |

**P1-INV-15's split is worth stating precisely**, because "partly owner-gated" is otherwise an excuse
to do none of it. Parts A (POS orders 500), B (no transaction), D (`tax_price` zeroed) and F are
pure defects with one correct answer and are owner-independent. Part **E** — the double credit
across the two return endpoints — has two defensible resolutions (make the storefront endpoint
record an intent and the merchant endpoint do the crediting, or make both idempotent against a
shared marker) and the choice determines when a merchant's stock moves. **That one part, and only
that one part, waits.** The rest should ship.

### D.2 The BACKLOG, classified

Per the owner's instruction: **A** = mandatory before Phase 01 acceptance, **B** = safely deferrable
with written justification, **C** = blocked by an owner decision or an unmet dependency.

| task | issue | risk | class | reasoning |
|---|---|---|---|---|
| **P1-ORD-02** | [#21](https://github.com/fullstackfull/syriastore98/issues/21) | high | **A** | Acceptance criterion C1 is *server-authoritative order totals*. **The seam exists and the cutover is incomplete — the code says so itself.** `OrderTotalReconciler` is 387 lines with 8 public methods, but what is actually invoked across the four consuming files is almost entirely `amountsMatch()`, its static *comparison* primitive (5 sites in `PaymentVerificationService`, `OrderTaxResolver` and `PaymentService`); `PaymentService` and `PaymentController` only hold lazy `totalReconciler()` accessors, and two comments in those files state in as many words that *"`OrderTotalReconciler::assertConsistent()` is the cutover"* — marking places that should route through it and do not. So nothing asserts every writer derives its total through the seam, and the measurement here is not "9 of 9 wired" but "the comparison helper is wired and the derivation is not". No dependencies, no owner decision, and **P1-ORD-01 depends on it** — doing the coupon fix first means doing it nine times. |
| **P1-PAY-02** | [#24](https://github.com/fullstackfull/syriastore98/issues/24) | high | **A** | Payment/order idempotency is an explicit acceptance criterion. **No idempotency key exists anywhere**; replay is blocked only because the stored state happens to be single-use, which is a coincidence and not a control. F.3 shows a form that needs no new table, so it is not gated on GATE F. |
| **P1-INV-03** | [#29](https://github.com/fullstackfull/syriastore98/issues/29) | high | **A** | POS stock consistency is an explicit criterion, and the ledger records it as **confirmed by execution in an earlier session**: seeding the POS session with store B's product moved B's `product_stock` 50 → 49. Not re-driven here. Its only dependency, P1-INV-01, is DONE. A cross-tenant stock write on a reachable route cannot be deferred. |
| **P1-INV-05** | [#31](https://github.com/fullstackfull/syriastore98/issues/31) | medium | **A (part), B (part)** | The "every mutation through the atomic primitive" half is mandatory — several restock paths are still read-modify-write — and is owner-independent. The **concurrency half is criterion E3 and cannot be proved on SQLite**; it is deferrable only in the sense that the *proof* must happen where there is a MySQL, not that the work is optional. See section H. |
| **P1-ORD-06** | [#62](https://github.com/fullstackfull/syriastore98/issues/62) | high | **A** | `auction-order-status-change` reads the **host** `orders` table with an auction id and POSTs the result, PII included, to a merchant-configured URL. That is a cross-tenant read plus an outbound PII leak on a reachable route. No owner decision; the fix is a tenant predicate plus the same URL policy `Utility::WebhookCall` already received in P1-SEC-33. |
| **P1-PLT-03** | [#35](https://github.com/fullstackfull/syriastore98/issues/35) | high | **A** | 10 of 93 models are enforced, `null_tenant` is `open`, and the cross-tenant escape hatches have **zero call sites**. It is the prerequisite for scoping `Product` (F.2), which is the single change that closes the largest remaining class. Dependency: **P1-PLT-02** — see section I. |
| **P1-PLT-05** | [#37](https://github.com/fullstackfull/syriastore98/issues/37) | medium | **B** | Enumerating the phase's security log lines and stating a channel/retention expectation is documentation of work already done. Deferrable **with this justification**: the log lines exist and are tested; what is missing is the operator-facing statement, which has no security effect until someone is watching. The LaraBug blacklist half moved to P1-SEC-14 and is tracked there. |
| **P1-ORD-01** | [#20](https://github.com/fullstackfull/syriastore98/issues/20) | high | **C** | `owner_decision: true`. **Changes charged amounts** — needs explicit acceptance. Also depends on P1-ORD-02. |
| **P1-INV-16** | [#61](https://github.com/fullstackfull/syriastore98/issues/61) | high | **C** | Owner-coupled: its clean fix is adding `Product` to `scoped_models`, a broad behavioural change (F.2). Its *narrow* fix — a tenant predicate, a replay guard, the shadowed-variable bug and a null guard — is in `packages/workdo/`, which must not be edited, so it needs a host-side control that P1-PLT-03 should shape first. |

**Four mandatory, one split, one deferrable, two blocked.** None of the four mandatory items has an
unmet dependency except P1-PLT-03, whose dependency is a reclassification in section I rather than
new work.

### D.3 New findings from this session

Both were found while doing P1-INV-10 and are recorded here rather than acted on, because acting on
either would have widened that task.

**D.3.1 — 67 live host-app routes answer with a 500.** Measured in this session by keying on
`$route->getActionName()` across every registered route: **69** route actions pointed at controller
methods that do not exist. Two were already in the security queue — `sku_combination_edit` as S38-3b
and `addToCartVariant`, which S36 wrote down and never gave a task row — and both are removed by
P1-INV-10. The remaining **67 split two ways, and the split was measured rather than assumed**:

- **58** carry one of the seven `Route::resource()` action names, across **17 controllers**
  (`PlanRequest` and `Report` 6 each, `OrderNote` and `OrderRefund` 5, `AccountProfile`, `Addon`,
  `AppSetting`, `Pos`, `ShopifyProduct` and `SupportTicket` 4 each, and the rest 1–2). A resource
  declared for a controller that implements only some of the seven registers the rest anyway.
- **9 are ordinary named routes** whose method simply does not exist, and these are the more
  interesting half: `OrderController@place_order` (`routes/customer.php:161`, the web `place.order`
  route), `Api\ApiController@random_product` (a storefront API route), three
  `MyFatoorahController` methods, both `ModuleController@AddOnSession*` add-on purchase routes,
  `AppSettingController@image_delete` and `OrderController@allOrders`.

**`place.order` was checked specifically, because a broken web checkout would not be a low-severity
finding.** It is dead: no Blade template, no JavaScript and no module references `route('place.order')`
or posts to the literal uri (the only `place-order` reference anywhere is the API documentation
pointing at `api/{slug}/place-order`, whose `ApiController::place_order` exists), and
`OrderController::store()` is the live checkout writer. So the storefront checkout works; this route
is residue.

*Severity: low, and stated as low.* A 500 rather than a 404 is an availability and
information-disclosure nuisance (a stack trace where `APP_DEBUG` is on), not an authorization
failure — the routes are mostly inside the `auth` group, and none of the nine named ones is on a
path a working client uses. *Fix: `->only([...])` on each resource declaration, and remove or
implement the nine* — mechanical, ~30 one-line edits, owner-independent, and worth one pass rather
than one route at a time. **It is held today by a declared both-directions ratchet**
(`CatalogueEndpointIntegrityTest::test_host_app_routes_pointing_at_missing_methods_match_the_declared_set`,
whose `DEAD_ROUTE_ACTIONS` constant enumerates all 67),
so a new dead route fails CI and a route that quietly gets its method back fails CI too and must be
removed from the list. The finding cannot rot.

**D.3.2 — `ProductController::attribute_combination` (POST) has no permission limb.** P1-INV-10 (e)
gave limbs to the three routes its row named. This fourth sibling on the same screen renders a
combination table and still calls `isAbleTo` nowhere. *Severity: low.* After (c) it reads only the
caller's **own** store's option rows, so there is no cross-tenant content behind it and nothing is
written; what is missing is the permission check, not the tenant check. *Fix: one call to the
`lacksProductPermission(['Manage Products', 'Edit Products'])` helper that now exists* — it is a
render reached from the same ungated screens, so it takes the render-level limb.

Neither finding is proposed as a new issue on its own. **Recommendation: one issue covering both,**
since both are small, both are mechanical, and both belong to the same question — which routes are
live and who may reach them — rather than to eight separate ones. Avoiding issue proliferation was
an explicit instruction and these two are exactly the case it was written for.

### D.4 Owner-independent work sitting inside BLOCKED tasks

A task marked BLOCKED does not mean every part of it is. Two were checked line by line, and both
carry a half that needs nobody:

**`P1-SEC-26` (#99)** — its own `blocked_on` field says so in as many words: *"TWO PARTS, and only
the first needs the owner."* The owner's half is the read-only `storage_setting` query. The other
half is host-closable now: `store.dpo.view` (`routes/customer.php:375`) sits in the
`[ActiveTheme, themelanguage]` group with **no `auth` and no `{storeSlug}`**, so its only store
source is the request body's `slug` — which is caller input, not a tenant check — and it needs a
route-shape change to a gateway-facing URL before it can name a store at all. And criterion 4
(unknown slug: fail closed) was **decided by reading** that every storefront handler refuses an
unknown slug through `getStore()` first; **that premise has never been executed.** Executing it is
one test.

**`P1-PLT-02` (#34)**, the audit's only BLOCKED verdict, is the cheapest item in this whole report.
Its unmet criterion is that existing NULL-store rows are *counted and reported*. The script exists
(`tests/Support/Security/bin/report-null-store-orders.php`, 53 lines, provably SELECT-only by
inspection — `count()`/`groupBy()`/`min()`/`max()` and no `INSERT`, `UPDATE` or `DELETE`) and has
**never been run**. It needs a database with real rows, so it could not be run here either. **One
command on a machine that has the database closes it**, and until it runs, nobody knows how many
orders carry a NULL `store_id`.

**The general point for the closure plan:** "BLOCKED" is a property of a decision, not of a task, and
splitting the two is worth doing before Stage 1 is scheduled. These two were split because they came
up; the other 28 were not re-read line by line for this report and may hold the same shape.

---

## E. The blocked-decision register

Thirty tasks are BLOCKED. Every one of them is blocked on something only the owner can supply: a
policy choice, an operation against the running server, live credentials, or an acceptance of a
behaviour change that moves money. **None of the thirty is blocked on engineering.**

They are grouped by subject, in the owner's stated priority order, and within each group by risk.
For each group: what is unresolved, what is already fixed, what prevents closure, the options with
their trade-offs, a recommendation, and whether the group blocks **acceptance** of Phase 01 or only
**deployment**.

A word on how to read the severity column. **Reachable** means a request today produces the effect.
**Latent** means the code path exists but nothing routes to it or a precondition is absent.
**Residue** means the code hole is closed and what remains is data already written. **Unverified**
means it was found by reading and no execution has confirmed it.

---

### Group 1 — Customer data already written in plaintext · BLOCKS ACCEPTANCE

| task | issue | risk | state |
|---|---|---|---|
| P1-OWN-08 | [#86](https://github.com/fullstackfull/syriastore98/issues/86) | **critical** | **residue** |

**The problem.** `shetabit_visits` has, since the visitor package's migration ran (2024-01-26),
recorded the full request of every themed-storefront page — including `request` and `headers`
columns. Every customer who logged in, registered or reset a password on a themed storefront has a
**plaintext password** in that table, failed attempts included (which are often near-misses of the
real password), together with session cookies and, where NMI or Authorize.Net were enabled,
possibly card fields.

**What is already fixed.** P1-SEC-13 stops new rows holding secrets. It cannot undo rows already
stored, and the development protocol forbids destructive data operations against the running server
without approval.

**What prevents closure.** Seven decisions, all the owner's: scrub versus purge (the table has no
`store_id` on these rows, so it is necessarily table-wide); whether customers are notified and
forced to reset; whether merchant and operator sessions are invalidated (their admin cookies are in
the table); backup retention and re-cutting, including any `mysqldump` the BackupRestore module
wrote to a web-reachable `chmod 0777` path; who had read access; sequencing against the APP_KEY
rotation; and whether the card-bearing rows start a PCI incident process.

**Options.**

| | what it is | for | against |
|---|---|---|---|
| **A** | **Scrub**: null `request` and `headers`, keep the analytics columns | nothing in the application reads those two columns, so no function is lost; analytics history survives | leaves `url`/`referer` query strings, which need a second pass; the table keeps growing with no retention |
| **B** | **Purge** the table | removes the whole exposure in one operation, including the second pass | loses all visit analytics; the table will refill unless retention is added |
| **C** | Scrub now, add retention, purge later | staged, lowest immediate risk | two operations instead of one; needs the retention decision anyway |

**Recommended: A, immediately, then C's retention step.** Scrubbing is reversible in the sense that
it loses no function, and it is the smallest operation that removes the credentials. The
industry-standard shape here — and this is a design recommendation, not a platform requirement — is
(1) read-only census first (`method = 'POST'`, then `visitor_type = 'App\Models\User'`), (2) scrub
on a **restored copy** and verify no row retains a non-analytics value, (3) scrub production,
(4) force a password reset for the affected cohort, (5) invalidate admin sessions, (6) add
retention. Notification obligations depend on jurisdiction and are the owner's call with their
counsel; this report does not advise on that.

**Compatibility impact.** None for any client. Customers forced to reset see the ordinary reset
flow. Admin session invalidation logs merchants out once.

**Data-loss risk.** Option B loses analytics permanently. Option A loses nothing the application
uses — verified by grep: no reader of `request` or `headers` exists in `app/`.

**Verification required.** The scrub must be proved on a restored copy before production, with a
query showing zero rows retaining a non-analytics value. **This cannot be done in this session** —
there is no database here and no production access.

---

### Group 2 — Payment-gateway secrets that were world-readable · BLOCKS ACCEPTANCE

| task | issue | risk | state |
|---|---|---|---|
| P1-OWN-09 | [#88](https://github.com/fullstackfull/syriastore98/issues/88) | **critical** | **residue** |

**The problem.** `POST /api/{slug}/payment-list` returned every configured gateway's live secrets —
56 secret fields across Stripe, Paystack, PayPal, Razorpay and the rest, including gateways the
store had disabled — to **any internet visitor**. Registration is public, so the audience was
unbounded. The repository history is squashed, so the date it first answered is unknowable here.

The scope widened as later tasks closed adjacent sinks: each merchant's **Telegram bot token**, the
**Midtrans** server keys (sandbox only — `isProduction=false` is hard-coded, so no production key
ever rendered), the **platform** reCAPTCHA secret, the **platform** OpenAI key (billed to the
platform, readable by every verified merchant and staff login), and the **Duo** secret and
integration keys for any merchant using the Donation or LotteryPrizes Telegram/WhatsApp/Khalti
checkout with Duo2FA configured.

**What is already fixed.** P1-SEC-15 stops the endpoint answering. P1-SEC-16/17/19/20 closed the
adjacent sinks. None of that un-discloses what was already served.

**What prevents closure.** Whether to tell merchants to rotate and how; a read-only access-log check
(the only possible evidence of exploitation); purging the `api-*.log` files, which write the
response body with most secrets in plaintext under a 15-day retention, plus any backup of them; and
sequencing against the APP_KEY rotation.

**Options.** There is no real option on *whether*: a secret served to the internet is exposed. The
options are on scope and tone.

| | what it is | for | against |
|---|---|---|---|
| **A** | Tell every merchant to rotate every configured gateway key | correct and complete | operationally heavy; merchants must re-enter keys; some will not act |
| **B** | Rotate only where the access log shows a suspicious read | less disruption | the access log may not go back far enough; absence of evidence is not evidence of absence |
| **C** | A, plus platform-side rotation of the platform's own keys (reCAPTCHA, OpenAI) which the owner can do unilaterally | complete, and the platform half needs no merchant action | same cost as A for the merchant half |

**Recommended: C.** Do the platform-side rotations first — they are unilateral, and the OpenAI key
is a direct billing exposure. Then the merchant notification. Run the access-log check regardless,
because its result changes the *statement* made to merchants even if it does not change the action.

**Compatibility impact.** Merchants who rotate must update keys in store settings; nothing in the
platform changes.

**Verification required.** The access-log check and the log purge both need server access. **Not
possible in this session.**

---

### Group 3 — Tenant isolation and host ownership · BLOCKS ACCEPTANCE (one item) / DEPLOYMENT

| task | issue | risk | state |
|---|---|---|---|
| P1-SEC-32 | [#111](https://github.com/fullstackfull/syriastore98/issues/111) | medium | reachable |
| P1-SEC-26 | [#99](https://github.com/fullstackfull/syriastore98/issues/99) | medium | reachable (1 of 7 left) |
| P1-INV-12 | [#53](https://github.com/fullstackfull/syriastore98/issues/53) | high | reachable |
| P1-INV-06 | [#32](https://github.com/fullstackfull/syriastore98/issues/32) | medium | latent |
| P1-SEC-07 | [#69](https://github.com/fullstackfull/syriastore98/issues/69) | high | reachable |

**P1-SEC-32 — who may hold a host?** The platform's own host and its reserved sub-domain labels are
not reserved, a host is taken first-come with no proof of DNS control, and there is no release path.
`HostClaims::assertFree` now stops a *second* store taking a host another store holds, so the race
is closed; the policy is not. **Decision needed:** the reserved-label list, whether DNS proof is
required before a claim, and what releases a host. **Recommended** (industry-standard shape, not a
platform requirement): reserve the platform apex and a fixed label list (`www`, `admin`, `api`,
`app`, `mail`, `static`, `cdn`), require a DNS TXT proof before a custom host activates, and
release a host when the store is deleted or the claim is explicitly withdrawn.

**P1-SEC-26 — store-less setting reads.** Six of the seven defects are fixed. The remaining one is a
`storage_setting` query the owner must answer: whether the platform's storage configuration should
be read through the merchant-shaped call at all. **Blocks deployment, not acceptance** — the fixed
six are what made the finding reachable.

**P1-INV-12 — a category delete cascades to every product in it, below the application.** This is an
FK `onDelete('cascade')`, so no application guard can intercept it. **Decision needed:** whether
deleting a category may delete products (then the UI must say so, loudly, with a count), or whether
the FK becomes `RESTRICT` and the merchant must move products first. **Recommended: RESTRICT**, with
a "move products to…" step in the delete flow. Cascading product deletion from a taxonomy change is
not a behaviour merchants expect, and it is irreversible. **This requires a migration**, so it is
coupled to Group 7.

**P1-INV-06 and P1-SEC-07** are vendored-module problems; see Group 8.

---

### Group 4 — Payment verification and COD settlement · BLOCKS ACCEPTANCE

| task | issue | risk | state |
|---|---|---|---|
| P1-PAY-03 | [#25](https://github.com/fullstackfull/syriastore98/issues/25) | high | reachable |
| P1-PAY-04 | [#26](https://github.com/fullstackfull/syriastore98/issues/26) | high | reachable |

**P1-PAY-03 — no gateway return proves a payment moved.** The seam is built:
`PaymentVerificationService` derives `Paid` and every unverified write is logged. **NMI is the only
writer backed by a recorded verification.** The other 51 live payment methods write `Paid` on the
strength of the return itself.

**What prevents closure:** which gateways to enforce first, and provider credentials to verify
against. Khalti is credential-blocked outright, and its verification is amount-unbound — it must be
bound to Khalti's minor-unit convention or the check passes for the wrong amount.

| | what it is | for | against |
|---|---|---|---|
| **A** | Enforce verification on all gateways at once | closes it completely | **stops merchant wallets being credited for 51 methods** until each is verified — a business outage |
| **B** | Enforce per gateway as credentials arrive, log-and-allow elsewhere | no outage; the log already exists | the window stays open for unenforced gateways |
| **C** | Enforce on the gateways carrying most volume first | closes most of the exposure with least disruption | needs volume data the repository does not hold |

**Recommended: C, falling back to B.** The owner has the volume data; this report does not. The
ordering decision is theirs, and the engineering is ready to take whichever list they give.

**P1-PAY-04 — COD settles at order time.** The hardcoded `Paid` is fixed; COD still settles when the
order is placed, before cash is collected. **This is the item that blocks releasing GATE F:**
`database/migrations/_gate_f_pending/README.md` warns that creating the money tables while COD
settles at order time makes the ledger *actively wrong* rather than merely absent.

| | what it is | for | against |
|---|---|---|---|
| **A** | COD settles on delivery confirmation | the ledger is correct; standard practice | merchants see money later than they do today — a visible change |
| **B** | Per-store configurable, default settle-on-delivery | respects merchants who reconcile differently | more surface; the default is still a change |
| **C** | Keep settle-at-order, document the ledger caveat | no merchant-visible change | GATE F cannot be released; the financial subsystem stays dead |

**Recommended: B.** It is the acceptance criterion the row already names ("explicit and configurable
per store", "the default does not credit a wallet before cash is collected"), it is the industry
norm, and it unblocks GATE F. **It changes when merchants see money, so it needs the owner's
explicit acceptance** — this report does not take that decision.

---

### Group 5 — Refund and return lifecycle · BLOCKS ACCEPTANCE

| task | issue | risk | state |
|---|---|---|---|
| P1-ORD-11 | [#75](https://github.com/fullstackfull/syriastore98/issues/75) | medium | reachable |
| P1-INV-16 | [#61](https://github.com/fullstackfull/syriastore98/issues/61) | high | reachable (BACKLOG, owner-coupled) |

**P1-ORD-11 — a storefront refund *request* rewrites the order's totals at request time**, before any
merchant decision, and not atomically. So a shopper asking for a refund changes the recorded value
of the order whether or not the merchant ever approves it.

**Decision needed:** when a refund affects the order's totals. **Recommended:** on merchant
approval, not on request; the request records an intent and nothing else. That is the standard
shape and it is also the only one in which a declined request leaves no trace on the money. **This
changes recorded order values**, so it needs owner acceptance.

**P1-INV-16** is in the BACKLOG but owner-coupled: its cleanest fix is adding `Product` to
`config/tenancy.php`'s `scoped_models`, which closes this *and* the whole S19 class in one move but
is a broad behavioural change across the application. See section F.

---

### Group 6 — Financial records and retention · BLOCKS ACCEPTANCE

| task | issue | risk | state |
|---|---|---|---|
| P1-ORD-03 | [#22](https://github.com/fullstackfull/syriastore98/issues/22) | high | reachable |
| P1-OWN-07 | [#56](https://github.com/fullstackfull/syriastore98/issues/56) | high | residue |

**P1-ORD-03 — `user_delete` hard-deletes orders while soft-deleting the account.** A customer
deleting their account destroys the merchant's order records. **Decision needed:** what a customer
deletion must retain. **Recommended:** retain the order rows and anonymise the personal fields on
them. Orders are the merchant's accounting records, not the customer's data alone, and most
retention regimes require keeping them; "delete my account" almost never means "delete the
merchant's books". **This is a policy call with legal content and is firmly the owner's.**

**P1-OWN-07 — data residue from the pre-fix WooCommerce transfer primitive.** A read-only
investigation of what the old primitive moved between stores. Needs production read access.

---

### Group 7 — GATE F and the schema migrations · BLOCKS DEPLOYMENT, AND ACCEPTANCE VIA THE SKIPS

| task | issue | risk | state |
|---|---|---|---|
| P1-MIG-01 | [#38](https://github.com/fullstackfull/syriastore98/issues/38) | high | contained |
| P1-MIG-02 | [#39](https://github.com/fullstackfull/syriastore98/issues/39) | high | prepared, not applied |
| P1-MIG-03 | [#40](https://github.com/fullstackfull/syriastore98/issues/40) | high | undeclared |
| P1-MIG-04 | [#41](https://github.com/fullstackfull/syriastore98/issues/41) | medium | undeclared |

**This group is one decision wearing four task numbers:** *does the owner approve applying
migrations, and in what order?*

**15 tables that live code reads and writes have no migration, 6 of them money-bearing.** Drafts are
held unrunnable in `database/migrations/_gate_f_pending/`. **15 of the Feature suite's 17 accepted
skips name one of those six tables** — which is why this group blocks *acceptance* and not only
deployment: criterion A3 cannot be met while 15 skips are structural.

The ordering is not free. P1-MIG-01 (the six money tables) is gated on **P1-MIG-03** first
(`products.cost_price` must exist before `store_order_financials`, or the P&L is all-margin) and on
**P1-PAY-04** (COD timing, Group 4), because creating the money tables while COD settles at order
time makes the ledger actively wrong. P1-MIG-02 (`customers.password_otp` is INTEGER while the web
reset writes a 60-char bcrypt hash) is independent and is the smallest of the four; its draft is
held as `.php.draft` so Laravel cannot load it. **It is also the defect that proves the MySQL
caveat** — SQLite's type affinity hides it entirely.

**Recommended sequence:** P1-MIG-02 (smallest, independent, closes a real auth defect) → P1-MIG-03
(cost inputs) → P1-PAY-04 decision → P1-MIG-01 (the six money tables) → P1-MIG-04 (the remaining
nine, with a decision on the two retired chatwoot tables). **Each step needs an approved recovery
plan before it is applied anywhere persistent**, which is the row's own acceptance criterion.

**Data-loss risk.** Creating tables is additive and safe. The risk is the *opposite*: P1-MIG-01
"makes a silently dead subsystem start working" — wallets, ledgers, payouts, P&L, FX and courier
commission all begin recording. If COD timing is wrong at that moment, the ledger is wrong from its
first row.

**Verification required.** On **real MySQL**. `tests/Support/Security/bin/verify-sec13-migration-on-mysql.php`
shows the pattern. **Not possible in this session** — see section H.

---

### Group 8 — Auth tokens, production secrets, and the vendored-module ceiling · BLOCKS DEPLOYMENT

| task | issue | risk | state |
|---|---|---|---|
| P1-OWN-02 | [#43](https://github.com/fullstackfull/syriastore98/issues/43) | high | open |
| P1-OWN-03 | [#44](https://github.com/fullstackfull/syriastore98/issues/44) | high | residue |
| P1-OWN-04 | [#45](https://github.com/fullstackfull/syriastore98/issues/45) | high | open |
| P1-SEC-07 | [#69](https://github.com/fullstackfull/syriastore98/issues/69) | high | reachable |
| P1-INV-06 | [#32](https://github.com/fullstackfull/syriastore98/issues/32) | medium | latent |
| P1-INV-20/21/22 | [#67](https://github.com/fullstackfull/syriastore98/issues/67) / [#68](https://github.com/fullstackfull/syriastore98/issues/68) / [#70](https://github.com/fullstackfull/syriastore98/issues/70) | high/high/medium | reachable |

**P1-OWN-02 — APP_KEY is in git history and unrotated.** So `Crypt::encrypt()` is an *encoding, not a
control*, and every encrypted id in a URL is forgeable. That single fact is what makes P1-ORD-09
(#73) a real finding rather than a theoretical one.

**The ordering trap:** rotating APP_KEY without re-encrypting breaks every encrypted-cast column. The
row's own criterion is that rotation is **preceded by an inventory of every encrypted-cast column**.
**Recommended:** inventory → re-encryption pass written and tested → rotate → verify. And the Zid
secret must be provisioned in the environment *before* it is moved out of config, or the integration
breaks at the moment of the move.

**P1-OWN-03 — the API log on the running server** holds up to 15 days of plaintext passwords, OTPs
and bearer tokens. Redaction has landed; the existing files are untouched. **Decision needed:** treat
that material as compromised (it is), and record a retention decision for API request/response
bodies. Past malicious use cannot be determined from the repository.

**P1-OWN-04 — tokens never expire, every one carries `['*']`, and there is no refresh path for any
client.** `CheckAbilities` is imported in `routes/api.php` and never used; `Api\AuthController::login`
is unrouted, so customers have no login endpoint at all.

| | what it is | for | against |
|---|---|---|---|
| **A** | Add expiry now | closes the window | **breaks every already-installed app**: there is no refresh path, and the Flutter client force-logs-out on any 401 |
| **B** | Ship a refresh path first, then expire in a later release | no forced logouts | needs a client release and a migration window |
| **C** | Scope abilities now (drop `['*']`), expire later | reduces blast radius without touching lifetime | does not close the lifetime question |

**Recommended: C then B.** Narrowing abilities is the half that does not break shipped binaries;
expiry needs a client release window, which is a scheduling decision the owner owns. See
`docs/security/P01-TOKEN-EXPIRY-PROPOSAL.md`.

**The vendored-module ceiling** (P1-SEC-07, P1-INV-06, P1-INV-20/21/22). These live in
`packages/workdo/`, which must not be edited — an in-place edit is reverted by the next upstream
update and leaves no patch record. Every fix must therefore be a host-side control (middleware
appended to the `web` group, a policy, a FormRequest, a query scope). That is possible for most of
them, and it is *why* they are expensive: a host-side control has to reconstruct the module's intent
from outside it. **Decision needed:** whether to keep these modules enabled at all. Row existence in
`add_on_managers` is what loads a module and `loadDynamicAddons()` plucks modules with **no
`is_enable` predicate**, so "disabled" is **not** containment — a disabled row still registers every
route. **Recommended:** for LotteryPrizes specifically (P1-INV-20/21/22 — an anonymous ticket writer
into any store, a forgeable client-posted ticket price, an unscoped refund delete, a 500 on every
successful draw, and two gates that lock out the platform operator), **remove the
`add_on_managers` row and confirm the routes are gone**. The module is substantially broken on its
own terms; containing it is cheaper and safer than reconstructing it from the host side.

---

### Group 9 — Operational and CI controls · BLOCKS DEPLOYMENT

| task | issue | risk | state |
|---|---|---|---|
| P1-OWN-01 | [#42](https://github.com/fullstackfull/syriastore98/issues/42) | high | needs verification |
| P1-OWN-05 | [#46](https://github.com/fullstackfull/syriastore98/issues/46) | medium | open |
| P1-OWN-06 | [#47](https://github.com/fullstackfull/syriastore98/issues/47) | medium | open |
| P1-OWN-10 | [#103](https://github.com/fullstackfull/syriastore98/issues/103) | medium | residue |
| P1-OWN-11 | [#106](https://github.com/fullstackfull/syriastore98/issues/106) | medium | needs read access |
| P1-OWN-12 | [#108](https://github.com/fullstackfull/syriastore98/issues/108) | medium | open |
| P1-PLT-04 | [#36](https://github.com/fullstackfull/syriastore98/issues/36) | medium | open |
| P1-ORD-09 | [#73](https://github.com/fullstackfull/syriastore98/issues/73) | high | reachable |

**P1-OWN-01 — BackupRestore.** The owner has confirmed the module is DISABLED. What remains is to
*verify* that, and per Group 8 a disabled `add_on_managers` row still registers every route. The
module's `mysqldump` writes the whole database to a web-reachable path with `chmod 0777`, and its
`database_name` is concatenated into `shell_exec`. **Recommended: remove the row, then assert the
routes are absent** — with a test, not a reading.

**P1-OWN-06 — branch protection and required checks on `main`.** Nothing in this repository can
enforce it; it is a GitHub setting. **Recommended:** require the Feature-suite known-outcomes gate,
the WorkDo guard, the GATE F containment guard and the secret scanner, and forbid force-push.
Without this, every CI guard this phase built is advisory.

**P1-OWN-10 — 407 user-uploaded files are committed to the repository**, including screenshots, a
receipt and profile images. **Decision needed:** remove from the working tree only, or rewrite
history. **Recommended: remove from the tree and add the path to `.gitignore`.** History rewriting is
explicitly outside this session's authority and is rarely worth it once the content is already
distributed; the exception would be if any of those files contains customer PII the owner must be
able to say is gone — which the receipt might, and which only the owner can judge.

**P1-ORD-09 — `/order/{Crypt::encrypt(id)}` is the only credential on the order page**, and with the
leaked APP_KEY it is forgeable. **Blocked behind P1-OWN-02:** rotating the key is what makes the
existing design defensible, and until then no amount of application work fixes it. **Recommended:**
rotate first (Group 8), then replace the encrypted id with a random, single-purpose, expiring token
if the owner wants a design that does not depend on key secrecy at all.

**P1-OWN-12 — who may put script and style on every storefront page, checkout included**
(`storejs`, `storecss`, `google_analytic`, `fbpixel_code`). Today a merchant setting injects script
into their own checkout. **Decision needed:** whether that stays, and if so whether it is restricted
to the store owner rather than any staff role. **Recommended:** keep the capability (merchants
legitimately need analytics), restrict it to the store owner, and keep it out of the checkout
template specifically — script on a payment page is the one place where the blast radius is a
customer's card details.

**P1-PLT-04 — module loading swallows every exception with no log line.** So a module that fails to
load fails silently and the platform looks fine. **Recommended:** log at `error` with the module
name and rethrow in non-production. Cheap, and it is the difference between "the feature is off" and
"the feature is broken".

**P1-OWN-05 and P1-OWN-11** are a tooling decision and three read-only production queries
respectively; both are small and both need the owner.

---

## F. Recommendations

Section E gives a recommendation inside each group. This section collects them into one table, and
then sets out the three recommendations that span several tasks and are therefore easy to miss when
reading task by task.

**Throughout this section, "verified platform requirement" means something measured in this
codebase, and "proposed design" means an industry-standard shape that this report is recommending.
They are labelled, because conflating them is how a consultant's preference becomes a project's
constraint.**

### F.1 One line per decision

| # | decision | recommended | alternatives | compatibility risk |
|---|---|---|---|---|
| 1 | P1-OWN-08 scrub or purge `shetabit_visits` | **scrub** `request`+`headers`, then add retention | purge (loses analytics); staged | none for clients; forced resets are customer-visible |
| 2 | P1-OWN-08 customer notification | owner + counsel | — | one reset flow per affected customer |
| 3 | P1-OWN-09 gateway-key rotation | platform keys first (unilateral), then merchant notice | log-driven subset | merchants re-enter keys |
| 4 | P1-SEC-32 host policy | reserve apex + fixed label list; DNS TXT proof; explicit release | first-come with reserved list only | a merchant mid-claim must complete DNS proof |
| 5 | P1-INV-12 category→product cascade | FK `RESTRICT` + "move products" step | keep cascade with a loud confirmation | needs a migration (Group 7) |
| 6 | P1-PAY-03 verification rollout | by volume, falling back to per-gateway as credentials arrive | all at once (business outage) | unenforced gateways keep the window open |
| 7 | P1-PAY-04 COD timing | **per-store configurable, default settle-on-delivery** | settle-at-order (GATE F stays blocked) | **merchants see money later** |
| 8 | P1-ORD-11 refund totals | rewrite on **approval**, not on request | keep request-time rewrite | recorded order values change |
| 9 | P1-ORD-03 account deletion | retain orders, anonymise personal fields | hard-delete (destroys merchant books) | policy/legal; owner's call |
| 10 | Group 7 migration order | MIG-02 → MIG-03 → PAY-04 decision → MIG-01 → MIG-04 | any other order risks a wrong ledger | GATE F release makes a dead subsystem live |
| 11 | P1-OWN-02 APP_KEY | inventory → re-encryption pass → rotate → verify | rotate first (**breaks every encrypted column**) | encrypted ids in existing links stop resolving |
| 12 | P1-OWN-04 tokens | **narrow abilities now, expiry after a refresh path ships** | expiry now (breaks installed apps) | a 401 force-logs-out the Flutter client |
| 13 | LotteryPrizes (P1-INV-20/21/22) | **contain**: remove the `add_on_managers` row, assert routes gone | host-side reconstruction of 7 defects | the module stops working — it largely does not |
| 14 | P1-OWN-01 BackupRestore | remove the row, assert routes absent **with a test** | trust the "disabled" flag | none |
| 15 | P1-OWN-06 branch protection | require the four guards; forbid force-push | none | every CI guard is advisory without it |
| 16 | P1-OWN-10 committed uploads | remove from tree + `.gitignore` | history rewrite | out of this session's authority |
| 17 | P1-OWN-12 storefront script | keep, restrict to store **owner**, exclude checkout | keep as-is | staff roles lose the field |
| 18 | P1-PLT-04 module load errors | log at `error` + rethrow outside production | leave silent | none |
| 19 | #66 P1-TOOL-01 | close as completed, pointing at the manifest | add a ledger row | none |

### F.2 `Product` in `scoped_models` — one change that closes a whole class

**Verified platform requirement.** `App\Models\Product` is absent from `config/tenancy.php`'s
`scoped_models`, and `StoreScope::isActiveFor()` short-circuits for a model that is not in that
list. So **`Product` adds no predicate for any principal** — not a customer, not a merchant, not the
operator. `Product::find($id)` reaches every tenant's catalogue. That produced a live cross-tenant
stock drain (S19) that a `customer_id` sweep missed entirely because it was looking at the wrong
identifier, and it is the mechanism behind **P1-INV-16** (#61) too.

**Proposed design.** Add `Product` to `scoped_models` and use the explicit `allowCrossTenant()`
escape hatch wherever a query must legitimately cross tenants (the operator's own screens, platform
reports, the module import paths).

**Why it is not simply done.** It is a broad behavioural change: every unscoped `Product` query in
70 vendored modules starts filtering. `null_tenant => 'open'` means the scope adds nothing when no
tenant resolves, so jobs and console commands are *probably* unaffected — but "probably" is the
problem. **P1-PLT-03** (#35) exists precisely to stage this: the escape hatches
(`forStoreContext`/`allowCrossTenant`) currently have **zero call sites**, so there is no
tested path for the legitimate crossings yet.

**Recommendation: do P1-PLT-03 first** — give the escape hatches real call sites and a documented
inventory of which models are scoped and which deliberately are not — **then** add `Product`, behind
a survey test that fails when a scoped model gains an implicit cross-tenant read. Adding `Product`
before the hatches are exercised would, on the evidence of this phase's two previous
too-tight-predicate incidents, empty a storefront.

### F.3 Idempotency, and why it is not a key column

**Verified platform requirement.** **No idempotency key exists anywhere** in this application
(P1-PAY-02, #24). Replay is currently blocked only because the stored state happens to be
single-use — which is not a control, it is a coincidence of the implementation.

**Proposed design.** The temptation is a new `idempotency_keys` table, and the row itself notes that
if a table is needed this becomes "a GATE-F-class schema decision and this task splits" — i.e. it
would be blocked behind Group 7. **It does not have to be.** A gateway return can be made idempotent
without new storage by making the *state transition* conditional — one atomic statement, acting only
on an affected-row count of 1, so the second caller sees 0 rows affected and returns the first
result. That satisfies the row's own criterion ("replay is refused idempotently, not by crashing")
with no new table.

**And the predicate has a trap worth naming, because the obvious form is wrong here.**
`orders.payment_status` is `string NULLABLE` (`create_orders_table.php:30`), and in SQL
`NULL <> 'Paid'` evaluates to NULL, not true — so the natural

```sql
UPDATE orders SET payment_status = 'Paid' WHERE id = ? AND payment_status <> 'Paid'
```

**matches no row whose `payment_status` is NULL**, which is exactly the pre-payment state the
transition is for. Every legitimate first call would report 0 rows affected and be mistaken for a
replay. The predicate has to spell the null out:

```sql
WHERE id = ? AND (payment_status IS NULL OR payment_status <> 'Paid')
```

In Eloquent: `->where(fn ($q) => $q->whereNull('payment_status')->orWhere('payment_status', '<>', 'Paid'))`.
This is recorded because the first version of this recommendation had the broken form, and a
three-valued-logic slip in an idempotency guard fails in the direction that *rejects real payments*.

**Recommendation:** implement the conditional-transition form now, as owner-independent work, and
keep the keyed-table design as a later improvement for the cases a single row cannot express
(multi-row settlements). **This removes P1-PAY-02 from the GATE F critical path**, which matters
because Group 7 is the longest chain in the whole phase.

### F.4 The order-total seam is three-quarters built and should be finished before P1-ORD-01

**Verified platform requirement.** `OrderTotalReconciler` (387 lines, 8 public methods) exists, as do
`OrderTaxResolver` (125 lines), `AppliesCartStockChanges` (137 lines), `ProductStockService` (346),
`PaymentVerificationService` (583) and `GuardsGatewayReturn` (587). **But the total-derivation
cutover has not happened.** Measured in this session: of the uses outside the class itself, five are
`amountsMatch()` — the static comparison primitive — and the rest are lazy accessors plus two
comments that say outright *"`OrderTotalReconciler::assertConsistent()` is the cutover"*. The
infrastructure is built and the writers have not been moved onto it. **P1-ORD-02** (#21) is the task
that both finishes the cutover and asserts it, and it is **owner-independent** with **no
dependencies**.

**P1-ORD-01** (#20) — the coupon defect — *is* owner-gated, because fixing it changes charged amounts.
But it `depends_on: [P1-ORD-02]` in the ledger, and that ordering is right: a coupon fix applied
before every writer shares one total is a fix applied nine times.

**Recommendation: do P1-ORD-02 now** (section D lists it as mandatory), so that when the owner
accepts the coupon behaviour change there is one place to make it.

---

## G. Production security and operational checklist

Everything here is for someone with production access. **Nothing in this list was performed in this
session**, and several items could not be: there is no database and no server access in this
container.

### G.1 Before anything else — containment, in this order

1. **Census `shetabit_visits` read-only.** `SELECT count(*)`, min/max `created_at`, and
   `count(*) WHERE method = 'POST'`. Then `count(*) WHERE visitor_type = 'App\Models\User'` for the
   admin-cookie cohort. *This is itself an owner-approved action.* (P1-OWN-08)
2. **Rotate the platform's own keys** — reCAPTCHA secret and OpenAI key(s). Unilateral, no merchant
   action, and the OpenAI key is a direct billing exposure. (P1-OWN-09)
3. **Check whether the BackupRestore module wrote a dump.** Look under
   `storage/uploads/Database_Backup` for any `mysqldump` output; the path is web-reachable and the
   module chmods it 0777. Any file there is a full-database disclosure. (P1-OWN-01, P1-OWN-08)
4. **Remove the `add_on_managers` row for BackupRestore and for LotteryPrizes**, then confirm with a
   request that their routes are gone. A `is_enable = 0` row still registers every route —
   `loadDynamicAddons()` has no `is_enable` predicate. (P1-OWN-01, P1-INV-20/21/22)
5. **Purge the `api-*.log` files and their backups.** They hold up to 15 days of plaintext
   passwords, OTPs, bearer tokens and gateway secrets. (P1-OWN-03, P1-OWN-09)

### G.2 Access-log checks — read-only, and the only possible evidence of exploitation

Absence of a hit is not proof of safety; the retention window may be shorter than the exposure.

| what to grep | what it would mean |
|---|---|
| `POST /api/*/payment-list` from sessions that are not the store's own shoppers | gateway secrets were harvested (P1-OWN-09) |
| `POST */get-massage` | the anonymous Telegram relay was used (P1-SEC-22) |
| `POST /setting-form` with `tab_type=chat_gpt_setting` from a non-operator session | the platform OpenAI key was read (P1-OWN-09) |
| the module `*-telegram` / `*-whatsapp` routes | merchant bot tokens were read |
| `POST */cart-clear`, `*/cart-remove`, `*/change-cart`, and customer logins with no `cart` cookie | baskets were cleared or reassigned (P1-SEC-24) |
| `GET /duologin*` | the authentication bypass was used (P1-SEC-35, **critical**) |

### G.3 Secrets and tokens

6. **Inventory every encrypted-cast column before touching APP_KEY.** Rotating without a
   re-encryption pass breaks all of them. (P1-OWN-02)
7. **Provision the Zid secret in the environment before moving it out of config**, not after.
8. **Narrow Sanctum abilities away from `['*']`.** This half does not break installed binaries.
   `CheckAbilities` is already imported in `routes/api.php` and unused — wiring it up is the
   mechanism. (P1-OWN-04)
9. **Do not add token expiry until a refresh path ships.** There is none today, and the Flutter
   client force-logs-out on any 401.

### G.4 Schema — each step needs an approved recovery plan first

10. P1-MIG-02 (`customers.password_otp`), then P1-MIG-03 (cost inputs), then the COD timing
    decision, then P1-MIG-01 (the six money tables), then P1-MIG-04.
11. **Verify each on real MySQL**, following
    `tests/Support/Security/bin/verify-sec13-migration-on-mysql.php` (it creates and drops its own
    throwaway database). SQLite's type affinity is exactly what hid P1-MIG-02.
12. **Do not release GATE F while COD settles at order time.** The ledger would be wrong from its
    first row.

### G.5 Repository and CI

13. **Branch protection on `main`:** require the Feature-suite known-outcomes gate, the WorkDo
    guard, the GATE F containment guard and the secret scanner; forbid force-push. Without this,
    every guard this phase built is advisory. (P1-OWN-06)
14. **Remove the 407 committed uploads from the working tree and gitignore the path.** Judge
    separately whether any of them (the receipt in particular) contains PII that obliges a history
    rewrite. (P1-OWN-10)
15. **Three read-only queries on the live `settings` table:** its real indexes, hosts held by more
    than one store, and rows renamed by a case variant. The table has **no unique index**, readers
    take the newest row and `updateOrCreate` matches the oldest — so a duplicate is a silent
    data-loss mechanism, and whether duplicates exist in production is unknown here. (P1-OWN-11)

### G.6 Operational posture

16. **Log module-load failures and rethrow outside production.** A module that fails to load
    currently fails silently. (P1-PLT-04)
17. **Decide a channel and retention for the security log lines this phase added**, and review
    LaraBug's blacklist before anyone enables it: the reporter posts to
    `https://www.larabug.com/api/log` whether or not `LB_KEY` is set, and its payload includes
    decrypted cookies, `Session::all()`, `Request::all()` and `auth()->user()->toArray()`, while its
    5-name blacklist misses `cookie`, `*session*`, `*secret*` and every card-number field.
    (P1-PLT-05, P1-SEC-14)
18. **Keep `Crypt::encrypt()` out of any new authorization decision** until APP_KEY is rotated. It is
    an encoding today, not a control.

---

## H. Verification — what was run, on what engine, and what could not be run

Every row in H.1 and H.2 was executed in this session. Nothing in this section is inferred from
reading.

### H.1 Suites

| what | command | result |
|---|---|---|
| The whole Security directory | `php artisan test tests/Feature/Security/` | **3545 passed, 4 skipped, 15344 assertions, exit 0** · 616s |
| P1-INV-10's new file | `php artisan test tests/Feature/Security/CatalogueEndpointIntegrityTest.php` | **9 passed, 29 assertions** |
| P1-INV-10's additions to the ownership file | `php artisan test tests/Feature/Security/MerchantProductOwnershipTest.php` | **22 passed, 51 assertions** (9 pre-existing + 13 new) |
| P1-PAY-01's file | `php artisan test tests/Feature/Commerce/` | **443 passed, 7 skipped** |
| P1-SEC-05's file | `php artisan test tests/Unit/Ci/SecretScannerCoverageTest.php` | **10 passed, 40 assertions** |
| Full gate (Unit + Feature + Contract + known-outcomes + four guards + scanner + contrast) | see H.4 | **PASS on the second run; the first run FAILED and caught a real regression** |

**Engine: SQLite `:memory:` with `CACHE_STORE=array`**, as `phpunit.xml` pins. Production is MySQL
with `CACHE_STORE=database`. That difference matters and section H.3 says where.

### H.2 Removal checks — "a test must bite"

Run with `docs/development/phase-1/tools/mutation-harness.py`, one textual mutation at a time, test
file, restore, with a SHA comparison of every touched file before and after.

**P1-INV-10 — 17 mutants, 17 bite.** Two survived the first pass and both were real test gaps, not
equivalent mutants; both were closed with a test and re-run.

| mutant | what it reverts | result |
|---|---|---|
| A | (c) the store predicate removed from the option lookup | **BITES** — *another stores attribute option contributes no terms* |
| B | (c) predicate kept, the empty-current-store guard removed | **SURVIVED → BITES** after a test was added (below) |
| C | (c-new) the empty-group guard removed, restoring the pre-existing view 500 | **BITES** |
| D | (e) `file_delete`'s permission limb removed | **BITES** |
| E | (e) `attribute_combination_edit`'s permission limb removed | **BITES** |
| F | (e) `product_attribute_delete`'s permission limb removed | **BITES** |
| **G** | **(e) the operator exemption dropped — `isAbleTo` alone** | **BITES, 3 tests at once** — all three platform-operator positive controls fail together. This is the lockout mutant and it is the most important row in the table. |
| H | (e) the render limb narrowed to `Edit Products` only | **BITES** — *manage products alone opens the combination screen* |
| I | (e) the permission limb moved after `findOrFail` | **SURVIVED → BITES** after a test was added (below) |
| J | (a) `AddVariant`'s refusal reverted to the `name`/`type` write | **BITES** |
| K | (a-new) `UpdateVariant`'s `type` write restored | **BITES** |
| L | (a-new) `UpdateVariant`'s `name → variant` write removed | **BITES** |
| M | (a-new) `UpdateVariant`'s tenant predicate removed | **BITES** — the positive control that the repair did not widen P1-SEC-04's predicate |
| N | (b) the `sku_combination_edit` dead route restored | **BITES, 2 tests** — the declared set and the by-name assertion |
| O | (b) the `addToCartVariant` dead route restored | **BITES, 2 tests** |
| P | (d) `DeleteProduct_backup` restored with its unscoped per-row lookups | **BITES** |
| Q | (d) `DeleteStore_backup` given a bare `$request->` read | **BITES** — the kept-methods ratchet |

**The two survivors, and why they were gaps rather than equivalents.**

*Mutant B* removed the `empty($currentStore)` guard while leaving the `where('store_id', $currentStore)`
predicate — and all 22 tests still passed, because **not one of them drove a principal with no
current store.** That is precisely the shape the guard exists for: Laravel converts
`where($column, null)` into `whereNull`, so a store-less caller would have matched every option row
whose own `store_id` is NULL. Fail *open*. Closed by
`test_a_store_less_session_gets_no_option_terms`, whose fixture is the delicate part —
`getCurrentStore()` falls back to `Store::where('created_by', $user->id)->first()` when
`current_store` is empty, so the principal has to be a merchant who owns **no** store, and the test
asserts that premise rather than assuming it.

*Mutant I* moved the permission limb to after `findOrFail($id)` and all 22 tests still passed,
because the refusal test drives an **existing** variant (403 either way) and the 404 test drives a
**permitted** caller (404 either way). Only the fourth combination — no permission, absent id —
separates the two orderings, and without it 403-vs-404 is an enumeration oracle. Closed by
`test_an_unpermitted_caller_gets_403_not_404_for_a_missing_variant`.

**File integrity.** `sha256sum` before and after all 17 runs, on all three mutated files
(`ProductController.php`, `Api/DashboardController.php`, `routes/web.php`): identical, and `cmp`
against independent pre-run copies confirms it. The harness's own "files restored exactly: YES"
agrees.

**P1-SEC-05 and P1-PAY-01** each had 4/4 mutants bite, recorded earlier in this session with the
same SHA discipline.

**A methodological note worth more than the table.** The first attempt at these ran in a separate
`git worktree` with `vendor/` symlinked to the main checkout, so that the 25-agent read-only audit
running concurrently would never see mutated source. **All nine mutants reported SURVIVED** — which
would have read as nine test gaps. They were not. Composer's classmap resolves `$baseDir` from
`dirname($vendorDir)`, and PHP resolves the symlink, so `$baseDir` was the **main** checkout: the
worktree's tests were loading the main tree's unmutated controllers the whole time. The worktree was
discarded and the mutants were re-run in the real tree once the audit finished. **It is recorded
here because a harness that silently tests the wrong files produces exactly the output a thorough
run produces**, and the only thing that distinguished them was that nine-for-nine survival is
implausible.

### H.3 What was NOT verified, and why

These are not failures. They are measurements that this environment cannot take, and they are
listed so that nobody reads section I's PASS marks as covering them.

| # | what | why not | where it must happen |
|---|---|---|---|
| 1 | **Criterion E3** — two concurrent checkouts of the last unit cannot oversell | **SQLite cannot show it.** Row-level contention between two simultaneous transactions *is* the behaviour under test, and SQLite serialises writers. | A real MySQL. **No script covers stock oversell** — `tests/Support/Security/bin/` holds 23 files, 15 of them `verify-*-on-mysql.php`, and the closest is `verify-refund-freshness-lock-and-limiter-keys-on-mysql.php`, which already drives **two concurrent requests against a `FOR UPDATE` row lock** for refund freshness. **That is the pattern to copy** — E3 needs the same shape pointed at `ProductStockService`. |
| 2 | **Criterion F4** — schema-sensitive evidence verified on real MySQL | **There is no MySQL in this container.** `command -v mysql mysqld mariadb` returns nothing — confirmed twice in this session. Earlier sessions had MySQL 8.0.46, which is where the 15 existing scripts were run. | Anywhere with a MySQL; `run-mysql-verifications.sh` drives them and each creates and drops its own throwaway database. |
| 3 | Any migration's behaviour on MySQL (P1-MIG-01…04) | Same as 2, plus the protocol forbids running migrations against a persistent database | Owner-approved, with a recovery plan |
| 4 | Verified online-payment settlement | **No gateway credentials, and none should be in this environment.** NMI is the only writer with a recorded verification and that recording was made elsewhere. | A staging environment with sandbox credentials |
| 5 | Production data state (P1-OWN-03, -07, -08, -09, -11) | No production access, and the protocol forbids modifying live data | The owner, or someone with server access |
| 6 | Arabic (RTL) / English (LTR) rendering and contrast | **No UI changed in this session.** P1-INV-10 touched controllers, routes and tests only; the one Blade file in its blast radius (`attribute_combinations.blade.php`) was *not* edited — the empty-group fix was made in the controller precisely so the view did not have to change. | Phase 02, where it should be a gate rather than a review habit |
| 7 | Lynomia integration contract | The Contract suite replays a committed artifact of Lynomia's producer output; **it does not reach a live Lynomia.** | A live integration test, which needs the other side |

**A simulated payment is never reported here as a verified settlement.** Nothing in this session
drove a real gateway.

### H.4 The mandatory gate

**The gate was run twice, and the first run is the more informative one.**

**Run 1** (on the tree before the last correction) — Feature: **3 failed, 17 skipped, 5223 passed,
27111 assertions**, 903s. The known-outcome gate **FAILED, exit 1**, with one undeclared failure:

```
NEW FAILURE, not in the manifest:
  ApiStoreOwnershipPredicateTest::test_resolve_owned_store_carries_the_tenancy_decision_for_the_whole_admin_api
  resolveOwnedStore() is called less than the 94 occurrences measured after this change (74 before
  it). Either call sites were deleted or some went back to inlining a predicate.
  Failed asserting that 92 is equal to 94 or is greater than 94.
```

**It was right to fire.** P1-INV-10 (d) deleted `ViewOrder_backup` and `DeleteProduct_backup`, and
each contained one `resolveOwnedStore()` call — 94 → 92, measured on comment-stripped source, a
delta of exactly two. A bare count cannot distinguish *"two dead methods were deleted"* from *"two
endpoints went back to inlining a predicate"*, which is what its own failure message says.

The floor was lowered to **92** and the reason recorded in the test, by name, in the terms the
project requires: both methods were unrouted and unreferenced, and **both called
`resolveOwnedStore()` while leaving their per-row lookups unscoped** — so each held one call that
satisfied this counter and a cross-tenant hole that did not. Holding the floor at 94 would mean
keeping two latent cross-tenant holes alive to satisfy a proxy measure. The comment also records
what the real control is: the count was never it. The spelling-agnostic
`Store::where('id',…)->where('created_by', <not creatorId()>)` scan in the same file (which pins
the one deliberate survivor to `DeleteStore` by byte offset) and
`test_every_api_method_that_reads_a_request_store_id_also_proves_ownership` are what stop an
endpoint inlining a predicate, and they key on the *request read*, not on the call.

**And the lowered floor was proved still to bite:** mutant R replaces `VariantList`'s
`resolveOwnedStore($request->store_id)` with an inline `Store::where('id', …)->first()` — the exact
regression the gate exists for — and the test fails. That makes **18 mutants, 18 bite** for this
range.

**This is recorded at length because it is the one place in this session where a CI guard was
changed.** The project rule is "do not weaken a CI guard to get green", and the distinction that
makes this not a weakening is that the removed code was *less* safe than the counter implied, the
delta is exactly accounted for, the reason is in the test rather than in a commit message, and the
guard still fails on the defect it was written for.

**Run 2** (the committed tree):

| check | result |
|---|---|
| Unit | **349 passed, 1827 assertions** |
| Contract | **26 passed, 83 assertions** |
| Feature | **5224 passed, 2 failed, 17 skipped, 27113 assertions**, 901s |
| `assert-known-test-outcomes.php` | **exit 0** — *"the observed outcome set matches the manifest exactly: 2 declared failures, 17 declared skips, 0 errors, and nothing undeclared"* |
| `.github/known-test-outcomes.json` | **unmodified** |

The gate's own words are worth keeping, because they are the correct reading of that exit code:
*"This is NOT a statement that the suite is green. It is a statement that the suite is failing and
skipping exactly the tests the project has recorded, for the reasons it has recorded."* **15 of
those 17 skips name one of the six GATE F money tables** — which is why releasing GATE F (section E,
group 7) is what converts them into assertions, and why criterion A3 cannot be met before it.

**Guards and scanners, from run 1 — unaffected by the one test-file correction between the runs:**

| check | result |
|---|---|
| `guard-no-workdo-changes.sh` | **exit 0** — no changes under `packages/workdo/` outside first-party modules |
| `guard-gate-f-containment.sh` | **exit 0** — 235 migration files scanned across both trees; **0 of the 6 money tables** created by any discoverable migration, all 6 still have a parked non-runnable draft |
| `guard-test-isolation.sh` | **exit 0** — `phpunit.xml` pins the suite to sqlite `:memory:` |
| `guard-no-env-files.sh` | **exit 0** — no live `.env` in the checkout or the index |
| `scan-secrets.sh` | **exit 0 — and that pass was worthless, which this table said at the time and CI then proved.** See H.7. |
| `check-contrast.py` | **exit 0** — **all 40 pairs pass, light and dark**; the `auto` scope is identical to `dark` across 35 tokens |
| `.github/known-test-outcomes.json` | **unmodified** (`git diff --stat` empty) |

### H.5 Unresolved findings carried out of this session

| finding | where it is recorded | status |
|---|---|---|
| 67 host-app routes answer 500 (58 resource actions + 9 named) | section D.3.1, and held by a both-directions CI ratchet | **open, low, owner-independent** |
| `ProductController::attribute_combination` (POST) still has no permission limb | section D.3.2 | **open, low, owner-independent** |
| `settings` has no unique index, so readers take the newest row and `updateOrCreate` matches the oldest | P1-OWN-11 (#106); the application side was closed by P1-PLT-07 | **open, needs a read-only production query** |
| `P1-SEC-26`'s remaining `storage_setting` question | #99 | **open, owner-gated** |
| `#66 P1-TOOL-01` has no ledger row and its subject is already decided | section B | **housekeeping; recommend the owner closes it** |

### H.6 The adjacent findings worth acting on

The 25 audits raised **98 findings outside their own tasks' scopes**. All 98 are committed verbatim
in [`P01-IN-REVIEW-AUDIT-FINDINGS.md`](P01-IN-REVIEW-AUDIT-FINDINGS.md) §3 — not summarised, because
an audit finding that is summarised and then lost is an audit that has to be run again. These are
the ones this session judged worth the owner's attention, and the first was **re-verified
independently here rather than taken on the agent's word.**

**1. A live SSRF that is in no ledger row at all. VERIFIED IN THIS SESSION.**
`app/Http/Controllers/WoocomCategoryController.php:102`:

```php
$woocommerce_store_url = Utility::GetValueByName('woocommerce_store_url', getCurrentStore());
$response = \Http::get($woocommerce_store_url . '/wp-json/wc/v3/products/categories');
```

A merchant-typed setting concatenated into an outbound request with **no scheme, port, IP-range,
redirect or timeout guard**. Reachable: `Route::resource('woocom_category')` at `routes/web.php:943`,
and `store()` exists (it is not among the 67 dead actions in D.3.1).

Three things measured here make it worth doing rather than filing:

- It is the **only** `Http::get()` in the host app that fetches a caller-influenced URL. The other
  two (`PaymentService:1435`, `IyziPayController:60`) fetch a hard-coded `https://ipinfo.io/`.
- **The guard already exists and is already used in this very file** —
  `SafeRemoteFetch::head()` at `:138` and `:232`. This one site was missed, not deliberately
  exempted.
- It is **blind**: `store()` discards `$jsonData` and returns nothing, so no response body reaches
  the caller. That lowers it to **MEDIUM** — authenticated, merchant-scoped, no reflection — but the
  request is still issued from the server, so internal hosts remain probeable by timing and error
  behaviour.

*Recommendation: fold into `P1-SEC-06` (#55), which the audit returned to READY anyway.* One call
changed to the service beside it. The audit also reports `StoreController::pwaSetting` in the same
class; that one was not re-verified here.

**2. `P1-SEC-06`'s own fix was HIGH-broken and the row credits the wrong commit.** A later read-only
review (`c894a5c7`) found that the pinned GET was sent with `stream => true`, so Guzzle's
StreamHandler ignored the curl array and **the `CURLOPT_RESOLVE` pin was absent from the request
that returns the bytes** — DNS rebinding straight past two of the row's criteria — and that the sink
wrote bytes under the name *and extension the remote URL chose*, into the web root. Both are fixed
on the branch; the row's `evidence` names only the commit whose fix was broken. **The remediation is
sound and the record is not** — which is the same A2/A3 bookkeeping problem as section I.

**3. `P1-SEC-09`'s owner limb is wider than its own compatibility text says.**
`ReportPermission.php:59` admits any principal whose `creatorId() === id`, and `User::creatorId()`
returns `$this->id` for type `admin` **and** `super admin` — so a tenant owner holding **none** of
the three report permissions still reaches all 27 report handlers. Separately, every admission
assertion in `ReportAuthorizationTest` is `assertFalse($this->refused($response))`, and `refused()`
is 403-or-redirect-with-error — **so a 500 passes as an admission.** That is failure mode 1 from the
project skill, in a test written to prove the opposite.

**4. Two operator lockouts of the class Phase 01 has been closing all along.**
`OrderController::order_grid_view` (`:1561-1570`) still does a bare
`isAbleTo('Manage Products')` with no operator limb (availability only — the query is
tenant-scoped), and `OrderDocumentAccessGuard`/`OrderPolicy` still compare `type === 'super admin'`
strictly while `OrderController` normalises, so an operator row stored with different casing is
refused by whichever guard runs first.

**5. `OrderDocumentAccessGuard` is missing its inverse census.** The guard is **URI-keyed**, over
handlers that have no in-handler check of their own — `Order::order_detail` is a bare `Order::find()`
and `Order` is not in `scoped_models`. A test asserts the guard is in the stack for four *named*
module routes; **nothing asserts that every route whose action is one of those three handlers appears
in `TARGETS`.** So any new route onto them is unguarded by construction. That is the regression pin
this family lacks, and it is the same census-failure mode as D.3.1.

**6. `P1-SEC-34`: comment attribution can cross stores by e-mail.**
`ModuleStorefrontInputGuard.php:115-126` resolves a shopper by e-mail, and customer registration is
unverified and unique only per `(email, store_id)` — so a comment can be attributed to another
store's shopper row. Reachable, untested.

**7. Two gate weaknesses in `P1-SEC-04`, which is otherwise the strongest DONE in the table.** The
route-census gate enforces that every unauthenticated module route **is** classified, but does not
machine-check that a classification is *correct in the public direction* — only one direction is
checked. And `route-census.php:57-66` counts `custom.auth` as authentication, so a module route
carrying only `custom.auth` would be exempt from classification **even though that is exactly
CLAUDE.md's guard-class trap** — a merchant's or driver's token passes `custom.auth` while
`auth('customers')` stays null. Latent today; it is the shape that would make the gate blind to the
next instance of this project's most productive defect pattern.

**Recommendation on all seven: one new issue, not seven.** Items 2–7 are bookkeeping and test-quality
corrections on tasks that already have issues, and belong as comments on those issues; item 1 folds
into #55. Avoiding issue proliferation was an explicit instruction, and these are the case it was
written for.

### H.7 The one thing in this report that was predicted in writing and then happened

**This section exists because it is the strongest evidence in the report for the discipline the rest
of it is written in: recording what you did *not* verify.**

The guard table in H.4 originally read, of the secret scanner: *"exit 0 — SKIP: no base ref given,
so this run scanned no diff. CI gives it a base ref; the local run is therefore not evidence about
the diff, and that is stated rather than counted as a pass."*

**Then the diff it had not scanned went red.** CI run 39835585655, *Secret scan*, exit 1:

```
HIT [aws-access-key-id] tests/Unit/Ci/SecretScannerCoverageTest.php:160
```

Reproduced locally in one command, which is the form the gate should have been using all along:

```bash
bash .github/scripts/scan-secrets.sh "$(git merge-base origin/main HEAD)" .
```

**The hit is P1-SEC-05's own test fixture, and it is a hit by design.** Line 160 plants
`AKIAIOSFODNN7EXAMPLE` so that `test_a_high_confidence_credential_in_the_test_tree_still_fails` can
assert the build **fails** — and that assertion is the entire justification for the structural
`tests/*` skip P1-SEC-05 gave the generic rule. A narrowing is only defensible while the unnarrowed
rules still bite in the narrowed tree, and that test is what proves they do.

**The usual remedy does not exist for this file**, and the change engages with the recorded norm
rather than quietly contradicting it. `PlanPaymentFailClosedTest:476` says a flagged fixture is
*"fixed by changing the VALUE, never by widening the regex or excluding this path — those narrowings
exist precisely so a real credential cannot hide behind an exclusion."* That norm is right and still
stands for every ordinary fixture. It does not reach this one for a reason that is a property of the
rule: SenangPay's value was renameable because `generic-assigned-secret` has a self-labelling
vocabulary to be renamed *into*; `aws-access-key-id` has none and is **deliberately** exempt from the
placeholder filter as well, because — in the script's own words — suppressing a high-confidence
shape *"because the line happens to contain the word example is how a real key gets through."* **No
value is simultaneously a hit, which the test requires, and not a hit, which the build requires.**

So the fix is the exclusion the script already applies to itself, extended to one more file of the
same kind: `EXCLUDE_RE` already skips `scan-secrets.sh` and `gitleaks.toml` as *"by their nature
files full of credential-shaped patterns and documented example keys"* — verified there, because
without it the scanner reports its own comment about that same AWS key. The scanner's own **test** is
the third such file.

**And the hole is tested, because an exclusion is a hole by construction and the norm's concern is
exactly right.** Two new tests, three biting mutants:

| | |
|---|---|
| `test_the_exclusion_for_this_file_is_exactly_one_file_wide` | plants the **same** AWS key at the excluded path and at a sibling path in the same directory; the first must pass, the second must fail. **Behavioural**, not a read of the regex, because the regex's spelling is not the property that matters. |
| `test_the_exclusion_list_names_exactly_the_three_self_referential_files` | pins the list by name and count. Its own first version was wrong in an instructive way: `/\^([^\|]+?)\$/` reported one anchor where there are two, stopping at the `\|` inside `(scripts/scan-secrets\.sh\|gitleaks\.toml)`; and `/\^[^^$]*\$/` has the opposite fault, starting at the `^` inside `(^\|/)` and running into the next alternative's `$`. Only depth-aware splitting on **top-level** `\|` is right. |

Mutants: widening the exclusion to the directory fails **two** tests, removing it fails **two**, and
quietly adding a fourth path fails **one** — which is that test's only job. **21 mutants, 21 bite,
across this range.**

**And the process finding I first wrote here was wrong, which is worth more than if it had been
right.** The first version of this section said the protocol's gate recipe omits the base ref, *"so
every local gate run in this project has been scanning nothing"*, and recommended changing it. **The
protocol is correct.** `13-PHASE-01-EXECUTION-PROTOCOL.md:81` already reads

```bash
bash .github/scripts/scan-secrets.sh "$(git merge-base origin/main HEAD)" .
```

— the exact form that reproduces the failure. What omitted the base ref was **my own gate script for
this session**, which wrote `bash .github/scripts/scan-secrets.sh` bare and so silently ran the
scan-nothing path. The defect was not following the documented recipe.

That is the more useful finding, and the less flattering one: **a guard can be documented correctly
and still be bypassed by the person re-typing it from memory into an ad-hoc script.** The protocol
needs no change. What would actually prevent a recurrence is the gate being a committed script that
is run rather than a code block that is copied — the same reasoning that put `ledger-tool.py` in the
repository instead of leaving the ledger to be hand-edited. **Recommended as a small Phase 01 item:
move §4's block into `.github/scripts/local-gate.sh` and have the protocol tell a session to run
that.** Not done here, because changing the project's gate definition is wider than this task.

---

## I. The Phase 01 acceptance matrix

Every criterion from `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md`, marked **PASS**
(shown, by execution in this session or by evidence on disk that this session read), **BLOCKED**
(waiting on an owner decision), **FAIL** (measured and not met), **PARTIAL** (one half met, the other
named) or **NOT VERIFIED** (no measurement exists, and this environment cannot take it).

**A PASS here means the criterion is shown, not that the subject is beyond criticism.** Where a
criterion passed because a test exists and I ran it, the test file is named.

### A. Task completion

| # | criterion | mark | basis |
|---|---|---|---|
| A1 | Every task is DONE, or BLOCKED with a named owner decision | **FAIL** | After this session's reclassification: **48 DONE, 17 READY, 9 BACKLOG, 31 BLOCKED** — so **26 of 105 tasks are neither DONE nor BLOCKED-with-a-named-decision**. The audit moved 15 tasks out of IN_REVIEW into READY, which is why the READY count went up rather than down. |
| A2 | No task is DONE on a commit alone | **PASS** *(with a qualification)* | Checked programmatically: **0 of the 39 DONE rows has an empty `evidence` field**, and all 105 rows carry an issue number. **The qualification:** for `P1-INV-11` and `P1-ORD-12` the audit found the *measured numbers* — the mutation counts — appear only in commit messages, with no on-disk record. `docs/security/P1-INV-14-MODULE-ORDER-MUTATION-VERIFICATION.md` shows the pattern that makes such a claim checkable; two rows should follow it. |
| A3 | Every corrective task is linked to the work it corrects | **FAIL** | **8 of 105 rows carry no `queue_refs`**: P1-INV-21, P1-INV-22, P1-INV-23, P1-SEC-07, P1-SEC-33, P1-SEC-34, P1-SEC-35, P1-TST-01. All eight have issue numbers, so the issue half of the cross-link holds and only the ledger half is missing. Cheap to fix. |

### B. Security and identity

| # | criterion | mark | basis |
|---|---|---|---|
| B1 | No handler derives its subject, store, price or quantity from caller input unchecked | **FAIL** | `AccountProfileController::edit_address_form` (:1533) still resolves the address from caller input with no ownership check, and it is the handler the **live** address book calls (`addressbook.blade.php:34`). Five of the six address sites went through `ownedAddress()`; this one did not. (P1-SEC-12, #84) |
| B2 | Cross-tenant reads and writes refused on every reachable path found in Phase 01 | **FAIL** | Three measured exceptions: the `edit_address_form` read above; `StoredUpload::resolve()` skipping its containment rule whenever the first path segment is not numeric, so `uploads/profile/…` and the `uploads/` root remain unlinkable by any caller who can name them (P1-SEC-28, #102); and `P1-INV-03`'s POS write, which the ledger records as **confirmed by execution in an earlier session** — seeding the POS session with store B's product moved B's `product_stock` 50 → 49. *I did not re-drive it here; it is quoted as that session's measurement, not mine.* |
| B3 | Merchant, customer and driver token boundaries hold under the `custom.auth` guard-class trap | **PASS** | `ResidualSafetyCorrectionsTest::test_neither_a_merchant_nor_a_driver_token_can_enrol_a_user` (:115-132) drives a **merchant token and a driver token** through the same endpoint and asserts zero rows written; `AuctionBidForgeryTest:802` acts as the `deliveryboy` guard directly. Ran green in this session's Security suite. |
| B4 | Role and permission escalation refused, including the finance-permission denylist gap | **PASS** | P1-SEC-02 is DONE and closed; `FinancePermission`'s operator-first ordering is the recorded fix for the six unseeded finance names. Covered by the green Security suite. |
| B5 | Session hygiene: logout symmetry, regeneration, `same_site`, CSRF path-matching, MFA ordering | **PASS** | P1-SEC-01 DONE and closed; its tests are inside the 3545 that passed here. I did not single out the locale-specific cases, so this rests on the suite rather than on a targeted run. |
| B6 | Every remaining security row closed with evidence or recorded as an owner decision | **FAIL** | `P1-SEC-22` (#96) has **four criteria that are neither**: the Marketing `business_password` (untraced, unchanged at `theme_script.blade.php:108`), `telegram_chat_id` absent from `GatewaySecretRedactor`, `POST generate/response` unauthorised and unthrottled, and Khalti's return not clearing a guest cookie basket. |

### C. Orders, checkout and monetary integrity

| # | criterion | mark | basis |
|---|---|---|---|
| C1 | One server-authoritative total per writer; no posted money field reaches a persisted total | **FAIL** | The seam exists (`OrderTotalReconciler`, 387 lines) but **the cutover onto it is incomplete and the code says so**: outside the class, the invoked method is overwhelmingly its static `amountsMatch()` comparison helper, and two comments in `PaymentService` and `PaymentController` name `assertConsistent()` as "the cutover" at sites that do not call it. Nothing asserts every writer derives its total through the seam — that survey test is `P1-ORD-02` (#21), class A in section D. |
| C2 | A coupon that is printed is a coupon that is charged | **BLOCKED** | `P1-ORD-01` (#20) changes charged amounts and needs explicit owner acceptance. It also depends on C1. |
| C3 | Invalid quantities refused at both ends — non-positive **and** above the sanity ceiling | **PARTIAL** | The primitive half is proven and ran green here: `OrderStockConcurrencyTest::test_a_negative_quantity_cannot_mint_stock` and `::test_zero_and_nonnumeric_quantities_are_noops`. The **ceiling** half is not: `ProductController::updateCart`'s own overflow guard is structurally dead — it compares `isset(...)`, a **bool**, against a quantity — and fixing it is still open as part of `P1-INV-03` (#29). |
| C4 | No partial write: a refused basket leaves no order, no stock movement, no orphan decrement | **PASS** | `OrderWriteTransactionTest`, `CheckoutCartPreservationTest` and `CheckoutPreflightStockTest` are on disk and inside the green Commerce run (443 passed / 7 skipped). I did not re-drive the orphan-decrement fixture individually, so this rests on the suite. |
| C5 | Returns and refunds restore exactly what the order took, and only for the owning store | **FAIL** | `OrderRefundController::restorationPlan()` applies its read-side bound **only** in the `$counted = true` pass; when no counted line fits, the `$counted = false` pass places the entry on any line of the same product with no bound at all — and that state is ordinary (a product on two lines). `unitsLostByProduct()` returns `null`, i.e. no clamp, in the same shape. (P1-ORD-14, #83) |

### D. Payments and financial integrity

| # | criterion | mark | basis |
|---|---|---|---|
| D1 | Payment state derived, never asserted from a browser return | **PASS** | `PaymentVerificationService` (583 lines) is the derivation seam, every unverified `Paid` write is logged, and `PaymentStatusDerivationTest`, `PaymentStatusNormalizationTest` and `GatewayReturnTrustBoundaryTest` ran green here. **Note the scope:** *derived* is not *verified against the provider* — that is D4. |
| D2 | Replay of a gateway return cannot produce a second order or a second charge | **FAIL** | **No idempotency key exists anywhere** (`P1-PAY-02`, #24); replay is blocked only because the stored state happens to be single-use. One gateway is an exception — `PaymeraCallbackIdempotencyTest` is on disk and green — so the criterion fails for the general case, not for every writer. Section F.3 gives a form that needs no new table. |
| D3 | COD settlement timing explicit and owner-approved | **BLOCKED** | `P1-PAY-04` (#26). The single highest-leverage decision in this report: it also blocks GATE F. |
| D4 | Gateway callback authenticity enforced for every gateway the owner elects | **BLOCKED** | `P1-PAY-03` (#25). **NMI is the only writer backed by a recorded verification**; the other 51 methods write `Paid` on the return's own word. Needs the owner's gateway list and provider credentials. |
| D5 | GATE F readiness: released with a recovery plan, **or** contained with the containment proven | **PASS** *(the containment limb)* | `guard-gate-f-containment.sh` runs in the mandatory gate, the drafts are unrunnable in `_gate_f_pending/`, and no runnable migration exists that the owner has not released. **Release is BLOCKED** (Group 7). |

### E. Inventory and stock

| # | criterion | mark | basis |
|---|---|---|---|
| E1 | Product and variant ownership checked on every reachable mutation | **FAIL** | `App\Models\Product` is absent from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and **`Product` adds no predicate for any principal**; and `P1-INV-03`'s POS write is a cross-tenant stock mutation the ledger records as confirmed by execution in an earlier session (not re-driven here). The per-handler work is extensive and largely done — P1-INV-10 closed the last three catalogue routes' permission limbs in this session — but the criterion says *every reachable mutation*. |
| E2 | `stock_management` ON enforces, OFF does not deduct, on every writer | **PASS** | `tests/Feature/Commerce/StockManagementSettingTest.php` — **40 passed / 63 assertions**, executed in this session. The criterion's own "40 cases over four settings states" is exactly what ran. |
| E3 | Concurrent checkout of the last unit cannot oversell | **PARTIAL / NOT VERIFIED as written** | The **property** is proven and ran green here (11 passed / 58 assertions): `OrderStockConcurrencyTest::test_ten_concurrent_checkouts_cannot_oversell_five_units` gives ten callers the stale read *before any of them writes* — the real interleaving — and asserts exactly 5 applied, 5 rejected, 0 remaining; `::test_the_decrement_is_a_single_guarded_update_statement` asserts the **mechanism** directly from the query log (exactly one UPDATE, the arithmetic computed in SQL, the availability guard in that same WHERE). **What is NOT verified is the criterion's own requirement — "on MySQL".** True simultaneous transactions, row locks and `FOR UPDATE` semantics cannot be shown on SQLite, and there is no MySQL in this container. See H.3. |
| E4 | Cancellation and return restore stock exactly | **PARTIAL** | Cancellation is proven: `OrderCancelRestockTest`, `ReturnApprovalStockTest` and `RefundStockIdempotencyTest` ran green in the Commerce suite. The **refund-by-line** half fails for the reason given at C5. |

### F. Platform foundation

| # | criterion | mark | basis |
|---|---|---|---|
| F1 | CI gates run on both event classes, or are explicitly report-only | **PASS** | `RequiredGateWiringTest`, `RegressionGateTest` and `SensitiveDiffGateTest` are in `tests/Unit/Ci/` and inside the **349 passed / 1827 assertions** executed here. |
| F2 | The known-outcome gate exits 0 with the declared set unchanged and the manifest unmodified | **PASS** | The manifest is **unmodified** — `git diff --stat .github/known-test-outcomes.json` is empty, which is the half of this criterion that matters most, because the forbidden move is adding an entry to obtain green. The declared set is unchanged: **17 skips observed, 17 declared**, and the failures are back to the **2 declared** after the correction in H.4. The gate ran twice and the first run is recorded in full there; it failed, and it failed on a real regression rather than on a manifest edit. |
| F3 | Unit, Feature and Contract suites all run, Contract pinning the Flutter and Lynomia contracts | **PASS** | Executed here: **Unit 349 passed / 1827 assertions**; **Contract 26 passed / 83 assertions**; Feature in the gate below. |
| F4 | Schema-sensitive evidence verified on **real MySQL**, not SQLite | **NOT VERIFIED** | `command -v mysql mysqld mariadb` returns nothing in this container — confirmed twice. The 15 `verify-*-on-mysql.php` scripts exist and were run in an earlier session on MySQL 8.0.46; none was re-run here, and none covers E3. |
| F5 | Migration readiness: every parked draft reviewed, nothing runnable the owner has not released | **PASS** | `guard-gate-f-containment.sh` exit 0 in this session: **235 migration files scanned across both trees, 0 of the 6 money tables created by any discoverable migration, and all 6 still carry a parked non-runnable draft.** Nothing runnable exists that the owner has not released. The *review* half rests on the drafts in `_gate_f_pending/` and `_sec13_pending/` and their READMEs, which this session read but did not re-review line by line. |
| F6 | Design System foundation intact and contrast-gated | **PASS** | `docs/design-system/tools/check-contrast.py` is wired into `.github/workflows/ci.yml:576` against `public/css/store-syria-ds.css` — it resolves the `--ss-*` `var()` chains and computes real WCAG 2.1 ratios for 40 foreground/background pairs across light and dark, and fails if the `auto` scope drifts from `dark`. The browser half (`browser-checks.cjs`) is deliberately a documented manual run, which the workflow states in a comment rather than leaving implicit. |
| F7 | Observability: the security log lines are enumerated and reach a channel an operator reads | **FAIL** | `P1-PLT-05` (#37) is BACKLOG, classified **B** in section D. The log lines exist and are tested; nothing enumerates them in one place and nothing asserts an operator can see them. |

### G. Phase-wide verification (the directive's §9)

**Run as one exercise: NOT DONE.** Each step is marked for what exists, because the criterion is
explicit that a sum of task tests is not the same thing.

| # | step | mark | basis |
|---|---|---|---|
| G1 | Merchant journey: create product with variants → order → fulfil → return | **NOT VERIFIED** | The segments are covered by task tests; no single test drives the whole journey. The return segment also fails at C5. |
| G2 | Customer journey: browse → cart → checkout (COD **and one online gateway**) → history → return | **NOT VERIFIED** | COD segments are covered (`CodStateMachineTest`, `CodCashCollectionTest`, green here). **The online-gateway segment cannot be driven in this environment** — no credentials, and none should be here. |
| G3 | Cross-tenant attempt at **every** step of both journeys, with merchant, customer **and driver** tokens | **PARTIAL** | The three-token discipline exists and is proven per endpoint (B3), not per journey step. |
| G4 | Checkout and payment-status consistency across the nine order writers | **PARTIAL** | `EnforcedPaymentTypesIntegrityTest` and `UnguardedOrderWriterTrustBoundaryTest` survey the writers and ran green; the **totals** survey (C1) does not exist yet. |
| G5 | Refund and inventory restoration | **FAIL** | C5 / E4. |
| G6 | Financial integrity with GATE F in whichever state the owner approved | **BLOCKED** | No state has been approved (Group 7). |
| G7 | Flutter admin and Lynomia Chat contracts | **PASS** | Contract suite **26 passed / 83 assertions**, executed here. **Its limit, stated:** it replays a committed artifact of Lynomia's producer output; it does not reach a live Lynomia. |
| G8 | Storefront and theme compatibility, Arabic (RTL) and English (LTR), contrast-checked | **NOT VERIFIED** | No UI changed in this session — P1-INV-10's empty-group fix was deliberately made in the controller so `attribute_combinations.blade.php` did not have to be touched. The token-level contrast gate (F6) is in CI; rendered-page checking is not. |
| G9 | MySQL-specific validation for anything schema-sensitive | **NOT VERIFIED** | F4. |
| G10 | CI and the security attestation on the final head | **PARTIAL** | PR #3 carries the §21 attestation for this range (posted as a comment, not a body edit — the body is 43 KB and records a classifier truncating a full-body rewrite once). **CI on the first pushed head FAILED**: *Secret scan* exit 1 on the scanner's own test fixture — see H.7, which is the finding this row is actually about. Fixed and re-pushed; the attestation runs were `success` throughout. The post-fix CI result belongs in the next update rather than being predicted here. |

### Criteria group H — "Honest limits", recorded as the criterion requires

**PASS.** All four classes are recorded, and this is the criterion that makes the rest of the matrix
readable rather than decorative:

- **Subsumed rather than independently proven** — named in the ledger and the queue; and in this
  session B5 and C4 are marked PASS on the strength of a suite run rather than a targeted drive,
  which is stated at those rows rather than hidden.
- **Unprovable in this environment** — no WorkDo module loads in a test run without an
  `add_on_managers` row; the Authorize.Net SDK and Stripe keys are unreachable; `dev.azure.com` is
  blocked; **and, new in this session, there is no MySQL at all** (F4, E3, H.3).
- **Recorded but not verified by execution** — `P1-SEC-03` and `P1-INV-04`, plus the five items in
  H.5 and the per-criterion UNPROVEN findings the audit produced for 12 reclassified tasks.
- **Owner decisions outstanding** — all 30, each with what it blocks, in section E.

---

## I.2 The IN_REVIEW acceptance matrix — all 25 tasks, reclassified

The directive's third instruction was explicit: *"Do not assume that an existing commit means a task
is DONE."* So each of the 25 `IN_REVIEW` tasks was audited independently — **25 agents, one per
task** — against that task's own acceptance criteria, with every verdict required to cite a file and
a line rather than a conclusion. This is the one place in this session where parallelism was the
right instrument: the 25 scopes are genuinely independent, and a single reader would have carried
24 tasks' worth of context into the 25th.

**The audits were read-only by construction.** No agent ran a test suite. That matters for reading
the table: an `UNPROVEN` verdict means *the acceptance criterion is not shown by what is on disk* —
which is sometimes "the test does not exist", sometimes "the test exists but asserts the wrong
thing", and sometimes "the implementation is simply absent". The audits distinguish those three in
their own basis text, and the distinction is what makes a reclassification actionable rather than
merely sceptical. Where the only gap was that an execution could not be witnessed, this session's
own suite runs (section H.1) are the corroboration: **3545 Security tests, 443 Commerce, 349 Unit
and 26 Contract all passed here.**

**What the reclassification found.** Of the 25, **9 are DONE**, **15 return to READY** and **1 is BLOCKED**. That is the headline of this report after the owner decisions themselves: **15 tasks that the ledger presented as awaiting review were not finished**, and the gap is an unmet acceptance criterion in each case, not a missing commit — every one of the 25 had its implementing commits reachable from HEAD.

The most consequential individual findings, because they are the ones that change what the owner
thinks is finished:

- **`P1-SEC-11` (#78) — `getCurrentStore()` still hard-codes `1` for the operator.**
  `app/Helper/helper.php:407-409` is unchanged, and the implementing commit's own message says so:
  *"Not done here, still READY under P1-SEC-11."* The row's alternative limb — "every caller is
  shown to be safe" — is not achievable as written: **901 call sites across 128 files.** The
  operator-definition drift the row describes is also still in all three named places
  (`OrderDocumentAccessGuard:415` and `OrderPolicy:21-26` compare `===` strictly; only
  `OrderController` normalises).
- **`P1-SEC-12` (#84) — one of the six address handlers was never fixed, and it is the one the UI
  calls.** `AccountProfileController::edit_address_form` (`:1533`) is reached from
  `addressbook.blade.php:34` and resolves the address from caller input with no ownership check,
  while the other five go through `ownedAddress()`. This is a **reachable cross-tenant read**, and
  it is the single most important thing in this table.
- **`P1-SEC-22` (#96) — four of five criteria are neither implemented nor recorded as decisions.**
  The one that matters most: `POST generate/response` (`routes/web.php:1049`) sits in the plain
  `['auth','xss','setlocate','verified']` group with **no `isAbleTo`, no Gate, no `abort` and no
  throttle** in `AITemplateController::aiGenerate` — so every merchant and every merchant's staff
  member can spend the **platform's** OpenAI budget. A throttle is the owner-independent half; who
  may call it at all is the row's own "decided and enforced" criterion and is the owner's.
- **`P1-ORD-14` (#83) — the read-side bound is bypassable in an ordinary state.**
  `OrderRefundController::restorationPlan()` applies its bound only in the `$counted = true` pass;
  the fall-through pass places the entry on any line of the same product with no bound at all, and
  `unitsLostByProduct()` returns `null` — no clamp — in the same shape. A product appearing on two
  lines is enough to reach it.
- **`P1-INV-09` (#50) — the "shared containment helper" was newly written, not promoted.**
  `StoredUpload` exists (and its own docblock calls the original a "Precedent"), but
  `ProductController::isInsideUploadsRoot()` is **still private and still the only gate** on
  `file_delete`'s unlink. Five other sites in that same controller do use `StoredUpload::delete()`.
  *(Line numbers drifted in this session: the private helper is now at `:2112` and the unlink at
  `:2197`, moved by P1-INV-10's edits to the same file.)*
- **`P1-PLT-02` (#34) — the only `BLOCKED` verdict,** and the cheapest thing in this table. The
  read-only reporting script `tests/Support/Security/bin/report-null-store-orders.php` exists and
  is provably SELECT-only by inspection, but **it has never been executed** — so the NULL-store row
  count it was written to produce does not exist. It cannot be run here either: it boots the
  framework against a real database and this container has none. **One command on a machine with
  the database closes it.**

**Two caveats on the table itself, stated so it is not over-read.** First, the "adjacent findings"
column counts issues each audit raised *outside* its task's named scope — they are not blockers for
that task's verdict and several are genuinely new. Second, for `P1-INV-11` and `P1-ORD-12` the
unproven criterion is a *mutation-count claim recorded only in a commit message*. The tests exist
and pass; what is missing is the record that the removal checks bit.
`docs/security/P1-INV-14-MODULE-ORDER-MUTATION-VERIFICATION.md` is the pattern those two rows should
follow, and this is the A2 qualification in section I.

| task | issue | verdict | severity if open | criteria (proven / unproven / owner-gated / n-a) | tests named but absent from disk | adjacent findings the audit raised | the one thing that decides the verdict |
|---|---|---|---|---|---|---|---|
| **P1-PLT-02** | [#34](https://github.com/fullstackfull/syriastore98/issues/34) | **BLOCKED** | MEDIUM | 2 / 1 / 1 / 0 | 0 | 5 | **OWNER_GATED:** Existing NULL-store rows are COUNTED and reported (not modified) |
| **P1-INV-09** | [#50](https://github.com/fullstackfull/syriastore98/issues/50) | **READY** | LOW | 4 / 1 / 0 / 0 | 0 | 3 | **UNPROVEN:** A single shared containment helper -- promoted from ProductController::isInsideUploadsRoot(), added by P1-INV-07 -- is the only thing... |
| **P1-INV-13** | [#54](https://github.com/fullstackfull/syriastore98/issues/54) | **READY** | MEDIUM | 6 / 1 / 0 / 0 | 0 | 6 | **UNPROVEN:** F2 (ShopifyProductController::edit) ownership check is exercised at runtime, with a positive control (row's tests[] asks for one per ... |
| **P1-ORD-10** | [#74](https://github.com/fullstackfull/syriastore98/issues/74) | **READY** | MEDIUM | 3 / 1 / 0 / 0 | 0 | 4 | **UNPROVEN:** The legitimate journey is byte-identical for the order's owner |
| **P1-ORD-12** | [#81](https://github.com/fullstackfull/syriastore98/issues/81) | **READY** | MEDIUM | 4 / 1 / 0 / 0 | 0 | 4 | **UNPROVEN:** (from the row's `tests` field) Removal check on the new refund-id resolution, and OrderDocumentAccessTest / OrderDocumentRouteResolut... |
| **P1-ORD-14** | [#83](https://github.com/fullstackfull/syriastore98/issues/83) | **READY** | MEDIUM | 2 / 5 / 0 / 0 | 3 | 5 | **UNPROVEN:** A recorded quantity larger than the amount the order line has ever lost is not added back (read-side bound, defence in depth) |
| **P1-PLT-06** | [#109](https://github.com/fullstackfull/syriastore98/issues/109) | **READY** | LOW | 3 / 0 / 0 / 0 | 3 | 3 | every criterion proven |
| **P1-SEC-06** | [#55](https://github.com/fullstackfull/syriastore98/issues/55) | **READY** | HIGH | 6 / 0 / 0 / 0 | 0 | 4 | every criterion proven |
| **P1-SEC-11** | [#78](https://github.com/fullstackfull/syriastore98/issues/78) | **READY** | LOW | 1 / 5 / 0 / 0 | 3 | 3 | **UNPROVEN:** A document or label for an order prints the settings of THAT ORDER'S store, not the viewer's current store and not a created_by=1 fal... |
| **P1-SEC-12** | [#84](https://github.com/fullstackfull/syriastore98/issues/84) | **READY** | MEDIUM | 1 / 3 / 0 / 0 | 0 | 5 | **UNPROVEN:** Every address handler derives the row from the signed-in shopper (id AND customer_id AND store_id) and refuses anything else uniformly |
| **P1-SEC-14** | [#89](https://github.com/fullstackfull/syriastore98/issues/89) | **READY** | LOW | 2 / 0 / 1 / 0 | 0 | 5 | **OWNER_GATED:** The owner is told whether the tracker is keyed in production, and the answer is recorded |
| **P1-SEC-16** | [#90](https://github.com/fullstackfull/syriastore98/issues/90) | **READY** | MEDIUM | 3 / 1 / 0 / 0 | 0 | 0 | **UNPROVEN:** ->withInput() calls on credential-bearing forms exclude the password fields |
| **P1-SEC-22** | [#96](https://github.com/fullstackfull/syriastore98/issues/96) | **READY** | MEDIUM | 1 / 5 / 0 / 0 | 3 | 8 | **UNPROVEN:** No handler flashes or echoes an exception object to a shopper |
| **P1-SEC-28** | [#102](https://github.com/fullstackfull/syriastore98/issues/102) | **READY** | MEDIUM | 4 / 1 / 0 / 0 | 0 | 4 | **UNPROVEN:** The files with no store segment are either given an owner or the residual is accepted in writing |
| **P1-SEC-31** | [#107](https://github.com/fullstackfull/syriastore98/issues/107) | **READY** | MEDIUM | 3 / 0 / 0 / 0 | 0 | 3 | every criterion proven |
| **P1-SEC-34** | [#112](https://github.com/fullstackfull/syriastore98/issues/112) | **READY** | MEDIUM | 4 / 1 / 0 / 0 | 0 | 7 | **UNPROVEN:** A shopper can comment only through the slug of their own store |
| **P1-INV-11** | [#52](https://github.com/fullstackfull/syriastore98/issues/52) | **DONE** | MEDIUM | 6 / 1 / 0 / 0 | 0 | 4 | **UNPROVEN:** TEST-PLAN ITEM (not in acceptance[]): removal check on the validation rule and on the cycle check, separately |
| **P1-INV-23** | [#114](https://github.com/fullstackfull/syriastore98/issues/114) | **DONE** | CRITICAL | 4 / 0 / 0 / 0 | 0 | 3 | every criterion proven |
| **P1-ORD-05** | [#59](https://github.com/fullstackfull/syriastore98/issues/59) | **DONE** | HIGH | 5 / 0 / 0 / 0 | 0 | 4 | every criterion proven |
| **P1-SEC-04** | [#18](https://github.com/fullstackfull/syriastore98/issues/18) | **DONE** | CRITICAL | 6 / 0 / 0 / 0 | 0 | 4 | every criterion proven |
| **P1-SEC-09** | [#76](https://github.com/fullstackfull/syriastore98/issues/76) | **DONE** | MEDIUM | 5 / 0 / 0 / 0 | 0 | 4 | every criterion proven |
| **P1-SEC-10** | [#77](https://github.com/fullstackfull/syriastore98/issues/77) | **DONE** | MEDIUM | 3 / 0 / 0 / 1 | 0 | 3 | every criterion proven |
| **P1-SEC-30** | [#105](https://github.com/fullstackfull/syriastore98/issues/105) | **DONE** | HIGH | 3 / 0 / 0 / 0 | 0 | 3 | every criterion proven |
| **P1-SEC-33** | [#115](https://github.com/fullstackfull/syriastore98/issues/115) | **DONE** | HIGH | 3 / 2 / 0 / 0 | 0 | 4 | **UNPROVEN:** EVIDENCE CLAIM (non-mandatory, does not gate DONE): 'WebhookSafetyTest 39' |
| **P1-SEC-35** | [#113](https://github.com/fullstackfull/syriastore98/issues/113) | **DONE** | CRITICAL | 5 / 0 / 0 / 0 | 0 | 0 | every criterion proven |

Audited: 25 of 25. Verdicts: {'DONE': 9, 'BLOCKED': 1, 'READY': 15}

**The per-criterion basis for every one of these verdicts, the auditors' own notes, and all 98 adjacent findings are committed in full at [`P01-IN-REVIEW-AUDIT-FINDINGS.md`](P01-IN-REVIEW-AUDIT-FINDINGS.md)** (309 KB — comparable to `P01-SECURITY-WORK-QUEUE.md`). It is a committed file rather than a transcript on purpose: the verdicts drove ledger status changes, so the basis for those changes has to be as durable as the changes.

---

## J. The closure plan

Finite, ordered, with the dependency that forces each position. **Steps marked ⬛ need the owner and
cannot be started by an engineering session.** Steps marked ⬜ are owner-independent and can start
now.

### Stage 0 — containment (⬛, days, no code)

| # | step | why here |
|---|---|---|
| 0.1 ⬛ | Read-only census of `shetabit_visits`; scrub `request` + `headers` on a restored copy, verify, then production | the only live credential exposure in this report |
| 0.2 ⬛ | Rotate the platform's own reCAPTCHA and OpenAI keys | unilateral, no merchant action, direct billing exposure |
| 0.3 ⬛ | Check for a BackupRestore `mysqldump` under `storage/uploads/Database_Backup`; purge the `api-*.log` files | full-database and 15-day-credential disclosures |
| 0.4 ⬛ | Remove the `add_on_managers` rows for BackupRestore and LotteryPrizes | a disabled row still registers every route |
| 0.5 ⬛ | Run the G.2 access-log greps | the result changes the statement made to merchants, even where it does not change the action |

Stage 0 is independent of everything below and should not wait for it.

### Stage 1 — owner-independent engineering (⬜, can start immediately, no decisions)

**This stage grew during this session, and that is the honest result of the audit rather than a
setback.** It was drafted against the 2 tasks the ledger carried as READY; the IN_REVIEW audit
returned **15 more**, so Stage 1 is 17 reclassified-or-ready tasks plus the 6 backlog items below.
The work was always outstanding — what changed is that it is now visible, with a named unmet
criterion each, instead of sitting in a status that reads as nearly finished.

**Order within Stage 1.** Do the reachable security gaps first, because they are the only Stage 1
items that are live defects rather than missing proof:

| # | step | why first |
|---|---|---|
| **1.0a** ⬜ | **`P1-SEC-12`** (#84) — `edit_address_form` (`AccountProfileController:1533`) | the one **reachable cross-tenant read** in the whole reclassified set, on the handler the live address book calls |
| **1.0b** ⬜ | **`P1-SEC-22`** (#96) — throttle `POST generate/response`, then the other three criteria | every merchant and staff member can spend the **platform's** OpenAI budget; the throttle is the owner-independent half |
| **1.0c** ⬜ | **`P1-ORD-14`** (#83) — bound the uncounted pass and `unitsLostByProduct()`'s null allowance | a refund can restore more stock than the order ever took, in an ordinary two-line state |
| **1.0d** ⬜ | **`P1-SEC-06`** (#55) — plus the verified `WoocomCategoryController:102` SSRF from H.6 | one call onto the service already used twice in that file |
| **1.0e** ⬜ | **`P1-SEC-11`** (#78) — `getCurrentStore()`'s hard-coded `1`, and one shared operator comparison | the row's other limb ("every caller shown safe") is not achievable: 901 call sites |
| **1.0f** ⬜ | The remaining 10 reclassified tasks — `P1-INV-09`, `P1-INV-13`, `P1-ORD-10`, `P1-ORD-12`, `P1-PLT-06`, `P1-SEC-14`, `P1-SEC-16`, `P1-SEC-28`, `P1-SEC-31`, `P1-SEC-34` | each has one named unmet criterion in `P01-IN-REVIEW-AUDIT-FINDINGS.md` §2 |
| **1.0g** ⬜ | **`P1-PLT-02`** (#34) — **run `report-null-store-orders.php` once** on a machine with the database | the cheapest item in this report; it is BLOCKED only because nobody has run it |

Then the originally-planned Stage 1:

| # | step | dependency |
|---|---|---|
| 1.1 ⬜ | **P1-ORD-13** (#82) — carry the order primary key through the gateway returns; close the Paymera replay | none |
| 1.2 ⬜ | **P1-INV-15** (#60) — the owner-independent parts: POS orders 500ing, the missing transaction, `tax_price` zeroed | the double-credit half is owner-gated (see D) |
| 1.3 ⬜ | **P1-ORD-02** (#21) — every order writer through one total seam, asserted by a survey test | none; and it must precede P1-ORD-01 |
| 1.4 ⬜ | **P1-PAY-02** (#24) — idempotency via the conditional-transition form in F.3 | none, if the no-new-table form is taken |
| 1.5 ⬜ | **P1-INV-03** (#29) — POS writes an absolute stock value from the session | P1-INV-01 (DONE) |
| 1.6 ⬜ | **P1-PLT-03** (#35) — give the cross-tenant escape hatches real call sites and a scoped-model inventory | P1-PLT-02 (see I) |
| 1.7 ⬜ | **P1-INV-05** (#31) — every stock mutation through the atomic primitive | P1-INV-02 (DONE); the concurrency half needs MySQL |
| 1.8 ⬜ | **P1-ORD-06** (#62) — the auction status endpoint reads the host `orders` table with an auction id | none |
| 1.9 ⬜ | **P1-PLT-05** (#37) — enumerate the phase's security log lines; review LaraBug's blacklist | P1-SEC-14 (see I) |
| 1.10 ⬜ | The **67 dead `Route::resource` actions** (section D) — `->only([...])` on each declaration | none |

**Revised estimate: Stage 1 is no longer two weeks.** With the 15 reclassified tasks it is the
bulk of the remaining Phase 01 engineering — on the evidence of this session's three tasks (each of
which found its recorded census short), budget for the named gap plus whatever the census turns out
to have missed. It still needs nothing from the owner, which is the point: **it can all start
today, in parallel with the Stage 0 containment and the Stage 2 decisions.**

### Stage 2 — the decisions that unblock the schema (⬛, must be sequential)

| # | step | dependency |
|---|---|---|
| 2.1 ⬛ | **COD settlement timing** (P1-PAY-04, #26) | nothing — and everything below waits on it |
| 2.2 ⬛ | Approve **P1-MIG-02** + a recovery plan; apply; verify on MySQL | independent, smallest, do it first to exercise the process |
| 2.3 ⬛ | Approve **P1-MIG-03** (cost inputs); apply | must precede 2.4 or the P&L is all-margin |
| 2.4 ⬛ | Approve **P1-MIG-01** (the six money tables); apply | 2.1 + 2.3 |
| 2.5 ⬜ | Convert the **15 blocked test skips** into real assertions | 2.4 |
| 2.6 ⬛ | Approve **P1-MIG-04** (nine more tables; decide the two retired chatwoot tables) | 2.4 |

**2.1 is the single highest-leverage decision in this report.** Until it is taken, GATE F cannot be
released, and until GATE F is released criterion A3 cannot be met, so Phase 01 cannot be accepted.

### Stage 3 — the decisions that change behaviour merchants or customers see (⬛)

| # | step |
|---|---|
| 3.1 ⬛ | **P1-PAY-03** (#25) — the gateway-verification rollout order, with credentials |
| 3.2 ⬛ | **P1-ORD-01** (#20) — accept the coupon behaviour change (after 1.3) |
| 3.3 ⬛ | **P1-ORD-11** (#75) — refund totals move to approval time |
| 3.4 ⬛ | **P1-ORD-03** (#22) — what an account deletion retains |
| 3.5 ⬛ | **P1-INV-12** (#53) — category→product cascade becomes RESTRICT (needs a migration, so after Stage 2) |
| 3.6 ⬛ | **P1-SEC-32** (#111) — the host policy |
| 3.7 ⬛ | **P1-OWN-12** (#108) — who may inject storefront script |

### Stage 4 — secrets, tokens and the operational floor (⬛)

| # | step | dependency |
|---|---|---|
| 4.1 ⬜ | Inventory every encrypted-cast column; write and test the re-encryption pass | none |
| 4.2 ⬛ | Rotate **APP_KEY** (P1-OWN-02, #43) and run the pass | 4.1 — rotating first breaks every encrypted column |
| 4.3 ⬜ | **P1-ORD-09** (#73) — replace the encrypted order id with a random expiring token | 4.2 |
| 4.4 ⬜ | Narrow Sanctum abilities away from `['*']` (P1-OWN-04 half) | none |
| 4.5 ⬛ | Ship a client refresh path, then introduce token expiry | 4.4 + a client release window |
| 4.6 ⬛ | **Branch protection on `main`** (P1-OWN-06, #47) | none — and it should be done first, not last |
| 4.7 ⬛ | **P1-OWN-03** (#44) retention decision; **P1-OWN-07** (#56) and **P1-OWN-11** (#106) read-only investigations |
| 4.8 ⬜ | **P1-PLT-04** (#36) — log module-load failures |
| 4.9 ⬛ | **P1-OWN-10** (#103) — remove the 407 committed uploads; judge the receipt separately |

4.6 is cheap and should move to the front: without it every CI guard this phase built is advisory.

### Release gates — the conditions for signing Phase 01 off

1. Stage 0 complete and recorded.
2. Every Stage 1 item DONE with its own biting regression tests.
3. GATE F released (2.1–2.4) and the 15 blocked skips converted (2.5).
4. **Criteria E3 and F4 measured on a real MySQL** — the two this environment cannot produce.
5. Stage 3 decisions taken and the resulting behaviour changes shipped and verified.
6. Branch protection in place (4.6), so the gates cannot be bypassed after acceptance.
7. The full Unit + Feature + Contract suites green against
   `.github/known-test-outcomes.json`, with the declared failure set *reduced*, not extended.

---

## K. Next-phase readiness

**Phase 02 (Merchant Web Experience) should not start yet, and the reason is narrow.**

What is ready. The design-system foundation exists (`docs/design-system/README.md`), the CI gates
are built and working, and the authorization and tenancy primitives that Phase 02 screens will sit
on — `OrderDocumentAccessGuard`, `StoreSettingsAccess`, `HostClaims`, `StoredPathPolicy`,
`ProductStockService`, `OrderTotalReconciler`, `PaymentVerificationService`,
`ProductController::lacksProductPermission()` — are all in place with regression tests. A Phase 02
session would not be building on sand.

What is not ready, and what it costs Phase 02 specifically:

1. **The permission model is not uniform, and Phase 02 is a screen-by-screen adoption.** This
   session found that `ProductController::show()` and `edit()` call `isAbleTo` **nowhere** while
   `index()`, `store()`, `update()` and `destroy()` do. A phase that adopts the design system screen
   by screen will meet that asymmetry on every screen and will have to decide it ad hoc unless the
   model is settled first. **Recommendation:** a small owner-independent task before Phase 02 that
   inventories which controller actions carry which permission and names the intended model.
2. **67 live routes still answer with a 500.** They are all `Route::resource` actions their
   controllers never implemented. A UI phase will link to some of them.
3. **GATE F is unreleased**, so any Phase 02 screen touching wallets, payouts, P&L or courier
   commission has no table behind it. That is six money-bearing tables of merchant-facing surface
   that cannot be built yet.
4. **`Product` is unscoped** (F.2). Phase 02 screens will query products constantly, and every one
   of those queries is a cross-tenant read today. Doing P1-PLT-03 and then scoping `Product` before
   Phase 02 means the new screens inherit the predicate instead of each needing its own.
5. **Bilingual and contrast verification is not automated.** Arabic (RTL) and English (LTR) must both
   be checked on every UI change, and a previous verification found option names rendering at
   1.00:1 white-on-white in dark mode. Phase 02 is almost entirely UI; it needs this as a gate, not
   as a review habit.

**The minimum to start Phase 02 safely:** Stage 1 of the closure plan (owner-independent, ~two
weeks), the permission-model inventory in K.1, and the GATE F decision in 2.1 — because without it a
sixth of the merchant surface cannot be designed.

**Explicitly not recommended:** starting Phase 02 while the thirty blocked decisions are open. Not
because Phase 02 depends on them technically — mostly it does not — but because an open decision
about money or customer data outranks a screen refresh, and a phase running in parallel competes for
the same single owner's attention.

