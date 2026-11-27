# NAV-004 — the four operator screens made discoverable, and the measurements behind it

Produced 2026-11-27 against the dev fixture (`database/p2dev.sqlite`), signed in as
`operator@example.test` (type `super admin`, holding production's `super admin` role via
`tests/Support/bin/grant-dev-operator-role.php`).

**`APP_DEBUG=false` and `DEBUGBAR_ENABLED=false` for every figure in this folder**, and
`curl <base>/login | grep -c phpdebugbar` is **0** on the instance these were taken from.
`CLAUDE.md` §6 records that with debug ON a contrast harness reads `span.phpdebugbar-badge` at
3.89:1 and files findings that are not in the product, and that **the number of badges varies by
surface** — so the control is the setting, never a subtracted constant. Both harnesses also exclude
`.phpdebugbar` by ancestor as a second line of defence, and the responsive one **fails the run**
if any such element is on the page rather than silently measuring it.

## What was wrong

Four operator screens were built, gated and tested, and reachable only by typing the uri:

| route | uri | built by |
|---|---|---|
| `kyc.queue` | `platform/verification` | the KYC workstream |
| `platform.approvals.index` | `platform/approvals` | `ws/p6-ctrl` |
| `platform.incidents.index` | `platform/incidents` | `ws/p6-ops` |
| `platform.operations` | `platform/operations` | OPS-001 |

All four were present in `int/checkpoint` and all four were driven. `OperatorPlatformNavigationTest`
was red on all four at once, which is the mechanism working: its subject set is **computed from the
booted route table** (every GET route carrying `PlatformOperatorOnly` at `platform/<one segment>`),
so no list typed into a test could have been short by them.

## The logs

| | |
|---|---|
| `measure-contrast-before-repairs.txt` | the four surfaces × four combinations **before** this slice's two repairs: **44 findings in 6 groups** |
| `measure-contrast-four-combinations.txt` | the same run after them, on the committed fixture: **14 findings in 2 groups**, 16 of 16 cells measured, 0 premise failures, 0 unreachable |
| `measure-responsive-four-widths.txt` | 390 / 768 / 1024 / 1440 px × four combinations: **64 of 64 measured, 0 premise failures, 8 findings**, all of them one screen's horizontal overflow |

Both runs abort every off-origin request and print the count (60 and 288 respectively,
`cdnjs.cloudflare.com` and `unpkg.com`), so neither depends on a network the next reader may not
have. If that total ever changes, retake the baseline rather than assume it still holds: an
aborted font could move the large-text floor.

## What the two repairs closed

Thirty of the 44 findings, both on `platform/approvals.blade.php` and both local to the view —
no stylesheet changed:

| finding | was | is |
|---|---|---|
| 14 × `<code>` per theme (28 in all): the governed-action keys | Bootstrap's own `#e83e8c`: **3.82:1** light, **3.73:1** dark | `.ss-code` — `--ss-fg` on `--ss-surface-sunken`, the class every other operator screen with a `<code>` already uses |
| 2 × `btn-sm btn-outline-danger` "Reject" per theme (4 in all) | the host's `#ff3a6e`: **3.45:1** light, **4.12:1** dark — failing in BOTH, and worse in light | `.ss-btn--danger-quiet` — `--ss-danger`, **6.25:1** light and **7.47:1** dark |

## What remains, and it is one defect wearing two selectors

All 14 remaining findings are a single colour pair, dark only, **0 in light**:

```
rgb(4,34,42) on rgb(51,93,168)   2.58:1 (needs 4.5)
  x8  span.dash-mtext                              all four surfaces, both languages
  x6  button.btn.btn-primary / .btn.btn-sm.btn-primary
      "Approve document", "Approve verification"   /platform/verification
      "Approve and perform"                        /platform/approvals
```

**The `span.dash-mtext` half is `D-25` and is pre-existing.** `gap-179-superadmin-ux-slice-3`'s
accepted baseline carries the identical pair at `x22 on 11 surfaces`, and `/platform/revenue` and
`/platform/resources` — group members since ADM-001 — were re-measured here and show it too. What
NAV-004 changes is its reach: four more surfaces now have an active entry in that group.

**The root cause is a one-hyphen token-name mismatch, and the CSS half of it was already found
and fixed once.** `store-syria-ds.css` records it: *"THE TOKEN NAME WAS `--ss-onbrand` AND THE
DECLARED ONE IS `--ss-on-brand` — one hyphen, and it cost a dark-mode contrast failure on every
active sidebar and list item."* The consumers were corrected. **The producer was not.**
`resources/views/layouts/app.blade.php`'s first-in-body luminance script — the mechanism
`custom.css` documents as the fix for exactly this ("white or near-black, whichever the merchant's
own colour actually contrasts with, chosen by measured WCAG ratio") — still writes
**`--ss-onbrand`**, and no stylesheet reads that name: every live occurrence of it in
`public/css/` is inside a comment.

Measured in the running application, dark, on `/platform/verification`:

```
document.documentElement.style['--ss-onbrand']     "#ffffff"   <- what the script wrote
getComputedStyle(root)['--ss-on-brand']            "#04222a"   <- what the CSS reads
button.btn.btn-primary  color rgb(4,34,42) on rgb(51,93,168)   2.58:1
```

Writing the script's own value to the **hyphenated** name repaired the sidebar label live:
`rgb(255,255,255)` on `rgb(51,93,168)` = **6.42:1**. The `.btn-primary` label did **not** move under
the same override; CDP's matched-styles confirms `body .btn-primary { color: var(--ss-on-brand, #fff) }`
is the winning declaration, so why the override does not reach it is **not resolved here** and is
stated rather than guessed.

**This slice does not take that fix.** `D-25` is an owner scope decision — the files that carry it
(`layouts/app.blade.php`, `custom.css`, `partision/menu-tree.blade.php`) render on every merchant
screen across all 70 vendored modules, and the traceability records that *the fix is reserved, not
the finding*. What is new here is the located cause and a live demonstration of the repair on one
of the two selectors.

## Responsive

Sidebar reachability, measured by opening the shell's toggle and the group the way a person does,
then requiring the link to have a box **inside the viewport** and `elementFromPoint` at its own
centre to land on it (the check that catches a link under a backdrop, which no geometric test can):

```
  390px   16 of 16 (screen x combination) reached their own sidebar link
  768px   16 of 16
 1024px   16 of 16
 1440px   16 of 16
```

Horizontal document overflow — `documentElement.scrollWidth - clientWidth`, which is what a person
feels as "the page slides sideways":

| screen | 390px | 768px | 1024px | 1440px |
|---|---|---|---|---|
| `/platform/verification` | +0 | +0 | +0 | +0 |
| `/platform/approvals` | **+484 / +438** | **+118 / +72** | +0 | +0 |
| `/platform/incidents` | +0 | +0 | +0 | +0 |
| `/platform/operations` | +0 | +0 | +0 | +0 |

(LTR / RTL; both themes identical.) **`/platform/approvals` is not acceptable at phone or tablet
width.** It is **pre-existing**: reverting this slice's two view repairs and re-running reproduced
+438 / +72 / +392 / +26 byte-for-byte on the one-row fixture. Bisection attributes it to the
screen's own `table.ss-table`, 1184px wide, driven by a 735px Decision column: hiding the table
drops `documentElement.scrollWidth` from 828 to 390. The wrapper is a `.table-responsive` whose
computed `overflow-x` **is** `auto` and whose box **is** 316px, so why it does not contain the table
is unresolved — `contain: paint` on it fixes the figure where `overflow-x: hidden` does not.
`/platform/incidents` has the same `.table-responsive` + `.ss-table` shape with a 961px table and
overflows by 0, so the difference is the table's width rather than the wrapper.

## Captures

`sidebar-group__<combo>__<width>.png`, at `deviceScaleFactor: 2`, showing the group expanded with
all ten children. Four of the eight the run produced are kept (light-en and dark-ar at 1440 and
390); all eight hashes differ, which the run asserts, because two byte-identical captures of
different combinations are a harness fault and not a page that looks the same.

## Reproducing

```bash
# the fixture, from scratch; APP_DEBUG=false and DEBUGBAR_ENABLED=false in .env FIRST
rm -f database/p2dev.sqlite && touch database/p2dev.sqlite
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/seed-p2dev.php');"
DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php artisan tinker --execute="require base_path('database/enable-p2dev-modules.php');"
APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/p2dev.sqlite CACHE_STORE=file \
    php tests/Support/bin/grant-dev-operator-role.php
touch storage/installed

docs/development/phase-2.5/tools/serve-dev.sh      # or the same four variables by hand
node tests/Support/bin/measure-superadmin-premium-live.cjs \
     --surfaces /platform/verification,/platform/approvals,/platform/incidents,/platform/operations
node tests/Support/bin/measure-operator-nav-responsive.cjs
```

### Two fixture rows are load-bearing rather than tidy

`seed-p2dev.php` now seeds the three queues' rows and **a second platform operator**, and both
matter for the same reason the coupon rows did in slice 3: *an empty grid hides a cell defect
exactly as an absent surface hides a screen, and neither reads as a failure.*

- Without the KYC, approval and incident rows, three of these four screens render an empty-state
  paragraph and nothing else. The first run against that fixture reported **0** findings for the
  verification queue — a figure about a sentence, not about the table, the pills or the decision
  forms.
- Without the **second operator**, every approval row is the viewing operator's own, so
  `platform/approvals` renders the refusal sentence and **its primary action never renders at all**.
  The run reported zero failing controls on that screen until the requester was changed; the same
  run then reported `button.btn.btn-sm.btn-primary` "Approve and perform" at **2.58:1** in dark.
  Pointing `requested_by` at the merchant instead would have been a shape the product cannot
  produce — the writer records the requesting operator's id — and two operators is what the feature
  needs to work at all: its own screen says *"distinct platform operators are required"*.

That new `users` row is visible to other harnesses: `/users` and `/stores` show one more row than
they did. Nothing in the PHPUnit suites reads this fixture (they run on `:memory:`), so the effect
is on the committed capture logs under `docs/development/phase-6/evidence/`, which should be
retaken rather than assumed.
