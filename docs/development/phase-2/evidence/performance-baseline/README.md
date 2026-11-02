# Master prompt §6 — the frontend performance baseline

**This closes the one obligation that had no artifact anywhere in the repository.**
`PHASE-02-COVERAGE-AUDIT-2026-10-25.md`'s re-derivation named it:

> **Section 6 — "compare actual frontend performance against a measured baseline".** There is **no
> artifact anywhere in the repository**: no Lighthouse run, no LCP, no TTFB. Not started, not
> deferred by decision, and not previously recorded as missing.

Data: [`baseline.json`](baseline.json).
Harness: [`../../tools/measure-performance-baseline.cjs`](../../tools/measure-performance-baseline.cjs).

```bash
node docs/development/phase-2/tools/measure-performance-baseline.cjs
```

## Read this before quoting any number

These are measured in **this container**: a cloud sandbox running the PHP built-in server against a
SQLite fixture, with **no opcache warm, no gzip, no HTTP/2 and no CDN**. The absolute values say
nothing about production and must not be reported as if they did.

What they are good for is the thing §6 asks for: a **recorded starting point on identical hardware**,
so a later run of the same harness can show whether a change made a merchant screen slower.

**Method.** Four navigations per URL; **run 1 is discarded as a warm-up** and the median of the
remaining three is recorded. A cold first navigation pays for route-cache population, view
compilation and the autoloader — the `coldTtfb` column keeps that cost visible rather than hidden,
and it runs up to 385ms against a warm 240ms. A single-run baseline would have recorded compilation
as page cost and made every later comparison read as a large improvement. CLS is the **worst** of
the three, not the median, because a layout shift that happens sometimes is still a layout shift.

**Both directions**, because `layouts/app.blade.php` picks between `style.css`, `style-dark.css`,
`style-rtl.css` and the dark RTL variant **server-side**, so LTR and RTL do not load the same bytes.
A single-direction baseline would be a baseline for half the merchants.

**No Lighthouse score, deliberately.** Lighthouse's number is a function of its simulated-throttling
model, and against a dev server in a shared sandbox it varies more with neighbour load than with the
code. The primitives it derives the score *from* are what is recorded, because those are what a
later run can meaningfully compare.

## The baseline

| screen | dir | TTFB | FCP | LCP | load | CLS | resources | weight |
|---|---|---|---|---|---|---|---|---|
| dashboard | ltr | 263 ms | — | **796 ms** | 712 ms | **0.0826** | 61 | 4420 KiB |
| order-list | ltr | 192 ms | — | 432 ms | 509 ms | 0.0325 | 64 | 4478 KiB |
| product-list | ltr | 255 ms | — | 516 ms | 579 ms | 0.0569 | 64 | 4472 KiB |
| customer-list | ltr | 259 ms | — | **864 ms** | 580 ms | 0.0209 | 65 | 4462 KiB |
| coupon-list | ltr | 196 ms | — | 684 ms | 478 ms | 0.0054 | 64 | 4441 KiB |
| settings | ltr | 235 ms | — | 468 ms | 549 ms | 0.0019 | 56 | 4320 KiB |
| dashboard | rtl | 240 ms | — | 760 ms | 682 ms | **0.0838** | 61 | 4421 KiB |
| order-list | rtl | 204 ms | — | 360 ms | 492 ms | 0.0326 | 64 | 4486 KiB |
| product-list | rtl | 196 ms | — | 404 ms | 509 ms | 0.0542 | 64 | 4482 KiB |
| customer-list | rtl | 231 ms | — | **828 ms** | 555 ms | 0.0205 | 65 | 4466 KiB |
| coupon-list | rtl | 192 ms | — | 736 ms | 506 ms | 0.0052 | 64 | 4443 KiB |
| settings | rtl | 177 ms | — | 376 ms | 452 ms | 0.0016 | 56 | 4321 KiB |

`weight` is `decodedBodySize` summed over the navigation and every resource — what the page weighs,
independent of cache state. `baseline.json` carries `transferBytes` beside it.

## Three observations, recorded and not acted on

Phase 02's scope is merchant experience, not a performance programme, and none of these is a
regression introduced by it. They are written down so the baseline is useful rather than merely
present.

1. **Every merchant screen ships ~4.3–4.5 MiB across 56–65 requests, and it is essentially the
   SAME payload on every screen.** The spread across six very different pages is under 4%, which
   means the weight is the shared vendored theme bundle rather than page content. That is where any
   future work would have to start; trimming a page cannot move a figure a page does not control.

2. **The dashboard is the slowest screen and the only one with a notable CLS** (0.0826 LTR /
   0.0838 RTL, against 0.0019 on settings). Still inside the 0.1 "good" threshold, so it is a
   watch item rather than a defect — but it is the screen WS C rebuilt, and it is the one to
   re-measure after any change to the card grid or the charts.

3. **`customer-list` has the worst LCP on both directions** (864 / 828 ms) while being no heavier
   than its siblings, so the cost is rendering rather than transfer.

## What is not measured here

- **No production figures**, and no way to obtain them from this container.
- **No Lighthouse score** — see above.
- **FCP reads 0** in this environment and is left blank rather than reported as zero.
- The two **declared-failing** and seventeen **declared-skipped** suite outcomes are unrelated to
  this document; see `.github/known-test-outcomes.json`.
- Per **D-30** this is neither owner acceptance nor release readiness.
