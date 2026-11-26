# `GAP-179` / `P06-06` slice 3 — captures and logs

Produced 2026-11-26 by `tests/Support/bin/measure-superadmin-premium-live.cjs` against the dev
fixture, signed in as `operator@example.test` (type `super admin`), at 1440×900, full page. The
reading is `../../PHASE-06-SUPER-ADMIN-UX.md` §14–§18.

**`APP_DEBUG=false` for every figure in this folder**, plus `DEBUGBAR_ENABLED=false`, and
`grep -c phpdebugbar` over a served page is **0**. `CLAUDE.md` §6 records that with debug ON a
contrast harness reads `span.phpdebugbar-badge` at 3.89:1 and files four findings per theme that
are not in the product — eight per surface. The harness now also excludes `.phpdebugbar`
**by ancestor** as a second line of defence; see below for the proof that adding it changed nothing
on this instance.

## Logs

| | |
|---|---|
| `measure-25-surfaces-unmodified-harness.txt` | the **UNMODIFIED** harness, slice 2's 25 surfaces: **24 findings in 2 groups**, 100 of 100 cells measured, 0 premise failures, 0 unreachable |
| `measure-all-27-before.txt` | the modified harness, **27** surfaces: **46 findings in 10 groups**, 108 of 108 measured, 0 premise failures |
| `measure-all-27-after.txt` | after the three repairs: **22 findings in 1 group** — the shared sidebar and settings rail only, which is `D-25` and excluded |
| `measure-before.txt` | the three repaired surfaces, **reverted**: **24 findings in 10 groups**, 12 of 12 measured |
| `measure-after.txt` | the same three surfaces repaired: **0 findings in 0 groups** |

### The instrument was changed, and the change is proved to be a no-op

Two changes were made to the harness before the baseline was taken, so that the baseline and the
result come from the same instrument:

1. `.phpdebugbar` excluded **by ancestor** in the visibility filter.
2. **Off-origin requests aborted**, so the run makes no egress, with the aborted count printed.

`measure-25-surfaces-unmodified-harness.txt` and `measure-all-27-before.txt` are the before/after
of that change on the SAME 25 surfaces. Both report **24 findings in 2 groups**, and the
per-surface tables are byte-identical once the two new surfaces are removed. So the 22 extra
findings in the second log come from the two added surfaces and the two fixture rows, not from the
instrument.

**That matters more than it looks, because the abort is not idle:** the admin chrome really does
reach off-origin, and the run reports **366–367 aborted requests** — `cdnjs.cloudflare.com` ×56,
`unpkg.com` ×28, `ecom-demo.workdo.io` ×6 and `workdo.io` ×1–2 per combination. Aborting a font
could have moved the large-text floor (≥24px, or ≥18.66px at weight ≥700) and therefore the
counts. Measured, it did not move a single one. If that total ever changes, retake the baseline
rather than assume it still holds.

## Captures

`<screen>__<theme>-<lang>.png`, the same name on both sides. Three surfaces × four combinations ×
before/after = **24 images, and all 12 pairs differ**; the harness separately reports no two
combinations of a surface sharing a hash.

All four combinations are kept for every surface, because every repair here fails in at least one
theme and two of the three fail in BOTH: the `/plan-coupon/{id}` grid tools measure 2.66 / 1.73 /
1.74:1 in light and 1.39 / 1.30 / 1.15 / 1.16:1 in dark, against WCAG 2.1 1.4.11's 3:1 non-text
floor. A light capture therefore carries a real finding rather than a change with nothing behind
it.

Every dark capture was taken only after the page was asserted to really be dark —
`data-ss-theme="dark"`, `style-dark.css` in `#main-style-link`, and a body background of exactly
`rgb(34,36,44)`. The admin theme is the `cust_darklayout` **setting**, not `prefers-color-scheme`.

## The "before" log is a revert proof, not an earlier run

`measure-before.txt` was produced by restoring the three views to their committed state with
`git checkout --`, clearing the compiled views, and re-running the same harness invocation. The
reverted tree reproduces **exactly 24 findings in 10 groups** with the same per-surface
distribution; restoring the repairs returns it to **0 in 0 groups** and the harness exits 0.

`php artisan view:clear` between every transform is not optional. Blade recompiles only when the
source is NEWER than the compiled file, so a revert whose mtime lands earlier than the compiled
view renders the previous version — measured in this repository in both directions, which is how a
mutation once read as caught by a test that never saw it.

## Reproducing

```bash
# the fixture, from scratch; APP_DEBUG=false and DEBUGBAR_ENABLED=false in .env FIRST
rm -f database/p2dev.sqlite && touch database/p2dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/seed-p2dev.php');"
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/enable-p2dev-modules.php');"

# WITHOUT THIS, SIX OPERATOR SCREENS SILENTLY MEASURE THE PREVIOUS PAGE
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php tests/Support/bin/grant-dev-operator-role.php

# `storage/installed` must exist or every route redirects to the installer
touch storage/installed

docs/development/phase-2.5/tools/serve-dev.sh          # or the same four variables by hand
node tests/Support/bin/measure-superadmin-premium-live.cjs
```

`seed-p2dev.php` now seeds **one plan coupon and one redemption row**, and both are load-bearing
rather than tidy. Without the coupon, `/plan-coupon` shows an empty grid,
`plan-coupon/action.blade.php` never renders, no `data-ajax-popup` trigger for `plan-coupon.edit`
exists on any page, and `/plan-coupon/{id}` does not resolve — so both screens were
**unmeasurable, not clean**, which is exactly how they kept the 2.58:1 button slice 1 existed to
close. Without the redemption, `/plan-coupon/{id}`'s grid has no data row, so its order-reference
cell is never built and a second `btn-primary` fill measures as nothing. The same empty grid also
made a capability probe read `.select-row` as 0 on both sides and call a destructive control
harmless (§16.2).

## The falsifiability check, run here too

```bash
php docs/development/phase-2/tools/set-admin-preview-state.php 1 light ltr en operator@example.test
node tests/Support/bin/measure-superadmin-premium-live.cjs --combos dark-en --skip-state
```

`--skip-state` writes nothing and still claims the combination it was asked for, so every premise
must fail and the run must report **0 contrast findings** — a harness whose dark pass silently
measured the light theme reads as a pass. The flag exists so that assertion can be shown to bite.
