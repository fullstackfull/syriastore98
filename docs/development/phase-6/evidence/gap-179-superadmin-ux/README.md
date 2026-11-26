# `GAP-179` / `P06-06` — captures and logs

Produced 2026-11-26 by `tests/Support/bin/measure-superadmin-premium-live.cjs` against the dev
fixture, signed in as `operator@example.test` (type `super admin`), at 1440×900, full page.

The reading of these numbers is `../../PHASE-06-SUPER-ADMIN-UX.md`. This file says only what is
here and what is not.

## Logs

| | |
|---|---|
| `measure-before.txt` | the whole surface before the slice — **388 contrast findings in 21 groups**, 96 of 96 surface × combination cells measured, 0 premise failures, 0 unreachable |
| `measure-after.txt` | the same run after — **98 findings in 16 groups**, same 96 of 96, 0 premise failures |

Each log carries the grouped census, the per-surface table and the sha256 prefix of every capture
the run took, so the four combinations of a screen can be seen to differ.

## Captures

`<screen>__<theme>-<lang>.png`, the same name on both sides, so `before/x.png` and `after/x.png`
are the same screen in the same combination.

**Every dark capture here was taken only after the page was asserted to really be dark** —
`data-ss-theme="dark"`, `style-dark.css` in `#main-style-link`, and a body background of exactly
`rgb(34,36,44)`. The admin theme is the `cust_darklayout` setting, not `prefers-color-scheme`, so
Playwright's colour-scheme emulation yields two identical light captures; a run that fails the
premise refuses to measure or capture at all.

### What is kept, and the rule

- **All four combinations** for `modules_list` and `platform_resources` — the two screens whose
  findings were in **both** themes (4.26:1 light / 1.03:1 dark, and 3.65:1 light / 4.05:1 dark).
- **Dark only** for `stores`, `plan_coupon` and `setting`. The control defect those screens carried
  is dark-only by construction: the host paints the same `.btn-primary` white-on-blue in light,
  which measures 6.42:1 and passes. A light before/after pair would show a change with no finding
  behind it.

The full 96-capture set of either run is reproducible from the logs in one command and is not
committed; the selection above is 11 MB and the Phase 2.5 precedent commits 168 MB.

### `setting__dark-en.png` and `setting__dark-ar.png` ARE BYTE-IDENTICAL BEFORE AND AFTER

sha256 `5c348c85daf08704` and `19511cde40aae9f7` on both sides. **That is deliberate and it is the
finding, not a mistake in the copy.** `setting/index.blade.php:283` was converted off the host
primary fill like the rest, and it sits inside
`<div id="delivery-syria-content" style="display: none;">` — it has never been rendered, it
measured 0×0 in the browser, and it was never among the failing controls. The pair is kept so the
identical hash is on the record rather than inferred. `../../PHASE-06-SUPER-ADMIN-UX.md` §3.5 has
the full reading.

## Reproducing

```bash
# the server the harness expects, with the fixture and the file cache store
docs/development/phase-2.5/tools/serve-dev.sh

# WITHOUT THIS, SIX OPERATOR SCREENS ANSWER redirect()->back() -- HTTP 200 and the PREVIOUS page --
# and the run photographs the predecessor while reporting success.
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
  php tests/Support/bin/grant-dev-operator-role.php

node tests/Support/bin/measure-superadmin-premium-live.cjs --out <dir>
```

The harness leaves the fixture light/en when it finishes, as every Phase 2.5 harness does, so the
next run starts level.
