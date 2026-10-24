# Phase 02 — design decisions for the shared layer and the dashboard

Design pass for P2-01 (shared components) and P2-02 (merchant dashboard), written **before**
implementation, as the owner's direction requires.

**Design authority, in order:** (1) `docs/design-system/` — the project's own verified system, which
is the implementation foundation and the only design system; (2)
`docs/design-system/ADMIN-LAYOUT-ARCHITECTURE.md` — the forward architecture, already approved;
(3) the `frontend-design` and `ui-ux-pro-max` skills the owner installed, as guidance. Where (3)
disagrees with (1), (1) wins — §4 records the two places that happened and why.

---

## 1. The brief, stated plainly

A merchant running a Syrian online store opens this panel to answer three questions, in this order:

1. **What needs me today?** — orders waiting, stock about to run out, a payment that failed.
2. **Is the shop working?** — are orders arriving at the usual rate.
3. **Let me go do a thing** — add a product, find order #4312, change a price.

Everything else is reference material. The current dashboard answers question 2 and a half of 3, and
answers question 1 not at all — it opens with eight equally-weighted count tiles, which is a
*summary*, not a *worklist*.

**Audience:** a shop owner or an employee, often on a phone, often in Arabic, usually in a hurry.
Not an analyst. The design consequence: **one obvious next action per screen**, and no number on
screen that the merchant cannot act on or trust.

## 2. Visual direction — what is fixed, and what is left to decide

Fixed by the existing system, and not re-opened: the teal brand ramp derived from the merchant's own
logo (`--ss-brand #097465`, pinned at 4.5:1 on white), the host's inherited font family, the three
token layers, logical properties for RTL, the ARIA-driven component states, `:focus-visible` rings.

What was actually left to decide, and the decisions:

**Hierarchy over uniformity.** `frontend-design` names the failure mode this dashboard currently
exhibits — *"content chopped into identical rounded cards, one border-radius on everything
regardless of hierarchy, the same soft grey shadow under each."* So the dashboard gets **three
distinct weights**, not one card style repeated:

| Weight | Used for | Treatment |
|---|---|---|
| **Attention** | things that need the merchant today | `--ss-radius-lg`, a 3px inline-start accent bar in the severity colour, `--ss-shadow-sm`. One per dashboard, at the top |
| **Measure** | counts and the order-value figure | no card at all — a bare grid on the page background, hairline `--ss-border` dividers between cells, tabular figures. Numbers do not need a box |
| **Reference** | trend chart, top products, recent orders | `.ss-card`, `--ss-radius-md`, `--ss-shadow-xs`. Quiet |

Making the *measures* boxless is the one deliberate departure from the host's existing look, and it
is what buys the attention block its emphasis for free: if everything is a card, nothing is.

**Status colour is never the only signal.** Order status carries a shape as well as a hue — the
existing `.ss-badge__dot` plus text. Required by `color-not-only`, and it is also the only way the
seven `delivered_status` values stay distinguishable in dark mode.

**Rejected, as named defaults rather than choices:** tracked-out ALL-CAPS eyebrow labels above
headings; `·`-joined meta strings; `→` appended to button labels; a monospace face for data labels;
gradient washes as decoration; a fade-and-slide-up entrance on every section.

**Motion:** one orchestrated moment only — the side panel sliding from its trigger edge, and the
skeleton-to-content swap. No hover transition on cards. `prefers-reduced-motion` already handled by
the system.

## 3. Screens and components, as wireframes

### 3a. Merchant dashboard (LTR; RTL mirrors through logical properties)

```
┌───────────────────────────────────────────────────────────────────────────┐
│  Dashboard                                    [ Add product ] [ Add order ]│   ss-page-header
│  Linen & Co · Damascus                                                     │   store name, plain
├───────────────────────────────────────────────────────────────────────────┤
│ ▌ Needs attention                                                          │   ATTENTION weight
│ ▌  12 orders waiting to be confirmed            → Confirm orders           │   accent bar = severity
│ ▌   3 products at or below the stock threshold  → Review stock             │   only rows that apply
│ ▌   (nothing here when nothing applies — the block disappears entirely)    │
├───────────────────────────────────────────────────────────────────────────┤
│  Orders        Order value       Customers      Products                   │   MEASURE weight
│  1,284         1,284,500 SYP     412            318                        │   tabular figures
│  all time      all time, gross   all time       active                     │   period stated on EVERY cell
│  ·········│·················│··············│············                   │   hairline dividers, no cards
├──────────────────────────────────┬────────────────────────────────────────┤
│  Orders, last 7 days             │  Recent orders                          │   REFERENCE weight
│  ┌────────────────────────────┐  │  #4312  Nour H.    2 items   pending ●  │
│  │        ╱╲      ╱           │  │  #4311  Omar S.    1 item    shipped ●  │
│  │   ╱╲  ╱  ╲   ╱             │  │  #4310  Lina K.    5 items   delivered● │
│  │  ╱  ╲╱    ╲ ╱              │  │  #4309  Sami A.    1 item    cancelled● │
│  └────────────────────────────┘  │                     View all orders     │
│  Show as table ▾ (a11y fallback) │                                         │
├──────────────────────────────────┴────────────────────────────────────────┤
│  Top products (by times ordered)          Latest products                  │
└───────────────────────────────────────────────────────────────────────────┘
```

**Honesty rules applied to this screen, item by item.**

- `Order value` is `sum(final_price)`, labelled **"gross, all time"** and never "revenue",
  "income" or "profit". Phase 01 established *Delivered is not Paid*, and `HANDOFF §13c` records
  that an order's stored components cannot always reproduce its own `final_price` — so this figure
  is shown as persisted and **nothing is derived from its parts**.
- **No profit or margin tile.** The codebase computes neither. An honest absence beats a fabricated
  number.
- **Every measure states its period.** A count with no period is the commonest dashboard lie.
- **The nine `*Per` "trend" percentages are not displayed.** `calculatePercentageToday()` computes
  today against the *all-time total*, which is a large negative number for any store with history,
  not a day-over-day change. They are currently computed and thrown away (verified: zero view
  references), so this screen does not resurrect them.
- **Top products is "by times ordered", not "best sellers"** — it counts appearances in
  `orders.product_id`, which is an order count and not a revenue ranking.
- **The attention block renders only rows that apply**, and vanishes when none do. A permanently
  present "0 items need attention" panel trains merchants to ignore the region.

**Chart.** Line chart, per `chart-type` for a 7-point time series. Series distinguished by line
style and a direct label, never by hue alone. A **"Show as table"** disclosure gives the same data
as a real `<table>` — `data-table` and `screen-reader-summary` both require a non-visual path to the
same facts, and the existing chart stack offers none.

### 3b. Side panel (`.ss-panel`) — the surface that is missing

```
            ╔═══════════════════════════════╗
  list      ║  Order #4312              [×] ║  480px; 100% under 768px
  stays     ║ ─────────────────────────────  ║  enters from the inline-end edge
  visible   ║  Customer   Nour H.           ║  (mirrors in RTL automatically)
  behind    ║  Placed     12 Oct, 14:22     ║
  the       ║  Status     ● pending         ║  focus moves to the heading on open
  scrim     ║  Total      12,500 SYP        ║  and returns to the trigger on close
            ║  ─────────────────────────    ║  Esc closes; the list cannot scroll
            ║  2 items …                    ║  while it is open
            ║ ─────────────────────────────  ║
            ║  [ Open full order ]          ║  one primary action
            ╚═══════════════════════════════╝
```

Three surfaces, with a rule for choosing between them, so the codebase stops deciding ad hoc:

| Surface | For | Width |
|---|---|---|
| **Side panel** | inspect a record, or edit 1–5 fields, without losing the list | 480px, full-width < 768px |
| **Dialog** | confirmations, and single-purpose forms | 520px |
| **Full page** | tabs, media, or more than ~8 fields | — |

**No nested modal workflows.** A panel never opens a second panel; it opens a dialog or navigates.

It reuses the existing `data-ajax-popup` contract with `data-surface="panel"`, so a screen opts in by
changing one attribute and no controller changes. **Both existing modal id spellings
(`#commanModel`, `#commanModelOver`, and `pos.index`'s `#commonModal`) keep working untouched.**

### 3c. Sticky action bar (`.ss-actionbar`) — and the trap in it

```
│  … long product form …                         │
│                                                │
├────────────────────────────────────────────────┤
│  ⚠ 2 fields need attention   [Cancel] [ Save ] │   sticky to the viewport bottom
└────────────────────────────────────────────────┘   role="region", a labelled landmark
```

A sticky bar is an accessibility hazard before it is a convenience: `focus-not-obscured` (WCAG 2.2
AA, and this is the **High**-severity one) is violated the moment it covers a focused field. So the
bar ships with `scroll-padding-block-end` on the scroll container equal to its own height, as part
of the component rather than as a note in a doc. Same for the sticky header and
`scroll-padding-block-start`.

**Unsaved changes:** a `beforeunload` guard plus a visible marker, armed on first real input — not on
focus, and not on a programmatic value change, or the guard cries wolf and merchants learn to click
through it.

**Validation:** a focusable summary at the top of the form (`role="alert"`, `tabindex="-1"`, each
item a link to its field) **and** inline field errors. The summary complements inline errors, it does
not replace them, and focus moves to it only after a failed submit.

### 3d. Navigation — zones inside the one sidebar

```
┌──────────────────────┐
│ ◆ Linen & Co      ▾  │  workspace chip: store name + plan, switcher
│   Damascus           │  a per-store accent on the chip only
├──────────────────────┤
│ SELL                 │  groups by the merchant's job, not by module name
│   Orders             │
│   POS                │
│ CATALOGUE            │
│   Products           │
│ ...                  │
├──────────────────────┤
│ Help · Account       │  pinned to the bottom
└──────────────────────┘
```

**The hard constraint, and it is a security property.** The sidebar is not a Blade file: it is a
hand-concatenated HTML string from `generateMenu()` / `generateSideMenu()`, and every item passes
`Menu::add()`'s two gates — module entitlement (`ActivatedModule()`) **and** Laratrust
`isAbleTo()`. So:

- the two gates do not move, and nothing new renders a nav item without passing them;
- the emitted DOM stays **selector-compatible**, because the inline sidebar search at
  `app.blade.php:358-398` selects `.dash-navbar > .dash-item` with **direct-child** combinators —
  wrapping an item in a new element breaks search *with no error*;
- `getCurrentStore()` semantics are frozen. The chip changes how the store is **displayed**, never
  how it resolves.

The accent is a `--ss-brand-accent` override **on the chip wrapper only**. It must not touch
`--ss-brand`, or the 40 verified contrast pairs stop meaning anything.

## 4. Where the installed skills were overruled, and why

Both are recorded because an unexplained departure from a tool the owner chose looks like an
oversight.

1. **`ui-ux-pro-max` recommends a Fira Code / Fira Sans "Dashboard Data" pairing** for data-heavy
   admin panels. **Rejected.** The design system deliberately inherits the host's own family via
   `var(--bs-body-font-family)` so no second webfont is downloaded, and Arabic families are declared
   ahead of any Latin fallback so an Arabic glyph is never served from a Latin font's substitution
   table. Adding Fira would break both. Worse, `frontend-design` independently names *"a monospace
   face for small data labels"* as a tell of a generated page. The actual requirement behind the
   recommendation — `number-tabular`, stop columns of figures shifting — is met by
   `font-variant-numeric: tabular-nums` on the existing family. Same benefit, no new font, and it
   works for Arabic-Indic digits too.
2. **`ui-ux-pro-max`'s `--persist` writes a `design-system/<project>/MASTER.md`.** **Not used.** The
   owner's standing instruction is that no second design system is introduced; a generated MASTER
   beside `docs/design-system/` is exactly that. The skill is used as research, and its conclusions
   land in this file and in the stylesheet.

Also noted: its touch-target guidance (44×44pt) is **mobile-app** scope. For the web panel the
governing rule is `web-target-size` — 24×24 CSS px (WCAG 2.2 AA) — and the skill says so itself.
The Flutter work in P2-07 is where the 44pt figure applies.

## 5. What "verified" will mean for each of these

Not negotiable, and not satisfiable by reading the diff:

- `check-contrast.py` passes with the new pairs added to it — light **and** dark.
- `browser-checks.cjs` passes, extended with checks for the new components.
- Screenshots exist under `docs/development/phase-2/screenshots/`, per screen, in
  **LTR/RTL × light/dark**, desktop and mobile widths.
- Keyboard traversal driven, not asserted: tab order, focus ring visible, focus moves into the panel
  and returns to the trigger, Esc closes, the sticky bar does not obscure focus.
- A screen that was only read is reported as **inspected**, never as verified.
