# F184 — explicit confirmation before every permitted variant deletion

Owner directive §4.4. Evidence captured 2026-10-30 on branch
`claude/store-syria-p02-continuation-smlpwi`.

## What is in this directory

| file | what it is |
|---|---|
| `f184-browser-5-configs.txt` | the real-browser run: **229 checks, 0 failures**, five configurations |

The harness itself is committed, not scratch: `docs/development/phase-2/tools/check-f184-confirmation.cjs`,
with its fixture reset beside it as `reset-f184-free-product.php`. Both resolve their paths from
`__dirname`, so the run reproduces from a fresh clone with nothing outside the repository. The
output above was produced by the committed copy, re-run from the repository after it was moved there.

## Running it

The preview server must be up first, with the documented invocation — `php artisan serve` is **not**
a substitute, because it routes static assets through Laravel and the page then loads without jQuery:

```bash
cd public && DB_CONNECTION=sqlite DB_DATABASE=$PWD/../database/p2dev.sqlite \
  CACHE_STORE=file SESSION_DRIVER=file PHP_CLI_SERVER_WORKERS=10 \
  php -S 127.0.0.1:8899 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

```bash
node docs/development/phase-2/tools/check-f184-confirmation.cjs
```

## The five configurations, and why the fifth exists

| | locale | direction | theme | viewport |
|---|---|---|---|---|
| 1 | en | ltr | light | 1500×950 |
| 2 | ar | rtl | dark | 1500×950 |
| 3 | en | ltr | dark | 390×844 |
| 4 | ar | rtl | light | 390×844 |
| 5 | **ar** | **ltr** | light | 1500×950 |

The fifth is the trap, and it is not a filler row. **Direction is driven by the per-store `SITE_RTL`
setting, not by the locale** (`resources/views/layouts/app.blade.php:68`), so an Arabic store with
RTL switched off renders an LTR document and `[dir="rtl"]` never matches. The dialog's one explicit
RTL rule is the centring-transform flip at `public/css/store-syria-ds.css:1355`; everything else is
logical properties. Configuration 5 is the only one that proves Arabic text reads correctly in an
LTR document.

## What each run proves

Per configuration, against the live editor and the live read-only SHOW screen:

- the trash click **opens the dialog instead of deleting** — and **no DELETE request is sent** by the
  click, which is the owner's *"do not start the delete on a bare Trash press"*
- the dialog **names the variant** and **states the loss is permanent**
- Confirm and Cancel are both present; `role="dialog"` and `aria-modal="true"`
- **focus lands on Cancel**, not on the destroying button
- Escape closes the dialog and sends nothing — Escape is Cancel
- Cancel sends nothing, and no card leaves the DOM
- Confirm sends **exactly one** DELETE, carrying the consent echo, and the echo is the variant name
  the dialog displayed
- all five contrast ratios ≥ 4.5:1 (`.ss-dialog__title`, `__note`, `__list li`, Confirm, Cancel)
- the dialog fits inside a 390px viewport
- Arabic configurations: title, note and Confirm label are all Arabic

Plus two checks added by the independent review, each of which catches a defect that was really
there:

- **a variant name containing `$` is displayed verbatim.** The name line was built with
  `String.replace()`, where the merchant's name is the *replacement* argument and `$$`, `$&`,
  `` $` `` and `$'` are substitution patterns — so `A$&B` displayed as `A@@v@@B` and ``A$`B`` as
  `AVariant: B`. The right row was still deleted, but the merchant approved a name that was not the
  variant's, which is the one guarantee the name echo exists to give. Driven through the shipped
  function with all five patterns.
- **a screen with no page-load include gets the bridge and the shell from the panel itself.** This
  is the vendored **AuctionProduct** edit screen, which calls the same first-party routes and so
  renders these same partials, but defines no bridge — and `packages/workdo/` must not be edited.
  The first version of this change pushed the bridge from the two first-party views only, so every
  trash button there answered *"This variant could not be deleted"* and sent nothing: a capability
  that worked before was gone, and the message blamed the variant. Simulated with first-party code
  only — tear the bridge and shell out of the page, re-inject the panel through the same route, and
  assert the panel brings both back and the click opens the dialog rather than deleting.

And §4.3 — *"a merchant's confirmation does not turn an unsafe operation into a safe one"* — in the
browser rather than only in PHPUnit:

- a **confirmed** delete of an order-blocked variant **left every card in the DOM**, was refused with
  a 409, and the refusal reached the merchant **both** visibly (`.notifier-container`) **and** in the
  DS assertive live region
- on an order-free product, the same confirmed delete removed **exactly one** card

## Three measurements that corrected the implementation

**1. The accordion kept toggling open behind the backdrop, in every configuration.** A capture-phase
listener on `document` was not enough. Bootstrap 5.1.3 passes its own delegation flag straight into
`addEventListener`'s third argument, so its `data-api` handler is *itself* a `document` **capture**
listener, registered when `bootstrap.min.js` loaded — long before a partial injected by `.html()`.
Two capture listeners on one node run in registration order, so Bootstrap ran first and
`stopPropagation()` could not un-run it. A stack capture on `bootstrap.Collapse.prototype.toggle`
named `HTMLDocument.s` as the caller. The listener moved to **`window`**, the first object in the
capture path, where the ordering is the event path itself rather than a registration race.

**2. The hard-block message read as empty in two configurations — and that was the harness.** This
app does not use toastr. `show_toastr()` (`public/js/custom.js:275`) renders through the `notifier`
plugin into `.notifier-container` and mirrors the same string into the DS live region; both carried
the full, correct refusal. An English needle could never have matched the Arabic message. The check
is now language-agnostic and asserts both surfaces.

**3. One of the review fixes broke the page, and this harness is what caught it.** Adding the
bridge include to the two partials, the insertion point was chosen by searching for the last
`<script` before the handler — which matched the text `<script> tags` **inside a JS comment**, so
the include landed in the middle of an open `<script>` block and killed every handler in it. The run
reported the shell present, the bridge defined, and the click doing nothing at all: no dialog, no
DELETE, and the accordion toggling again. Moved above the block.

A harness that only ever produces green tells you nothing. This one has now produced three distinct
real failures and one of its own.

## What this evidence does NOT cover

- **The WorkDo `AuctionProductController` path (F200) is untouched and still reachable, and the
  review raised its severity.** Re-verified by hand: `update()` loads the host catalogue row with a
  bare `Product::find($id)` and there is **no `product_type` predicate anywhere in the method**, so
  it reaches an ordinary variant product as readily as an auction one; the route carries only
  `auth,web,PlanModuleCheck`; and the permission test is the cross-store `isAbleTo()`. It bypasses
  the **hard blocks** — a variant in a shopper's cart, or a product named by a past order — not
  merely the confirmation. It is vendored, so §4.7 forbids editing it. **Not every variant-deletion
  path in the product is confirmed**, and this directory must not be read as saying so.
- **F205** — the **bulk** save door's consent is still a plain boolean, so the one door that deletes
  many variants at once is the one whose consent is not verifiable. Half of it was mine and is
  fixed (it briefly advertised `confirm_value: 'true'`, the server telling a client that a bare
  literal suffices); the rest is a protocol change the owner has to want.
- **The check-then-delete race** on both per-variant doors is an open risk, not closed here. Its
  consequence is now recorded: a cart row created inside the window survives a delete the policy
  approved, and the shopper's cart then prices a line off a `null` variant.
- `.show_confirm`, the canonical idiom behind the other 65 destructive confirmations, has its own
  defect (**F204**, recorded not fixed — it is outside F184's authorised scope).
- A browser harness is not a CI gate. It runs against the development SQLite fixture on a local
  preview server and is not part of any `--testsuite`. The server-side half of F184 *is* pinned by
  CI, in `tests/Feature/Security/VariantRemovalPolicyTest.php`.
- Store 1 only. Cross-store coverage for these doors lives in the PHPUnit suites, which drive a
  second store and a second merchant.
