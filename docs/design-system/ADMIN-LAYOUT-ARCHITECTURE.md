# Store Syria — Administration Layout Architecture

Design foundations for **Phase 02** (merchant dashboard) and **Phase 06** (Super Admin).
Nothing here is implemented in Sprint 01. This document fixes the shape of the work so that the
design system built in Sprint 01 does not have to be rebuilt to accommodate it.

Each section states **what exists today** (with evidence), **what is proposed**, and **what
constrains it**. Where a proposal needs something from Backend/Core, it is flagged
**→ Claude A**.

---

## 0. The constraint that shapes everything

The admin sidebar is **not** a Blade file and **not** a config array. It is an event-driven PHP
registry: `partision/sidebar.blade.php:34-39` emits `{!! getSideMenu() !!}` or `{!! getMenu() !!}`,
and those helpers (`app/Helper/helper.php:48` and `:2013`) fire `CompanyMenuEvent` /
`SuperAdminMenuEvent`, collect items into `App\Classes\Menu`, then build the entire `<ul>` tree as
a **hand-concatenated HTML string** in `generateMenu()` / `generateSideMenu()`.

Every sidebar class — `dash-item`, `dash-hasmenu`, `dash-link`, `dash-mtext`, `dash-arrow`,
`dash-submenu`, `sub-menu-lnk` — is a PHP string literal in `helper.php`.

Two independent gates filter every item inside `App\Classes\Menu::add()` (`app/Classes/Menu.php:19`):

1. the item's `module` must be in `ActivatedModule()` — the SaaS entitlement gate; and
2. its `permission` must be empty or pass Laratrust `isAbleTo()`.

**Consequences for every proposal below:**

- Navigation cannot be restyled from a view. It is changed in `helper.php` or not at all.
- Any new navigation surface must register through `Menu::add()`, or it will silently bypass both
  the entitlement and the permission gate. **This is a security property, not a convenience.**
- `partision/header.blade.php` and `partision/sidebar.blade.php` contain **zero** `@yield`/`@stack`
  hooks. Adding anything to the header or sidebar requires editing those files. The empty
  `div.dash-center-drp` at `header.blade.php:70-73` is not an injectable hook.

**→ Claude A:** the single highest-leverage backend change for Phase 02 is to make
`generateMenu()` / `generateSideMenu()` return a **data structure** (array/DTO) that a Blade
partial renders, instead of a concatenated HTML string. The gates stay exactly where they are;
only the rendering moves. Without this, every navigation item in Phase 02 is string concatenation
in a helper, and the design system cannot reach it.

---

## 1. Merchant dashboard navigation

**Today.** One flat-ish sidebar built from the registry, plus a header with a store switcher, a
language dropdown, a quick-add dropdown and notification icons. There is no grouping beyond
submenu nesting, no pinning, no recents, and no indication of which store you are in beyond the
switcher's own label. An inline script at `app.blade.php:358-398` implements sidebar search by
selecting `.dash-navbar > .dash-item` with direct-child combinators and setting inline `display`.

**Proposed.** Keep one sidebar; add structure inside it.

- **Three fixed zones:** *Workspace switcher* (store context) at the top, *Sections* in the middle,
  *Account & help* pinned to the bottom.
- **Sections grouped by job, not by module name** — Sell (orders, POS), Catalogue (products,
  attributes, brands), Customers, Marketing, Reports, Settings. Module registration stays as-is;
  grouping is a `group` key on the menu item, defaulted so an unmigrated module still appears.
- **Collapsed rail** at ≥1200px wide screens, remembering state per user.
- **Search stays**, but moves to a command palette (§7) and keeps the existing DOM contract.

**Constraint.** Do not rename or wrap `.dash-navbar > .dash-item`, `.dash-link`, `.dash-mtext`
or `.dash-submenu`. The inline sidebar search selects exactly those with direct-child combinators;
a wrapper element breaks search **with no error**.

---

## 2. Workspaces

**Today.** "Workspace" is really *store context*: `getCurrentStore()` (`app/Helper/helper.php:386-412`)
is called from **1,411 sites**. The store switcher lives in the header
(`header.blade.php:131`).

**Proposed.** Make store context a first-class, always-visible part of the chrome rather than a
dropdown among dropdowns:

- A persistent workspace chip at the top of the sidebar: store name, plan badge, switcher.
- A **colour-coded accent per store**, so a merchant running several stores can tell at a glance
  which one they are editing. The accent is a `--ss-brand-accent` override on a wrapper element;
  it must not change `--ss-brand`, or contrast guarantees break.
- Destructive and financial screens restate the store name in the page header.

**Constraint.** `getCurrentStore()` semantics are frozen by the sprint brief and by its 1,411 call
sites. Nothing here changes how the current store is resolved — only how it is *displayed*.

---

## 3. Tabbed modules

**Today.** Tabs exist only within individual screens, as Bootstrap `.nav-tabs`, with no shared
pattern. `country/index.blade.php` is a 624-line three-tab page with three hand-wired DataTables
and ~60 lines of inline `<style>` duplicated once for LTR and once for RTL.

**Proposed.** One tab pattern (`.ss-tabs`, already built) with a fixed contract:

- Tabs are **views of one record or one collection**, never navigation to a different subject.
- The selected tab is in the URL (`?tab=`), so it survives reload, back, and deep links.
- Tab panels are lazy — a panel's DataTable initialises on first reveal, not on page load.
- A tab the user cannot access is **not rendered**, rather than rendered and disabled.

**Constraint.** `aria-selected` drives the visual state; keep the roving-tabindex keyboard
behaviour (Left/Right/Home/End) when the interactive version lands.

---

## 4. Contextual record previews

**Today.** Create and edit open in a **shared Bootstrap modal over AJAX** —
`data-ajax-popup="true"` with `data-url`, `data-size` and `data-title`. The global modal IDs are
route-dependent and misspelled: `#commanModel` and `#commanModelOver` everywhere except
`pos.index`, which gets `#commonModal`.

**Proposed.** Add a **side panel** (sheet) as a third option between modal and full page:

| Surface | Use for | Width |
|---|---|---|
| Side panel | Inspect a record, quick edit 1-5 fields, without losing list context | 480px, full-width under 768px |
| Modal | Confirmations and single-purpose forms | 520px |
| Full page | Anything with tabs, media, or more than ~8 fields | — |

The side panel reuses the existing `data-ajax-popup` contract with `data-surface="panel"`, so a
screen opts in by changing one attribute and no controller changes.

**Constraint.** Both modal ID spellings must keep working. Focus must move into the panel on open
and return to the trigger on close; the list behind must not scroll while it is open.

---

## 5. Full record editing pages

**Today.** Long single-column forms inside one `.card`, with the save button at the bottom.
There is no dirty-state tracking, so navigating away silently discards work.

**Proposed.**

- Two-column at ≥992px: primary fields in the main column, metadata/status/visibility in a
  narrower aside. One column below that.
- **Sticky action bar** at the bottom of the viewport carrying Save/Cancel and validation summary.
- **Dirty-state tracking** with a beforeunload guard and an explicit "unsaved changes" marker.
- Validation errors summarised at the top (focusable, linked to each field) *and* inline, with
  `aria-invalid` + `aria-describedby` — the contract the design system's field component already
  implements.

**→ Claude A:** a consistent validation-error response shape across controllers would let one
frontend component render every form's errors. Today each screen hand-rolls it.

---

## 6. Unified notifications

**Today.** Transient feedback is `show_toastr(...)`, loaded globally. There is a notifications
icon in the header. These are unrelated systems.

**Proposed.** Separate the two concepts explicitly, because they have different lifetimes:

- **Transient** (toast): the result of something *you* just did. Keep `show_toastr` as the API —
  it is called from hundreds of places — and restyle it to `.ss-alert` tokens. Must be
  `role="status"`, must not steal focus, must not auto-dismiss errors.
- **Durable** (inbox): something that happened *to the store* — a new order, a failed payment, a
  low-stock threshold. Grouped by type, unread count, mark-all-read, deep-links to the record.

**→ Claude A:** a single notifications table/endpoint with `type`, `severity`, `store_id`,
`read_at` and a target URL. The design can proceed against a documented shape before it exists.

---

## 7. Global search

**Today.** Sidebar menu search only (filters visible menu items). There is no record search.

**Proposed.** A command palette on `Ctrl/Cmd+K`, also reachable from a header control (keyboard
shortcuts alone are not discoverable, and are not an accessible substitute for a visible control):

- Searches **records** (orders, products, customers) and **actions** ("Add product") and
  **navigation**, grouped, keyboard-first.
- Results are gated by the *same* two gates as the menu — entitlement and permission. A merchant
  must never see a record in search they cannot open.
- Debounced, with an explicit empty state and a loading state (both already in the system).

**→ Claude A:** a search endpoint that applies `getCurrentStore()` scoping, Laratrust permissions
and module entitlement server-side. **Frontend filtering is not acceptable here** — it would leak
record existence across stores.

---

## 8. Contextual help

**Today.** Nothing systematic. Some screens carry a `<p><strong>Note :</strong> …</p>` paragraph;
the pilot screen was one of them.

**Proposed.**

- `.ss-field__hint` under a field for anything explainable in one line (already built).
- A help affordance in the page header opening a side panel with task-oriented guidance.
- Empty states carry the teaching, because that is when someone most needs it — the pilot screen
  demonstrates this.
- All help copy goes through `__()` and must exist in `ar.json` **at the time the feature ships**,
  not afterwards.

---

## 9. Super Admin design compatibility

**Today.** Super Admin uses the **same** `layouts/app.blade.php`, the same theme palettes and the
same chrome; it differs by menu registry (`SuperAdminMenuEvent`) and by `getSuperAdminAllSetting()`
replacing `getAdminAllSetting()`. Note that `$setting` is reassigned to the super-admin's settings
at `app.blade.php:415-417`, so code added below that line reads the wrong tenant's settings.

**Proposed.** Keep one design system and one layout. Differentiate by **information density and a
chrome accent**, not by a second visual language:

- Super Admin gets a distinct chrome accent so it is impossible to confuse with a merchant store.
- Denser table defaults, more columns, bulk actions prominent.
- Everything in §§1-8 applies unchanged; Phase 06 should need no new components, only new screens.

---

## Sequencing

| Order | Work | Blocked by |
|---|---|---|
| 1 | Menu registry returns data, not an HTML string | **→ Claude A** |
| 2 | Sidebar zones, grouping, workspace chip | 1 |
| 3 | Side-panel surface + record editing layout | — |
| 4 | Toast restyle (host-wide, `show_toastr`) | — |
| 5 | Host-wide dark tokens: an opted-in page already follows `cust_darklayout` (Sprint 01's opt-in partial); the host chrome itself still uses four swapped stylesheets | — |
| 6 | Notifications inbox | **→ Claude A** (schema + endpoint) |
| 7 | Global search | **→ Claude A** (scoped, permission-filtered endpoint) |
| 8 | Super Admin pass | 2, 3 |

Items 3, 4 and 5 are unblocked and are the natural Sprint 02 candidates.
