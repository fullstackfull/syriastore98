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
| FRAGMENT | 9 | bare markup a list screen injects — **not a page**, and driving one as a page measures an unstyled orphan and invents contrast defects |
| HTTP 404 | 1 | correct — the handler-less customer edit route, after F-C-04 |
| PAGE (prints, then returns) | 1 | correct — the receipt, after F-C-01 was withdrawn |
| HTTP 403 | 1 | **the one open finding**, F-C-02 |

After the repairs the run holds **no HTTP 500 at all**. Before them: four.

---

## 3. The findings, and what happened to each

Of the four this census first reported, **one was withdrawn, two are fixed, one stands**, and a
fifth was found while diagnosing them. Keeping the withdrawal visible rather than quietly deleting
the row is the point: it was wrong for the same reason as the four corrections above.

| id | surface | state |
|---|---|---|
| F-C-01 | `GET /order-receipt/{enc}` | **WITHDRAWN — not a defect** |
| F-C-02 | `GET /order/{order}` → 403 | **OPEN** — needs a reviewed change to a security middleware |
| F-C-03 | `GET /customer-filter-data` → 500 | **FIXED** |
| F-C-04 | `GET /customer/{customer}/edit` → 500 | **FIXED** |
| F-C-05 | four dead `catch (DecryptException)` blocks | **FIXED** |
| F-C-06 | `CustomFilterData()` had no permission check at all | **FIXED** — found by the review, not the census |

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

### F-C-02 — OPEN. `GET /order/{order}` answers 403 because the guard is handed a model

`order/{order}` is a target of `OrderDocumentAccessGuard` with `'param' => 'order'`. The route is
**route-model-bound** (`OrderController::show(Order $order, $id)`), and the guard is appended to the
`web` group, so it runs *after* `SubstituteBindings`. `$route->parameter('order')` therefore returns
an **`Order` instance**, and `resolveOrderId()` opens with `if (! is_string($raw)) return null;`
(`OrderDocumentAccessGuard.php:401-407`) — so it resolves nothing and the guard `abort(403)`s for
every caller, the legitimate owner included.

**The direction is fail-closed**, so there is no exposure: the surface is simply dead. The fix is
to let `resolveOrderId()` read a bound model's key, after which the row goes through the same
`authorises()` test as every other target. That is a change to a **security middleware** and will
be made as its own reviewed slice with its own tests, not folded in here.

Also noted on the same method: `show(Order $order, $id)` takes two parameters while both its
routes supply one segment. The second route works only because `{id}` fills `$id` and `Order` is
resolved from the container.

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
