# Package C — orders and customers: the access census

**Date:** 2026-11-06
**Tool:** `docs/development/phase-2.5/tools/probe-package-c-reachability.cjs`
**Data:** `docs/development/phase-2.5/tools/package-c-reachability.json`
**Status:** slice 1 of Package C. **This is the access-check, not a design verdict.** No surface
is marked premium or verified by anything in this document.

---

## 1. Why this comes first, and what it cost not to have it

Package B's experience: three surfaces read as "permission-refused" when the permissions were
held — the probe was asking for a URL that was not a surface. Package C reproduced that twice over
before the table below was correct. **Every intermediate reading was wrong in a way that looked
exactly like a defect**, which is the whole argument for a control-bearing census:

| Reading | Looked like | Actually was |
|---|---:|---|
| 8 surfaces refused, incl. the cart and wishlist families | the screens are permission-gated | the **dev role holds 68 of the 186** permissions a real merchant gets on login; a fixture gap |
| Abandoned cart/wishlist lists empty | nothing to redesign | `carts` = 0 and `wishlists` = 0 rows in the fixture; **six surfaces had no data at all** |
| 3 order surfaces refused — **2 of them already "REDESIGNED AND VERIFIED"** | my recent security work broke them | they take an **encrypted** id; a plain integer is a `DecryptException` |
| the newest order id | a representative order | a **seeder probe order** whose `product_json` is `[]` |

Each correction was forced by the probe's own **controls** — the six surfaces the coverage matrix
already records as verified. They are in the run precisely so that "the screens are broken" and
"my measurement is broken" are distinguishable. The first three runs reported controls failing;
the final run reports **all 6 controls rendering as PAGE**, which is what makes the remaining rows
worth reading.

**Nothing was "fixed" by weakening a controller.** The dev role was granted the 14 permissions a
production merchant already receives from `Utility::addNewData()`, and the fixture was given cart
and wishlist rows. Both are dev-fixture changes, tagged and reversible.

---

## 2. The measured result — 26 surfaces

| shape | count | meaning |
|---|---:|---|
| PAGE | 14 | renders with the admin shell |
| FRAGMENT | 10 | bare markup a list screen injects — **not a page**, and driving one as a page measures an unstyled orphan and invents contrast defects |
| PAGE (prints, then returns) | 1 | the receipt: a printable document that prints itself and navigates back |
| HTTP 404 | 1 | correct — the handler-less customer edit route, after F-C-04 withdrew it |

**After the repairs the run holds no HTTP 500, no HTTP 403 and no refusal at all.** Before them:
four 500s, one 403 and eight refusals.

**All six controls render as PAGE**, which is what makes the rest of the table worth reading.

**The surface list took SIX id corrections to converge**, each of which first looked like a defect:
the dev role's missing permissions, the two empty tables, the seeder-probe order with
`product_json = []`, the five routes that need an encrypted token, the print-and-return receipt,
and finally `order/{order}` needing an encrypted token too once F-C-02 removed its binding. None
of them was a product fault. A census is only as good as the questions it asks, and the controls
are the only reason the wrong questions were caught rather than published.

## 3. The findings, and what happened to each

Of the four this census first reported, **one was withdrawn, two are fixed, one stands**, and a
fifth was found while diagnosing them. Keeping the withdrawal visible rather than quietly deleting
the row is the point: it was wrong for the same reason as the four corrections above.

| id | surface | state |
|---|---|---|
| F-C-01 | `GET /order-receipt/{enc}` | **WITHDRAWN — not a defect** |
| F-C-02 | `GET /order/{order}` → 403 | **FIXED** — in the controller, not the guard |
| F-C-03 | `GET /customer-filter-data` → 500 | **FIXED** |
| F-C-04 | `GET /customer/{customer}/edit` → 500 | **FIXED** |
| F-C-05 | four dead `catch (DecryptException)` blocks | **FIXED** |
| F-C-06 | `CustomFilterData()` had no permission check at all | **FIXED** — found by the review, not the census |
| F-C-07 | a customer's order count and **total spend** included other stores' orders | **FIXED** |
| F-C-08 | the same defect on `/api/admin/customer/orders`, which the **Flutter app calls** | **FIXED** — found by the review of F-C-07 |

### F-C-01 — WITHDRAWN. The printable receipt prints itself and returns, by design

`order/receipt.blade.php:294` calls `window.print()` and `:299` calls `window.history.back()`. The
response is **200 with the receipt in it**; the browser then leaves of its own accord. My
classifier asked "where did we land", saw `/dashboard`, and reported it identically to a
permission denial.

Before withdrawing it I drove every step of `order_receipt()`'s body in isolation — `order_detail`,
the store lookup, all three `DocumentDirection` helpers, and the view itself, which rendered 8,263
bytes. Nothing in the handler fails. The probe now classifies this surface as
**PAGE (prints, then returns)** from an explicit flag rather than inferring a denial from the
landing path.

One sub-lesson recorded: while chasing this I searched the response HTML for the string
"Something was wrong" as evidence of the error branch. It appears on `order/view.blade.php:871`
as a **JavaScript template string** in a ternary, on a page that rendered perfectly. A text needle
is not a branch detector.

### F-C-02 — FIXED, by deleting an unused parameter rather than widening a guard

`order/{order}` is one of `OrderDocumentAccessGuard`'s ~20 targets, with `'param' => 'order'`. The
guard is appended to the `web` group, so it runs **after** `SubstituteBindings`. The handler was
declared `show(Order $order, $id)`, and that parameter's NAME matched the route segment — so
binding replaced the segment value with an `Order` **instance**, `resolveOrderId()` hit
`if (! is_string($raw)) return null;` (`OrderDocumentAccessGuard.php:405`), and the guard
`abort(403)`d for **every caller, the legitimate owner included**.

**Broken in two directions, which is how it hid.** A plain id (`/order/1`) binds, so the guard gets
a model and answers 403. An encrypted token — the format every sibling order surface uses — finds
no row with that primary key, so binding answers **404 before the guard runs**. There was no input
that worked, and the UI links neither spelling. My browser probe saw the 403 and my first test saw
the 404; the two readings only made sense together.

**The fix is in the controller.** The obvious alternative is to teach `resolveOrderId()` to read a
bound model's key — and I deliberately did not, because that loosens an input check in a **security
middleware** to accommodate one caller. The bound parameter turned out to be dead weight: `show()`
overwrites `$order` on its very next statement (`$order = Order::order_detail($id);`) and never
reads the bound instance. Deleting an unused parameter is the smaller change and leaves the guard
strict. Laravel fills an untyped parameter from the route's segments positionally, so `{order}` and
`{id}` both arrive as `$id` and the two routes that reach this method now behave identically.

**A ratchet instead of a looser guard.** `order/{order}` was the only model-bound GET target of the
twenty, and a bound target 403s *silently* — fail-closed but invisible, which is exactly how this
survived. `OrderDocumentGuardBoundParameterTest` now fails CI if another appears.

**That ratchet's first run was over-broad, and the correction is worth recording.** It reported
`DELETE order/{order}` → `OrderController::destroy(Order $order)` as an offender. That binding is
correct and deliberate — `destroy()` genuinely uses the model — and the guard is **inert** on it:
`handle()` returns `$next($request)` for any route whose methods do not include GET. Narrowed to
mirror the guard's own condition. Had I trusted the test over the guard, I would have removed a
binding that is doing its job.

**Evidence** — 4 passed. The cross-tenant case is the load-bearing one: a merchant asking for
**another tenant's** order on this same route still gets 403, so the fix did not quietly open the
route. Reverting the signature reddens three of the four; the sibling-route control correctly stays
green either way, because `orders/order_view/{id}` worked before and after.

One more self-inflicted stumble on the way: the ratchet's failure *message* interpolated `$raw`
inside a double-quoted string, and PHPUnit builds that message eagerly — so the test threw
`Undefined variable $raw` whether or not the assertion held. A test can fail for reasons that have
nothing to do with what it asserts.

#### What the review added to F-C-02

**No new authorization hole** — it walked the path that now runs and confirmed a merchant of store
A still cannot read store B's order (`:449` permission, `:459` `getCurrentStore($user->id)` passed
explicitly rather than taken from the mutable default guard, `:468` strict `(int)` compare), that
anonymous is refused twice, and that a `customers`-guard-only principal cannot reach the handler
at all on this URI.

**It also found that test 2 discriminates on two axes, not one:** reverting the signature makes it
404 (binding throws), and deleting the `TARGETS` entry makes it 200. Both redden it.

**And it corrected a premise of mine.** I asked whether `order.order_show` was being rendered for
the first time through this URI. It was not: `show()` is also the handler for
`orders/order_view/{id}`, which the UI's eye button links
(`order/action.blade.php:78`, `order/grid.blade.php:182`). The view has been exercised in
production all along, so this change adds a second URI onto an existing path rather than a new
rendering path — which removes most of the risk I was asking about.

Three items recorded rather than fixed, all pre-existing:

- **`order_show.blade.php:99`** does `Shipping::find($orders->delivery_id)` with no store
  predicate, and `Shipping` is absent from `scoped_models`. Bounded: the guard has already
  authorised the row and only `$shipping->name` is rendered, which belongs to the order's own
  store. Live on the sibling URI long before this change.
- **The cost of a `TARGETS` mistake is now higher.** While the route 403'd for everybody, an error
  in the guard's URI table was free; it is not any more, because `show()` checks nothing itself.
  Already ratcheted twice — the reviewer verified both would fire — so the control exists and only
  the stakes changed. Written into the method's docblock.
- **The raw-integer form `/order/5` is now live**, since `resolveOrderId()` accepts a raw positive
  integer by design. Not a leak: foreign and nonexistent are both a bare 403.

**A pre-existing security test was passing for the wrong reason, and this change exposed it.**
`OrderDocumentAccessTest::test_a_merchant_cannot_read_another_tenants_order_through_the_resource_show_uri`
asserted two forms on this very route: a raw id → 403, and an encrypted id → 404. Its docblock
explained the raw form as *"binds successfully and reaches the handler, so the raw form is the path
that was actually reachable — and it is the one the guard has to refuse."*

That was half right. A raw id did bind — and the guard then received an **`Order` instance**, so it
denied on a **parse failure** (`! is_string($raw)`), not on the ownership comparison. The 403 was
real but it was not the control the test believed it was measuring: **the owner's own order
answered 403 too**, and nothing in that test could tell the two apart. The same blind spot the
census had, inside a test written to prevent it.

With the bound parameter gone, both forms reach the guard as strings and both are refused by the
ownership comparison at `OrderDocumentAccessGuard:468`. The encrypted form moved 404 → 403 —
**still a refusal, by a better mechanism** — which is exactly what that assertion's own comment
said it existed to surface: *"a future reordering of the stack shows up here rather than silently
changing which component is doing the work."* It surfaced it.

I updated the assertion and wrote the reason into it, rather than loosening it. Reverting my
signature change reddens that test too, so the new assertion is tied to the fix.

One correction to how I should cite the evidence: **test 3 is a regression guard, not mutation
coverage.** `orders/order_view/{id}` worked before and after, so it stays green under every
mutation and proves only that the sibling route was not broken.

### F-C-03 — FIXED. `customer-filter-data` threw on a missing filter key

`CustomFilterData()` read `$requestData['customer_field']` **six times**, and exactly one of the six
already carried `?? null` with a comment about a previous round of this same defect. A bare request
answered HTTP 500, `Undefined array key "customer_field"`.

Now read once into `$customerField`, guarded, and used at all six sites. Absent means "no filter",
which is what the unfiltered list already shows.

**Guarding only the first read is not enough, and that is not hypothetical** — it is what my first
attempt did, and the probe answered 500 again naming the second site. The second attempt then
rewrote the declaration itself into `$customerField = $customerField;` and the probe named *that*.
Both were caught by re-driving within a minute. Neither would have been caught by reading the diff.

### F-C-04 — FIXED. The customer resource registered two routes with no handler

**CORRECTION TO MY OWN FRAMING, and it matters.** I first wrote this up as a finding. It is not a
discovery: `tests/Feature/Security/CatalogueEndpointIntegrityTest::DEAD_ROUTE_ACTIONS` already
declares **both** of these by name, inside an inventory measured by P1-INV-10 of roughly
**sixty-seven host routes whose controller method does not exist** — "fifty-eight carry one of the
seven `Route::resource()` action names across seventeen controllers", plus nine ordinary named
routes. Its docblock says in as many words: "Not this task's to fix."

So the repository knew. What this slice contributes is that two of them are now **fixed** rather
than declared, which is why the declaration is two entries shorter. I found that out because the
broad suite reddened on my change and the test's own failure message told me to delete the
resolved rows — which is the declaration working exactly as designed, and a useful reminder that a
census can rediscover a tracked fact and mistake it for news.

`CustomerController` has **neither `edit` nor `update`**, so `Route::resource('customer', …)`
registered both and each answered HTTP 500 with a `BadMethodCallException`. The coverage matrix
also called this surface "registered, unimplemented" — but *unimplemented* meant a **500 error
page**, not a 404.

`->except(['edit', 'update'])`. Nothing references either name: a repository-wide search including
the `'route' => …` form that a `route('...')`-only grep would miss found no hit outside the
separate Shopify and WooCommerce controllers. For contrast the same search finds `customer.store`
at `customer/create.blade.php:28`.

**This is the opposite call to F-SEC-07's**, deliberately. There the routes stayed registered
because a handler existed and had to start *refusing*; here there is no handler at all, so there is
nothing to refuse with and 404 is the honest answer.

**Whether a merchant should be able to edit a customer is a separate, functional question** and is
not answered here. Implementing it would be new merchant functionality, which Phase 2.5 does not
authorise. Flagged rather than filled in.

**SURFACED FOR THE OWNER, not fixed:** about **sixty-five host routes remain** in that inventory,
each answering HTTP 500 on every request. Several are in Package C's own area —
`OrderController@edit` and `OrderController@allOrders` among them. They are declared and tracked,
so nothing is hidden, but "a registered URL that throws" sits squarely inside what a premium
merchant experience is supposed to exclude. It is a distinct piece of work from redesigning the
surfaces that do render, and it needs a decision about which of the sixty-five should be
implemented, which withdrawn, and which left declared.

### F-C-05 — FIXED. Four `catch (DecryptException)` blocks that had never run

`OrderController.php` carried `catch (DecryptException $e)` at four sites — 829 (`order_view`),
1170 (`shippinglabel`), 1193 (`order_receipt`) and 1871 (`orderDetails`) — all **unqualified**, with
no `use` statement for the class anywhere in the file. An unqualified name in a catch resolves
against the current namespace, so every one of them named `App\Http\Controllers\DecryptException`.
**Measured: that class does not exist** (`class_exists()` false) while
`Illuminate\Contracts\Encryption\DecryptException` — what `Crypt::decrypt()` throws — does.

Why it survived: two of the four have a second, fully-qualified `catch (\Exception $e)` immediately
after returning the same message, so `order_view` and `order_receipt` handled a bad id correctly
**by accident**. `shippinglabel` has only the dead catch, and `show()` had no `try` at all — both
answered HTTP 500 with the exception's stack page.

Fixed by one import, plus a `try` on `show()` whose message matches its siblings' wording exactly.

**Severity stated rather than inflated:** a merchant always arrives from a list link carrying a
token this application produced, so this is the quality of the error page on a stale or hand-edited
URL, not a broken screen. Four handlers that have never once run is still worth one line.

### F-C-06 — FIXED. The customer filter fragment checked nothing at all

**Found by the `tenant-authz-reviewer` pass on the F-C-03 fix, not by the census.** The census can
only see what a *reachable* surface answers; it has nothing to say about who else could reach it.

`CustomerController::CustomFilterData()` checked **nothing** — no permission, no policy, no
operator limb — while returning every customer in the caller's store with first and last name,
**email**, registration date, last-active date, order count and total spend. Its route sits in the
`['auth','xss','setlocate','verified']` group, so any authenticated `web` principal reached it,
**a staff member holding zero customer permissions included**.

**The review was explicit that this is not a regression from F-C-03, and that matters:** the gap
was already reachable with *any* query parameter at all, since any value misses all five filter
branches and falls through to `$query->get()`. Guarding the absent-key case conferred no new
capability. It is a live gap either way, so it is closed here rather than filed.

`Manage Customer`, not a new permission name: `index()` and `customer_grid_view()` already require
exactly that, and this fragment is fetched over ajax from `customer/grid.blade.php:265` — a page
the refused principal cannot open. So anyone who could legitimately reach it already holds the
permission and the gate locks nobody out.

**Operator first**, which both siblings omit. Laratrust has no super-admin bypass and the platform
operator holds no merchant role, so a bare `isAbleTo()` refuses the one principal who legitimately
reads across stores. Through `UserPolicy::isOperator()` rather than a fourth spelling of the
comparison. Both limbs are separately mutation-checked: removing the gate reddens the staff test,
dropping the operator limb reddens the operator test, and neither reddens the other's.

**Recorded, not fixed:** the reviewer also measured that if `getCurrentStore()` resolves null — the
staff-with-no-`current_store` case — then `where('store_id', null)` compiles to `IS NULL` and
`StoreScope` adds nothing under `null_tenant => 'open'`, so the fragment would return every
platform customer whose `store_id` is NULL. Pre-existing, unchanged by this work, and the same
edge already open elsewhere in this phase.

### What the review corrected in my own work

Worth keeping visible, because three of the four were in the *fix*, not the original code:

1. **A false claim in my test docblock.** I wrote that `show()` is "routed twice". It is not:
   `GET /order/{order}` answers 403 and never reaches the method, because `SubstituteBindings` runs
   before the appended guard and hands `resolveOrderId()` an `Order` instance it rejects. My own
   403 measurement said so and I had read it as a separate finding without connecting the two.
   Corrected in place; `orders/order_view/{id}` is the only URI that exercises the fix.
2. **`assertNotSame(500, …)` was too loose** on the filter test — satisfied by a 404, a 403 or a
   redirect, which is this project's own "the route does not exist and the 404 satisfies the
   assertion" failure mode. Now `assertStatus(200)` plus an `assertSee` on the fixture customer's
   address, so an empty 200 fails too.
3. **Two bad-id tests paired `assertNotSame(500)` with a bare `assertRedirect()`** — a redirect from
   some other middleware would have satisfied both while the 500 vanished for the wrong reason. Now
   also `assertSessionHas('error', …)`, tying the redirect to the catch arm.
4. **No positive control for `show()` itself** — the existing one drove `order_view()`, a different
   method. Added.

The review also confirmed, by reading the controls rather than taking my word: reviving the four
catch blocks changes only the response on failure and never who reaches what (two of the four are
byte-identical no-ops, because a pre-existing `catch (\Exception)` already caught the same class);
`show()`'s new `try` wraps a single statement and creates no existence oracle, since "exists in
another store" and "exists nowhere" both remain 403 at the guard; and removing the two customer
routes breaks no caller, no route-census gate and no API contract.

### Two ratchets fired on these changes, and both were right

Neither was a regression; both were declarations that my change made stale, and each told me what
to do in its own failure message.

- **`CatalogueEndpointIntegrityTest`** — the two customer routes I removed were declared dead, and
  the list is asserted in both directions, so a resolved entry is fiction. Deleted them. This is
  the same discipline as `.github/known-test-outcomes.json`.
- **`SecurityLogInventoryTest`** — my import comment and `show()`'s new `try` shifted line numbers
  in `OrderController.php`, so thirteen `Log::` rows in `docs/security/P01-OBSERVABILITY-INVENTORY.md`
  pointed at the wrong lines. Re-pointed with the repository's own
  `resync-observability-inventory.py`, which matches by `(file, message)` and **refuses to write if
  a line was added or deleted** rather than moved — exactly the case where a person has to supply a
  destination and reader. It reported 13 re-pointed, 205 unchanged, 218 source == 218 documented,
  so nothing was added or lost.

### Evidence for the fixes

`tests/Feature/Security/OrderDocumentDecryptFailureTest.php` — **8 passed, 19 assertions**, including a
positive control that a **valid** encrypted id still renders the order detail at 200, because every
other assertion in the file is satisfied by a handler that redirects unconditionally.

The bad-id tests use a **plain integer of a real, owned order** rather than random text, and that
choice is measured: `OrderDocumentAccessGuard::resolveOrderId()` falls back to the raw value when
decryption fails, so random text resolves to null and the guard denies with 403 before the
controller is reached — testing the guard, not the fix. `"1"` resolves to order 1, is authorised,
and reaches the controller, whose own `Crypt::decrypt('1')` then throws.

**Mutation-checked, all six changes:** removing the import reddens both bad-id tests; reverting
`show()`'s try reddens the modal test; reverting the `customer_field` guard reddens the filter test;
reverting the route `except()` reddens the route test; removing the new permission gate reddens the staff test; dropping its operator limb reddens the operator test. Each reddens its own test and nothing else.

---

## 5. Fixture and tooling changes this slice made

| change | reversible |
|---|---|
| dev role granted the 14 Cart/Wishlist/Refund/Order permissions a production merchant already holds | yes — dev fixture only |
| `carts` and `wishlists` seeded: 9 rows each over 3 customers, per-customer counts **1, 3, 5** | yes — `--remove`, verified 9→0→9 |
| `seed-dev-interaction-fixtures.php` extended, idempotent on the tag | yes |
| **a fail-closed write guard on all three dev tools that write** (directive §7) | n/a — see below |

The per-customer counts are deliberately distinct: `wishlist/product.blade.php` renders a **count
badge**, and three equal counts would render one value and prove nothing.

### The §7 guard

The seeder and both permission-granting scripts previously carried only a *comment* saying which
environment to pass — "or it writes to a different sqlite file and reports success". The directive
is explicit that a written warning is not a control, so there is now
`Tests\Support\DevFixtureDatabaseGuard`, asserted before the application boots in all three tools:

- it checks **every** environment source rather than the first one present, which is the exact gap
  that emptied this fixture once (an `export` survives in `$_SERVER` alone and Laravel's Dotenv
  reads `$_SERVER` first);
- the target must be sqlite **and** named `p2dev.sqlite` — `database.sqlite` is deliberately not
  allowed, being the name `.env` carries and a real path in this repository;
- the file must already exist, because a dev write tool that *creates* a database has written
  nowhere and will report success;
- an empty environment is **refused**, not allowed to fall through to `.env`.

It lives under `tests/`, mapped by composer's **autoload-dev**, so on a `--no-dev` install the
class is absent and the tools cannot start at all.

**Validated with safe negative tests**, as §7 asks: 14 pure unit cases driven with literals against
a pure function with `file_exists` injected (`Tests\Unit\DevFixtureDatabaseGuardTest`), so nothing
in the suite can touch a database even if it is wrong; plus 14 driven checks against the real
executables (`tests/Support/Security/bin/verify-dev-fixture-write-guard.sh`) which assert the
fixture is byte-for-byte unchanged by every refusal **and** carry a positive control, because a
guard that refused everything would pass every refusal case while leaving the tools dead rather
than safe. Mutation-checked: removing the guard from one tool reddens the script.


---

## 6. F-C-07 and F-C-08 — a cross-tenant MONEY disclosure, found while starting the design pass

**Not found by hunting.** The premium pass's hygiene run flagged `/customer-grid` at 2.09:1 in
dark. Chasing that into the markup found the customer's name wrapped in a `badge bg-primary`, and
reading the card's data source found this.

### F-C-07 — `Customer::Ordercount()` and `Customer::total_spend()`

Five near-identical lines each, and **neither carried a store predicate in any of its three
queries**. `Order` is absent from `scoped_models`; `order_billing_details` has **no `store_id`
column at all** and its model registers no scope, so the only route to a tenant is
`order_id → orders.store_id`.

**Measured.** Store A customer, a $100 guest order in A and a $900 guest order in B, both billed
to the same email:

| | before | correct |
|---|---:|---:|
| `Ordercount()` | **2** | 1 |
| `total_spend()` | **1000.0** | 100.0 |

So store A's merchant was shown **store B's $900 of revenue** attributed to their own customer. The
direct branch leaked identically, since `orders.customer_id` has no composite key with `store_id`.
It also feeds **AOV** (`total_spend() / Ordercount()`), computed in four places, so both inputs
were wrong.

**The guest branch is the live one**, not an edge case: it runs whenever no order carries the
customer's own id — the ordinary state for someone who checked out as a guest — and it matched
billing records **by email across every store**. One person shopping at two stores with one email
address is the normal case.

**One shared helper, because the duplication was the defect:** the same five lines twice, both
missing the same predicate, so fixing one would have left the other leaking.

**The review confirmed the authority is right, and that matters:** scoping to the customer's own
`store_id` rather than `getCurrentStore()` is the *only* correct choice, because
`getCurrentStore()` returns the literal `1` for a super admin with an empty `current_store` — so a
`getCurrentStore()` predicate would have shown a store-7 customer "0 orders, $0", a **fabrication**
rather than a refusal.

### F-C-08 — the same defect on an API the shipped Flutter app calls

Raised by the review of F-C-07, and the more serious of the two: every row this endpoint returns
is expanded in full by `Order::order_detail()`, so it leaks **whole orders**, not a number. The
route is `/api/admin/customer/orders`, inside `Route::prefix('admin')` — the Flutter admin app's
base URL.

**The endpoint contradicted itself, which is what makes it an omission rather than a decision:**
the count and the sum **ten lines above** the list both carried `->where('store_id', $storeId)`;
the list did not. `resolveOwnedStore()` guards the store_id *parameter*, not the rows, so it cannot
help — the merchant legitimately owns the store they named and the customer really is theirs. The
pre-existing `ApiStoreOwnershipPredicateTest` drives only the foreign-merchant 403 and was green
against this hole.

Response **shape unchanged, no new status code** — the orders array simply no longer carries
another store's rows, so the app sees fewer entries and never a new error. Contract suite 27
passed; `ApiStoreOwnershipPredicateTest` 20 passed.

### The mutation check found two untested branches, both mine

This is the part worth keeping. Twice, tests that looked complete were not:

1. Replacing the storeless fail-closed limb with `Order::query()` left **all four** other tests
   green — and that mutant returns *every* store's orders for a customer with no store, the
   original defect inverted and total.
2. Unscoping the **API's direct branch** left the API test green, because that test's orders all
   carry `customer_id = 0` and so only ever exercise the email fallback. Driving one branch of an
   if/else is not driving the method.

Both gaps now have their own test, and both mutants redden.

### One of my own assertions was wrong as a specification

My first API test asserted that `data.customer.orders` and `data.pagination.total` must agree.
Driven, it reported **0 against 2**: the scoped count uses only the direct branch while the list
has the email fallback, so they measure different things by construction and can legitimately
differ. Replaced with the tenancy property, which is unambiguous.

**Recorded, not fixed:** that incoherence is real and pre-existing — for a guest-only customer the
endpoint reports "0 orders" and "$0 total spend" *while listing their orders*. Reconciling it is a
decision about what the figure means.

**Also surfaced by the review, not fixed** (same defect family, different surfaces):

- `ActivityLog::totalspend()` — the same money figure on the customer timeline. **Already declared
  OPEN** in `ResidualTenancyExposureTest:588-605` with an exact-path assertion, so a fix must move
  that ratchet in the same commit.
- `CustomerController:411,415` — the activity feed itself is cross-store, and `ActivityLog` *has* a
  `store_id` column. Its `remark` JSON carries `order_id`, `price` and `payment_type`, so it leaks
  foreign order prices. **Not in any ratchet.**
- `AccountProfileController:48,700,795` — storefront, the shopper's own orders unscoped by store.
  Hardening: their own data, rendered under the wrong storefront.

**And a pre-existing behaviour worth knowing:** the direct/guest branch is **exclusive, not a
union**. A customer with both a direct order and guest orders in the same store has the guest ones
excluded from both figures — so the first time a long-standing guest shopper places an identified
order, their displayed Total Spend *drops*. Unchanged by this work, and a second independent reason
a figure can look wrong to a merchant.
