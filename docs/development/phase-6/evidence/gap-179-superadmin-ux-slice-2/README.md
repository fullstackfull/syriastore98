# `GAP-179` / `P06-06` slice 2 — captures and logs

Produced 2026-11-26 by `tests/Support/bin/measure-superadmin-premium-live.cjs` against the dev
fixture, signed in as `operator@example.test` (type `super admin`), at 1440×900, full page. The
reading is `../../PHASE-06-SUPER-ADMIN-UX.md` §6.

## Logs

| | |
|---|---|
| `measure-before.txt` | **94 contrast findings in 14 groups** across the four surfaces, 16 of 16 cells measured, 0 premise failures |
| `measure-after.txt` | **4 findings in 1 group** — the shared sidebar's active item only, which is `D-25` and excluded |

**The "before" log was produced by REVERTING the slice, not from an earlier run**, so it is a
revert proof as well as a baseline: the reverted tree reproduces 94 findings in the same 14 groups
with the same per-surface distribution. `/tmp`-side script, re-runnable from the same transforms.

**The first revert attempt read 96, not 94, and that was the revert's fault rather than a finding.**
It also undid `plans/create`'s two controls, which slice 1 had converted — so it overstated what
slice 2 fixed by 2. Scoping the revert to slice 2's own changes brought it to exactly 94.

## Captures

`<screen>__<theme>-<lang>.png`, the same name on both sides. Four surfaces × four combinations ×
before/after = **32 images, and all 16 pairs differ** (the harness also reports no two combinations
of a surface sharing a hash).

**All four combinations are kept for every surface here**, unlike slice 1's button screens. The
reason is in the measurement: the biggest defect on these screens, `.text-danger` rendering as
plain `red`, measures **4.00:1 on a white row and 3.56:1 on the dark card** — it fails in BOTH
themes, so a light capture carries a real finding rather than a change with nothing behind it.

Every dark capture was taken only after the page was asserted to really be dark — `data-ss-theme="dark"`,
`style-dark.css` in `#main-style-link`, and a body background of exactly `rgb(34,36,44)`. The admin
theme is the `cust_darklayout` setting, not `prefers-color-scheme`.

## Reproducing

```bash
docs/development/phase-2.5/tools/serve-dev.sh

# WITHOUT THIS, SIX OPERATOR SCREENS ANSWER redirect()->back() -- HTTP 200 and the PREVIOUS page.
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
  php tests/Support/bin/grant-dev-operator-role.php

node tests/Support/bin/measure-superadmin-premium-live.cjs \
  --surfaces /plan,/plan/create,/plan/1/edit,/countries --out <dir>
```

`/plan/1/edit` uses the dev fixture's only plan (`plans.id = 1`). That surface was **not** in slice
1's list, and adding it is what exposed `plans/edit.blade.php` still carrying the 2.58:1 primary
button slice 1 existed to close.
