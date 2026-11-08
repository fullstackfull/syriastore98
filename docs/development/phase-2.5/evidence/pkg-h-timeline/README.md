# P2.5-PKG-H — customer timeline, before and after

Captured from the **running merchant admin** against the dev fixture (sqlite `database/p2dev.sqlite`),
in all four combinations this project requires: English LTR and Arabic RTL, light and dark.

* `before/` — the view as of `8051bc5f`, the commit immediately before PKG-H slice 1. A **real
  revert**: the view and `app/Models/ActivityLog.php` were checked out from that commit, the
  compiled-view cache was cleared, the capture was taken, and the working tree was restored and
  verified clean with `git status`.
* `after/`  — the view as of `956be3ee`, PKG-H slices 1–7.

Both runs report HTTP 200, the requested direction, the requested theme, and the page title
`Customer Timeline` / `خط الزمن للعميل`.

**That title check is the point, not decoration.** A permission-gated merchant screen does not
error — it **redirects to the dashboard** — so a harness that records only status and theme reports
four clean rows over four dashboard screenshots. This project records that as the most dangerous
reading it can take, and it has already happened here once. The title is what proves the capture
reached the timeline.

## What the before capture shows, and none of it was found by reading code

| | before | after |
|---|---|---|
| Register card, **Email** | **`operator@example.test`** — the PLATFORM OPERATOR | the customer's own address |
| Register card, First/Last name | both **blank** | `Layla` / `Haddad` |
| Register card, Register Date | **blank**, with a stray dot | the real date, in a pill |
| Register card, Contact | `Contact:-` over **nothing** | the row is not drawn |
| Arabic timestamps | `Nov 2026 01:24 AM 07` — day displaced | `07 Nov 2026 01:24 AM` |
| Arabic login pill | `01:24:57 2026-11-01` | `2026-11-01 01:24:57` |
| Customer Email card | wraps **mid-word**: `layla.haddad@exa` / `mple.test` | identity moved to the page subtitle |
| The measures | `$550.00` and `55` with **no period** | `all time` under each |
| Event icons | 14 inline SVGs, six internal scales, 42 `fill="black"` | 7 Tabler glyphs inheriting `currentColor` |

### The e-mail in the before capture is a disclosure, not a typo

`ActivityLog::userinfo()` was `hasOne(User::class, 'id', 'customer_id')`. `customer_id` refers to
the **customers** table; that joined it against `users.id`. Customer #1 and user #1 both exist
because both id sequences start at 1, so the card rendered **user #1 — the platform operator**.
Customer #2 would render user #2, another merchant.

Four of the five fields were always blank because `users` has no `first_name`, no `last_name` and
no `regiester_date`. Only `email` and `mobile` resolved — precisely the two that leak.

This is in the picture at `before/_customer_timeline_1__en_ltr_light.png`. It is not inferable from
the template, it failed no test, and the package had already been declared complete when the
capture was taken.

## Scope of the claim

These images show that the listed defects are gone and the surface renders correctly in all four
combinations. They are **not** a classification: the inventory still records this screen at its
previous level, because PREMIUM is a judgement about a finished screen and that judgement is the
owner's. The known remaining items for this surface are recorded in
`PHASE-2.5-REPORT-AR-2026-11-08.md`.
