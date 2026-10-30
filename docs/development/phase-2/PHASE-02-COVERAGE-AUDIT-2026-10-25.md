# Phase 02 — Master Prompt coverage audit, 2026-10-25

**What this is.** A requirement-by-requirement trace of
[`PHASE-02-MASTER-EXECUTION-PROMPT.md`](PHASE-02-MASTER-EXECUTION-PROMPT.md) against
[`PHASE-02-TASK-LEDGER.md`](PHASE-02-TASK-LEDGER.md), asking one question only:
**does a ledger row exist that covers this requirement?** Not whether the work is done —
that is the ledger's own Status column, and it is a different question.

**Why it exists.** The coverage check inside the master prompt document was performed at
commit `7f27c06c` and had gone stale: it reported Workstream D as having no task rows (it now
has seven) and Workstream J as needing a row per screen (it still has one row covering five of
its seven). A stale coverage check reads exactly like a current one.

**Method, and its limit.** Every requirement below is a bullet lifted mechanically from the
prompt's own text between `# THE ORIGINAL PROMPT` and `# END OF THE ORIGINAL PROMPT` — 104
bullets across Workstreams A–K, plus 85 cross-cutting obligations in sections 3, 4, 6, 7, 9, 10
and 11. The mapping to ledger rows was made by reading both documents. **It is a judgement, not
a measurement**: a reader could reasonably call some of the PARTIAL rows TRACKED. What is not a
judgement is the MISSING set — those requirements match no row at all, and a grep for their
subject in the ledger returns nothing.

## [2026-10-30] SUPERSEDED HEADLINE — re-derived at the final HEAD

**This document is dated 2026-10-25 and its headline is now historical rather than current.** It
asked one question — *does a ledger row exist for this requirement?* — and answered 50 tracked / 23
partial / **31 missing**. All 31 became ledger rows in the same change that produced this file, and
the work has since landed.

**Re-derived independently at the final HEAD, by reading the evidence rather than the ledger:**

| | |
|---|---|
| Workstream A–K requirements | **104** |
| DONE | **92** |
| PARTIAL | **11** |
| BLOCKED | **1** (E13's cancel-and-linked-replacement, under D-15's conditions) |
| untracked | **0** |

The re-derivation checked the citations rather than trusting them: **all 399 file paths the ledger
cites resolve** (12 apparent misses are ellipses, generated files, or deliberately-absent files such
as `config/sanctum.php`), and **132 of 133 named test classes exist** — the 133rd being a rename the
ledger itself records (`PaymentGatewayValidationIsDeadTest` → `PaymentGatewayRequiredFieldsTest`).

**TWO OBLIGATIONS ARE GENUINELY NOT MET, and neither is a tracking gap:**

1. **Section 6 — "compare actual frontend performance against a measured baseline".** There is **no
   artifact anywhere in the repository**: no Lighthouse run, no LCP, no TTFB. Not started, not
   deferred by decision, and not previously recorded as missing. It is named here so it stops being
   invisible.
2. **E13's second clause** — see the ledger row; it is blocked on an owner decision and on three
   measured prerequisites.

**And two smaller gaps nobody had claimed:** `resources/views/partision/sidebar.blade.php:38` gives
`#sideBarsearch` a placeholder and no accessible name (a placeholder is not an accessible name), and
seven slices with significant UI changes (P2-01-14/-15/-18/-20, P2-05-2, P2-05-8, P2-05-9) carry
browser measurement logs but **no before/after screenshots**, which the Mandatory Visual Evidence
rule asks for by name.

**Read the table below as the 2026-10-25 state.** Where it and a ledger row disagree, the ledger
row is newer. Several of its entries now UNDERSTATE what exists — P2-03-3, P2-03-4, P2-04-17, A3,
E3, C6 and its own 2026-10-27 correction (which still lists F141/F142/F143 as open when all three
read FIXED with bite-tests).

---

## Headline

| | count | share |
|---|---|---|
| Requirements traced (Workstreams A–K) | **104** | |
| TRACKED — a row plainly covers it | 50 | 48% |
| PARTIAL — covered, but lumped in with others or implied | 23 | 22% |
| **MISSING — no row anywhere** | **31** | **30%** |

Per workstream:

| WS | Subject | Reqs | Tracked | Partial | Missing |
|---|---|---|---|---|---|
| A | Design System Adoption | 8 | 5 | 3 | 0 |
| B | Merchant Workspace and Navigation | 9 | 5 | 1 | 3 |
| C | Merchant Dashboard | 8 | 7 | 1 | 0 |
| D | Product Management Experience | 14 | 11 | 1 | 2 |
| E | Order Management Experience | 14 | 4 | 3 | 7 |
| F | Reusable Forms and Record Panels | 9 | 4 | 3 | 2 |
| G | Employee Preferences | 5 | 1 | 2 | 2 |
| H | Unified Search and Notifications | 7 | 5 | 0 | 2 |
| I | Onboarding and Contextual Help | 6 | 1 | 1 | 4 |
| J | Other Merchant Screens | 7 | 0 | 5 | 2 |
| K | Flutter Merchant Experience | 17 | 7 | 3 | 7 |

**Workstream C is the only one with no missing requirement.** It was the first slice built, and
it is the one whose ledger rows were written alongside the work rather than after it.

## Cross-cutting obligations (sections 3, 4, 6, 7, 9, 10, 11)

85 further obligations were enumerated from the prompt's non-workstream sections. They are
**process rules and completion criteria, not features**, and most should not become task rows —
a row saying "do not weaken CI guards" would be noise. They are handled as follows, and the
distinction is stated so the absence of rows is a decision rather than an oversight.

| Section | Obligations | How they are carried |
|---|---|---|
| 3 — Mandatory Discovery of Existing Work | 12 | `PHASE-02-REUSE-INVENTORY.md` and slice P2-00 (5 rows) |
| 4 — Execution Principles | 17 | standing rules; the prohibitions among them are in *Scope boundaries* in the ledger |
| 6 — Testing and Quality Assurance | 16 | the per-slice evidence discipline; every row's **Verified by** column is where this lands |
| 7 — Efficient Delivery Strategy | 6 | method, not deliverable |
| 9 — Git Branch and Integration Rules | 12 | followed; branch discipline is visible in the commit history |
| 10 — Completion Criteria | 11 | **the acceptance gate.** Phase 02 is not complete until each is verified or an exception is authorised |
| 11 — Required Final Report | 11 | `PHASE-02-ACCEPTANCE-REPORT-AR.md` |

**Two obligations in section 6 have no evidence at all and are called out rather than buried:**

- *"Compare actual frontend performance against a measured baseline."* — **no baseline exists.**
  Nothing in this phase has measured frontend performance. This is an unmet requirement, not a
  rule, and it is listed here because it would otherwise disappear between the workstreams and
  the completion criteria.
- *"Use browser screenshots and accessibility checks where supported."* — met for every screen
  this phase touched, and **not** met for the screens it has not: that is what the 31 missing
  requirements above are.

## What the audit found that a mechanical read did not

The matrix above was built by extracting the prompt's bullet lists. A parallel audit, one agent
per workstream reading the prompt's **prose** as well, found obligations stated outside the
bullets. The ones worth tracking were added to the ledger; the rest are prohibitions and are in
*Scope boundaries*. Examples:

- Workstream A: *"Use CSS logical properties."* — a checkable requirement with no row → **P2-01-16**
- Workstream F: *"Preserve existing AJAX modal contracts during migration."* → **P2-01-21**
- Workstream E: *"Any unsupported advanced order operations belong to Phase 04."* → a boundary
- Workstream D: *"Do not implement new Phase 04 product pricing or stock engines."* → a boundary

**The two methods agreed on the substance.** Independently, both concluded that Workstream E's
four order rules (E11–E14) and its manual-entry and printing requirements are tracked by nothing,
and that Workstream J's Reports and Integrations are absent. Where they differed, the agents were
harsher — calling a requirement MISSING where the matrix says PARTIAL — and some of those were
rejected as false positives: a prohibition such as *"do not introduce a second competing design
system"* is not a deliverable and does not need a row.

## What this audit changed in the ledger

| | before | after |
|---|---|---|
| task rows | 65 | **105** |
| P2-05 (Workstream J) | 3 | **9** — the lumped row became one per screen, and Reports and Integrations gained rows |
| P2-04 (Workstream E) | 10 | **19** — including the four order rules that were tracked by nothing |
| P2-06 (G, H, I) | 5 | **13** |
| P2-07 (Flutter) | 9 | **16** |
| P2-01 (A, B, F) | 13 | **21** |
| P2-03 (D) | 7 | **9** |
| remediation rows | 0 | **2** (R1 = F46, R2 = F48) |

Every new row carries acceptance criteria, its dependencies, and the **test evidence it will
require** — marked `REQUIRED:` because none of it exists yet. No existing row was deleted, no
status was changed, and no completed work was re-opened.

**P2-05-2 was deliberately split.** It read *"Design-system pass over customers, stock,
categories, staff, settings"* — five screens in one row, which cannot be accepted screen by
screen, and which silently dropped two of Workstream J's seven areas.

## Limits of this audit

- It answers **"is it tracked?"**, not **"is it done?"**. A TRACKED requirement may be TODO.
- The TRACKED/PARTIAL boundary is a judgement. The MISSING set is not: those match no row.
- Cross-cutting obligations are summarised, not traced one by one.
- The parallel agent audit was still running when this document was written; six of twelve
  sections had reported. Their findings for Workstreams A–F are incorporated. **Anything they
  report for G–K and the cross-cutting section after this date is an addendum, not a correction**,
  and the matrix above should be re-read against it.

## The matrix

### Workstream A — Design System Adoption

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| A1 | Consistent typography and spacing | TRACKED | P2-01-1, P2-01-6 | the DS and the 15-component Blade layer |
| A2 | Consistent cards, forms, buttons, tables, badges and alerts | TRACKED | P2-01-1, P2-01-6 | cards, forms, buttons, tables, badges, alerts all exist as ss-* components |
| A3 | Unified page headers and breadcrumbs | PARTIAL | P2-01-11 | page headers exist; BREADCRUMBS have no row and no check |
| A4 | Arabic RTL and English LTR | TRACKED | P2-01-8, P2-01-12 | both directions driven in the browser suite |
| A5 | Light and dark themes | TRACKED | P2-01-8 | light/dark driven in the browser suite |
| A6 | Responsive desktop, tablet and mobile layouts | TRACKED | P2-01-8, P2-01-13 | 8 widths in the browser suite |
| A7 | Keyboard navigation and accessibility | PARTIAL | P2-01-2, P2-01-3 | focus handled for panel/action bar; no row for keyboard nav as a whole |
| A8 | Clear loading, empty, success, validation and error states | PARTIAL | P2-01-1, P2-01-5 | empty/validation/error exist; LOADING and SUCCESS states have no row |

### Workstream B — Merchant Workspace and Navigation

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| B1 | Workspace switcher with current-store visibility | TRACKED | P2-01-11 | workspace chip |
| B2 | Clear navigation groups by merchant workflow | TRACKED | P2-01-11 | sidebar zones |
| B3 | Collapsible sidebar | TRACKED | P2-01-11 | collapsed rail |
| B4 | Improved header | **MISSING** | — | "Improved header" has no row at all |
| B5 | Quick actions | **MISSING** | — | "Quick actions" has no row; the header Quick Add is the host theme, not ours |
| B6 | Accessible search/navigation controls | PARTIAL | P2-06-2, P2-01-13 | search is tracked; accessible NAVIGATION controls are not |
| B7 | Persistent employee-level preferences | TRACKED | P2-06-4 | persisted table preferences |
| B8 | Saved favorites and recent pages where appropriate | **MISSING** | — | saved favourites and recent pages: no row |
| B9 | Responsive mobile navigation | TRACKED | P2-01-13 | responsive nav below 1025px |

### Workstream C — Merchant Dashboard

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| C1 | Store overview | TRACKED | P2-02-1 | — |
| C2 | Sales and orders summary | TRACKED | P2-02-1, P2-02-4, P2-02-5 | — |
| C3 | Recent orders | TRACKED | P2-02-9 | — |
| C4 | Pending operational tasks | TRACKED | P2-02-2 | — |
| C5 | Inventory alerts | TRACKED | P2-02-3 | — |
| C6 | Shortcuts to common actions | PARTIAL | P2-02-4, P2-02-11 | status counts link out; no row for a shortcuts surface |
| C7 | Important store notifications | TRACKED | P2-06-3 | unified notifications |
| C8 | Clear date range and data state | TRACKED | P2-02-1 | every figure carries its period |

### Workstream D — Product Management Experience

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| D1 | Modern product list | TRACKED | P2-03-1 | — |
| D2 | Fast search and filters | TRACKED | P2-03-2 | — |
| D3 | Saved table preferences | TRACKED | P2-06-4 | — |
| D4 | Clear product status and stock indicators | PARTIAL | F45 | stock badges fixed as a defect; no requirement row for status/stock indicators |
| D5 | Full-page product editor | TRACKED | P2-03-3 | — |
| D6 | Basic and advanced field organization | **MISSING** | — | basic vs advanced field organisation: no row |
| D7 | Images and media workflow | TRACKED | P2-03-4 | — |
| D8 | Existing variants/options support | TRACKED | P2-03-4 | — |
| D9 | Contextual help | **MISSING** | — | contextual help: no row (same gap as I2) |
| D10 | Validation summary and inline errors | TRACKED | P2-01-5 | — |
| D11 | Sticky save controls | TRACKED | P2-01-3 | — |
| D12 | Unsaved-change detection | TRACKED | P2-01-4 | — |
| D13 | Concurrent-edit conflict detection | TRACKED | P2-03-5 | — |
| D14 | Record preview side panel | TRACKED | P2-03-6 | — |

### Workstream E — Order Management Experience

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| E1 | Improved default order table | TRACKED | P2-04-4 | — |
| E2 | Optional Kanban presentation | TRACKED | P2-04-7 | — |
| E3 | Saved views and filters | PARTIAL | P2-04-8 | filters shipped; SAVED VIEWS have no row |
| E4 | Quick order preview | TRACKED | P2-04-5 | — |
| E5 | Full order detail page | TRACKED | P2-04-6 | — |
| E6 | Distinct order, payment, fulfilment and shipping status presentation | PARTIAL | P2-04-1, P2-04-2 | the four-way order/payment/fulfilment/shipping split is not a row |
| E7 | Permission-aware actions | **MISSING** | — | permission-aware actions: enforced in code, no requirement row |
| E8 | Clear manual order entry workflow where supported | **MISSING** | — | manual order entry workflow: no row |
| E9 | Operational "Needs Attention" center | PARTIAL | P2-02-2 | dashboard attention block exists; an ORDER-level needs-attention centre does not |
| E10 | Better printing and export UI where already supported | **MISSING** | — | printing and export UI: no row |
| E11 | No editing order products or quantities after creation | **MISSING** | — | RULE: no editing order products/quantities after creation — no row, no guard test |
| E12 | Only authorized delivery and internal note changes when allowed | **MISSING** | — | RULE: only authorised delivery and internal note changes — no row |
| E13 | Product corrections through cancellation and linked replacement orders | **MISSING** | — | RULE: corrections via cancellation + linked replacement orders — no row |
| E14 | No duplication of payments or inventory movements | **MISSING** | — | RULE: no duplication of payments or inventory movements — no row |

### Workstream F — Reusable Forms and Record Panels

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| F1 | Full-page editing for complex forms | TRACKED | P2-03-3 | full-page editing, via the product editor |
| F2 | Side panels for quick previews and simple editing | TRACKED | P2-01-2 | — |
| F3 | Dialogs for confirmations | **MISSING** | — | confirmation dialogs: show_confirm exists, no row |
| F4 | No confusing nested modal workflows | **MISSING** | — | RULE: no nested modal workflows — no row, no check |
| F5 | Keyboard and focus management | PARTIAL | P2-01-2, P2-01-3 | focus handled per component; no row for keyboard/focus as a contract |
| F6 | Unsaved-change protection | TRACKED | P2-01-4 | — |
| F7 | Reliable field validation | PARTIAL | P2-01-5 | validation summary exists; "reliable field validation" is broader |
| F8 | Concurrent edit/version conflict handling | TRACKED | P2-03-5 | — |
| F9 | Accessible error messages | PARTIAL | P2-01-5 | focusable summary; accessible error MESSAGES not separately tracked |

### Workstream G — Employee Preferences

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| G1 | Saved table filters | **MISSING** | — | saved table FILTERS: P2-06-4 saves sort/length/columns, not filters |
| G2 | Visible columns and column order where supported | PARTIAL | P2-06-4 | column visibility saved; column ORDER is not |
| G3 | Page size and sorting preferences | TRACKED | P2-06-4 | — |
| G4 | Favorite pages | **MISSING** | — | favourite pages: no row (same gap as B8) |
| G5 | Workspace UI preferences | PARTIAL | P2-01-11 | workspace chip/rail exist; no row for UI preferences as a set |

### Workstream H — Unified Search and Notifications

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| H1 | Navigation | TRACKED | P2-06-2 | — |
| H2 | Products | TRACKED | P2-06-2 | — |
| H3 | Orders | TRACKED | P2-06-2 | — |
| H4 | Customers | TRACKED | P2-06-2 | — |
| H5 | Supported quick actions | **MISSING** | — | quick actions from search results: no row |
| H6 | Temporary action feedback | **MISSING** | — | temporary action feedback (toasts) as a tracked surface: no row |
| H7 | Persistent operational notifications | TRACKED | P2-06-3 | — |

### Workstream I — Onboarding and Contextual Help

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| I1 | Merchant onboarding guidance | TRACKED | P2-06-1 | — |
| I2 | Contextual field help | **MISSING** | — | contextual field help: no row |
| I3 | Helpful empty states | **MISSING** | — | helpful empty states: x-ss.empty exists, no requirement row |
| I4 | Short task-oriented instructions | **MISSING** | — | short task-oriented instructions: no row |
| I5 | Accessible UI guidance | **MISSING** | — | accessible UI guidance: no row |
| I6 | Arabic and English translations | PARTIAL | P2-02-6 | Arabic at ship time proven for the dashboard; not a standing WS I row |

### Workstream J — Other Merchant Screens

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| J1 | Customers | PARTIAL | P2-05-2 | one row lumps five screens; customers not individually acceptable |
| J2 | Inventory views | PARTIAL | P2-05-2 | ditto, inventory views |
| J3 | Categories and attributes | PARTIAL | P2-05-2 | ditto, categories and attributes |
| J4 | Staff and permissions | PARTIAL | P2-05-2 | ditto, staff and permissions |
| J5 | Reports already supported by the backend | **MISSING** | — | REPORTS: named in WS J, absent from P2-05-2 and from every other row |
| J6 | Store settings | PARTIAL | P2-05-2 | ditto, store settings |
| J7 | Integrations already supported by the backend | **MISSING** | — | INTEGRATIONS: named in WS J, absent from every row |

### Workstream K — Flutter Merchant Experience

| # | Requirement, in the prompt's own words | Status | Ledger row | Note |
|---|---|---|---|---|
| K1 | Merchant web functionality | TRACKED | P2-07-4 | — |
| K2 | Existing Flutter functionality | TRACKED | P2-07-4 | — |
| K3 | Shared APIs | TRACKED | P2-07-4 | — |
| K4 | Permission behavior | TRACKED | P2-07-4 | — |
| K5 | Missing mobile workflows | TRACKED | P2-07-4 | — |
| K6 | Features intentionally retained on web | TRACKED | P2-07-4 | — |
| K7 | Dashboard usability | **MISSING** | — | Flutter dashboard usability: parity doc G3 is OPEN, no P2-07 row |
| K8 | Orders and order details | PARTIAL | P2-07-6 | status naming fixed; orders/order-detail UX not tracked |
| K9 | Products and inventory visibility | **MISSING** | — | products and inventory visibility in the app: no row |
| K10 | Quick daily operations | **MISSING** | — | quick daily operations: no row |
| K11 | Search and filters | **MISSING** | — | search and filters in the app: no row |
| K12 | Notifications | PARTIAL | P2-07-4 | notifications covered in the parity matrix, not as a task row |
| K13 | Employee permissions | **MISSING** | — | employee permissions in the app: no row |
| K14 | Arabic/English support | PARTIAL | P2-07-6 | 13 keys added; no standing row for AR/EN coverage |
| K15 | RTL/LTR | TRACKED | P2-07-2, P2-07-5 | — |
| K16 | Loading, empty and error states | **MISSING** | — | loading, empty and error states in the app: no row |
| K17 | Secure authentication behavior | **MISSING** | — | secure authentication behaviour in the app: no row |


---

## Correction, 2026-10-27 — Workstream J now has a row per screen

**The table above is a dated snapshot and is left as it was written.** This section records what
has changed since, in the same terms, because the audit's own warning applies to itself: *a stale
coverage check reads exactly like a current one.*

The J rows were the audit's headline complaint — one ledger row (P2-05-2) covering five of seven
screens, with reports and integrations covered by nothing. The same change that published this
audit split that row, and the work has since been done:

| # | Subject | Was | Now | Ledger row |
|---|---|---|---|---|
| J1 | Customers | PARTIAL → P2-05-2 | **TRACKED** | P2-05-2 (DONE) |
| J2 | Inventory views | PARTIAL → P2-05-2 | **TRACKED** | P2-05-3 (DONE) |
| J3 | Categories and attributes | PARTIAL → P2-05-2 | **TRACKED** | P2-05-4 (DONE) |
| J4 | Staff and permissions | PARTIAL → P2-05-2 | **TRACKED** | P2-05-5 (DONE) |
| J5 | Reports | **MISSING** | **TRACKED** | P2-05-6 (DONE) |
| J6 | Store settings | PARTIAL → P2-05-2 | **TRACKED** | P2-05-7 |
| J7 | Integrations | **MISSING** | **TRACKED** | P2-05-8 |

So Workstream J's MISSING count goes from 2 to 0 and its PARTIAL count from 5 to 0. The headline
table's totals are NOT restated here, because the other workstreams have not been re-traced and a
partially-updated total would be worse than a stale one that says its own date.

**WHAT THIS SECTION DOES NOT CLAIM.** This audit asks only whether a row exists. Two of the seven
rows above are not finished work:

- **P2-05-7 and P2-05-8** have their design-system migration, their credential masking and their
  measured AA complete across all 31 panels in four configurations — and they carry three findings
  recorded for the owner rather than closed: **F141** (no way to clear a stored gateway secret),
  **F142** (the payment screen's required-field validation has never run) and **F143**
  (`paiementpro_mode` is written empty on every save). None of those is a coverage gap; each is a
  decision that changes behaviour beyond what the slice was asked to do.
- Every "DONE" above means engineering done on this branch. It does not mean reviewed, released,
  or accepted, and nothing here waives the Phase 01 closure gates or security incident #120.
