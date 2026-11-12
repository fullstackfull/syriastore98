# P2.5-PKG-G Slice 5 — the timeline, in the merchant's language and currency

Captured from the running admin by `render-matrix.cjs`. **8 rows, 0 not clean.**

---

## A documented trap, walked into and caught by looking

The first capture reported **4 rows, 0 not clean, HTTP 200 in all four combinations** — and was a
screenshot of the **dashboard**. `customerTimeline()` requires `isAbleTo('Show Customer')`, which
the dev fixture role does not carry, and the controller answers a **redirect**, not an error. The
harness's premise check covers status, direction and theme; it does not check that the request
reached the page it asked for. The tell was in its own output: the title column read
`Store Syria - لوحة التحكم` for a `/customer-timeline/1` request.

`grant-dev-screen-permissions.php` exists for exactly this, and its docblock names it: *"a screen
the fixture role cannot open answers with a REDIRECT TO THE DASHBOARD… the harness records HTTP
200 with the wrong title and a screenshot of the dashboard. That is the single most dangerous
reading this project can take, because it looks like evidence."*

`Show Customer` was granted to the dev merchant with that tool — a dev-fixture grant, additive,
recorded here because it changes what later captures of this fixture can reach.

## What was measured

| | |
|---|---|
| `{{ $value->log_type }}` printed **bare** | **14 sites**, never through `__()` |
| the seven stored enum values in `ar.json` | **all seven absent** — so wrapping the call in `__()` alone would have changed nothing |
| `Email:-`, `Contact:-`, `Register Date:-`, `Last Login Date:-`, `Product:-`, `Variant:-` | hardcoded English, **all six absent** from `ar.json`, used 2–10 times each |
| `Total Spend` | `{{ $totalSpend['total_spend'] }}.00` — a hardcoded `.00` and **no currency symbol at all** |

So an Arabic merchant read a register entry whose first two labels were Arabic and whose next
three were English, **in one card**, under a chip reading the raw database string `register`.

## The defect the duplication was hiding

The `$key % 2` alternation emits the whole seven-type block **twice** — 216 lines against 214,
**95.8% identical**. Diffing the two arms line by line found that most differences are the
`tl-container right` / `left` class, and **one that is not**:

```
line 173  {{ json_decode($value->remark)->product }}                      ← raw
line 389  {{ App\Models\ActivityLog::products(json_decode(...)->product) }} ← resolved
```

`ActivityLog::products()` turns a comma-separated list of product **ids** into **names**. Line 173
is the **only one of ten** product renders in the file that skips it. So an "add wishlist" event
showed a bare id on an even row and the product's name on an odd one — the same event reading
differently depending on row parity, because the two copies had drifted apart.

**That is the argument for the deduplication, found by measuring it rather than asserting it.**

## What this slice does, and what it leaves

**Done:** the line-173 defect; `App\Support\ActivityLogLabel` mapping the seven stored types
through `__()` (built in the shape of `App\Payments\PaymentTypeLabel`, which the sibling screen
already uses for this exact problem); the six hardcoded labels wrapped; thirteen keys added to both
language files; `Total Spend` through `currency_format_with_sym(…, getCurrentStore())` as
`customer/view.blade.php:99` does.

**Not done, and deliberately:** the 430-line deduplication of the two arms. It is a structural
change to a 520-line Blade file carrying six inline SVGs, and collapsing to a single-column rail
needs matching CSS geometry work in both `custom.css` and `rtl-custom.css`. The content fixes above
were applied to **both** arms, so nothing here is authored once and lost. The dedup stays open with
the line-173 finding as its evidence.

## A false claim written and corrected in the same session

`ActivityLogLabelTest`'s first docblock said *"`__()` falls through to the key when no translator
is bound"*. It does not — it throws `BindingResolutionException: Target class [translator] does not
exist`, and two of the five tests failed on it immediately. The test now extends the Laravel base
case and the docblock records the correction rather than hiding it.
