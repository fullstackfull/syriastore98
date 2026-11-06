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
| FRAGMENT | 8 | bare markup a list screen injects — **not a page**, and driving one as a page measures an unstyled orphan and invents contrast defects |
| HTTP 500 | 2 | **finding** |
| HTTP 403 | 1 | **finding** |
| REFUSED (back to dashboard) | 1 | **finding** |

---

## 3. The four findings

### F-C-01 — `GET /order-receipt/{enc}` refuses with a VALID encrypted id

The printable receipt redirects to the dashboard even when asked with a token the application
itself produced via `Crypt::encrypt($order->id)`. Every other encrypted-id surface in the family
renders with the same token: order detail (PAGE), the AJAX modal (FRAGMENT), the shipping label
(FRAGMENT), refund detail (PAGE). So this is not the id and not the permission set.

`order_receipt()` has **no permission gate at all** and goes straight to
`try { Crypt::decrypt($id); … } catch (DecryptException $e) { redirect()->back() }`
(`OrderController.php:1175-1193`). Something inside that try is redirecting or throwing. Not yet
diagnosed — recorded rather than guessed at.

### F-C-02 — `GET /order/{order}` answers HTTP 403

`OrderController::show(Order $order, $id)` takes **two** parameters while both of its routes supply
one segment (`order/{order}` and `orders/order_view/{id}`). The second route works, because `{id}`
fills `$id` and `Order $order` is resolved from the container. The first does not. The 403's source
is not yet identified; the route carries no permission middleware
(`web, Authenticate, XSS, SetLocate, EmailIsVerified` only).

Same family as the three route-optional/signature-required 500s already fixed in Package B.

### F-C-03 — `GET /customer-filter-data` → HTTP 500, `Undefined array key "customer_field"`

`CustomerController@CustomFilterData` reads a request key without checking for it, so the fragment
throws rather than rendering empty or refusing. Measured exception, from the log.

### F-C-04 — `GET /customer/{customer}/edit` → HTTP 500, `Method CustomerController::edit does not exist`

`Route::resource('customer', …)` registers `customer.edit`; the controller has no `edit` method.
The coverage matrix already calls this surface "registered, unimplemented" — but *unimplemented*
here means a **500 error page**, not a 404. A registered route that throws is a finding.

---

## 4. Recorded, lower severity: four dead-looking `DecryptException` catches

`DecryptException` is **never imported** in `OrderController.php` (no `use` line; checked with an
unanchored grep over the whole file), yet four `catch (DecryptException $e)` blocks reference it
unqualified at lines 829, 1170, 1193 and 1871. An unqualified catch resolves against the current
namespace, so these name `App\Http\Controllers\DecryptException`.

**The behaviour does not match that reading and the measurement wins:** with an invalid id,
`order_view` and `order_receipt` *redirect* (their catch ran — a caught exception is not logged),
while `OrderController::show` and `shippinglabel` answer **HTTP 500** with
`Illuminate\Contracts\Encryption\DecryptException` in the log. `show()` has no try at all, which
explains one of the two; `shippinglabel`'s try demonstrably spans its decrypt (brace-traced,
`try` at 1108 → `catch` at 1170), which does not explain the other.

**Not resolved, and deliberately not guessed at.** Severity is low either way: a merchant always
arrives from a list link carrying a valid token, so this is the quality of the error page on a
stale or hand-edited URL, not a broken screen. It is written down because an inconsistency this
specific usually means one of the two readings is wrong, and finding out which is cheap later.

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
