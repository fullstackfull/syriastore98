# P2-05-7 / P2-05-8 — the settings screens, measured

56 screenshots of the Store Syria settings screens, Arabic (RTL) and English (LTR) × light and
dark, taken by `docs/development/phase-2/tools/shoot-settings-live.cjs` against the running
application and by `render-settings-panel.php` for the operator-only panels. Invented fixture
data only (`database/seed-p2dev.php`): **no real credential appears in any of them**, and the
gateway and storage secrets in the fixtures are obvious inventions (`sk_INVENTED_…`,
`FIXTURE-S3-SECRET-DO-NOT-RENDER`) chosen so that a leak would be unmistakable in a screenshot.

**All 56 are distinct files.** The harness hashes them and fails if two configurations produce the
same bytes, because "four configurations" that are really two is a false reading this phase has
already produced once.

## What to look for

**No credential field carries a value, in any shot.** The payment tab alone renders 58 masked
secret fields across 52 gateways; before this slice every one of them rendered its stored value
into the DOM. Each masked box says whether something is saved and what an empty save does, and
never what the value is — not its length, not a prefix.

**Every control has a visible label.** The storage panel had fourteen fields whose label pointed
at nothing; the OTP panel had nine; the mail fragment had one. Compare the Arabic and English
shots of the same panel: the label text changes, the association does not.

**The dark shots are the ones worth checking.** Light mode hid the defects below for months,
because the vendored admin theme's hard-coded text colours happen to read correctly on white.

## The numbers behind them

Every text node's computed colour is resolved against the first ancestor with a non-transparent
background and the WCAG 2.x ratio computed — 546 checks across the 14 panels and four
configurations.

| what | before | after | threshold |
|---|---|---|---|
| readonly mail fields, dark (an inline script forcing `#e9ecef`) | **1.84:1** | 6.79:1 | 4.5 |
| currency row headers and cells, dark | **1.02:1** | 11.1:1 | 4.5 |
| 52 payment gateway names, dark | **1.02:1** | — see below | 4.5 |
| reCAPTCHA help link, light | **2.59:1** | 5.68:1 | 4.5 |

Control counts, measured rather than assumed, and asserted as floors so a tab that fails to
render cannot pass as a clean result: email 9 (after its second fetch), brand 19, system 4,
twilio 5, stock 5, otp 11, currency 35, **payment 311**.

## What these shots do NOT prove, stated plainly

- **They predate the F140 root-cause fix.** The run that produced them is the run that FOUND it:
  `.ss-card` themed its background and not its text, so anything inside a card without an `ss-*`
  class kept the admin theme's colour. The dark currency and payment shots here still show that
  defect. They are kept because they are the evidence for the finding.
- **The three operator-only panels are RENDERED, not driven.** `app/Http/Middleware/XSS.php`
  redirects every `super admin` to the Laravel Updater whenever a migration file is unrecorded,
  and the preview database has 96 unrecorded WorkDo module migrations, so the operator cannot
  open any admin page here. Those shots prove the panel's markup and the real stylesheets in a
  real browser; they do not prove the route, the permission gate or the AJAX.
- **Nothing here is a MySQL result.** Production is MySQL; this container has no MySQL client and
  the suite runs on SQLite, whose type affinity has hidden a real defect in this project before.
- **A clean browser run is not a clean test suite**, and neither is a release gate.
