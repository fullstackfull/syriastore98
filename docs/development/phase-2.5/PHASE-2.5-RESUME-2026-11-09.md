# Phase 2.5 — safe pause, 2026-11-09

**Phase 2.5 is NOT complete.** This is a resumable checkpoint taken at a clean batch boundary on
the owner's instruction. Both working trees are clean and both branches are pushed.

---

## 1. Exact state

| | |
|---|---|
| Laravel `syriastore98` | `539ac59e84772df311de9319288f974d21efd0d3` |
| Flutter `StoreSyria_app98` | `ca33a19b526d3af3925d0d0f598af7544fcba59e` |
| Branch (both) | `claude/vibrant-bell-12tquy` |
| Working trees | **clean**, local == remote on both |

### Coverage matrix — 295 surfaces

| state | count |
|---|---:|
| REDESIGNED AND VERIFIED | **119** |
| PREMIUM VERIFIED | 24 |
| PENDING | **141** |
| BLOCKED | 10 |
| OUT OF SCOPE | 1 |

Session arc: **34 → 119 verified**, BLOCKED **13 → 10**.

### Tests at the pause

| suite | result |
|---|---|
| Flutter `flutter test` | **1823 passed** |
| Flutter `flutter analyze` | 0 errors, 0 warnings |
| Laravel `--testsuite=Unit` | **1550 passed** (11798 assertions) |
| Laravel `--testsuite=Contract` | **32 passed** |
| `build-coverage-matrix.py --check` | current |
| `check-distinct-surface-captures.py` | 1646 captures, 1522 distinct, no collisions |
| shared-capture-set guard | passes, 0 rejected claims |

**The Laravel Feature suite was not re-run at the pause.** It last ran green-against-manifest at
`9b273687` ("2 declared failures, 1 declared skip, nothing undeclared"). Nothing committed since
touches PHP application code — the Laravel commits since are tests, tools, docs and evidence — but
it is a ~24-minute run and should be the first thing started on resume, not assumed.

---

## 2. CI — unchanged and still truthfully red

The two known checks remain red and were never bypassed, relabelled or worked around:

1. **Composer advisories.**
2. **The human security acknowledgement** — which must be signed by a person, not an agent.

Nothing in this session changed either. **Not release-ready.**

---

## 3. What was built this session, and where it lives

Three reusable harnesses, deliberately built once and applied across groups:

| harness | what it does | where |
|---|---|---|
| **Flutter screen harness** | drives real screens with real controllers, network stubbed through `http.runWithClient`, no `pumpAndSettle` | `StoreSyria_app98/test/support/screen_harness.dart` |
| **DS component gallery** | renders every `x-ss.*` through Laravel's real view factory **to a file, not a route**, then screenshots each specimen | `syriastore98/tests/Support/DesignSystem/bin/render-ds-gallery.php` + `shoot-ds-gallery.cjs` |
| **Web page harness** (pre-existing, now usable) | signs in, walks paths in 4 combinations, asserts the direction/theme premises | `syriastore98/docs/development/phase-2.5/tools/render-matrix.cjs` |

Harness capabilities added this session: `runWithApi` (post-mount fetches escape the stub's zone),
query strings in `ApiStub.calls`, `VisibilityDetectorController.updateInterval = 0`, `pages:` route
table, `surface:` for a tablet render, and theme alignment between `ThemeController` and the
MaterialApp theme.

**Web captures need `grant-dev-harness-permissions.sh` first.** Thirteen permissions on the dev
fixture only, minimum-necessary, idempotent. Without it nine routes answer HTTP 200 titled
"Store Syria - Dashboard" and the captures are of refusal redirects.

---

## 4. The 141 PENDING, classified

No silent exclusions. Counts are from the matrix at this commit.

| group | ~count | why it is still pending | next action |
|---|---:|---|---|
| **Settings tabs** (Brand, System, Storage, Currency, Cache, OTP, Shopify, WooCommerce, Webhook, Pixel, + a 17-tab combined row) | 11 | AJAX tabs of `/setting`; each loads its panel over XHR, so a plain navigation captures the hub, not the tab | extend `render-matrix.cjs` to click a tab and wait for its panel before capturing |
| **Web row-actions** (Category, Brand, Attribute, Tag, Product label, Coupon, Customer, Order, Product, Abandoned cart/wishlist, FAQ, Testimonial, Product question, Flash sale cell, Stock report cells) | ~16 | action cells rendered inside DataTable rows by PHP helpers; a page capture is evidence for the page | render the helper's output into the DS gallery the same way `x-ss.*` specimens are |
| **Modals / bottom sheets** (create+edit for coupon, category, FAQ, flash sale, tag, testimonial, brand, product label, attribute, pixel, webhook, users, variant delete, + Flutter add/edit tax, shipping, category, customer) | ~30 | need interaction driving — open the modal, wait, capture | one reusable "open and capture" step in `render-matrix.cjs`; Flutter sheets via `pumpScreen` + tap |
| **Parameterised web routes** (`/product/{id}`, `/product/{id}/edit`, `/user-info/{id}`, `/email_template_lang/{id}`, `/ticket-view/{id}`, Shopify/WooCommerce import screens, menus edit, …) | ~25 | need a real id from the fixture and, for several, a permission not yet granted | resolve ids from `p2dev.sqlite` and extend the permission manifest |
| **Heavier Flutter screens** (order detail, product detail, add/edit product, invoice viewer, create order, add rating, my plan, payment methods, coupon detail, notifications detail) | ~13 | need nested-model fixtures (`OrderDetail`, `CurrentPlanResponse` are deeply nested) | build one reusable nested-fixture file, then one capture test per group |
| **Flutter infrastructure rows** (routing table, bindings/DI, theme definition, design tokens colour/typography, theme switch animation, loading indicator, toast duplicate-class) | ~8 | not screens; several are code-structure rows a photograph cannot evidence | decide per row whether a test or an owner note is the right closure |
| **Web non-component rows** (`x-datatable`, `custom.css F126 region`, DS stylesheet, DS theme hook, legacy Tailwind set, Breeze profile orphan, Delivery Syria tab, plan-pay routes) | ~10 | a stylesheet, a Blade include and a co-resident library are not components a photograph evidences; `x-datatable` needs real rows and a page | mostly owner notes; `x-datatable` is capturable on a list page |
| **Remaining single screens** (Create order, Store payment methods, Flutter dashboard-shell duplicate rows, Home widgets, Product card partials, Statistics widgets, Shared toast ×2, Shared confirmation dialog, Settings popup) | ~28 | mixed: some duplicates of verified rows, some need the modal/interaction work above | per-row, after the harness extensions land |

---

## 5. The 10 BLOCKED, unchanged

Four need an **owner decision**, not engineering:

* **Customer filter result fragment** — a route with no caller.
* **Customer filter field metadata** — returns JSON; already covered as part of the filter bar.
* **Edit customer (web)** — backend ships and Flutter uses it; the web screen's existence is a
  product call.
* **Wishlist create/edit/store/update** — four routes whose bodies are literally `//`.

Six are out-of-phase, dead files or fragments. Also recorded, not BLOCKED but not screens:
`/languages` does not exist; `ShippingMethodController::index()` has an empty body; the three
`/stock-report/*` routes are row-payload endpoints.

---

## 6. Owner decisions — all three resolved and shipped

| decision | outcome |
|---|---|
| **P2.5-FLUTTER-PRICE** | Fixed with **no API change**: the card reads `final_price`, which `ProductList` already resolved from the product's own price. Pinned by `ProductListPriceContractTest` (4) + `product_price_parity_test.dart` (3). |
| **TAX-CURRENCY-SYMBOL** | `currency_symbol` added to `taxlist`, additive, resolved from the store's active currency rate. Pinned by `TaxListCurrencyContractTest` (5, incl. tenant isolation) + 3 Flutter cases. |
| **Arabic copy deck** | All 68 strings, 76 call sites, 5 screens. `State` corrected on **both** surfaces to `المحافظة` (`ar.json` said `الدولة`, colliding with `البلد`). |

**One authorised fix was not made, deliberately.** The shell-header bidi defect I reported **does
not exist** — `<bdi>` is already applied (P2.5-A-03), four tests pass, and the painted order was
measured in the running Arabic admin (`مرحباً،` 1088–1124, `Amina Haddad` 994–1088, `!` 989–994):
correct for RTL. I had misread a screenshot left-to-right. The README carrying my wrong claim is
corrected in place.

---

## 7. First task on resume

**Start the Laravel Feature suite immediately** (~24 min, background it), then:

> **Settings tabs.** Extend `render-matrix.cjs` with a "click this selector, wait for its panel,
> then capture" step, and use it for the 11 settings-tab rows. It is the largest group reachable
> with the smallest harness change, the dev fixture already has the permissions, and the tabs all
> live behind one URL (`/setting`) so one fixture state serves all of them.

Before any web capture:

```bash
docs/development/phase-2.5/tools/grant-dev-harness-permissions.sh
docs/development/phase-2.5/tools/serve-dev.sh &
```

Then verify a route answers with **its own title**, not "Store Syria - Dashboard" — that title is
the signature of a refusal redirect, and it is what made a whole earlier run worthless.

---

## 8. Standing constraints, unchanged

No merge to `main`. No force push. No history rewrite. PRs #132 and #8 stay draft. No production
access, migration or deployment. Never create or sign the human security acknowledgement. Do not
weaken the Composer advisory gate or add to `.github/known-test-outcomes.json` to get green. Do not
edit `packages/workdo/` except first-party `LynomiaChat`. No PREMIUM VERIFIED promotion without the
owner's visual acceptance. One screenshot may not be evidence for two surfaces — now enforced by a
guard in `build-coverage-matrix.py`.
